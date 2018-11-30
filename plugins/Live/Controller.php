<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugins\Goals\API as APIGoals;
use Piwik\Url;
use Piwik\View;

/**
 */
class Controller extends \Piwik\Plugin\Controller
{
    const SIMPLE_VISIT_COUNT_WIDGET_LAST_MINUTES_CONFIG_KEY = 'live_widget_visitor_count_last_minutes';

    function index()
    {
        return $this->widget();
    }

    public function widget()
    {
        Piwik::checkUserHasViewAccess($this->idSite);
        
        $view = new View('@Live/index');
        $view->idSite = $this->idSite;
        $view->isWidgetized = Common::getRequestVar('widget', 0, 'int');
        $view = $this->setCounters($view);
        $view->liveRefreshAfterMs = (int)Config::getInstance()->General['live_widget_refresh_after_seconds'] * 1000;
        $view->visitors = $this->getLastVisitsStart();
        $view->liveTokenAuth = Piwik::getCurrentUserTokenAuth();
        return $this->render($view);
    }

    public function ajaxTotalVisitors()
    {
        Piwik::checkUserHasViewAccess($this->idSite);
        
        $view = new View('@Live/ajaxTotalVisitors');
        $view = $this->setCounters($view);
        $view->idSite = $this->idSite;
        return $this->render($view);
    }

    private function render(View $view)
    {
        $rendered = $view->render();

        return $rendered;
    }

    public function indexVisitorLog()
    {
        Piwik::checkUserHasViewAccess($this->idSite);
        
        $view = new View('@Live/indexVisitorLog.twig');
        $view->visitorLog = $this->renderReport('getLastVisitsDetails');
        return $view->render();
    }

    /**
     * Widget
     */
    public function getVisitorLog()
    {
        return $this->renderReport('getLastVisitsDetails');
    }
    
    public function getLastVisitsStart()
    {
        Piwik::checkUserHasViewAccess($this->idSite);

        // hack, ensure we load today's visits by default
        $_GET['date'] = 'today';
        \Piwik\Period\Factory::checkPeriodIsEnabled('day');
        $_GET['period'] = 'day';

        $view = new View('@Live/getLastVisitsStart');
        $view->idSite = (int) $this->idSite;
        $api = new Request("method=Live.getLastVisitsDetails&idSite={$this->idSite}&filter_limit=10&format=original&serialize=0&disable_generic_filters=1");
        $visitors = $api->process();
        $view->visitors = $visitors;

        return $this->render($view);
    }

    private function setCounters($view)
    {
        $segment = Request::getRawSegmentFromRequest();
        $last30min = API::getInstance()->getCounters($this->idSite, $lastMinutes = 30, $segment, array('visits', 'actions'));
        $last30min = $last30min[0];
        $today = API::getInstance()->getCounters($this->idSite, $lastMinutes = 24 * 60, $segment, array('visits', 'actions'));
        $today = $today[0];
        $view->visitorsCountHalfHour = $last30min['visits'];
        $view->visitorsCountToday = $today['visits'];
        $view->pisHalfhour = $last30min['actions'];
        $view->pisToday = $today['actions'];
        return $view;
    }

    /**
     * Echo's HTML for visitor profile popup.
     */
    public function getVisitorProfilePopup()
    {
        Piwik::checkUserHasViewAccess($this->idSite);
        $visitorData = Request::processRequest('Live.getVisitorProfile');

        if (empty($visitorData)) {
            throw new \Exception('Visitor could not be found'); // for example when URL parameter is not set
        }
        
        $view = new View('@Live/getVisitorProfilePopup.twig');
        $view->idSite = $this->idSite;
        $view->goals = Request::processRequest('Goals.getGoals', ['idSite' => $this->idSite, 'filter_limit' => '-1'], $default = []);
        $view->visitorData = $visitorData;
        $view->exportLink = $this->getVisitorProfileExportLink();

        $this->setWidgetizedVisitorProfileUrl($view);

        $summaryEntries = array();

        $profileSummaries = StaticContainer::get('Piwik\Plugins\Live\ProfileSummaryProvider')->getAllInstances();
        foreach ($profileSummaries as $profileSummary) {
            $profileSummary->setProfile($view->visitorData);
            $summaryEntries[] = [$profileSummary->getOrder(), $profileSummary->render()];
        }

        usort($summaryEntries, function($a, $b) {
            return version_compare($a[0], $b[0]);
        });

        $summary = '';

        foreach ($summaryEntries AS $summaryEntry) {
            $summary .= $summaryEntry[1];
        }

        $view->profileSummary = $summary;

        return $view->render();
    }

    public function getVisitList()
    {
        $this->checkSitePermission();
        Piwik::checkUserHasViewAccess($this->idSite);
        
        $filterLimit = Common::getRequestVar('filter_offset', 0, 'int');
        $startCounter = Common::getRequestVar('start_number', 0, 'int');
        $limit = Config::getInstance()->General['live_visitor_profile_max_visits_to_aggregate'];

        if ($startCounter >= $limit) {
            return ''; // do not return more visits than configured for profile
        }

        $nextVisits = Request::processRequest('Live.getLastVisitsDetails', array(
                                                                                'segment'                 => Live::getSegmentWithVisitorId(),
                                                                                'filter_limit'            => VisitorProfile::VISITOR_PROFILE_MAX_VISITS_TO_SHOW,
                                                                                'filter_offset'           => $filterLimit,
                                                                                'period'                  => false,
                                                                                'date'                    => false
                                                                           ));

        if (empty($nextVisits)) {
            return '';
        }

        $view = new View('@Live/getVisitList.twig');
        $view->idSite = $this->idSite;
        $view->startCounter = $startCounter < count($nextVisits) ? count($nextVisits) : $startCounter;
        $view->visits = $nextVisits;
        return $view->render();
    }

    private function getVisitorProfileExportLink()
    {
        return Url::getCurrentQueryStringWithParametersModified(array(
                                                                     'module'   => 'API',
                                                                     'action'   => 'index',
                                                                     'method'   => 'Live.getVisitorProfile',
                                                                     'format'   => 'XML',
                                                                     'expanded' => 1
                                                                ));
    }

    private function setWidgetizedVisitorProfileUrl($view)
    {
        if (\Piwik\Plugin\Manager::getInstance()->isPluginLoaded('Widgetize')) {
            $view->widgetizedLink = Url::getCurrentQueryStringWithParametersModified(array(
                                                                                          'module'            => 'Widgetize',
                                                                                          'action'            => 'iframe',
                                                                                          'moduleToWidgetize' => 'Live',
                                                                                          'actionToWidgetize' => 'getVisitorProfilePopup'
                                                                                     ));
        }
    }
}
