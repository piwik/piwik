<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Updates;

use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;

/**
 * Update for version 4.0.0-b3.
 */
class Updates_4_0_0_b4 extends PiwikUpdates
{
    /**
     * @var MigrationFactory
     */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $migrations = [];

        $table = Common::prefixTable('plugin_settings');

        $hasSetting = Db::fetchOne("SELECT COUNT(*) FROM `$table` WHERE plugin_name = 'CoreAdminHome' AND setting_name = 'enable_tracking_cookies'");
        if (!empty($hasSetting)) {
            $migration = $this->migration->db->sql("INSERT INTO `$table` (plugin_name, setting_name, setting_value, json_encoded, user_login)
                VALUES ('CoreAdminHome', 'enable_tracking_cookies', 1, 0, '')");
            $migrations[] = $migration;
        }

        return $migrations;
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));
    }

}
