<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Piwik\Columns\Updater as ColumnUpdater;
use Piwik\Container\StaticContainer;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Installation\ServerFilesGenerator;
use Piwik\Updater\Migration;
use Piwik\Exception\MissingFilePermissionException;
use Piwik\Updater\UpdateObserver;
use Zend_Db_Exception;

/**
 * Load and execute all relevant, incremental update scripts for Piwik core and plugins, and bump the component version numbers for completed updates.
 *
 */
class Updater
{
    const INDEX_CURRENT_VERSION = 0;
    const INDEX_NEW_VERSION = 1;
    const OPTION_KEY_MATOMO_UPDATE_HISTORY = 'MatomoUpdateHistory';

    private $pathUpdateFileCore;
    private $pathUpdateFilePlugins;
    private $hasMajorDbUpdate = false;
    private $updatedClasses = array();
    private $componentsWithNewVersion = array();
    private $componentsWithUpdateFile = array();

    /**
     * @var UpdateObserver[]
     */
    private $updateObservers = array();

    /**
     * @var Columns\Updater
     */
    private $columnsUpdater;

    /**
     * Currently used Updater instance, set on construction. This instance is used to provide backwards
     * compatibility w/ old code that may use the deprecated static methods in Updates.
     *
     * @var Updater
     */
    private static $activeInstance;

    /**
     * Constructor.
     *
     * @param string|null $pathUpdateFileCore The path to core Update files.
     * @param string|null $pathUpdateFilePlugins The path to plugin update files. Should contain a `'%s'` placeholder
     *                                           for the plugin name.
     * @param Columns\Updater|null $columnsUpdater The dimensions updater instance.
     */
    public function __construct($pathUpdateFileCore = null, $pathUpdateFilePlugins = null, Columns\Updater $columnsUpdater = null)
    {
        $this->pathUpdateFileCore = $pathUpdateFileCore ?: PIWIK_INCLUDE_PATH . '/core/Updates/';

        if ($pathUpdateFilePlugins) {
            $this->pathUpdateFilePlugins = $pathUpdateFilePlugins;
        } else {
            $this->pathUpdateFilePlugins = null;
        }

        $this->columnsUpdater = $columnsUpdater ?: new Columns\Updater();

        self::$activeInstance = $this;
    }

    /**
     * Adds an UpdateObserver to the internal list of listeners.
     *
     * @param UpdateObserver $listener
     */
    public function addUpdateObserver(UpdateObserver $listener)
    {
        $this->updateObservers[] = $listener;
    }

    /**
     * Marks a component as successfully updated to a specific version in the database. Sets an option
     * that looks like `"version_$componentName"`.
     *
     * @param string $name The component name. Eg, a plugin name, `'core'` or dimension column name.
     * @param string $version The component version (should use semantic versioning).
     * @param bool   $isNew indicates if the component is a new one (for plugins)
     */
    public function markComponentSuccessfullyUpdated($name, $version, $isNew = false)
    {
        try {
            Option::set(self::getNameInOptionTable($name), $version, $autoLoad = 1);
        } catch (\Exception $e) {
            // case when the option table is not yet created (before 0.2.10)
        }

        if ($isNew) {

            /**
             * Event triggered after a new component has been installed.
             *
             * @param string $name The component that has been installed.
             */
            Piwik::postEvent('Updater.componentInstalled', array($name));

            return;
        }

        /**
         * Event triggered after a component has been updated.
         *
         * Can be used to handle logic that should be done after a component was updated
         *
         * **Example**
         *
         *     Piwik::addAction('Updater.componentUpdated', function ($componentName, $updatedVersion) {
         *          $mail = new Mail();
         *          $mail->setDefaultFromPiwik();
         *          $mail->addTo('test@example.org');
         *          $mail->setSubject('Component was updated);
         *          $message = sprintf(
         *              'Component %1$s has been updated to version %2$s',
         *              $componentName, $updatedVersion
         *          );
         *          $mail->setBodyText($message);
         *          $mail->send();
         *     });
         *
         * @param string $componentName 'core', plugin name or dimension name
         * @param string $updatedVersion version updated to
         */
        Piwik::postEvent('Updater.componentUpdated', array($name, $version));
    }

