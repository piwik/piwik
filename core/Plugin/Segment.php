<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugin;
use Exception;
use Piwik\Development;

/**
 * Creates a new segment that can be used for instance within the {@link \Piwik\Columns\Dimension::configureSegment()}
 * method. Make sure to set at least the following values: {@link setName()}, {@link setSegment()},
 * {@link setSqlSegment()}, {@link setType()} and {@link setCategory()}. If you are using a segment in the context of a
 * dimension the type and the SQL segment is usually set for you automatically.
 *
 * Example:
 * ```
 $segment = new \Piwik\Plugin\Segment();
 $segment->setType(\Piwik\Plugin\Segment::TYPE_DIMENSION);
 $segment->setName('General_EntryKeyword');
 $segment->setCategory('General_Visit');
 $segment->setSegment('entryKeyword');
 $segment->setSqlSegment('log_visit.entry_keyword');
 $segment->setAcceptedValues('Any keywords people search for on your website such as "help" or "imprint"');
 ```
 * @api
 * @since 2.5.0
 */
class Segment
{
    /**
     * Segment type 'dimension'. Can be used along with {@link setType()}.
     * @api
     */
    const TYPE_DIMENSION = 'dimension';

    /**
     * Segment type 'metric'. Can be used along with {@link setType()}.
     * @api
     */
    const TYPE_METRIC = 'metric';

    private $type;
    private $category;
    private $name;
    private $segment;
    private $sqlSegment;
    private $sqlFilter;
    private $sqlFilterValue;
    private $acceptValues;
    private $permission;
    private $suggestedValuesCallback;
    private $unionOfSegments;
    private $isInternalSegment = false;
    private $suggestedValuesApi = '';

    /**
     * If true, this segment will only be visible to a registered user (see API.getSegmentsMetadata).
     *
     * @var bool
     */
    private $requiresRegisteredUser = false;

    private $requiresProfilableData = false;

    /**
     * @ignore
     */
    final public function __construct()
    {
        $this->init();
    }

    /**
     * Here you can initialize this segment and set any default values. It is called directly after the object is
     * created.
     * @api
     */
    protected function init()
    {
    }

    /**
     * Here you should explain which values are accepted/useful for your segment, for example:
     * "1, 2, 3, etc." or "comcast.net, proxad.net, etc.". If the value needs any special encoding you should mention
     * this as well. For example "Any URL including protocol. The URL must be URL encoded."
     *
     * @param string $acceptedValues
     * @api
     */
    public function setAcceptedValues($acceptedValues)
    {
        $this->acceptValues = $acceptedValues;
    }

    /**
     * Set (overwrite) the category this segment belongs to. It should be a translation key such as 'General_Actions'
     * or 'General_Visit'.
     * @param string $category
     * @api
     */
    public function setCategory($category)
    {
        $this->category = $category;
    }

    /**
     * Set (overwrite) the segment display name. This name will be visible in the API and the UI. It should be a
     * translation key such as 'Actions_ColumnEntryPageTitle' or 'Resolution_ColumnResolution'.
     * @param string $name
     * @api
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Set (overwrite) the name of the segment. The name should be lower case first and has to be unique. The segment
     * name defined here needs to be set in the URL to actually apply this segment. Eg if the segment is 'searches'
     * you need to set "&segment=searches>0" in the UI.
     * @param string $segment
     * @api
     */
    public function setSegment($segment)
    {
        $this->segment = $segment;
        $this->check();
    }

    /**
     * Sometimes you want users to set values that differ from the way they are actually stored. For instance if you
     * want to allow to filter by any URL than you might have to resolve this URL to an action id. Or a country name
     * maybe has to be mapped to a 2 letter country code. You can do this by specifying either a callable such as
     * `array('Classname', 'methodName')` or by passing a closure. There will be four values passed to the given closure
     * or callable: `string $valueToMatch`, `string $segment` (see {@link setSegment()}), `string $matchType`
     * (eg SegmentExpression::MATCH_EQUAL or any other match constant of this class) and `$segmentName`.
     *
     * If the closure returns NULL, then Piwik assumes the segment sub-string will not match any visitor.
     *
     * @param string|\Closure $sqlFilter
     * @api
     */
    public function setSqlFilter($sqlFilter)
    {
        $this->sqlFilter = $sqlFilter;
    }

