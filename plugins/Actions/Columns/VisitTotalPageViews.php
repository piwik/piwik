<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Actions\Columns;

use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Plugins\CoreHome\Segment;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class VisitTotalPageViews extends VisitDimension
{
    protected $columnName = 'visit_total_pageviews';
    protected $columnType = 'MEDIUMINT UNSIGNED DEFAULT 0';
    protected $type = self::TYPE_NUMBER;
    protected $segmentName = 'pageviews';
    protected $nameSingular = 'General_NbPageviews';
    protected $acceptValues = 'Any positive integer';

    public function __construct()
    {
        $this->suggestedValuesCallback = function ($idSite, $maxValuesToReturn) {
            $positions = range(1,50);

            return array_slice($positions, 0, $maxValuesToReturn);
        };
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return int
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        if (self::shouldCountPageView($action)) {
            return 1;
        }

        return 0;
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return int
     */
    public function onExistingVisit(Request $request, Visitor $visitor, $action)
    {
        $request->setMetadata('Actions', $this->columnName, $visitor->getVisitorColumn($this->columnName));

        if (self::shouldCountPageView($action)) {
            return $this->columnName . ' + 1';
        }

        return false;
    }

    /**
     * @param Action|null $action
     * @return bool
     */
    public static function shouldCountPageView($action)
    {
        if (empty($action)) {
            return false;
        }

        $idActionUrl = $action->getIdActionUrlForEntryAndExitIds();

        if ($idActionUrl !== false) {
            return true;
        }

        return false;
    }

}
