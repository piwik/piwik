<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreHome\Columns;

use Piwik\Date;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugin\Segment;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class VisitorSecondsSinceOrder extends VisitDimension
{
    const COLUMN_TYPE = 'INT(11) UNSIGNED NULL';

    protected $columnName = 'visitor_seconds_since_order';
    protected $columnType = self::COLUMN_TYPE;
    protected $segmentName = 'secondsSinceLastEcommerceOrder';
    protected $nameSingular = 'General_SecondsSinceLastEcommerceOrder';
    protected $category = 'General_Visitors'; // todo put into ecommerce category?
    protected $type = self::TYPE_NUMBER;

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        return $this->onExistingVisit($request, $visitor, $action);
    }

    public function onExistingVisit(Request $request, Visitor $visitor, $action)
    {
        $idorder = $request->getParam('ec_id');
        $isOrder = !empty($idorder);
        if ($isOrder) {
            return 0;
        }

        $existingValue = $visitor->getVisitorColumn($this->columnName);
        if ($existingValue !== null && $existingValue !== false) { // already set
            return $existingValue;
        }

        $prevSecondsSinceLastOrder = $visitor->getPreviousVisitColumn($this->columnName);
        if ($prevSecondsSinceLastOrder === null || $prevSecondsSinceLastOrder === false) { // no order at all for visitor
            return null;
        }

        $visitFirstActionTime = $visitor->getPreviousVisitColumn('visit_first_action_time');
        if ($visitFirstActionTime === null || $visitFirstActionTime === false) { // no previous visit
            return null;
        }

        $visitFirstActionTime = Date::factory($visitFirstActionTime)->getTimestamp();
        $secondsSinceLastAction = $request->getCurrentTimestamp() - $visitFirstActionTime;

        $secondsSinceLastOrder = $prevSecondsSinceLastOrder + $secondsSinceLastAction;
        return $secondsSinceLastOrder;
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