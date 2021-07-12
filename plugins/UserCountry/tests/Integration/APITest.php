<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\UserCountry\tests\Integration;

use Piwik\Access;
use Piwik\Common;
use Piwik\Config;
use Piwik\Plugins\UserCountry\API;
use Piwik\Plugins\GeoIp2\LocationProvider\GeoIp2;
use Piwik\Plugins\UserCountry\LocationProvider;
use Piwik\Plugins\UserCountry\LocationProvider\DefaultProvider;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group UserCountry
 * @group APITest
 * @group Plugins
 */
class APITest extends IntegrationTestCase
{
    /**
     * @var API
     */
    private $api;
    
    public function setUp(): void
    {
        parent::setUp();

        $this->api = API::getInstance();

        // reset location providers as they might be manipulated by other tests
        LocationProvider::$providers = null;
        LocationProvider::getAllProviders();
    }

    public function test_setLocationProvider()
    {
        $locationProvider = GeoIp2\Php::ID;
        $this->api->setLocationProvider($locationProvider);
        $this->assertEquals($locationProvider, Common::getCurrentLocationProviderId());

        $locationProvider = DefaultProvider::ID;
        $this->api->setLocationProvider($locationProvider);
        $this->assertEquals($locationProvider, Common::getCurrentLocationProviderId());
    }

    public function test_setLocationProviderInvalid()
    {
        $this->expectException(\Exception::class);

        $locationProvider = 'invalidProvider';
        $this->api->setLocationProvider($locationProvider);
    }

    public function test_setLocationProviderNoSuperUser()
    {
        $this->expectException(\Exception::class);

        Access::getInstance()->setSuperUserAccess(false);

        $locationProvider = GeoIp2\Php::ID;
        $this->api->setLocationProvider($locationProvider);
    }

    public function test_setLocationProviderDisabledInConfig()
    {
        $this->expectException(\Exception::class);

        Config::getInstance()->General['enable_geolocation_admin'] = 0;

        $locationProvider = GeoIp2\Php::ID;
        $this->api->setLocationProvider($locationProvider);
    }

    /**
     * @dataProvider getTestDataForGetLocationFromIP
     */
    public function test_getLocationFromIP($ipAddress, $expected, $ipAddressHeader = null)
    {
        if (!empty($ipAddressHeader)) {
            $_SERVER['REMOTE_ADDR'] = $ipAddressHeader;
        }

        // Default provider will guess the location based on HTTP_ACCEPT_LANGUAGE header
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en_US';

        $location = $this->api->getLocationFromIP($ipAddress);
        $this->assertEquals($expected, $location);
    }

    public function getTestDataForGetLocationFromIP()
    {
        return [
            ['113.62.1.1', [
                'country_code' => 'us',
                'continent_code' => 'amn',
                'continent_name' => 'Intl_Continent_amn',
                'country_name' => 'General_Unknown',
                'ip' => '113.62.1.1',
            ]],
            [null, [
                'country_code' => 'us',
                'continent_code' => 'amn',
                'continent_name' => 'Intl_Continent_amn',
                'country_name' => 'General_Unknown',
                'ip' => '151.100.101.92',
            ], '151.100.101.92'],
        ];
    }
}
