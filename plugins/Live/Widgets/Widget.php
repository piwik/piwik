<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Live\Widgets;

use Piwik\Widget\WidgetConfig;

class Widget extends \Piwik\Widget\Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('General_Profiles');
        $config->setSubcategoryId('Live_RealTimeVisitors');
        $config->setName('Live_VisitorsInRealTime');
        $config->setIsWide();
        $config->setOrder(20);
    }
}
