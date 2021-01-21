<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\VisitsSummary;

use Matomo\Cache\Transient;
use Piwik\Archive;
use Piwik\Metrics\Formatter;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugin\Report;
use Piwik\Plugin\ReportsProvider;
use Piwik\Segment;
use Piwik\SettingsPiwik;

/**
 * VisitsSummary API lets you access the core web analytics metrics (visits, unique visitors,
 * count of actions (page views & downloads & clicks on outlinks), time on site, bounces and converted visits.
 *
 * @method static \Piwik\Plugins\VisitsSummary\API getInstance()
 */
class API extends \Piwik\Plugin\API
{
    public function get($idSite, $period, $date, $segment = false, $columns = false)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $archive = Archive::build($idSite, $period, $date, $segment);

        $requestedColumns = Piwik::getArrayFromApiParameter($columns);

        $report = ReportsProvider::factory("VisitsSummary", "get");
        $columns = $report->getMetricsRequiredForReport($this->getCoreColumns($period), $requestedColumns);

        $dataTable = $archive->getDataTableFromNumeric($columns);

        if (!empty($requestedColumns)) {
            $columnsToShow = $requestedColumns ?: $report->getAllMetrics();
            $dataTable->queueFilter('ColumnDelete', array($columnsToRemove = array(), $columnsToShow));
        }

        return $dataTable;
    }

    public function isProfilable($idSite, $period, $date, $segment = false)
    {
        Piwik::checkUserHasViewAccess($idSite);

        if (!is_numeric($idSite)
            || Period::isMultiplePeriod($date, $period)
        ) {
            throw new \Exception("VisitsSummary.isProfilable should not be called with multisites or period [idSite = $idSite, date = $date, period = $period]");
        }

        $segment = new Segment($segment, [$idSite]);

        $data = $this->get($idSite, $period, $date, $segment, ['nb_visits', 'nb_profilable']);
        $row = $data->getFirstRow()->getColumns();

        if (empty($row['nb_visits']) // no visits
            || !isset($row['nb_profilable']) // no profilable metric
            || $row['nb_profilable'] === false
        ) {
            $value = 1;
        } else {
            $nbProfilable = $row['nb_profilable'];
            if ($nbProfilable < 0) {
                $nbProfilable = 0;
            }

            // check that nb_profilable / nb_visits >= 0.01
            $value = (int) ($nbProfilable * 100 >= $row['nb_visits']);
        }

        return $value;
    }

    protected function getCoreColumns($period)
    {
        $columns = array(
            'nb_visits',
            'nb_actions',
            'nb_visits_converted',
            'bounce_count',
            'sum_visit_length',
            'max_actions',
            'nb_profilable',
        );
        if (SettingsPiwik::isUniqueVisitorsEnabled($period)) {
            $columns = array_merge(array('nb_uniq_visitors', 'nb_users'), $columns);
        }
        $columns = array_values($columns);
        return $columns;
    }

    protected function getNumeric($idSite, $period, $date, $segment, $toFetch)
    {
        Piwik::checkUserHasViewAccess($idSite);
        $archive = Archive::build($idSite, $period, $date, $segment);
        $dataTable = $archive->getDataTableFromNumeric($toFetch);
        return $dataTable;
    }

    public function getVisits($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'nb_visits');
    }

    public function getUniqueVisitors($idSite, $period, $date, $segment = false)
    {
        $metric = 'nb_uniq_visitors';
        $this->checkUniqueIsEnabledOrFail($period, $metric);
        return $this->getNumeric($idSite, $period, $date, $segment, $metric);
    }

    public function getUsers($idSite, $period, $date, $segment = false)
    {
        $metric = 'nb_users';
        $this->checkUniqueIsEnabledOrFail($period, $metric);
        return $this->getNumeric($idSite, $period, $date, $segment, $metric);
    }

    public function getActions($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'nb_actions');
    }

    public function getMaxActions($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'max_actions');
    }

    public function getBounceCount($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'bounce_count');
    }

    public function getVisitsConverted($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'nb_visits_converted');
    }

    public function getSumVisitsLength($idSite, $period, $date, $segment = false)
    {
        return $this->getNumeric($idSite, $period, $date, $segment, 'sum_visit_length');
    }

    public function getSumVisitsLengthPretty($idSite, $period, $date, $segment = false)
    {
        $formatter = new Formatter();

        $table = $this->getSumVisitsLength($idSite, $period, $date, $segment);
        if (is_object($table)) {
            $table->filter('ColumnCallbackReplace',
                array('sum_visit_length', array($formatter, 'getPrettyTimeFromSeconds'), array(true)));
        } else {
            $table = $formatter->getPrettyTimeFromSeconds($table, true);
        }
        return $table;
    }

    /**
     * @param $period
     * @param $metric
     * @throws \Exception
     */
    private function checkUniqueIsEnabledOrFail($period, $metric)
    {
        if (!SettingsPiwik::isUniqueVisitorsEnabled($period)) {
            throw new \Exception(
                "The metric " . $metric . " is not enabled for the requested period. " .
                "Please see this FAQ: https://matomo.org/faq/how-to/faq_113/"
            );
        }
    }
}
