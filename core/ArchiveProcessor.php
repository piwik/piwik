<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Piwik\Archive\DataTableFactory;
use Piwik\ArchiveProcessor\Parameters;
use Piwik\ArchiveProcessor\Rules;
use Piwik\DataAccess\ArchiveWriter;
use Piwik\DataAccess\LogAggregator;
use Piwik\DataTable\Manager;
use Piwik\DataTable\Map;
use Piwik\DataTable\Row;
use Piwik\Segment\SegmentExpression;

/**
 * Used by {@link Piwik\Plugin\Archiver} instances to insert and aggregate archive data.
 *
 * ### See also
 *
 * - **{@link Piwik\Plugin\Archiver}** - to learn how plugins should implement their own analytics
 *                                       aggregation logic.
 * - **{@link Piwik\DataAccess\LogAggregator}** - to learn how plugins can perform data aggregation
 *                                                across Piwik's log tables.
 *
 * ### Examples
 *
 * **Inserting numeric data**
 *
 *     // function in an Archiver descendant
 *     public function aggregateDayReport()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 *
 *         $myFancyMetric = // ... calculate the metric value ...
 *         $archiveProcessor->insertNumericRecord('MyPlugin_myFancyMetric', $myFancyMetric);
 *     }
 *
 * **Inserting serialized DataTables**
 *
 *     // function in an Archiver descendant
 *     public function aggregateDayReport()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 *
 *         $maxRowsInTable = Config::getInstance()->General['datatable_archiving_maximum_rows_standard'];j
 *
 *         $dataTable = // ... build by aggregating visits ...
 *         $serializedData = $dataTable->getSerialized($maxRowsInTable, $maxRowsInSubtable = $maxRowsInTable,
 *                                                     $columnToSortBy = Metrics::INDEX_NB_VISITS);
 *
 *         $archiveProcessor->insertBlobRecords('MyPlugin_myFancyReport', $serializedData);
 *     }
 *
 * **Aggregating archive data**
 *
 *     // function in Archiver descendant
 *     public function aggregateMultipleReports()
 *     {
 *         $archiveProcessor = $this->getProcessor();
 *
 *         // aggregate a metric
 *         $archiveProcessor->aggregateNumericMetrics('MyPlugin_myFancyMetric');
 *         $archiveProcessor->aggregateNumericMetrics('MyPlugin_mySuperFancyMetric', 'max');
 *
 *         // aggregate a report
 *         $archiveProcessor->aggregateDataTableRecords('MyPlugin_myFancyReport');
 *     }
 *
 */
class ArchiveProcessor
{
    /**
     * @var \Piwik\DataAccess\ArchiveWriter
     */
    private $archiveWriter;

    /**
     * @var \Piwik\DataAccess\LogAggregator
     */
    private $logAggregator;

    /**
     * @var Archive
     */
    public $archive = null;

    /**
     * @var Parameters
     */
    private $params;

    /**
     * @var int
     */
    private $numberOfVisits = false;

    private $numberOfVisitsConverted = false;

    public function __construct(Parameters $params, ArchiveWriter $archiveWriter, LogAggregator $logAggregator)
    {
        $this->params = $params;
        $this->logAggregator = $logAggregator;
        $this->archiveWriter = $archiveWriter;
    }

    protected function getArchive()
    {
        if (empty($this->archive)) {
            $subPeriods = $this->params->getSubPeriods();
            $idSites    = $this->params->getIdSites();
            $this->archive = Archive::factory($this->params->getSegment(), $subPeriods, $idSites);

            /**
             * @internal
             */
            Piwik::postEvent('ArchiveProcessor.getArchive', [$this->archive]);
        }

        return $this->archive;
    }

    public function setNumberOfVisits($visits, $visitsConverted)
    {
        $this->numberOfVisits = $visits;
        $this->numberOfVisitsConverted = $visitsConverted;
    }

