<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Framework\TestRequest;

use Piwik\API\Request;
use PHPUnit\Framework\Assert as Asserts;
use Exception;
use Piwik\Tests\Framework\Fixture;

/**
 * Utility class used to obtain and process API responses for API tests.
 * @since 2.8.0
 */
class Response
{
    private $processedResponseText;

    private $params;

    private $requestUrl;

    public function __construct($apiResponse, $params, $requestUrl, $normalize = true)
    {
        $this->params = $params;
        $this->requestUrl = $requestUrl;

        $apiResponse = (string) $apiResponse;
        $this->processedResponseText = $normalize ? $this->normalizeApiResponse($apiResponse) : $apiResponse;
    }

    public function getResponseText()
    {
        return $this->processedResponseText;
    }

    public function save($path)
    {
        file_put_contents($path, $this->processedResponseText);
    }

    public static function loadFromFile($path, $params, $requestUrl)
    {
        $contents = @file_get_contents($path);

        if (empty($contents)) {
            throw new Exception("$path does not exist");
        }

        return new Response($contents, $params, $requestUrl);
    }

    public static function loadFromApi($params, $requestUrl, $normalize = true)
    {
        $testRequest = new Request($requestUrl);

        // set the request as root request
        Request::setIsRootRequestApiRequest(Request::getMethodIfApiRequest(Request::getRequestArrayFromString($requestUrl, null)));

        // Cast as string is important. For example when calling
        // with format=original, objects or php arrays can be returned.
        $response = (string) $testRequest->process();

        return new Response($response, $params, $requestUrl, $normalize);
    }

    public static function assertEquals(Response $expected, Response $actual, $message = false)
    {
        $expectedText = $expected->getResponseText();
        $actualText = $actual->getResponseText();

        $expectedText = preg_replace('/[^\x09-\x0d\x1b\x20-\xff]/', '', $expectedText);
        $actualText = preg_replace('/[^\x09-\x0d\x1b\x20-\xff]/', '', $actualText);

        if ($expected->requestUrl['format'] == 'xml') {
            Asserts::assertXmlStringEqualsXmlString($expectedText, $actualText, $message);
            return;
        }

        // check content size to get quick feedback and avoid lengthy diff
        $checkSizeFirst = array('pdf', 'csv', 'html');
        if(!empty($expected->requestUrl['reportFormat'])
            && in_array($expected->requestUrl['reportFormat'], $checkSizeFirst)) {
            Asserts::assertEquals(strlen($expectedText), strlen($actualText), $message);
        }

        Asserts::assertEquals($expectedText, $actualText, $message);
    }

    private function normalizeApiResponse($apiResponse)
    {
        $apiResponse = $this->removeSubtableIdsFromXml($apiResponse);
        $apiResponse = $this->removePageViewIds($apiResponse);

        if ($this->shouldDeleteLiveIds()) {
            $apiResponse = $this->removeAllIdsFromXml($apiResponse);
        }

        if ($this->shouldDeleteLiveDates()) {
            $apiResponse = $this->removeAllLiveDatesFromXml($apiResponse);
        } else if ($this->requestHasNonDeterministicDate()) {
            // If date=lastN the <prettyDate> element will change each day, we remove XML element before comparison

            if ($this->requestUrl['method'] == 'API.getProcessedReport') {
                $apiResponse = $this->removeXmlElement($apiResponse, 'prettyDate');
            }

            $apiResponse = $this->removeXmlElement($apiResponse, 'visitServerHour');

            $regex = "/date=[-0-9,%Ca-z]+/"; // need to remove %2C which is encoded ,
            $apiResponse = preg_replace($regex, 'date=', $apiResponse);
        }

        $apiResponse = $this->normalizePdfContent($apiResponse);
        $apiResponse = $this->removeXmlFields($apiResponse);
        $apiResponse = $this->removeTodaysDate($apiResponse);
        $apiResponse = $this->normalizeDecimalFields($apiResponse);
        $apiResponse = $this->normalizeEncodingPhp533($apiResponse);
        $apiResponse = $this->normalizeSpaces($apiResponse);
        $apiResponse = $this->replacePiwikUrl($apiResponse);

        return $apiResponse;
    }

    private function removeTodaysDate($apiResponse)
    {
        $result = preg_replace('/' . date('Y-m-d') . ' [0-9]{2}:[0-9]{2}:[0-9]{2}/', 'today-date-removed-in-tests', $apiResponse);
        $result = str_replace(date('Y-m-d'), 'today-date-removed-in-tests', $result);;
        return $result;
    }

    private function normalizeEncodingPhp533($apiResponse)
    {
        return str_replace('&amp;#039;', "'", $apiResponse);
    }

    private function removePageViewIds($apiResponse)
    {
        $toRemove = array(
            'idpageview',
        );

        return $this->removeXmlFields($apiResponse, $toRemove);
    }

    private function removeAllIdsFromXml($apiResponse)
    {
        $toRemove = array(
            'visitorId',
            'nextVisitorId',
            'previousVisitorId',
            'idvisitor'
        );

        return $this->removeXmlFields($apiResponse, $toRemove);
    }

