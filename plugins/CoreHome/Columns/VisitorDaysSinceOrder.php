<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreHome\Columns;

use Piwik\Plugin\Dimension\VisitDimension;

class VisitorDaysSinceOrder extends VisitDimension
{
    protected $nameSingular = 'General_DaysSinceLastEcommerceOrder';
    protected $category = 'General_Visitors';  // todo put into ecommerce category?
    protected $type = self::TYPE_NUMBER;
    protected $columnName = 'visitor_seconds_since_order';
    protected $sqlSegment = 'FLOOR(log_visit.visitor_seconds_since_order / 86400)';
    protected $segmentName = 'daysSinceLastEcommerceOrder';

    public function isRequiresProfilableData()
    {
        return true;
    }
}