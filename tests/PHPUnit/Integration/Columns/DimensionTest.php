<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Tests\Integration\Columns;

// there is a test that requires the class to be defined in a plugin

use Piwik\Columns\Dimension;
use Piwik\Columns\DimensionSegmentFactory;
use Piwik\Plugin\Segment;
use Piwik\Metrics\Formatter;
use Piwik\Plugin\Dimension\ActionDimension;
use Piwik\Plugin\Dimension\ConversionDimension;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugin\Manager;
use Piwik\Segment\SegmentsList;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class CustomDimensionTest extends Dimension
{
    protected $columnName  = 'test_dimension';
    protected $columnType  = 'INTEGER (10) DEFAULT 0';
    protected $dbTableName  = 'log_visit';

    public function getId()
    {
        return $this->generateIdFromClass('Piwik\Plugins\Test\Columns\DimensionTest');
    }

    public function hasImplementedEvent($method)
    {
        $method = new \ReflectionMethod($this, $method);
        $declaringClass = $method->getDeclaringClass();

        return 0 === strpos($declaringClass->name, 'Piwik\Tests');
    }

    public function set($param, $value)
    {
        $this->$param = $value;
    }

    public function setColumnType($columnType)
    {
        $this->columnType = $columnType;
    }

    public function configureSegments(SegmentsList $segmentsList, DimensionSegmentFactory $dimensionSegmentFactory)
    {
        $segment = new Segment();
        $segment->setSegment('exitPageUrl');
        $segment->setName('Actions_ColumnExitPageURL');
        $segment->setCategory('General_Visit');
        $segmentsList->addSegment($dimensionSegmentFactory->createSegment($segment));

        // custom type and sqlSegment
        $segment = new Segment();
        $segment->setSegment('exitPageUrl');
        $segment->setSqlSegment('customValue');
        $segment->setType(Segment::TYPE_METRIC);
        $segment->setName('Actions_ColumnExitPageURL');
        $segment->setCategory('General_Visit');
        $segmentsList->addSegment($dimensionSegmentFactory->createSegment($segment));
    }
}


/**
 * @group Core
 */
class ColumnDimensionTest extends IntegrationTestCase
{
    /**
     * @var CustomDimensionTest
     */
    private $dimension;

    public function setUp(): void
    {
        parent::setUp();

        Fixture::loadAllTranslations();

        Fixture::createWebsite('2014-04-05 01:02:03');

        Manager::getInstance()->unloadPlugins();
        Manager::getInstance()->doNotLoadAlwaysActivatedPlugins();

        $this->dimension = new CustomDimensionTest();
    }

    public function tearDown(): void
    {
        Fixture::resetTranslations();
        parent::tearDown();
    }

    public function test_hasImplementedEvent_shouldDetectWhetherAMethodWasOverwrittenInTheActualPluginClass()
    {
        $this->assertTrue($this->dimension->hasImplementedEvent('set'));
        $this->assertTrue($this->dimension->hasImplementedEvent('configureSegments'));

        $this->assertFalse($this->dimension->hasImplementedEvent('getSegments'));
    }

    public function test_getColumnName_shouldReturnTheNameOfTheColumn()
    {
        $this->assertSame('test_dimension', $this->dimension->getColumnName());
    }

    public function test_hasColumnType_shouldDetectWhetherAColumnTypeIsSet()
    {
        $this->assertTrue($this->dimension->hasColumnType());

        $this->dimension->set('columnType', '');
        $this->assertFalse($this->dimension->hasColumnType());
    }

    public function test_getName_ShouldNotReturnANameByDefault()
    {
        $this->assertSame('', $this->dimension->getName());
    }

    public function test_getAllDimensions_shouldReturnAllKindOfDimensions()
    {
        Manager::getInstance()->loadPlugins(array('Actions', 'Events', 'DevicesDetector', 'Goals', 'CustomVariables'));

        $dimensions = Dimension::getAllDimensions();

        $this->assertGreaterThan(20, count($dimensions));

        $foundConversion = false;
        $foundVisit      = false;
        $foundAction     = false;
        $foundNormal     = false;

        foreach ($dimensions as $dimension) {
            if ($dimension instanceof ConversionDimension) {
                $foundConversion = true;
            } else if ($dimension instanceof ActionDimension) {
                $foundAction = true;
            } else if ($dimension instanceof VisitDimension) {
                $foundVisit = true;
            } else if ($dimension instanceof Dimension) {
                $foundNormal = true;
            } else {
                $this->fail('Unexpected dimension class found');
            }

            if (get_class($dimension) === 'Piwik\Plugins\CustomVariables\CustomDimension') {
                continue;
            }

            $this->assertRegExp('/Piwik.Plugins.(Actions|Events|DevicesDetector|Goals|CustomVariables).Columns/', get_class($dimension));
        }

        $this->assertTrue($foundConversion);
        $this->assertTrue($foundAction);
        $this->assertTrue($foundVisit);
        $this->assertTrue($foundNormal);
    }

