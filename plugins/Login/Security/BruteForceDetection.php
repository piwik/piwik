<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Login\Security;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Db;
use Piwik\Option;
use Piwik\Plugins\Login\Emails\SuspiciousLoginAttemptsInLastHourEmail;
use Piwik\Plugins\Login\Model;
use Piwik\Plugins\Login\SystemSettings;
use Piwik\SettingsServer;
use Piwik\Updater;
use Piwik\Version;
use Psr\Log\LoggerInterface;

class BruteForceDetection {

    const OVERALL_LOGIN_LOCKOUT_THRESHOLD_MIN = 10;
    const TABLE_NAME = 'brute_force_log';
    const API_LOGIN_PLACEHOLDER = '__API__';

    private $minutesTimeRange;
    private $maxLogAttempts;

    private $table = self::TABLE_NAME;
    private $tablePrefixed = '';

    /**
     * @var SystemSettings
     */
    private $settings;

    /**
     * @var Updater
     */
    private $updater;

    /**
     * @var Model
     */
    private $model;

    public function __construct(SystemSettings $systemSettings, Model $model)
    {
        $this->tablePrefixed = Common::prefixTable($this->table);
        $this->settings = $systemSettings;
        $this->minutesTimeRange = $systemSettings->loginAttemptsTimeRange->getValue();
        $this->maxLogAttempts = $systemSettings->maxFailedLoginsPerMinutes->getValue();
        $this->updater = new Updater();
        $this->model = $model;
    }

    public function isEnabled()
    {
        $dbSchemaVersion = $this->updater->getCurrentComponentVersion('core');
        if ($dbSchemaVersion && version_compare($dbSchemaVersion, '3.8.0') == -1) {
            return false; // do not enable brute force detection before the tables exist
        }

        return $this->settings->enableBruteForceDetection->getValue();
    }

    public function addFailedAttempt($ipAddress, $login = null)
    {
        if (empty($login) && Request::isRootRequestApiRequest()) {
            $login = self::API_LOGIN_PLACEHOLDER;
        }

        $now = $this->getNow()->getDatetime();
        $db = Db::get();
        try {
            $db->query('INSERT INTO ' . $this->tablePrefixed . ' (ip_address, attempted_at, login) VALUES(?,?,?)', array($ipAddress, $now, $login));
        } catch (\Exception $ex) {
            $this->ignoreExceptionIfThrownDuringOneClickUpdate($ex);
        }
    }

    public function isAllowedToLogin($ipAddress)
    {
        if ($this->settings->isBlacklistedIp($ipAddress)) {
            return false;
        }

        if ($this->settings->isWhitelistedIp($ipAddress)) {
            return true;
        }

        $db = Db::get();

        $startTime = $this->getStartTimeRange();
        $sql = 'SELECT count(*) as numLogins FROM '.$this->tablePrefixed.' WHERE ip_address = ? AND attempted_at > ?';
        $numLogins = $db->fetchOne($sql, array($ipAddress, $startTime));

        return empty($numLogins) || $numLogins <= $this->maxLogAttempts;
    }

    public function getCurrentlyBlockedIps()
    {
        $sql = 'SELECT ip_address
                FROM ' . $this->tablePrefixed . ' 
                WHERE attempted_at > ?
                GROUP BY ip_address 
                HAVING count(*) > ' . (int) $this->maxLogAttempts;
        $rows = Db::get()->fetchAll($sql, array($this->getStartTimeRange()));

        $ips = array();
        foreach ($rows as $row) {
            if ($this->settings->isWhitelistedIp($row['ip_address'])) {
                continue;
            }
            $ips[] = $row['ip_address'];
        }

        return $ips;
    }

    public function unblockIp($ip)
    {
        // we only delete where attempted_at was recent and keep other IPs for history purposes
        Db::get()->query('DELETE FROM '.$this->tablePrefixed.' WHERE ip_address = ? and attempted_at > ?', array($ip, $this->getStartTimeRange()));
    }

    public function cleanupOldEntries()
    {
        // we delete all entries older than 7 days (or more if more attempts are logged)
        $minutesAutoDelete = 10080;

        $minutes = max($minutesAutoDelete, $this->minutesTimeRange);
        $deleteOlderDate = $this->getDateTimeSubMinutes($minutes);
        Db::get()->query('DELETE FROM '.$this->tablePrefixed.' WHERE attempted_at < ?', array($deleteOlderDate));
    }

    /**
     * @internal tests only
     */
    public function deleteAll()
    {
        return Db::query('DELETE FROM ' . $this->tablePrefixed);
    }

    /**
     * @internal tests only
     */
    public function getAll()
    {
        return Db::get()->fetchAll('SELECT * FROM ' . $this->tablePrefixed);
    }

    protected function getNow()
    {
        return Date::now();
    }

    private function getStartTimeRange()
    {
        return $this->getDateTimeSubMinutes($this->minutesTimeRange);
    }

    private function getDateTimeSubMinutes($minutes)
    {
        return $this->getNow()->subPeriod($minutes, 'minute')->getDatetime();
    }

    public function isUserLoginBlocked($login)
    {
        $count = 0;
        try {
            $count = $this->model->getTotalLoginAttemptsInLastHourForLogin($login);
        } catch (\Exception $ex) {
            $this->ignoreExceptionIfThrownDuringOneClickUpdate($ex);
        }

        if (!$this->hasTooManyTriesOverallInlastHour($count)) {
            return false;
        }

        if (!$this->model->hasNotifiedUserAboutSuspiciousLogins($login)) {
            $this->sendSuspiciousLoginsEmailToUser($login, $count);
        }

        return true;
    }

    private function hasTooManyTriesOverallInLastHour($count)
    {
        return $count > $this->getOverallLoginLockoutThreshold();
    }

    private function sendSuspiciousLoginsEmailToUser($login, $countOverall)
    {
        $distinctIps = $this->model->getDistinctIpsAttemptingLoginsInLastHour($login);

        try {
            // create from DI container so plugins can modify email contents if they want
            $email = StaticContainer::getContainer()->make(SuspiciousLoginAttemptsInLastHourEmail::class, [
                'login' => $login,
                'countOverall' => $countOverall,
                'countDistinctIps' => $distinctIps
            ]);
            $email->send();

            $this->model->markSuspiciousLoginsNotifiedEmailSent($login);
        } catch (\Exception $ex) {
            // log if error is not that we can't find a user
            if (strpos($ex->getMessage(), 'unable to find user to send') === false) {
                StaticContainer::get(LoggerInterface::class)->info(
                    'Error when sending ' . SuspiciousLoginAttemptsInLastHourEmail::class . ' email. User exists but encountered {exception}', [
                    'exception' => $ex,
                ]);
            }
        }
    }

    protected function getOverallLoginLockoutThreshold()
    {
        $settings = new SystemSettings();
        $threshold = $settings->maxFailedLoginsPerMinutes->getValue() * 3;
        return max(self::OVERALL_LOGIN_LOCKOUT_THRESHOLD_MIN, $threshold);
    }

    private function ignoreExceptionIfThrownDuringOneClickUpdate(\Exception $ex)
    {
        // ignore column not found errors during one click update since the db will not be up to date while new code is being used
        $module = Common::getRequestVar('module', false);
        if (strpos($ex->getMessage(), 'Unknown column') === false
            || $module != 'CoreUpdater'
        ) {
            throw $ex;
        }
    }
}
