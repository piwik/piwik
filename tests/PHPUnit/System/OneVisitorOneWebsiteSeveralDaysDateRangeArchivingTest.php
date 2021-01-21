<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\System;

use Piwik\Archive\ArchivePurger;
use Piwik\Archive\Chunk;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Piwik;
use Piwik\Tests\Framework\TestCase\SystemTestCase;
use Piwik\Tests\Fixtures\VisitsOverSeveralDays;

/**
 * Tests some API using range periods & makes sure the correct amount of blob/numeric
 * archives are created.
 *
 * @group OneVisitorOneWebsiteSeveralDaysDateRangeArchivingTest
 * @group Core
 */
class OneVisitorOneWebsiteSeveralDaysDateRangeArchivingTest extends SystemTestCase
{
    /**
     * @var VisitsOverSeveralDays
     */
    public static $fixture = null; // initialized below test definition

    public static function getOutputPrefix()
    {
        return 'oneVisitor_oneWebsite_severalDays_DateRange';
    }

    /**
     * @dataProvider getApiForTesting
     */
    public function testApi($api, $params)
    {
        $this->runApiTests($api, $params);
    }

    public function getApiForTesting()
    {
        $idSite = self::$fixture->idSite;

        $apiToCall = array('Actions.getPageUrls',
                           'VisitsSummary.get',
                           'Resolution.getResolution',
                           'VisitFrequency.get',
                           'VisitTime.getVisitInformationPerServerTime');

        // 2 segments: ALL and another way of expressing ALL but triggering the Segment code path
        // 2 segments: ALL and another way of expressing ALL but triggering the Segment code path
        $segments = array(
            false,
            'countryCode!=aa',
            'pageUrl!=ThisIsNotKnownPageUrl',
        );

        // Running twice just as health check that second call also works
        $result = array();
        for ($i = 0; $i <= 1; $i++) {
            foreach ($segments as $segment) {
                $result[] = array($apiToCall, array('idSite'  => $idSite, 'date' => '2010-12-15,2011-01-15',
                                                    'periods' => array('range'),
                                                    'segment' => $segment,
                                                    'otherRequestParameters' => array(
                                                        'flat'                   => '0',
                                                        'expanded'               => '0'
                                                    ),
                ));
            }
        }

        // Testing Date range in January only
        // Because of flat=1, this test will archive all sub-tables
        $result[] = array('Actions.getPageUrls', array('idSite'  => $idSite, 'date' => '2011-01-01,2011-02-01',
                                                       'periods' => array('range'),
                                                       'otherRequestParameters' => array(
                                                           'flat'                   => '1',
                                                           'expanded'               => '0'
                                                       ),
                                                       'testSuffix' => '_periodIsRange_flattened_')
        );
        // testing the same with expanded=1 should not create new archive records
        $result[] = array('Actions.getPageUrls', array('idSite'  => $idSite, 'date' => '2011-01-01,2011-02-01',
                                                       'periods' => array('range'),
                                                       'otherRequestParameters' => array(
                                                           'flat'                   => '0',
                                                           'expanded'               => '1'
                                                       ),
                                                       'testSuffix' => '_periodIsRange_expanded_')
        );
        return $result;
    }