    /**
     * Marks a component as successfully uninstalled. Deletes an option
     * that looks like `"version_$componentName"`.
     *
     * @param string $name The component name. Eg, a plugin name, `'core'` or dimension column name.
     */
    public function markComponentSuccessfullyUninstalled($name)
    {
        try {
            Option::delete(self::getNameInOptionTable($name));
        } catch (\Exception $e) {
            // case when the option table is not yet created (before 0.2.10)
        }

        /**
         * Event triggered after a component has been uninstalled.
         *
         * @param string $name The component that has been uninstalled.
         */
        Piwik::postEvent('Updater.componentUninstalled', array($name));
    }

    /**
     * Returns the currently installed version of a Piwik component.
     *
     * @param string $name The component name. Eg, a plugin name, `'core'` or dimension column name.
     * @return string A semantic version.
     * @throws \Exception
     */
    public function getCurrentComponentVersion($name)
    {
        try {
            $currentVersion = Option::get(self::getNameInOptionTable($name));
        } catch (\Exception $e) {
            // mysql error 1146: table doesn't exist
            if (Db::get()->isErrNo($e, '1146')) {
                // case when the option table is not yet created (before 0.2.10)
                $currentVersion = false;
            } else {
                // failed for some other reason
                throw $e;
            }
        }

        return $currentVersion;
    }

    /**
     * Returns a list of components (core | plugin) that need to run through the upgrade process.
     *
     * @param string[] $componentsToCheck An array mapping component names to the latest locally available version.
     *                                    If the version is later than the currently installed version, the component
     *                                    must be upgraded.
     *
     *                                    Example: `array('core' => '2.11.0')`
     * @return array( componentName => array( file1 => version1, [...]), [...])
     */
    public function getComponentsWithUpdateFile($componentsToCheck)
    {
        $this->componentsWithNewVersion = $this->getComponentsWithNewVersion($componentsToCheck);
        $this->componentsWithUpdateFile = $this->loadComponentsWithUpdateFile();
        return $this->componentsWithUpdateFile;
    }

    /**
     * Component has a new version?
     *
     * @param string $componentName
     * @return bool TRUE if component is to be updated; FALSE if not
     */
    public function hasNewVersion($componentName)
    {
        return isset($this->componentsWithNewVersion[$componentName]);
    }

    /**
     * Does one of the new versions involve a major database update?
     * Note: getSqlQueriesToExecute() must be called before this method!
     *
     * @return bool
     */
    public function hasMajorDbUpdate()
    {
        return $this->hasMajorDbUpdate;
    }

    /**
     * Returns the list of SQL queries that would be executed during the update
     *
     * @return Migration[] of SQL queries
     * @throws \Exception
     */
    public function getSqlQueriesToExecute()
    {
        $queries = array();
        $classNames = array();

        foreach ($this->componentsWithUpdateFile as $componentName => $componentUpdateInfo) {
            foreach ($componentUpdateInfo as $file => $fileVersion) {
                require_once $file; // prefixed by PIWIK_INCLUDE_PATH

                $className = $this->getUpdateClassName($componentName, $fileVersion);
                if (!class_exists($className, false)) {
                    // throwing an error here causes Matomo to show the safe mode instead of showing an exception fatal only
                    // that makes it possible to deactivate / uninstall a broken plugin to recover Matomo directly
                    throw new \Error("The class $className was not found in $file");
                }

                if (in_array($className, $classNames)) {
                    continue; // prevent from getting updates from Piwik\Columns\Updater multiple times
                }

                $classNames[] = $className;

                /** @var Updates $update */
                $update = StaticContainer::getContainer()->make($className);
                $migrationsForComponent = $update->getMigrations($this);
                foreach ($migrationsForComponent as $index => $migration) {
                    $migration = $this->keepBcForOldMigrationQueryFormat($index, $migration);
                    $queries[] = $migration;
                }
                $this->hasMajorDbUpdate = $this->hasMajorDbUpdate || call_user_func(array($className, 'isMajorUpdate'));
            }
        }
        return $queries;
    }

    public function getUpdateClassName($componentName, $fileVersion)
    {
        $suffix = strtolower(str_replace(array('-', '.'), '_', $fileVersion));
        $className = 'Updates_' . $suffix;

        if ($componentName == 'core') {
            return '\\Piwik\\Updates\\' . $className;
        }

        if (ColumnUpdater::isDimensionComponent($componentName)) {
            return '\\Piwik\\Columns\\Updater';
        }

        return '\\Piwik\\Plugins\\' . $componentName . '\\' . $className;
    }

