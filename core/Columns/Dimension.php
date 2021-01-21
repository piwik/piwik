<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Columns;
use Piwik\Common;
use Piwik\Db;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugin\ArchivedMetric;
use Piwik\Plugin\ComponentFactory;
use Piwik\Plugin\Segment;
use Exception;
use Piwik\CacheId;
use Piwik\Cache as PiwikCache;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Metrics\Formatter;
use Piwik\Segment\SegmentsList;

/**
 * @api
 * @since 3.1.0
 */
abstract class Dimension
{
    const COMPONENT_SUBNAMESPACE = 'Columns';

    /**
     * Segment type 'dimension'. Can be used along with {@link setType()}.
     * @api
     */
    const TYPE_DIMENSION = 'dimension';
    const TYPE_BINARY = 'binary';
    const TYPE_TEXT = 'text';
    const TYPE_ENUM = 'enum';
    const TYPE_MONEY = 'money';
    const TYPE_BYTE = 'byte';
    const TYPE_DURATION_MS = 'duration_ms';
    const TYPE_DURATION_S = 'duration_s';
    const TYPE_NUMBER = 'number';
    const TYPE_FLOAT = 'float';
    const TYPE_URL = 'url';
    const TYPE_DATE = 'date';
    const TYPE_TIME = 'time';
    const TYPE_DATETIME = 'datetime';
    const TYPE_TIMESTAMP = 'timestamp';
    const TYPE_BOOL = 'bool';
    const TYPE_PERCENT = 'percent';

    /**
     * This will be the name of the column in the database table if a $columnType is specified.
     * @var string
     * @api
     */
    protected $columnName = '';

    /**
     * If a columnType is defined, we will create a column in the MySQL table having this type. Please make sure
     * MySQL understands this type. Once you change the column type the Piwik platform will notify the user to
     * perform an update which can sometimes take a long time so be careful when choosing the correct column type.
     * @var string
     * @api
     */
    protected $columnType = '';

    /**
     * Holds an array of segment instances
     * @var Segment[]
     */
    protected $segments = array();

    /**
     * Defines what kind of data type this dimension holds. By default the type is auto-detected based on
     * `$columnType` but sometimes it may be needed to correct this value. Depending on this type, a dimension will be
     * formatted differently for example.
     * @var string
     * @api since Piwik 3.2.0
     */
    protected $type = '';

    /**
     * Translation key for name singular
     * @var string
     */
    protected $nameSingular = '';

    /**
     * Translation key for name plural
     * @var string
     * @api since Piwik 3.2.0
     */
    protected $namePlural = '';

    /**
     * Translation key for category
     * @var string
     */
    protected $category = '';

    /**
     * By defining a segment name a user will be able to filter their visitors by this column. If you do not want to
     * define a segment for this dimension, simply leave the name empty.
     * @api since Piwik 3.2.0
     */
    protected $segmentName = '';

    /**
     * Sets a callback which will be executed when user will call for suggested values for segment.
     *
     * @var callable
     * @api since Piwik 3.2.0
     */
    protected $suggestedValuesCallback;

    /**
     * An API method whose label columns will be used to determine the suggested values should browser archiving
     * be disabled. The API must have defined a segment metadata on each row for this to work.
     * @var string
     */
    protected $suggestedValuesApi = '';

    /**
     * Here you should explain which values are accepted/useful for your segment, for example:
     * "1, 2, 3, etc." or "comcast.net, proxad.net, etc.". If the value needs any special encoding you should mention
     * this as well. For example "Any URL including protocol. The URL must be URL encoded."
     *
     * @var string
     * @api since Piwik 3.2.0
     */
    protected $acceptValues;

    /**
     * Defines to which column in the MySQL database the segment belongs (if one is conifugred). Defaults to
     * `$this.dbTableName . '.'. $this.columnName` but you can customize it eg like `HOUR(log_visit.visit_last_action_time)`.
     *
     * @param string $sqlSegment
     * @api since Piwik 3.2.0
     */
    protected $sqlSegment;

