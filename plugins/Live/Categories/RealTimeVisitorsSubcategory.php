<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live\Categories;

use Piwik\Category\Subcategory;

class RealTimeVisitorsSubcategory extends Subcategory
{
    protected $categoryId = 'General_Profiles';
    protected $id = 'Live_RealTimeVisitors';
    protected $order = 50;

}
