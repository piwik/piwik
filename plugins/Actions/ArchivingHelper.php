<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Actions;

use PDOStatement;
use Piwik\Config;
use Piwik\DataTable\Row\DataTableSummaryRow;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\Metrics as PiwikMetrics;
use Piwik\Piwik;
use Piwik\RankingQuery;
use Piwik\Tracker\Action;
use Piwik\Tracker\PageUrl;
use Zend_Db_Statement;

/**
 * This static class provides:
 * - logic to parse/cleanup Action names,
 * - logic to efficiently process aggregate the array data during Archiving
 *
 */
class ArchivingHelper
{
    const OTHERS_ROW_KEY = '';

    /**
     * Ideally this should use the DataArray object instead of custom data structure
     *
     * @param Zend_Db_Statement|PDOStatement $query
     * @param string|bool $fieldQueried
     * @param array $actionsTablesByType
     * @return int
     */
    public static function updateActionsTableWithRowQuery($query, $fieldQueried, & $actionsTablesByType, $metricsConfig)
    {
        $rowsProcessed = 0;
        while ($row = $query->fetch()) {
            if (empty($row['idaction'])) {
                $row['type'] = ($fieldQueried == 'idaction_url' ? Action::TYPE_PAGE_URL : Action::TYPE_PAGE_TITLE);
                // This will be replaced with 'X not defined' later
                $row['name'] = '';
                // Yes, this is kind of a hack, so we don't mix 'page url not defined' with 'page title not defined' etc.
                $row['idaction'] = -$row['type'];
            }

            if ($row['type'] != Action::TYPE_SITE_SEARCH) {
                unset($row[PiwikMetrics::INDEX_SITE_SEARCH_HAS_NO_RESULT]);
            }

            if (in_array($row['type'], array(Action::TYPE_CONTENT, Action::TYPE_EVENT, Action::TYPE_EVENT_NAME, Action::TYPE_CONTENT_PIECE, Action::TYPE_CONTENT_TARGET))) {
                continue;
            }

            $hasRowName = !empty($row['name']) && $row['name'] != RankingQuery::LABEL_SUMMARY_ROW;

            // This will appear as <url /> in the API, which is actually very important to keep
            // eg. When there's at least one row in a report that does not have a URL, not having this <url/> would break HTML/PDF reports.
            $url = '';
            $pageTitlePath = null;
            if ($row['type'] == Action::TYPE_SITE_SEARCH
                || $row['type'] == Action::TYPE_PAGE_TITLE
            ) {
                $url = null;
                if ($hasRowName) {
                    $pageTitlePath = $row['name'];
                }
            } elseif ($hasRowName) {
                $url = PageUrl::reconstructNormalizedUrl((string)$row['name'], $row['url_prefix']);
            }

            if (isset($row['name'])
                && isset($row['type'])
            ) {
                $actionName = $row['name'];
                $actionType = $row['type'];
                $urlPrefix = $row['url_prefix'];
                $idaction = $row['idaction'];

                // in some unknown case, the type field is NULL, as reported in #1082 - we ignore this page view
                if (empty($actionType)) {
                    if ($idaction != DataTable::LABEL_SUMMARY_ROW) {
                        self::setCachedActionRow($idaction, $actionType, false);
                    }
                    continue;
                }

                $actionRow = self::getActionRow($actionName, $actionType, $urlPrefix, $actionsTablesByType);

                self::setCachedActionRow($idaction, $actionType, $actionRow);
            } else {
                $actionRow = self::getCachedActionRow($row['idaction'], $row['type']);

                // Action processed as "to skip" for some reasons
                if ($actionRow === false) {
                    continue;
                }
            }

            if (is_null($actionRow)) {
                continue;
            }

            // Here we do ensure that, the Metadata URL set for a given row, is the one from the Pageview with the most hits.
            // This is to ensure that when, different URLs are loaded with the same page name.
            // For example http://piwik.org and http://id.piwik.org are reported in Piwik > Actions > Pages with /index
            // But, we must make sure http://piwik.org is used to link & for transitions
            // Note: this code is partly duplicated from Row->sumRowMetadata()
            if (!is_null($url)
                && !$actionRow->isSummaryRow()
            ) {
                if (($existingUrl = $actionRow->getMetadata('url')) !== false) {
                    if (!empty($row[PiwikMetrics::INDEX_PAGE_NB_HITS])
                        && $row[PiwikMetrics::INDEX_PAGE_NB_HITS] > $actionRow->maxVisitsSummed
                    ) {
                        $actionRow->setMetadata('url', $url);
                        $actionRow->maxVisitsSummed = $row[PiwikMetrics::INDEX_PAGE_NB_HITS];
                    }
                } else {
                    $actionRow->setMetadata('url', $url);
                    $actionRow->maxVisitsSummed = !empty($row[PiwikMetrics::INDEX_PAGE_NB_HITS]) ? $row[PiwikMetrics::INDEX_PAGE_NB_HITS] : 0;
                }
            }

            if ($pageTitlePath !== null
                && !$actionRow->isSummaryRow()
            ) {
                $actionRow->setMetadata('page_title_path', $pageTitlePath);
            }

            if ($row['type'] != Action::TYPE_PAGE_URL
                && $row['type'] != Action::TYPE_PAGE_TITLE
            ) {
                // only keep performance metrics when they're used (i.e. for URLs and page titles)
                if (array_key_exists(PiwikMetrics::INDEX_PAGE_SUM_TIME_GENERATION, $row)) {
                    unset($row[PiwikMetrics::INDEX_PAGE_SUM_TIME_GENERATION]);
                }
                if (array_key_exists(PiwikMetrics::INDEX_PAGE_NB_HITS_WITH_TIME_GENERATION, $row)) {
                    unset($row[PiwikMetrics::INDEX_PAGE_NB_HITS_WITH_TIME_GENERATION]);
                }
                if (array_key_exists(PiwikMetrics::INDEX_PAGE_MIN_TIME_GENERATION, $row)) {
                    unset($row[PiwikMetrics::INDEX_PAGE_MIN_TIME_GENERATION]);
                }
                if (array_key_exists(PiwikMetrics::INDEX_PAGE_MAX_TIME_GENERATION, $row)) {
                    unset($row[PiwikMetrics::INDEX_PAGE_MAX_TIME_GENERATION]);
                }
            }

            unset($row['name']);
            unset($row['type']);
            unset($row['idaction']);
            unset($row['url_prefix']);

            foreach ($row as $name => $value) {
                // in some edge cases, we have twice the same action name with 2 different idaction
                // - this happens when 2 visitors visit the same new page at the same time, and 2 actions get recorded for the same name
                // - this could also happen when 2 URLs end up having the same label (eg. 2 subdomains get aggregated to the "/index" page name)
                if (($alreadyValue = $actionRow->getColumn($name)) !== false) {
                    $newValue = self::getColumnValuesMerged($name, $alreadyValue, $value, $metricsConfig);
                    $actionRow->setColumn($name, $newValue);
                } else {
                    $actionRow->addColumn($name, $value);
                }
            }

            // if the exit_action was not recorded properly in the log_link_visit_action
            // there would be an error message when getting the nb_hits column
            // we must fake the record and add the columns
            if ($actionRow->getColumn(PiwikMetrics::INDEX_PAGE_NB_HITS) === false) {
                // to test this code: delete the entries in log_link_action_visit for
                //  a given exit_idaction_url
                foreach (self::getDefaultRow()->getColumns() as $name => $value) {
                    $actionRow->addColumn($name, $value);
                }
            }
            $rowsProcessed++;
        }

        // just to make sure php copies the last $actionRow in the $parentTable array
        $actionRow =& $actionsTablesByType;
        return $rowsProcessed;
    }

