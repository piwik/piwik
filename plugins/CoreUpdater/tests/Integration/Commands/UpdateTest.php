<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Plugins\CoreUpdater\tests\Integration\Commands;

use Piwik\Config;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Option;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;
use Piwik\Updates\Updates_2_10_0_b5;
use Piwik\Version;
use Symfony\Component\Console\Helper\QuestionHelper;

require_once PIWIK_INCLUDE_PATH . '/core/Updates/2.10.0-b5.php';

/**
 * @group CoreUpdater
 */
class UpdateTest extends ConsoleCommandTestCase
{
    const VERSION_TO_UPDATE_FROM = '2.9.0';
    const EXPECTED_SQL_FROM_2_10 = "UPDATE report SET reports = REPLACE(reports, 'UserSettings_getBrowserVersion', 'DevicesDetection_getBrowserVersions');";

    private $oldScriptName = null;

    public function setUp(): void
    {
        parent::setUp();

        Option::set('version_core', self::VERSION_TO_UPDATE_FROM);

        $this->oldScriptName = $_SERVER['SCRIPT_NAME'];
        $_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] . " console"; // update won't execute w/o this, see Common::isRunningConsoleCommand()

        ArchiveTableCreator::clear();
        DbHelper::getTablesInstalled($forceReload = true); // force reload so internal cache in Mysql.php is refreshed
        Updates_2_10_0_b5::$archiveBlobTables = null;
    }

    public function tearDown(): void
    {
        $_SERVER['SCRIPT_NAME'] = $this->oldScriptName;

        parent::tearDown();
    }

    public function test_UpdateCommand_SuccessfullyExecutesUpdate()
    {
        $result = $this->applicationTester->run(array(
            'command' => 'core:update',
            '--yes' => true
        ));

        $this->assertEquals(0, $result, $this->getCommandDisplayOutputErrorMessage());

        $this->assertDryRunExecuted($this->applicationTester->getDisplay());

        // make sure update went through
        $this->assertEquals(Version::VERSION, Option::get('version_core'));
    }

    public function test_UpdateCommand_DoesntExecuteSql_WhenUserSaysNo()
    {
        /** @var QuestionHelper $dialog */
        $dialog = $this->application->getHelperSet()->get('question');
        $dialog->setInputStream($this->getInputStream("N\n"));

        $result = $this->applicationTester->run(array(
            'command' => 'core:update'
        ));

        $this->assertEquals(0, $result, $this->getCommandDisplayOutputErrorMessage());

        $this->assertDryRunExecuted($this->applicationTester->getDisplay());

        // make sure update did not go through
        $this->assertEquals(self::VERSION_TO_UPDATE_FROM, Option::get('version_core'));
    }

    public function test_UpdateCommand_DoesNotExecuteUpdate_IfPiwikUpToDate()
    {
        Option::set('version_core', Version::VERSION);

        $result = $this->applicationTester->run(array(
            'command' => 'core:update',
            '--yes' => true
        ));

        $this->assertEquals(0, $result, $this->getCommandDisplayOutputErrorMessage());

        // check no update occurred
        self::assertStringContainsString("Everything is already up to date.", $this->applicationTester->getDisplay());
        $this->assertEquals(Version::VERSION, Option::get('version_core'));
    }

    public function test_UpdateCommand_ReturnsCorrectExitCode_WhenErrorOccurs()
    {
        // create a blob table, then drop it manually so update 2.10.0-b10 will fail
        $tableName = ArchiveTableCreator::getBlobTable(Date::factory('2015-01-01'));
        Db::exec("DROP TABLE $tableName");

        $result = $this->applicationTester->run(array(
            'command' => 'core:update',
            '--yes' => true
        ));

        $this->assertEquals(1, $result, $this->getCommandDisplayOutputErrorMessage());
        self::assertStringContainsString("Matomo could not be updated! See above for more information.", $this->applicationTester->getDisplay());
    }

    private function assertDryRunExecuted($output)
    {
        self::assertStringContainsString("Note: this is a Dry Run", $output);
        self::assertStringContainsString(self::EXPECTED_SQL_FROM_2_10, $output);
    }
}