    /**
     * Update the named component
     *
     * @param string $componentName 'core', or plugin name
     * @throws \Exception|UpdaterErrorException
     * @return array of warning strings if applicable
     */
    public function update($componentName)
    {
        $warningMessages = array();

        $this->executeListenerHook('onComponentUpdateStarting', array($componentName));

        foreach ($this->componentsWithUpdateFile[$componentName] as $file => $fileVersion) {
            try {
                require_once $file; // prefixed by PIWIK_INCLUDE_PATH

                $className = $this->getUpdateClassName($componentName, $fileVersion);
                if (!in_array($className, $this->updatedClasses)
                    && class_exists($className, false)
                ) {
                    $this->executeListenerHook('onComponentUpdateFileStarting', array($componentName, $file, $className, $fileVersion));

                    $this->executeSingleUpdateClass($className);

                    $this->executeListenerHook('onComponentUpdateFileFinished', array($componentName, $file, $className, $fileVersion));

                    // makes sure to call Piwik\Columns\Updater only once as one call updates all dimensions at the same
                    // time for better performance
                    $this->updatedClasses[] = $className;
                }

                $this->markComponentSuccessfullyUpdated($componentName, $fileVersion);
            } catch (UpdaterErrorException $e) {
                $this->executeListenerHook('onError', array($componentName, $fileVersion, $e));
                throw $e;

            } catch (\Exception $e) {
                $warningMessages[] = $e->getMessage();

                $this->executeListenerHook('onWarning', array($componentName, $fileVersion, $e));
            }
        }

        // to debug, create core/Updates/X.php, update the core/Version.php, throw an Exception in the try, and comment the following lines
        $updatedVersion = $this->componentsWithNewVersion[$componentName][self::INDEX_NEW_VERSION];
        $this->markComponentSuccessfullyUpdated($componentName, $updatedVersion);

        $this->executeListenerHook('onComponentUpdateFinished', array($componentName, $updatedVersion, $warningMessages));
        ServerFilesGenerator::createFilesForSecurity();
        return $warningMessages;
    }

    /**
     * Construct list of update files for the outdated components
     *
     * @return array( componentName => array( file1 => version1, [...]), [...])
     */
    private function loadComponentsWithUpdateFile()
    {
        $componentsWithUpdateFile = array();

        foreach ($this->componentsWithNewVersion as $name => $versions) {
            $currentVersion = $versions[self::INDEX_CURRENT_VERSION];
            $newVersion = $versions[self::INDEX_NEW_VERSION];

            if ($name == 'core') {
                $pathToUpdates = $this->pathUpdateFileCore . '*.php';
            } elseif (ColumnUpdater::isDimensionComponent($name)) {
                $componentsWithUpdateFile[$name][PIWIK_INCLUDE_PATH . '/core/Columns/Updater.php'] = $newVersion;
            } else {
                if ($this->pathUpdateFilePlugins) {
                    $pathToUpdates = sprintf($this->pathUpdateFilePlugins, $name) . '*.php';
                } else {
                    $pathToUpdates = Manager::getPluginDirectory($name) . '/Updates/*.php';
                }
            }

            if (!empty($pathToUpdates)) {
                $files = _glob($pathToUpdates);
                if ($files == false) {
                    $files = array();
                }

                foreach ($files as $file) {
                    $fileVersion = basename($file, '.php');
                    if (// if the update is from a newer version
                        version_compare($currentVersion, $fileVersion) == -1
                        // but we don't execute updates from non existing future releases
                        && version_compare($fileVersion, $newVersion) <= 0
                    ) {
                        $componentsWithUpdateFile[$name][$file] = $fileVersion;
                    }
                }
            }

            if (isset($componentsWithUpdateFile[$name])) {
                // order the update files by version asc
                uasort($componentsWithUpdateFile[$name], "version_compare");
            } else {
                // there are no update file => nothing to do, update to the new version is successful
                $this->markComponentSuccessfullyUpdated($name, $newVersion);
            }
        }

        return $componentsWithUpdateFile;
    }