    /**
     * Returns the {@link Parameters} object containing the site, period and segment we're archiving
     * data for.
     *
     * @return Parameters
     * @api
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Returns a `{@link Piwik\DataAccess\LogAggregator}` instance for the site, period and segment this
     * ArchiveProcessor will insert archive data for.
     *
     * @return LogAggregator
     * @api
     */
    public function getLogAggregator()
    {
        return $this->logAggregator;
    }

    /**
     * Array of (column name before => column name renamed) of the columns for which sum operation is invalid.
     * These columns will be renamed as per this mapping.
     * @var array
     */
    protected static $columnsToRenameAfterAggregation = array(
        Metrics::INDEX_NB_UNIQ_VISITORS => Metrics::INDEX_SUM_DAILY_NB_UNIQ_VISITORS,
        Metrics::INDEX_NB_USERS         => Metrics::INDEX_SUM_DAILY_NB_USERS,
    );

    /**
     * Sums records for every subperiod of the current period and inserts the result as the record
     * for this period.
     *
     * DataTables are summed recursively so subtables will be summed as well.
     *
     * @param string|array $recordNames Name(s) of the report we are aggregating, eg, `'Referrers_type'`.
     * @param int $maximumRowsInDataTableLevelZero Maximum number of rows allowed in the top level DataTable.
     * @param int $maximumRowsInSubDataTable Maximum number of rows allowed in each subtable.
     * @param string $columnToSortByBeforeTruncation The name of the column to sort by before truncating a DataTable.
     * @param array $columnsAggregationOperation Operations for aggregating columns, see {@link Row::sumRow()}.
     * @param array $columnsToRenameAfterAggregation Columns mapped to new names for columns that must change names
     *                                               when summed because they cannot be summed, eg,
     *                                               `array('nb_uniq_visitors' => 'sum_daily_nb_uniq_visitors')`.
     * @param bool|array $countRowsRecursive if set to true, will calculate the recursive rows count for all record names
     *                                       which makes it slower. If you only need it for some records pass an array of
     *                                       recordNames that defines for which ones you need a recursive row count.
     * @return array Returns the row counts of each aggregated report before truncation, eg,
     *
     *                   array(
     *                       'report1' => array('level0' => $report1->getRowsCount,
     *                                          'recursive' => $report1->getRowsCountRecursive()),
     *                       'report2' => array('level0' => $report2->getRowsCount,
     *                                          'recursive' => $report2->getRowsCountRecursive()),
     *                       ...
     *                   )
     * @api
     */
    public function aggregateDataTableRecords($recordNames,
                                              $maximumRowsInDataTableLevelZero = null,
                                              $maximumRowsInSubDataTable = null,
                                              $columnToSortByBeforeTruncation = null,
                                              &$columnsAggregationOperation = null,
                                              $columnsToRenameAfterAggregation = null,
                                              $countRowsRecursive = true,
                                              $preprocessTables = null)
    {
        if (!is_array($recordNames)) {
            $recordNames = array($recordNames);
        }

        $nameToCount = array();
        foreach ($recordNames as $recordName) {
            $latestUsedTableId = Manager::getInstance()->getMostRecentTableId();

            $table = $this->aggregateDataTableRecord($recordName, $columnsAggregationOperation, $columnsToRenameAfterAggregation, $preprocessTables);

            $nameToCount[$recordName]['level0'] = $table->getRowsCount();
            if ($countRowsRecursive === true || (is_array($countRowsRecursive) && in_array($recordName, $countRowsRecursive))) {
                $nameToCount[$recordName]['recursive'] = $table->getRowsCountRecursive();
            }

            $blob = $table->getSerialized($maximumRowsInDataTableLevelZero, $maximumRowsInSubDataTable, $columnToSortByBeforeTruncation);
            Common::destroy($table);
            $this->insertBlobRecord($recordName, $blob);

            unset($blob);
            DataTable\Manager::getInstance()->deleteAll($latestUsedTableId);
        }

        return $nameToCount;
    }

