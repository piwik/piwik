<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\PrivacyManager\tests\Integration\Dao;

use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Option;
use Piwik\Plugins\PrivacyManager\API;
use Piwik\Plugins\PrivacyManager\Dao\LogDataAnonymizer;
use Piwik\Plugins\PrivacyManager\PrivacyManager;
use Piwik\Plugins\PrivacyManager\tests\Fixtures\MultipleSitesMultipleVisitsFixture;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Tracker\Cache;

/**
 * Class LogDataAnonymizationsTest
 *
 * @group Plugins
 * @group LogDataAnonymizerTest
 */
class LogDataAnonymizerTest extends IntegrationTestCase
{
    /**
     * @var LogDataAnonymizer
     */
    private $anonymizer;
    /**
     * @var MultipleSitesMultipleVisitsFixture
     */
    private $theFixture;

    public function setUp(): void
    {
        parent::setUp();

        Option::set(PrivacyManager::OPTION_USERID_SALT, 'simpleuseridsalt1');
        Cache::clearCacheGeneral();
        Fixture::loadAllTranslations();

        $this->anonymizer = new LogDataAnonymizer();
        $this->theFixture = new MultipleSitesMultipleVisitsFixture();
        $this->theFixture->setUpLocation();
    }

    public function tearDown(): void
    {
        $this->theFixture->tearDownLocation();
    }

    public function test_checkAllVisitColumns_noColumnsGiven()
    {
        $this->assertNull($this->anonymizer->checkAllVisitColumns(array()));
    }

    public function test_checkAllVisitColumns_validColumnsGiven()
    {
        $this->assertNull($this->anonymizer->checkAllVisitColumns(array('visitor_localtime', 'location_region')));
    }

    public function test_checkAllVisitColumns_notExistingColumnGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The column "foobarbaz" seems to not exist in log_visit or cannot be unset');

