<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live;

use Piwik\API\Request;
use Piwik\Cache;
use Piwik\CacheId;
use Piwik\Config;
use Piwik\DataTable\Filter\ColumnDelete;
use Piwik\Date;
use Piwik\Metrics\Formatter;
use Piwik\Plugin;
use Piwik\Piwik;
use Piwik\Plugins\Live\Visualizations\VisitorLog;
use Piwik\Tracker\GoalManager;

class Visitor implements VisitorInterface
{
    private $details = array();

    function __construct($visitorRawData)
    {
        $this->details = $visitorRawData;
    }

    function getAllVisitorDetails()
    {
        $visitor = array();

        $instances = self::getAllVisitorDetailsInstances();

        $isProfilable = Request::isCurrentPeriodProfilable();

        foreach ($instances as $instance) {
            $instance->setDataProfilable($isProfilable);
            $instance->setDetails($this->details);
            $instance->extendVisitorDetails($visitor);
        }

        return $visitor;
    }

    /**
     * Returns all available visitor details instances
     *
     * @return VisitorDetailsAbstract[]
     * @throws \Exception
     */
    public static function getAllVisitorDetailsInstances()
    {
        $cacheId = CacheId::pluginAware('VisitorDetails');
        $cache   = Cache::getTransientCache();

        if (!$cache->contains($cacheId)) {
            $instances = [
                new VisitorDetails() // needs to be first
            ];

            /**
             * Triggered to add new visitor details that cannot be picked up by the platform automatically.
             *
             * **Example**
             *
             *     public function addVisitorDetails(&$visitorDetails)
             *     {
             *         $visitorDetails[] = new CustomVisitorDetails();
             *     }
             *
             * @param VisitorDetailsAbstract[] $visitorDetails An array of visitorDetails
             */
            Piwik::postEvent('Live.addVisitorDetails', array(&$instances));

            foreach (self::getAllVisitorDetailsClasses() as $className) {
                $instance = new $className();

                if ($instance instanceof VisitorDetails) {
                    continue;
                }

                $instances[] = $instance;
            }

            /**
             * Triggered to filter / restrict vistor details.
             *
             * **Example**
             *
             *     public function filterVisitorDetails(&$visitorDetails)
             *     {
             *         foreach ($visitorDetails as $index => $visitorDetail) {
             *              if (strpos(get_class($visitorDetail), 'MyPluginName') !== false) {}
             *                  unset($visitorDetails[$index]); // remove all visitor details for a specific plugin
             *              }
             *         }
             *     }
             *
             * @param VisitorDetailsAbstract[] $visitorDetails An array of visitorDetails
             */
            Piwik::postEvent('Live.filterVisitorDetails', array(&$instances));

            $cache->save($cacheId, $instances);
        }

        return $cache->fetch($cacheId);
    }

    /**
     * Returns class names of all VisitorDetails classes.
     *
     * @return string[]
     * @api
     */
    protected static function getAllVisitorDetailsClasses()
    {
        return Plugin\Manager::getInstance()->findComponents('VisitorDetails', 'Piwik\Plugins\Live\VisitorDetailsAbstract');
    }

    function getVisitorId()
    {
        if (isset($this->details['idvisitor'])) {
            return bin2hex($this->details['idvisitor']);
        }
        return false;
    }

    /**
     * Removes fields that the user should only access if they are Super User or admin (cookie, IP,
     * md5 config "fingerprint" hash)
     *
     * @param array $visitorDetails
     * @return array
     */
    public static function cleanVisitorDetails($visitorDetails)
    {
        if (Piwik::isUserIsAnonymous()) {
            $toUnset = array(
                'idvisitor',
                'user_id',
                'location_ip',
                'config_id'
            );

            foreach ($toUnset as $keyName) {
                if (isset($visitorDetails[$keyName])) {
                    unset($visitorDetails[$keyName]);
                }
            }
        }

        return $visitorDetails;
    }

