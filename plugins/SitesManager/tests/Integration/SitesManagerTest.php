<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\SitesManager\tests\Integration;

use Piwik\Cache;
use Piwik\Archive\ArchiveInvalidator;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\SitesManager\SitesManager;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Class Plugins_SitesManagerTest
 *
 * @group Plugins
 */
class SitesManagerTest extends IntegrationTestCase
{
    /**
     * @var SitesManager
     */
    private $manager;

    private $siteId;

    public function setUp(): void
    {
        parent::setUp();

        // setup the access layer
        FakeAccess::$superUser = true;

        $this->manager = new SitesManager();
        $this->siteId  = Fixture::createWebsite('2014-03-03 00:00:00');
    }

    public function test_onSiteDeleted_shouldClearSiteCache()
    {
        $cache = Cache::getLazyCache();
        $cache->save($this->siteId, 'testcontent');

        $this->manager->onSiteDeleted($this->siteId);

        $this->assertFalse($cache->contains($this->siteId));
    }

    public function test_onSiteDeleted_shouldRemoveRememberedArchiveReports()
    {
        $archive = StaticContainer::get('Piwik\Archive\ArchiveInvalidator');
        $archive->rememberToInvalidateArchivedReportsLater($this->siteId, Date::factory('2014-04-05'));
        $archive->rememberToInvalidateArchivedReportsLater($this->siteId, Date::factory('2014-04-06'));
        $archive->rememberToInvalidateArchivedReportsLater(4949, Date::factory('2014-04-05'));

        $remembered = $archive->getRememberedArchivedReportsThatShouldBeInvalidated();
        $this->assertCount(2, $remembered);

        sort($remembered['2014-04-05']);
        $this->assertSame(array($this->siteId, 4949), $remembered['2014-04-05']);

        sort($remembered['2014-04-06']);
        $this->assertSame(array($this->siteId), $remembered['2014-04-06']);

        $this->manager->onSiteDeleted($this->siteId);

        $expected = array(
            '2014-04-05' => array(4949)
        );
        $this->assertEquals($expected, $archive->getRememberedArchivedReportsThatShouldBeInvalidated());
    }

    /**
     * @dataProvider getTestDataForRedirectDashboard
     */
    public function test_redirectDashboardToWelcomePage_doesNothingIfModuleActionAreIncorrect($module, $action)
    {
        $originalModule = $module;
        $originalAction = $action;
        $params = [];

        Piwik::postEvent('Request.dispatch', [&$module, &$action, &$params]);

        $this->assertEquals($originalModule, $module);
        $this->assertEquals($originalAction, $action);
    }

    public function getTestDataForRedirectDashboard()
    {
        return [
            ['CoreHome', 'someothermethod'],
            ['SitesManager', 'index'],
        ];
    }

    public function test_redirectDashboardToWelcomePage_doesNothingIfThereIsNoIdSiteParam()
    {
        $module = 'CoreHome';
        $action = 'index';
        $params = [];

        Piwik::postEvent('Request.dispatch', [&$module, &$action, &$params]);

        $this->assertEquals('CoreHome', $module);
        $this->assertEquals('index', $action);
    }

    public function test_redirectDashboardToWelcomePage_doesNothingIfAVisitWasTrackedInThePast()
    {
        $module = 'CoreHome';
        $action = 'index';
        $params = [];

        $_GET['idSite'] = $this->siteId;

        $tracker = Fixture::getTracker($this->siteId, '2015-02-04 04:12:35');
        $tracker->setUrl('http://example.com/');
        Fixture::checkResponse($tracker->doTrackPageView('a test title'));

        $this->assertEquals(false, Option::get('SitesManagerHadTrafficInPast_' . $this->siteId));

        Piwik::postEvent('Request.dispatch', [&$module, &$action, &$params]);

        $this->assertEquals('1', Option::get('SitesManagerHadTrafficInPast_' . $this->siteId));

        $this->assertEquals('CoreHome', $module);
        $this->assertEquals('index', $action);
    }

    public function test_redirectDashboardToWelcomePage_doesNothingIfAVisitWasTrackedAndWasLaterPurged()
    {
        $module = 'CoreHome';
        $action = 'index';
        $params = [];

        $_GET['idSite'] = $this->siteId;

        $tracker = Fixture::getTracker($this->siteId, '2015-02-04 04:12:35');
        $tracker->setUrl('http://example.com/');
        Fixture::checkResponse($tracker->doTrackPageView('a test title'));

        Piwik::postEvent('Request.dispatch', [&$module, &$action, &$params]);

        Db::exec('TRUNCATE ' . Common::prefixTable('log_visit'));

        $module = 'CoreHome';
        $action = 'index';

        $this->assertEquals('CoreHome', $module);
        $this->assertEquals('index', $action);
    }

    public function test_redirectDashboardToWelcomePage_redirectsIfThereIsNoDataAndAppropriateParams()
    {
        $module = 'CoreHome';
        $action = 'index';
        $params = [];

        $_GET['idSite'] = $this->siteId;

        \Zend_Session::$_unitTestEnabled = true;

        Piwik::postEvent('Request.dispatch', [&$module, &$action, &$params]);

        $this->assertEquals('SitesManager', $module);
        $this->assertEquals('siteWithoutData', $action);
    }

    protected static function configureFixture($fixture)
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess()
        );
    }
}
