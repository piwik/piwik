<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Tests\System;

use Piwik\Tests\Fixtures\NonProfilableData;
use Piwik\Tests\Framework\TestCase\SystemTestCase;

class NoProfilableDataTest extends SystemTestCase
{
    /**
     * @var NonProfilableData
     */
    public static $fixture = null;

    /**
     * @dataProvider getApiForTesting
     */
    public function testApi($api, $params)
    {
        $this->runApiTests($api, $params);
    }

    public function getApiForTesting()
    {
        $dateTime = self::$fixture->dateTime;

        $apiToCall = ['VisitsSummary.get', 'Actions.getPageUrls', 'Actions.get'];
        return [
            array($apiToCall, array(
                'idSite' => 'all',
                'date' => $dateTime,
            )),
        ];
    }
}

NoProfilableDataTest::$fixture = new NonProfilableData();