    /**
     * Interesting when specifying a segment. Sometimes you want users to set segment values that differ from the way
     * they are actually stored. For instance if you want to allow to filter by any URL than you might have to resolve
     * this URL to an action id. Or a country name maybe has to be mapped to a 2 letter country code. You can do this by
     * specifying either a callable such as `array('Classname', 'methodName')` or by passing a closure.
     * There will be four values passed to the given closure or callable: `string $valueToMatch`, `string $segment`
     * (see {@link setSegment()}), `string $matchType` (eg SegmentExpression::MATCH_EQUAL or any other match constant
     * of this class) and `$segmentName`.
     *
     * If the closure returns NULL, then Piwik assumes the segment sub-string will not match any visitor.
     *
     * @var string|\Closure
     * @api since Piwik 3.2.0
     */
    protected $sqlFilter;

    /**
     * Similar to {@link $sqlFilter} you can map a given segment value to another value. For instance you could map
     * "new" to 0, 'returning' to 1 and any other value to '2'. You can either define a callable or a closure. There
     * will be only one value passed to the closure or callable which contains the value a user has set for this
     * segment.
     * @var string|array
     * @api since Piwik 3.2.0
     */
    protected $sqlFilterValue;

    /**
     * Defines whether this dimension (and segment based on this dimension) is available to anonymous users.
     * @var bool
     * @api since Piwik 3.2.0
     */
    protected $allowAnonymous = true;

    /**
     * The name of the database table this dimension refers to
     * @var string
     * @api
     */
    protected $dbTableName = '';

    /**
     * By default the metricId is automatically generated based on the dimensionId. This might sometimes not be as
     * readable and quite long. If you want more expressive metric names like `nb_visits` compared to
     * `nb_corehomevisitid`, you can eg set a metricId `visit`.
     *
     * @var string
     * @api since Piwik 3.2.0
     */
    protected $metricId = '';

    /**
     * @var bool
     */
    private $isRequiresProfilableData = false;

    /**
     * To be implemented when a column references another column
     * @return Join|null
     * @api since Piwik 3.2.0
     */
    public function getDbColumnJoin()
    {
        return null;
    }

    /**
     * @return Discriminator|null
     * @api since Piwik 3.2.0
     */
    public function getDbDiscriminator()
    {
        return null;
    }

    /**
     * To be implemented when a column represents an enum.
     * @return array
     * @api since Piwik 3.2.0
     */
    public function getEnumColumnValues()
    {
        return array();
    }

    /**
     * Get the metricId which is used to generate metric names based on this dimension.
     * @return string
     */
    public function getMetricId()
    {
        if (!empty($this->metricId)) {
            return $this->metricId;
        }

        $id = $this->getId();

        return str_replace(array('.', ' ', '-'), '_', strtolower($id));
    }

    /**
     * Installs the action dimension in case it is not installed yet. The installation is already implemented based on
     * the {@link $columnName} and {@link $columnType}. If you want to perform additional actions beside adding the
     * column to the database - for instance adding an index - you can overwrite this method. We recommend to call
     * this parent method to get the minimum required actions and then add further custom actions since this makes sure
     * the column will be installed correctly. We also recommend to change the default install behavior only if really
     * needed. FYI: We do not directly execute those alter table statements here as we group them together with several
     * other alter table statements do execute those changes in one step which results in a faster installation. The
     * column will be added to the `log_link_visit_action` MySQL table.
     *
     * Example:
     * ```
    public function install()
    {
    $changes = parent::install();
    $changes['log_link_visit_action'][] = "ADD INDEX index_idsite_servertime ( idsite, server_time )";

    return $changes;
    }
    ```
     *
     * @return array An array containing the table name as key and an array of MySQL alter table statements that should
     *               be executed on the given table. Example:
     * ```
    array(
    'log_link_visit_action' => array("ADD COLUMN `$this->columnName` $this->columnType", "ADD INDEX ...")
    );
    ```
     * @api
     */
    public function install()
    {
        if (empty($this->columnName) || empty($this->columnType) || empty($this->dbTableName)) {
            return array();
        }

        // TODO if table does not exist, create it with a primary key, but at this point we cannot really create it
        // cause we need to show the query in the UI first and user needs to be able to create table manually.
        // we cannot return something like "create table " here as it would be returned for each table etc.
        // we need to do this in column updater etc!

        return array(
            $this->dbTableName => array("ADD COLUMN `$this->columnName` $this->columnType")
        );
    }

