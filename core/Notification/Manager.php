<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Notification;

use Piwik\Notification;
use Piwik\Session;
use Piwik\Session\SessionNamespace;

/**
 * Posts and removes UI notifications (see {@link Piwik\Notification} to learn more).
 *
 */
class Manager
{
    const MAX_NOTIFICATIONS_IN_SESSION = 30;
    /**
     * @var SessionNamespace
     */
    private static $session = null;

    /**
     * @var Notification[]
     */
    private static $notifications = array();

    /**
     * Posts a notification that will be shown in Piwik's status bar. If a notification with the same ID
     * has been posted and has not been closed/removed, it will be replaced with `$notification`.
     *
     * @param string       $id   A unique identifier for this notification. The ID must be a valid HTML
     *                           element ID. It can only contain alphanumeric characters (underscores can
     *                           be used).
     * @param Notification $notification The notification to post.
     * @return bool true if the notification was added, false if it was ignored because there were too many
     *                   pending ones.
     * @api
     */
    public static function notify($id, Notification $notification)
    {
        self::checkId($id);

        self::removeOldestNotificationsIfThereAreTooMany();
        return self::addNotification($id, $notification);
    }

    /**
     * Removes a posted notification by ID.
     *
     * @param string $id The notification ID, see {@link notify()}.
     */
    public static function cancel($id)
    {
        self::checkId($id);

        self::removeNotification($id);
    }

    /**
     * Removes all temporary notifications.
     *
     * Call this method after the notifications have been
     * displayed to make sure temporary notifications won't be displayed twice.
     */
    public static function cancelAllNonPersistent()
    {
        foreach (static::getAllNotifications() as $id => $notification) {
            if (Notification::TYPE_PERSISTENT != $notification->type) {
                static::removeNotification($id);
            }
        }
    }

    /**
     * Determine all notifications that needs to be displayed. They are sorted by priority. Highest priorities first.
     * @return \ArrayObject
     */
    public static function getAllNotificationsToDisplay()
    {
        $notifications = static::getAllNotifications();

        uasort($notifications, function ($n1, $n2) {
            /** @var Notification $n1 */ /** @var Notification $n2 */
            if ($n1->getPriority() == $n2->getPriority()) {
                return 0;
            }

            return $n1->getPriority() > $n2->getPriority() ? -1 : 1;
        });

        return $notifications;
    }

    /**
     * @param $id
     * @throws \Exception In case id is empty or if id contains non word characters
     */
    private static function checkId($id)
    {
        if (empty($id)) {
            throw new \Exception('Notification ID is empty.');
        }

        if (!preg_match('/^\w*$/', $id)) {
            throw new \Exception('Invalid Notification ID given. Only word characters (AlNum + underscore) allowed.');
        }
    }

    private static function addNotification($id, Notification $notification)
    {
        self::saveNotificationAcrossUiRequestsIfNeeded($id, $notification);

        if (count(self::$notifications) >= self::MAX_NOTIFICATIONS_IN_SESSION) {
            return false;
        }

        // we store all kinda notifications here so in case the session is not enabled or disabled later there is still
        // a chance it gets delivered to the UI during the same request.
        self::$notifications[$id] = $notification;

        return true;
    }

    private static function saveNotificationAcrossUiRequestsIfNeeded($id, Notification $notification)
    {
        if (self::isSessionEnabled()) {
            // we need to save even non persistent notifications if possible. Otherwise if there's a redirect
            // a notification is not shown on the next page view
            $session = static::getSession();
            $session->notifications[$id] = $notification;
        }
    }

    private static function removeOldestNotificationsIfThereAreTooMany()
    {
        if (!self::isSessionEnabled()) {
            return;
        }

        $maxNotificationsInSession = self::MAX_NOTIFICATIONS_IN_SESSION;

        $session = static::getSession();

        while (count($session->notifications) >= $maxNotificationsInSession) {
            array_shift($session->notifications);
        }
    }

    private static function getAllNotifications()
    {
        if (!self::isSessionEnabled()) {
            return array();
        }

        $notifications = self::$notifications;

        foreach ($notifications as $id => $notification) {
            // we copy them over to the session if possible and persist it in case the session was not yet
            // writable / enabled at the time the notification was added.
            self::saveNotificationAcrossUiRequestsIfNeeded($id, $notification);
        }

        if (self::isSessionEnabled()) {
            $session = static::getSession();
            foreach ($session->notifications as $id => $notification) {
                $notifications[$id] = $notification;
            }
        }

        return $notifications;
    }

    private static function removeNotification($id)
    {
        if (array_key_exists($id, self::$notifications)) {
            unset(self::$notifications[$id]);
        }

        if (self::isSessionEnabled()) {
            $session = static::getSession();
            if (array_key_exists($id, $session->notifications)) {
                unset($session->notifications[$id]);
            }
        }
    }

    private static function isSessionEnabled()
    {
        return Session::isWritable() && Session::isReadable();
    }

    /**
     * @return SessionNamespace
     */
    private static function getSession()
    {
        if (!isset(static::$session)) {
            static::$session = new SessionNamespace('notification');
        }

        if (empty(static::$session->notifications) && self::isSessionEnabled()) {
            static::$session->notifications = array();
        }

        return static::$session;
    }

    public static function cancelAllNotifications()
    {
        self::$notifications = [];
    }

    // for tests
    public static function getPendingInMemoryNotifications()
    {
        return self::$notifications;
    }
}
