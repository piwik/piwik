<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Monolog\Handler;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Piwik\Common;
use Piwik\Notification;
use Piwik\Notification\Manager;
use Zend_Session_Exception;

/**
 * Writes log messages into HTML notification box.
 */
class WebNotificationHandler extends AbstractProcessingHandler
{
    const MAX_NOTIFICATION_MESSAGE_LENGTH = 512;

    public function isHandling(array $record)
    {
        if (!empty($record['context']['ignoreInScreenWriter'])) {
            return false;
        }

        return parent::isHandling($record);
    }

    protected function write(array $record)
    {
        switch ($record['level']) {
            case Logger::EMERGENCY:
            case Logger::ALERT:
            case Logger::CRITICAL:
            case Logger::ERROR:
                $context = Notification::CONTEXT_ERROR;
                break;
            case Logger::WARNING:
                $context = Notification::CONTEXT_WARNING;
                break;
            default:
                $context = Notification::CONTEXT_INFO;
                break;
        }

        $recordMessage = $record['message'];
        $recordMessage = str_replace(PIWIK_INCLUDE_PATH, '', $recordMessage);
        $recordMessage = substr($recordMessage, 0, self::MAX_NOTIFICATION_MESSAGE_LENGTH);

        $message = $record['level_name'] . ': ' . htmlentities($recordMessage, ENT_COMPAT | ENT_HTML401, 'UTF-8');
        $message .= $this->getLiteDebuggingInfo();

        $notification = new Notification($message);
        $notification->context = $context;
        $notification->flags = 0;
        try {
            Manager::notify(Common::getRandomString(), $notification);
        } catch (Zend_Session_Exception $e) {
            // Can happen if this handler is enabled in CLI
            // Silently ignore the error.
        }
    }

    private function getLiteDebuggingInfo()
    {
        $info = [
            'Module' => Common::getRequestVar('module', false),
            'Action' => Common::getRequestVar('action', false),
            'Method' => Common::getRequestVar('method', false),
            'Trigger' => Common::getRequestVar('trigger', false),
            'In CLI mode' => Common::isPhpCliMode() ? 'true' : 'false',
        ];

        $parts = [];
        foreach ($info as $title => $value) {
            if (empty($value)) {
                continue;
            }

            $parts[] = "$title: $value";
        }

        if (empty($parts)) {
            return "";
        }

        return "\n(" . implode(', ', $parts) . ")";
    }
}
