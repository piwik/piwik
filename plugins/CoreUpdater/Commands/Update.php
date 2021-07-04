<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreUpdater\Commands;

use Piwik\Filechecks;
use Piwik\SettingsServer;
use Piwik\Version;
use Piwik\Config;
use Piwik\DbHelper;
use Piwik\Filesystem;
use Piwik\Piwik;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\CoreUpdater\Commands\Update\CliUpdateObserver;
use Piwik\Plugins\CoreUpdater\NoUpdatesFoundException;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Updater;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * @package CoreUpdater
 */
class Update extends ConsoleCommand
{
    /**
     * @var string[]
     */
    private $migrationQueries;

    protected function configure()
    {
        $this->setName('core:update');

        $this->setDescription(Piwik::translate('CoreUpdater_ConsoleCommandDescription'));

        $this->addOption('yes', null, InputOption::VALUE_NONE, Piwik::translate('CoreUpdater_ConsoleParameterDescription'));
        $this->addOption('skip-cache-clear', null, InputOption::VALUE_NONE, Piwik::translate('CoreUpdater_SkipCacheClearDesc'));
    }

    /**
     * Execute command like: ./console core:update --yes
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipCacheClear = $input->getOption('skip-cache-clear');
        try {
            if ($skipCacheClear) {
                $output->writeln(Piwik::translate('CoreUpdater_SkipCacheClear'));

                Filesystem::$skipCacheClearOnUpdate = true;
            }

            $this->executeClearCaches();

            $yes = $input->getOption('yes');

            try {
                $this->makeUpdate($input, $output, true);

                if (!$yes) {
                    $yes = $this->askForUpdateConfirmation($input, $output);
                }

                if ($yes) {
                    $output->writeln("\n" . Piwik::translate('CoreUpdater_ConsoleStartingDbUpgrade'));

                    $this->makeUpdate($input, $output, false);

                    $this->writeSuccessMessage($output, array(Piwik::translate('CoreUpdater_PiwikHasBeenSuccessfullyUpgraded')));
                } else {
                    $this->writeSuccessMessage($output, array(Piwik::translate('CoreUpdater_DbUpgradeNotExecuted')));
                }

                $this->writeAlertMessageWhenCommandExecutedWithUnexpectedUser($output);


            } catch (NoUpdatesFoundException $e) {
                // Do not fail if no updates were found
                $this->writeSuccessMessage($output, array($e->getMessage()));
            }
        } finally {
            Filesystem::$skipCacheClearOnUpdate = false;
        }
    }

    private function askForUpdateConfirmation(InputInterface $input, OutputInterface $output)
    {
        $helper   = $this->getHelper('question');
        $question = new ConfirmationQuestion('<comment>'.Piwik::translate('CoreUpdater_ExecuteDbUpgrade').' (y/N) </comment>', false);

        return $helper->ask($input, $output, $question);
    }

    protected function executeClearCaches()
    {
        Filesystem::deleteAllCacheOnUpdate();
    }

    protected function makeUpdate(InputInterface $input, OutputInterface $output, $doDryRun)
    {
        $this->checkAllRequiredOptionsAreNotEmpty($input);

        $updater = $this->makeUpdaterInstance($output);

        $componentsWithUpdateFile = $updater->getComponentUpdates();
        if (empty($componentsWithUpdateFile)) {
            throw new NoUpdatesFoundException(Piwik::translate('CoreUpdater_AlreadyUpToDate'));
        }

        $output->writeln(array(
            "",
            "    *** " . Piwik::translate('CoreUpdater_UpdateTitle') . " ***"
        ));

        // handle case of existing database with no tables
        if (!DbHelper::isInstalled()) {
            $this->handleCoreError($output, Piwik::translate('CoreUpdater_EmptyDatabaseError', Config::getInstance()->database['dbname']));
            return;
        }

        $output->writeln(array(
            "",
            "    " . Piwik::translate('CoreUpdater_DatabaseUpgradeRequired'),
            "",
            "    " . Piwik::translate('CoreUpdater_YourDatabaseIsOutOfDate')
        ));

        if ($this->isUpdatingCore($componentsWithUpdateFile)) {
            $currentVersion = $this->getCurrentVersionForCore($updater);
            $output->writeln(array(
                "",
                "    " . Piwik::translate('CoreUpdater_PiwikWillBeUpgradedFromVersionXToVersionY', array($currentVersion, Version::VERSION))
            ));
        }

        $pluginsToUpdate = $this->getPluginsToUpdate($componentsWithUpdateFile);
        if (!empty($pluginsToUpdate)) {
            $output->writeln(array(
                "",
                "    " . Piwik::translate('CoreUpdater_TheFollowingPluginsWillBeUpgradedX', implode(', ', $pluginsToUpdate))
            ));
        }

        $dimensionsToUpdate = $this->getDimensionsToUpdate($componentsWithUpdateFile);
        if (!empty($dimensionsToUpdate)) {
            $output->writeln(array(
                "",
                "    " . Piwik::translate('CoreUpdater_TheFollowingDimensionsWillBeUpgradedX', implode(', ', $dimensionsToUpdate))
            ));
        }

        $output->writeln("");

        if ($doDryRun) {
            $this->doDryRun($updater, $output);
        } else {
            $this->doRealUpdate($updater, $componentsWithUpdateFile, $output);
        }
    }

    private function doDryRun(Updater $updater, OutputInterface $output)
    {
        $migrationQueries = $this->getMigrationQueriesToExecute($updater);

        if(empty($migrationQueries)) {
            $output->writeln(array("    *** ".Piwik::translate('CoreUpdater_ConsoleUpdateNoSqlQueries')." ***", ""));
            return;
        }


        if ($updater->hasMajorDbUpdate()) {
            $output->writeln(array(
                "",
                sprintf("<comment>%s \n</comment>", Piwik::translate('CoreUpdater_MajorUpdateWarning1'))
            ));
        }

        $output->writeln(array("    *** ".Piwik::translate('CoreUpdater_DryRun')." ***", ""));

        foreach ($migrationQueries as $query) {
            $result = $query->__toString();
            if (!empty($result)) {
                $output->writeln("    " . $result);
            }
        }

        $output->writeln(array("", "    *** " . Piwik::translate('CoreUpdater_DryRunEnd') . " ***", ""));
    }

    private function doRealUpdate(Updater $updater, $componentsWithUpdateFile, OutputInterface $output)
    {
        $output->writeln(array("    " . Piwik::translate('CoreUpdater_TheUpgradeProcessMayTakeAWhilePleaseBePatient'), ""));

        $updaterResult = $updater->updateComponents($componentsWithUpdateFile);

        if (@$updaterResult['coreError']) {
            $this->handleCoreError($output, $updaterResult['errors'], $includeDiyHelp = true);
            return;
        }

        if (!empty($updaterResult['warnings'])) {
            $this->outputUpdaterWarnings($output, $updaterResult['warnings']);
        }

        if (!empty($updaterResult['errors'])) {
            $this->outputUpdaterErrors($output, $updaterResult['errors'], $updaterResult['deactivatedPlugins']);
        }

        if (!empty($updaterResult['warnings'])
            || !empty($updaterResult['errors'])
        ) {
            $output->writeln(array(
                "    " . Piwik::translate('CoreUpdater_HelpMessageIntroductionWhenWarning'),
                "",
                "    * " . $this->getUpdateHelpMessage()
            ));
        }
    }

    private function handleCoreError(OutputInterface $output, $errors, $includeDiyHelp = false)
    {
        if (!is_array($errors)) {
            $errors = array($errors);
        }

        $output->writeln(array(
            "",
            "    [X] " . Piwik::translate('CoreUpdater_CriticalErrorDuringTheUpgradeProcess'),
            "",
        ));

        foreach ($errors as $errorMessage) {
            $errorMessage = trim($errorMessage);
            $errorMessage = str_replace("\n", "\n    ", $errorMessage);

            $output->writeln("    * $errorMessage");
        }

        $output->writeln(array(
            "",
            "    " . Piwik::translate('CoreUpdater_HelpMessageIntroductionWhenError'),
            "",
            "    * " . $this->getUpdateHelpMessage()
        ));

        if ($includeDiyHelp) {
            $output->writeln(array(
                "",
                "    " . Piwik::translate('CoreUpdater_ErrorDIYHelp'),
                "",
                "    * " . Piwik::translate('CoreUpdater_ErrorDIYHelp_1'),
                "    * " . Piwik::translate('CoreUpdater_ErrorDIYHelp_2'),
                "    * " . Piwik::translate('CoreUpdater_ErrorDIYHelp_3'),
                "    * " . Piwik::translate('CoreUpdater_ErrorDIYHelp_4'),
                "    * " . Piwik::translate('CoreUpdater_ErrorDIYHelp_5')
            ));
        }

        throw new \RuntimeException(Piwik::translate('CoreUpdater_ConsoleUpdateFailure'));
    }

    private function outputUpdaterWarnings(OutputInterface $output, $warnings)
    {
        $output->writeln(array(
            "",
            "    [!] " . Piwik::translate('CoreUpdater_WarningMessages'),
            ""
        ));

        foreach ($warnings as $message) {
            $output->writeln("    * $message");
        }
    }

    private function outputUpdaterErrors(OutputInterface $output, $errors, $deactivatedPlugins)
    {
        $output->writeln(array(
            "",
            "    [X] " . Piwik::translate('CoreUpdater_ErrorDuringPluginsUpdates'),
            ""
        ));

        foreach ($errors as $message) {
            $output->writeln("    * $message");
        }

        if (!empty($deactivatedPlugins)) {
            $output->writeln(array(
                "",
                "    [!] " . Piwik::translate('CoreUpdater_WeAutomaticallyDeactivatedTheFollowingPlugins', implode(', ', $deactivatedPlugins))
            ));
        }
    }

    private function getUpdateHelpMessage()
    {
        return Piwik::translate('CoreUpdater_HelpMessageContent', array('[',']',"\n    *"));
    }

    private function isUpdatingCore($componentsWithUpdateFile)
    {
        foreach ($componentsWithUpdateFile as $componentName => $updates) {
            if ($componentName == 'core') {
                return true;
            }
        }
        return false;
    }

    private function getCurrentVersionForCore(Updater $updater)
    {
        $currentVersion = $updater->getCurrentComponentVersion('core');
        if ($currentVersion === false) {
            $currentVersion = "<= 0.2.9";
        }
        return $currentVersion;
    }

    private function getPluginsToUpdate($componentsWithUpdateFile)
    {
        $plugins = array();
        foreach ($componentsWithUpdateFile as $componentName => $updates) {
            if ($componentName !== 'core'
                && 0 !== strpos($componentName, 'log_')
            ) {
                $plugins[] = $componentName;
            }
        }
        return $plugins;
    }

    private function getDimensionsToUpdate($componentsWithUpdateFile)
    {
        $dimensions = array();
        foreach ($componentsWithUpdateFile as $componentName => $updates) {
            if (0 === strpos($componentName, 'log_')) {
                $dimensions[] = $componentName;
            }
        }

        sort($dimensions);
        return $dimensions;
    }

    private function getMigrationQueriesToExecute(Updater $updater)
    {
        if (empty($this->migrationQueries)) {
            $this->migrationQueries = $updater->getSqlQueriesToExecute();
        }
        return $this->migrationQueries;
    }

    private function makeUpdaterInstance(OutputInterface $output)
    {
        $updater = new Updater();

        $migrationQueryCount = count($this->getMigrationQueriesToExecute($updater));
        $updater->addUpdateObserver(new CliUpdateObserver($output, $migrationQueryCount));

        return $updater;
    }

    /**
     * @param OutputInterface $output
     */
    protected function writeAlertMessageWhenCommandExecutedWithUnexpectedUser(OutputInterface $output)
    {
        if (SettingsServer::isWindows()) {
            // does not work on windows
            return;
        }

        $processUserAndGroup = Filechecks::getUserAndGroup();
        $fileOwnerUserAndGroup = Filechecks::getOwnerOfPiwikFiles();

        if (!$fileOwnerUserAndGroup || $processUserAndGroup == $fileOwnerUserAndGroup) {
            // current process user/group appear to be same as the Matomo filesystem user/group -> OK
            return;
        }
        $output->writeln(
            sprintf("<comment>%s</comment>", Piwik::translate('CoreUpdater_ConsoleUpdateUnexpectedUserWarning', [
                $processUserAndGroup,
                $fileOwnerUserAndGroup,
                Filechecks::getCommandToChangeOwnerOfPiwikFiles()
            ]))
        );
    }
}
