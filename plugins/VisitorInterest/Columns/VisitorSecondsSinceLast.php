<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\VisitorInterest\Columns;

use Piwik\Common;
use Piwik\Date;
use Piwik\Piwik;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugin\Segment;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class VisitorSecondsSinceLast extends VisitDimension
{
    const COLUMN_TYPE = 'INT(11) UNSIGNED NULL';

    protected $columnName = 'visitor_seconds_since_last';
    protected $columnType = self::COLUMN_TYPE;
    protected $type = self::TYPE_NUMBER;
    protected $segmentName = 'secondsSinceLastVisit';
    protected $nameSingular = 'General_SecondsSinceLastVisit';

    public function getName()
    {
        return Piwik::translate('General_SecondsSinceLastVisit');
    }

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

        $currentTimestamp = $request->getCurrentTimestamp();

        $previousVisitFirstActionTime = $visitor->getPreviousVisitColumn('visit_first_action_time');
        if (empty($previousVisitFirstActionTime)) {
            return 0;
        }

        $previousVisitFirstActionTime = Date::factory($previousVisitFirstActionTime)->getTimestamp();

        if (empty($previousVisitFirstActionTime)) {
            Common::printDebug("Found empty visit_first_action_time for last visit of known visitor, this is unexpected.");
            return 0;
        }

        $secondsSinceLast = $currentTimestamp - $previousVisitFirstActionTime;
        if ($secondsSinceLast < 0) { // tracking a visit in the past
            return null;
        }

        return $secondsSinceLast;
    }

    public function isRequiresProfilableData()
    {
        return true;
    }
}