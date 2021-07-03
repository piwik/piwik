<?php declare(strict_types=1);

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreAdminHome\Commands;

use Piwik\Config;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\CoreAdminHome\Commands\DeleteConfig\ConfigDeletingManipulation;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use \RuntimeException;

class DeleteConfig extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('config:delete');
        $this->setDescription('Delete one or more config settings in the file config/config.ini.php');
        $this->addArgument('assignment', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, "List of config setting assignments, eg, section.config_setting_key");
        $this->addOption('section', null, InputOption::VALUE_REQUIRED, 'The section the INI config setting belongs to.');
        $this->addOption('key', null, InputOption::VALUE_REQUIRED, 'The name of the INI config setting.');
        $this->setHelp("This command can be used to remove INI config settings on a Piwik instance.

You can remove config values two ways, via --section, --key or by command arguments.

To use --section, --key, simply supply those options.

To use arguments, supply one or more arguments in the following format:

Remove setting inside section
$ ./console config:delete 'section.config_setting_key'
'section' is the name of the section,
'config_setting_key' the name of the setting
");
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $manipulations = $this->getManipulations($input);

        if (empty($manipulations)) {
            throw new \InvalidArgumentException("Nothing to remove. Add key as arguments or use the ". "--section and --key options.");
        }

        $config = Config::getInstance();

        foreach ($manipulations as $manipulation) {
            try {
                $manipulation->manipulate($config);

                $output->write("<info>Removing [{$manipulation->getSectionName()}] {$manipulation->getName()}...</info>");
                $output->writeln("<info> done.</info>");

            } catch (RuntimeException $exception) {
                $output->writeln("<error> ".$exception->getMessage()."</error>");
            }
        }

        $config->forceSave();
    }

    /**
     * @param InputInterface $input
     *
     * @return ConfigSettingManipulation[]
     */
    private function getManipulations(InputInterface $input): array
    {
        $manipulations = $this->getAssignments($input);

        $section = $input->getOption('section');
        $setting_key = $input->getOption('key');

        if (!empty($section) && !empty($setting_key)) {
            $manipulations[] = new ConfigDeletingManipulation($section, $setting_key);
        }

        return $manipulations;
    }

    /**
     * @param InputInterface $input
     *
     * @return ConfigSettingManipulation[]
     */
    private function getAssignments(InputInterface $input):array
    {
        $assignments = $input->getArgument('assignment');

        $result = [];
        foreach ($assignments as $assignment) {
            $result[] = ConfigDeletingManipulation::make($assignment);
        }

        return $result;
    }
}