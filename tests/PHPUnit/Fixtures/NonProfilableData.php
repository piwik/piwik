<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Plugins\Goals\API as APIGoals;
use Piwik\Tests\Framework\Fixture;

class NonProfilableData extends Fixture
{
    public $idSite = 1;
    public $idSite2 = 2;
    public $dateTime = '2020-04-04 03:00:00';

    public function setUp(): void
    {
        $this->createTestWebsite();
        $this->trackNonProfilableVisits();
        $this->trackProfilableVisits();
    }

    public function tearDown(): void
    {
        // empty
    }

    private function trackNonProfilableVisits()
    {
        // two non profilable visits
        $t = self::getTracker($this->idSite, $this->dateTime);
        $t->setUserAgent('Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0; GTB6.3; Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1) ; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; .NET CLR 3.5.30729; .NET CLR 3.0.30729; OfficeLiveConnector.1.4; OfficeLivePatch.1.3)');
        $t->setUrl('http://example.com/isapage');
        $this->unsetVisitorId($t);
        Fixture::checkResponse($t->doTrackPageView('page view'));

        $t = self::getTracker($this->idSite, Date::factory($this->dateTime)->addHour(1)->getDatetime());
        $t->setUserAgent('Mozilla/5.0 (Linux; U; Android 4.3; zh-cn; SM-N9006 Build/JSS15J) AppleWebKit/537.36 (KHTML, like Gecko)Version/4.0 MQQBrowser/5.0 Mobile Safari/537.36');
        $t->setUrl('http://example.com/yet/another/page');
        $this->unsetVisitorId($t);
        Fixture::checkResponse($t->doTrackPageView('a second > page view'));

        $this->assertNoProfilableData();
    }

    private function trackProfilableVisits()
    {
        // one profilable visit
        $t = self::getTracker($this->idSite2, $this->dateTime);
        $t->setUserAgent('Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.0; Trident/4.0; GTB6.3; Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1) ; SLCC1; .NET CLR 2.0.50727; Media Center PC 5.0; .NET CLR 3.5.30729; .NET CLR 3.0.30729; OfficeLiveConnector.1.4; OfficeLivePatch.1.3)');
        $t->setUrl('http://example.com/profilablepage');
        Fixture::checkResponse($t->doTrackPageView('profilable page view'));
    }

    private function createTestWebsite()
    {
        if (!self::siteCreated($idSite = 1)) {
            self::createWebsite('2020-03-04 03:00:00', $ecommerce = 1);
        }

        $idGoal = 1;
        if (!self::goalExists($idSite, $idGoal)) {
            APIGoals::getInstance()->addGoal($idSite, 'all', 'url', 'http', 'contains');
        }

        if (!self::siteCreated($idSite = 2)) {
            self::createWebsite('2020-03-04 03:00:00', $ecommerce = 1);
        }
    }

    private function unsetVisitorId(\MatomoTracker $t)
    {
        $t->randomVisitorId = false;
    }

    private function assertNoProfilableData()
    {
        $table = Common::prefixTable('log_visit');
        $sql = "SELECT COUNT(*) FROM $table WHERE profilable = 1";
        $count = Db::fetchOne($sql);
        Assert::assertEquals(0, $count);
    }

    public function provideContainerConfig()
    {
        return [
            'tests.isProfilableCheckDisabled' => false,
        ];
    }
}