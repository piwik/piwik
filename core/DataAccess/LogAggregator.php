<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\DataAccess;

use Piwik\ArchiveProcessor\ArchivingStatus;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataArray;
use Piwik\Date;
use Piwik\Db;
use Piwik\DbHelper;
use Piwik\Metrics;
use Piwik\Plugin\LogTablesProvider;
use Piwik\Segment;
use Piwik\Tracker\GoalManager;
use Psr\Log\LoggerInterface;

/**
 * Contains methods that calculate metrics by aggregating log data (visits, actions, conversions,
 * ecommerce items).
 *
 * You can use the methods in this class within {@link Piwik\Plugin\Archiver Archiver} descendants
 * to aggregate log data without having to write SQL queries.
 *
 * ### Aggregation Dimension
 *
 * All aggregation methods accept a **dimension** parameter. These parameters are important as
 * they control how rows in a table are aggregated together.
 *
 * A **_dimension_** is just a table column. Rows that have the same values for these columns are
 * aggregated together. The result of these aggregations is a set of metrics for every recorded value
 * of a **dimension**.
 *
 * _Note: A dimension is essentially the same as a **GROUP BY** field._
 *
 * ### Examples
 *
 * **Aggregating visit data**
 *
 *     $archiveProcessor = // ...
 *     $logAggregator = $archiveProcessor->getLogAggregator();
 *
 *     // get metrics for every used browser language of all visits by returning visitors
 *     $query = $logAggregator->queryVisitsByDimension(
 *         $dimensions = array('log_visit.location_browser_lang'),
 *         $where = 'log_visit.visitor_returning = 1',
 *
 *         // also count visits for each browser language that are not located in the US
 *         $additionalSelects = array('sum(case when log_visit.location_country <> 'us' then 1 else 0 end) as nonus'),
 *
 *         // we're only interested in visits, unique visitors & actions, so don't waste time calculating anything else
 *         $metrics = array(Metrics::INDEX_NB_UNIQ_VISITORS, Metrics::INDEX_NB_VISITS, Metrics::INDEX_NB_ACTIONS),
 *     );
 *     if ($query === false) {
 *         return;
 *     }
 *
 *     while ($row = $query->fetch()) {
 *         $uniqueVisitors = $row[Metrics::INDEX_NB_UNIQ_VISITORS];
 *         $visits = $row[Metrics::INDEX_NB_VISITS];
 *         $actions = $row[Metrics::INDEX_NB_ACTIONS];
 *
 *         // ... do something w/ calculated metrics ...
 *     }
 *
 * **Aggregating conversion data**
 *
 *     $archiveProcessor = // ...
 *     $logAggregator = $archiveProcessor->getLogAggregator();
 *
 *     // get metrics for ecommerce conversions for each country
 *     $query = $logAggregator->queryConversionsByDimension(
 *         $dimensions = array('log_conversion.location_country'),
 *         $where = 'log_conversion.idgoal = 0', // 0 is the special ecommerceOrder idGoal value in the table
 *
 *         // also calculate average tax and max shipping per country
 *         $additionalSelects = array(
 *             'AVG(log_conversion.revenue_tax) as avg_tax',
 *             'MAX(log_conversion.revenue_shipping) as max_shipping'
 *         )
 *     );
 *     if ($query === false) {
 *         return;
 *     }
 *
 *     while ($row = $query->fetch()) {
 *         $country = $row['location_country'];
 *         $numEcommerceSales = $row[Metrics::INDEX_GOAL_NB_CONVERSIONS];
 *         $numVisitsWithEcommerceSales = $row[Metrics::INDEX_GOAL_NB_VISITS_CONVERTED];
 *         $avgTaxForCountry = $row['avg_tax'];
 *         $maxShippingForCountry = $row['max_shipping'];
 *
 *         // ... do something with aggregated data ...
 *     }
 */
class LogAggregator
{
    const LOG_VISIT_TABLE = 'log_visit';

    const LOG_ACTIONS_TABLE = 'log_link_visit_action';

    const LOG_CONVERSION_TABLE = "log_conversion";

    const REVENUE_SUBTOTAL_FIELD = 'revenue_subtotal';

    const REVENUE_TAX_FIELD = 'revenue_tax';

    const REVENUE_SHIPPING_FIELD = 'revenue_shipping';

    const REVENUE_DISCOUNT_FIELD = 'revenue_discount';

    const TOTAL_REVENUE_FIELD = 'revenue';

    const ITEMS_COUNT_FIELD = "items";

    const CONVERSION_DATETIME_FIELD = "server_time";

    const ACTION_DATETIME_FIELD = "server_time";

    const VISIT_DATETIME_FIELD = 'visit_last_action_time';

    const IDGOAL_FIELD = 'idgoal';

    const FIELDS_SEPARATOR = ", \n\t\t\t";

    const LOG_TABLE_SEGMENT_TEMPORARY_PREFIX = 'logtmpsegment';

    /** @var \Piwik\Date */
    protected $dateStart;

    /** @var \Piwik\Date */
    protected $dateEnd;

    /** @var int[] */
    protected $sites;

    /** @var \Piwik\Segment */
    protected $segment;

    /**
     * @var string
     */
    private $queryOriginHint = '';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $isRootArchiveRequest;

    /**
     * @var bool
     */
    private $allowUsageSegmentCache = false;

    /**
     * @var Parameters
     */
    private $params;

    /**
     * Constructor.
     *
     * @param \Piwik\ArchiveProcessor\Parameters $params
     */
    public function __construct(Parameters $params, LoggerInterface $logger = null)
    {
        $this->dateStart = $params->getDateTimeStart();
        $this->dateEnd = $params->getDateTimeEnd();
        $this->segment = $params->getSegment();
        $this->sites = $params->getIdSites();
        $this->isRootArchiveRequest = $params->isRootArchiveRequest();
        $this->logger = $logger ?: StaticContainer::get('Psr\Log\LoggerInterface');
        $this->params = $params;
    }

    public function setSites($sites)
    {
        $this->sites = array_map('intval', $sites);
    }

    public function getSites()
    {
        return $this->sites;
    }

    public function getSegment()
    {
        return $this->segment;
    }

    public function setQueryOriginHint($nameOfOrigiin)
    {
        $this->queryOriginHint = $nameOfOrigiin;
    }

