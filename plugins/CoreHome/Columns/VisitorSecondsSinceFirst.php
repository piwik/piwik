<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreHome\Columns;

use Piwik\Common;
use Piwik\Date;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugin\Segment;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class VisitorSecondsSinceFirst extends VisitDimension
{
    const COLUMN_TYPE = 'INT(11) UNSIGNED NULL';

    protected $columnName = 'visitor_seconds_since_first';
    protected $columnType = self::COLUMN_TYPE;
    protected $segmentName = 'secondsSinceFirstVisit';
    protected $nameSingular = 'General_SecondsSinceFirstVisit';
    protected $type = self::TYPE_NUMBER;

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        if (!$visitor->isVisitorKnown()) {
            return 0;
        }

        $prevSecondsSinceFirst = $visitor->getPreviousVisitColumn('visitor_seconds_since_first');

        // no data for previous visit, we can't calculate for this one
        if ($prevSecondsSinceFirst === null
            || $prevSecondsSinceFirst === false
            || $prevSecondsSinceFirst === ''
        ) {
            return null;
        }

        $prevVisitStart = $visitor->getPreviousVisitColumn('visit_first_action_time');
        $currentVisitStart = $visitor->getVisitorColumn('visit_first_action_time');
        if (empty($prevVisitStart)
            || empty($currentVisitStart)
        ) {
            Common::printDebug("Unexpected: found empty visit first action time for either previous or current visit (previous = $prevVisitStart, current = $currentVisitStart)");
            return null;
        }

        try {
            $timeBetweenVisitStarts = Date::factory($currentVisitStart)->getTimestamp() - Date::factory($prevVisitStart)->getTimestamp();
        } catch (\Exception $ex) {
            Common::printDebug("Error in parsing current/previous visit start datetime values: " . $ex->getMessage());
            return null;
        }

        $newVisitorSecondsSinceFirst = $prevSecondsSinceFirst + $timeBetweenVisitStarts;

        if ($newVisitorSecondsSinceFirst < 0) { // visit in the past
            return null;
        }

        return $newVisitorSecondsSinceFirst;
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