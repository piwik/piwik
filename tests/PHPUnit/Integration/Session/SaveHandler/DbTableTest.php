<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace PHPUnit\Integration\Session\SaveHandler;

use Piwik\Session;
use Piwik\Session\SaveHandler\DbTable;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class DbTableTest extends IntegrationTestCase
{
    /**
     * @var DbTable
     */
    private $testInstance;

    public function setUp(): void
    {
        parent::setUp();
        $this->testInstance = new DbTable(Session::getDbTableConfig());
    }

    public function test_read_returnsTheSessionDataCorrectly()
    {
        $this->testInstance->write('testid', 'testdata');

        $result = $this->testInstance->read('testid');

        $this->assertEquals('testdata', $result);
    }

    public function test_read_noticesWhenSessionDataIsTooLarge()
    {
        $data = str_repeat('1', DbTable::SESSION_DATA_MAX_LEN + 10);

        $this->testInstance->write('testid', $data);

        $result = $this->testInstance->read('testid');

        $this->assertTrue(DbTable::$wasSessionToLargeToRead);
        $this->assertEquals(DbTable::SESSION_DATA_MAX_LEN, strlen($result));
    }
}