    /**
     * Updates the action dimension in case the {@link $columnType} has changed. The update is already implemented based
     * on the {@link $columnName} and {@link $columnType}. This method is intended not to overwritten by plugin
     * developers as it is only supposed to make sure the column has the correct type. Adding additional custom "alter
     * table" actions would not really work since they would be executed with every {@link $columnType} change. So
     * adding an index here would be executed whenever the columnType changes resulting in an error if the index already
     * exists. If an index needs to be added after the first version is released a plugin update class should be
     * created since this makes sure it is only executed once.
     *
     * @return array An array containing the table name as key and an array of MySQL alter table statements that should
     *               be executed on the given table. Example:
     * ```
    array(
    'log_link_visit_action' => array("MODIFY COLUMN `$this->columnName` $this->columnType", "DROP COLUMN ...")
    );
    ```
     * @ignore
     */
    public function update()
    {
        if (empty($this->columnName) || empty($this->columnType) || empty($this->dbTableName)) {
            return array();
        }

        return array(
            $this->dbTableName => array("MODIFY COLUMN `$this->columnName` $this->columnType")
        );
    }

    /**
     * Uninstalls the dimension if a {@link $columnName} and {@link columnType} is set. In case you perform any custom
     * actions during {@link install()} - for instance adding an index - you should make sure to undo those actions by
     * overwriting this method. Make sure to call this parent method to make sure the uninstallation of the column
     * will be done.
     * @throws Exception
     * @api
     */
    public function uninstall()
    {
        if (empty($this->columnName) || empty($this->columnType) || empty($this->dbTableName)) {
            return;
        }

        try {
            $sql = "ALTER TABLE `" . Common::prefixTable($this->dbTableName) . "` DROP COLUMN `$this->columnName`";
            Db::exec($sql);
        } catch (Exception $e) {
            if (!Db::get()->isErrNo($e, '1091')) {
                throw $e;
            }
        }
    }

    /**
     * Returns the ID of the category (typically a translation key).
     * @return string
     */
    public function getCategoryId()
    {
        return $this->category;
    }

    /**
     * Returns the translated name of this dimension which is typically in singular.
     *
     * @return string
     */
    public function getName()
    {
        if (!empty($this->nameSingular)) {
            return Piwik::translate($this->nameSingular);
        }

        return $this->nameSingular;
    }

    /**
     * Returns a translated name in plural for this dimension.
     * @return string
     * @api since Piwik 3.2.0
     */
    public function getNamePlural()
    {
        if (!empty($this->namePlural)) {
            return Piwik::translate($this->namePlural);
        }

        return $this->getName();
    }

    /**
     * Defines whether an anonymous user is allowed to view this dimension
     * @return bool
     * @api since Piwik 3.2.0
     */
    public function isAnonymousAllowed()
    {
        return $this->allowAnonymous;
    }

    /**
     * Sets (overwrites) the SQL segment
     * @param $segment
     * @api since Piwik 3.2.0
     */
    public function setSqlSegment($segment)
    {
        $this->sqlSegment = $segment;
    }

    /**
     * Sets (overwrites the dimension type)
     * @param $type
     * @api since Piwik 3.2.0
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * A dimension should group values by using this method. Otherwise the same row may appear several times.
     *
     * @param mixed $value
     * @param int $idSite
     * @return mixed
     * @api since Piwik 3.2.0
     */
    public function groupValue($value, $idSite)
    {
        switch ($this->type) {
            case Dimension::TYPE_URL:
                return str_replace(array('http://', 'https://'), '', $value);
            case Dimension::TYPE_BOOL:
                return !empty($value) ? '1' : '0';
            case Dimension::TYPE_DURATION_MS:
                return number_format($value / 1000, 2) * 1000; // because we divide we need to group them and cannot do this in formatting step
        }
        return $value;
    }