    public static function removeEmptyColumns($dataTable)
    {
        // Delete all columns that have a value of zero
        $dataTable->filter('ColumnDelete', array(
                                                $columnsToRemove = array(PiwikMetrics::INDEX_PAGE_IS_FOLLOWING_SITE_SEARCH_NB_HITS),
                                                $columnsToKeep = array(),
                                                $deleteIfZeroOnly = true
                                           ));
    }

    /**
     * For rows which have subtables (eg. directories with sub pages),
     * deletes columns which don't make sense when all values of sub pages are summed.
     *
     * @param $dataTable DataTable
     */
    public static function deleteInvalidSummedColumnsFromDataTable($dataTable)
    {
        foreach ($dataTable->getRows() as $id => $row) {
            if (($idSubtable = $row->getIdSubDataTable()) !== null
                || $id === DataTable::ID_SUMMARY_ROW
            ) {
                $subTable = $row->getSubtable();
                if ($subTable) {
                    self::deleteInvalidSummedColumnsFromDataTable($subTable);
                }

                if ($row instanceof DataTableSummaryRow) {
                    $row->recalculate();
                }

                foreach (Metrics::$columnsToDeleteAfterAggregation as $name) {
                    $row->deleteColumn($name);
                }
            }
        }

        // And this as well
        ArchivingHelper::removeEmptyColumns($dataTable);
    }