    /**
     * Construct list of outdated components
     *
     * @param string[] $componentsToCheck An array mapping component names to the latest locally available version.
     *                                    If the version is later than the currently installed version, the component
     *                                    must be upgraded.
     *
     *                                    Example: `array('core' => '2.11.0')`
     * @throws \Exception
     * @return array array( componentName => array( oldVersion, newVersion), [...])
     */
    public function getComponentsWithNewVersion($componentsToCheck)
    {
        $componentsToUpdate = array();

        // we make sure core updates are processed before any plugin updates
        if (isset($componentsToCheck['core'])) {
            $coreVersions = $componentsToCheck['core'];
            unset($componentsToCheck['core']);
            $componentsToCheck = array_merge(array('core' => $coreVersions), $componentsToCheck);
        }

        $recordedCoreVersion = $this->getCurrentComponentVersion('core');
        if (empty($recordedCoreVersion)) {
            // This should not happen
            $recordedCoreVersion = Version::VERSION;
            $this->markComponentSuccessfullyUpdated('core', $recordedCoreVersion);
        }

        foreach ($componentsToCheck as $name => $version) {
            $currentVersion = $this->getCurrentComponentVersion($name);

            if (ColumnUpdater::isDimensionComponent($name)) {
                $isComponentOutdated = $currentVersion !== $version;
            } else {
                // note: when versionCompare == 1, the version in the DB is newer, we choose to ignore
                $isComponentOutdated = version_compare($currentVersion, $version) == -1;
            }

            if ($isComponentOutdated || $currentVersion === false) {
                $componentsToUpdate[$name] = array(
                    self::INDEX_CURRENT_VERSION => $currentVersion,
                    self::INDEX_NEW_VERSION     => $version
                );
            }
        }

        return $componentsToUpdate;
    }

    /**
     * Updates multiple components, while capturing & returning errors and warnings.
     *
     * @param string[] $componentsWithUpdateFile Component names mapped with arrays of update files. Same structure
     *                                           as the result of `getComponentsWithUpdateFile()`.
     * @return array Information about the update process, including:
     *
     *               * **warnings**: The list of warnings that occurred during the update process.
     *               * **errors**: The list of updater exceptions thrown during individual component updates.
     *               * **coreError**: True if an exception was thrown while updating core.
     *               * **deactivatedPlugins**: The list of plugins that were deactivated due to an error in the
     *                                         update process.
     */
    public function updateComponents($componentsWithUpdateFile)
    {
        $warnings = array();
        $errors   = array();
        $deactivatedPlugins = array();
        $coreError = false;

        try {
            $history = Option::get(self::OPTION_KEY_MATOMO_UPDATE_HISTORY);
            $history = explode(',', (string) $history);
            $previousVersion = Option::get(self::getNameInOptionTable('core'));

            if (!empty($previousVersion) && !in_array($previousVersion, $history, true)) {
                // this allows us to see which versions of matomo the user was using before this update so we better understand
                // which version maybe regressed something
                array_unshift( $history, $previousVersion );
                $history = array_slice( $history, 0, 6 ); // lets keep only the last 6 versions
                Option::set(self::OPTION_KEY_MATOMO_UPDATE_HISTORY, implode(',', $history));
            }
        } catch (\Exception $e) {
            // case when the option table is not yet created (before 0.2.10)
        }

        if (!empty($componentsWithUpdateFile)) {
            $currentAccess      = Access::getInstance();
            $hasSuperUserAccess = $currentAccess->hasSuperUserAccess();

            if (!$hasSuperUserAccess) {
                $currentAccess->setSuperUserAccess(true);
            }

            $pluginManager = \Piwik\Plugin\Manager::getInstance();

            // if error in any core update, show message + help message + EXIT
            // if errors in any plugins updates, show them on screen, disable plugins that errored + CONTINUE
            // if warning in any core update or in any plugins update, show message + CONTINUE
            // if no error or warning, success message + CONTINUE
            foreach ($componentsWithUpdateFile as $name => $filenames) {
                try {
                    $warnings = array_merge($warnings, $this->update($name));
                } catch (UpdaterErrorException $e) {
                    $errors[] = $e->getMessage();
                    if ($name == 'core') {
                        $coreError = true;
                        break;
                    } elseif ($pluginManager->isPluginActivated($name) && $pluginManager->isPluginBundledWithCore($name)) {
                        $coreError = true;
                        break;
                    } elseif ($pluginManager->isPluginActivated($name)) {
                        $pluginManager->deactivatePlugin($name);
                        $deactivatedPlugins[] = $name;
                    }
                }
            }

            if (!$hasSuperUserAccess) {
                $currentAccess->setSuperUserAccess(false);
            }
        }

        Filesystem::deleteAllCacheOnUpdate();
        ServerFilesGenerator::createFilesForSecurity();

        $result = array(
            'warnings'  => $warnings,
            'errors'    => $errors,
            'coreError' => $coreError,
            'deactivatedPlugins' => $deactivatedPlugins
        );

        /**
         * Triggered after Piwik has been updated.
         */
        Piwik::postEvent('CoreUpdater.update.end');

        return $result;
    }