    /**
     * Formats the dimension value. By default, the dimension is formatted based on the set dimension type.
     *
     * @param mixed $value
     * @param int $idSite
     * @param Formatter $formatter
     * @return mixed
     * @api since Piwik 3.2.0
     */
    public function formatValue($value, $idSite, Formatter $formatter)
    {
        switch ($this->type) {
            case Dimension::TYPE_BOOL:
                if (empty($value)) {
                    return Piwik::translate('General_No');
                }

                return Piwik::translate('General_Yes');
            case Dimension::TYPE_ENUM:
                $values = $this->getEnumColumnValues();
                if (isset($values[$value])) {
                    return $values[$value];
                }
                break;
            case Dimension::TYPE_MONEY:
                return $formatter->getPrettyMoney($value, $idSite);
            case Dimension::TYPE_FLOAT:
                return $formatter->getPrettyNumber((float) $value, $precision = 2);
            case Dimension::TYPE_NUMBER:
                return $formatter->getPrettyNumber($value);
            case Dimension::TYPE_DURATION_S:
                return $formatter->getPrettyTimeFromSeconds($value, $displayAsSentence = false);
            case Dimension::TYPE_DURATION_MS:
                $val = number_format($value / 1000, 2);
                if ($val > 60) {
                    $val = round($val);
                }
                return $formatter->getPrettyTimeFromSeconds($val, $displayAsSentence = true);
            case Dimension::TYPE_PERCENT:
                return $formatter->getPrettyPercentFromQuotient($value);
            case Dimension::TYPE_BYTE:
                return $formatter->getPrettySizeFromBytes($value);
        }

        return $value;
    }

    /**
     * Overwrite this method to configure segments. To do so just create an instance of a {@link \Piwik\Plugin\Segment}
     * class, configure it and call the {@link addSegment()} method. You can add one or more segments for this
     * dimension. Example:
     *
     * ```
     * $segment = new Segment();
     * $segment->setSegment('exitPageUrl');
     * $segment->setName('Actions_ColumnExitPageURL');
     * $segment->setCategory('General_Visit');
     * $segmentsList->addSegment($segment);
     * ```
     *
     * @param SegmentsList            $segmentsList
     * @param DimensionSegmentFactory $dimensionSegmentFactory
     * @throws Exception
     */
    public function configureSegments(SegmentsList $segmentsList, DimensionSegmentFactory $dimensionSegmentFactory)
    {
        if ($this->segmentName && $this->category
            && ($this->sqlSegment || ($this->columnName && $this->dbTableName))
            && $this->nameSingular) {
            $segment = $dimensionSegmentFactory->createSegment(null);
            $segmentsList->addSegment($segment);
        }
    }

    /**
     * Configures metrics for this dimension.
     *
     * For certain dimension types, some metrics will be added automatically.
     *
     * @param MetricsList $metricsList
     * @param DimensionMetricFactory $dimensionMetricFactory
     */
    public function configureMetrics(MetricsList $metricsList, DimensionMetricFactory $dimensionMetricFactory)
    {
        if ($this->getMetricId() && $this->dbTableName && $this->columnName && $this->getNamePlural()) {
            if (in_array($this->getType(), array(self::TYPE_DATETIME, self::TYPE_DATE, self::TYPE_TIME, self::TYPE_TIMESTAMP))) {
                // we do not generate any metrics from these types
                return;
            } elseif (in_array($this->getType(), array(self::TYPE_URL, self::TYPE_TEXT, self::TYPE_BINARY, self::TYPE_ENUM))) {
                $metric = $dimensionMetricFactory->createMetric(ArchivedMetric::AGGREGATION_UNIQUE);
                $metricsList->addMetric($metric);
            } elseif (in_array($this->getType(), array(self::TYPE_BOOL))) {
                $metric = $dimensionMetricFactory->createMetric(ArchivedMetric::AGGREGATION_SUM);
                $metricsList->addMetric($metric);
            } else {
                $metric = $dimensionMetricFactory->createMetric(ArchivedMetric::AGGREGATION_SUM);
                $metricsList->addMetric($metric);

                $metric = $dimensionMetricFactory->createMetric(ArchivedMetric::AGGREGATION_MAX);
                $metricsList->addMetric($metric);
            }
        }
    }

    /**
     * Check whether a dimension has overwritten a specific method.
     * @param $method
     * @return bool
     * @ignore
     */
    public function hasImplementedEvent($method)
    {
        $method = new \ReflectionMethod($this, $method);
        $declaringClass = $method->getDeclaringClass();

        return 0 === strpos($declaringClass->name, 'Piwik\Plugins');
    }