    /**
     * Returns the limit to use with RankingQuery for this plugin.
     *
     * @return int
     */
    public static function getRankingQueryLimit()
    {
        $configGeneral = Config::getInstance()->General;
        $configLimit = $configGeneral['archiving_ranking_query_row_limit'];
        $limit = $configLimit == 0 ? 0 : max(
            $configLimit,
            $configGeneral['datatable_archiving_maximum_rows_actions'],
            $configGeneral['datatable_archiving_maximum_rows_subtable_actions']
        );

        // FIXME: This is a quick fix for #3482. The actual cause of the bug is that
        // the site search & performance metrics additions to
        // ArchivingHelper::updateActionsTableWithRowQuery expect every
        // row to have 'type' data, but not all of the SQL queries that are run w/o
        // ranking query join on the log_action table and thus do not select the
        // log_action.type column.
        //
        // NOTES: Archiving logic can be generalized as follows:
        // 0) Do SQL query over log_link_visit_action & join on log_action to select
        //    some metrics (like visits, hits, etc.)
        // 1) For each row, cache the action row & metrics. (This is done by
        //    updateActionsTableWithRowQuery for result set rows that have
        //    name & type columns.)
        // 2) Do other SQL queries for metrics we can't put in the first query (like
        //    entry visits, exit vists, etc.) w/o joining log_action.
        // 3) For each row, find the cached row by idaction & add the new metrics to
        //    it. (This is done by updateActionsTableWithRowQuery for result set rows
        //    that DO NOT have name & type columns.)
        //
        // The site search & performance metrics additions expect a 'type' all the time
        // which breaks the original pre-rankingquery logic. Ranking query requires a
        // join, so the bug is only seen when ranking query is disabled.
        if ($limit === 0) {
            $limit = 100000;
        }
        return $limit;

    }

    /**
     * @param $columnName
     * @param $alreadyValue
     * @param $value
     * @return mixed
     */
    private static function getColumnValuesMerged($columnName, $alreadyValue, $value, $metricsConfig)
    {
        if (array_key_exists($columnName, $metricsConfig)) {
            $config = $metricsConfig[$columnName];

            if (!empty($config['aggregation'])) {

                if ($config['aggregation'] == 'min') {
                    if (empty($alreadyValue)) {
                        $newValue = $value;
                    } else if (empty($value)) {
                        $newValue = $alreadyValue;
                    } else {
                        $newValue = min($alreadyValue, $value);
                    }
                    return $newValue;
                }
                if ($config['aggregation'] == 'max') {
                    $newValue = max($alreadyValue, $value);
                    return $newValue;
                }
            }
        }

        $newValue = $alreadyValue + $value;
        return $newValue;
    }

    public static $maximumRowsInDataTableLevelZero;
    public static $maximumRowsInSubDataTable;
    public static $maximumRowsInDataTableSiteSearch;
    public static $columnToSortByBeforeTruncation;

    protected static $actionUrlCategoryDelimiter = null;
    protected static $actionTitleCategoryDelimiter = null;
    protected static $defaultActionName = null;
    protected static $defaultActionNameWhenNotDefined = null;
    protected static $defaultActionUrlWhenNotDefined = null;
    public static $actionsIncludeHostInReport = null;

