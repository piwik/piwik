<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Tests\Integration\ArchiveProcessor;


use Piwik\Archive\ArchiveInvalidator;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\ArchiveProcessor\Loader;
use Piwik\ArchiveProcessor\PluginsArchiver;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataAccess\ArchiveTableCreator;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period\Factory;
use Piwik\Piwik;
use Piwik\Plugins\ExamplePlugin\Archiver;
use Piwik\Segment;
use Piwik\Sequence;
use Piwik\Site;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class LoaderTest extends IntegrationTestCase
{
    protected static function beforeTableDataCached()
    {
        parent::beforeTableDataCached();

        Fixture::createWebsite('2012-02-03 00:00:00');
        Fixture::createWebsite('2012-02-03 00:00:00');
    }

    /**
     * @dataProvider getTestDataForArchiving
     */
    public function test_pluginOnlyArchivingCreatesAndReusesCorrectArchives($archiveData, $params, $expectedArchives, $archiveTwice)
    {
        $_GET['pluginOnly'] = 1;
        $_GET['trigger'] = 'archivephp';

        Date::$now = strtotime('2018-03-04 05:00:00');

        list($idSite, $period, $date, $segment, $plugin, $report) = $params;

        $t = Fixture::getTracker($idSite, $date);
        $t->setUrl('http://slkdfj.com');
        $t->doTrackPageView('alsdkjf');

        $params = new Parameters(new Site($idSite), Factory::build($period, $date), new Segment($segment, [$idSite]));
        $params->setRequestedPlugin($plugin);
        if ($report) {
            $params->setArchiveOnlyReport($report);
        }

        $this->insertArchiveData($archiveData);

        $loader = new Loader($params);
        $loader->prepareArchive($params->getRequestedPlugin());

        if ($archiveTwice) {
            if (is_array($archiveTwice)) {
                list($idSite2, $period2, $date2, $segment2, $plugin2, $report2) = $archiveTwice;

                $params2 = new Parameters(new Site($idSite2), Factory::build($period2, $date2), new Segment($segment2, [$idSite2]));
                $params2->setRequestedPlugin($plugin2);
                if ($report2) {
                    $params2->setArchiveOnlyReport($report2);
                }
            } else {
                $params2 = $params;
            }

            $loader2 = new Loader($params2);
            $loader2->prepareArchive($params->getRequestedPlugin());
        }

        $actualArchives = $this->getArchives();
        if ($actualArchives != $expectedArchives) {
            var_export($actualArchives);
        }
        $this->assertEquals($expectedArchives, $actualArchives);
    }

    public function getTestDataForArchiving()
    {
        $pluginSpecificArchive = [1, 'day', '2018-03-03', '', 'ExamplePlugin', false];

        $reportSpecificArchive1 = [1, 'day', '2018-03-03', '', 'ExamplePlugin', Archiver::EXAMPLEPLUGIN_METRIC_NAME];
        $reportSpecificArchive2 = [1, 'day', '2018-03-03', '', 'ExamplePlugin', Archiver::EXAMPLEPLUGIN_CONST_METRIC_NAME];

        $unloadedPluginArchive = [1, 'day', '2018-03-03', '', 'MyImaginaryPlugin', false];

        return [
            // no archive, archive specific plugin
            [
                [],
                $pluginSpecificArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'bounce_count',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'max_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_profilable',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_uniq_visitors',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                false,
            ],

            // all plugins, recent, archive specific plugin
            [
                [
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'done', 'value' => ArchiveWriter::DONE_OK, 'ts_archived' => '2018-03-04 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits', 'value' => 12, 'ts_archived' => '2018-03-04 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits_converted', 'value' => 3, 'ts_archived' => '2018-03-04 04:50:00'],
                ],
                $pluginSpecificArchive,
                array ( // done archive already exists and is recent, so we don't archive the plugin
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '12',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits_converted',
                        'value' => '3',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                false,
            ],

            // visitssummary, recent, archive specific plugin
            [
                [
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'done.VisitsSummary', 'value' => ArchiveWriter::DONE_OK, 'ts_archived' => '2018-03-04 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits', 'value' => 12, 'ts_archived' => '2018-03-04 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits_converted', 'value' => 3, 'ts_archived' => '2018-03-04 04:50:00'],
                ],
                $pluginSpecificArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '12',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits_converted',
                        'value' => '3',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                false,
            ],

            // all plugins, old, archive specific plugin
            [
                [
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'done', 'value' => ArchiveWriter::DONE_OK, 'ts_archived' => '2018-03-01 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits', 'value' => 12, 'ts_archived' => '2018-03-01 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits_converted', 'value' => 3, 'ts_archived' => '2018-03-01 04:50:00'],
                ],
                $pluginSpecificArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '12',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits_converted',
                        'value' => '3',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                false,
            ],

            // visitssummary, old, archive specific plugin
            [
                [
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'done.VisitsSummary', 'value' => ArchiveWriter::DONE_OK, 'ts_archived' => '2018-03-01 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits', 'value' => 12, 'ts_archived' => '2018-03-01 04:50:00'],
                    ['idarchive' => 1, 'idsite' => 1, 'date1' => '2018-03-03', 'date2' => '2018-03-03', 'period' => 1, 'name' => 'nb_visits_converted', 'value' => 3, 'ts_archived' => '2018-03-01 04:50:00'],
                ],
                $pluginSpecificArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '12',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits_converted',
                        'value' => '3',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                false,
            ],

            // no archive, archive specific plugin, archive specific plugin again
            [
                [],
                $pluginSpecificArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'bounce_count',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'max_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_profilable',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_uniq_visitors',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric2',
                        'value' => '1',
                    ),
                ),
                true,
            ],

            // no archive, archive specific report, archive specific report again
            [
                [],
                $reportSpecificArchive1,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'bounce_count',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'max_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_profilable',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_uniq_visitors',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '5',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '5',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                ),
                true,
            ],

            // no archive, archive specific report, archive different report again
            [
                [],
                $reportSpecificArchive1,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'bounce_count',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'max_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_profilable',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_uniq_visitors',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '5',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'ExamplePlugin_example_metric',
                        'value' => '-603',
                    ),
                    array (
                        'idarchive' => '3',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.ExamplePlugin',
                        'value' => '5',
                    ),
                ),
                $reportSpecificArchive2,
            ],

            // no archive, unloaded plugin
            [
                [],
                $unloadedPluginArchive,
                array (
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'bounce_count',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.VisitsSummary',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'max_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_actions',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_profilable',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_uniq_visitors',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '1',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'nb_visits',
                        'value' => '1',
                    ),
                    array (
                        'idarchive' => '2',
                        'idsite' => '1',
                        'date1' => '2018-03-03',
                        'date2' => '2018-03-03',
                        'period' => '1',
                        'name' => 'done.MyImaginaryPlugin',
                        'value' => '1',
                    ),
                ),
                false,
            ],
        ];
    }

    public function test_loadExistingArchiveIdFromDb_returnsFalsesIfNoArchiveFound()
    {
        $params = new Parameters(new Site(1), Factory::build('day', '2015-03-03'), new Segment('', [1]));
        $loader = new Loader($params);

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();

        $this->assertEquals([false, false, false, false, false], $archiveInfo);
    }

    /**
     * @dataProvider getTestDataForLoadExistingArchiveIdFromDbDebugConfig
     */
    public function test_loadExistingArchiveIdFromDb_returnsFalsesPeriodIsForcedToArchive($periodType, $configSetting)
    {
        $date = $periodType == 'range' ? '2015-03-03,2015-03-04' : '2015-03-03';
        $params = new Parameters(new Site(1), Factory::build($periodType, $date), new Segment('', [1]));
        $this->insertArchive($params);

        $loader = new Loader($params);

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();

        $this->assertNotEmpty($archiveInfo[4]);
        $this->assertLessThanOrEqual(time(), strtotime($archiveInfo[4]));
        unset($archiveInfo[4]);

        $this->assertNotEquals([false, false, false, false], $archiveInfo);

        Config::getInstance()->Debug[$configSetting] = 1;

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();
        $this->assertEquals([false, false, false, false], $archiveInfo);
    }

    public function getTestDataForLoadExistingArchiveIdFromDbDebugConfig()
    {
        return [
            ['day', 'always_archive_data_day'],
            ['week', 'always_archive_data_period'],
            ['month', 'always_archive_data_period'],
            ['year', 'always_archive_data_period'],
            ['range', 'always_archive_data_range'],
        ];
    }

    public function test_loadExistingArchiveIdFromDb_returnsArchiveIfArchiveInThePast()
    {
        $params = new Parameters(new Site(1), Factory::build('month', '2015-03-03'), new Segment('', [1]));
        $this->insertArchive($params);

        $loader = new Loader($params);

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();

        $this->assertNotEmpty($archiveInfo[4]);
        unset($archiveInfo[4]);

        $this->assertEquals([['1'], '10', '0', true], $archiveInfo);
    }

    public function test_loadExistingArchiveIdFromDb_returnsArchiveIfForACurrentPeriod_AndNewEnough()
    {
        $params = new Parameters(new Site(1), Factory::build('day', 'now'), new Segment('', [1]));
        $this->insertArchive($params, $tsArchived = time() - 1);

        $loader = new Loader($params);

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();

        $this->assertNotEmpty($archiveInfo[4]);
        unset($archiveInfo[4]);

        $this->assertEquals([['1'], '10', '0', true], $archiveInfo);
    }

    public function test_loadExistingArchiveIdFromDb_returnsNoArchiveIfForACurrentPeriod_AndNoneAreNewEnough()
    {
        $params = new Parameters(new Site(1), Factory::build('month', 'now'), new Segment('', [1]));
        $this->insertArchive($params, $tsArchived = time() - 3 * 3600);

        $loader = new Loader($params);

        $archiveInfo = $loader->loadExistingArchiveIdFromDb();

        $this->assertNotEmpty($archiveInfo[4]);
        unset($archiveInfo[4]);

        $this->assertEquals([false, '10', '0', true], $archiveInfo); // visits are still returned as this was the original behavior
    }

    /**
     * @dataProvider getTestDataForGetReportsToInvalidate
     */
    public function test_getReportsToInvalidate_returnsCorrectReportsToInvalidate($rememberedReports, $idSite, $period, $date, $segment, $expected)
    {
        $invalidator = StaticContainer::get(ArchiveInvalidator::class);
        foreach ($rememberedReports as $entry) {
            $invalidator->rememberToInvalidateArchivedReportsLater($entry['idSite'], Date::factory($entry['date']));
        }

        $params = new Parameters(new Site($idSite), Factory::build($period, $date), new Segment($segment, [$idSite]));
        $loader = new Loader($params);

        $reportsToInvalidate = $loader->getReportsToInvalidate();
        foreach ($reportsToInvalidate as &$sites) {
            sort($sites);
        }
        $this->assertEquals($expected, $reportsToInvalidate);
    }

    public function getTestDataForGetReportsToInvalidate()
    {
        return [
            // two dates for one site
            [
                [
                    ['idSite' => 1, 'date' => '2013-04-05'],
                    ['idSite' => 1, 'date' => '2013-03-05'],
                    ['idSite' => 2, 'date' => '2013-05-05'],
                ],
                1,
                'day',
                '2013-04-05',
                '',
                [
                    '2013-04-05' => [1],
                ],
            ],

            // no dates for a site
            [
                [
                    ['idSite' => '', 'date' => '2013-04-05'],
                    ['idSite' => '', 'date' => '2013-04-06'],
                    ['idSite' => 2, 'date' => '2013-05-05'],
                ],
                1,
                'day',
                '2013-04-05',
                'browserCode==ff',
                [],
            ],

            // day period not within range
            [
                [
                    ['idSite' => 1, 'date' => '2014-03-04'],
                    ['idSite' => 1, 'date' => '2014-03-06'],
                ],
                1,
                'day',
                '2013-03-05',
                '',
                [],
            ],

            // non-day periods
            [
                [
                    ['idSite' => 1, 'date' => '2014-03-01'],
                    ['idSite' => 1, 'date' => '2014-03-06'],
                    ['idSite' => 2, 'date' => '2014-03-01'],
                ],
                1,
                'week',
                '2014-03-01',
                '',
                [
                    '2014-03-01' => [1, 2],
                ],
            ],
            [
                [
                    ['idSite' => 1, 'date' => '2014-02-01'],
                    ['idSite' => 1, 'date' => '2014-03-06'],
                    ['idSite' => 2, 'date' => '2014-03-05'],
                    ['idSite' => 2, 'date' => '2014-03-06'],
                ],
                1,
                'month',
                '2014-03-01',
                '',
                [
                    '2014-03-06' => [1, 2],
                ],
            ],
        ];
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteIsNotUsingTracker()
    {
        Piwik::addAction('CronArchive.getIdSitesNotUsingTracker', function (&$idSites) {
            $idSites[] = 1;
        });

        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('', []));
        $loader = new Loader($params);

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteHasVisitWithinTimeframe_ForPeriodDay()
    {
        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('', []));
        $loader = new Loader($params);

        $tracker = Fixture::getTracker(1, '2016-02-03 04:00:00');
        $tracker->setUrl('http://example.org/abc');
        Fixture::checkResponse($tracker->doTrackPageView('abc'));

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteHasVisitWithinTimeframe_ForPeriodYear()
    {
        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('', []));
        $loader = new Loader($params);

        $tracker = Fixture::getTracker(1, '2016-03-04 00:00:00');
        $tracker->setUrl('http://example.org/abc');
        Fixture::checkResponse($tracker->doTrackPageView('abc'));

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteHasChildArchiveWithinPeriod_ForPeriodWeek()
    {
        $params = new Parameters(new Site(1), Factory::build('week', '2016-02-03'), new Segment('browserCode==ch', []));
        $loader = new Loader($params);

        $dayParams = new Parameters(new Site(1), Factory::build('day', '2016-02-03'), new Segment('', []));

        $archiveWriter = new ArchiveWriter($dayParams);
        $archiveWriter->initNewArchive();
        $archiveWriter->finalizeArchive();

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteHasChildArchiveWithinPeriod_ForPeriodMonth_WhenWeekChildSpansTwoMonths()
    {
        $params = new Parameters(new Site(1), Factory::build('month', '2016-02-01'), new Segment('browserCode==ch', []));
        $loader = new Loader($params);

        $dayParams = new Parameters(new Site(1), Factory::build('week', '2016-02-01'), new Segment('', []));

        $archiveWriter = new ArchiveWriter($dayParams);
        $archiveWriter->initNewArchive();
        $archiveWriter->finalizeArchive();

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsFalseIfSiteHasChildArchiveWithinPeriod_ForPeriodYear()
    {
        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('browserCode==ch', []));
        $loader = new Loader($params);

        $dayParams = new Parameters(new Site(1), Factory::build('day', '2016-03-04'), new Segment('', []));

        $archiveWriter = new ArchiveWriter($dayParams);
        $archiveWriter->initNewArchive();
        $archiveWriter->finalizeArchive();

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_returnsTrueIfThereAreNoVisits_NoChildArchives_AndSiteIsUsingTheTracker()
    {
        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('', []));
        $loader = new Loader($params);

        $this->assertTrue($loader->canSkipThisArchive());

        $tracker = Fixture::getTracker(2, '2016-03-04 00:00:00');
        $tracker->setUrl('http://example.org/abc');
        Fixture::checkResponse($tracker->doTrackPageView('abc'));

        $this->assertTrue($loader->canSkipThisArchive());
    }

    public function test_canSkipThisArchive_ignoresSegments()
    {
        $params = new Parameters(new Site(1), Factory::build('year', '2016-02-03'), new Segment('browserCode==ch', []));
        $loader = new Loader($params);

        $tracker = Fixture::getTracker(1, '2016-03-04 00:00:00');
        $tracker->setUrl('http://example.org/abc');
        Fixture::checkResponse($tracker->doTrackPageView('abc'));

        $this->assertFalse($loader->canSkipThisArchive());
    }

    public function test_forcePluginArchiving_createsPluginSpecificArchive()
    {
        $_GET['trigger'] = 'archivephp';
        $_GET['pluginOnly'] = '1';

        $params = new Parameters(new Site(1), Factory::build('day', '2016-02-03'), new Segment('', [1]));
        $loader = new Loader($params);

        $tracker = Fixture::getTracker(1, '2016-02-03 00:00:00');
        $tracker->setUrl('http://example.org/abc');
        Fixture::checkResponse($tracker->doTrackPageView('abc'));

        $idArchive = $loader->prepareArchive('Actions')[0];
        $this->assertNotEmpty($idArchive);

        $table = ArchiveTableCreator::getNumericTable(Date::factory('2016-02-03'));
        $doneFlag = Db::fetchOne("SELECT `name` FROM `$table` WHERE `name` LIKE 'done%' AND idarchive IN (" . implode(',', $idArchive) . ")");
        $this->assertEquals('done.Actions', $doneFlag);
    }

    private function insertArchive(Parameters $params, $tsArchived = null, $visits = 10)
    {
        $archiveWriter = new ArchiveWriter($params);
        $archiveWriter->initNewArchive();
        $archiveWriter->insertRecord('nb_visits', $visits);
        $archiveWriter->finalizeArchive();

        if ($tsArchived) {
            Db::query("UPDATE " . ArchiveTableCreator::getNumericTable($params->getPeriod()->getDateStart()) . " SET ts_archived = ?",
                [Date::factory($tsArchived)->getDatetime()]);
        }
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    private function insertArchiveData($archiveRows)
    {
        foreach ($archiveRows as $row) {
            if (!empty($row['is_blob_data'])) {
                $row['value'] = gzcompress($row['value']);
            }

            $d = Date::factory($row['date1']);
            $table = !empty($row['is_blob_data']) ? ArchiveTableCreator::getBlobTable($d) : ArchiveTableCreator::getNumericTable($d);
            $tsArchived = isset($row['ts_archived']) ? $row['ts_archived'] : Date::now()->getDatetime();

            Db::query("INSERT INTO `$table` (idarchive, idsite, period, date1, date2, `name`, `value`, ts_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$row['idarchive'], $row['idsite'], $row['period'], $row['date1'], $row['date2'], $row['name'], $row['value'], $tsArchived]);
        }

        if (!empty($archiveRows)) {
            $idarchives = array_column($archiveRows, 'idarchive');
            $max = max($idarchives);

            $seq = new Sequence(ArchiveTableCreator::getNumericTable(Date::factory($archiveRows[0]['date1'])));
            $seq->create($max);
        }
    }

    private function getArchives()
    {
        $results = [];
        foreach (ArchiveTableCreator::getTablesArchivesInstalled('numeric', true) as $table) {
            $queryResults = Db::fetchAll("SELECT idarchive, idsite, date1, date2, period, `name`, `value` FROM `$table`");
            $results = array_merge($results, $queryResults);
        }
        return $results;
    }
}
