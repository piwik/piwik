<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Piwik\Cache as PiwikCache;
use Piwik\Container\StaticContainer;

require_once PIWIK_INCLUDE_PATH . "/core/Piwik.php";

/**
 * This class contains metadata regarding core metrics and contains several
 * related helper functions.
 *
 * Of note are the `INDEX_...` constants. In the database, metric column names
 * in {@link DataTable} rows are stored as integers to save space. The integer
 * values used are determined by these constants.
 *
 * @api
 */
class Metrics
{
    /*
     * When saving DataTables in the DB, we replace all columns name with these IDs. This saves many bytes,
     * eg. INDEX_NB_UNIQ_VISITORS is an integer: 4 bytes, but 'nb_uniq_visitors' is 16 bytes at least
     */
    const INDEX_NB_UNIQ_VISITORS = 1;
    const INDEX_NB_VISITS = 2;
    const INDEX_NB_ACTIONS = 3;
    const INDEX_MAX_ACTIONS = 4;
    const INDEX_SUM_VISIT_LENGTH = 5;
    const INDEX_BOUNCE_COUNT = 6;
    const INDEX_NB_VISITS_CONVERTED = 7;
    const INDEX_NB_CONVERSIONS = 8;
    const INDEX_REVENUE = 9;
    const INDEX_GOALS = 10;
    const INDEX_SUM_DAILY_NB_UNIQ_VISITORS = 11;
    const INDEX_NB_PROFILABLE = 44;

    // Specific to the Actions reports
    const INDEX_PAGE_NB_HITS = 12;
    const INDEX_PAGE_SUM_TIME_SPENT = 13;
    const INDEX_PAGE_EXIT_NB_UNIQ_VISITORS = 14;
    const INDEX_PAGE_EXIT_NB_VISITS = 15;
    const INDEX_PAGE_EXIT_SUM_DAILY_NB_UNIQ_VISITORS = 16;
    const INDEX_PAGE_ENTRY_NB_UNIQ_VISITORS = 17;
    const INDEX_PAGE_ENTRY_SUM_DAILY_NB_UNIQ_VISITORS = 18;
    const INDEX_PAGE_ENTRY_NB_VISITS = 19;
    const INDEX_PAGE_ENTRY_NB_ACTIONS = 20;
    const INDEX_PAGE_ENTRY_SUM_VISIT_LENGTH = 21;
    const INDEX_PAGE_ENTRY_BOUNCE_COUNT = 22;

    // Ecommerce Items reports
    const INDEX_ECOMMERCE_ITEM_REVENUE = 23;
    const INDEX_ECOMMERCE_ITEM_QUANTITY = 24;
    const INDEX_ECOMMERCE_ITEM_PRICE = 25;
    const INDEX_ECOMMERCE_ORDERS = 26;
    const INDEX_ECOMMERCE_ITEM_PRICE_VIEWED = 27;

    // Site Search
    const INDEX_SITE_SEARCH_HAS_NO_RESULT = 28;
    const INDEX_PAGE_IS_FOLLOWING_SITE_SEARCH_NB_HITS = 29;

    // Performance Analytics
    const INDEX_PAGE_SUM_TIME_GENERATION = 30;
    const INDEX_PAGE_NB_HITS_WITH_TIME_GENERATION = 31;
    const INDEX_PAGE_MIN_TIME_GENERATION = 32;
    const INDEX_PAGE_MAX_TIME_GENERATION = 33;

    // Events
    const INDEX_EVENT_NB_HITS = 34;
    const INDEX_EVENT_SUM_EVENT_VALUE = 35;
    const INDEX_EVENT_MIN_EVENT_VALUE = 36;
    const INDEX_EVENT_MAX_EVENT_VALUE = 37;
    const INDEX_EVENT_NB_HITS_WITH_VALUE = 38;

    // Number of unique User IDs
    const INDEX_NB_USERS = 39;
    const INDEX_SUM_DAILY_NB_USERS = 40;

    // Contents
    const INDEX_CONTENT_NB_IMPRESSIONS = 41;
    const INDEX_CONTENT_NB_INTERACTIONS = 42;

    // Unique visitors fingerprints (useful to process unique visitors across websites)
    const INDEX_NB_UNIQ_FINGERPRINTS = 43;

