<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreAdminHome;

use Piwik\Container\StaticContainer;
use Piwik\Piwik;
use Piwik\Plugins\CoreAdminHome\Controller as CoreAdminController;
use Piwik\Plugins\CustomJsTracker\TrackerUpdater;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;
use Piwik\Tracker\Cache;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $corsDomains;

    /** @var Setting */
    public $trustedHostnames;

    /** @var Setting */
    public $enableTrackingCookies;

    protected function init()
    {
        $this->title = ' '; // intentionally left blank as it's hidden with css

        $isWritable = Piwik::hasUserSuperUserAccess() && CoreAdminController::isGeneralSettingsAdminEnabled();
        $this->trustedHostnames = $this->createTrustedHostnames();
        $this->trustedHostnames->setIsWritableByCurrentUser($isWritable);

        $isWritable = Piwik::hasUserSuperUserAccess();
        $this->corsDomains = $this->createCorsDomains();
        $this->corsDomains->setIsWritableByCurrentUser($isWritable);

        $this->enableTrackingCookies = $this->createEnableTrackingCookies();
        $this->enableTrackingCookies->setIsWritableByCurrentUser($isWritable);
    }


    private function createCorsDomains()
    {
        return $this->makeSettingManagedInConfigOnly('General', 'cors_domains', $default = [], FieldConfig::TYPE_ARRAY, function (FieldConfig $field) {
            $field->introduction = Piwik::translate('CoreAdminHome_CorsDomains');
            $field->uiControl = FieldConfig::UI_CONTROL_FIELD_ARRAY;
            $arrayField = new FieldConfig\ArrayField(Piwik::translate('Overlay_Domain'), FieldConfig::UI_CONTROL_TEXT);
            $field->uiControlAttributes['field'] = $arrayField->toArray();
            $field->inlineHelp = Piwik::translate('CoreAdminHome_CorsDomainsHelp');
            $field->transform = function($values) {
                return array_filter($values);
            };
        });
    }

    private function createTrustedHostnames()
    {
        return $this->makeSettingManagedInConfigOnly('General', 'trusted_hosts', $default = [], FieldConfig::TYPE_ARRAY, function (FieldConfig $field) {
            $field->introduction = Piwik::translate('CoreAdminHome_TrustedHostSettings');
            $field->uiControl = FieldConfig::UI_CONTROL_FIELD_ARRAY;
            $arrayField = new FieldConfig\ArrayField(Piwik::translate('CoreAdminHome_ValidPiwikHostname'), FieldConfig::UI_CONTROL_TEXT);
            $field->uiControlAttributes['field'] = $arrayField->toArray();
            $field->transform = function($values) {
                return array_filter($values);
            };
        });
    }

    private function createEnableTrackingCookies()
    {
        return $this->makeSetting('enable_tracking_cookies', $default = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $isActivated = \Piwik\Plugin\Manager::getInstance()->isPluginActivated('CustomJsTracker');

            // TODO: since we use requireCookieConsent(), this means cookies COULD be used if a user gives consent (or already has given consent?), so should we make that clear somehow?
            $field->introduction = 'Track With Cookies'; // TODO: translate (and below)
            $field->title = 'Enable tracking cookies';
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = 'By default Matomo does not use cookies in the JavaScript tracker to provide simpler way to comply with privacy regulations. Enable this setting if you want to have more accurate metrics, like accurate unique visitor counts, but keep in mind you may need to implement a consent form for compliance.';
            if (!$isActivated) {
                $field->inlineHelp .= "\n\nNote: this setting only has an effect when the CustomJsTracker plugin is enabled, please ensure it is enabled.";
            }
            $field->onValueSaved = function (Setting $setting) {
                if (\Piwik\Plugin\Manager::getInstance()->isPluginActivated('CustomJsTracker')) {
                    $trackerUpdater = StaticContainer::get(TrackerUpdater::class);
                    if (!empty($trackerUpdater)) {
                        $trackerUpdater->update();
                    }
                }
            };
        });
    }

    public function save()
    {
        parent::save();
        Cache::deleteTrackerCache();
    }
}