    /**
     * Similar to {@link setSqlFilter()} you can map a given segment value to another value. For instance you could map
     * "new" to 0, 'returning' to 1 and any other value to '2'. You can either define a callable or a closure. There
     * will be only one value passed to the closure or callable which contains the value a user has set for this
     * segment. This callback is called shortly before {@link setSqlFilter()}.
     * @param string|array $sqlFilterValue
     * @api
     */
    public function setSqlFilterValue($sqlFilterValue)
    {
        $this->sqlFilterValue = $sqlFilterValue;
    }

    /**
     * Defines to which column in the MySQL database the segment belongs: 'mytablename.mycolumnname'. Eg
     * 'log_visit.idsite'. When a segment is applied the given or filtered value will be compared with this column.
     *
     * @param string $sqlSegment
     * @api
     */
    public function setSqlSegment($sqlSegment)
    {
        $this->sqlSegment = $sqlSegment;
        $this->check();
    }

    /**
     * Set a list of segments that should be used instead of fetching the values from a single column.
     * All set segments will be applied via an OR operator.
     *
     * @param array $segments
     * @api
     */
    public function setUnionOfSegments($segments)
    {
        $this->unionOfSegments = $segments;
        $this->check();
    }

    /**
     * @return array
     * @ignore
     */
    public function getUnionOfSegments()
    {
        return $this->unionOfSegments;
    }

    /**
     * @return string
     * @ignore
     */
    public function getSqlSegment()
    {
        return $this->sqlSegment;
    }

    /**
     * @return string
     * @ignore
     */
    public function getSqlFilterValue()
    {
        return $this->sqlFilterValue;
    }

    /**
     * @return string
     * @ignore
     */
    public function getAcceptValues()
    {
        return $this->acceptValues;
    }

    /**
     * @return string
     * @ignore
     */
    public function getSqlFilter()
    {
        return $this->sqlFilter;
    }

    /**
     * Set (overwrite) the type of this segment which is usually either a 'dimension' or a 'metric'.
     * @param string $type See constansts TYPE_*
     * @api
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return string
     * @ignore
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string
     * @ignore
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     * @ignore
     */
    public function getCategoryId()
    {
        return $this->category;
    }

    /**
     * Returns the name of this segment as it should appear in segment expressions.
     *
     * @return string
     */
    public function getSegment()
    {
        return $this->segment;
    }

    /**
     * @return string
     * @ignore
     */
    public function getSuggestedValuesCallback()
    {
        return $this->suggestedValuesCallback;
    }

    /**
     * Set callback which will be executed when user will call for suggested values for segment.
     *
     * @param callable $suggestedValuesCallback
     */
    public function setSuggestedValuesCallback($suggestedValuesCallback)
    {
        $this->suggestedValuesCallback = $suggestedValuesCallback;
    }

    /**
     * @return string
     * @ignore
     */
    public function getSuggestedValuesApi()
    {
        return $this->suggestedValuesApi;
    }

    /**
     * Set callback which will be executed when user will call for suggested values for segment.
     *
     * @param string $suggestedValuesApi
     */
    public function setSuggestedValuesApi($suggestedValuesApi)
    {
        if (!empty($suggestedValuesApi) && is_string($suggestedValuesApi)) {
            if (Development::isEnabled() && strpos($suggestedValuesApi, '.get') === false) {
                throw new Exception('Invalid suggested values API defined, expecting ".get" to be present.');
            }
        } else {
            $suggestedValuesApi = '';
        }
        $this->suggestedValuesApi = $suggestedValuesApi;
    }