        $this->anonymizer->checkAllVisitColumns(array('visitor_localtime', 'foobarbaz'));
    }

    public function test_checkAllVisitColumns_blacklistedColumnGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The column "idsite" seems to not exist in log_visit or cannot be unset');

        $this->anonymizer->checkAllVisitColumns(array('visitor_localtime', 'idsite'));
    }

    public function test_checkAllVisitColumns_columnWithoutDefaultValueGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The column "visit_total_time" seems to not exist in log_visit or cannot be unset');

        $this->anonymizer->checkAllVisitColumns(array('visitor_localtime', 'visit_total_time'));
    }

    public function test_checkAllLinkVisitActionColumns_noColumnsGiven()
    {
        $this->assertNull($this->anonymizer->checkAllLinkVisitActionColumns(array()));
    }

    public function test_checkAllLinkVisitActionColumns_validColumnsGiven()
    {
        $this->assertNull($this->anonymizer->checkAllLinkVisitActionColumns(array('time_spent_ref_action', 'idaction_content_piece')));
    }

    public function test_checkAllLinkVisitActionColumns_notExistingColumnGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The column "foobarbaz" seems to not exist in log_link_visit_action or cannot be unset');

        $this->anonymizer->checkAllLinkVisitActionColumns(array('time_spent_ref_action', 'foobarbaz'));
    }

    public function test_checkAllLinkVisitActionColumns_blacklistedColumnGiven()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The column "idsite" seems to not exist in log_link_visit_action or cannot be unset');

        $this->anonymizer->checkAllLinkVisitActionColumns(array('time_spent_ref_action', 'idsite'));
    }

    public function test_checkAllLinkVisitActionColumns_columnWithNonNullDefaultValueIsFine()
    {
        $this->assertNull($this->anonymizer->checkAllLinkVisitActionColumns(array('idaction_url_ref')));
    }

    public function test_anonymizeVisitInformation_whenNoWorkTodo()
    {
        $this->assertSame(0, $this->anonymizer->anonymizeVisitInformation($idSites = null, $startDate = '2017-01-01', $endDate = '2018-01-01', $anonymizeIp = false, $anonimizeLocation = false, $anonymizeUserId = false));
    }

    public function test_anonymizeVisitInformation_whenNoVisitsDuringThatTime()
    {
        $this->assertSame(0, $this->anonymizer->anonymizeVisitInformation($idSites = null, $startDate = '2017-01-01', $endDate = '2018-01-01', $anonymizeIp = true, $anonimizeLocation = true, $anonymizeUserId = true));
    }

    public function test_anonymizeInformation_whenNoSitesGivenDoesAnonymizeAllVisits()
    {
        $this->theFixture->setUpWebsites();
        $this->theFixture->trackVisits($idSite = 1, 2);
        $this->theFixture->trackVisits($idSite = 2, 1);
        $result1 = $this->anonymizer->anonymizeVisitInformation($idSites = null, $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', $anonymizeIp = true, $anonimizeLocation = true, $anonymizeUserId = false);
        $result2 = $this->anonymizer->unsetLogVisitTableColumns($idSites = null, $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', ['config_os', 'config_os_version', 'location_browser_lang', 'referer_url', 'referer_name', 'referer_type']);
        $result3 = $this->anonymizer->unsetLogLinkVisitActionColumns($idSites = null, $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', ['idaction_name', 'idaction_event_category', 'pageview_position', 'idpageview']);
        $this->assertAnonymizedDb('allSitesAllDates');
        $this->assertNotEmpty($result1);
        $this->assertNotEmpty($result2);
        $this->assertNotEmpty($result3);
    }

    public function test_anonymizeInformation_anonymizeUserId()
    {
        $this->theFixture->setUpWebsites();
        $this->theFixture->trackVisits($idSite = 1, 1);
        $result1 = $this->anonymizer->anonymizeVisitInformation($idSites = array(1,3), $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', $anonymizeIp = false, $anonimizeLocation = false, $anonymizeUserId = true);
        $this->assertAnonymizedDb('anonymizeUserId');
        $this->assertNotEmpty($result1);
    }

    public function test_anonymizeInformation_restrictSites()
    {
        $this->theFixture->setUpWebsites();
        $this->theFixture->trackVisits($idSite = 1, 2);
        $this->theFixture->trackVisits($idSite = 2, 1);
        $result1 = $this->anonymizer->anonymizeVisitInformation($idSites = array(1,3), $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', $anonymizeIp = true, $anonimizeLocation = true, $anonymizeUserId = false);
        $result2 = $this->anonymizer->unsetLogVisitTableColumns($idSites = array(1,3), $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', ['config_os', 'config_os_version', 'location_browser_lang', 'referer_url', 'referer_name', 'referer_type']);
        $result3 = $this->anonymizer->unsetLogLinkVisitActionColumns($idSites = array(1,3), $startDate = '2010-01-01 00:00:00', $endDate = '2035-01-01 23:59:59', ['idaction_name', 'idaction_event_category', 'pageview_position', 'idpageview']);
        $this->assertAnonymizedDb('restrictSites');
        $this->assertNotEmpty($result1);
        $this->assertNotEmpty($result2);
        $this->assertNotEmpty($result3);
    }

    public function test_anonymizeInformation_restrictDate()
    {
        $startDate = Date::factory($this->theFixture->dateTime)->subHour(3)->getDatetime();
        $endDate = Date::factory($this->theFixture->dateTime)->addHour(3)->getDatetime();
        $this->theFixture->setUpWebsites();
        $this->theFixture->trackVisits($idSite = 1, 2);
        $this->theFixture->trackVisits($idSite = 2, 1);
        $result1 = $this->anonymizer->anonymizeVisitInformation($idSites = null, $startDate, $endDate, $anonymizeIp = true, $anonimizeLocation = true, $anonimizeUserId = false);
        $result2 = $this->anonymizer->unsetLogVisitTableColumns($idSites = null, $startDate, $endDate, ['config_os', 'config_os_version', 'location_browser_lang', 'referer_url', 'referer_name', 'referer_type']);
        $result3 = $this->anonymizer->unsetLogLinkVisitActionColumns($idSites = null, $startDate, $endDate, ['idaction_name', 'idaction_event_category', 'pageview_position', 'idpageview']);
        $this->assertAnonymizedDb('restrictDate');
        $this->assertNotEmpty($result1);
        $this->assertNotEmpty($result2);
        $this->assertNotEmpty($result3);
    }

    public function test_getAvailableVisitColumnsToAnonymize()
    {
        $this->assertSame(array(
            'profilable' => null,
            'user_id' => null,
            'visit_goal_buyer' => null,
            'visit_goal_converted' => null,
            'visitor_returning' => null,
            'visitor_seconds_since_first' => null,
            'visitor_seconds_since_order' => null,
            'visitor_count_visits' => '0',
            'visit_entry_idaction_name' => null,
            'visit_entry_idaction_url' => null,
            'visit_exit_idaction_name' => null,
            'visit_exit_idaction_url' => '0',
            'visit_total_actions' => null,
            'visit_total_interactions' => '0',
            'visit_total_pageviews' => '0',
            'visit_total_searches' => null,
            'referer_keyword' => null,
            'referer_name' => null,
            'referer_type' => null,
            'referer_url' => null,
            'location_browser_lang' => null,
            'config_browser_engine' => null,
            'config_browser_name' => null,
            'config_browser_version' => null,
            'config_client_type' => null,
            'config_device_brand' => null,
            'config_device_model' => null,
            'config_device_type' => null,
            'config_os' => null,
            'config_os_version' => null,
            'visit_total_events' => null,
            'visitor_localtime' => null,
            'visitor_seconds_since_last' => null,
            'config_resolution' => null,
            'config_cookie' => null,
            'config_flash' => null,
            'config_java' => null,
            'config_pdf' => null,
            'config_quicktime' => null,
            'config_realplayer' => null,
            'config_silverlight' => null,
            'config_windowsmedia' => null,
            'location_city' => null,
            'location_country' => null,
            'location_latitude' => null,
            'location_longitude' => null,
            'location_region' => null,
            'last_idlink_va' => null,
            'custom_dimension_1' => null,
            'custom_dimension_2' => null,
            'custom_dimension_3' => null,
            'custom_dimension_4' => null,
            'custom_dimension_5' => null,
            'custom_var_k1' => null,
            'custom_var_v1' => null,
            'custom_var_k2' => null,
            'custom_var_v2' => null,
            'custom_var_k3' => null,
            'custom_var_v3' => null,
            'custom_var_k4' => null,
            'custom_var_v4' => null,
            'custom_var_k5' => null,
            'custom_var_v5' => null,
        ), $this->anonymizer->getAvailableVisitColumnsToAnonymize());
    }

    private function assertAnonymizedDb($fileName)
    {
        $rows = Db::fetchAll('SELECT idsite, idvisit from ' . Common::prefixTable('log_visit'));
        $export = API::getInstance()->exportDataSubjects($rows);
        $export = MultipleSitesMultipleVisitsFixture::cleanResult($export);
        $result = json_encode($export, JSON_PRETTY_PRINT);
        $fileExpected = PIWIK_DOCUMENT_ROOT . '/plugins/PrivacyManager/tests/System/expected/anonymizeVisitInformation_' . $fileName . '.json';
        $fileProcessed = str_replace('/expected/', '/processed/', $fileExpected);
        \Piwik\Filesystem::mkdir(dirname($fileProcessed));
        file_put_contents($fileProcessed, $result);

        $this->assertJsonStringEqualsJsonFile($fileExpected, $result);
    }

}