    // Goal reports
    const INDEX_GOAL_NB_CONVERSIONS = 1;
    const INDEX_GOAL_REVENUE = 2;
    const INDEX_GOAL_NB_VISITS_CONVERTED = 3;
    const INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL = 4;
    const INDEX_GOAL_ECOMMERCE_REVENUE_TAX = 5;
    const INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING = 6;
    const INDEX_GOAL_ECOMMERCE_REVENUE_DISCOUNT = 7;
    const INDEX_GOAL_ECOMMERCE_ITEMS = 8;

    public static $mappingFromIdToName = array(
        Metrics::INDEX_NB_UNIQ_VISITORS                      => 'nb_uniq_visitors',
        Metrics::INDEX_NB_UNIQ_FINGERPRINTS                  => 'nb_uniq_fingerprints',
        Metrics::INDEX_NB_VISITS                             => 'nb_visits',
        Metrics::INDEX_NB_ACTIONS                            => 'nb_actions',
        Metrics::INDEX_NB_USERS                              => 'nb_users',
        Metrics::INDEX_MAX_ACTIONS                           => 'max_actions',
        Metrics::INDEX_SUM_VISIT_LENGTH                      => 'sum_visit_length',
        Metrics::INDEX_BOUNCE_COUNT                          => 'bounce_count',
        Metrics::INDEX_NB_VISITS_CONVERTED                   => 'nb_visits_converted',
        Metrics::INDEX_NB_CONVERSIONS                        => 'nb_conversions',
        Metrics::INDEX_REVENUE                               => 'revenue',
        Metrics::INDEX_GOALS                                 => 'goals',
        Metrics::INDEX_SUM_DAILY_NB_UNIQ_VISITORS            => 'sum_daily_nb_uniq_visitors',
        Metrics::INDEX_SUM_DAILY_NB_USERS                    => 'sum_daily_nb_users',
        Metrics::INDEX_NB_PROFILABLE                         => 'nb_profilable',

        // Actions metrics
        Metrics::INDEX_PAGE_NB_HITS                          => 'nb_hits',
        Metrics::INDEX_PAGE_SUM_TIME_SPENT                   => 'sum_time_spent',
        Metrics::INDEX_PAGE_SUM_TIME_GENERATION              => 'sum_time_generation',
        Metrics::INDEX_PAGE_NB_HITS_WITH_TIME_GENERATION     => 'nb_hits_with_time_generation',
        Metrics::INDEX_PAGE_MIN_TIME_GENERATION              => 'min_time_generation',
        Metrics::INDEX_PAGE_MAX_TIME_GENERATION              => 'max_time_generation',

        Metrics::INDEX_PAGE_EXIT_NB_UNIQ_VISITORS            => 'exit_nb_uniq_visitors',
        Metrics::INDEX_PAGE_EXIT_NB_VISITS                   => 'exit_nb_visits',
        Metrics::INDEX_PAGE_EXIT_SUM_DAILY_NB_UNIQ_VISITORS  => 'sum_daily_exit_nb_uniq_visitors',

        Metrics::INDEX_PAGE_ENTRY_NB_UNIQ_VISITORS           => 'entry_nb_uniq_visitors',
        Metrics::INDEX_PAGE_ENTRY_SUM_DAILY_NB_UNIQ_VISITORS => 'sum_daily_entry_nb_uniq_visitors',
        Metrics::INDEX_PAGE_ENTRY_NB_VISITS                  => 'entry_nb_visits',
        Metrics::INDEX_PAGE_ENTRY_NB_ACTIONS                 => 'entry_nb_actions',
        Metrics::INDEX_PAGE_ENTRY_SUM_VISIT_LENGTH           => 'entry_sum_visit_length',
        Metrics::INDEX_PAGE_ENTRY_BOUNCE_COUNT               => 'entry_bounce_count',
        Metrics::INDEX_PAGE_IS_FOLLOWING_SITE_SEARCH_NB_HITS => 'nb_hits_following_search',

        // Items reports metrics
        Metrics::INDEX_ECOMMERCE_ITEM_REVENUE                => 'revenue',
        Metrics::INDEX_ECOMMERCE_ITEM_QUANTITY               => 'quantity',
        Metrics::INDEX_ECOMMERCE_ITEM_PRICE                  => 'price',
        Metrics::INDEX_ECOMMERCE_ITEM_PRICE_VIEWED           => 'price_viewed',
        Metrics::INDEX_ECOMMERCE_ORDERS                      => 'orders',

        // Events
        Metrics::INDEX_EVENT_NB_HITS                         => 'nb_events',
        Metrics::INDEX_EVENT_SUM_EVENT_VALUE                 => 'sum_event_value',
        Metrics::INDEX_EVENT_MIN_EVENT_VALUE                 => 'min_event_value',
        Metrics::INDEX_EVENT_MAX_EVENT_VALUE                 => 'max_event_value',
        Metrics::INDEX_EVENT_NB_HITS_WITH_VALUE              => 'nb_events_with_value',

        // Contents
        Metrics::INDEX_CONTENT_NB_IMPRESSIONS                => 'nb_impressions',
        Metrics::INDEX_CONTENT_NB_INTERACTIONS               => 'nb_interactions'
    );

