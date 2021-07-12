<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Goals\tests\System;

use Piwik\Common;
use Piwik\Db;
use Piwik\Plugins\Goals\API;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group Goals
 * @group NumericAttributeGoalTrackingTest
 */
class NumericAttributeGoalTrackingTest extends IntegrationTestCase
{
    private $idSite = 1;
    private $visitDurationIdGoal = 1;
    private $visitPageViewsIdGoal = 2;

    protected static function beforeTableDataCached()
    {
        parent::beforeTableDataCached();

        $idSite = Fixture::createWebsite('2012-02-03 04:05:56');
        API::getInstance()->addGoal($idSite, 'visit duration goal', 'visit_duration', 2.5, 'greater_than');
        API::getInstance()->addGoal($idSite, 'visit pageviews goal', 'visit_nb_pageviews', 3, 'greater_than');
    }

    public function test_trackingVisitDurationGoal()
    {
        $t = Fixture::getTracker($this->idSite, '2013-02-03 04:00:00');
        $this->assertEquals(0, $this->getConversionCount($this->visitDurationIdGoal));

        Fixture::checkResponse($t->doTrackPageView('test page view'));
        $this->assertEquals(0, $this->getConversionCount($this->visitDurationIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:02:00');
        Fixture::checkResponse($t->doTrackPageView('another page view'));
        $this->assertEquals(0, $this->getConversionCount($this->visitDurationIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:02:29');
        Fixture::checkResponse($t->doTrackEvent('event category', 'blah', 'blah'));
        $this->assertEquals(0, $this->getConversionCount($this->visitDurationIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:02:31');
        Fixture::checkResponse($t->doTrackEvent('event category', 'blah 2', 'blah 2'));
        $this->assertEquals(1, $this->getConversionCount($this->visitDurationIdGoal));
    }

    public function test_trackingPageViewsGoal()
    {
        $t = Fixture::getTracker($this->idSite, '2013-02-03 04:00:00');
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        Fixture::checkResponse($t->doTrackPageView('test page view'));
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        // search should not be counted
        $t->setForceVisitDateTime('2013-02-03 04:01:16');
        Fixture::checkResponse($t->doTrackSiteSearch('keyword', 'category', 2));
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:00:05');
        Fixture::checkResponse($t->doTrackPageView('another page view'));
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:01:05');
        Fixture::checkResponse($t->doTrackPageView('one more page view'));
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        // event should not be counted
        $t->setForceVisitDateTime('2013-02-03 04:01:16');
        Fixture::checkResponse($t->doTrackEvent('event category', 'blah 2', 'blah 2'));
        $this->assertEquals(0, $this->getConversionCount($this->visitPageViewsIdGoal));

        $t->setForceVisitDateTime('2013-02-03 04:01:59');
        Fixture::checkResponse($t->doTrackPageView('and another page view'));
        $this->assertEquals(1, $this->getConversionCount($this->visitPageViewsIdGoal));
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    private function getConversionCount($idGoal)
    {
        return Db::fetchOne('SELECT COUNT(*) FROM ' . Common::prefixTable('log_conversion') . ' WHERE idsite = ? AND idgoal = ?',
            [$this->idSite, $idGoal]);
    }
}