<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Goals;

use Exception;
use Piwik\API\Request;
use Piwik\Archive;
use Piwik\CacheId;
use Piwik\Cache as PiwikCache;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DbHelper;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugin\Manager;
use Piwik\Plugins\API\DataTable\MergeDataTables;
use Piwik\Plugins\CoreHome\Columns\Metrics\ConversionRate;
use Piwik\Plugins\Goals\Columns\Metrics\AverageOrderRevenue;
use Piwik\Plugin\ReportsProvider;
use Piwik\Plugins\Goals\Columns\Metrics\GoalConversionRate;
use Piwik\Segment;
use Piwik\Segment\SegmentExpression;
use Piwik\Site;
use Piwik\Tracker\Cache;
use Piwik\Tracker\GoalManager;
use Piwik\Plugins\VisitFrequency\API as VisitFrequencyAPI;
use Piwik\Validators\Regex;
use Piwik\Validators\WhitelistedValue;

/**
 * Goals API lets you Manage existing goals, via "updateGoal" and "deleteGoal", create new Goals via "addGoal",
 * or list existing Goals for one or several websites via "getGoals"
 *
 * If you are <a href='http://matomo.org/docs/ecommerce-analytics/' target='_blank'>tracking Ecommerce orders and products</a> on your site, the functions "getItemsSku", "getItemsName" and "getItemsCategory"
 * will return the list of products purchased on your site, either grouped by Product SKU, Product Name or Product Category. For each name, SKU or category, the following
 * metrics are returned: Total revenue, Total quantity, average price, average quantity, number of orders (or abandoned carts) containing this product, number of visits on the Product page,
 * Conversion rate.
 *
 * By default, these functions return the 'Products purchased'. These functions also accept an optional parameter &abandonedCarts=1.
 * If the parameter is set, it will instead return the metrics for products that were left in an abandoned cart therefore not purchased.
 *
 * The API also lets you request overall Goal metrics via the method "get": Conversions, Visits with at least one conversion, Conversion rate and Revenue.
 * If you wish to request specific metrics about Ecommerce goals, you can set the parameter &idGoal=ecommerceAbandonedCart to get metrics about abandoned carts (including Lost revenue, and number of items left in the cart)
 * or &idGoal=ecommerceOrder to get metrics about Ecommerce orders (number of orders, visits with an order, subtotal, tax, shipping, discount, revenue, items ordered)
 *
 * See also the documentation about <a href='http://matomo.org/docs/tracking-goals-web-analytics/' rel='noreferrer' target='_blank'>Tracking Goals</a> in Matomo.
 *
 * @method static \Piwik\Plugins\Goals\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    const AVG_PRICE_VIEWED = 'avg_price_viewed';

    /**
     * Return a single goal.
     *
     * @param int $idSite
     * @param int $idGoal
     * @return array An array of goal attributes.
     */
    public function getGoal($idSite, $idGoal)
    {
        Piwik::checkUserHasViewAccess($idSite);
        
        $goal = $this->getModel()->getActiveGoal($idSite, $idGoal);

        if (!empty($goal)) {
            return $this->formatGoal($goal);
        }
    }

    /**
     * Returns all Goals for a given website, or list of websites
     *
     * @param string|array $idSite Array or Comma separated list of website IDs to request the goals for
     * @return array Array of Goal attributes
     */
    public function getGoals($idSite)
    {
        $cacheId = self::getCacheId($idSite);
        $cache = $this->getGoalsInfoStaticCache();
        if (!$cache->contains($cacheId)) {
            // note: the reason this is secure is because the above cache is a static cache and cleared after each request
            // if we were to use a different cache that persists the result, this would not be secure because when a
            // result is in the cache, it would just return the result
            $idSite = Site::getIdSitesFromIdSitesString($idSite);

            if (empty($idSite)) {
                return array();
            }

            Piwik::checkUserHasViewAccess($idSite);

            $goals = $this->getModel()->getActiveGoals($idSite);

            $cleanedGoals = array();
            foreach ($goals as &$goal) {
                $cleanedGoals[$goal['idgoal']] = $this->formatGoal($goal);
            }

            $cache->save($cacheId, $cleanedGoals);
        }

        return $cache->fetch($cacheId);
    }

    private function formatGoal($goal)
    {
        if ($goal['match_attribute'] == 'manually') {
            unset($goal['pattern']);
            unset($goal['pattern_type']);
            unset($goal['case_sensitive']);
        }

        return $goal;
    }

    /**
     * Creates a Goal for a given website.
     *
     * @param int $idSite
     * @param string $name
     * @param string $matchAttribute 'url', 'title', 'file', 'external_website', 'manually', 'visit_duration', 'visit_total_actions', 'visit_nb_pageviews',
     *                               'event_action', 'event_category' or 'event_name'
     * @param string $pattern eg. purchase-confirmation.htm or numeric value if used with a numeric match attribute
     * @param string $patternType 'regex', 'contains', 'exact', or 'greater_than' for numeric match attributes
     * @param bool $caseSensitive
     * @param bool|float $revenue If set, default revenue to assign to conversions
     * @param bool $allowMultipleConversionsPerVisit By default, multiple conversions in the same visit will only record the first conversion.
     *                         If set to true, multiple conversions will all be recorded within a visit (useful for Ecommerce goals)
     * @param string $description
     * @return int ID of the new goal
     */
    public function addGoal($idSite, $name, $matchAttribute, $pattern, $patternType, $caseSensitive = false, $revenue = false, $allowMultipleConversionsPerVisit = false, $description = '',
                            $useEventValueAsRevenue = false)
    {
        Piwik::checkUserHasWriteAccess($idSite);

        $patternType = Common::unsanitizeInputValue($patternType);

        $this->checkPatternIsValid($patternType, $pattern, $matchAttribute);
        $name        = $this->checkName($name);
        $pattern     = $this->checkPattern($pattern, $matchAttribute);
        $patternType = $this->checkPatternType($patternType, $matchAttribute);
        $description = $this->checkDescription($description);

        $revenue = Common::forceDotAsSeparatorForDecimalPoint((float)$revenue);

        $goal = array(
            'name'            => $name,
            'description'     => $description,
            'match_attribute' => $matchAttribute,
            'pattern'         => $pattern,
            'pattern_type'    => $patternType,
            'case_sensitive'  => (int)$caseSensitive,
            'allow_multiple'  => (int)$allowMultipleConversionsPerVisit,
            'revenue'         => $revenue,
            'deleted'         => 0,
            'event_value_as_revenue' => (int) $useEventValueAsRevenue,
        );

        $idGoal = $this->getModel()->createGoalForSite($idSite, $goal);

        $this->getGoalsInfoStaticCache()->delete(self::getCacheId($idSite));

        Cache::regenerateCacheWebsiteAttributes($idSite);
        return $idGoal;
    }

    private function getModel()
    {
        return new Model();
    }

    /**
     * Updates a Goal description.
     * Will not update or re-process the conversions already recorded
     *
     * @see addGoal() for parameters description
     * @param int $idSite
     * @param int $idGoal
     * @param $name
     * @param $matchAttribute
     * @param string $pattern
     * @param string $patternType
     * @param bool $caseSensitive
     * @param bool|float $revenue
     * @param bool $allowMultipleConversionsPerVisit
     * @param string $description
     * @return void
     */
    public function updateGoal($idSite, $idGoal, $name, $matchAttribute, $pattern, $patternType, $caseSensitive = false, $revenue = false, $allowMultipleConversionsPerVisit = false, $description = '',
                               $useEventValueAsRevenue = false)
    {
        Piwik::checkUserHasWriteAccess($idSite);

        $patternType = Common::unsanitizeInputValue($patternType);

        $name        = $this->checkName($name);
        $description = $this->checkDescription($description);
        $patternType = $this->checkPatternType($patternType, $matchAttribute);
        $pattern     = $this->checkPattern($pattern, $matchAttribute);
        $this->checkPatternIsValid($patternType, $pattern, $matchAttribute);

        $revenue = Common::forceDotAsSeparatorForDecimalPoint((float)$revenue);

        $goal = array(
            'name'            => $name,
            'description'     => $description,
            'match_attribute' => $matchAttribute,
            'pattern'         => $pattern,
            'pattern_type'    => $patternType,
            'case_sensitive'  => (int) $caseSensitive,
            'allow_multiple'  => (int) $allowMultipleConversionsPerVisit,
            'revenue'         => $revenue,
            'event_value_as_revenue' => (int) $useEventValueAsRevenue,
        );

        $this->checkEventValueAsRevenue($goal);

        $this->getModel()->updateGoal($idSite, $idGoal, $goal);

        $this->getGoalsInfoStaticCache()->delete(self::getCacheId($idSite));

        Cache::regenerateCacheWebsiteAttributes($idSite);
    }

    private function checkEventValueAsRevenue($goal)
    {
        if ($goal['event_value_as_revenue'] && !GoalManager::isEventMatchingGoal($goal)) {
            throw new \Exception("'useEventValueAsRevenue' can only be 1 if the goal matches an event attribute.");
        }
    }

    private function checkPatternIsValid($patternType, $pattern, $matchAttribute)
    {
        if ($patternType == 'exact'
            && substr($pattern, 0, 4) != 'http'
            && substr($matchAttribute, 0, 6) != 'event_'
            && $matchAttribute != 'title'
        ) {
            throw new Exception(Piwik::translate('Goals_ExceptionInvalidMatchingString', array("http:// or https://", "http://www.yourwebsite.com/newsletter/subscribed.html")));
        }

        if ($patternType == 'regex') {
            $validator = new Regex();
            $validator->validate(GoalManager::formatRegex($pattern));
        }
    }

    private function checkName($name)
    {
        return urldecode($name);
    }

    private function checkDescription($description)
    {
        return urldecode($description);
    }

    private function checkPatternType($patternType, $matchAttribute)
    {
        if (empty($patternType)) {
            return '';
        }

        $patternType = strtolower($patternType);

        if (in_array($matchAttribute, GoalManager::$NUMERIC_MATCH_ATTRIBUTES)) {
            $validValues = ['greater_than'];
        } else {
            $validValues = ['exact', 'contains', 'regex'];
        }

        $validator = new WhitelistedValue($validValues);
        $validator->validate($patternType);

        return $patternType;
    }

    private function checkPattern($pattern, $matchAttribute)
    {
        if (in_array($matchAttribute, GoalManager::$NUMERIC_MATCH_ATTRIBUTES)
            && !is_numeric($pattern)
        ) {
            throw new \Exception("Invalid pattern for match attribute '$matchAttribute'. (got '$pattern', expected numeric value).");
        }

        return urldecode($pattern);
    }

    /**
     * Soft deletes a given Goal.
     * Stats data in the archives will still be recorded, but not displayed.
     *
     * @param int $idSite
     * @param int $idGoal
     * @return void
     */
    public function deleteGoal($idSite, $idGoal)
    {
        Piwik::checkUserHasWriteAccess($idSite);

        $this->getModel()->deleteGoal($idSite, $idGoal);
        $this->getModel()->deleteGoalConversions($idSite, $idGoal);

        $this->getGoalsInfoStaticCache()->delete(self::getCacheId($idSite));

        Cache::regenerateCacheWebsiteAttributes($idSite);
    }

    /**
     * Returns a datatable of Items SKU/name or categories and their metrics
     * If $abandonedCarts set to 1, will return items abandoned in carts. If set to 0, will return items ordered
     */
    protected function getItems($recordName, $idSite, $period, $date, $abandonedCarts, $segment)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $recordNameFinal = $recordName;
        if ($abandonedCarts) {
            $recordNameFinal = Archiver::getItemRecordNameAbandonedCart($recordName);
        }

        $archive   = Archive::build($idSite, $period, $date, $segment);
        $dataTable = $archive->getDataTable($recordNameFinal);

        // Before Matomo 4.0.0 ecommerce views were tracked in custom variables
        // So if Matomo was installed before try to fetch the views from custom variables and enrich the report
        if (version_compare(DbHelper::getInstallVersion(),'4.0.0-b2', '<')) {
            $this->enrichItemsTableWithViewMetrics($dataTable, $recordName, $idSite, $period, $date, $segment);
        }

        // use average ecommerce view price if no cart price is available
        $dataTable->filter(function(DataTable $table){
            foreach ($table->getRowsWithoutSummaryRow() as $row) {
                if (!$row->getColumn('avg_price') && !$row->getColumn(Metrics::INDEX_ECOMMERCE_ITEM_PRICE)) {
                    $row->renameColumn(self::AVG_PRICE_VIEWED, 'avg_price');
                }
                $row->deleteColumn(self::AVG_PRICE_VIEWED);
            }
        });

        $reportToNotDefinedString = array(
            'Goals_ItemsSku'      => Piwik::translate('General_NotDefined', Piwik::translate('Goals_ProductSKU')), // Note: this should never happen
            'Goals_ItemsName'     => Piwik::translate('General_NotDefined', Piwik::translate('Goals_ProductName')),
            'Goals_ItemsCategory' => Piwik::translate('General_NotDefined', Piwik::translate('Goals_ProductCategory'))
        );
        $notDefinedStringPretty = $reportToNotDefinedString[$recordName];
        $this->renameNotDefinedRow($dataTable, $notDefinedStringPretty);

        $dataTable->queueFilter('ReplaceColumnNames');
        $dataTable->queueFilter('ReplaceSummaryRowLabel');

        if ($abandonedCarts) {
            $ordersColumn = 'abandoned_carts';
            $dataTable->renameColumn(Metrics::INDEX_ECOMMERCE_ORDERS, $ordersColumn);
        }

        $dataTable->queueFilter('ColumnDelete', array('price'));

        return $dataTable;
    }

    protected function renameNotDefinedRow($dataTable, $notDefinedStringPretty)
    {
        if ($dataTable instanceof DataTable\Map) {
            foreach ($dataTable->getDataTables() as $table) {
                $this->renameNotDefinedRow($table, $notDefinedStringPretty);
            }
            return;
        }

        $rowNotDefined = $dataTable->getRowFromLabel('Value not defined');
        if ($rowNotDefined) {
            $rowNotDefined->setColumn('label', $notDefinedStringPretty);
        }
    }

    protected function enrichItemsDataTableWithItemsViewMetrics($dataTable, $idSite, $period, $date, $segment, $idSubtable)
    {
        if (!Manager::getInstance()->isPluginActivated('CustomVariables') || in_array('nb_visits', $dataTable->getColumns())) {
            // skip if CustomVariables plugin is not available or table already contains visits
            return;
        }

        $ecommerceViews = \Piwik\Plugins\CustomVariables\API::getInstance()->getCustomVariablesValuesFromNameId($idSite, $period, $date, $idSubtable, $segment, $_leavePriceViewedColumn = true);

        // For Product names and SKU reports, and for Category report
        // Use the Price (tracked on page views)
        // ONLY when the price sold in conversions is not found (ie. product viewed but not sold)
        foreach ($ecommerceViews->getRows() as $rowView) {
            // If there is not already a 'sum price' for this product
            $rowFound = $dataTable->getRowFromLabel($rowView->getColumn('label'));
            $price = $rowFound
                ? $rowFound->getColumn(Metrics::INDEX_ECOMMERCE_ITEM_PRICE)
                : false;
            if (empty($price)) {
                // If a price was tracked on the product page
                if ($rowView->getColumn(Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED)) {
                    $rowView->renameColumn(Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED, self::AVG_PRICE_VIEWED);
                }
            }
            $rowView->deleteColumn(Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED);
        }

        $dataTable->addDataTable($ecommerceViews);
    }

    public function getItemsSku($idSite, $period, $date, $abandonedCarts = false, $segment = false)
    {
        return $this->getItems('Goals_ItemsSku', $idSite, $period, $date, $abandonedCarts, $segment);
    }

    public function getItemsName($idSite, $period, $date, $abandonedCarts = false, $segment = false)
    {
        return $this->getItems('Goals_ItemsName', $idSite, $period, $date, $abandonedCarts, $segment);
    }

    public function getItemsCategory($idSite, $period, $date, $abandonedCarts = false, $segment = false)
    {
        return $this->getItems('Goals_ItemsCategory', $idSite, $period, $date, $abandonedCarts, $segment);
    }

    /**
     * Helper function that checks for special string goal IDs and converts them to
     * their integer equivalents.
     *
     * Checks for the following values:
     * Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER
     * Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART
     *
     * @param string|int $idGoal The goal id as an integer or a special string.
     * @return int The numeric goal id.
     */
    protected static function convertSpecialGoalIds($idGoal)
    {
        if ($idGoal == Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER) {
            return GoalManager::IDGOAL_ORDER;
        } else if ($idGoal == Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART) {
            return GoalManager::IDGOAL_CART;
        } else {
            return $idGoal;
        }
    }

    /**
     * Returns Goals data.
     *
     * @param int $idSite
     * @param string $period
     * @param string $date
     * @param bool $segment
     * @param bool|int $idGoal
     * @param array $columns Array of metrics to fetch: nb_conversions, conversion_rate, revenue
     * @param bool $showAllGoalSpecificMetrics whether to show all goal specific metrics when no goal is set
     * @return DataTable
     */
    public function get($idSite, $period, $date, $segment = false, $idGoal = false, $columns = array(), $showAllGoalSpecificMetrics = false, $compare = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        /** @var DataTable|DataTable\Map $table */
        $table = null;

        $segments = array(
            '' => false,
            '_new_visit' => VisitFrequencyAPI::NEW_VISITOR_SEGMENT,
            '_returning_visit' => VisitFrequencyAPI::RETURNING_VISITOR_SEGMENT
        );

        foreach ($segments as $appendToMetricName => $predefinedSegment) {
            $segmentToUse = $this->appendSegment($predefinedSegment, $segment);

            /** @var DataTable|DataTable\Map $tableSegmented */
            $tableSegmented = Request::processRequest('Goals.getMetrics', array(
                'segment' => $segmentToUse,
                'idSite'  => $idSite,
                'period'  => $period,
                'date'    => $date,
                'idGoal'  => $idGoal,
                'columns' => $columns,
                'showAllGoalSpecificMetrics' => $showAllGoalSpecificMetrics,
                'format_metrics' => Common::getRequestVar('format_metrics', 'bc'),
            ), $default = []);

            $tableSegmented->filter('Piwik\Plugins\Goals\DataTable\Filter\AppendNameToColumnNames',
                                    array($appendToMetricName));

            if (!isset($table)) {
                $table = $tableSegmented;
            } else {
                $merger = new MergeDataTables();
                $merger->mergeDataTables($table, $tableSegmented);
            }
        }

        // if we are comparing, this will be queried with format_metrics=0, but we will eventually need to format the metrics.
        // unfortunately, we can't do that since the processed metric information is in the GetMetrics report. in this case,
        // we queue the filter so it will eventually be formatted.
        if (!empty($compare)) {
            $getMetricsReport = ReportsProvider::factory('Goals', 'getMetrics');
            $table->queueFilter(function (DataTable $t) use ($getMetricsReport) {
                $t->setMetadata(Metrics\Formatter::PROCESSED_METRICS_FORMATTED_FLAG, false);

                $formatter = new Metrics\Formatter();
                $formatter->formatMetrics($t, $getMetricsReport, $metricsToFormat = null, $formatAll = true);
            });
        }

        return $table;
    }

    /**
     * Similar to {@link get()} but does not return any metrics for new and returning visitors. It won't apply
     * any segment by default. This method is deprecated from the API as it is only there to make the implementation of
     * the actual {@link get()} method easy.
     *
     * @deprecated
     * @internal
     */
    public function getMetrics($idSite, $period, $date, $segment = false, $idGoal = false, $columns = array(), $showAllGoalSpecificMetrics = false)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $archive = Archive::build($idSite, $period, $date, $segment);

        $showAllGoalSpecificMetrics = $showAllGoalSpecificMetrics && $idGoal === false;

        // Mapping string idGoal to internal ID
        $idGoal = self::convertSpecialGoalIds($idGoal);
        $isEcommerceGoal = $idGoal === GoalManager::IDGOAL_ORDER || $idGoal === GoalManager::IDGOAL_CART;

        $allMetrics = Goals::getGoalColumns($idGoal);

        if ($showAllGoalSpecificMetrics) {
            foreach ($this->getGoals($idSite) as $aGoal) {
                foreach (Goals::getGoalColumns($aGoal['idgoal']) as $goalColumn) {
                    $allMetrics[] = Goals::makeGoalColumn($aGoal['idgoal'], $goalColumn);
                }
            }
            $allMetrics[] = 'nb_visits';
        }

        $columnsToShow = Piwik::getArrayFromApiParameter($columns);
        $requestedColumns = $columnsToShow;

        $shouldAddAverageOrderRevenue = (in_array('avg_order_revenue', $requestedColumns) || empty($requestedColumns)) && $isEcommerceGoal;

        if ($shouldAddAverageOrderRevenue && !empty($requestedColumns)) {

            $avgOrder = new AverageOrderRevenue();
            $metricsToAdd = $avgOrder->getDependentMetrics();

            $requestedColumns = array_unique(array_merge($requestedColumns, $metricsToAdd));
        }

        if ($showAllGoalSpecificMetrics && !empty($requestedColumns)) {
            foreach ($requestedColumns as $requestedColumn) {
                if (strpos($requestedColumn, '_conversion_rate') !== false) {
                    $columnIdGoal = Goals::getGoalIdFromGoalColumn($requestedColumn);
                    if ($columnIdGoal) {
                        $goalConversionRate = new GoalConversionRate($idSite, $columnIdGoal);
                        $metricsToAdd = $goalConversionRate->getDependentMetrics();
                        $requestedColumns = array_unique(array_merge($requestedColumns, $metricsToAdd));
                    }
                }
            }
        }

        $report = ReportsProvider::factory('Goals', 'getMetrics');
        $columnsToGet = $report->getMetricsRequiredForReport($allMetrics, $requestedColumns);

        $inDbMetricNames = array_map(function ($name) use ($idGoal) {
            $name = str_replace('goal_', '', $name);
            return $name == 'nb_visits' ? $name : Archiver::getRecordName($name, $idGoal);
        }, $columnsToGet);
        $dataTable = $archive->getDataTableFromNumeric($inDbMetricNames);

        if (count($columnsToGet) > 0) {
            $newNameMapping = array_combine($inDbMetricNames, $columnsToGet);
        } else {
            $newNameMapping = array();
        }
        $dataTable->filter('ReplaceColumnNames', array($newNameMapping));

        // TODO: this should be in Goals/Get.php but it depends on idGoal parameter which isn't always in _GET (ie,
        //       it's not in ProcessedReport.php). more refactoring must be done to report class before this can be
        //       corrected.
        if ($shouldAddAverageOrderRevenue) {
            $dataTable->filter(function (DataTable $table) {
                $extraProcessedMetrics = $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME);
                if (empty($extraProcessedMetrics)) {
                    $extraProcessedMetrics = array();
                }
                $extraProcessedMetrics[] = new AverageOrderRevenue();
                $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, $extraProcessedMetrics);
            });
        }
        if ($showAllGoalSpecificMetrics) {
            $dataTable->filter(function (DataTable $table) use($idSite, &$allMetrics, $requestedColumns) {
                $extraProcessedMetrics = $table->getMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME);
                if (empty($extraProcessedMetrics)) {
                    $extraProcessedMetrics = array();
                }
                foreach ($this->getGoals($idSite) as $aGoal) {
                    $metric = new GoalConversionRate($idSite, $aGoal['idgoal']);
                    if (!empty($requestedColumns) && !in_array($metric->getName(), $requestedColumns)) {
                        continue;
                    }
                    $extraProcessedMetrics[] = $metric;
                    $allMetrics[] = $metric->getName();
                }
                $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, $extraProcessedMetrics);
            });
        }

        // remove temporary metrics that were not explicitly requested
        if (empty($columnsToShow)) {
            $columnsToShow = $allMetrics;
            $columnsToShow[] = 'conversion_rate';
            if ($isEcommerceGoal) {
                $columnsToShow[] = 'avg_order_revenue';
            }
        }

        $dataTable->queueFilter('ColumnDelete', array($columnsToRemove = array(), $columnsToShow));

        return $dataTable;
    }

    protected function appendSegment($segment, $segmentToAppend)
    {
        return Segment::combine($segment, SegmentExpression::AND_DELIMITER, $segmentToAppend);
    }

    protected function getNumeric($idSite, $period, $date, $segment, $toFetch)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $archive = Archive::build($idSite, $period, $date, $segment);
        $dataTable = $archive->getDataTableFromNumeric($toFetch);
        return $dataTable;
    }

    /**
     * @ignore
     */
    public function getConversions($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, Archiver::getRecordName('nb_conversions', $idGoal));
    }

    /**
     * @ignore
     */
    public function getNbVisitsConverted($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, Archiver::getRecordName('nb_visits_converted', $idGoal));
    }

    /**
     * @ignore
     */
    public function getConversionRate($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        $table = $this->get($idSite, $period, $date, $segment, $idGoal, 'conversion_rate');
        $table->setMetadata(DataTable::EXTRA_PROCESSED_METRICS_METADATA_NAME, array(new ConversionRate()));
        return $table;
    }

    /**
     * @ignore
     */
    public function getRevenue($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, Archiver::getRecordName('revenue', $idGoal));
    }

    /**
     * Utility method that retrieve an archived DataTable for a specific site, date range,
     * segment and goal. If not goal is specified, this method will retrieve and sum the
     * data for every goal.
     *
     * @param string $recordName The archive entry name.
     * @param int|string $idSite The site(s) to select data for.
     * @param string $period The period type.
     * @param string $date The date type.
     * @param string $segment The segment.
     * @param int|bool $idGoal The id of the goal to get data for. If this is set to false,
     *                         data for every goal that belongs to $idSite is returned.
     * @return false|DataTable
     */
    protected function getGoalSpecificDataTable($recordName, $idSite, $period, $date, $segment, $idGoal)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $archive = Archive::build($idSite, $period, $date, $segment);

        // check for the special goal ids
        $realGoalId = $idGoal != true ? false : self::convertSpecialGoalIds($idGoal);

        // get the data table
        $dataTable = $archive->getDataTable(Archiver::getRecordName($recordName, $realGoalId), $idSubtable = null);
        $dataTable->queueFilter('ReplaceColumnNames');

        return $dataTable;
    }

    /**
     * Gets a DataTable that maps ranges of days to the number of conversions that occurred
     * within those ranges, for the specified site, date range, segment and goal.
     *
     * @param int $idSite The site to select data from.
     * @param string $period The period type.
     * @param string $date The date type.
     * @param string|bool $segment The segment.
     * @param int|bool $idGoal The id of the goal to get data for. If this is set to false,
     *                         data for every goal that belongs to $idSite is returned.
     * @return false|DataTable
     */
    public function getDaysToConversion($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        $dataTable = $this->getGoalSpecificDataTable(
            Archiver::DAYS_UNTIL_CONV_RECORD_NAME, $idSite, $period, $date, $segment, $idGoal);

        $dataTable->queueFilter('Sort', array('label', 'asc', true, false));
        $dataTable->queueFilter(
            'BeautifyRangeLabels', array(Piwik::translate('Intl_OneDay'), Piwik::translate('Intl_NDays')));

        return $dataTable;
    }

    /**
     * Gets a DataTable that maps ranges of visit counts to the number of conversions that
     * occurred on those visits for the specified site, date range, segment and goal.
     *
     * @param int $idSite The site to select data from.
     * @param string $period The period type.
     * @param string $date The date type.
     * @param string|bool $segment The segment.
     * @param int|bool $idGoal The id of the goal to get data for. If this is set to false,
     *                         data for every goal that belongs to $idSite is returned.
     * @return bool|DataTable
     */
    public function getVisitsUntilConversion($idSite, $period, $date, $segment = false, $idGoal = false)
    {
        $dataTable = $this->getGoalSpecificDataTable(
            Archiver::VISITS_UNTIL_RECORD_NAME, $idSite, $period, $date, $segment, $idGoal);

        $dataTable->queueFilter('Sort', array('label', 'asc', true, false));
        $dataTable->queueFilter(
            'BeautifyRangeLabels', array(Piwik::translate('General_OneVisit'), Piwik::translate('General_NVisits')));

        return $dataTable;
    }

    /**
     * Enhances the dataTable with Items attributes found in the Custom Variables report.
     *
     * @param $dataTable
     * @param $recordName
     * @param $idSite
     * @param $period
     * @param $date
     * @param $segment
     */
    protected function enrichItemsTableWithViewMetrics($dataTable, $recordName, $idSite, $period, $date, $segment)
    {
        if (!Manager::getInstance()->isPluginActivated('CustomVariables')) {
            return;
        }

        // Enrich the datatable with Product/Categories views, and conversion rates
        $customVariables = \Piwik\Plugins\CustomVariables\API::getInstance()->getCustomVariables($idSite, $period, $date, $segment, $expanded = false,
            $_leavePiwikCoreVariables = true);
        $mapping = array(
            'Goals_ItemsSku'      => '_pks',
            'Goals_ItemsName'     => '_pkn',
            'Goals_ItemsCategory' => '_pkc',
        );
        $customVarNameToLookFor = $mapping[$recordName];

        // Handle case where date=last30&period=day
        if ($customVariables instanceof DataTable\Map) {
            $customVariableDatatables = $customVariables->getDataTables();
            $dataTables = $dataTable->getDataTables();
            foreach ($customVariableDatatables as $key => $customVariableTableForDate) {
                $dataTableForDate = isset($dataTables[$key]) ? $dataTables[$key] : new DataTable();

                // we do not enter the IF
                // if case idSite=1,3 AND period=day&date=datefrom,dateto,
                if ($customVariableTableForDate instanceof DataTable
                    && $customVariableTableForDate->getMetadata(Archive\DataTableFactory::TABLE_METADATA_PERIOD_INDEX)
                ) {
                    $dateRewrite = $customVariableTableForDate->getMetadata(Archive\DataTableFactory::TABLE_METADATA_PERIOD_INDEX)->getDateStart()->toString();
                    $row = $customVariableTableForDate->getRowFromLabel($customVarNameToLookFor);
                    if ($row) {
                        $idSubtable = $row->getIdSubDataTable();
                        $this->enrichItemsDataTableWithItemsViewMetrics($dataTableForDate, $idSite, $period, $dateRewrite, $segment, $idSubtable);
                    }
                    $dataTable->addTable($dataTableForDate, $key);
                }
            }
        } elseif ($customVariables instanceof DataTable) {
            $row = $customVariables->getRowFromLabel($customVarNameToLookFor);
            if ($row) {
                $idSubtable = $row->getIdSubDataTable();
                $this->enrichItemsDataTableWithItemsViewMetrics($dataTable, $idSite, $period, $date, $segment, $idSubtable);
            }
        }
    }


    private function getCacheId($idSite)
    {
        return CacheId::pluginAware('Goals.getGoals.' . $idSite);
    }

    private function getGoalsInfoStaticCache()
    {
        return PiwikCache::getTransientCache();
    }
}
