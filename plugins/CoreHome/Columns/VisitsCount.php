<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreHome\Columns;

use Piwik\Columns\DimensionMetricFactory;
use Piwik\Columns\MetricsList;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class VisitsCount extends VisitDimension
{
    protected $columnName = 'visitor_count_visits';
    protected $columnType = 'INT(11) UNSIGNED NOT NULL DEFAULT 0';
    protected $segmentName = 'visitCount';
    protected $nameSingular = 'General_NumberOfVisits';
    protected $type = self::TYPE_NUMBER;

    public function configureMetrics(MetricsList $metricsList, DimensionMetricFactory $dimensionMetricFactory)
    {
        // no metrics for this dimension, it would be rather confusing I think
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        $previousVisitCount = $visitor->getPreviousVisitColumn($this->columnName);
        if ($previousVisitCount === false || $previousVisitCount === null || $previousVisitCount === '') {
            return 1;
        }
        $result = $previousVisitCount + 1;
        return $result;
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onAnyGoalConversion(Request $request, Visitor $visitor, $action)
    {
        return $visitor->getVisitorColumn($this->columnName);
    }

    public function isRequiresProfilableData()
    {
        return true;
    }
}