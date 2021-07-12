<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Referrers\tests\System;

use Piwik\API\Request;
use Piwik\Config;
use Piwik\DataTable;
use Piwik\Tests\Fixtures\TwoSitesManyVisitsOverSeveralDaysWithSearchEngineReferrers;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\SystemTestCase;

/**
 * @group Referrers
 * @group ApiTest
 * @group Plugins
 */
class ApiTest extends SystemTestCase
{
    /**
     * @var TwoSitesManyVisitsOverSeveralDaysWithSearchEngineReferrers
     */
    public static $fixture = null; // initialized below class definition

    /**
     * @dataProvider getApiForTesting
     */
    public function testApi($api, $params)
    {
        $this->runApiTests($api, $params);
    }

    public function getApiForTesting()
    {
        $api = array(
            'API.getProcessedReport'
        );

        $apiToTest   = array();

        // we make sure it returns a subtableIds even if a DataTable\Map is requested
        $apiToTest[] = array($api,
            array(
                'idSite'     => 1,
                'apiModule'  => 'Referrers',
                'apiAction'  => 'getReferrerType',
                'date'       => '2010-01-01,2010-03-10',
                'periods'    => array('day'),
                'testSuffix' => 'Referrers_getReferrerType',
                'otherRequestParameters' => array('expanded' => 0)
            )
        );

        $apiToTest[] = [
            'Referrers.getReferrerType',
            [
                'idSite' => 1,
                'date' => '2010-01-01',
                'periods' => 'year',
                'testSuffix' => 'phpSerialized' . (version_compare(PHP_VERSION, '8', '>=') ? 8 : (version_compare(PHP_VERSION, '7.4', '>=') ? '74' : '')),
                'format' => 'original',
            ],
        ];

        $apiToTest[] = [
            array('Referrers.getAll', 'Referrers.getReferrerType'),
            [
                'idSite' => 'all',
                'date' => '2010-01-01',
                'periods' => 'year',
                'testSuffix' => 'allSites',
            ],
        ];

        return $apiToTest;
    }