    public function test_getDimensions_shouldReturnAllKindOfDimensionsThatBelongToASpecificPlugin()
    {
        Manager::getInstance()->loadPlugins(array('Actions', 'Events', 'DevicesDetector', 'Goals'));

        $dimensions = Dimension::getDimensions(Manager::getInstance()->loadPlugin('Actions'));

        $this->assertGreaterThan(10, count($dimensions));

        $foundVisit      = false;
        $foundAction     = false;

        foreach ($dimensions as $dimension) {
            if ($dimension instanceof ActionDimension) {
                $foundAction = true;
            } else if ($dimension instanceof VisitDimension) {
                $foundVisit = true;
            }

            $this->assertRegExp('/Piwik.Plugins.Actions.Columns/', get_class($dimension));
        }

        $this->assertTrue($foundAction);
        $this->assertTrue($foundVisit);
    }

    public function test_getDimensions_shouldReturnConversionDimensionsThatBelongToASpecificPlugin()
    {
        Manager::getInstance()->loadPlugins(array('Actions', 'Events', 'DevicesDetector', 'Goals'));

        $dimensions = Dimension::getDimensions(Manager::getInstance()->loadPlugin('Goals'));

        $this->assertGreaterThan(2, count($dimensions));

        $foundConversion = false;

        foreach ($dimensions as $dimension) {
            if ($dimension instanceof ConversionDimension) {
                $foundConversion = true;
            }

            $this->assertRegExp('/Piwik.Plugins.Goals.Columns/', get_class($dimension));
        }

        $this->assertTrue($foundConversion);
    }

    public function test_getSegment_ShouldReturnConfiguredSegments()
    {
        $segments = $this->dimension->getSegments();

        $this->assertCount(2, $segments);
        $this->assertInstanceOf('\Piwik\Plugin\Segment', $segments[0]);
        $this->assertInstanceOf('\Piwik\Plugin\Segment', $segments[1]);
    }

    /**
     * @param $expectedType
     * @param $columnType
     * @dataProvider getTypeProvider
     */
    public function test_getType_shouldGuessTypeBasedOnColumnType($expectedType, $columnType)
    {
        $this->dimension->setColumnType($columnType);
        $this->assertSame($expectedType, $this->dimension->getType());
    }

    public function getTypeProvider()
    {
        return array(
            array($expected = Dimension::TYPE_NUMBER, $columnType = 'INTEGER (10) DEFAULT 0'),
            array($expected = Dimension::TYPE_NUMBER, $columnType = 'INTEGER(10) DEFAULT 0'),
            array($expected = Dimension::TYPE_NUMBER, $columnType = 'INT(10) DEFAULT 0'),
            array($expected = Dimension::TYPE_NUMBER, $columnType = 'int(10) DEFAULT 0'),
            array($expected = Dimension::TYPE_NUMBER, $columnType = 'SMALLINT(10) DEFAULT 0'),
            array($expected = Dimension::TYPE_FLOAT, $columnType = 'FLOAT (10) DEFAULT 0'),
            array($expected = Dimension::TYPE_FLOAT, $columnType = 'DECIMAL(10) DEFAULT 0'),
            array($expected = Dimension::TYPE_BINARY, $columnType = 'BINARY(8)'),
            array($expected = Dimension::TYPE_TIMESTAMP, $columnType = 'timestamp null'),
            array($expected = Dimension::TYPE_TIMESTAMP, $columnType = 'timeStAmp null'),
            array($expected = Dimension::TYPE_DATETIME, $columnType = 'DATETIME NOT NULL'),
            array($expected = Dimension::TYPE_DATE, $columnType = 'DATE NOT NULL'),
            array($expected = Dimension::TYPE_TEXT, $columnType = ''),
        );
    }

    public function test_addSegment_ShouldPrefilSomeSegmentValuesIfNotDefinedYetAndGuessTypeMetric()
    {
        $segments = $this->dimension->getSegments();

        $this->assertEquals(Segment::TYPE_METRIC, $segments[0]->getType());
    }

    public function test_addSegment_ShouldPrefilSomeSegmentValuesIfNotDefinedYetAndGuessTypeDimension()
    {
        $this->dimension->setColumnType('TEXT NOT NULL');
        $segments = $this->dimension->getSegments();

        $this->assertEquals(Segment::TYPE_DIMENSION, $segments[0]->getType());
    }

