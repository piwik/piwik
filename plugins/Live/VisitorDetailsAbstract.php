<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live;

use Piwik\DataTable;
use Piwik\Db;

/**
 * Class VisitorDetailsAbstract
 *
 * This class can be implemented in a plugin to extend the Live reports, visits log and profile
 *
 * @api
 */
abstract class VisitorDetailsAbstract
{
    /**
     * @var bool
     */
    protected $isDataProfilable;

    /**
     * The visitor raw data (will be automatically set)
     *
     * @var array
     */
    protected $details = array();

    /**
     * Set details of current visit
     *
     * @ignore
     * @param array $details
     */
    public function setDetails($details)
    {
        $this->details = $details;
    }

    /**
     * @ignore
     * @param bool $isProfilable
     */
    public function setDataProfilable($isProfilable)
    {
        $this->isDataProfilable = $isProfilable;
    }

    /**
     * Makes it possible to extend the visitor details returned from API
     *
     * **Example:**
     *
     *     public function extendVisitorDetails(&$visitor) {
     *         $crmData = Model::getCRMData($visitor['userid']);
     *
     *         foreach ($crmData as $prop => $value) {
     *             $visitor[$prop] = $value;
     *         }
     *     }
     *
     * @param array $visitor
     */
    public function extendVisitorDetails(&$visitor)
    {
    }

    /**
     * Makes it possible to enrich the action set for a single visit
     *
     * **Example:**
     *
     *     public function provideActionsForVisit(&$actions, $visitorDetails) {
     *         $adviews = Model::getAdviews($visitorDetails['visitid']);
     *         $actions += $adviews;
     *     }
     *
     * @param array $actions List of action to manipulate
     * @param array $visitorDetails
     */
    public function provideActionsForVisit(&$actions, $visitorDetails)
    {
    }

    /**
     * Makes it possible to enrich the action set for multiple visits identified by given visit ids
     *
     * action set to enrich needs to have the following structure
     *
     * ```
     * $actions = array (
     *     'idvisit' => array ( list of actions for this visit id ),
     *     'idvisit' => array ( list of actions for this visit id ),
     *     ...
     * )
     * ```
     *
     * **Example:**
     *
     *     public function provideActionsForVisitIds(&$actions, $visitIds) {
     *         $adviews = Model::getAdviewsByVisitIds($visitIds);
     *         foreach ($adviews as $idVisit => $adView) {
     *             $actions[$idVisit][] = $adView;
     *         }
     *     }
     *
     * @param array $actions   action set to enrich
     * @param array $visitIds  list of visit ids
     */
    public function provideActionsForVisitIds(&$actions, $visitIds)
    {
    }

    /**
     * Allows filtering the provided actions
     *
     * **Example:**
     *
     *     public function filterActions(&$actions, $visitorDetailsArray) {
     *         foreach ($actions as $idx => $action) {
     *             if ($action['type'] == 'customaction') {
     *                 unset($actions[$idx]);
     *                 continue;
     *             }
     *         }
     *     }
     *
     * @param array $actions
     * @param array $visitorDetailsArray
     */
    public function filterActions(&$actions, $visitorDetailsArray)
    {
    }

    /**
     * Allows extending each action with additional information
     *
     * **Example:**
     *
     *     public function extendActionDetails(&$action, $nextAction, $visitorDetails) {
     *          if ($action['type'] === 'Contents') {
     *              $action['contentView'] = true;
     *          }
     *     }
     *
     * @param array $action
     * @param array $nextAction
     * @param array $visitorDetails
     */
    public function extendActionDetails(&$action, $nextAction, $visitorDetails)
    {
    }

    /**
     * Called when rendering a single Action
     *
     * **Example:**
     *
     *     public function renderAction($action, $previousAction, $visitorDetails) {
     *         if ($action['type'] != Action::TYPE_CONTENT) {
     *             return;
     *         }
     *
     *         $view                 = new View('@Contents/_actionContent.twig');
     *         $view->sendHeadersWhenRendering = false;
     *         $view->action         = $action;
     *         $view->previousAction = $previousAction;
     *         $view->visitInfo      = $visitorDetails;
     *         return $view->render();
     *     }
     *
     * @param array $action
     * @param array $previousAction
     * @param array $visitorDetails
     * @return string
     */
    public function renderAction($action, $previousAction, $visitorDetails)
    {
        return '';
    }