    private function removeAllLiveDatesFromXml($apiResponse)
    {
        $toRemove = array(
            'serverDate',
            'firstActionTimestamp',
            'lastActionTimestamp',
            'lastActionDateTime',
            'serverTimestamp',
            'serverTimePretty',
            'daysAgo',
            'serverDatePretty',
            'serverDatePrettyFirstAction',
            'serverTimePrettyFirstAction',
            'goalTimePretty',
            'serverTimePretty',
            'visitServerHour',
            'timestamp',
            'date',
            'prettyDate',
            'serverDateTimePrettyFirstAction'
        );
        return $this->removeXmlFields($apiResponse, $toRemove);
    }

    /**
     * Removes content from PDF binary the content that changes with the datetime or other random Ids
     */
    private function normalizePdfContent($response)
    {
        // normalize date markups and document ID in pdf files :
        // - /LastModified (D:20120820204023+00'00')
        // - /CreationDate (D:20120820202226+00'00')
        // - /ModDate (D:20120820202226+00'00')
        // - /M (D:20120820202226+00'00')
        // - /ID [ <0f5cc387dc28c0e13e682197f485fe65> <0f5cc387dc28c0e13e682197f485fe65> ]
        $response = preg_replace('/\(D:[0-9]{14}/', '(D:19700101000000', $response);
        $response = preg_replace('/\/ID \[ <.*> ]/', '', $response);
        $response = preg_replace('/\/id:\[ <.*> ]/', '', $response);

        $response = $this->removeXmlElement($response, "xmp:CreateDate");
        $response = $this->removeXmlElement($response, "xmp:ModifyDate");
        $response = $this->removeXmlElement($response, "xmp:MetadataDate");
        $response = $this->removeXmlElement($response, "xmpMM:DocumentID");
        $response = $this->removeXmlElement($response, "xmpMM:InstanceID");
        return $response;
    }

    private function removeXmlFields($input, $fieldsToRemove = false)
    {
        if ($fieldsToRemove === false) {
            $fieldsToRemove = @$this->params['xmlFieldsToRemove'];
        }

        if (!is_array($fieldsToRemove)) {
            $fieldsToRemove = array();
        }
        
        foreach ($fieldsToRemove as $xml) {
            $input = $this->removeXmlElement($input, $xml);
        }
        return $input;
    }

    private function removeXmlElement($input, $xmlElement, $testNotSmallAfter = true)
    {
        // Only raise error if there was some data before
        $testNotSmallAfter = strlen($input > 100) && $testNotSmallAfter;

        $oldInput = $input;
        $input = preg_replace('/(<' . $xmlElement . '>.+?<\/' . $xmlElement . '>)/', '', $input);
        $input = str_replace('<' . $xmlElement . ' />', '', $input);

        // check we didn't delete the whole string
        if ($testNotSmallAfter && $input != $oldInput) {
            Asserts::assertTrue(strlen($input) > 100, "Removing element $xmlElement from request " . http_build_query($this->requestUrl) . " resulted in a too small value:\n$input");
        }
        return $input;
    }

    private function requestHasNonDeterministicDate()
    {
        if (empty($this->requestUrl['date'])) {
            return false;
        }

        $dateTime = $this->requestUrl['date'];
        return strpos($dateTime, 'last') !== false
            || strpos($dateTime, 'today') !== false
            || strpos($dateTime, 'now') !== false;
    }

    private function shouldDeleteLiveIds()
    {
        return empty($this->params['keepLiveIds']);
    }

    private function shouldDeleteLiveDates()
    {
        return empty($this->params['keepLiveDates'])
            && ($this->requestUrl['method'] == 'Live.getLastVisits'
                || $this->requestUrl['method'] == 'Live.getLastVisitsDetails'
                || $this->requestUrl['method'] == 'Live.getVisitorProfile');
    }

    private function normalizeDecimalFields($response)
    {
        // Do not test for TRUNCATE(SUM()) returning .00 on mysqli since this is not working
        // http://bugs.php.net/bug.php?id=54508
        $response = str_replace('.000000</l', '</l', $response); //lat/long
        $response = str_replace('.00</revenue>', '</revenue>', $response);

        // eg. <totalEcommerceRevenue>0.00</totalEcommerceRevenue>
        $response = str_replace('.00</t', '</t', $response);

        return $response;
    }

    private function normalizeSpaces($apiResponse)
    {
        if (strpos($this->requestUrl['format'], 'json') === 0) {
            $apiResponse = str_replace('&nbsp;', '\u00a0', $apiResponse);
        }

        return $apiResponse;
    }

    private function removeSubtableIdsFromXml($apiResponse)
    {
        return $this->removeXmlFields($apiResponse, array('idsubdatatable_in_db'));
    }

    /**
     * To allow tests to pass no matter what port Piwik is on, we replace the test URL w/ another
     * one in the response. We don't remove the URL outright, because then we would not be able
     * to detect regressions where the root URL went missing.
     *
     * @param $apiResponse
     * @return mixed
     * @throws Exception
     */
    private function replacePiwikUrl($apiResponse)
    {
        $rootUrl = Fixture::getRootUrl();
        $rootUrlRel = str_replace(array('http://', 'https://'), '//', $rootUrl);

        $apiResponse = str_replace($rootUrl, "http://example.com/piwik/", $apiResponse);
        $apiResponse = str_replace($rootUrlRel, "//example.com/piwik/", $apiResponse);
        return $apiResponse;
    }
}