    public function getSegmentTmpTableName()
    {
        $bind = $this->getGeneralQueryBindParams();
        $tableName = self::LOG_TABLE_SEGMENT_TEMPORARY_PREFIX . md5(json_encode($bind) . $this->segment->getString());

        $lengthPrefix = Common::mb_strlen(Common::prefixTable(''));
        $maxLength = Db\Schema\Mysql::MAX_TABLE_NAME_LENGTH - $lengthPrefix;

        return Common::mb_substr($tableName, 0, $maxLength);
    }

    public function cleanup()
    {
        if (!$this->segment->isEmpty() && $this->isSegmentCacheEnabled()) {
            $segmentTable = $this->getSegmentTmpTableName();
            $segmentTable = Common::prefixTable($segmentTable);

            if ($this->doesSegmentTableExist($segmentTable)) {
                // safety in case an older MySQL version is used that does not drop table at the end of the connection
                // automatically. also helps us release disk space/memory earlier when multiple segments are archived
                $this->getDb()->query('DROP TEMPORARY TABLE IF EXISTS ' . $segmentTable);
            }

            $logTablesProvider = $this->getLogTableProvider();
            if ($logTablesProvider->getLogTable($segmentTable)) {
                $logTablesProvider->setTempTable(null); // no longer available
            }
        }
    }

    private function doesSegmentTableExist($segmentTablePrefixed)
    {
        try {
            // using DROP TABLE IF EXISTS would not work on a DB reader if the table doesn't exist...
            $this->getDb()->fetchOne('SELECT 1 FROM ' . $segmentTablePrefixed . ' LIMIT 1');
            $tableExists = true;
        } catch (\Exception $e) {
            $tableExists = false;
        }

        return $tableExists;
    }

    private function isSegmentCacheEnabled()
    {
        if (!$this->allowUsageSegmentCache) {
            return false;
        }

        $config = Config::getInstance();
        $general = $config->General;
        return !empty($general['enable_segments_cache']);
    }

    public function allowUsageSegmentCache()
    {
        $this->allowUsageSegmentCache = true;
    }

    private function getLogTableProvider()
    {
        return StaticContainer::get(LogTablesProvider::class);
    }

    private function createTemporaryTable($unprefixedSegmentTableName, $segmentSelectSql, $segmentSelectBind)
    {
        $table = Common::prefixTable($unprefixedSegmentTableName);

        if ($this->doesSegmentTableExist($table)) {
            return; // no need to create the table, it was already created... better to have a select vs unneeded create table
        }
	    
        $engine = '';
        if (defined('PIWIK_TEST_MODE') && PIWIK_TEST_MODE) {
            $engine = 'ENGINE=MEMORY';
        }
        $tempTableIdVisitColumn = 'idvisit  BIGINT(10) UNSIGNED NOT NULL';
        $createTableSql = 'CREATE TEMPORARY TABLE ' . $table . ' (' . $tempTableIdVisitColumn . ') ' . $engine;
        // we do not insert the data right away using create temporary table ... select ...
        // to avoid metadata lock see eg https://www.percona.com/blog/2018/01/10/why-avoid-create-table-as-select-statement/

        $readerDb = Db::getReader();
        try {
            $readerDb->query($createTableSql);
        } catch (\Exception $e) {
            if ($readerDb->isErrNo($e, \Piwik\Updater\Migration\Db::ERROR_CODE_TABLE_EXISTS)) {
                return;
            } elseif ($readerDb->isErrNo($e, \Piwik\Updater\Migration\Db::ERROR_CODE_REQUIRES_PRIMARY_KEY)
                || $readerDb->isErrNo($e, \Piwik\Updater\Migration\Db::ERROR_CODE_UNABLE_CREATE_TABLE_WITHOUT_PRIMARY_KEY
                || stripos($e->getMessage(), 'requires a primary key') !== false
                || stripos($e->getMessage(), 'table without a primary key') !== false)
            ) {
                $createTableSql = str_replace($tempTableIdVisitColumn, $tempTableIdVisitColumn . ', PRIMARY KEY (`idvisit`)', $createTableSql);

                try {
                    $readerDb->query($createTableSql);
                } catch (\Exception $e) {
                    if ($readerDb->isErrNo($e, \Piwik\Updater\Migration\Db::ERROR_CODE_TABLE_EXISTS)) {
                        return;
                    } else {
                        throw $e;
                    }
                }
            } else {
                throw $e;
            }
        }

        $transactionLevel = new Db\TransactionLevel($readerDb);
        $canSetTransactionLevel = $transactionLevel->canLikelySetTransactionLevel();

	    if ($canSetTransactionLevel) {
	        // i know this could be shortened to one if or one line but I want to make sure this line where we
            // set uncommitted is easily noticeable in the code as it could be missed quite easily otherwise
            // we set uncommitted so we don't make the INSERT INTO... SELECT... locking ... we do not want to lock
            // eg the visits table
	        if (!$transactionLevel->setUncommitted()) {
	        	$canSetTransactionLevel = false;
	        }
	    }

        if (!$canSetTransactionLevel) {
            // transaction level doesn't work... we're instead executing the select individually and then insert the data
            // this uses more memory but at least is not locking
            $all = $readerDb->fetchAll($segmentSelectSql, $segmentSelectBind);
            if (!empty($all)) {
                // we're not using batchinsert since this would not support the reader DB.
                $readerDb->query('INSERT INTO ' . $table . ' VALUES ('.implode('),(', array_column($all, 'idvisit')).')');
            }
            return;
        }

        $insertIntoStatement = 'INSERT INTO ' . $table . ' (idvisit) ' . $segmentSelectSql;
        $readerDb->query($insertIntoStatement, $segmentSelectBind);

        $transactionLevel->restorePreviousStatus();
    }

