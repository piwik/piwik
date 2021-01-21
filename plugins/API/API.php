<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\API;

use Piwik\API\Proxy;
use Piwik\API\Request;
use Piwik\ArchiveProcessor\Rules;
use Piwik\Cache;
use Piwik\CacheId;
use Piwik\Category\CategoryList;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Filter\ColumnDelete;
use Piwik\DataTable\Row;
use Piwik\Date;
use Piwik\IP;
use Piwik\Metrics;
use Piwik\Period;
use Piwik\Period\Range;
use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\SettingsProvider;
use Piwik\Plugins\API\DataTable\MergeDataTables;
use Piwik\Plugins\CoreAdminHome\CustomLogo;
use Piwik\Plugins\CorePluginsAdmin\SettingsMetadata;
use Piwik\Segment;
use Piwik\Site;
use Piwik\Translation\Translator;
use Piwik\Measurable\Type\TypeManager;
use Piwik\Version;
use Piwik\Widget\WidgetsList;

require_once PIWIK_INCLUDE_PATH . '/core/Config.php';

/**
 * This API is the <a href='http://matomo.org/docs/analytics-api/metadata/' rel='noreferrer' target='_blank'>Metadata API</a>: it gives information about all other available APIs methods, as well as providing
 * human readable and more complete outputs than normal API methods.
 *
 * Some of the information that is returned by the Metadata API:
 * <ul>
 * <li>the dynamically generated list of all API methods via "getReportMetadata"</li>
 * <li>the list of metrics that will be returned by each method, along with their human readable name, via "getDefaultMetrics" and "getDefaultProcessedMetrics"</li>
 * <li>the list of segments metadata supported by all functions that have a 'segment' parameter</li>
 * <li>the (truly magic) method "getProcessedReport" will return a human readable version of any other report, and include the processed metrics such as
 * conversion rate, time on site, etc. which are not directly available in other methods.</li>
 * <li>the method "getSuggestedValuesForSegment" returns top suggested values for a particular segment. It uses the Live.getLastVisitsDetails API to fetch the most recently used values, and will return the most often used values first.</li>
 * </ul>
 * The Metadata API is for example used by the Matomo Mobile App to automatically display all Matomo reports, with translated report & columns names and nicely formatted values.
 * More information on the <a href='http://matomo.org/docs/analytics-api/metadata/' rel='noreferrer' target='_blank'>Metadata API documentation page</a>
 *
 * @method static \Piwik\Plugins\API\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    /**
     * @var SettingsProvider
     */
    private $settingsProvider;

    /**
     * @var ProcessedReport
     */
    private $processedReport;

    /**
     * For Testing purpose only
     * @var int
     */
    public static $_autoSuggestLookBack = 60;

    public function __construct(SettingsProvider $settingsProvider, ProcessedReport $processedReport)
    {
        $this->settingsProvider = $settingsProvider;
        $this->processedReport = $processedReport;
    }

    /**
     * Get Matomo version
     * @return string
     */
    public function getMatomoVersion()
    {
        Piwik::checkUserHasSomeViewAccess();
        return Version::VERSION;
    }

    /**
     * Get PHP version
     * @return array
     */
    public function getPhpVersion()
    {
        Piwik::checkUserHasSuperUserAccess();
        return array(
            'version' => PHP_VERSION,
            'major' => PHP_MAJOR_VERSION,
            'minor' => PHP_MINOR_VERSION,
            'release' => PHP_RELEASE_VERSION,
            'versionId' => PHP_VERSION_ID,
            'extra' => PHP_EXTRA_VERSION,
        );
    }

    /**
     * Get Matomo version
     * @return string
     * @deprecated Deprecated but we keep it for historical reasons to not break BC
     */
    public function getPiwikVersion()
    {
        return $this->getMatomoVersion();
    }

    /**
     * Returns the most accurate IP address available for the current user, in
     * IPv4 format. This could be the proxy client's IP address.
     *
     * @return string IP address in presentation format.
     */
    public function getIpFromHeader()
    {
        Piwik::checkUserHasSomeViewAccess();
        return IP::getIpFromHeader();
    }

    /**
     * Returns the section [APISettings] if defined in config.ini.php
     * @return array
     */
    public function getSettings()
    {
        return Config::getInstance()->APISettings;
    }

    /**
     * Returns all available measurable types.
     * Marked as deprecated so it won't appear in API page. It won't be a public API for now.
     * @deprecated
     * @return array
     */
    public function getAvailableMeasurableTypes()
    {
        Piwik::checkUserHasSomeViewAccess();

        $typeManager = new TypeManager();
        $types = $typeManager->getAllTypes();

        $available = array();
        foreach ($types as $type) {
            $measurableSettings = $this->settingsProvider->getAllMeasurableSettings($idSite = 0, $type->getId());

            $settingsMetadata = new SettingsMetadata();

            $available[] = array(
                'id' => $type->getId(),
                'name' => Piwik::translate($type->getName()),
                'description' => Piwik::translate($type->getDescription()),
                'howToSetupUrl' => $type->getHowToSetupUrl(),
                'settings' => $settingsMetadata->formatSettings($measurableSettings)
            );
        }

        return $available;
    }

    public function getSegmentsMetadata($idSites = array(), $_hideImplementationData = true, $_showAllSegments = false,
                                        $_hideSegmentsIfDataNotProfilable = false)
    {
        if (empty($idSites)) {
            Piwik::checkUserHasSomeViewAccess();
        } else {
            $idSites = Site::getIdSitesFromIdSitesString($idSites);
            Piwik::checkUserHasViewAccess($idSites);
        }

        $isNotAnonymous = !Piwik::isUserIsAnonymous();

        $sites   = (is_array($idSites) ? implode('.', $idSites) : (int) $idSites);
        $cache   = Cache::getTransientCache();
        $cacheKey = 'API.getSegmentsMetadata' . $sites . '_' . (int) $_hideImplementationData . '_' . (int) $isNotAnonymous . '_' . (int) $_showAllSegments;
        $cacheKey = CacheId::pluginAware($cacheKey);

        if ($cache->contains($cacheKey)) {
            return $cache->fetch($cacheKey);
        }

        $metadata = new SegmentMetadata();
        $segments = $metadata->getSegmentsMetadata($idSites, $_hideImplementationData, $isNotAnonymous, $_showAllSegments);

        $cache->save($cacheKey, $segments);

        if ($_hideSegmentsIfDataNotProfilable
            && !Request::isCurrentPeriodProfilable()
        ) {
            $segments = array_filter($segments, function ($data) {
                return empty($data['requiresProfilableData']);
            });
        }

        return $segments;
    }

    /**
     * @param $segmentName
     * @param $table
     * @return array
     */
    protected function getSegmentValuesFromVisitorLog($segmentName, $table)
    {
        // Cleanup data to return the top suggested (non empty) labels for this segment
        $values = $table->getColumn($segmentName);


        // Select also flattened keys (custom variables "page" scope, page URLs for one visit, page titles for one visit)
        $valuesBis = $table->getColumnsStartingWith($segmentName . ColumnDelete::APPEND_TO_COLUMN_NAME_TO_KEEP);
        $values = array_merge($values, $valuesBis);

        // Select values from the action details if needed for this particular segment
        if (empty(array_filter($values)) && $this->doesSegmentNeedActionsData($segmentName)) {
            foreach ($table->getRows() as $row) {
                foreach ($row->getColumn('actionDetails') as $actionRow) {
                    if (isset($actionRow[$segmentName])) {
                        $values[] = $actionRow[$segmentName];
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Loads reports metadata, then return the requested one,
     * matching optional API parameters.
     */
    public function getMetadata($idSite, $apiModule, $apiAction, $apiParameters = array(), $language = false,
                                $period = false, $date = false, $hideMetricsDoc = false, $showSubtableReports = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        if ($language) {
            /** @var Translator $translator */
            $translator = StaticContainer::get('Piwik\Translation\Translator');
            $translator->setCurrentLanguage($language);
        }

        $metadata = $this->processedReport->getMetadata($idSite, $apiModule, $apiAction, $apiParameters, $language, $period, $date, $hideMetricsDoc, $showSubtableReports);
        return $metadata;
    }

    /**
     * Triggers a hook to ask plugins for available Reports.
     * Returns metadata information about each report (category, name, dimension, metrics, etc.)
     *
     * @param string $idSites THIS PARAMETER IS DEPRECATED AND WILL BE REMOVED IN PIWIK 4
     * @param bool|string $period
     * @param bool|Date $date
     * @param bool $hideMetricsDoc
     * @param bool $showSubtableReports
     * @param int $idSite
     * @return array
     */
    public function getReportMetadata($idSites = '', $period = false, $date = false, $hideMetricsDoc = false,
                                      $showSubtableReports = false, $idSite = false)
    {
        if (empty($idSite) && !empty($idSites)) {
            if (is_array($idSites)) {
                $idSite = array_shift($idSites);
            } else {
                $idSite = $idSites;
            }
        } elseif (empty($idSite) && empty($idSites)) {
            throw new \Exception('Calling API.getReportMetadata without any idSite is no longer supported since Matomo 3.0.0. Please specify at least one idSite via the "idSite" parameter.');
        }

        Piwik::checkUserHasViewAccess($idSite);

        $metadata = $this->processedReport->getReportMetadata($idSite, $period, $date, $hideMetricsDoc, $showSubtableReports);
        return $metadata;
    }

    public function getProcessedReport($idSite, $period, $date, $apiModule, $apiAction, $segment = false,
                                       $apiParameters = false, $idGoal = false, $language = false,
                                       $showTimer = true, $hideMetricsDoc = false, $idSubtable = false, $showRawMetrics = false,
                                       $format_metrics = null, $idDimension = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $processed = $this->processedReport->getProcessedReport($idSite, $period, $date, $apiModule, $apiAction, $segment,
            $apiParameters, $idGoal, $language, $showTimer, $hideMetricsDoc, $idSubtable, $showRawMetrics, $format_metrics, $idDimension);

        return $processed;
    }

    /**
     * Get a list of all pages that shall be shown in a Matomo UI including a list of all widgets that shall
     * be shown within each page.
     *
     * @param int $idSite
     * @return array
     */
    public function getReportPagesMetadata($idSite)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $widgetsList  = WidgetsList::get();
        $categoryList = CategoryList::get();
        $metadata     = new WidgetMetadata();

        return $metadata->getPagesMetadata($categoryList, $widgetsList);
    }

    /**
     * Get a list of all widgetizable widgets.
     *
     * @param int $idSite
     * @return array
     */
    public function getWidgetMetadata($idSite)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $widgetsList  = WidgetsList::get();
        $categoryList = CategoryList::get();
        $metadata     = new WidgetMetadata();

        return $metadata->getWidgetMetadata($categoryList, $widgetsList);
    }

    /**
     * Get a combined report of the *.get API methods.
     */
    public function get($idSite, $period, $date, $segment = false, $columns = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $columns = Piwik::getArrayFromApiParameter($columns);

        // build columns map for faster checks later on
        $columnsMap = array();
        foreach ($columns as $column) {
            $columnsMap[$column] = true;
        }

        // find out which columns belong to which plugin
        $columnsByPlugin = array();
        $meta = \Piwik\Plugins\API\API::getInstance()->getReportMetadata($idSite, $period, $date);
        foreach ($meta as $reportMeta) {
            // scan all *.get reports
            if ($reportMeta['action'] == 'get'
                && !isset($reportMeta['parameters'])
                && $reportMeta['module'] != 'API'
                && !empty($reportMeta['metrics'])
            ) {
                $plugin = $reportMeta['module'];
                $allMetrics = array_merge($reportMeta['metrics'], @$reportMeta['processedMetrics'] ?: array());
                foreach ($allMetrics as $column => $columnTranslation) {
                    // a metric from this report has been requested
                    if (isset($columnsMap[$column])
                        // or by default, return all metrics
                        || empty($columnsMap)
                    ) {
                        $columnsByPlugin[$plugin][] = $column;
                    }
                }
            }
        }
        krsort($columnsByPlugin);

        $mergedDataTable = false;
        $params = compact('idSite', 'period', 'date', 'segment');
        foreach ($columnsByPlugin as $plugin => $columns) {
            // load the data
            $className = Request::getClassNameAPI($plugin);
            $params['columns'] = implode(',', $columns);
            $dataTable = Proxy::getInstance()->call($className, 'get', $params);

            $dataTable->filter(function (DataTable $table) {
                $table->clearQueuedFilters();
            });

            // merge reports
            if ($mergedDataTable === false) {
                $mergedDataTable = $dataTable;
            } else {
                $merger = new MergeDataTables();
                $merger->mergeDataTables($mergedDataTable, $dataTable);
            }
        }

        if (!empty($columnsMap)
            && !empty($mergedDataTable)
        ) {
            $mergedDataTable->queueFilter('ColumnDelete', array(false, array_keys($columnsMap)));
        }

        return $mergedDataTable;
    }

    /**
     * Given an API report to query (eg. "Referrers.getKeywords", and a Label (eg. "free%20software"),
     * this function will query the API for the previous days/weeks/etc. and will return
     * a ready to use data structure containing the metrics for the requested Label, along with enriched information (min/max values, etc.)
     *
     * @param int $idSite
     * @param string $period
     * @param Date $date
     * @param string $apiModule
     * @param string $apiAction
     * @param bool|string $label
     * @param bool|string $segment
     * @param bool|string $column
     * @param bool|string $language
     * @param bool|int $idGoal
     * @param bool|string $legendAppendMetric
     * @param bool|string $labelUseAbsoluteUrl
     * @param bool|int $idDimension
     * @return array
     */
    public function getRowEvolution($idSite, $period, $date, $apiModule, $apiAction, $label = false, $segment = false, $column = false, $language = false, $idGoal = false, $legendAppendMetric = true, $labelUseAbsoluteUrl = true, $idDimension = false, $labelSeries = false)
    {
        // check if site exists
        $idSite = (int) $idSite;
        $site = new Site($idSite);

        Piwik::checkUserHasViewAccess($idSite);

        $apiParameters = array();
        $entityNames = StaticContainer::get('entities.idNames');
        foreach ($entityNames as $entityName) {
            if ($entityName === 'idGoal' && is_numeric($idGoal)) {
                $apiParameters['idGoal'] = $idGoal;
            } elseif ($entityName === 'idDimension' && $idDimension) {
                $apiParameters['idDimension'] = $idDimension;
            } else {
                // ideally it would get the value from API params but dynamic params is not possible yet in API. If this
                // method is called eg in Request::processRequest, it could in theory pick up a param from the original request
                // and not from the API request within the original request.
                $idEntity = Common::getRequestVar($entityName, 0, 'int');
                if ($idEntity > 0) {
                    $apiParameters[$entityName] = $idEntity;
                }
            }
        }

        $rowEvolution = new RowEvolution();
        return $rowEvolution->getRowEvolution($idSite, $period, $date, $apiModule, $apiAction, $label, $segment, $column,
            $language, $apiParameters, $legendAppendMetric, $labelUseAbsoluteUrl, $labelSeries);
    }

    /**
     * Performs multiple API requests at once and returns every result.
     *
     * @param array $urls The array of API requests.
     * @return array
     */
    public function getBulkRequest($urls)
    {
        if (empty($urls)) {
            return array();
        }

        $urls = array_map('urldecode', $urls);
        $urls = array_map(array('Piwik\Common', 'unsanitizeInputValue'), $urls);

        $result = array();
        foreach ($urls as $url) {
            $params = Request::getRequestArrayFromString($url . '&format=json');

            if (!empty($params['method']) && $params['method'] === 'API.getBulkRequest') {
                continue;
            }

            if (isset($params['urls']) && $params['urls'] == $urls) {
                // by default 'urls' is added to $params as Request::getRequestArrayFromString adds all $_GET/$_POST
                // default parameters
                unset($params['urls']);
            }

            if (!empty($params['segment']) && strpos($url, 'segment=') > -1) {
                // only unsanitize input when segment is actually present in URL, not when it was used from
                // $defaultRequest in Request::getRequestArrayFromString from $_GET/$_POST
                $params['segment'] = urlencode(Common::unsanitizeInputValue($params['segment']));
            }

            $req = new Request($params);
            $result[] = json_decode($req->process(), true);
        }
        return $result;
    }

    /**
     * Return true if plugin is activated, false otherwise
     *
     * @param string $pluginName
     * @return bool
     */
    public function isPluginActivated($pluginName)
    {
        Piwik::checkUserHasSomeViewAccess();
        return \Piwik\Plugin\Manager::getInstance()->isPluginActivated($pluginName);
    }

    /**
     * Given a segment, will return a list of the most used values for this particular segment.
     * @param $segmentName
     * @param $idSite
     * @throws \Exception
     * @return array
     */
    public function getSuggestedValuesForSegment($segmentName, $idSite)
    {
        if (empty(Config::getInstance()->General['enable_segment_suggested_values'])) {
            return array();
        }

        Piwik::checkUserHasViewAccess($idSite);

        $maxSuggestionsToReturn = 30;
        $segment = $this->findSegment($segmentName, $idSite);

        // if segment has suggested values callback then return result from it instead
        $suggestedValuesCallbackRequiresTable = false;

        if (!empty($segment['suggestedValuesApi']) && is_string($segment['suggestedValuesApi']) && !Rules::isBrowserTriggerEnabled()) {
            $now = Date::now()->setTimezone(Site::getTimezoneFor($idSite));
            if (self::$_autoSuggestLookBack != 60) {
                // in Auto suggest tests we need to assume now is in 2018...
                // we do - 20 to make sure the year is still correct otherwise could end up being 2017-12-31 and the recorded visits are over several days in the tests we make sure to select the last day a visit was recorded
                $now = $now->subDay(self::$_autoSuggestLookBack - 20);
            }
            // we want to avoid launching the archiver should browser archiving be enabled as this can be very slow... we then rather
            // use the live api.
            $period = 'year';
            $date = $now->toString();
            if ($now->toString('m') == '01') {
                if (Rules::isArchivingDisabledFor(array($idSite), new Segment('', array($idSite)), 'range')) {
                    $date = $now->subYear(1)->toString(); // use previous year data to avoid using range
                } else {
                    $period = 'range';
                    $date = $now->subMonth(1)->toString() . ',' . $now->addDay(1)->toString();
                }
            }

            $apiParts = explode('.', $segment['suggestedValuesApi']);
            $meta = $this->getMetadata($idSite, $apiParts[0], $apiParts[1]);
            $flat = !empty($meta[0]['actionToLoadSubTables']) && $meta[0]['actionToLoadSubTables'] == $apiParts[1];

            $table = Request::processRequest($segment['suggestedValuesApi'], array(
                'idSite' => $idSite,
                'period' => $period,
                'date' => $date,
                'segment' => '',
                'filter_offset' => 0,
                'flat' => (int) $flat,
                'filter_limit' => $maxSuggestionsToReturn
            ));

            if ($table && $table instanceof DataTable && $table->getRowsCount()) {
                $values = [];
                foreach ($table->getRowsWithoutSummaryRow() as $row) {
                    $segment = $row->getMetadata('segment');
                    $remove = array(
                        $segmentName . Segment\SegmentExpression::MATCH_EQUAL,
                        $segmentName . Segment\SegmentExpression::MATCH_STARTS_WITH
                    );
                    // we don't look at row columns since this could include rows that won't work eg Other summary rows. etc
                    // and it is generally not reliable.
                    if (!empty($segment) && preg_match('/^' . implode('|',$remove) . '/', $segment)) {
                        $values[] = urldecode(urldecode(str_replace($remove, '', $segment)));
                    }
                }

                $values = array_slice($values, 0, $maxSuggestionsToReturn);
                $values = array_map(array('Piwik\Common', 'unsanitizeInputValue'), $values);
                return $values;
            }
        }

        if (isset($segment['suggestedValuesCallback'])) {
            $suggestedValuesCallbackRequiresTable = $this->doesSuggestedValuesCallbackNeedData(
                $segment['suggestedValuesCallback']);

            if (!$suggestedValuesCallbackRequiresTable) {
                return call_user_func($segment['suggestedValuesCallback'], $idSite, $maxSuggestionsToReturn);
            }
        }

        // if period=range is disabled, do not proceed
        if (!Period\Factory::isPeriodEnabledForAPI('range')) {
            return array();
        }

        if (!empty($segment['unionOfSegments'])) {
            $values = array();
            foreach ($segment['unionOfSegments'] as $unionSegmentName) {
                $unionSegment = $this->findSegment($unionSegmentName, $idSite, $_showAllSegments = true);

                try {
                    $result = $this->getSuggestedValuesForSegmentName($idSite, $unionSegment, $maxSuggestionsToReturn);
                    if (!empty($result)) {
                        $values = array_merge($result, $values);
                    }
                } catch (\Exception $e) {
                    // we ignore if there was no data found for $unionSegmentName
                }
            }

            if (empty($values)) {
                throw new \Exception("There was no data to suggest for $segmentName");
            }

        } else {
            $values = $this->getSuggestedValuesForSegmentName($idSite, $segment, $maxSuggestionsToReturn);
        }

        $values = $this->getMostFrequentValues($values);
        $values = array_slice($values, 0, $maxSuggestionsToReturn);
        $values = array_map(array('Piwik\Common', 'unsanitizeInputValue'), $values);

        return $values;
    }

    /**
     * Returns category/subcategory pairs as "CategoryId.SubcategoryId" for whom comparison features should
     * be disabled.
     *
     * @return string[]
     */
    public function getPagesComparisonsDisabledFor()
    {
        $pages = [];

        /**
         * If your plugin has pages where you'd like comparison features to be disabled, you can add them
         * via this event. Add the pages as "CategoryId.SubcategoryId".
         *
         * **Example**
         *
         * ```
         * public function getPagesComparisonsDisabledFor(&$pages)
         * {
         *     $pages[] = "General_Visitors.MyPlugin_MySubcategory";
         *     $pages[] = "MyPlugin.myControllerAction"; // if your plugin defines a whole page you want comparison disabled for
         * }
         * ```
         *
         * @param string[] &$pages
         */
        Piwik::postEvent('API.getPagesComparisonsDisabledFor', [&$pages]);

        return $pages;
    }

    private function findSegment($segmentName, $idSite, $_showAllSegments = false)
    {
        $segmentsMetadata = $this->getSegmentsMetadata($idSite, $_hideImplementationData = false, $_showAllSegments);

        $segmentFound = false;
        foreach ($segmentsMetadata as $segmentMetadata) {
            if ($segmentMetadata['segment'] == $segmentName) {
                $segmentFound = $segmentMetadata;
                break;
            }
        }

        if (empty($segmentFound)) {
            throw new \Exception("Requested segment $segmentName not found.");
        }

        return $segmentFound;
    }

    private function getSuggestedValuesForSegmentName($idSite, $segment, $maxSuggestionsToReturn)
    {
        $startDate = Date::now()->subDay(self::$_autoSuggestLookBack)->toString();
        $requestLastVisits = "method=Live.getLastVisitsDetails
        &idSite=$idSite
        &period=range
        &date=$startDate,today
        &format=original
        &serialize=0
        &flat=1";

        $segmentName = $segment['segment'];

        // Select non empty fields only
        // Note: this optimization has only a very minor impact
        $requestLastVisits .= "&segment=$segmentName" . urlencode('!=');

        // By default Live fetches all actions for all visitors, but we'd rather do this only when required
        if ($this->doesSegmentNeedActionsData($segmentName)) {
            $requestLastVisits .= "&filter_limit=400";
        } else {
            $requestLastVisits .= "&doNotFetchActions=1";
            $requestLastVisits .= "&filter_limit=800";
        }

        $request = new Request($requestLastVisits);
        $table = $request->process();

        if (empty($table)) {
            throw new \Exception("There was no data to suggest for $segmentName");
        }

        if (isset($segment['suggestedValuesCallback']) &&
            $this->doesSuggestedValuesCallbackNeedData($segment['suggestedValuesCallback'])) {
            $values = call_user_func($segment['suggestedValuesCallback'], $idSite, $maxSuggestionsToReturn, $table);
        } else {
            $values = $this->getSegmentValuesFromVisitorLog($segmentName, $table);
        }

        return $values;
    }

    /**
     * A glossary of all reports and their definition
     *
     * @param $idSite
     * @return array
     */
    public function getGlossaryReports($idSite)
    {
        $glossary = StaticContainer::get('Piwik\Plugins\API\Glossary');
        return $glossary->reportsGlossary($idSite);
    }

    /**
     * A glossary of all metrics and their definition
     *
     * @param $idSite
     * @return array
     */
    public function getGlossaryMetrics($idSite)
    {
        $glossary = StaticContainer::get('Piwik\Plugins\API\Glossary');
        return $glossary->metricsGlossary($idSite);
    }

    /**
     * @param $segmentName
     * @return bool
     */
    protected function doesSegmentNeedActionsData($segmentName)
    {
        // If you update this, also update flattenVisitorDetailsArray
        $segmentsNeedActionsInfo = array('visitConvertedGoalId',
            'pageUrl', 'pageTitle', 'siteSearchKeyword', 'siteSearchCategory', 'siteSearchCount',
            'entryPageTitle', 'entryPageUrl', 'exitPageTitle', 'exitPageUrl',
            'outlinkUrl', 'downloadUrl', 'eventUrl', 'orderId', 'productViewName', 'productViewSku', 'productViewPrice',
            'productViewCategory1', 'productViewCategory2', 'productViewCategory3', 'productViewCategory4', 'productViewCategory5'
        );
        $isCustomVariablePage = stripos($segmentName, 'customVariablePage') !== false;
        $isEventSegment = stripos($segmentName, 'event') !== false;
        $isContentSegment = stripos($segmentName, 'content') !== false;
        $doesSegmentNeedActionsInfo = in_array($segmentName, $segmentsNeedActionsInfo) || $isCustomVariablePage || $isEventSegment || $isContentSegment;
        return $doesSegmentNeedActionsInfo;
    }

    /**
     * @param $values
     *
     * @return array
     */
    private function getMostFrequentValues($values)
    {
        // remove false values (while keeping zeros)
        $values = array_filter($values, 'strlen');

        // array_count_values requires strings or integer, convert floats to string (mysqli)
        foreach ($values as &$value) {
            if (is_numeric($value)) {
                $value = (string)round($value, 3);
            }
        }
        // we have a list of all values. let's show the most frequently used first.
        $values = array_count_values($values);

        // Sort this list by converting and sorting the array with custom method, so the result doesn't differ between PHP versions
        $sortArray = [];

        foreach ($values as $value => $count) {
            $sortArray[] = [
                'value' => $value,
                'count' => $count
            ];
        }

        usort($sortArray, function($a, $b) {
            if ($a['count'] == $b['count']) {
                return strcmp($a['value'], $b['value']);
            }

            return $a['count'] > $b['count'] ? -1 : 1;
        });

        return array_column($sortArray, 'value');
    }

    private function doesSuggestedValuesCallbackNeedData($suggestedValuesCallback)
    {
        if (is_string($suggestedValuesCallback)
            && strpos($suggestedValuesCallback, '::') !== false
        ) {
            $suggestedValuesCallback = explode('::', $suggestedValuesCallback);
        }

        if (is_array($suggestedValuesCallback)) {
            $methodMetadata = new \ReflectionMethod($suggestedValuesCallback[0], $suggestedValuesCallback[1]);
        } else {
            $methodMetadata = new \ReflectionFunction($suggestedValuesCallback);
        }

        return $methodMetadata->getNumberOfParameters() >= 3;
    }
}

/**
 */
class Plugin extends \Piwik\Plugin
{
    public function __construct()
    {
        // this class is named 'Plugin', manually set the 'API' plugin
        parent::__construct($pluginName = 'API');
    }

    /**
     * @see \Piwik\Plugin::registerEvents
     */
    public function registerEvents()
    {
        return array(
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'Platform.initialized' => 'detectIsApiRequest'
        );
    }

    public function detectIsApiRequest()
    {
        Request::setIsRootRequestApiRequest(Request::getMethodIfApiRequest($request = null));
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = "plugins/API/stylesheets/listAllAPI.less";
        $stylesheets[] = "plugins/API/stylesheets/glossary.less";
    }
}
