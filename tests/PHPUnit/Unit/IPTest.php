<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @backupGlobals enabled
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Unit;

use Piwik\Config;
use Piwik\IP;

/**
 * @group Core
 */
class IPTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Dataprovider for long2ip test
     */
    public function getLong2IPTestData()
    {
        // a valid network address is either 4 or 16 bytes; those lines are intentionally left blank ;)
        return array(
            // invalid
            array(null, '0.0.0.0'),
            array("", '0.0.0.0'),

            // IPv4
            array("\x7f\x00\x00\x01", '127.0.0.1'),

            // IPv4-compatible (this transitional format is deprecated in RFC 4291, section 2.5.5.1)
            array("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xc0\xa8\x01\x01", '192.168.1.1'),

            // IPv4-mapped (RFC 4291, 2.5.5.2)
            array("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\xc0\xa8\x01\x02", '192.168.1.2'),

            // other IPv6 address
            array("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\x00\xc0\xa8\x01\x03", '0.0.0.0'),
            array("\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\xc0\xa8\x01\x04", '0.0.0.0'),
            array("\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xc0\xa8\x01\x05", '0.0.0.0'),

            /*
             * We assume all stored IP addresses (pre-Piwik 1.4) were converted from UNSIGNED INT to VARBINARY.
             * The following is just for informational purposes.
             */

            // 192.168.1.0
            array('-1062731520', '0.0.0.0'),
            array('3232235776', '0.0.0.0'),

            // 10.10.10.10
            array('168430090', '0.0.0.0'),

            // 0.0.39.15 - this is the ambiguous case (i.e., 4 char string)
            array('9999', '57.57.57.57'),
            array("\x39\x39\x39\x39", '57.57.57.57'),

            // 0.0.3.231
            array('999', '0.0.0.0'),
            array("\x39\x39\x39", '0.0.0.0'),
        );
    }

    /**
     * Dataprovider for ip from header tests
     */
    public function getIpFromHeaderTestData()
    {
        return array(
            array('localhost inside LAN', array('127.0.0.1', '', null, null, '127.0.0.1')),
            array('outside LAN, no proxy', array('128.252.135.4', '', null, null, '128.252.135.4')),
            array('outside LAN, no (trusted) proxy', array('128.252.135.4', '137.18.2.13, 128.252.135.4', '', null, '128.252.135.4')),
            array('outside LAN, one trusted proxy', array('137.18.2.13', '137.18.2.13, 128.252.135.4, 192.168.1.10', 'HTTP_X_FORWARDED_FOR', null, '128.252.135.4')),
            array('outside LAN, proxy', array('192.168.1.10', '128.252.135.4, 192.168.1.10', 'HTTP_X_FORWARDED_FOR', null, '128.252.135.4')),
            array('outside LAN, misconfigured proxy', array('192.168.1.10', '128.252.135.4, 192.168.1.10, 192.168.1.10', 'HTTP_X_FORWARDED_FOR', null, '128.252.135.4')),
            array('outside LAN, multiple proxies', array('192.168.1.10', '128.252.135.4, 192.168.1.20, 192.168.1.10', 'HTTP_X_FORWARDED_FOR', '192.168.1.*', '128.252.135.4')),
            array('outside LAN, multiple proxies', array('[::ffff:7f00:10]', '128.252.135.4, [::ffff:7f00:20], [::ffff:7f00:10]', 'HTTP_X_FORWARDED_FOR', '::ffff:7f00:0/120', '128.252.135.4')),
        );
    }

    /**
     * @dataProvider getIpFromHeaderTestData
     */
    public function testGetIpFromHeader($description, $test)
    {
        $_SERVER['REMOTE_ADDR'] = $test[0];
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $test[1];
        Config::getInstance()->General['proxy_client_headers'] = array($test[2]);
        Config::getInstance()->General['proxy_ips'] = array($test[3]);
        $this->assertEquals($test[4], IP::getIpFromHeader(), $description);
    }

    /**
     * Dataprovider
     * @return array
     */
    public function getIpTestData()
    {
        return array(
            array('0.0.0.0'),
            array('72.14.204.99'),
            array('127.0.0.1'),
            array('169.254.0.1'),
            array('208.80.152.2'),
            array('224.0.0.1'),
        );
    }

    /**
     * @dataProvider getIpTestData
     */
    public function testGetNonProxyIpFromHeader($ip)
    {
        $this->assertEquals($ip, IP::getNonProxyIpFromHeader($ip, array()));
    }

    /**
     * @dataProvider getIpTestData
     */
    public function testGetNonProxyIpFromHeader2($ip)
    {
        // 1.1.1.1 is not a trusted proxy
        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '';
        $this->assertEquals('1.1.1.1', IP::getNonProxyIpFromHeader('1.1.1.1', array('HTTP_X_FORWARDED_FOR')));
    }

    /**
     * @dataProvider getIpTestData
     */
    public function testGetNonProxyIpFromHeader3($ip)
    {
        // 1.1.1.1 is a trusted proxy
        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';

        $_SERVER['HTTP_X_FORWARDED_FOR'] = $ip;
        $this->assertEquals($ip, IP::getNonProxyIpFromHeader('1.1.1.1', array('HTTP_X_FORWARDED_FOR')), 'case 1');

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, ' . $ip;
        $this->assertEquals('1.2.3.4', IP::getNonProxyIpFromHeader('1.1.1.1', array('HTTP_X_FORWARDED_FOR')), 'case 2');

        // misconfiguration
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $ip . ', 1.1.1.1';
        $this->assertEquals($ip, IP::getNonProxyIpFromHeader('1.1.1.1', array('HTTP_X_FORWARDED_FOR')), 'case 3');
    }

    /**
     * See https://github.com/piwik/piwik/issues/8721
     */
    public function testGetNonProxyIpFromHeader4_ShouldReturnDefaultIp_IfDefaultIpIsGivenMultipleTimes()
    {
        // 1.1.1.1 is a trusted proxy
        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $_SERVER['REMOTE_ADDR'];
        $_SERVER['HTTP_CF_CONNECTING_IP'] = $_SERVER['REMOTE_ADDR'];

        $this->assertEquals('1.1.1.1', IP::getNonProxyIpFromHeader('1.1.1.1', array('HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP')));
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    /**
     * Dataprovider for testGetFirstIpFromList
     */
    public function getFirstIpFromListTestData()
    {
        return array(
            array('', ''),
            array('127.0.0.1', '127.0.0.1'),
            array(' 127.0.0.1 ', '127.0.0.1'),
            array(' 192.168.1.1, 127.0.0.1', '192.168.1.1'),
            array('192.168.1.1 ,127.0.0.1 ', '192.168.1.1'),
            array('2001:db8:cafe::17 , 192.168.1.1', '2001:db8:cafe::17'),
            array('192.168.1.1,', '192.168.1.1'),
            array(',192.168.1.1,', '192.168.1.1'),
        );
    }

    /**
     * @dataProvider getFirstIpFromListTestData
     */
    public function testGetFirstIpFromList($csv, $expected)
    {
        // without excluded IPs
        $this->assertEquals($expected, IP::getFirstIpFromList($csv));

        // with excluded Ips
        $this->assertEquals($expected, IP::getFirstIpFromList($csv . ', 10.10.10.10', array('10.10.10.10')));
    }

    public function testGetFirstIpFromList_shouldReturnAnEmptyString_IfMultipleIpsAreGivenButAllAreExcluded()
    {
        // with excluded Ips
        $this->assertEquals('', IP::getFirstIpFromList('10.10.10.10, 10.10.10.10', array('10.10.10.10')));
    }

    /**
     * Dataprovider for testGetLastIpFromList
     */
    public function getLastIpFromListTestData()
    {
        return array(
            array('', ''),
            array('127.0.0.1', '127.0.0.1'),
            array(' 127.0.0.1 ', '127.0.0.1'),
            array(' 192.168.1.1, 127.0.0.1', '127.0.0.1'),
            array('192.168.1.1 ,127.0.0.1 ', '127.0.0.1'),
            array('2001:db8:cafe::17 , 192.168.1.1', '192.168.1.1'),
            array('192.168.1.1 , 2001:db8:cafe::17', '2001:db8:cafe::17'),
            array('192.168.1.1,', '192.168.1.1'),
            array(',192.168.1.1,', '192.168.1.1'),
        );
    }

    /**
     * @dataProvider getLastIpFromListTestData
     */
    public function testGetLastIpFromList($csv, $expected)
    {
        // without excluded IPs
        $this->assertEquals($expected, IP::getLastIpFromList($csv));

        // with excluded Ips
        $this->assertEquals($expected, IP::getLastIpFromList($csv . ', 10.10.10.10', array('10.10.10.10')));
    }

    public function testGetLastIpFromList_shouldReturnAnEmptyString_IfMultipleIpsAreGivenButAllAreExcluded()
    {
        // with excluded Ips
        $this->assertEquals('', IP::getLastIpFromList('10.10.10.10, 10.10.10.10', array('10.10.10.10')));
    }
}