    public static $mappingFromIdToNameGoal = array(
        Metrics::INDEX_GOAL_NB_CONVERSIONS             => 'nb_conversions',
        Metrics::INDEX_GOAL_NB_VISITS_CONVERTED        => 'nb_visits_converted',
        Metrics::INDEX_GOAL_REVENUE                    => 'revenue',
        Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SUBTOTAL => 'revenue_subtotal',
        Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_TAX      => 'revenue_tax',
        Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_SHIPPING => 'revenue_shipping',
        Metrics::INDEX_GOAL_ECOMMERCE_REVENUE_DISCOUNT => 'revenue_discount',
        Metrics::INDEX_GOAL_ECOMMERCE_ITEMS            => 'items',
    );

    protected static $metricsAggregatedFromLogs = array(
        Metrics::INDEX_NB_UNIQ_VISITORS,
        Metrics::INDEX_NB_VISITS,
        Metrics::INDEX_NB_ACTIONS,
        Metrics::INDEX_NB_USERS,
        Metrics::INDEX_MAX_ACTIONS,
        Metrics::INDEX_SUM_VISIT_LENGTH,
        Metrics::INDEX_BOUNCE_COUNT,
        Metrics::INDEX_NB_VISITS_CONVERTED,
        Metrics::INDEX_NB_PROFILABLE,
    );

    public static function getMappingFromIdToName()
    {
        $cache = PiwikCache::getTransientCache();
        $cacheKey = CacheId::siteAware(CacheId::pluginAware('Metrics.mappingFromIdToName'));

        $value = $cache->fetch($cacheKey);
        if (empty($value)) {
            $value = self::$mappingFromIdToName;

            /**
             * Use this event if your plugin uses custom metric integer IDs to associate those IDs with the
             * actual metric names (eg, 2 => nb_visits). This allows matomo to automate the replacing
             * of IDs => metric names for your new metrics.
             *
             * **Example**
             *
             *     public function addMetricIdToNameMapping(&$mapping)
             *     {
             *         $mapping[Archiver::INDEX_MY_NEW_METRIC] = $mapping['MyPlugin_myNewMetric'];
             *     }
             *
             * @ignore
             */
            Piwik::postEvent('Metrics.addMetricIdToNameMapping', [&$value]);

            $cache->save($cacheKey, $value);
        }
        return $value;
    }

    public static function getVisitsMetricNames()
    {
        $names = array();

        foreach (self::$metricsAggregatedFromLogs as $metricId) {
            $names[$metricId] = self::$mappingFromIdToName[$metricId];
        }

        return $names;
    }

    public static function getMappingFromNameToId()
    {
        static $nameToId = null;
        if ($nameToId === null) {
            $nameToId = array_flip(self::$mappingFromIdToName);
        }
        return $nameToId;
    }

    public static function getMappingFromNameToIdGoal()
    {
        static $nameToId = null;
        if ($nameToId === null) {
            $nameToId = array_flip(self::$mappingFromIdToNameGoal);
        }
        return $nameToId;
    }