    public function generateQuery($select, $from, $where, $groupBy, $orderBy, $limit = 0, $offset = 0)
    {
        $segment = $this->segment;
        $bind = $this->getGeneralQueryBindParams();

        if (!$this->segment->isEmpty() && $this->isSegmentCacheEnabled()) {
            // here we create the TMP table and apply the segment including the datetime and the requested idsite
            // at the end we generated query will no longer need to apply the datetime/idsite and segment
            $segment = new Segment('', $this->sites, $this->params->getPeriod()->getDateTimeStart(), $this->params->getPeriod()->getDateTimeEnd());

            $segmentTable = $this->getSegmentTmpTableName();

            $segmentWhere = $this->getWhereStatement('log_visit', 'visit_last_action_time');
            $segmentBind = $this->getGeneralQueryBindParams();

            $logQueryBuilder = StaticContainer::get('Piwik\DataAccess\LogQueryBuilder');
            $forceGroupByBackup = $logQueryBuilder->getForcedInnerGroupBySubselect();
            $logQueryBuilder->forceInnerGroupBySubselect(LogQueryBuilder::FORCE_INNER_GROUP_BY_NO_SUBSELECT);
            $segmentSql = $this->segment->getSelectQuery('distinct log_visit.idvisit as idvisit', 'log_visit', $segmentWhere, $segmentBind, 'log_visit.idvisit ASC');
            $logQueryBuilder->forceInnerGroupBySubselect($forceGroupByBackup);

            $this->createTemporaryTable($segmentTable, $segmentSql['sql'], $segmentSql['bind']);

            if (!is_array($from)) {
                $from = array($segmentTable, $from);
            } else {
                array_unshift($from, $segmentTable);
            }

            $logTablesProvider = $this->getLogTableProvider();
            $logTablesProvider->setTempTable(new LogTableTemporary($segmentTable));

            foreach ($logTablesProvider->getAllLogTables() as $logTable) {
                if ($logTable->getDateTimeColumn()) {
                    $whereTest = $this->getWhereStatement($logTable->getName(), $logTable->getDateTimeColumn());
                    if (strpos($where, $whereTest) === 0) {
                        // we don't need to apply the where statement again as it would have been applied already
                        // in the temporary table... instead it should join the tables through the idvisit index
                        $where = ltrim(str_replace($whereTest, '', $where));
                        if (stripos($where, 'and ') === 0) {
                            $where = substr($where, strlen('and '));
                        }
                        $bind = array();
                        break;
                    }
                }

            }

        }

        $query = $segment->getSelectQuery($select, $from, $where, $bind, $orderBy, $groupBy, $limit, $offset);

        $select = 'SELECT';
        if ($this->queryOriginHint && is_array($query) && 0 === strpos(trim($query['sql']), $select)) {
            $query['sql'] = trim($query['sql']);
            $query['sql'] = 'SELECT /* ' . $this->queryOriginHint . ' */' . substr($query['sql'], strlen($select));
        }

        if (!$this->getSegment()->isEmpty() && is_array($query) && 0 === strpos(trim($query['sql']), $select)) {
            $query['sql'] = trim($query['sql']);
            $query['sql'] = 'SELECT /* ' . $this->dateStart->toString() . ',' . $this->dateEnd->toString() . ' sites ' . implode(',', array_map('intval', $this->sites)) . ' segmenthash ' . $this->getSegment()->getHash(). ' */' . substr($query['sql'], strlen($select));
        }
 
        return $query;
    }

    protected function getVisitsMetricFields()
    {
        return array(
            Metrics::INDEX_NB_UNIQ_VISITORS               => "count(distinct " . self::LOG_VISIT_TABLE . ".idvisitor)",
            Metrics::INDEX_NB_UNIQ_FINGERPRINTS           => "count(distinct " . self::LOG_VISIT_TABLE . ".config_id)",
            Metrics::INDEX_NB_VISITS                      => "count(*)",
            Metrics::INDEX_NB_ACTIONS                     => "sum(" . self::LOG_VISIT_TABLE . ".visit_total_actions)",
            Metrics::INDEX_MAX_ACTIONS                    => "max(" . self::LOG_VISIT_TABLE . ".visit_total_actions)",
            Metrics::INDEX_SUM_VISIT_LENGTH               => "sum(" . self::LOG_VISIT_TABLE . ".visit_total_time)",
            Metrics::INDEX_BOUNCE_COUNT                   => "sum(case " . self::LOG_VISIT_TABLE . ".visit_total_actions when 1 then 1 when 0 then 1 else 0 end)",
            Metrics::INDEX_NB_VISITS_CONVERTED            => "sum(case " . self::LOG_VISIT_TABLE . ".visit_goal_converted when 1 then 1 else 0 end)",
            Metrics::INDEX_NB_USERS                       => "count(distinct " . self::LOG_VISIT_TABLE . ".user_id)",
            Metrics::INDEX_NB_PROFILABLE                  => "sum(" . self::LOG_VISIT_TABLE . ".profilable)",
        );
    }