    public function test_forceNewVisit_shouldNotForceANewVisitWhenNoKeywordIsSetAndNoReferrerWasSetInitially()
    {
        $dateTime = '2015-01-02';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track a campaign that was opened directly (no referrer)
        $t->setUrlReferrer('');
        $t->setUrl('http://piwik.net/?pk_campaign=adwbuccc');
        $t->doTrackPageView('My Title');

        // navigate to next page on same page
        $t->setUrlReferrer('http://piwik.net/?pk_campaign=adwbuccc');
        $t->setCustomTrackingParameter('_rcn', 'adwbuccc'); // this parameter would be set by piwik.js from cookie / attributionInfo
        $t->setCustomTrackingParameter('_rck', ''); // no keyword was used in previous tracking request
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('VisitsSummary.get', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_actions'));
    }

    public function test_forceNewVisit_shouldNotForceANewVisitWhenNoKeywordIsSetAndReferrerHostChanges()
    {
        $dateTime = '2015-01-03';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track a campaign that was opened directly (no referrer)
        $t->setUrlReferrer('http://www.google.com');
        $t->setUrl('http://piwik.net/?pk_campaign=adwbuccc');
        $t->doTrackPageView('My Title');

        // navigate to next page on same page
        $t->setUrlReferrer('http://piwik.net/?pk_campaign=adwbuccc');
        $t->setCustomTrackingParameter('_rcn', 'adwbuccc'); // this parameter would be set by piwik.js from cookie / attributionInfo
        $t->setCustomTrackingParameter('_rck', ''); // no keyword was used in previous tracking request
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('VisitsSummary.get', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_actions'));
    }

    public function test_forceNewVisit_shouldNotForceANewVisitWhenNoKeywordIsSetAndCampaignNameIsUpperCase()
    {
        $dateTime = '2015-01-04';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track a campaign that was opened directly (w/ saved referrer cookie info)
        $t->setUrlReferrer('http://www.google.com');
        $t->setUrl('http://piwik.net/?pk_campaign=adwBuCcc');
        $t->doTrackPageView('My Title');

        // navigate to same page but from different URL w/ same campaign
        $t->setUrlReferrer('http://links.piwik.net/?pk_campaign=adwBuCcc');
        $t->setCustomTrackingParameter('_rcn', 'adwBuCcc'); // this parameter would be set by piwik.js from cookie / attributionInfo
        $t->setCustomTrackingParameter('_rck', ''); // no keyword was used in previous tracking request
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('VisitsSummary.get', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_actions'));
    }

    public function test_forceNewVisit_shouldNotForceANewVisitWhenKeywordIsLongerThanDbColumnLength()
    {
        $dateTime = '2015-01-05';
        $idSite = self::$fixture->idSite;
        $longReferrer = 'thisisaverylongreferrerkeywordhereitisdefinitelylongerthanseventycharacterswhyitisevenlongerthantwohundredfiftyfivecharacters'
            . 'butboyisithardtocomeupwiththingstosayhereimeantheresonlysomuchapersoncanthinkitsnotlikeimplatoimmoreofacamusbutithinkicangettotheendof'
            . 'thiscrazylongstringohijustdid';

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track a campaign that was opened directly (w/ saved referrer cookie info)
        $t->setUrlReferrer('http://www.google.com');
        $t->setUrl('http://piwik.net/?pk_campaign=' . $longReferrer);
        $t->doTrackPageView('My Title');

        // navigate to same page but from different URL w/ same campaign
        $t->setUrlReferrer('http://links.piwik.net/?pk_campaign=' . $longReferrer);
        $t->setCustomTrackingParameter('_rcn', $longReferrer); // this parameter would be set by piwik.js from cookie / attributionInfo
        $t->setCustomTrackingParameter('_rck', ''); // no keyword was used in previous tracking request
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('VisitsSummary.get', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_actions'));

        /** @var DataTable $referrers */
        $referrers = Request::processRequest('Referrers.getCampaigns', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));
        $this->assertEquals(substr($longReferrer, 0, 255), $referrers->getFirstRow()->getColumn('label'));
    }

    public function test_forceNewVisit_shouldNotForceNewVisitWhenReferrerNameIsLongerThanDbColumnLength()
    {
        $dateTime = '2015-01-06';
        $idSite = self::$fixture->idSite;
        $longReferrer = 'http://www.thisisaverylongreferrerkeywordhereitisdefinitelylongerthanseventycharacters.com';

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track a campaign that was opened directly
        $t->setUrlReferrer($longReferrer);
        $t->setUrl('http://piwik.net/');
        $t->doTrackPageView('My Title');

        // navigate to same page but from different URL w/ same campaign
        $t->setUrlReferrer($longReferrer);
        $t->setCustomTrackingParameter('_rcn', ''); // this parameter would be set by piwik.js from cookie / attributionInfo
        $t->setCustomTrackingParameter('_rck', ''); // no keyword was used in previous tracking request
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('VisitsSummary.get', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_actions'));
    }

    public function test_referrersReport_sameUrlButDifferentProtocol_flat()
    {
        $dateTime = '2015-01-07';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track an HTTPS request
        $t->setUrlReferrer('https://somewebsite.com/');
        $t->setUrl('http://piwik.net/');
        $t->doTrackPageView('My Title');

        // track an HTTP request
        $t->setForceNewVisit(true);
        $t->setUrlReferrer('http://somewebsite.com/');
        $t->setUrl('http://piwik.net/');
        $t->doTrackPageView('My Title');

        /** @var DataTable $visits */
        $visits = Request::processRequest(
            'Referrers.getWebsites',
            array('idSite' => $idSite, 'period' => 'day', 'date' => $dateTime, 'flat' => 1)
        );

        $firstRow = $visits->getFirstRow();
        $this->assertEquals('somewebsite.com/index', $firstRow->getColumn('label'));
        $this->assertEquals(2, $firstRow->getColumn('nb_visits'));
    }

    public function test_referrersReport_sameUrlButDifferentProtocol_hierarchical()
    {
        $dateTime = '2015-01-08';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);
        // track an HTTPS request
        $t->setUrlReferrer('https://somewebsite.com/');
        $t->setUrl('http://piwik.net/');
        $t->doTrackPageView('My Title');

        // track an HTTP request
        $t->setForceNewVisit(true);
        $t->setUrlReferrer('http://somewebsite.com/');
        $t->setUrl('http://piwik.net/');
        $t->doTrackPageView('My Title');

        /** @var DataTable $visits */
        $visits = Request::processRequest(
            'Referrers.getWebsites',
            array('idSite' => $idSite, 'period' => 'day', 'date' => $dateTime)
        );

        $idSubtable = $visits->getFirstRow()->getIdSubDataTable();

        $visits = Request::processRequest(
            'Referrers.getUrlsFromWebsiteId',
            array('idSite' => $idSite, 'period' => 'day', 'date' => $dateTime, 'idSubtable' => $idSubtable)
        );

        $firstRow = $visits->getFirstRow();
        $this->assertEquals('index', $firstRow->getColumn('label'));
        $this->assertEquals(2, $visits->getFirstRow()->getColumn('nb_visits'));
    }

    public function test_searchEngineWithHiddenKeywordIsTrackedCorrectly()
    {
        $dateTime = '2015-01-09';
        $idSite = self::$fixture->idSite;

        $t = Fixture::getTracker($idSite, $dateTime . ' 00:01:02', $defaultInit = true);

        $t->setUrlReferrer('https://www.looksmart.com/');
        $t->setUrl('http://piwik.net/page1');
        $t->doTrackPageView('Page 1');

        /** @var DataTable $visits */
        $visits = Request::processRequest('Referrers.getSearchEngines', array('idSite' => 1, 'period' => 'day', 'date' => $dateTime));

        $this->assertEquals('Looksmart', $visits->getFirstRow()->getColumn('label'));
        $this->assertEquals(1, $visits->getFirstRow()->getColumn('nb_visits'));
    }

    public static function getOutputPrefix()
    {
        return '';
    }

    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__);
    }

    public static function provideContainerConfigBeforeClass()
    {
        return [
            Config::class => \DI\decorate(function (Config $config) {
                $config->Tracker['create_new_visit_when_website_referrer_changes'] = 1;
                return $config;
            }),
        ];
    }
}

ApiTest::$fixture = new TwoSitesManyVisitsOverSeveralDaysWithSearchEngineReferrers();