    public static function reloadConfig()
    {
        // for BC, we read the old style delimiter first (see #1067)
        $actionDelimiter = @Config::getInstance()->General['action_category_delimiter'];
        if (empty($actionDelimiter)) {
            self::$actionUrlCategoryDelimiter = Config::getInstance()->General['action_url_category_delimiter'];
            self::$actionTitleCategoryDelimiter = Config::getInstance()->General['action_title_category_delimiter'];
        } else {
            self::$actionUrlCategoryDelimiter = self::$actionTitleCategoryDelimiter = $actionDelimiter;
        }

        self::$defaultActionName = Config::getInstance()->General['action_default_name'];
        self::$columnToSortByBeforeTruncation = PiwikMetrics::INDEX_NB_VISITS;
        self::$maximumRowsInDataTableLevelZero = Config::getInstance()->General['datatable_archiving_maximum_rows_actions'];
        self::$maximumRowsInSubDataTable = Config::getInstance()->General['datatable_archiving_maximum_rows_subtable_actions'];
        self::$maximumRowsInDataTableSiteSearch = Config::getInstance()->General['datatable_archiving_maximum_rows_site_search'];

        DataTable::setMaximumDepthLevelAllowedAtLeast(self::getSubCategoryLevelLimit() + 1);
    }

    public static function setSiteDependentConfig($idSite)
    {
        $settings = new MeasurableSettings($idSite);
        self::$actionsIncludeHostInReport = $settings->includeHostInPageUrlsReport->getValue();
    }

    /**
     * The default row is used when archiving, if data is inconsistent in the DB,
     * there could be pages that have exit/entry hits, but don't yet
     * have a record in the table (or the record was truncated).
     *
     * @return Row
     */
    private static function getDefaultRow()
    {
        static $row = false;
        if ($row === false) {
            // This row is used in the case where an action is know as an exit_action
            // but this action was not properly recorded when it was hit in the first place
            // so we add this fake row information to make sure there is a nb_hits, etc. column for every action
            $row = new Row(array(
                                Row::COLUMNS => array(
                                    PiwikMetrics::INDEX_NB_VISITS        => 1,
                                    PiwikMetrics::INDEX_NB_UNIQ_VISITORS => 1,
                                    PiwikMetrics::INDEX_PAGE_NB_HITS     => 1,
                                )));
        }
        return $row;
    }

    /**
     * Given a page name and type, builds a recursive datatable where
     * each level of the tree is a category, based on the page name split by a delimiter (slash / by default)
     *
     * @param string $actionName
     * @param int $actionType
     * @param int $urlPrefix
     * @param array $actionsTablesByType
     * @return DataTable\Row
     */
    public static function getActionRow($actionName, $actionType, $urlPrefix, &$actionsTablesByType)
    {
        // we work on the root table of the given TYPE (either ACTION_URL or DOWNLOAD or OUTLINK etc.)
        /* @var DataTable $currentTable */
        $currentTable =& $actionsTablesByType[$actionType];

        if (is_null($currentTable)) {
            throw new \Exception("Action table for type '$actionType' was not found during Actions archiving.");
        }

        // check for ranking query cut-off
        if ($actionName == RankingQuery::LABEL_SUMMARY_ROW) {
            $summaryRow = $currentTable->getRowFromId(DataTable::ID_SUMMARY_ROW);
            if ($summaryRow === false) {
                $summaryRow = $currentTable->addSummaryRow(self::createSummaryRow());
            }
            return $summaryRow;
        }

        // go to the level of the subcategory
        $actionExplodedNames = self::getActionExplodedNames($actionName, $actionType, $urlPrefix);
        list($row, $level) = $currentTable->walkPath(
            $actionExplodedNames, self::getDefaultRowColumns(), self::$maximumRowsInSubDataTable);

        return $row;
    }

    /**
     * Returns the configured sub-category level limit.
     *
     * @return int
     */
    public static function getSubCategoryLevelLimit()
    {
        return Config::getInstance()->General['action_category_level_limit'];
    }