    /**
     * Get the list of configured segments.
     *
     * @return Segment[]
     * @throws Exception
     * @ignore
     */
    public function getSegments()
    {
        $list = new SegmentsList();
        $this->configureSegments($list, new DimensionSegmentFactory($this));
        return $list->getSegments();
    }

    /**
     * Returns the name of the segment that this dimension defines
     * @return string
     * @api since Piwik 3.2.0
     */
    public function getSegmentName()
    {
        return $this->segmentName;
    }

    /**
     * Get the name of the dimension column.
     * @return string
     * @ignore
     */
    public function getColumnName()
    {
        return $this->columnName;
    }

    /**
     * Returns a sql segment expression for this dimension.
     * @return string
     * @api since Piwik 3.2.0
     */
    public function getSqlSegment()
    {
        if (!empty($this->sqlSegment)) {
            return $this->sqlSegment;
        }

        if ($this->dbTableName && $this->columnName) {
            return $this->dbTableName . '.' . $this->columnName;
        }
    }

    /**
     * @return null|callable
     * @ignore
     */
    public function getSuggestedValuesCallback()
    {
        return $this->suggestedValuesCallback;
    }

    /**
     * @return null|string
     * @ignore
     */
    public function getSuggestedValuesApi()
    {
        return $this->suggestedValuesApi;
    }

    /**
     * @return null|string
     * @ignore
     */
    public function getAcceptValues()
    {
        return $this->acceptValues;
    }

    /**
     * @return \Closure|string|null
     * @ignore
     */
    public function getSqlFilter()
    {
        return $this->sqlFilter;
    }

    /**
     * @return array|string|null
     * @ignore
     */
    public function getSqlFilterValue()
    {
        return $this->sqlFilterValue;
    }

    /**
     * Check whether the dimension has a column type configured
     * @return bool
     * @ignore
     */
    public function hasColumnType()
    {
        return !empty($this->columnType);
    }

    /**
     * Returns the name of the database table this dimension belongs to.
     * @return string
     * @api since Piwik 3.2.0
     */
    public function getDbTableName()
    {
        return $this->dbTableName;
    }

    /**
     * Returns a unique string ID for this dimension. The ID is built using the namespaced class name
     * of the dimension, but is modified to be more human readable.
     *
     * @return string eg, `"Referrers.Keywords"`
     * @throws Exception if the plugin and simple class name of this instance cannot be determined.
     *                   This would only happen if the dimension is located in the wrong directory.
     * @api
     */
    public function getId()
    {
        $className = get_class($this);

        return $this->generateIdFromClass($className);
    }

    /**
     * @param string $className
     * @return string
     * @throws Exception
     * @ignore
     */
    protected function generateIdFromClass($className)
    {
        // parse plugin name & dimension name
        $regex = "/Piwik\\\\Plugins\\\\([^\\\\]+)\\\\" . self::COMPONENT_SUBNAMESPACE . "\\\\([^\\\\]+)/";
        if (!preg_match($regex, $className, $matches)) {
            throw new Exception("'$className' is located in the wrong directory.");
        }

        $pluginName = $matches[1];
        $dimensionName = $matches[2];

        return $pluginName . '.' . $dimensionName;
    }

    /**
     * Gets an instance of all available visit, action and conversion dimension.
     * @return Dimension[]
     */
    public static function getAllDimensions()
    {
        $cacheId = CacheId::siteAware(CacheId::pluginAware('AllDimensions'));
        $cache   = PiwikCache::getTransientCache();

        if (!$cache->contains($cacheId)) {
            $plugins   = PluginManager::getInstance()->getPluginsLoadedAndActivated();
            $instances = array();

            /**
             * Triggered to add new dimensions that cannot be picked up automatically by the platform.
             * This is useful if the plugin allows a user to create reports / dimensions dynamically. For example
             * CustomDimensions or CustomVariables. There are a variable number of dimensions in this case and it
             * wouldn't be really possible to create a report file for one of these dimensions as it is not known
             * how many Custom Dimensions will exist.
             *
             * **Example**
             *
             *     public function addDimension(&$dimensions)
             *     {
             *         $dimensions[] = new MyCustomDimension();
             *     }
             *
             * @param Dimension[] $reports An array of dimensions
             */
            Piwik::postEvent('Dimension.addDimensions', array(&$instances));

            foreach ($plugins as $plugin) {
                foreach (self::getDimensions($plugin) as $instance) {
                    $instances[] = $instance;
                }
            }

            /**
             * Triggered to filter / restrict dimensions.
             *
             * **Example**
             *
             *     public function filterDimensions(&$dimensions)
             *     {
             *         foreach ($dimensions as $index => $dimension) {
             *              if ($dimension->getName() === 'Page URL') {}
             *                  unset($dimensions[$index]); // remove this dimension
             *              }
             *         }
             *     }
             *
             * @param Dimension[] $dimensions An array of dimensions
             */
            Piwik::postEvent('Dimension.filterDimensions', array(&$instances));

            $cache->save($cacheId, $instances);
        }

        return $cache->fetch($cacheId);
    }

