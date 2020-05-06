<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreUpdater;

use Piwik\Db\Settings;
use Piwik\DbHelper;
use Piwik\Piwik;
use Piwik\Plugin\ReleaseChannels;
use Piwik\Plugins\CoreAdminHome\Controller as CoreAdminController;
use Piwik\Plugins\Marketplace\UpdateCommunication as PluginUpdateCommunication;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;

/**
 * Defines Settings for CoreUpdater.
 *
 * Usage like this:
 * $settings = new SystemSettings();
 * $settings->metric->getValue();
 * $settings->description->getValue();
 */
class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $releaseChannel;

    /** @var Setting */
    public $sendPluginUpdateEmail;

    /** @var Setting */
    public $updateToUtf8mb4;

    /**
     * @var ReleaseChannels
     */
    private $releaseChannels;

    public function __construct(ReleaseChannels $releaseChannels)
    {
        $this->releaseChannels = $releaseChannels;

        parent::__construct();
    }

    protected function init()
    {
        $this->title = Piwik::translate('CoreAdminHome_UpdateSettings');

        $isWritable = Piwik::hasUserSuperUserAccess() && CoreAdminController::isGeneralSettingsAdminEnabled();
        $this->releaseChannel = $this->createReleaseChannel();
        $this->releaseChannel->setIsWritableByCurrentUser($isWritable);

        $isWritable = $isWritable && PluginUpdateCommunication::canBeEnabled();
        $this->sendPluginUpdateEmail = $this->createSendPluginUpdateEmail();
        $this->sendPluginUpdateEmail->setIsWritableByCurrentUser($isWritable);

        $isWritable = Piwik::hasUserSuperUserAccess() && CoreAdminController::isGeneralSettingsAdminEnabled();
        $dbSettings = new Settings();
        if ($dbSettings->getUsedCharset() !== 'utf8mb4' && DbHelper::getDefaultCharset() === 'utf8mb4') {
            $this->updateToUtf8mb4 = $this->createUpdateToUtf8mb4();
            $this->updateToUtf8mb4->setIsWritableByCurrentUser($isWritable);
        }
    }

    private function createReleaseChannel()
    {
        $releaseChannels = $this->releaseChannels;
        $default = 'latest_stable';

        return $this->makeSettingManagedInConfigOnly('General', 'release_channel', $default, FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($releaseChannels) {

            $field->introduction = Piwik::translate('CoreAdminHome_ReleaseChannel');
            $field->uiControl = FieldConfig::UI_CONTROL_RADIO;

            $field->availableValues = array();
            foreach ($releaseChannels->getAllReleaseChannels() as $channel) {
                $name = $channel->getName();
                $description = $channel->getDescription();
                if (!empty($description)) {
                    $name .= ' (' . $description . ')';
                }

                $field->availableValues[$channel->getId()] = $name;
            }

            $field->validate = function ($channel) use ($releaseChannels) {
                if (!$releaseChannels->isValidReleaseChannelId($channel)) {
                    throw new \Exception('Release channel is not valid');
                }
            };

            $field->inlineHelp = Piwik::translate('CoreAdminHome_DevelopmentProcess')
                            . '<br/>'
                            . Piwik::translate('CoreAdminHome_StableReleases',
                                               array("<a target='_blank' rel='noreferrer noopener' href='https://developer.matomo.org/guides/core-team-workflow#influencing-piwik-development'>",
                                                     "</a>"))
                            . '<br/>'
                            . Piwik::translate('CoreAdminHome_LtsReleases');
        });
    }

    private function createSendPluginUpdateEmail()
    {
        return $this->makeSetting('enable_plugin_update_communication', $default = true, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->introduction = Piwik::translate('CoreAdminHome_SendPluginUpdateCommunication');
            $field->uiControl = FieldConfig::UI_CONTROL_RADIO;
            $field->availableValues = array('1' => sprintf('%s (%s)', Piwik::translate('General_Yes'), Piwik::translate('General_Default')),
                                            '0' => Piwik::translate('General_No'));
            $field->inlineHelp = Piwik::translate('CoreAdminHome_SendPluginUpdateCommunicationHelp');
        });
    }

    private function createUpdateToUtf8mb4()
    {
        return $this->makeSetting('update_to_utf8mb4', $default = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->introduction = 'Convert database to UTF8mb4 charset';
            $field->title = 'Trigger conversion in background';
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = 'Your database supports utf8mb4 charset, but your database tables have not been converted yet. ';
        });
    }

}
