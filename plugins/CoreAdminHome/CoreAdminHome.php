<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreAdminHome;

use Piwik\API\Request;
use Piwik\Piwik;
use Piwik\ProxyHttp;
use Piwik\Plugins\CoreHome\SystemSummary;
use Piwik\Settings\Storage\Backend\PluginSettingsTable;

/**
 *
 */
class CoreAdminHome extends \Piwik\Plugin
{
    /**
     * @see \Piwik\Plugin::registerEvents
     */
    public function registerEvents()
    {
        return array(
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
            'AssetManager.getJavaScriptFiles' => 'getJsFiles',
            'UsersManager.deleteUser'         => 'cleanupUser',
            'API.DocumentationGenerator.@hideExceptForSuperUser' => 'displayOnlyForSuperUser',
            'Template.jsGlobalVariables' => 'addJsGlobalVariables',
            'Translate.getClientSideTranslationKeys' => 'getClientSideTranslationKeys',
            'System.addSystemSummaryItems' => 'addSystemSummaryItems',
            'CustomJsTracker.manipulateJsTracker' => 'manipulateJsTracker',
        );
    }

    public function manipulateJsTracker(&$content)
    {
        $settings = new SystemSettings();

        $areCookiesEnabled = $settings->enableTrackingCookies->getValue();
        if (empty($areCookiesEnabled)) {
            $this->disableCookiesInJsTracker($content);
        }
    }

    private function disableCookiesInJsTracker(&$content)
    {
        $content .= '(function () { window._paq = window._paq || []; window._paq.push([\'requireCookieConsent\']);})()';
    }

    public function addSystemSummaryItems(&$systemSummary)
    {
        if (Piwik::isUserHasSomeAdminAccess()) {
            $failures = Request::processRequest('CoreAdminHome.getTrackingFailures', [], []);
            $numFailures = count($failures);
            $icon = 'icon-error';
            if ($numFailures === 0) {
                $icon = 'icon-ok';
            }
            $systemSummary[] = new SystemSummary\Item($key = 'trackingfailures', Piwik::translate('CoreAdminHome_NTrackingFailures', $numFailures), $value = null, array('module' => 'CoreAdminHome', 'action' => 'trackingFailures'), $icon, $order = 9);
        }
    }

    public function cleanupUser($userLogin)
    {
        PluginSettingsTable::removeAllUserSettingsForUser($userLogin);
    }

    public function getStylesheetFiles(&$stylesheets)
    {
        $stylesheets[] = "node_modules/jquery-ui-dist/jquery-ui.min.css";
        $stylesheets[] = "plugins/Morpheus/stylesheets/base.less";
        $stylesheets[] = "plugins/Morpheus/stylesheets/main.less";
        $stylesheets[] = "plugins/CoreAdminHome/stylesheets/generalSettings.less";
        $stylesheets[] = "plugins/CoreAdminHome/angularjs/trackingfailures/trackingfailures.directive.less";
    }

    public function getJsFiles(&$jsFiles)
    {
        $jsFiles[] = "node_modules/jquery/dist/jquery.min.js";
        $jsFiles[] = "node_modules/jquery-ui-dist/jquery-ui.min.js";
        $jsFiles[] = "node_modules/jquery.browser/dist/jquery.browser.min.js";
        $jsFiles[] = "node_modules/sprintf-js/dist/sprintf.min.js";
        $jsFiles[] = "plugins/Morpheus/javascripts/piwikHelper.js";
        $jsFiles[] = "plugins/Morpheus/javascripts/ajaxHelper.js";
        $jsFiles[] = "plugins/CoreHome/javascripts/broadcast.js";
        $jsFiles[] = "plugins/CoreAdminHome/javascripts/protocolCheck.js";
    }

    public function displayOnlyForSuperUser(&$hide)
    {
        $hide = !Piwik::hasUserSuperUserAccess();
    }

    public function addJsGlobalVariables(&$out)
    {
        if (ProxyHttp::isHttps()) {
            $isHttps = 'true';
        } else {
            $isHttps = 'false';
        }

        $out .= "piwik.hasServerDetectedHttps = $isHttps;\n";
    }

    public function getClientSideTranslationKeys(&$translationKeys)
    {
        $translationKeys[] = 'CoreAdminHome_ProtocolNotDetectedCorrectly';
        $translationKeys[] = 'CoreAdminHome_ProtocolNotDetectedCorrectlySolution';
        $translationKeys[] = 'CoreAdminHome_SettingsSaveSuccess';
        $translationKeys[] = 'UserCountryMap_None';
        $translationKeys[] = 'Actions_ColumnPageURL';
        $translationKeys[] = 'General_Date';
        $translationKeys[] = 'General_Measurable';
        $translationKeys[] = 'General_Action';
        $translationKeys[] = 'General_Delete';
        $translationKeys[] = 'General_Id';
        $translationKeys[] = 'CoreHome_ClickToSeeFullInformation';
        $translationKeys[] = 'CoreAdminHome_LearnMore';
        $translationKeys[] = 'CoreAdminHome_ConfirmDeleteAllTrackingFailures';
        $translationKeys[] = 'CoreAdminHome_ConfirmDeleteThisTrackingFailure';
        $translationKeys[] = 'CoreAdminHome_DeleteAllFailures';
        $translationKeys[] = 'CoreAdminHome_NTrackingFailures';
        $translationKeys[] = 'CoreAdminHome_Problem';
        $translationKeys[] = 'CoreAdminHome_Solution';
        $translationKeys[] = 'CoreAdminHome_TrackingFailures';
        $translationKeys[] = 'CoreAdminHome_TrackingFailuresIntroduction';
        $translationKeys[] = 'CoreAdminHome_TrackingURL';
        $translationKeys[] = 'CoreAdminHome_NoKnownFailures';
        $translationKeys[] = 'CoreAdminHome_JSTracking_CodeNoteBeforeClosingHead';
        $translationKeys[] = 'CoreAdminHome_JSTracking_CodeNoteBeforeClosingHeadEmail';
    }
}
