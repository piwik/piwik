<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration;

use Piwik\Container\StaticContainer;
use Piwik\EventDispatcher;
use Piwik\Http;
use Piwik\Piwik;
use Piwik\Tests\Framework\Fixture;
use Piwik\Version;

/**
 * @group Core
 * @group HttpTest
 */
class HttpTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Dataprovider for testFetchRemoteFile
     */
    public function getMethodsToTest()
    {
        return array(
            'curl' => array('curl'),
            'fopen' => array('fopen'),
            'socket' => array('socket'),
        );
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testFetchRemoteFile($method)
    {
        $this->assertNotNull(Http::getTransportMethod());
        $result = Http::sendHttpRequestBy($method, Fixture::getRootUrl() . 'matomo.js', 30);
        $this->assertTrue(strpos($result, 'Matomo') !== false);
    }

    public function testFetchApiLatestVersion()
    {
        $destinationPath = PIWIK_DOCUMENT_ROOT . '/tmp/latest/LATEST';
        Http::fetchRemoteFile(Fixture::getRootUrl(), $destinationPath, 3);
        $this->assertFileExists($destinationPath);
        $this->assertGreaterThan(0, filesize($destinationPath));
    }

    public function testFetchLatestZip()
    {
        $destinationPath = PIWIK_DOCUMENT_ROOT . '/tmp/latest/latest.zip';
        Http::fetchRemoteFile(Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/fixture.zip', $destinationPath, 3, 30);
        $this->assertFileExists($destinationPath);
        $this->assertGreaterThan(0, filesize($destinationPath));
    }

    public function testBuildQuery()
    {
        $this->assertEquals('', Http::buildQuery(array()));
        $this->assertEquals('test=foo', Http::buildQuery(array('test' => 'foo')));
        $this->assertEquals('test=foo&bar=baz', Http::buildQuery(array('test' => 'foo', 'bar' => 'baz')));
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testCustomByteRange($method)
    {
        if ($method == 'fopen') {
            $this->assertTrue(true); // pass
            return; // not supported w/ this method
        }

        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . '/matomo.js',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = array(10, 20),
            $getExtendedInfo = true
        );

        $this->assertEquals(206, $result['status']);
        $this->assertTrue(isset($result['headers']['Content-Range']));
        $this->assertEquals('bytes 10-20/', substr($result['headers']['Content-Range'], 0, 12));
        $this->assertTrue(in_array($result['headers']['Content-Type'], array('application/x-javascript', 'application/javascript')));
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHEADOperation($method)
    {
        if ($method == 'fopen') {
            $this->assertTrue(true); // pass
            return; // not supported w/ this method
        }

        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/fixture.zip',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = true,
            $httpMethod = 'HEAD'
        );

        $this->assertEquals('', $result['data']);
        $this->assertEquals(200, $result['status']);

        $this->assertTrue(isset($result['headers']['Content-Length']), "Content-Length header not set!");
        $this->assertTrue(is_numeric($result['headers']['Content-Length']), "Content-Length header not numeric!");
        $this->assertTrue(in_array($result['headers']['Content-Type'], array('application/zip', 'application/x-zip-compressed')));
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpAuthentication($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/HttpAuthentication.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = true,
            $httpMethod = 'GET',
            $httpUsername = 'test',
            $httpPassword = 'test'
        );

        $this->assertEquals('Authentication successful', $result['data']);
        $this->assertEquals(200, $result['status']);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpAuthenticationInvalid($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/HttpAuthentication.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = true,
            $httpMethod = 'GET',
            $httpUsername = '',
            $httpPassword = ''
        );

        $this->assertEquals(401, $result['status']);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpPost_ViaString($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/Post.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = false,
            $httpMethod = 'POST',
            $httpUsername = '',
            $httpPassword = '',
            'abc12=43&abfec=abcdef'
        );

        $this->assertEquals('{"abc12":"43","abfec":"abcdef","method":"post"}', $result);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpPost_ViaArray($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/Post.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = false,
            $httpMethod = 'POST',
            $httpUsername = '',
            $httpPassword = '',
            array('adf2' => '44', 'afc23' => 'ab12')
        );

        $this->assertEquals('{"adf2":"44","afc23":"ab12","method":"post"}', $result);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpCustomHeaders($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/AdditionalHeaders.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = false,
            $getExtendedInfo = false,
            $httpMethod = 'POST',
            $httpUsername = '',
            $httpPassword = '',
            array(),
            array('CustomHeader: customdata')
        );

        $this->assertEquals('customdata', $result);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpsWorksWithValidCertificate($method)
    {
        $result = Http::sendHttpRequestBy($method, 'https://builds.matomo.org/LATEST', 10);

        $this->assertStringMatchesFormat('%d.%d.%d', $result);
    }

    /**
     * error message can be:
     *      curl_exec: server certificate verification failed. CAfile: /home/travis/build/piwik/piwik/core/DataFiles/cacert.pem CRLfile: none. Hostname requested was: self-signed.badssl.com
     * or
     *      curl_exec: SSL certificate problem: self signed certificate. Hostname requested was: self-signed.badssl.com
     */
    public function testCurlHttpsFailsWithInvalidCertificate()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/curl_exec: .*certificate.* /');

        // use a domain from https://badssl.com/
        Http::sendHttpRequestBy('curl', 'https://self-signed.badssl.com/', 10);
    }

    public function testFopenHttpsFailsWithInvalidCertificate()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/failed to open stream/i');

        // use a domain from https://badssl.com/
        Http::sendHttpRequestBy('fopen', 'https://self-signed.badssl.com/', 10);
    }

    public function testSocketHttpsWorksWithValidCertificate()
    {
        $result = Http::sendHttpRequestBy('socket', 'https://piwik.org/', 10);
        $this->assertNotEmpty($result);
    }

    /**
     * @dataProvider getMethodsToTest
     */
    public function testHttpDownloadChunk_responseSizeLimitedToChunk($method)
    {
        $result = Http::sendHttpRequestBy(
            $method,
            'https://builds.matomo.org/matomo.zip',
            300,
            null,
            null,
            null,
            0,
            '',
            false,
            array(0, 50)
        );
        /**
         * The last arg above asked the server to limit the response sent back to bytes 0->50.
         * The RFC for HTTP Range Requests says that these headers can be ignored, so the test
         * depends on a server that will respect it - we are requesting our build download, which does.
         */
        $this->assertEquals(51, strlen($result));
    }

    /**
     * @dataProvider getRedirectUrls
     */
    public function test_redirects($url, $method, $isValid, $message)
    {
        if ($isValid === false) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessageMatches($message);
        }

        $response = Http::sendHttpRequestBy($method, $url, 1000);

        if ($isValid !== false) {
            $this->assertEquals($message, $response);
        }
    }

    public function getRedirectUrls()
    {
        return [
            // check 5 redirects are working
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=5', 'curl', true, Fixture::getRootUrl().'tests/resources/redirector.php?redirects=0'],
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=5', 'socket', true, Fixture::getRootUrl().'tests/resources/redirector.php?redirects=0'],
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=4', 'fopen', true, Fixture::getRootUrl().'tests/resources/redirector.php?redirects=0'],

            // more than 5 redirects should fail
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=6', 'curl', false, '/curl_exec: Maximum \(5\) redirects followed./'],
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=6', 'socket', false, '/Too many redirects/'],
            [Fixture::getRootUrl().'tests/resources/redirector.php?redirects=6', 'fopen', true, ''],

            // Redirect to disallowed protocol shouldn't be possible
            [Fixture::getRootUrl().'tests/resources/redirector.php?target='.urlencode('ftps://my.local'), 'curl', false, '/curl_exec: Protocol "ftps" not supported or disabled in libcurl/'],
            [Fixture::getRootUrl().'tests/resources/redirector.php?target='.urlencode('ftps://my.local'), 'socket', false, '/Protocol ftps not in list of allowed protocols/'],
            //[Fixture::getRootUrl().'tests/resources/redirector.php?target='.urlencode('ftps://my.local'), 'fopen', false, ''],
        ];
    }

    public function test_http_postsEvent()
    {
        $params = null;
        $params2 = null;
        Piwik::addAction('Http.sendHttpRequest', function () use (&$params) {
            $params = func_get_args();
        });
        Piwik::addAction('Http.sendHttpRequest.end', function () use (&$params2) {
            $params2 = func_get_args();
        });
        $destinationPath = PIWIK_USER_PATH . '/tmp/latest/LATEST';
        $url = Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/Post.php';
        Http::sendHttpRequestBy(
            Http::getTransportMethod(),
            $url,
            30,
            $userAgent = null,
            $destinationPath,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = array(10, 20),
            $getExtendedInfo = false,
            $httpMethod = 'POST',
            $httpUsername = '',
            $httpPassword = '',
            array('adf2' => '44', 'afc23' => 'ab12')
        );

        $this->assertEquals(array($url, array(
            'httpMethod' => 'POST',
            'body' => array('adf2' => '44','afc23' => 'ab12'),
            'userAgent' => 'Matomo/' . Version::VERSION,
            'timeout' => 30,
            'headers' => array(
                'Range: bytes=10-20',
                'Via: ' . Version::VERSION . '  (Matomo/' . Version::VERSION . ')',
                'X-Forwarded-For: 127.0.0.1',
            ),
            'verifySsl' => true,
            'destinationPath' => $destinationPath
        ), null, null, array()), $params);

        $this->assertNotEmpty($params2[4]);// headers
        unset($params2[4]);
        $this->assertEquals(array($url, array(
            'httpMethod' => 'POST',
            'body' => array('adf2' => '44','afc23' => 'ab12'),
            'userAgent' => 'Matomo/' . Version::VERSION,
            'timeout' => 30,
            'headers' => array(
                'Range: bytes=10-20',
                'Via: ' . Version::VERSION . '  (Matomo/' . Version::VERSION . ')',
                'X-Forwarded-For: 127.0.0.1',
            ),
            'verifySsl' => true,
            'destinationPath' => $destinationPath
        ), '{"adf2":"44","afc23":"ab12","method":"post"}', 200), $params2);
    }

    public function test_http_returnsResultOfPostedEvent()
    {
        Piwik::addAction('Http.sendHttpRequest', function ($url, $args, &$response, &$status, &$headers) {
            $response = '{test: true}';
            $status = 204;
            $headers = array('content-length' => 948);
        });

        $result = Http::sendHttpRequestBy(
            Http::getTransportMethod(),
            Fixture::getRootUrl() . 'tests/PHPUnit/Integration/Http/Post.php',
            30,
            $userAgent = null,
            $destinationPath = null,
            $file = null,
            $followDepth = 0,
            $acceptLanguage = false,
            $acceptInvalidSslCertificate = false,
            $byteRange = array(10, 20),
            $getExtendedInfo = true,
            $httpMethod = 'POST',
            $httpUsername = '',
            $httpPassword = '',
            array('adf2' => '44', 'afc23' => 'ab12')
        );

        $this->assertEquals(array(
            'data' => '{test: true}',
            'status' => 204,
            'headers' => array('content-length' => 948)
        ), $result);
    }

    /**
     * @dataProvider getProtocolUrls
     */
    public function test_invalid_protocols($url, $message)
    {
        self::expectException(\Exception::class);
        self::expectExceptionMessage($message);

        Http::sendHttpRequest($url, 5);
    }

    public function getProtocolUrls()
    {
        return [
            ['phar://malformed.url', 'Protocol phar not in list of allowed protocols: http,https'],
            ['ftp://usful.ftp/file.md', 'Protocol ftp not in list of allowed protocols: http,https'],
            ['rtp://custom.url', 'Protocol rtp not in list of allowed protocols: http,https'],
            ['/local/file', 'Missing scheme in given url'],
        ];
    }

    /**
     * @dataProvider getBlockedHostUrls
     */
    public function test_blocked_hosts($url, $isValid, $message='')
    {
        EventDispatcher::getInstance()->addObserver('Http.sendHttpRequest', function($aUrl, $httpEventParams, &$response, &$status, &$headers) {
            $response = 'prevented request';
        });

        StaticContainer::getContainer()->set('http.blocklist.hosts', [
            '*.amazonaws.com',
            'matomo.org',
            'piwik.*'
        ]);

        if (!$isValid) {
            self::expectException(\Exception::class);
            self::expectExceptionMessage($message);
        }

        self::assertEquals('prevented request', Http::sendHttpRequest($url, 5));
    }

    public function getBlockedHostUrls()
    {
        return [
            ['https://test.amazonaws.com/test/download', false, 'Hostname test.amazonaws.com is in list of disallowed hosts'],
            ['https://s3.us-west-2.amazonaws.com/mybucket/puppy.jpg', false, 'Hostname s3.us-west-2.amazonaws.com is in list of disallowed hosts'],
            ['https://s3.us-west-2.amazonaws.de/mybucket/puppy.jpg', true],
            ['https://matomo.org', false, 'Hostname matomo.org is in list of disallowed hosts'],
            ['https://builds.matomo.org', true],
            ['https://piwik.org', false, 'Hostname piwik.org is in list of disallowed hosts'],
            ['https://piwik.com.ax', false, 'Hostname piwik.com.ax is in list of disallowed hosts'],
            ['https://de.piwik.org', true],
        ];
    }
}