    /**
     * Returns default label for the action type
     *
     * @param $type
     * @return string
     */
    public static function getUnknownActionName($type)
    {
        if (empty(self::$defaultActionNameWhenNotDefined)) {
            self::$defaultActionNameWhenNotDefined = Piwik::translate('General_NotDefined', Piwik::translate('Actions_ColumnPageName'));
            self::$defaultActionUrlWhenNotDefined = Piwik::translate('General_NotDefined', Piwik::translate('Actions_ColumnPageURL'));
        }
        if ($type == Action::TYPE_PAGE_TITLE) {
            return self::$defaultActionNameWhenNotDefined;
        }
        return self::$defaultActionUrlWhenNotDefined;
    }

    /**
     * Explodes action name into an array of elements.
     *
     * NOTE: before calling this function make sure ArchivingHelper::reloadConfig(); is called
     *
     * for downloads:
     *  we explode link http://piwik.org/some/path/piwik.zip into an array( 'piwik.org', '/some/path/piwik.zip' );
     *
     * for outlinks:
     *  we explode link http://dev.piwik.org/some/path into an array( 'dev.piwik.org', '/some/path' );
     *
     * for action urls:
     *  we explode link http://piwik.org/some/path into an array( 'some', 'path' );
     *
     * for action names:
     *   we explode name 'Piwik / Category 1 / Category 2' into an array('Matomo', 'Category 1', 'Category 2');
     *
     * @param string $name action name
     * @param int $type action type
     * @param int $urlPrefix url prefix (only used for TYPE_PAGE_URL)
     * @return array of exploded elements from $name
     */
    public static function getActionExplodedNames($name, $type, $urlPrefix = null)
    {
        // Site Search does not split Search keywords
        if ($type == Action::TYPE_SITE_SEARCH) {
            return array($name);
        }

        $name = str_replace("\n", "", $name);

        if ($type == Action::TYPE_PAGE_TITLE && self::$actionTitleCategoryDelimiter === '') {
            if ($name === '' || $name === false || $name === null || trim($name) === '') {
                $name = self::getUnknownActionName($type);
            }
            return array(' ' . trim($name));
        }

        $name = self::parseNameFromPageUrl($name, $type, $urlPrefix);

        // outlinks and downloads
        if (is_array($name)) {
            return $name;
        }

        $split = self::splitNameByDelimiter($name, $type);

        if (empty($split)) {
            $defaultName = self::getUnknownActionName($type);
            return array(trim($defaultName));
        }

        $lastPageName = end($split);
        // we are careful to prefix the page URL / name with some value
        // so that if a page has the same name as a category
        // we don't merge both entries
        if ($type != Action::TYPE_PAGE_TITLE) {
            $lastPageName = '/' . $lastPageName;
        } else {
            $lastPageName = ' ' . $lastPageName;
        }
        $split[count($split) - 1] = $lastPageName;
        return array_values($split);
    }

    /**
     * Gets the key for the cache of action rows from an action ID and type.
     *
     * @param int $idAction
     * @param int $actionType
     * @return string|int
     */
    private static function getCachedActionRowKey($idAction, $actionType)
    {
        return $idAction == RankingQuery::LABEL_SUMMARY_ROW
            ? $actionType . '_others'
            : $idAction;
    }

    /**
     * Static cache to store Rows during processing
     */
    protected static $cacheParsedAction = array();

    public static function clearActionsCache()
    {
        self::$cacheParsedAction = array();
    }

    /**
     * Get cached action row by id & type. If $idAction is set to -1, the 'Others' row
     * for the specific action type will be returned.
     *
     * @param int $idAction
     * @param int $actionType
     * @return Row|false
     */
    private static function getCachedActionRow($idAction, $actionType)
    {
        $cacheLabel = self::getCachedActionRowKey($idAction, $actionType);

        if (!isset(self::$cacheParsedAction[$cacheLabel])) {
            // This can happen when
            // - We select an entry page ID that was only seen yesterday, so wasn't selected in the first query
            // - We count time spent on a page, when this page was only seen yesterday
            return false;
        }

        return self::$cacheParsedAction[$cacheLabel];
    }

    /**
     * Set cached action row for an id & type.
     *
     * @param int $idAction
     * @param int $actionType
     * @param \Piwik\DataTable\Row
     */
    private static function setCachedActionRow($idAction, $actionType, $actionRow)
    {
        $cacheLabel = self::getCachedActionRowKey($idAction, $actionType);
        self::$cacheParsedAction[$cacheLabel] = $actionRow;
    }

