<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Goals\Reports;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\Goals\API;
use Piwik\Plugins\Goals\Goals;

abstract class Base extends \Piwik\Plugin\Report
{
    protected $orderGoal = 50;

    protected function init()
    {
        $this->categoryId = 'Goals_Goals';
        $this->onlineGuideUrl = 'https://matomo.org/docs/tracking-goals-web-analytics/';
    }

    protected function addReportMetadataForEachGoal(&$availableReports, $infos, $goalNameFormatter, $isGoalSummaryReport = false)
    {
        $idSite = $this->getIdSiteFromInfos($infos);
        $goals  = $this->getGoalsForIdSite($idSite);

        foreach ($goals as $goal) {
            $goal['name'] = Common::sanitizeInputValue($goal['name']);

            $this->name       = $goalNameFormatter($goal);
            $this->parameters = array('idGoal' => $goal['idgoal']);
            $this->order      = $this->orderGoal + $goal['idgoal'] * 3;

            $availableReports[] = $this->buildReportMetadata();
        }

        // for goal overview
        if ($isGoalSummaryReport) {
            $this->name = Piwik::translate('Goals_GoalsOverview');
        } else {
            $this->name = $goalNameFormatter(['name' => Piwik::translate('Goals_GoalsOverview')]);
        }
        $this->parameters = [];
        $this->order = $this->orderGoal;
        $availableReports[] = $this->buildReportMetadata();

        $this->init();
    }

    protected function getIdSiteFromInfos($infos)
    {
        $idSite = $infos['idSite'];

        if (empty($idSite)) {
            return null;
        }

        return $idSite;
    }

    private function getGoalsForIdSite($idSite)
    {
        if (empty($idSite)) {
            return array();
        }

        return Request::processRequest('Goals.getGoals', ['idSite' => $idSite, 'filter_limit' => '-1'], $default = []);
    }
}