    public static function getDimensions(Plugin $plugin)
    {
        $columns = $plugin->findMultipleComponents('Columns', '\\Piwik\\Columns\\Dimension');
        $instances  = array();
        $removedDimensions = self::getRemovedDimensions();

        foreach ($columns as $column) {
            if (!in_array($column, $removedDimensions)) {
                $instances[] = new $column();
            }
        }

        return $instances;
    }

    /**
     * Returns a list of dimension class names that have been removed from core over time
     *
     * @return string[]
     */
    public static function getRemovedDimensions()
    {
        return [
            // dimensions removed in Matomo 4.0.0
            'Piwik\Plugins\DevicePlugins\Columns\PluginDirector',
            'Piwik\Plugins\DevicePlugins\Columns\PluginGears',
            'Piwik\Plugins\VisitorInterest\Columns\VisitsByDaysSinceLastVisit',
        ];
    }

    /**
     * Returns the name of the plugin that contains this Dimension.
     *
     * @return string
     * @throws Exception if the Dimension is not located within a Plugin module.
     * @api
     */
    public function getModule()
    {
        $id = $this->getId();
        if (empty($id)) {
            throw new Exception("Invalid dimension ID: '$id'.");
        }

        $parts = explode('.', $id);
        return reset($parts);
    }

    /**
     * Returns the type of the dimension which defines what kind of value this dimension stores.
     * @return string
     * @api since Piwik 3.2.0
     */
    public function getType()
    {
        if (!empty($this->type)) {
            return $this->type;
        }

        if ($this->getDbColumnJoin()) {
            // best guess
            return self::TYPE_TEXT;
        }

        if ($this->getEnumColumnValues()) {
            // best guess
            return self::TYPE_ENUM;
        }

        if (!empty($this->columnType)) {
            // best guess
            $type = strtolower($this->columnType);
            if (strpos($type, 'datetime') !== false) {
                return self::TYPE_DATETIME;
            } elseif (strpos($type, 'timestamp') !== false) {
                return self::TYPE_TIMESTAMP;
            } elseif (strpos($type, 'date') !== false) {
                return self::TYPE_DATE;
            } elseif (strpos($type, 'time') !== false) {
                return self::TYPE_TIME;
            } elseif (strpos($type, 'float') !== false) {
                return self::TYPE_FLOAT;
            } elseif (strpos($type, 'decimal') !== false) {
                return self::TYPE_FLOAT;
            } elseif (strpos($type, 'int') !== false) {
                return self::TYPE_NUMBER;
            } elseif (strpos($type, 'binary') !== false) {
                return self::TYPE_BINARY;
            }
        }

        return self::TYPE_TEXT;
    }

    /**
     * Get the version of the dimension which is used for update checks.
     * @return string
     * @ignore
     */
    public function getVersion()
    {
        return $this->columnType;
    }

    /**
     * Returns true if this dimension requires profilable visit data in order to be useful.
     * Dimensions that require profilable data are hidden (as are their associated reports)
     * when we find that a specific period contains a majority of un-profilable tracked data.
     *
     * Derived classes should set the $isRequiresProfilableData member if they are not profilable.
     *
     * @return mixed
     */
    public function isRequiresProfilableData()
    {
        return $this->isRequiresProfilableData;
    }
}