    /**
     * You can restrict the access to this segment by passing a boolean `false`. For instance if you want to make
     * a certain segment only available to users having super user access you could do the following:
     * `$segment->setPermission(Piwik::hasUserSuperUserAccess());`
     * @param bool $permission
     * @api
     */
    public function setPermission($permission)
    {
        $this->permission = $permission;
    }

    /**
     * @return array
     * @ignore
     */
    public function toArray()
    {
        $segment = array(
            'type'       => $this->type,
            'category'   => $this->category,
            'name'       => $this->name,
            'segment'    => $this->segment,
            'sqlSegment' => $this->sqlSegment,
        );

        if (!empty($this->unionOfSegments)) {
            $segment['unionOfSegments'] = $this->unionOfSegments;
        }

        if (!empty($this->sqlFilter)) {
            $segment['sqlFilter'] = $this->sqlFilter;
        }

        if (!empty($this->sqlFilterValue)) {
            $segment['sqlFilterValue'] = $this->sqlFilterValue;
        }

        if (!empty($this->acceptValues)) {
            $segment['acceptedValues'] = $this->acceptValues;
        }

        if (isset($this->permission)) {
            $segment['permission'] = $this->permission;
        }

        if (is_callable($this->suggestedValuesCallback)) {
            $segment['suggestedValuesCallback'] = $this->suggestedValuesCallback;
        }

        if (is_string($this->suggestedValuesApi) && !empty($this->suggestedValuesApi)) {
            $segment['suggestedValuesApi'] = $this->suggestedValuesApi;
        }

        $segment['requiresProfilableData'] = $this->requiresProfilableData;

        return $segment;
    }

    /**
     * Returns true if this segment should only be visible to registered users (see API.getSegmentsMetadata),
     * false if it should always be visible to any user (even the anonymous user).
     *
     * @return boolean
     * @ignore
     */
    public function isRequiresRegisteredUser()
    {
        return $this->requiresRegisteredUser;
    }

    /**
     * Sets whether the segment should only be visible to registered users. If set to false it will be even visible to
     * the anonymous user
     *
     * @param boolean $requiresRegisteredUser
     * @ignore
     */
    public function setRequiresRegisteredUser($requiresRegisteredUser)
    {
        $this->requiresRegisteredUser = $requiresRegisteredUser;
    }

    /**
     * Sets whether the segment is for internal use only and should not be visible in the UI or in API metadata output.
     * These types of segments are, for example, used in unions for other segments, but have no value to users.
     *
     * @param bool $value
     */
    public function setIsInternal($value)
    {
        $this->isInternalSegment = $value;
    }

    /**
     * Gets whether the segment is for internal use only and should not be visible in the UI or in API metadata output.
     * These types of segments are, for example, used in unions for other segments, but have no value to users.
     *
     * @return bool
     */
    public function isInternal()
    {
        return $this->isInternalSegment;
    }

    private function check()
    {
        if ($this->sqlSegment && $this->unionOfSegments) {
            throw new Exception(sprintf('Union of segments and SQL segment is set for segment "%s", use only one of them', $this->name));
        }

        if ($this->segment && $this->unionOfSegments && in_array($this->segment, $this->unionOfSegments, true)) {
            throw new Exception(sprintf('The segment %s contains a union segment to itself', $this->name));
        }
    }

    /**
     * Returns true if this segment is only useful with profilable data, false if not.
     *
     * @return bool
     */
    public function isRequiresProfilableData(): bool
    {
        return $this->requiresProfilableData;
    }

    /**
     * Set whether this segment is only useful with profilable data. If a segment requires
     * profilable data and Matomo detects that this data is not present, the segment will be
     * hidden in the UI. (It will still work in the API.)
     *
     * @param bool $requiresProfilableData
     */
    public function setRequiresProfilableData(bool $requiresProfilableData): void
    {
        $this->requiresProfilableData = $requiresProfilableData;
    }
}