    /**
     * Aggregates one or more metrics for every subperiod of the current period and inserts the results
     * as metrics for the current period.
     *
     * @param array|string $columns Array of metric names to aggregate.
     * @param bool|string $operationToApply The operation to apply to the metric. Either `'sum'`, `'max'` or `'min'`.
     * @return array|int Returns the array of aggregate values. If only one metric was aggregated,
     *                   the aggregate value will be returned as is, not in an array.
     *                   For example, if `array('nb_visits', 'nb_hits')` is supplied for `$columns`,
     *
     *                       array(
     *                           'nb_visits' => 3040,
     *                           'nb_hits' => 405
     *                       )
     *
     *                   could be returned. If `array('nb_visits')` or `'nb_visits'` is used for `$columns`,
     *                   then `3040` would be returned.
     * @api
     */
    public function aggregateNumericMetrics($columns, $operationToApply = false)
    {
        $metrics = $this->getAggregatedNumericMetrics($columns, $operationToApply);

        foreach ($metrics as $column => $value) {
            $this->insertNumericRecord($column, $value);
        }
        // if asked for only one field to sum
        if (count($metrics) === 1) {
            return reset($metrics);
        }

        // returns the array of records once summed
        return $metrics;
    }

    public function getNumberOfVisits()
    {
        if ($this->numberOfVisits === false) {
            throw new Exception("visits should have been set here");
        }
        return $this->numberOfVisits;
    }

    public function getNumberOfVisitsConverted()
    {
        return $this->numberOfVisitsConverted;
    }

    /**
     * Caches multiple numeric records in the archive for this processor's site, period
     * and segment.
     *
     * @param array $numericRecords A name-value mapping of numeric values that should be
     *                              archived, eg,
     *
     *                                  array('Referrers_distinctKeywords' => 23, 'Referrers_distinctCampaigns' => 234)
     * @api
     */
    public function insertNumericRecords($numericRecords)
    {
        foreach ($numericRecords as $name => $value) {
            $this->insertNumericRecord($name, $value);
        }
    }

    /**
     * Caches a single numeric record in the archive for this processor's site, period and
     * segment.
     *
     * Numeric values are not inserted if they equal `0`.
     *
     * @param string $name The name of the numeric value, eg, `'Referrers_distinctKeywords'`.
     * @param float $value The numeric value.
     * @api
     */
    public function insertNumericRecord($name, $value)
    {
        $value = round($value, 2);
        $value = Common::forceDotAsSeparatorForDecimalPoint($value);

        $this->archiveWriter->insertRecord($name, $value);
    }

    /**
     * Caches one or more blob records in the archive for this processor's site, period
     * and segment.
     *
     * @param string $name The name of the record, eg, 'Referrers_type'.
     * @param string|array $values A blob string or an array of blob strings. If an array
     *                             is used, the first element in the array will be inserted
     *                             with the `$name` name. The others will be inserted with
     *                             `$name . '_' . $index` as the record name (where $index is
     *                             the index of the blob record in `$values`).
     * @api
     */
    public function insertBlobRecord($name, $values)
    {
        $this->archiveWriter->insertBlobRecord($name, $values);
    }