    public function test_addSegment_ShouldNotOverwritePreAssignedValues()
    {
        $segments = $this->dimension->getSegments();

        $this->assertEquals(Segment::TYPE_METRIC, $segments[1]->getType());
    }

    public function test_getId_ShouldCorrectlyGenerateIdFromDimensionsQualifiedClassName()
    {
        $this->assertEquals("Test.DimensionTest", $this->dimension->getId());
    }


    /**
     * @dataProvider getFormatValueProvider
     */
    public function test_formatValue($type, $value, $expected)
    {
        $formatter = new Formatter();
        $this->dimension->setType($type);
        $formatted = $this->dimension->formatValue($value, $idSite = 1, $formatter);

        $this->assertEquals($expected, $formatted);
    }

    public function getFormatValueProvider()
    {
        return array(
            array($type = Dimension::TYPE_NUMBER, $value = 5.354, $expected = 5),
            array($type = Dimension::TYPE_FLOAT, $value = 5.354, $expected = 5.35),
            array($type = Dimension::TYPE_MONEY, $value = 5.392, $expected = '$5.39'),
            array($type = Dimension::TYPE_PERCENT, $value = 0.343, $expected = '34.3%'),
            array($type = Dimension::TYPE_DURATION_S, $value = 121, $expected = '00:02:01'),
            array($type = Dimension::TYPE_DURATION_MS, $value = 392, $expected = '0.39s'),
            array($type = Dimension::TYPE_BYTE, $value = 3912, $expected = '3.8 K'),
            array($type = Dimension::TYPE_BOOL, $value = 0, $expected = 'No'),
            array($type = Dimension::TYPE_BOOL, $value = 1, $expected = 'Yes'),
        );
    }

    protected static $availableColumnDimensions = [
        'Piwik\Plugins\Actions\Columns\EntryPageTitle',
        'Piwik\Plugins\Actions\Columns\EntryPageUrl',
        'Piwik\Plugins\Actions\Columns\ExitPageTitle',
        'Piwik\Plugins\Actions\Columns\ExitPageUrl',
        'Piwik\Plugins\Actions\Columns\IdPageview',
        'Piwik\Plugins\Actions\Columns\PageTitle',
        'Piwik\Plugins\Actions\Columns\PageUrl',
        'Piwik\Plugins\Actions\Columns\SearchCategory',
        'Piwik\Plugins\Actions\Columns\SearchCount',
        'Piwik\Plugins\Actions\Columns\TimeSpentRefAction',
        'Piwik\Plugins\Actions\Columns\VisitTotalActions',
        'Piwik\Plugins\Actions\Columns\VisitTotalInteractions',
        'Piwik\Plugins\Actions\Columns\VisitTotalPageViews',
        'Piwik\Plugins\Actions\Columns\VisitTotalSearches',
        'Piwik\Plugins\Bandwidth\Columns\Bandwidth',
        'Piwik\Plugins\Contents\Columns\ContentInteraction',
        'Piwik\Plugins\Contents\Columns\ContentName',
        'Piwik\Plugins\Contents\Columns\ContentPiece',
        'Piwik\Plugins\Contents\Columns\ContentTarget',
        'Piwik\Plugins\CoreHome\Columns\Profilable',
        'Piwik\Plugins\CoreHome\Columns\ServerTime',
        'Piwik\Plugins\CoreHome\Columns\UserId',
        'Piwik\Plugins\CoreHome\Columns\VisitFirstActionTime',
        'Piwik\Plugins\CoreHome\Columns\VisitGoalBuyer',
        'Piwik\Plugins\CoreHome\Columns\VisitGoalConverted',
        'Piwik\Plugins\CoreHome\Columns\VisitTotalTime',
        'Piwik\Plugins\CoreHome\Columns\VisitorReturning',
        'Piwik\Plugins\CoreHome\Columns\VisitorSecondsSinceFirst',
        'Piwik\Plugins\CoreHome\Columns\VisitorSecondsSinceOrder',
        'Piwik\Plugins\CoreHome\Columns\VisitsCount',
        'Piwik\Plugins\DevicePlugins\Columns\PluginCookie',
        'Piwik\Plugins\DevicePlugins\Columns\PluginFlash',
        'Piwik\Plugins\DevicePlugins\Columns\PluginJava',
        'Piwik\Plugins\DevicePlugins\Columns\PluginPdf',
        'Piwik\Plugins\DevicePlugins\Columns\PluginQuickTime',
        'Piwik\Plugins\DevicePlugins\Columns\PluginRealPlayer',
        'Piwik\Plugins\DevicePlugins\Columns\PluginSilverlight',
        'Piwik\Plugins\DevicePlugins\Columns\PluginWindowsMedia',
        'Piwik\Plugins\DevicesDetection\Columns\BrowserEngine',
        'Piwik\Plugins\DevicesDetection\Columns\BrowserName',
        'Piwik\Plugins\DevicesDetection\Columns\BrowserVersion',
        'Piwik\Plugins\DevicesDetection\Columns\ClientType',
        'Piwik\Plugins\DevicesDetection\Columns\DeviceBrand',
        'Piwik\Plugins\DevicesDetection\Columns\DeviceModel',
        'Piwik\Plugins\DevicesDetection\Columns\DeviceType',
        'Piwik\Plugins\DevicesDetection\Columns\Os',
        'Piwik\Plugins\DevicesDetection\Columns\OsVersion',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewCategory',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewCategory2',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewCategory3',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewCategory4',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewCategory5',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewName',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewPrice',
        'Piwik\Plugins\Ecommerce\Columns\ProductViewSku',
        'Piwik\Plugins\Ecommerce\Columns\Revenue',
        'Piwik\Plugins\Events\Columns\EventAction',
        'Piwik\Plugins\Events\Columns\EventCategory',
        'Piwik\Plugins\Events\Columns\TotalEvents',
        'Piwik\Plugins\PagePerformance\Columns\TimeDomCompletion',
        'Piwik\Plugins\PagePerformance\Columns\TimeDomProcessing',
        'Piwik\Plugins\PagePerformance\Columns\TimeNetwork',
        'Piwik\Plugins\PagePerformance\Columns\TimeOnLoad',
        'Piwik\Plugins\PagePerformance\Columns\TimeServer',
        'Piwik\Plugins\PagePerformance\Columns\TimeTransfer',
        'Piwik\Plugins\Referrers\Columns\Keyword',
        'Piwik\Plugins\Referrers\Columns\ReferrerName',
        'Piwik\Plugins\Referrers\Columns\ReferrerType',
        'Piwik\Plugins\Referrers\Columns\ReferrerUrl',
        'Piwik\Plugins\Resolution\Columns\Resolution',
        'Piwik\Plugins\UserCountry\Columns\City',
        'Piwik\Plugins\UserCountry\Columns\Country',
        'Piwik\Plugins\UserCountry\Columns\Latitude',
        'Piwik\Plugins\UserCountry\Columns\Longitude',
        'Piwik\Plugins\UserCountry\Columns\Region',
        'Piwik\Plugins\UserLanguage\Columns\Language',
        'Piwik\Plugins\VisitTime\Columns\LocalTime',
        'Piwik\Plugins\VisitorInterest\Columns\VisitorSecondsSinceLast',
    ];

