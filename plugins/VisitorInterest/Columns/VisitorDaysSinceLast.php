<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\VisitorInterest\Columns;

use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugin\Segment;

class VisitorDaysSinceLast extends VisitDimension
{
    protected $category = 'General_Visitors';
    protected $type = self::TYPE_NUMBER;
    protected $nameSingular = 'General_DaysSinceLastVisit';
    protected $columnName = 'visitor_seconds_since_last';
    protected $sqlSegment = 'FLOOR(log_visit.visitor_seconds_since_last / 86400)';
    protected $segmentName = 'daysSinceLastVisit';

    public function isRequiresProfilableData()
    {
        return true;
    }
}