    /**
     * This method selects all DataTables that have the name $name over the period.
     * All these DataTables are then added together, and the resulting DataTable is returned.
     *
     * @param string $name
     * @param array $columnsAggregationOperation Operations for aggregating columns, @see Row::sumRow()
     * @param array $columnsToRenameAfterAggregation columns in the array (old name, new name) to be renamed as the sum operation is not valid on them (eg. nb_uniq_visitors->sum_daily_nb_uniq_visitors)
     * @return DataTable
     */
    protected function aggregateDataTableRecord($name, $columnsAggregationOperation = null, $columnsToRenameAfterAggregation = null, $preprocessTables = null)
    {
        try {
            ErrorHandler::pushFatalErrorBreadcrumb(__CLASS__, ['name' => $name]);

            // By default we shall aggregate all sub-tables.
            $dataTable = $this->getArchive()->getDataTableExpanded($name, $idSubTable = null, $depth = null, $addMetadataSubtableId = false);

            if ($preprocessTables
                && $dataTable instanceof Map // sanity check
            ) {
                $preprocessTables($dataTable);
            }

            $columnsRenamed = false;

            if ($dataTable instanceof Map) {
                $columnsRenamed = true;
                // see https://github.com/piwik/piwik/issues/4377
                $self = $this;
                $dataTable->filter(function ($table) use ($self, $columnsToRenameAfterAggregation) {

                    if ($self->areColumnsNotAlreadyRenamed($table)) {
                        /**
                         * This makes archiving and range dates a lot faster. Imagine we archive a week, then we will
                         * rename all columns of each 7 day archives. Afterwards we know the columns will be replaced in a
                         * week archive. When generating month archives, which uses mostly week archives, we do not have
                         * to replace those columns for the week archives again since we can be sure they were already
                         * replaced. Same when aggregating year and range archives. This can save up 10% or more when
                         * aggregating Month, Year and Range archives.
                         */
                        $self->renameColumnsAfterAggregation($table, $columnsToRenameAfterAggregation);
                    }
                });
            }

            $dataTable = $this->getAggregatedDataTableMap($dataTable, $columnsAggregationOperation);

            if (!$columnsRenamed) {
                $this->renameColumnsAfterAggregation($dataTable, $columnsToRenameAfterAggregation);
            }
        } finally {
            ErrorHandler::popFatalErrorBreadcrumb();
        }

        return $dataTable;
    }

    /**
     * Note: public only for use in closure in PHP 5.3.
     *
     * @param $table
     * @return \Piwik\Period
     */
    public function areColumnsNotAlreadyRenamed($table)
    {
        $period = $table->getMetadata(DataTableFactory::TABLE_METADATA_PERIOD_INDEX);

        return !$period || $period->getLabel() === 'day';
    }

    protected function getOperationForColumns($columns, $defaultOperation)
    {
        $operationForColumn = array();
        foreach ($columns as $name) {
            $operation = $defaultOperation;
            if (empty($operation)) {
                $operation = $this->guessOperationForColumn($name);
            }
            $operationForColumn[$name] = $operation;
        }
        return $operationForColumn;
    }