    /**
     * Returns the default columns for a row in an Actions DataTable.
     *
     * @return array
     */
    private static function getDefaultRowColumns()
    {
        return array(PiwikMetrics::INDEX_NB_VISITS           => 0,
                     PiwikMetrics::INDEX_NB_UNIQ_VISITORS    => 0,
                     PiwikMetrics::INDEX_PAGE_NB_HITS        => 0,
                     PiwikMetrics::INDEX_PAGE_SUM_TIME_SPENT => 0);
    }

    /**
     * Creates a summary row for an Actions DataTable.
     *
     * @return Row
     */
    private static function createSummaryRow()
    {
        $summaryRow = new Row(array(
                            Row::COLUMNS =>
                                array('label' => DataTable::LABEL_SUMMARY_ROW) + self::getDefaultRowColumns()
                       ));
        $summaryRow->setIsSummaryRow(); // this should be set in DataTable::addSummaryRow(), but we set it here as well to be safe
        return $summaryRow;
    }

    private static function splitNameByDelimiter($name, $type)
    {
        if(is_array($name)) {
            return $name;
        }
        if ($type == Action::TYPE_PAGE_TITLE) {
            $categoryDelimiter = self::$actionTitleCategoryDelimiter;
        } else {
            $categoryDelimiter = self::$actionUrlCategoryDelimiter;
        }

        if (empty($categoryDelimiter)) {
            return array(trim($name));
        }

        $split = explode($categoryDelimiter, $name, self::getSubCategoryLevelLimit());

        // trim every category and remove empty categories
        $split = array_map('trim', $split);
        $split = array_filter($split, 'strlen');

        // forces array key to start at 0
        $split = array_values($split);

        return $split;
    }

    private static function parseNameFromPageUrl($name, $type, $urlPrefix)
    {
        $urlRegexAfterDomain = '([^/]+)[/]?([^#]*)[#]?(.*)';
        if ($urlPrefix === null) {
            // match url with protocol (used for outlinks / downloads)
            $urlRegex = '@^http[s]?://' . $urlRegexAfterDomain . '$@i';
        } else {
            // the name is a url that does not contain protocol and www anymore
            // we know that normalization has been done on db level because $urlPrefix is set
            $urlRegex = '@^' . $urlRegexAfterDomain . '$@i';
        }

        $matches = array();
        preg_match($urlRegex, $name, $matches);
        if (!count($matches)) {
            return $name;
        }
        $urlHost = $matches[1];
        $urlPath = $matches[2];
        $urlFragment = $matches[3];

        if (in_array($type, array(Action::TYPE_DOWNLOAD, Action::TYPE_OUTLINK))) {
            $path = '/' . trim($urlPath);
            if (!empty($urlFragment)) {
                $path .= '#' . $urlFragment;
            }

            return array(trim($urlHost), $path);
        }

        $name = '';
        if (self::$actionsIncludeHostInReport) {
            $name .= $urlHost . '/';
        }

        $name .= $urlPath;
        if ($name === '' || substr($name, -1) == '/') {
            $name .= self::$defaultActionName;
        }

        $urlFragment = PageUrl::processUrlFragment($urlFragment);
        if (!empty($urlFragment)) {
            $name .= '#' . $urlFragment;
        }

        return $name;
    }

    public static function setFolderPathMetadata(DataTable $dataTable, $isUrl, $prefix = '')
    {
        $configGeneral = Config::getInstance()->General;
        $separator = $isUrl ? '/' : $configGeneral['action_title_category_delimiter'];
        $metadataName = $isUrl ? 'folder_url_start' : 'page_title_path';

        foreach ($dataTable->getRows() as $row) {
            $subtable = $row->getSubtable();
            if (!$subtable) {
                continue;
            }

            $metadataValue = $prefix . $row->getColumn('label');
            $row->setMetadata($metadataName, $metadataValue);

            self::setFolderPathMetadata($subtable, $isUrl, $metadataValue . $separator);
        }
    }
}