    /**
     *  Check that requesting period "Range" means only processing
     *  the requested Plugin blob (Actions in this case), not all Plugins blobs
     *
     * @depends      testApi
     */
    public function test_checkArchiveRecords_whenPeriodIsRange()
    {
        $archivePurger = StaticContainer::get(ArchivePurger::class);
        foreach (self::$fixture->dateTimes as $date) {
            $archivePurger->purgeInvalidatedArchivesFrom(Date::factory($date));
        }

        // we expect 6 blobs for Actions plugins, because flat=1 or expanded=1 was not set
        // so we only archived the parent table
        $expectedActionsBlobs = 6;

        // When flat=1, Actions plugin will process 5 + 1 extra chunk blobs (URL = 'http://example.org/sub1/sub2/sub3/news')
        $expectedActionsBlobsWhenFlattened = $expectedActionsBlobs + 1;

        $tests = array(
            'archive_blob_2010_12'    => ( ($expectedActionsBlobs+1) /*Actions*/
                    + 2 /* Resolution */
                    + 2 /* VisitTime */) * 3,

            /**
             *  In Each "Period=range" Archive, we expect following non zero numeric entries:
             *                 6 metrics + 1 flag  // VisitsSummary
             *               + 2 metrics + 1 flag // Actions
             *               + 1 flag // Resolution
             *               + 1 flag // VisitTime
             *               = 12
             *
             *   because we call VisitFrequency.get, this creates archives for visitorType==returning
             *   and visitorType==new segment.
             *          -> There are two archives for each segment (one for "countryCode!=aa"
             *                      and VisitFrequency creates two more.
             *
             * So each period=range will have = 12 records + (6 metrics + 2 flags // VisitsSummary + 3 metrics // VisitorInterest)
             *                                = 23
             *
             * Total expected records = count unique archives * records per archive
             *                        = 3 * 24
             *                        = 72
             */
            'archive_numeric_2010_12' => 72,

            /**
             * In the January date range,
             * we archive only Actions plugins.
             * It is flattened so all 3 sub-tables should be archived.
             */
            'archive_blob_2011_01'    => $expectedActionsBlobsWhenFlattened,

            /**
             *   6 metrics + 1 flag // VisitsSummary
             * + 2 metrics + 1 flag // Actions
             */
            'archive_numeric_2011_01' => (7 + 3),

            // nothing in Feb
            'archive_blob_2011_02'    => 0,
            'archive_numeric_2011_02' => 0,
        );
        foreach ($tests as $table => $expectedRows) {
            if ($expectedRows === 0 && !$this->tableExists($table)) {
                continue;
            }

            $sql = "SELECT count(*) FROM " . Common::prefixTable($table) . " WHERE period = " . Piwik::$idPeriods['range'];
            $countBlobs = Db::get()->fetchOne($sql);

            if($expectedRows != $countBlobs) {
                $this->printDebugWhenTestFails($table);
            }
            $this->assertEquals($expectedRows, $countBlobs, "$table expected $expectedRows, got $countBlobs");
        }
    }

    /**
     *  Check that requesting period "Range" means only processing
     *  the requested Plugin blob (Actions in this case), not all Plugins blobs
     *
     * @depends      testApi
     */
    public function test_checkArchiveRecords_shouldMergeSubtablesIntoOneRow()
    {
        $tests = array(
            'archive_blob_2010_12' => 3,

            /**
             * In the January date range,
             * we archive only Actions plugins.
             * It is flattened so all 3 sub-tables should be archived.
             */
            'archive_blob_2011_01'    => 3,
        );
        $chunk = new Chunk();
        $chunkName = $chunk->getRecordNameForTableId('Actions_actions_url', 0);

        foreach ($tests as $table => $expectedNumSubtables) {
            $sql = "SELECT value FROM " . Common::prefixTable($table) . " WHERE period = " . Piwik::$idPeriods['range'] . " and `name` ='$chunkName'";
            $blob = Db::get()->fetchOne($sql);
            $blob = gzuncompress($blob);
            $blob = unserialize($blob);
            $countSubtables = count($blob);

            $this->assertEquals($expectedNumSubtables, $countSubtables, "Actions_actions_url_chunk_0_99 in $table expected to contain $expectedNumSubtables subtables, got $countSubtables");
        }
    }

    /**
     * @param $table
     */
    protected function printDebugWhenTestFails($table)
    {
        $data = Db::get()->fetchAll("SELECT * FROM " . Common::prefixTable($table) . " WHERE period = " . Piwik::$idPeriods['range'] . " ORDER BY idarchive ASC");
        if (strpos($table, 'blob') !== false) {
            $data = array_map(function ($r) {
                unset($r['value']);
                return $r;
            }, $data);
        }
        var_export($data);

        $idArchives = array();
        foreach ($data as $row) {
            $idArchives[] = $row['idarchive'];
        }
        $idArchives = array_unique($idArchives);
        foreach ($idArchives as $idArchive) {
            $numericTable = str_replace("blob", "numeric", Common::prefixTable($table));
            var_export(Db::get()->fetchAll("SELECT idarchive, name FROM " . $numericTable . " WHERE idarchive = ? AND name LIKE 'done%' LIMIT 1 ", $idArchive));
        }
    }

    private function tableExists($table)
    {
        return (bool) Db::fetchOne("SHOW TABLES LIKE '$table'");
    }

}

OneVisitorOneWebsiteSeveralDaysDateRangeArchivingTest::$fixture = new VisitsOverSeveralDays();