    protected function enrichWithUniqueVisitorsMetric(Row $row)
    {
        if ($row->getColumn('nb_uniq_visitors') === false
            && $row->getColumn('nb_users') === false
        ) {
            return;
        }

        $periodLabel = $this->getParams()->getPeriod()->getLabel();

        if (!SettingsPiwik::isUniqueVisitorsEnabled($periodLabel)) {
            $row->deleteColumn('nb_uniq_visitors');
            $row->deleteColumn('nb_users');
            return;
        }

        $sites = $this->getIdSitesToComputeNbUniques();

        if (count($sites) > 1 && Rules::shouldSkipUniqueVisitorsCalculationForMultipleSites()) {
            if ($periodLabel != 'day') {
                // for day we still keep the aggregated metric but for other periods we remove it as it becomes to
                // inaccurate
                $row->deleteColumn('nb_uniq_visitors');
                $row->deleteColumn('nb_users');
            }
            return;
        }

        if (empty($sites)) {
            // a plugin disabled running below query by removing all sites.
            $row->deleteColumn('nb_uniq_visitors');
            $row->deleteColumn('nb_users');
            return;
        }

        if (count($sites) === 1) {
            $uniqueVisitorsMetric = Metrics::INDEX_NB_UNIQ_VISITORS;
        } else {
            if (!SettingsPiwik::isSameFingerprintAcrossWebsites()) {
                throw new Exception("Processing unique visitors across websites is enabled for this instance,
                            but to process this metric you must first set enable_fingerprinting_across_websites=1
                            in the config file, under the [Tracker] section.");
            }
            $uniqueVisitorsMetric = Metrics::INDEX_NB_UNIQ_FINGERPRINTS;
        }

        $metrics = array(
            Metrics::INDEX_NB_USERS,
            $uniqueVisitorsMetric
        );

        $uniques = $this->computeNbUniques($metrics, $sites);

        // see edge case as described in https://github.com/piwik/piwik/issues/9357 where uniq_visitors might be higher
        // than visits because we archive / process it after nb_visits. Between archiving nb_visits and nb_uniq_visitors
        // there could have been a new visit leading to a higher nb_unique_visitors than nb_visits which is not possible
        // by definition. In this case we simply use the visits metric instead of unique visitors metric.
        $visits = $row->getColumn('nb_visits');
        if ($visits !== false && $uniques[$uniqueVisitorsMetric] !== false) {
            $uniques[$uniqueVisitorsMetric] = min($uniques[$uniqueVisitorsMetric], $visits);
        }

        $row->setColumn('nb_uniq_visitors', $uniques[$uniqueVisitorsMetric]);
        $row->setColumn('nb_users', $uniques[Metrics::INDEX_NB_USERS]);
    }

    protected function guessOperationForColumn($column)
    {
        if (strpos($column, 'max_') === 0) {
            return 'max';
        }
        if (strpos($column, 'min_') === 0) {
            return 'min';
        }
        return 'sum';
    }

    private function getIdSitesToComputeNbUniques()
    {
        $params = $this->getParams();
        $sites = array($params->getSite()->getId());

        /**
         * Triggered to change which site ids should be looked at when processing unique visitors and users.
         *
         * @param array &$idSites An array with one idSite. This site is being archived currently. To cancel the query
         *                        you can change this value to an empty array. To include other sites in the query you
         *                        can add more idSites to this list of idSites.
         * @param Period $period  The period that is being requested to be archived.
         * @param Segment $segment The segment that is request to be archived.
         */
        Piwik::postEvent('ArchiveProcessor.ComputeNbUniques.getIdSites', array(&$sites, $params->getPeriod(), $params->getSegment()));

        return $sites;
    }

    /**
     * Processes number of unique visitors for the given period
     *
     * This is the only Period metric (ie. week/month/year/range) that we process from the logs directly,
     * since unique visitors cannot be summed like other metrics.
     *
     * @param array $metrics Metrics Ids for which to aggregates count of values
     * @param int[] $sites  A list of idSites that should be included
     * @return array|null An array of metrics, where the key is metricid and the value is the metric value or null if
     *                      the query was cancelled and not executed.
     */
    protected function computeNbUniques($metrics, $sites)
    {
        $logAggregator = $this->getLogAggregator();
        $sitesBackup = $logAggregator->getSites();

        $logAggregator->setSites($sites);
        try {
            $query = $logAggregator->queryVisitsByDimension(array(), false, array(), $metrics);
        } finally {
            $logAggregator->setSites($sitesBackup);
        }
        $data = $query->fetch();
        return $data;
    }

    /**
     * If the DataTable is a Map, sums all DataTable in the map and return the DataTable.
     *
     *
     * @param $data DataTable|DataTable\Map
     * @param $columnsToRenameAfterAggregation array
     * @return DataTable
     */
    protected function getAggregatedDataTableMap($data, $columnsAggregationOperation)
    {
        $table = new DataTable();

        if (!empty($columnsAggregationOperation)) {
            $table->setMetadata(DataTable::COLUMN_AGGREGATION_OPS_METADATA_NAME, $columnsAggregationOperation);
        }

        if ($data instanceof DataTable\Map) {
            // as $date => $tableToSum
            $this->aggregatedDataTableMapsAsOne($data, $table);
        } else {
            $table->addDataTable($data);
        }

        return $table;
    }

    /**
     * Aggregates the DataTable\Map into the destination $aggregated
     * @param $map
     * @param $aggregated
     */
    protected function aggregatedDataTableMapsAsOne(Map $map, DataTable $aggregated)
    {
        foreach ($map->getDataTables() as $tableToAggregate) {
            if ($tableToAggregate instanceof Map) {
                $this->aggregatedDataTableMapsAsOne($tableToAggregate, $aggregated);
            } else {
                $aggregated->addDataTable($tableToAggregate);
            }
        }
    }

    /**
     * Note: public only for use in closure in PHP 5.3.
     */
    public function renameColumnsAfterAggregation(DataTable $table, $columnsToRenameAfterAggregation = null)
    {
        // Rename columns after aggregation
        if (is_null($columnsToRenameAfterAggregation)) {
            $columnsToRenameAfterAggregation = self::$columnsToRenameAfterAggregation;
        }

        if (empty($columnsToRenameAfterAggregation)) {
            return;
        }
        
        foreach ($table->getRows() as $row) {
            foreach ($columnsToRenameAfterAggregation as $oldName => $newName) {
                $row->renameColumn($oldName, $newName);
            }

            $subTable = $row->getSubtable();
            if ($subTable) {
                $this->renameColumnsAfterAggregation($subTable, $columnsToRenameAfterAggregation);
            }
        }
    }

    protected function getAggregatedNumericMetrics($columns, $operationToApply)
    {
        if (!is_array($columns)) {
            $columns = array($columns);
        }

        $operationForColumn = $this->getOperationForColumns($columns, $operationToApply);

        $dataTable = $this->getArchive()->getDataTableFromNumeric($columns);

        $results = $this->getAggregatedDataTableMap($dataTable, $operationForColumn);
        if ($results->getRowsCount() > 1) {
            throw new Exception("A DataTable is an unexpected state:" . var_export($results, true));
        }

        $rowMetrics = $results->getFirstRow();
        if ($rowMetrics === false) {
            $rowMetrics = new Row;
        }
        $this->enrichWithUniqueVisitorsMetric($rowMetrics);
        $this->renameColumnsAfterAggregation($results, self::$columnsToRenameAfterAggregation);

        $metrics = $rowMetrics->getColumns();

        foreach ($columns as $name) {
            if (!isset($metrics[$name])) {
                $metrics[$name] = 0;
            }
        }

        return $metrics;
    }

    /**
     * Initiate archiving for a plugin during an ongoing archiving. The plugin can be another
     * plugin or the same plugin.
     *
     * This method should be called during archiving when one plugin uses the report of another
     * plugin with a segment. It will ensure reports for that segment & plugin will be archived
     * without initiating archiving for every plugin with that segment (which would be a performance
     * killer).
     *
     * @param string $plugin
     * @param string $segment
     */
    public function processDependentArchive($plugin, $segment)
    {
        $params = $this->getParams();
        if (!$params->isRootArchiveRequest()) { // prevent all recursion
            return;
        }

        $idSites = [$params->getSite()->getId()];

        $newSegment = Segment::combine($params->getSegment()->getString(), SegmentExpression::AND_DELIMITER, $segment);
        if ($newSegment === $segment && $params->getRequestedPlugin() === $plugin) { // being processed now
            return;
        }

        $newSegment = new Segment($newSegment, $idSites, $params->getDateStart(), $params->getDateEnd());
        if (ArchiveProcessor\Rules::isSegmentPreProcessed($idSites, $newSegment)) {
            // will be processed anyway
            return;
        }

        $parameters = new ArchiveProcessor\Parameters($params->getSite(), $params->getPeriod(), $newSegment);
        $parameters->onlyArchiveRequestedPlugin();
        $parameters->setIsRootArchiveRequest(false);

        $archiveLoader = new ArchiveProcessor\Loader($parameters);
        $archiveLoader->prepareArchive($plugin);
    }

    public function getArchiveWriter()
    {
        return $this->archiveWriter;
    }
}
