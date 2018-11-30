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

class TotalTimeSpent extends ProcessedMetric
{
    public function getName()
    {
        return 'sum_time_spent';
    }

    public function compute(Row $row)
    {
        return $this->getMetric($row, 'sum_time_spent');
    }

    public function format($value, Formatter $formatter)
    {
        return $formatter->getPrettyTimeFromSeconds($value, true);
    }

    public function getTranslatedName()
    {
        return Piwik::translate('General_ColumnSumTimeOnSite');
    }

    public function getDependentMetrics()
    {
        return array('sum_time_spent');
    }
}