    /**
     * Called for rendering the tooltip on actions
     * returned array needs to look like this:
     *
     * ```
     * array (
     *          20,   // order id
     *          'rendered html content'
     * )
     * ```
     *
     * **Example:**
     *
     *     public function renderActionTooltip($action, $visitInfo) {
     *         if (empty($action['bandwidth'])) {
     *             return [];
     *         }
     *
     *         $view         = new View('@Bandwidth/_actionTooltip');
     *         $view->action = $action;
     *         return [[ 20, $view->render() ]];
     *     }
     *
     * @param array $action
     * @param array $visitInfo
     * @return array
     */
    public function renderActionTooltip($action, $visitInfo)
    {
        return [];
    }

    /**
     * Called when rendering the Icons in visits log
     *
     * **Example:**
     *
     *     public function renderIcons($visitorDetails) {
     *         if (!empty($visitorDetails['avatar'])) {
     *             return '<img src="' . $visitorDetails['avatar'] . '" height="16" width="16">';
     *         }
     *         return '';
     *     }
     *
     * @param array $visitorDetails
     * @return string
     */
    public function renderIcons($visitorDetails)
    {
        return '';
    }

    /**
     * Called when rendering the visitor details in visits log
     * returned array needs to look like this:
     * array (
     *          20,   // order id
     *          'rendered html content'
     * )
     *
     * **Example:**
     *
     *     public function renderVisitorDetails($visitorDetails) {
     *         $view            = new View('@MyPlugin/_visitorDetails.twig');
     *         $view->visitInfo = $visitorDetails;
     *         return $view->render();
     *     }
     *
     * @param array $visitorDetails
     * @return array
     */
    public function renderVisitorDetails($visitorDetails)
    {
        return [];
    }

    /**
     * Allows manipulating the visitor profile properties
     * Will be called when visitor profile is initialized
     *
     * **Example:**
     *
     *     public function initProfile($visit, &$profile) {
     *         // initialize properties that will be filled based on visits or actions
     *         $profile['totalActions']         = 0;
     *         $profile['totalActionsOfMyType'] = 0;
     *     }
     *
     * @param DataTable $visits
     * @param array $profile
     */
    public function initProfile($visits, &$profile)
    {
    }

    /**
     * Allows manipulating the visitor profile properties based on included visits
     * Will be called for every action within the profile
     *
     * **Example:**
     *
     *     public function handleProfileVisit($visit, &$profile) {
     *         $profile['totalActions'] += $visit->getColumn('actions');
     *     }
     *
     * @param DataTable\Row $visit
     * @param array $profile
     */
    public function handleProfileVisit($visit, &$profile)
    {
    }

    /**
     * Allows manipulating the visitor profile properties based on included actions
     * Will be called for every action within the profile
     *
     * **Example:**
     *
     *     public function handleProfileAction($action, &$profile)
     *     {
     *         if ($action['type'] != 'myactiontype') {
     *             return;
     *         }
     *
     *         $profile['totalActionsOfMyType']++;
     *     }
     *
     * @param array $action
     * @param array $profile
     */
    public function handleProfileAction($action, &$profile)
    {
    }

    /**
     * Will be called after iterating over all actions
     * Can be used to set profile information that requires data that was set while iterating over visits & actions
     *
     * **Example:**
     *
     *     public function finalizeProfile($visits, &$profile) {
     *         $profile['isPowerUser'] = false;
     *
     *         if ($profile['totalActionsOfMyType'] > 20) {
     *             $profile['isPowerUser'] = true;
     *         }
     *     }
     *
     * @param DataTable $visits
     * @param array $profile
     */
    public function finalizeProfile($visits, &$profile)
    {
    }

    /**
     * @since Matomo 3.12
     * @return Db|Db\AdapterInterface
     */
    public function getDb()
    {
        return Db::getReader();
    }
}
