<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Piwik\Common;
use Piwik\Config;
use Piwik\DataAccess\TableMetadata;
use Piwik\Db;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Core
 */
class DbTest extends IntegrationTestCase
{
    private $dbReaderConfigBackup;

    public function setUp(): void
    {
        parent::setUp();

        $this->dbReaderConfigBackup = Config::getInstance()->database_reader;
    }

    public function tearDown(): void
    {
        Db::destroyDatabaseObject();
        Config::getInstance()->database_reader = $this->dbReaderConfigBackup;
        parent::tearDown();
    }

    // this test is for PDO which will fail if execute() is called w/ a null param value
    public function test_insertWithNull()
    {
        $GLOBALS['abc']=1;
        $table = Common::prefixTable('testtable');
        Db::exec("CREATE TABLE `$table` (
                      testid BIGINT NOT NULL AUTO_INCREMENT,
                      testvalue BIGINT NULL,
                      PRIMARY KEY (testid)
                  )");

        Db::query("INSERT INTO `$table` (testvalue) VALUES (?)", [4]);
        Db::query("INSERT INTO `$table` (testvalue) VALUES (?)", [null]);

        $values = Db::fetchAll("SELECT testid, testvalue FROM `$table`");

        $expected = [
            ['testid' => 1, 'testvalue' => 4],
            ['testid' => 2, 'testvalue' => null],
        ];

        $this->assertEquals($expected, $values);
    }

    public function test_getColumnNamesFromTable()
    {
        $this->assertColumnNames('access', array('idaccess', 'login', 'idsite', 'access'));
        $this->assertColumnNames('option', array('option_name', 'option_value', 'autoload'));
    }

    public function test_getDb()
    {
        $db = Db::get();
        $this->assertNotEmpty($db);
        $this->assertTrue($db instanceof Db\AdapterInterface);
    }

    public function test_hasReaderDatabaseObject_byDefaultNotInUse()
    {
        $this->assertFalse(Db::hasReaderDatabaseObject());
    }

    public function test_hasReaderConfigured_byDefaultNotConfigured()
    {
        $this->assertFalse(Db::hasReaderConfigured());
    }

    public function test_getReader_whenNotConfigured_StillReturnsRegularDbConnection()
    {
        $this->assertFalse(Db::hasReaderConfigured());// ensure no reader is configured
        $db = Db::getReader();
        $this->assertNotEmpty($db);
        $this->assertTrue($db instanceof Db\AdapterInterface);
    }

    public function test_withReader()
    {
        Config::getInstance()->database_reader = Config::getInstance()->database;

        $this->assertFalse(Db::hasReaderDatabaseObject());
        $this->assertTrue(Db::hasReaderConfigured());

        $db = Db::getReader();
        $this->assertNotEmpty($db);
        $this->assertTrue($db instanceof Db\AdapterInterface);

        $this->assertTrue(Db::hasReaderDatabaseObject());
        Db::destroyDatabaseObject();
        $this->assertFalse(Db::hasReaderDatabaseObject());
    }

    public function test_withReader_createsDifferentConnectionForDb()
    {
        Config::getInstance()->database_reader = Config::getInstance()->database;

        $db = Db::getReader();
        $this->assertNotSame($db->getConnection(), Db::get()->getConnection());
    }

    public function test_withoutReader_usesSameDbConnection()
    {
        $this->assertFalse(Db::hasReaderConfigured());
        $this->assertFalse(Db::hasReaderDatabaseObject());

        $db = Db::getReader();
        $this->assertSame($db->getConnection(), Db::get()->getConnection());
    }

    private function assertColumnNames($tableName, $expectedColumnNames)
    {
        $tableMetadataAccess = new TableMetadata();
        $colmuns = $tableMetadataAccess->getColumns(Common::prefixTable($tableName));

        $this->assertEquals($expectedColumnNames, $colmuns);
    }

    /**
     * @dataProvider getIsOptimizeInnoDBTestData
     */
    public function test_isOptimizeInnoDBSupported_ReturnsCorrectResult($version, $expectedResult)
    {
        $result = Db::isOptimizeInnoDBSupported($version);
        $this->assertEquals($expectedResult, $result);
    }

    /**
     * @dataProvider getDbAdapter
     */
    public function test_SqlMode_IsSet_PDO($adapter, $expectedClass)
    {
        Db::destroyDatabaseObject();
        Config::getInstance()->database['adapter'] = $adapter;
        $db = Db::get();
        // make sure test is useful and setting adapter works
        $this->assertInstanceOf($expectedClass, $db);
        $result = $db->fetchOne('SELECT @@SESSION.sql_mode');

        $expected = 'NO_AUTO_VALUE_ON_ZERO';
        $this->assertSame($expected, $result);
    }

    public function test_getDbLock_shouldThrowAnException_IfDbLockNameIsTooLong()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('name has to be 64 characters or less');

        Db::getDbLock(str_pad('test', 65, '1'));
    }

    public function test_getDbLock_shouldGetLock()
    {
        $db = Db::get();
        $this->assertTrue(Db::getDbLock('MyLock'));
        // same session still has lock
        $this->assertTrue(Db::getDbLock('MyLock'));

        Db::setDatabaseObject(null);
        // different session, should not be able to acquire lock
        $this->assertFalse(Db::getDbLock('MyLock', 1));
        // different session cannot release lock
        $this->assertFalse(Db::releaseDbLock('MyLock'));
        Db::destroyDatabaseObject();

        // release lock again by using previous session
        Db::setDatabaseObject($db);
        $this->assertTrue(Db::releaseDbLock('MyLock'));
        Db::destroyDatabaseObject();
    }

    /**
     * @dataProvider getDbAdapter
     */
    public function test_getRowCount($adapter, $expectedClass)
    {
        Db::destroyDatabaseObject();
        Config::getInstance()->database['adapter'] = $adapter;
        $db = Db::get();
        // make sure test is useful and setting adapter works
        $this->assertInstanceOf($expectedClass, $db);

        $result = $db->query('select 21');
        $this->assertEquals(1, $db->rowCount($result));
    }

    public function getDbAdapter()
    {
        return array(
            array('Mysqli', 'Piwik\Db\Adapter\Mysqli'),
            array('PDO\MYSQL', 'Piwik\Db\Adapter\Pdo\Mysql')
        );
    }

    public function getIsOptimizeInnoDBTestData()
    {
        return array(
            array("10.0.17-MariaDB-1~trusty", false),
            array("10.1.1-MariaDB-1~trusty", true),
            array("10.2.0-MariaDB-1~trusty", true),
            array("10.6.19-0ubuntu0.14.04.1", false),

            // for sanity. maybe not ours.
            array("", false),
            array(0, false),
            array(false, false),
            array("slkdf(@*#lkesjfMariaDB", false),
            array("slkdfjq3rujlkv", false),
        );
    }
}
