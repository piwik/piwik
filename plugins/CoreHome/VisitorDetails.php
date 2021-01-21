<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreHome;

use Piwik\Metrics\Formatter;
use Piwik\Plugins\CoreHome\Columns\VisitGoalBuyer;
use Piwik\Plugins\Live\VisitorDetailsAbstract;

class VisitorDetails extends VisitorDetailsAbstract
{
    public function extendVisitorDetails(&$visitor)
    {
        $visitor['userId']                      = $this->getUserId();
        if ($this->isDataProfilable) {
            $visitor['visitorType'] = $this->getVisitorReturning();
            $visitor['visitorTypeIcon'] = $this->getVisitorReturningIcon();
        }

        $visitor['visitConverted']              = $this->isVisitorGoalConverted();
        $visitor['visitConvertedIcon']          = $this->getVisitorGoalConvertedIcon();
        $visitor['visitCount']                  = $this->getVisitCount();
        $visitor['visitEcommerceStatus']        = $this->getVisitEcommerceStatus();
        $visitor['visitEcommerceStatusIcon']    = $this->getVisitEcommerceStatusIcon();

        if ($this->isDataProfilable) {
            $visitor['daysSinceFirstVisit'] = $this->getDaysSinceFirstVisit();
            $visitor['secondsSinceFirstVisit'] = $this->getSecondsSinceFirstVisit();
            $visitor['daysSinceLastEcommerceOrder'] = $this->getDaysSinceLastEcommerceOrder();
            $visitor['secondsSinceLastEcommerceOrder'] = $this->getSecondsSinceLastEcommerceOrder();
        }

        $visitor['visitDuration']               = $this->getVisitLength();
        $visitor['visitDurationPretty']         = $this->getVisitLengthPretty();
    }

    protected function getVisitEcommerceStatusIcon()
    {
        $status = $this->getVisitEcommerceStatus();

        if (in_array($status, array('ordered', 'orderedThenAbandonedCart'))) {
            return "plugins/Morpheus/images/ecommerceOrder.svg";
        } elseif ($status == 'abandonedCart') {
            return "plugins/Morpheus/images/ecommerceAbandonedCart.svg";
        }
        // Note: it is important that there is no icon when there was no ecommerce conversion
        return null;
    }

    protected function getVisitEcommerceStatus()
    {
        return VisitGoalBuyer::getVisitEcommerceStatusFromId($this->details['visit_goal_buyer']);
    }

    protected function isVisitorGoalConverted()
    {
        return $this->details['visit_goal_converted'];
    }

    protected function getVisitorGoalConvertedIcon()
    {
        return $this->isVisitorGoalConverted()
            ? "plugins/Morpheus/images/goal.svg"
            : null;
    }

    protected function getDaysSinceFirstVisit()
    {
        return floor($this->details['visitor_seconds_since_first'] / 86400);
    }

    protected function getSecondsSinceFirstVisit()
    {
        return $this->details['visitor_seconds_since_first'];
    }

    protected function getDaysSinceLastEcommerceOrder()
    {
        return floor($this->details['visitor_seconds_since_order'] / 86400);
    }

    protected function getSecondsSinceLastEcommerceOrder()
    {
        return $this->details['visitor_seconds_since_order'];
    }

    protected function getVisitorReturning()
    {
        $type = $this->details['visitor_returning'];
        return $type == 2
            ? 'returningCustomer'
            : ($type == 1
                ? 'returning'
                : 'new');
    }

    protected function getVisitorReturningIcon()
    {
        $type = $this->getVisitorReturning();
        if ($type == 'returning'
            || $type == 'returningCustomer'
        ) {
            return "plugins/Live/images/returningVisitor.png";
        }
        return null;
    }

    protected function getVisitCount()
    {
        return $this->details['visitor_count_visits'];
    }

    protected function getVisitLength()
    {
        return $this->details['visit_total_time'];
    }

    protected function getVisitLengthPretty()
    {
        $formatter = new Formatter();
        return $formatter->getPrettyTimeFromSeconds($this->details['visit_total_time'], true);
    }

    protected function getUserId()
    {
        if (isset($this->details['user_id'])
            && strlen($this->details['user_id']) > 0
        ) {
            return $this->details['user_id'];
        }
        return null;
    }
}