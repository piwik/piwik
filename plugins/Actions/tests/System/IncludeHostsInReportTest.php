<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Actions\tests\System;


use Piwik\Config;
use Piwik\Plugins\Actions\tests\Fixtures\SeveralVisitsWithDifferentDomains;
use Piwik\Tests\Framework\TestCase\SystemTestCase;

class IncludeHostsInReportTest extends SystemTestCase
{
    /**
     * @var SeveralVisitsWithDifferentDomains
     */
    public static $fixture;


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
        $dateTime = self::$fixture->dateTime;

        return [
            array(['Actions.getPageUrls', 'Actions.getEntryPageUrls', 'Actions.getExitPageUrls'], array(
                'idSite' => $idSite,
                'date' => $dateTime,
                'period' => ['day', 'week'],
            )),
        ];
    }

    public static function getPathToTestDirectory()
    {
        return dirname(__FILE__);
    }

    public static function provideContainerConfigBeforeClass()
    {
        return [
            Config::class => \DI\decorate(function ($previous) {
                $previous->General['actions_include_host_in_report'] = 1;
                return $previous;
            }),
        ];
    }
}

IncludeHostsInReportTest::$fixture = new SeveralVisitsWithDifferentDomains();