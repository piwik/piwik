<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Diagnostics\tests\Integration\Commands;

use Piwik\Tests\Fixtures\OneVisitorTwoVisits;
use Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase;
use Piwik\Plugins\VisitsSummary\API as VisitsSummaryAPI;

/**
 * TODO: This could be a unit test if we could inject the ArchiveTableDao in the command
 * @group AnalyzeArchiveTableTest
 */
class AnalyzeArchiveTableTest extends ConsoleCommandTestCase
{
    /**
     * @var OneVisitorTwoVisits
     */
    public static $fixture = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // make sure archiving is initiated so there is data in the archive tables
        VisitsSummaryAPI::getInstance()->get(self::$fixture->idSite, 'month', '2010-03-01');
        VisitsSummaryAPI::getInstance()->get(self::$fixture->idSite, 'month', '2010-03-01', 'browserCode==FF');
        VisitsSummaryAPI::getInstance()->get(self::$fixture->idSite, 'month', '2010-03-01', 'daysSinceFirstVisit==2');
    }

    public function test_CommandOutput_IsAsExpected()
    {
        $expected = <<<OUTPUT
Statistics for the archive_numeric_2010_03 and archive_blob_2010_03 tables:

+-------------------------------------------+------------+---------------+-------------+---------+-----------+----------------+-------------+-------------+
| Group                                     | # Archives | # Invalidated | # Temporary | # Error | # Segment | # Numeric Rows | # Blob Rows | # Blob Data |
+-------------------------------------------+------------+---------------+-------------+---------+-----------+----------------+-------------+-------------+
| week[2010-03-01 - 2010-03-07] idSite = 1  | 7          | 0             | 0           | 0       | 6         | 79             | 97          | %d       |
| month[2010-03-01 - 2010-03-31] idSite = 1 | 7          | 0             | 0           | 0       | 6         | 79             | 97          | %d       |
| day[2010-03-06 - 2010-03-06] idSite = 1   | 7          | 0             | 0           | 0       | 6         | 79             | 73          | %d       |
+-------------------------------------------+------------+---------------+-------------+---------+-----------+----------------+-------------+-------------+

Total # Archives: 21
Total # Invalidated Archives: 0
Total # Temporary Archives: 0
Total # Error Archives: 0
Total # Segment Archives: 18
Total Size of Blobs: 31.%d K


OUTPUT;

        $this->applicationTester->run(array(
            'command' => 'diagnostics:analyze-archive-table',
            'table-date' => '2010_03',
        ));
        $actual = $this->applicationTester->getDisplay();

        $this->assertStringMatchesFormat($expected, $actual);
    }
}

AnalyzeArchiveTableTest::$fixture = new OneVisitorTwoVisits();