    /**
     * The &flat=1 feature is used by API.getSuggestedValuesForSegment
     *
     * @param $visitorDetailsArray
     * @return array
     */
    public static function flattenVisitorDetailsArray($visitorDetailsArray)
    {
        // NOTE: if you flatten more fields from the "actionDetails" array
        //       ==> also update API/API.php getSuggestedValuesForSegment(), the $segmentsNeedActionsInfo array

        // flatten visit custom variables
        if (!empty($visitorDetailsArray['customVariables'])
            && is_array($visitorDetailsArray['customVariables'])) {
            foreach ($visitorDetailsArray['customVariables'] as $thisCustomVar) {
                $visitorDetailsArray = array_merge($visitorDetailsArray, $thisCustomVar);
            }
            unset($visitorDetailsArray['customVariables']);
        }

        // flatten page views custom variables
        $count = 1;
        foreach ($visitorDetailsArray['actionDetails'] as $action) {
            if (!empty($action['customVariables'])) {
                foreach ($action['customVariables'] as $thisCustomVar) {
                    foreach ($thisCustomVar as $cvKey => $cvValue) {
                        $flattenedKeyName = $cvKey . ColumnDelete::APPEND_TO_COLUMN_NAME_TO_KEEP . $count;
                        $visitorDetailsArray[$flattenedKeyName] = $cvValue;
                        $count++;
                    }
                }
            }
        }

        // Flatten Goals
        $count = 1;
        foreach ($visitorDetailsArray['actionDetails'] as $action) {
            if (!empty($action['goalId'])) {
                $flattenedKeyName = 'visitConvertedGoalId' . ColumnDelete::APPEND_TO_COLUMN_NAME_TO_KEEP . $count;
                $visitorDetailsArray[$flattenedKeyName] = $action['goalId'];
                $count++;
            }
        }

        // Flatten Page Titles/URLs
        $count = 1;
        foreach ($visitorDetailsArray['actionDetails'] as $action) {

            // API.getSuggestedValuesForSegment
            $flattenForActionType = array(
                'outlink' => 'outlinkUrl',
                'download' => 'downloadUrl',
                'event' => 'eventUrl',
                'action' => 'pageUrl'
            );
            foreach($flattenForActionType as $actionType => $flattenedKeyPrefix) {
                if (!empty($action['url'])
                    && $action['type'] == $actionType) {
                    $flattenedKeyName = $flattenedKeyPrefix . ColumnDelete::APPEND_TO_COLUMN_NAME_TO_KEEP . $count;
                    $visitorDetailsArray[$flattenedKeyName] = $action['url'];
                }
            }

            $flatten = array( 'pageTitle', 'siteSearchKeyword', 'eventCategory', 'eventAction', 'eventName', 'eventValue');
            foreach($flatten as $toFlatten) {
                if (!empty($action[$toFlatten])) {
                    $flattenedKeyName = $toFlatten . ColumnDelete::APPEND_TO_COLUMN_NAME_TO_KEEP . $count;
                    $visitorDetailsArray[$flattenedKeyName] = $action[$toFlatten];
                }
            }
            $count++;
        }

        // Entry/exit pages
        $firstAction = $lastAction = false;
        foreach ($visitorDetailsArray['actionDetails'] as $action) {
            if ($action['type'] == 'action') {
                if (empty($firstAction)) {
                    $firstAction = $action;
                }
                $lastAction = $action;
            }
        }

        if (!empty($firstAction['pageTitle'])) {
            $visitorDetailsArray['entryPageTitle'] = $firstAction['pageTitle'];
        }
        if (!empty($firstAction['url'])) {
            $visitorDetailsArray['entryPageUrl'] = $firstAction['url'];
        }
        if (!empty($lastAction['pageTitle'])) {
            $visitorDetailsArray['exitPageTitle'] = $lastAction['pageTitle'];
        }
        if (!empty($lastAction['url'])) {
            $visitorDetailsArray['exitPageUrl'] = $lastAction['url'];
        }

        return $visitorDetailsArray;
    }

    /**
     * @param array $visitorDetailsArray
     * @param array $actionDetails  preset action details
     *
     * @return array
     */
    public static function enrichVisitorArrayWithActions($visitorDetailsArray, $actionDetails = array())
    {
        $actionsLimit = (int)Config::getInstance()->General['visitor_log_maximum_actions_per_visit'];
        $visitorDetailsManipulators = self::getAllVisitorDetailsInstances();

        foreach ($visitorDetailsManipulators as $instance) {
            $instance->provideActionsForVisit($actionDetails, $visitorDetailsArray);
        }

        foreach ($visitorDetailsManipulators as $instance) {
            $instance->filterActions($actionDetails, $visitorDetailsArray);
        }

        $actionDetails = self::sortActionDetails($actionDetails);

        $actionDetails = array_values($actionDetails);

        // limit actions
        if ($actionsLimit < count($actionDetails)) {
            $visitorDetailsArray['truncatedActionsCount'] = count($actionDetails) - $actionsLimit;
            $actionDetails = array_slice($actionDetails, 0, $actionsLimit);
        }

        foreach ($actionDetails as $actionIdx => &$actionDetail) {
            $actionDetail =& $actionDetails[$actionIdx];
            $nextAction = isset($actionDetails[$actionIdx+1]) ? $actionDetails[$actionIdx+1] : null;

            foreach ($visitorDetailsManipulators as $instance) {
                $instance->extendActionDetails($actionDetail, $nextAction, $visitorDetailsArray);
            }
        }

        $visitorDetailsArray['actionDetails'] = $actionDetails;

        return $visitorDetailsArray;
    }

    private static function sortActionDetails($actions)
    {
        usort($actions, function ($a, $b) {
            $fields = array('serverTimePretty', 'idlink_va', 'type', 'title', 'url', 'pageIdAction', 'goalId');
            foreach ($fields as $field) {
                $sort = VisitorLog::sortByActionsOnPageColumn($a, $b, $field);
                if ($sort !== 0) {
                    return $sort;
                }
            }

            return 0;
        });

        return $actions;
    }

}