    /**
     * Returns any updates that should occur for core and all plugins that are both loaded and
     * installed. Also includes updates required for dimensions.
     *
     * @return string[]|null Returns the result of `getComponentsWithUpdateFile()`.
     */
    public function getComponentUpdates()
    {
        $componentsToCheck = array(
            'core' => Version::VERSION
        );

        $manager = \Piwik\Plugin\Manager::getInstance();
        $plugins = $manager->getLoadedPlugins();
        foreach ($plugins as $pluginName => $plugin) {
            if ($manager->isPluginInstalled($pluginName)) {
                $componentsToCheck[$pluginName] = $plugin->getVersion();
            }
        }

        $columnsVersions = $this->columnsUpdater->getAllVersions($this);
        foreach ($columnsVersions as $component => $version) {
            $componentsToCheck[$component] = $version;
        }

        $componentsWithUpdateFile = $this->getComponentsWithUpdateFile($componentsToCheck);

        if (count($componentsWithUpdateFile) == 0) {
            $this->columnsUpdater->onNoUpdateAvailable($columnsVersions);

            return null;
        }

        return $componentsWithUpdateFile;
    }

    /**
     * Execute multiple migration queries from a single Update file.
     *
     * @param string $file The path to the Updates file.
     * @param Migration[] $migrations An array of migrations
     * @api
     */
    public function executeMigrations($file, $migrations)
    {
        foreach ($migrations as $index => $migration) {
            $migration = $this->keepBcForOldMigrationQueryFormat($index, $migration);
            $this->executeMigration($file, $migration);
        }
    }

    /**
     * @param $file
     * @param Migration $migration
     * @throws UpdaterErrorException
     * @api
     */
    public function executeMigration($file, Migration $migration)
    {
        try {
            $this->executeListenerHook('onStartExecutingMigration', array($file, $migration));

            $migration->exec();

        } catch (\Exception $e) {
            if (!$migration->shouldIgnoreError($e)) {
                $message = sprintf("%s:\nError trying to execute the migration '%s'.\nThe error was: %s",
                                   $file, $migration->__toString(), $e->getMessage());
                throw new UpdaterErrorException($message);
            }
        }

        $this->executeListenerHook('onFinishedExecutingMigration', array($file, $migration));
    }

    private function executeListenerHook($hookName, $arguments)
    {
        foreach ($this->updateObservers as $listener) {
            call_user_func_array(array($listener, $hookName), $arguments);
        }
    }

    private function executeSingleUpdateClass($className)
    {
        $update = StaticContainer::getContainer()->make($className);
        try {
            call_user_func(array($update, 'doUpdate'), $this);
        } catch (\Exception $e) {
            // if an Update file executes PHP statements directly, DB exceptions be handled by executeSingleMigrationQuery, so
            // make sure to check for them here
            if ($e instanceof Zend_Db_Exception) {
                throw new UpdaterErrorException($e->getMessage(), $e->getCode(), $e);
            } else if ($e instanceof MissingFilePermissionException) {
                throw new UpdaterErrorException($e->getMessage(), $e->getCode(), $e);
            }{
                throw $e;
            }
        }
    }

    private function keepBcForOldMigrationQueryFormat($index, $migration)
    {
        if (!is_object($migration)) {
            // keep BC for old format (pre 3.0): array($sqlQuery => $errorCodeToIgnore)
            $migrationFactory = StaticContainer::get('Piwik\Updater\Migration\Factory');
            $migration = $migrationFactory->db->sql($index, $migration);
        }

        return $migration;
    }

    /**
     * Record version of successfully completed component update
     *
     * @param string $name
     * @param string $version
     */
    public static function recordComponentSuccessfullyUpdated($name, $version)
    {
        self::$activeInstance->markComponentSuccessfullyUpdated($name, $version);
    }

    /**
     * Returns the flag name to use in the option table to record current schema version
     * @param string $name
     * @return string
     */
    private static function getNameInOptionTable($name)
    {
        return 'version_' . $name;
    }
}
