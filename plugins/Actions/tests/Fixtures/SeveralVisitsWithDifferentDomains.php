<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Actions\tests\Fixtures;

use Piwik\Date;
use Piwik\Tests\Framework\Fixture;

class SeveralVisitsWithDifferentDomains extends Fixture
{
    public $idSite = 1;
    public $dateTime = '2020-01-15 03:00:00';

    public function setUp(): void
    {
        parent::setUp();

        $this->createWebsites();
        $this->trackVisits();
    }

    public function createWebsites()
    {
        if (!self::siteCreated($idSite = 1)) {
            Fixture::createWebsite('2020-01-02 03:00:00');
        }
    }

    private function trackVisits()
    {
        $t = Fixture::getTracker($this->idSite, $this->dateTime);
        $t->setUrl('http://host1.com/a/page');
        Fixture::checkResponse($t->doTrackPageView('page title host 1'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(0.2));
        $t->setUrl('http://host2.com/a/page');
        Fixture::checkResponse($t->doTrackPageView('page title host 2'));

        $t->setForceVisitDateTime(Date::factory($this->dateTime)->addHour(1.2));
        $t->setUrl('http://host3.com/a/nother/page');
        Fixture::checkResponse($t->doTrackPageView('page title host 3'));
    }
}