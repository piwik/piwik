<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
namespace Piwik\Plugins\Live\Columns\Metrics;

use Piwik\DataTable\Row;
use Piwik\Metrics\Formatter;
use Piwik\Piwik;
use Piwik\Plugin\ProcessedMetric;

/**
 * The average number of seconds spent on the site per visit. Calculated as:
 *
 *     sum_visit_length / nb_visits
 *
 * sum_visit_length & nb_visits are calculated during archiving.
 *
 * @api
 */
class AverageTimeSpent extends ProcessedMetric
{
    public function getName()
    {
        return 'avg_time_spent';
    }

    public function compute(Row $row)
    {
        return (int) $this->getMetric($row, 'avg_time_spent');
    }

    public function format($value, Formatter $formatter)
    {
        return $formatter->getPrettyTimeFromSeconds((int) $value, true);
    }

    public function getTranslatedName()
    {
        return Piwik::translate('General_ColumnAvgTimeOnSite');
    }

    public function getDependentMetrics()
    {
        return array('avg_time_spent');
    }
}