    public static function getConversionsMetricFields()
    {
        return array(
            Metrics::INDEX_GOAL_NB_CONVERSIONS             => "count(*)",
            Metrics::INDEX_GOAL_NB_VISITS_CONVERTED        => "count(distinct " . self::LOG_CONVERSION_TABLE . ".idvisit)",
            Metrics::INDEX_GOAL_REVENUE                    => self::getSqlConversionRevenueSum(self::TOTAL_REVENUE_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL => self::getSqlConversionRevenueSum(self::REVENUE_SUBTOTAL_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_TAX      => self::getSqlConversionRevenueSum(self::REVENUE_TAX_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING => self::getSqlConversionRevenueSum(self::REVENUE_SHIPPING_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_DISCOUNT => self::getSqlConversionRevenueSum(self::REVENUE_DISCOUNT_FIELD),
            Metrics::INDEX_GOAL_ECOMMERCE_ITEMS            => "SUM(" . self::LOG_CONVERSION_TABLE . "." . self::ITEMS_COUNT_FIELD . ")",
        );
    }

    private static function getSqlConversionRevenueSum($field)
    {
        return self::getSqlRevenue('SUM(' . self::LOG_CONVERSION_TABLE . '.' . $field . ')');
    }

    public static function getSqlRevenue($field)
    {
        return "ROUND(" . $field . "," . GoalManager::REVENUE_PRECISION . ")";
    }

    /**
     * Helper function that returns an array with common metrics for a given log_visit field distinct values.
     *
     * The statistics returned are:
     *  - number of unique visitors
     *  - number of visits
     *  - number of actions
     *  - maximum number of action for a visit
     *  - sum of the visits' length in sec
     *  - count of bouncing visits (visits with one page view)
     *
     * For example if $dimension = 'config_os' it will return the statistics for every distinct Operating systems
     * The returned array will have a row per distinct operating systems,
     * and a column per stat (nb of visits, max  actions, etc)
     *
     * 'label'    Metrics::INDEX_NB_UNIQ_VISITORS    Metrics::INDEX_NB_VISITS    etc.
     * Linux    27    66    ...
     * Windows XP    12    ...
     * Mac OS    15    36    ...
     *
     * @param string $dimension Table log_visit field name to be use to compute common stats
     * @return DataArray
     */
    public function getMetricsFromVisitByDimension($dimension)
    {
        if (!is_array($dimension)) {
            $dimension = array($dimension);
        }
        if (count($dimension) == 1) {
            $dimension = array("label" => reset($dimension));
        }
        $query = $this->queryVisitsByDimension($dimension);
        $metrics = new DataArray();
        while ($row = $query->fetch()) {
            $metrics->sumMetricsVisits($row["label"], $row);
        }
        return $metrics;
    }

    /**
     * Executes and returns a query aggregating visit logs, optionally grouping by some dimension. Returns
     * a DB statement that can be used to iterate over the result
     *
     * **Result Set**
     *
     * The following columns are in each row of the result set:
     *
     * - **{@link \Piwik\Metrics::INDEX_NB_UNIQ_VISITORS}**: The total number of unique visitors in this group
     *                                                      of aggregated visits.
     * - **{@link \Piwik\Metrics::INDEX_NB_VISITS}**: The total number of visits aggregated.
     * - **{@link \Piwik\Metrics::INDEX_NB_ACTIONS}**: The total number of actions performed in this group of
     *                                                aggregated visits.
     * - **{@link \Piwik\Metrics::INDEX_MAX_ACTIONS}**: The maximum actions performed in one visit for this group of
     *                                                 visits.
     * - **{@link \Piwik\Metrics::INDEX_SUM_VISIT_LENGTH}**: The total amount of time spent on the site for this
     *                                                      group of visits.
     * - **{@link \Piwik\Metrics::INDEX_BOUNCE_COUNT}**: The total number of bounced visits in this group of
     *                                                  visits.
     * - **{@link \Piwik\Metrics::INDEX_NB_VISITS_CONVERTED}**: The total number of visits for which at least one
     *                                                         conversion occurred, for this group of visits.
     *
     * Additional data can be selected by setting the `$additionalSelects` parameter.
     *
     * _Note: The metrics returned by this query can be customized by the `$metrics` parameter._
     *
     * @param array|string $dimensions `SELECT` fields (or just one field) that will be grouped by,
     *                                 eg, `'referrer_name'` or `array('referrer_name', 'referrer_keyword')`.
     *                                 The metrics retrieved from the query will be specific to combinations
     *                                 of these fields. So if `array('referrer_name', 'referrer_keyword')`
     *                                 is supplied, the query will aggregate visits for each referrer/keyword
     *                                 combination.
     * @param bool|string $where Additional condition for the `WHERE` clause. Can be used to filter
     *                           the set of visits that are considered for aggregation.
     * @param array $additionalSelects Additional `SELECT` fields that are not included in the group by
     *                                 clause. These can be aggregate expressions, eg, `SUM(somecol)`.
     * @param bool|array $metrics The set of metrics to calculate and return. If false, the query will select
     *                            all of them. The following values can be used:
     *
     *                            - {@link \Piwik\Metrics::INDEX_NB_UNIQ_VISITORS}
     *                            - {@link \Piwik\Metrics::INDEX_NB_VISITS}
     *                            - {@link \Piwik\Metrics::INDEX_NB_ACTIONS}
     *                            - {@link \Piwik\Metrics::INDEX_MAX_ACTIONS}
     *                            - {@link \Piwik\Metrics::INDEX_SUM_VISIT_LENGTH}
     *                            - {@link \Piwik\Metrics::INDEX_BOUNCE_COUNT}
     *                            - {@link \Piwik\Metrics::INDEX_NB_VISITS_CONVERTED}
     * @param bool|\Piwik\RankingQuery $rankingQuery
     *                                   A pre-configured ranking query instance that will be used to limit the result.
     *                                   If set, the return value is the array returned by {@link \Piwik\RankingQuery::execute()}.
     * @param bool|string $orderBy       Order By clause to add (e.g. user_id ASC)
     * @param int $timeLimitInMs         Adds a MAX_EXECUTION_TIME query hint to the query if $timeLimitInMs > 0
     *
     * @return mixed A Zend_Db_Statement if `$rankingQuery` isn't supplied, otherwise the result of
     *               {@link \Piwik\RankingQuery::execute()}. Read {@link queryVisitsByDimension() this}
     *               to see what aggregate data is calculated by the query.
     * @api
     */
    public function queryVisitsByDimension(array $dimensions = array(), $where = false, array $additionalSelects = array(),
                                           $metrics = false, $rankingQuery = false, $orderBy = false, $timeLimitInMs = -1)
    {
        $tableName = self::LOG_VISIT_TABLE;
        $availableMetrics = $this->getVisitsMetricFields();

        $select  = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics, $metrics);
        $from    = array($tableName);
        $where   = $this->getWhereStatement($tableName, self::VISIT_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);
        $orderBys = $orderBy ? [$orderBy] : [];

        if ($rankingQuery) {
            $orderBys[] = '`' . Metrics::INDEX_NB_VISITS . '` DESC';
        }

        $query = $this->generateQuery($select, $from, $where, $groupBy, implode(', ', $orderBys));

        if ($rankingQuery) {
            unset($availableMetrics[Metrics::INDEX_MAX_ACTIONS]);

            // INDEX_NB_UNIQ_FINGERPRINTS is only processed if specifically asked for
            if (!$this->isMetricRequested(Metrics::INDEX_NB_UNIQ_FINGERPRINTS, $metrics)) {
                unset($availableMetrics[Metrics::INDEX_NB_UNIQ_FINGERPRINTS]);
            }

            $sumColumns = array_keys($availableMetrics);

            if ($metrics) {
                $sumColumns = array_intersect($sumColumns, $metrics);
            }

            $rankingQuery->addColumn($sumColumns, 'sum');
            if ($this->isMetricRequested(Metrics::INDEX_MAX_ACTIONS, $metrics)) {
                $rankingQuery->addColumn(Metrics::INDEX_MAX_ACTIONS, 'max');
            }

            return $rankingQuery->execute($query['sql'], $query['bind'], $timeLimitInMs);
        }

        $query['sql'] = DbHelper::addMaxExecutionTimeHintToQuery($query['sql'], $timeLimitInMs);

        return $this->getDb()->query($query['sql'], $query['bind']);
    }

    protected function getSelectsMetrics($metricsAvailable, $metricsRequested = false)
    {
        $selects = array();

        foreach ($metricsAvailable as $metricId => $statement) {
            if ($this->isMetricRequested($metricId, $metricsRequested)) {
                $aliasAs   = $this->getSelectAliasAs($metricId);
                $selects[] = $statement . $aliasAs;
            }
        }

        return $selects;
    }

    protected function getSelectStatement($dimensions, $tableName, $additionalSelects, array $availableMetrics, $requestedMetrics = false)
    {
        $dimensionsToSelect = $this->getDimensionsToSelect($dimensions, $additionalSelects);

        $selects = array_merge(
            $this->getSelectDimensions($dimensionsToSelect, $tableName),
            $this->getSelectsMetrics($availableMetrics, $requestedMetrics),
            !empty($additionalSelects) ? $additionalSelects : array()
        );

        $select = implode(self::FIELDS_SEPARATOR, $selects);
        return $select;
    }

    /**
     * Will return the subset of $dimensions that are not found in $additionalSelects
     *
     * @param $dimensions
     * @param array $additionalSelects
     * @return array
     */
    protected function getDimensionsToSelect($dimensions, $additionalSelects)
    {
        if (empty($additionalSelects)) {
            return $dimensions;
        }

        $dimensionsToSelect = array();
        foreach ($dimensions as $selectAs => $dimension) {
            $asAlias = $this->getSelectAliasAs($dimension);
            foreach ($additionalSelects as $additionalSelect) {
                if (strpos($additionalSelect, $asAlias) === false) {
                    $dimensionsToSelect[$selectAs] = $dimension;
                }
            }
        }

        $dimensionsToSelect = array_unique($dimensionsToSelect);
        return $dimensionsToSelect;
    }

    /**
     * Returns the dimensions array, where
     * (1) the table name is prepended to the field
     * (2) the "AS `label` " is appended to the field
     *
     * @param $dimensions
     * @param $tableName
     * @param bool $appendSelectAs
     * @param bool $parseSelectAs
     * @return mixed
     */
    protected function getSelectDimensions($dimensions, $tableName, $appendSelectAs = true)
    {
        foreach ($dimensions as $selectAs => &$field) {
            $selectAsString = $field;

            if (!is_numeric($selectAs)) {
                $selectAsString = $selectAs;
            } else if ($this->isFieldFunctionOrComplexExpression($field)) {
                // if complex expression has a select as, use it
                if (!$appendSelectAs && preg_match('/\s+AS\s+(.*?)\s*$/', $field, $matches)) {
                    $field = $matches[1];
                    continue;
                }

                // if function w/o select as, do not alias or prefix
                $selectAsString = $appendSelectAs = false;
            }

            $isKnownField = !in_array($field, array('referrer_data'));

            if ($selectAsString == $field && $isKnownField) {
                $field = $this->prefixColumn($field, $tableName);
            }

            if ($appendSelectAs && $selectAsString) {
                $field = $this->prefixColumn($field, $tableName) . $this->getSelectAliasAs($selectAsString);
            }
        }

        return $dimensions;
    }

    /**
     * Prefixes a column name with a table name if not already done.
     *
     * @param string $column eg, 'location_provider'
     * @param string $tableName eg, 'log_visit'
     * @return string eg, 'log_visit.location_provider'
     */
    private function prefixColumn($column, $tableName)
    {
        if (strpos($column, '.') === false) {
            return $tableName . '.' . $column;
        } else {
            return $column;
        }
    }

    protected function isFieldFunctionOrComplexExpression($field)
    {
        return strpos($field, "(") !== false
            || strpos($field, "CASE") !== false;
    }

    protected function getSelectAliasAs($metricId)
    {
        return " AS `" . $metricId . "`";
    }

    protected function isMetricRequested($metricId, $metricsRequested)
    {
        // do not process INDEX_NB_UNIQ_FINGERPRINTS unless specifically asked for
        if ($metricsRequested === false) {
            if ($metricId == Metrics::INDEX_NB_UNIQ_FINGERPRINTS) {
                return false;
            }
            return true;
        }
        return in_array($metricId, $metricsRequested);
    }

    public function getWhereStatement($tableName, $datetimeField, $extraWhere = false)
    {
        $where = "$tableName.$datetimeField >= ?
				AND $tableName.$datetimeField <= ?
				AND $tableName.idsite IN (". Common::getSqlStringFieldsArray($this->sites) . ")";

        if (!empty($extraWhere)) {
            $extraWhere = sprintf($extraWhere, $tableName, $tableName);
            $where     .= ' AND ' . $extraWhere;
        }

        return $where;
    }

    protected function getGroupByStatement($dimensions, $tableName)
    {
        $dimensions = $this->getSelectDimensions($dimensions, $tableName, $appendSelectAs = false);
        $groupBy    = implode(", ", $dimensions);

        return $groupBy;
    }

    /**
     * Returns general bind parameters for all log aggregation queries. This includes the datetime
     * start of entities, datetime end of entities and IDs of all sites.
     *
     * @return array
     */
    public function getGeneralQueryBindParams()
    {
        $bind = array($this->dateStart->toString(Date::DATE_TIME_FORMAT), $this->dateEnd->toString(Date::DATE_TIME_FORMAT));
        $bind = array_merge($bind, $this->sites);

        return $bind;
    }

    /**
     * Executes and returns a query aggregating ecommerce item data (everything stored in the
     * **log\_conversion\_item** table)  and returns a DB statement that can be used to iterate over the result
     *
     * <a name="queryEcommerceItems-result-set"></a>
     * **Result Set**
     *
     * Each row of the result set represents an aggregated group of ecommerce items. The following
     * columns are in each row of the result set:
     *
     * - **{@link Piwik\Metrics::INDEX_ECOMMERCE_ITEM_REVENUE}**: The total revenue for the group of items.
     * - **{@link Piwik\Metrics::INDEX_ECOMMERCE_ITEM_QUANTITY}**: The total number of items in this group.
     * - **{@link Piwik\Metrics::INDEX_ECOMMERCE_ITEM_PRICE}**: The total price for the group of items.
     * - **{@link Piwik\Metrics::INDEX_ECOMMERCE_ORDERS}**: The total number of orders this group of items
     *                                                      belongs to. This will be <= to the total number
     *                                                      of items in this group.
     * - **{@link Piwik\Metrics::INDEX_NB_VISITS}**: The total number of visits that caused these items to be logged.
     * - **ecommerceType**: Either {@link Piwik\Tracker\GoalManager::IDGOAL_CART} if the items in this group were
     *                      abandoned by a visitor, or {@link Piwik\Tracker\GoalManager::IDGOAL_ORDER} if they
     *                      were ordered by a visitor.
     *
     * **Limitations**
     *
     * Segmentation is not yet supported for this aggregation method.
     *
     * @param string $dimension One or more **log\_conversion\_item** columns to group aggregated data by.
     *                          Eg, `'idaction_sku'` or `'idaction_sku, idaction_category'`.
     * @return \Zend_Db_Statement A statement object that can be used to iterate through the query's
     *                           result set. See [above](#queryEcommerceItems-result-set) to learn more
     *                           about what this query selects.
     * @api
     */
    public function queryEcommerceItems($dimension)
    {
        $query = $this->generateQuery(
            // SELECT ...
            implode(
                ', ',
                array(
                    "log_action.name AS label",
                    sprintf("log_conversion_item.%s AS labelIdAction", $dimension),
                    sprintf(
                        '%s AS `%d`',
                        self::getSqlRevenue('SUM(log_conversion_item.quantity * log_conversion_item.price)'),
                        Metrics::INDEX_ECOMMERCE_ITEM_REVENUE
                    ),
                    sprintf(
                        '%s AS `%d`',
                        self::getSqlRevenue('SUM(log_conversion_item.quantity)'),
                        Metrics::INDEX_ECOMMERCE_ITEM_QUANTITY
                    ),
                    sprintf(
                        '%s AS `%d`',
                        self::getSqlRevenue('SUM(log_conversion_item.price)'),
                        Metrics::INDEX_ECOMMERCE_ITEM_PRICE
                    ),
                    sprintf(
                        'COUNT(distinct log_conversion_item.idorder) AS `%d`',
                        Metrics::INDEX_ECOMMERCE_ORDERS
                    ),
                    sprintf(
                        'COUNT(distinct log_conversion_item.idvisit) AS `%d`',
                        Metrics::INDEX_NB_VISITS
                    ),
                    sprintf(
                        'CASE log_conversion_item.idorder WHEN \'0\' THEN %d ELSE %d END AS ecommerceType',
                        GoalManager::IDGOAL_CART,
                        GoalManager::IDGOAL_ORDER
                    )
                )
            ),

            // FROM ...
            array(
                "log_conversion_item",
                array(
                    "table" => "log_action",
                    "joinOn" => sprintf("log_conversion_item.%s = log_action.idaction", $dimension)
                )
            ),

            // WHERE ... AND ...
            implode(
                ' AND ',
                array(
                    'log_conversion_item.server_time >= ?',
                    'log_conversion_item.server_time <= ?',
                    'log_conversion_item.idsite IN (' . Common::getSqlStringFieldsArray($this->sites) . ')',
                    'log_conversion_item.deleted = 0'
                )
            ),

            // GROUP BY ...
            sprintf(
                "ecommerceType, log_conversion_item.%s",
                $dimension
            ),

            // ORDER ...
            false
        );

        return $this->getDb()->query($query['sql'], $query['bind']);
    }

    /**
     * Executes and returns a query aggregating action data (everything in the log_action table) and returns
     * a DB statement that can be used to iterate over the result
     *
     * <a name="queryActionsByDimension-result-set"></a>
     * **Result Set**
     *
     * Each row of the result set represents an aggregated group of actions. The following columns
     * are in each aggregate row:
     *
     * - **{@link Piwik\Metrics::INDEX_NB_UNIQ_VISITORS}**: The total number of unique visitors that performed
     *                                             the actions in this group.
     * - **{@link Piwik\Metrics::INDEX_NB_VISITS}**: The total number of visits these actions belong to.
     * - **{@link Piwik\Metrics::INDEX_NB_ACTIONS}**: The total number of actions in this aggregate group.
     *
     * Additional data can be selected through the `$additionalSelects` parameter.
     *
     * _Note: The metrics calculated by this query can be customized by the `$metrics` parameter._
     *
     * @param array|string $dimensions One or more SELECT fields that will be used to group the log_action
     *                                 rows by. This parameter determines which log_action rows will be
     *                                 aggregated together.
     * @param bool|string $where Additional condition for the WHERE clause. Can be used to filter
     *                           the set of visits that are considered for aggregation.
     * @param array $additionalSelects Additional SELECT fields that are not included in the group by
     *                                 clause. These can be aggregate expressions, eg, `SUM(somecol)`.
     * @param bool|array $metrics The set of metrics to calculate and return. If `false`, the query will select
     *                            all of them. The following values can be used:
     *
     *                              - {@link Piwik\Metrics::INDEX_NB_UNIQ_VISITORS}
     *                              - {@link Piwik\Metrics::INDEX_NB_VISITS}
     *                              - {@link Piwik\Metrics::INDEX_NB_ACTIONS}
     * @param bool|\Piwik\RankingQuery $rankingQuery
     *                                   A pre-configured ranking query instance that will be used to limit the result.
     *                                   If set, the return value is the array returned by {@link Piwik\RankingQuery::execute()}.
     * @param bool|string $joinLogActionOnColumn One or more columns from the **log_link_visit_action** table that
     *                                           log_action should be joined on. The table alias used for each join
     *                                           is `"log_action$i"` where `$i` is the index of the column in this
     *                                           array.
     *
     *                                           If a string is used for this parameter, the table alias is not
     *                                           suffixed (since there is only one column).
     * @param string $secondaryOrderBy      A secondary order by clause for the ranking query
     * @param int $timeLimitInMs                Adds a MAX_EXECUTION_TIME hint to the query if $timeLimitInMs > 0
     * @return mixed A Zend_Db_Statement if `$rankingQuery` isn't supplied, otherwise the result of
     *               {@link Piwik\RankingQuery::execute()}. Read [this](#queryEcommerceItems-result-set)
     *               to see what aggregate data is calculated by the query.
     * @api
     */
    public function queryActionsByDimension(
        $dimensions,
        $where = '',
        $additionalSelects = array(),
        $metrics = false,
        $rankingQuery = null,
        $joinLogActionOnColumn = false,
        $secondaryOrderBy = null,
        $timeLimitInMs = -1
    ) {
        $tableName = self::LOG_ACTIONS_TABLE;
        $availableMetrics = $this->getActionsMetricFields();

        $select  = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics, $metrics);
        $from    = array($tableName);
        $where   = $this->getWhereStatement($tableName, self::ACTION_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);

        if ($joinLogActionOnColumn !== false) {
            $multiJoin = is_array($joinLogActionOnColumn);
            if (!$multiJoin) {
                $joinLogActionOnColumn = array($joinLogActionOnColumn);
            }

            foreach ($joinLogActionOnColumn as $i => $joinColumn) {
                $tableAlias = 'log_action' . ($multiJoin ? $i + 1 : '');

                if (strpos($joinColumn, ' ') === false) {
                    $joinOn = $tableAlias . '.idaction = ' . $tableName . '.' . $joinColumn;
                } else {
                    // more complex join column like if (...)
                    $joinOn = $tableAlias . '.idaction = ' . $joinColumn;
                }

                $from[] = array(
                    'table'      => 'log_action',
                    'tableAlias' => $tableAlias,
                    'joinOn'     => $joinOn
                );
            }
        }

        $orderBy = false;
        if ($rankingQuery) {
            $orderBy = '`' . Metrics::INDEX_NB_ACTIONS . '` DESC';
            if ($secondaryOrderBy) {
                $orderBy .= ', ' . $secondaryOrderBy;
            }
        }

        $query = $this->generateQuery($select, $from, $where, $groupBy, $orderBy);

        if ($rankingQuery !== null) {
            $sumColumns = array_keys($availableMetrics);
            if ($metrics) {
                $sumColumns = array_intersect($sumColumns, $metrics);
            }

            $rankingQuery->addColumn($sumColumns, 'sum');

            return $rankingQuery->execute($query['sql'], $query['bind'], $timeLimitInMs);
        }

        $query['sql'] = DbHelper::addMaxExecutionTimeHintToQuery($query['sql'], $timeLimitInMs);

        return $this->getDb()->query($query['sql'], $query['bind']);
    }

    protected function getActionsMetricFields()
    {
        return array(
            Metrics::INDEX_NB_VISITS        => "count(distinct " . self::LOG_ACTIONS_TABLE . ".idvisit)",
            Metrics::INDEX_NB_UNIQ_VISITORS => "count(distinct " . self::LOG_ACTIONS_TABLE . ".idvisitor)",
            Metrics::INDEX_NB_ACTIONS       => "count(*)",
        );
    }

    /**
     * Executes a query aggregating conversion data (everything in the **log_conversion** table) and returns
     * a DB statement that can be used to iterate over the result.
     *
     * <a name="queryConversionsByDimension-result-set"></a>
     * **Result Set**
     *
     * Each row of the result set represents an aggregated group of conversions. The
     * following columns are in each aggregate row:
     *
     * - **{@link Piwik\Metrics::INDEX_GOAL_NB_CONVERSIONS}**: The total number of conversions in this aggregate
     *                                                         group.
     * - **{@link Piwik\Metrics::INDEX_GOAL_NB_VISITS_CONVERTED}**: The total number of visits during which these
     *                                                              conversions were converted.
     * - **{@link Piwik\Metrics::INDEX_GOAL_REVENUE}**: The total revenue generated by these conversions. This value
     *                                                  includes the revenue from individual ecommerce items.
     * - **{@link Piwik\Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL}**: The total cost of all ecommerce items sold
     *                                                                     within these conversions. This value does not
     *                                                                     include tax, shipping or any applied discount.
     *
     *                                                                     _This metric is only applicable to the special
     *                                                                     **ecommerce** goal (where `idGoal == 'ecommerceOrder'`)._
     * - **{@link Piwik\Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_TAX}**: The total tax applied to every transaction in these
     *                                                                conversions.
     *
     *                                                                _This metric is only applicable to the special
     *                                                                **ecommerce** goal (where `idGoal == 'ecommerceOrder'`)._
     * - **{@link Piwik\Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING}**: The total shipping cost for every transaction
     *                                                                     in these conversions.
     *
     *                                                                     _This metric is only applicable to the special
     *                                                                     **ecommerce** goal (where `idGoal == 'ecommerceOrder'`)._
     * - **{@link Piwik\Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_DISCOUNT}**: The total discount applied to every transaction
     *                                                                     in these conversions.
     *
     *                                                                     _This metric is only applicable to the special
     *                                                                     **ecommerce** goal (where `idGoal == 'ecommerceOrder'`)._
     * - **{@link Piwik\Metrics::INDEX_GOAL_ECOMMERCE_ITEMS}**: The total number of ecommerce items sold in each transaction
     *                                                          in these conversions.
     *
     *                                                          _This metric is only applicable to the special
     *                                                          **ecommerce** goal (where `idGoal == 'ecommerceOrder'`)._
     *
     * Additional data can be selected through the `$additionalSelects` parameter.
     *
     * _Note: This method will only query the **log_conversion** table. Other tables cannot be joined
     * using this method._
     *
     * @param array|string $dimensions One or more **SELECT** fields that will be used to group the log_conversion
     *                                 rows by. This parameter determines which **log_conversion** rows will be
     *                                 aggregated together.
     * @param bool|string $where An optional SQL expression used in the SQL's **WHERE** clause.
     * @param array $additionalSelects Additional SELECT fields that are not included in the group by
     *                                 clause. These can be aggregate expressions, eg, `SUM(somecol)`.
     * @return \Zend_Db_Statement
     */
    public function queryConversionsByDimension($dimensions = array(), $where = false, $additionalSelects = array(), $extraFrom = [])
    {
        $dimensions = array_merge(array(self::IDGOAL_FIELD), $dimensions);
        $tableName  = self::LOG_CONVERSION_TABLE;
        $availableMetrics = $this->getConversionsMetricFields();

        $select = $this->getSelectStatement($dimensions, $tableName, $additionalSelects, $availableMetrics);

        $from    = array_merge([$tableName], $extraFrom);
        $where   = $this->getWhereStatement($tableName, self::CONVERSION_DATETIME_FIELD, $where);
        $groupBy = $this->getGroupByStatement($dimensions, $tableName);
        $orderBy = false;
        $query   = $this->generateQuery($select, $from, $where, $groupBy, $orderBy);

        return $this->getDb()->query($query['sql'], $query['bind']);
    }

    /**
     * Creates and returns an array of SQL `SELECT` expressions that will each count how
     * many rows have a column whose value is within a certain range.
     *
     * **Note:** The result of this function is meant for use in the `$additionalSelects` parameter
     * in one of the query... methods (for example {@link queryVisitsByDimension()}).
     *
     * **Example**
     *
     *     // summarize one column
     *     $visitTotalActionsRanges = array(
     *         array(1, 1),
     *         array(2, 10),
     *         array(10)
     *     );
     *     $selects = LogAggregator::getSelectsFromRangedColumn('visit_total_actions', $visitTotalActionsRanges, 'log_visit', 'vta');
     *
     *     // summarize another column in the same request
     *     $visitCountVisitsRanges = array(
     *         array(1, 1),
     *         array(2, 20),
     *         array(20)
     *     );
     *     $selects = array_merge(
     *         $selects,
     *         LogAggregator::getSelectsFromRangedColumn('visitor_count_visits', $visitCountVisitsRanges, 'log_visit', 'vcv')
     *     );
     *
     *     // perform the query
     *     $logAggregator = // get the LogAggregator somehow
     *     $query = $logAggregator->queryVisitsByDimension($dimensions = array(), $where = false, $selects);
     *     $tableSummary = $query->fetch();
     *
     *     $numberOfVisitsWithOneAction = $tableSummary['vta0'];
     *     $numberOfVisitsBetweenTwoAnd10 = $tableSummary['vta1'];
     *
     *     $numberOfVisitsWithVisitCountOfOne = $tableSummary['vcv0'];
     *
     * @param string $column The name of a column in `$table` that will be summarized.
     * @param array $ranges The array of ranges over which the data in the table
     *                      will be summarized. For example,
     *                      ```
     *                      array(
     *                          array(1, 1),
     *                          array(2, 2),
     *                          array(3, 8),
     *                          array(8) // everything over 8
     *                      )
     *                      ```
     * @param string $table The unprefixed name of the table whose rows will be summarized.
     * @param string $selectColumnPrefix The prefix to prepend to each SELECT expression. This
     *                                   prefix is used to differentiate different sets of
     *                                   range summarization SELECTs. You can supply different
     *                                   values to this argument to summarize several columns
     *                                   in one query (see above for an example).
     * @param bool $restrictToReturningVisitors Whether to only summarize rows that belong to
     *                                          visits of returning visitors or not. If this
     *                                          argument is true, then the SELECT expressions
     *                                          returned can only be used with the
     *                                          {@link queryVisitsByDimension()} method.
     * @return array An array of SQL SELECT expressions, for example,
     *               ```
     *               array(
     *                   'sum(case when log_visit.visit_total_actions between 0 and 2 then 1 else 0 end) as vta0',
     *                   'sum(case when log_visit.visit_total_actions > 2 then 1 else 0 end) as vta1'
     *               )
     *               ```
     * @api
     */
    public static function getSelectsFromRangedColumn($column, $ranges, $table, $selectColumnPrefix, $restrictToReturningVisitors = false)
    {
        $selects = array();
        $extraCondition = '';

        $tableColumn = $column;
        if (strpos($tableColumn, $table) === false) {
            $tableColumn = "$table.$column";
        }

        if ($restrictToReturningVisitors) {
            // extra condition for the SQL SELECT that makes sure only returning visits are counted
            // when creating the 'days since last visit' report
            $extraCondition = 'and log_visit.visitor_returning = 1';
            $extraSelect    = "sum(case when log_visit.visitor_returning = 0 then 1 else 0 end) "
                            . " as `" . $selectColumnPrefix . 'General_NewVisits' . "`";
            $selects[] = $extraSelect;
        }

        foreach ($ranges as $gap) {
            if (count($gap) == 2) {
                $lowerBound = $gap[0];
                $upperBound = $gap[1];

                $selectAs = "$selectColumnPrefix$lowerBound-$upperBound";

                $selects[] = "sum(case when $tableColumn between $lowerBound and $upperBound $extraCondition" .
                             " then 1 else 0 end) as `$selectAs`";
            } else {
                $lowerBound = $gap[0];

                $selectAs  = $selectColumnPrefix . ($lowerBound + 1) . urlencode('+');
                $selects[] = "sum(case when $tableColumn > $lowerBound $extraCondition then 1 else 0 end) as `$selectAs`";
            }
        }

        return $selects;
    }

    /**
     * Clean up the row data and return values.
     * $lookForThisPrefix can be used to make sure only SOME of the data in $row is used.
     *
     * The array will have one column $columnName
     *
     * @param $row
     * @param $columnName
     * @param bool $lookForThisPrefix A string that identifies which elements of $row to use
     *                                 in the result. Every key of $row that starts with this
     *                                 value is used.
     * @return array
     */
    public static function makeArrayOneColumn($row, $columnName, $lookForThisPrefix = false)
    {
        $cleanRow = array();

        foreach ($row as $label => $count) {
            if (empty($lookForThisPrefix)
                || strpos($label, $lookForThisPrefix) === 0
            ) {
                $cleanLabel = substr($label, strlen($lookForThisPrefix));
                $cleanRow[$cleanLabel] = array($columnName => $count);
            }
        }

        return $cleanRow;
    }

    public function getDb()
    {
        /** @var ArchivingStatus $archivingStatus */
        $archivingStatus = StaticContainer::get(ArchivingStatus::class);
        $archivingLock = $archivingStatus->getCurrentArchivingLock();
        return new ArchivingDbAdapter(Db::getReader(), $archivingLock, $this->logger);
    }
}
