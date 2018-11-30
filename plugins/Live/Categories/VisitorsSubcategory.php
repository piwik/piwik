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

class VisitorsSubcategory extends Subcategory
{
    protected $categoryId = 'General_Profiles';
    protected $id = 'General_Visitors';
    protected $order = 25;

}