    /**
     * Check all available dimensions are listed above
     */
    public function testNoNewDimensionsAvailable()
    {
        self::expectNotToPerformAssertions();
        Manager::getInstance()->loadAllPluginsAndGetTheirInfo();

        $dimensions = Dimension::getAllDimensions();

        foreach ($dimensions as $dimension) {
            if (!$dimension->getColumnName() || !$dimension->getVersion()) {
                continue; // ignore dimensions that don't manage their database column
            }

            if (!in_array(get_class($dimension), self::$availableColumnDimensions)) {
                $this->fail("New dimension found: ".get_class($dimension)."\nPlease update list of available column dimensions");
            }
        }
    }

    /**
     * Check all dimensions listed above, still exist and manage their column
     */
    public function testNoDimensionWasRemoved()
    {
        self::expectNotToPerformAssertions();
        Manager::getInstance()->loadAllPluginsAndGetTheirInfo();

        $removedDimensions = Dimension::getRemovedDimensions();

        foreach (self::$availableColumnDimensions as $dimension) {
            if (!class_exists($dimension)) {
                $this->fail("Dimension does no longer exist: $dimension\nPlease update list of available column dimensions and don't forget to add dimension to Dimension::getRemovedDimensions()");
            }

            $dimensionObj = new $dimension();

            if (!$dimensionObj->getColumnName() || !$dimensionObj->getVersion()) {
                $this->fail("Dimension does no longer manage a column: $dimension\nPlease remove it from the list of available column dimensions");
            }

            if (in_array($dimension, $removedDimensions)) {
                $this->fail("Dimension listed as available found in list of removed dimensions: $dimension");
            }
        }
    }

    /**
     * Check non of the dimensions marked as removed still exist
     */
    public function testRemovedDimensionNoLongerExists()
    {
        Manager::getInstance()->loadAllPluginsAndGetTheirInfo();

        $removedDimensions = Dimension::getRemovedDimensions();

        foreach ($removedDimensions as $removedDimension) {
            $this->assertFalse(class_exists($removedDimension), "Dimension marked as removed but still exist: $removedDimension");
        }
    }
}
