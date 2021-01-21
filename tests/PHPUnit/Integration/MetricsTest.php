<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Piwik\Metrics;
use Piwik\Site;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class MetricsTest extends IntegrationTestCase
{
    /**
     * @group Core
     */
    public function testGetVisitsMetricNames()
    {
        $names = Metrics::getVisitsMetricNames();
        $expectedNames = array(
            1 => 'nb_uniq_visitors',
            2 => 'nb_visits',
            39 => 'nb_users',
            3 => 'nb_actions',
            4 => 'max_actions',
            5 => 'sum_visit_length',
            6 => 'bounce_count',
            7 => 'nb_visits_converted',
            44 => 'nb_profilable',
        );
        $this->assertEquals($expectedNames, $names);
    }

    /**
     * @group Core
     */
    public function testGetMappingFromIdToName()
    {
        $mapping = Metrics::getMappingFromNameToId();
        $expectedMapping = array(
            'nb_uniq_visitors' => 1,
            'nb_visits' => 2,
            'nb_actions' => 3,
            'max_actions' => 4,
            'sum_visit_length' => 5,
            'bounce_count' => 6,
            'nb_users' => 39,
            'nb_visits_converted' => 7,
            'nb_conversions' => 8,
            'revenue' => 23,
            'goals' => 10,
            'sum_daily_nb_uniq_visitors' => 11,
            'sum_daily_nb_users' => 40,
            'nb_hits' => 12,
            'sum_time_spent' => 13,
            'sum_time_generation' => 30,
            'nb_hits_with_time_generation' => 31,
            'min_time_generation' => 32,
            'max_time_generation' => 33,
            'exit_nb_uniq_visitors' => 14,
            'exit_nb_visits' => 15,
            'sum_daily_exit_nb_uniq_visitors' => 16,
            'entry_nb_uniq_visitors' => 17,
            'sum_daily_entry_nb_uniq_visitors' => 18,
            'entry_nb_visits' => 19,
            'entry_nb_actions' => 20,
            'entry_sum_visit_length' => 21,
            'entry_bounce_count' => 22,
            'nb_hits_following_search' => 29,
            'quantity' => 24,
            'price' => 25,
            'price_viewed' => 27,
            'orders' => 26,
            'nb_events' => 34,
            'sum_event_value' => 35,
            'min_event_value' => 36,
            'max_event_value' => 37,
            'nb_events_with_value' => 38,
            'nb_impressions' => 41,
            'nb_interactions' => 42,
            'nb_uniq_fingerprints' => 43,
            'nb_profilable' => 44,
        );
        $this->assertEquals($expectedMapping, $mapping);
    }

    public function getLowerValuesBetter()
    {
        return array(
            array('bounce', true),
            array('exit', true),
            array('', false),
            array('price', false),
        );
    }

    /**
     * @dataProvider getLowerValuesBetter
     * @group Core
     */
    public function testIsLowerValueBetter($column, $expected)
    {
        $actual = Metrics::isLowerValueBetter($column);
        $this->assertEquals($expected, $actual);
    }

    public function getUnitColumns()
    {
        return array(
            array('avg_time_on_page', 's'),
            array('sum_time_spent', 's'),
            array('conversion_rate', '%'),
            array('revenue', 'EUR'),
            array('nb_visits', ''),
        );
    }

    /**
     * @dataProvider getUnitColumns
     * @group Core
     */
    public function testGetUnit($column, $expected)
    {
        Site::setSites(array(
            1 => array('name' => 'TestSite', 'currency' => 'EUR')
        ));

        FakeAccess::$superUser = true;

        $actual = Metrics::getUnit($column, 1);
        $this->assertEquals($expected, $actual);
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess()
        );
    }
}