    /**
     * Is a lower value for a given column better?
     * @param $column
     * @return bool
     *
     * @ignore
     */
    public static function isLowerValueBetter($column)
    {
        $isLowerBetter = null;

        /**
         * Use this event to define if a lower value of a metric is better.
         *
         * @param string $isLowerBetter should be set to a boolean indicating if lower is better
         * @param string $column name of the column to determine
         *
         * **Example**
         *
         * public function checkIsLowerMetricValueBetter(&$isLowerBetter, $metric)
         * {
         *     if ($metric === 'position') {
         *         $isLowerBetter = true;
         *     }
         * }
         */
        Piwik::postEvent('Metrics.isLowerValueBetter', [&$isLowerBetter, $column]);

        if (!is_null($isLowerBetter)) {
            return true;
        }

        $lowerIsBetterPatterns = array(
            'bounce', 'exit'
        );

        foreach ($lowerIsBetterPatterns as $pattern) {
            if (strpos($column, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive the unit name from a column name
     * @param $column
     * @param $idSite
     * @return string
     * @ignore
     */
    public static function getUnit($column, $idSite)
    {
        $nameToUnit = array(
            '_rate'   => '%',
            'revenue' => Site::getCurrencySymbolFor($idSite),
            '_time_'  => 's'
        );

        $unit = null;

        /**
         * Use this event to define units for custom metrics used in evolution graphs and row evolution only.
         *
         * @param string $unit should hold the unit (e.g. %, €, s or empty string)
         * @param string $column name of the column to determine
         * @param string $idSite id of the current site
         */
        Piwik::postEvent('Metrics.getEvolutionUnit', [&$unit, $column, $idSite]);

        if (!empty($unit)) {
            return $unit;
        }

        foreach ($nameToUnit as $pattern => $type) {
            if (strpos($column, $pattern) !== false) {
                return $type;
            }
        }

        return '';
    }

    public static function getDefaultMetricTranslations()
    {
        $cacheId = CacheId::pluginAware('DefaultMetricTranslations');
        $cache   = PiwikCache::getTransientCache();

        if ($cache->contains($cacheId)) {
            return $cache->fetch($cacheId);
        }

        $translations = array(
            'label'                         => 'General_ColumnLabel',
            'date'                          => 'General_Date',
            'avg_time_on_page'              => 'General_ColumnAverageTimeOnPage',
            'sum_time_spent'                => 'General_ColumnSumVisitLength',
            'sum_visit_length'              => 'General_ColumnSumVisitLength',
            'bounce_count'                  => 'General_ColumnBounces',
            'bounce_count_returning'        => 'VisitFrequency_ColumnBounceCountForReturningVisits',
            'max_actions'                   => 'General_ColumnMaxActions',
            'max_actions_returning'         => 'VisitFrequency_ColumnMaxActionsInReturningVisit',
            'nb_visits_converted_returning' => 'VisitFrequency_ColumnNbReturningVisitsConverted',
            'sum_visit_length_returning'    => 'VisitFrequency_ColumnSumVisitLengthReturning',
            'nb_visits_converted'           => 'General_ColumnVisitsWithConversions',
            'nb_conversions'                => 'Goals_ColumnConversions',
            'revenue'                       => 'General_ColumnRevenue',
            'nb_hits'                       => 'General_ColumnPageviews',
            'entry_nb_visits'               => 'General_ColumnEntrances',
            'entry_nb_uniq_visitors'        => 'General_ColumnUniqueEntrances',
            'exit_nb_visits'                => 'General_ColumnExits',
            'exit_nb_uniq_visitors'         => 'General_ColumnUniqueExits',
            'entry_bounce_count'            => 'General_ColumnBounces',
            'exit_bounce_count'             => 'General_ColumnBounces',
            'exit_rate'                     => 'General_ColumnExitRate',
        );

        $dailySum = ' (' . Piwik::translate('General_DailySum') . ')';
        $afterEntry = ' ' . Piwik::translate('General_AfterEntry');

        $translations['sum_daily_nb_uniq_visitors'] = Piwik::translate('General_ColumnNbUniqVisitors') . $dailySum;
        $translations['sum_daily_nb_users'] = Piwik::translate('General_ColumnNbUsers') . $dailySum;
        $translations['sum_daily_entry_nb_uniq_visitors'] = Piwik::translate('General_ColumnUniqueEntrances') . $dailySum;
        $translations['sum_daily_exit_nb_uniq_visitors'] = Piwik::translate('General_ColumnUniqueExits') . $dailySum;
        $translations['entry_nb_actions'] = Piwik::translate('General_ColumnNbActions') . $afterEntry;
        $translations['entry_sum_visit_length'] = Piwik::translate('General_ColumnSumVisitLength') . $afterEntry;

        $translations = array_merge(self::getDefaultMetrics(), self::getDefaultProcessedMetrics(), $translations);

        /**
         * Use this event to register translations for metrics processed by your plugin.
         *
         * @param string $translations The array mapping of column_name => Plugin_TranslationForColumn
         */
        Piwik::postEvent('Metrics.getDefaultMetricTranslations', array(&$translations));

        $translations = array_map(array('\\Piwik\\Piwik', 'translate'), $translations);

        $cache->save($cacheId, $translations);

        return $translations;
    }

    public static function getDefaultMetrics()
    {
        $cacheId = CacheId::languageAware('DefaultMetrics');
        $cache   = PiwikCache::getTransientCache();

        if ($cache->contains($cacheId)) {
            return $cache->fetch($cacheId);
        }

        $translations = array(
            'nb_visits'        => 'General_ColumnNbVisits',
            'nb_uniq_visitors' => 'General_ColumnNbUniqVisitors',
            'nb_actions'       => 'General_ColumnNbActions',
            'nb_users'         => 'General_ColumnNbUsers',
        );
        $translations = array_map(array('\\Piwik\\Piwik', 'translate'), $translations);

        $cache->save($cacheId, $translations);

        return $translations;
    }

    public static function getDefaultProcessedMetrics()
    {
        $cacheId = CacheId::languageAware('DefaultProcessedMetrics');
        $cache   = PiwikCache::getTransientCache();

        if ($cache->contains($cacheId)) {
            return $cache->fetch($cacheId);
        }

        $translations = array(
            // Processed in AddColumnsProcessedMetrics
            'nb_actions_per_visit' => 'General_ColumnActionsPerVisit',
            'avg_time_on_site'     => 'General_ColumnAvgTimeOnSite',
            'bounce_rate'          => 'General_ColumnBounceRate',
            'conversion_rate'      => 'General_ColumnConversionRate',
        );
        $translations = array_map(array('\\Piwik\\Piwik', 'translate'), $translations);

        $cache->save($cacheId, $translations);

        return $translations;
    }

    public static function getReadableColumnName($columnIdRaw)
    {
        $mappingIdToName = self::$mappingFromIdToName;

        if (array_key_exists($columnIdRaw, $mappingIdToName)) {
            return $mappingIdToName[$columnIdRaw];
        }

        return $columnIdRaw;
    }

    public static function getMetricIdsToProcessReportTotal()
    {
        return array(
            self::INDEX_NB_VISITS,
            self::INDEX_NB_UNIQ_VISITORS,
            self::INDEX_NB_ACTIONS,
            self::INDEX_PAGE_NB_HITS,
            self::INDEX_NB_VISITS_CONVERTED,
            self::INDEX_NB_CONVERSIONS,
            self::INDEX_BOUNCE_COUNT,
            self::INDEX_PAGE_ENTRY_BOUNCE_COUNT,
            self::INDEX_PAGE_ENTRY_NB_VISITS,
            self::INDEX_PAGE_ENTRY_NB_ACTIONS,
            self::INDEX_PAGE_EXIT_NB_VISITS,
            self::INDEX_PAGE_EXIT_NB_UNIQ_VISITORS,
            self::INDEX_REVENUE
        );
    }

    public static function getDefaultMetricsDocumentation()
    {
        $cacheId = CacheId::pluginAware('DefaultMetricsDocumentation');
        $cache   = PiwikCache::getTransientCache();

        if ($cache->contains($cacheId)) {
            return $cache->fetch($cacheId);
        }

        $translations = array(
            'nb_visits'            => 'General_ColumnNbVisitsDocumentation',
            'nb_uniq_visitors'     => 'General_ColumnNbUniqVisitorsDocumentation',
            'nb_actions'           => 'General_ColumnNbActionsDocumentation',
            'nb_users'             => 'General_ColumnNbUsersDocumentation',
            'nb_actions_per_visit' => 'General_ColumnActionsPerVisitDocumentation',
            'avg_time_on_site'     => 'General_ColumnAvgTimeOnSiteDocumentation',
            'bounce_rate'          => 'General_ColumnBounceRateDocumentation',
            'conversion_rate'      => 'General_ColumnConversionRateDocumentation',
            'avg_time_on_page'     => 'General_ColumnAverageTimeOnPageDocumentation',
            'nb_hits'              => 'General_ColumnPageviewsDocumentation',
            'exit_rate'            => 'General_ColumnExitRateDocumentation'
        );

        /**
         * Use this event to register translations for metrics documentation processed by your plugin.
         *
         * @param string[] $translations The array mapping of column_name => Plugin_TranslationForColumnDocumentation
         */
        Piwik::postEvent('Metrics.getDefaultMetricDocumentationTranslations', array(&$translations));

        $translations = array_map(array('\\Piwik\\Piwik', 'translate'), $translations);

        $cache->save($cacheId, $translations);

        return $translations;
    }

    public static function getPercentVisitColumn()
    {
        $percentVisitsLabel = str_replace(' ', '&nbsp;', Piwik::translate('General_ColumnPercentageVisits'));
        return $percentVisitsLabel;
    }
}
