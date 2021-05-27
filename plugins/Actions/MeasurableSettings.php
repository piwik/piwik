<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Actions;

use Piwik\Plugins\MobileAppMeasurable\Type as MobileAppType;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;

class MeasurableSettings extends \Piwik\Settings\Measurable\MeasurableSettings
{
    /** @var Setting|null */
    public $includeHostInPageUrlsReport;

    protected function init()
    {
        $this->includeHostInPageUrlsReport = $this->makeIncludeHostInPageUrlsReportSetting();
    }

    private function makeIncludeHostInPageUrlsReportSetting()
    {
        return $this->makeSetting('include_host_in_page_urls_report', $defaultValue = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = "Include Hostname in Page Urls Report"; // TODO: translate
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = "If enabled, Matomo will include the hostname used to access your website in the first level of the Page URLs reports. By default it is disabled since many users would not care to include it. Read our FAQ for more information."; // TODO: translate
        });
    }
}
