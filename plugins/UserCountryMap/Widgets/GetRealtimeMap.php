<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\UserCountryMap\Widgets;

use Piwik\Widget\WidgetConfig;
use Piwik\Plugin\Manager as PluginManager;

class GetRealtimeMap extends \Piwik\Widget\Widget
{
    public static function configure(WidgetConfig $config)
    {
        $config->setCategoryId('General_Profiles');
        $config->setSubcategoryId('UserCountryMap_RealTimeMap');
        $config->setName('UserCountryMap_RealTimeMap');
        $config->setModule('UserCountryMap');
        $config->setAction('realtimeMap');
        $config->setIsWide();
        $config->setOrder(15);

        if (!PluginManager::getInstance()->isPluginActivated('UserCountry')) {
            $config->disable();
        }
    }
}
