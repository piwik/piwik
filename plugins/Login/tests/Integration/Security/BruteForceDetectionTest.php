<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Login\tests\Integration\Security;

use Piwik\API\Request;
use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Mail;
use Piwik\Piwik;
use Piwik\Plugins\Login\Dao\BruteForceDetectionDao;
use Piwik\Plugins\Login\Emails\SuspiciousLoginAttemptsInLastHourEmail;
use Piwik\Plugins\Login\Security\BruteForceDetection;
use Piwik\Plugins\Login\SystemSettings;
use Piwik\Plugins\UsersManager\API;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

class CustomBruteForceDetection extends BruteForceDetection {
    /**
     * @var Date
     */
    private $now;

    public function setNow($now)
    {
        $this->now = $now;
    }

    public function getNow()
    {
        if (!isset($this->now)) {
            return Date::factory('2018-09-23 12:40:10');
        }

        return $this->now;
    }

    protected function getOverallLoginLockoutThreshold()
    {
        return 3;
    }
}

/**
 * @group Login
 * @group BruteForceDetection
 */
class BruteForceDetectionTest extends IntegrationTestCase
{
    /**
     * @var CustomBruteForceDetection
     */
    private $detection;

    /**
     * @var SystemSettings
     */
    private $settings;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = new SystemSettings();
        $this->settings->loginAttemptsTimeRange->setValue(10);
        $this->settings->maxFailedLoginsPerMinutes->setValue(5);
        $this->settings->whitelisteBruteForceIps->setValue(array('10.99.99.99'));
        $this->settings->blacklistedBruteForceIps->setValue(array('10.55.55.55'));
        $this->detection = new CustomBruteForceDetection($this->settings, new \Piwik\Plugins\Login\Model());
    }

    public function test_isEnabled_isEnabledByDefault()
    {
        $this->assertTrue($this->detection->isEnabled());
    }

    public function test_addFailedAttempt_usesApiUsernameIfInApiRequest()
    {
        $this->addFailedLoginInPast('127.0.0.1', 1);

        Request::setIsRootRequestApiRequest(true);
        $this->addFailedLoginInPast('10.1.2.3', 3);

        $entries = $this->detection->getAll();
        $expected = [
            [
                'id_brute_force_log' => '1',
                'ip_address' => '127.0.0.1',
                'attempted_at' => '2018-09-23 12:39:10',
                'login' => null,
            ],
            [
                'id_brute_force_log' => '2',
                'ip_address' => '10.1.2.3',
                'attempted_at' => '2018-09-23 12:37:10',
                'login' => BruteForceDetection::API_LOGIN_PLACEHOLDER,
            ],
        ];
        $this->assertEquals($expected, $entries);
    }

    public function test_addFailedAttempt_addsEntries()
    {
        $this->addFailedLoginInPast('127.0.0.1', 1);
        $this->addFailedLoginInPast('2001:0db8:85a3:0000:0000:8a2e:0370:7334', 2);
        $this->addFailedLoginInPast('10.1.2.3', 3);
        $this->addFailedLoginInPast('2001:0db8:85a3:0000:0000:8a2e:0370:7334', 4);
        $this->addFailedLoginInPast('10.1.2.3', 5);

        $entries = $this->detection->getAll();
        $expected = array (
                array (
                    'id_brute_force_log' => '1',
                    'ip_address' => '127.0.0.1',
                    'attempted_at' => '2018-09-23 12:39:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '2',
                    'ip_address' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                    'attempted_at' => '2018-09-23 12:38:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '3',
                    'ip_address' => '10.1.2.3',
                    'attempted_at' => '2018-09-23 12:37:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '4',
                    'ip_address' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                    'attempted_at' => '2018-09-23 12:36:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '5',
                    'ip_address' => '10.1.2.3',
                    'attempted_at' => '2018-09-23 12:35:10',
                    'login' => null,
                ),
        );
        $this->assertEquals($expected, $entries);
    }

    public function test_unblockIp_onlyRemovesRecentEntriesOfIp()
    {
        $now = $this->detection->getNow();
        $this->addFailedLoginInPast('127.0.0.1',1);
        $this->addFailedLoginInPast('10.1.2.3', 2); // should be deleted
        $this->addFailedLoginInPast('10.1.2.3', 3); // should be deleted

        // those should not be touched
        $this->detection->setNow($now->subDay(20));
        $this->detection->addFailedAttempt('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->detection->addFailedAttempt('10.1.2.3');

        $this->detection->setNow($now);
        $this->assertCount(5, $this->detection->getAll());

        $this->detection->unblockIp('10.1.2.3');

        $entries = $this->detection->getAll();
        $expected = array (
                array (
                    'id_brute_force_log' => '1',
                    'ip_address' => '127.0.0.1',
                    'attempted_at' => '2018-09-23 12:39:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '4',
                    'ip_address' => '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                    'attempted_at' => '2018-09-03 12:40:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '5',
                    'ip_address' => '10.1.2.3',
                    'attempted_at' => '2018-09-03 12:40:10',
                    'login' => null,
                ),
        );
        $this->assertEquals($expected, $entries);
    }

    public function test_cleanupOldEntries_onlyRemovesOldEntries()
    {
        $now = $this->detection->getNow();
        // these should be kept cause they are recent
        $this->addFailedLoginInPast('127.0.0.1', 1);
        $this->addFailedLoginInPast('10.1.2.3', 2, 'auser');

        $this->detection->setNow($now->subDay(5));
        $this->detection->addFailedAttempt('10.1.2.6');

        // those should be cleaned up
        $this->detection->setNow($now->subDay(10));
        $this->detection->addFailedAttempt('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->detection->addFailedAttempt('10.1.2.4');

        $this->detection->setNow($now);
        $this->assertCount(5, $this->detection->getAll());

        $this->detection->cleanupOldEntries();

        $entries = $this->detection->getAll();
        $expected = array (
                array (
                    'id_brute_force_log' => '1',
                    'ip_address' => '127.0.0.1',
                    'attempted_at' => '2018-09-23 12:39:10',
                    'login' => null,
                ),
                array (
                    'id_brute_force_log' => '2',
                    'ip_address' => '10.1.2.3',
                    'attempted_at' => '2018-09-23 12:38:10',
                    'login' => 'auser',
                ),
                array (
                    'id_brute_force_log' => '3',
                    'ip_address' => '10.1.2.6',
                    'attempted_at' => '2018-09-18 12:40:10',
                    'login' => null,
                ),
        );
        $this->assertEquals($expected, $entries);
    }

    public function test_getCurrentlyBlockedIps_noIpBlockedWhenNonAdded()
    {
        $this->assertEquals(array(), $this->detection->getCurrentlyBlockedIps());
    }

    public function test_getCurrentlyBlockedIps_noIpBlockedWhenOnlyRecentOnesAdded()
    {
        $this->detection->addFailedAttempt('127.0.0.1');
        $this->detection->addFailedAttempt('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->detection->addFailedAttempt('10.1.2.3');
        $this->detection->addFailedAttempt('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
        $this->detection->addFailedAttempt('10.1.2.3');

        $this->assertEquals(array(), $this->detection->getCurrentlyBlockedIps());
    }

    public function test_getCurrentlyBlockedIps_isAllowedToLogin_onlyBlockedWhenMaxAttemptsReached()
    {
        $this->addFailedLoginInPast('127.0.0.1', 1);
        $this->addFailedLoginInPast('2001:0db8:85a3:0000:0000:8a2e:0370:7334', 2);
        $this->addFailedLoginInPast('10.1.2.3', 3);
        $this->addFailedLoginInPast('10.1.2.3', 5);
        $this->addFailedLoginInPast('10.1.2.3', 7);
        $this->addFailedLoginInPast('10.1.2.3', 9); // 4 logins per 10 minute allowed

        // now we make sure more than 10 minutes ago there were heaps of entries and the user was actually blocked...
        // but not anymore cause only last 10 min matters
        $this->addFailedLoginInPast('10.1.2.3', 11);
        $this->addFailedLoginInPast('10.1.2.3', 12);
        for ($i = 0; $i < 20; $i++) {
            $this->addFailedLoginInPast('10.1.2.3', 14);
        }

        $this->assertEquals(array(), $this->detection->getCurrentlyBlockedIps());
        $this->assertTrue($this->detection->isAllowedToLogin('10.1.2.3'));
        $this->assertTrue($this->detection->isAllowedToLogin('127.0.0.1'));

        $this->detection->setNow($this->detection->getNow()->subPeriod(10, 'minute'));

        // now we go 10 min back and the user will be blocked
        $this->assertEquals(array('10.1.2.3'), $this->detection->getCurrentlyBlockedIps());
        $this->assertFalse($this->detection->isAllowedToLogin('10.1.2.3'));
        $this->assertTrue($this->detection->isAllowedToLogin('127.0.0.1'));
    }

    public function test_getCurrentlyBlockedIps_isAllowedToLogin_whitelistedIpCanAlwaysLoginAndIsNeverBlocked()
    {
        for ($i = 0; $i < 20; $i++) {
            $this->addFailedLoginInPast('10.99.99.99', 1);
            $this->addFailedLoginInPast('127.0.0.1', 1);
        }

        $this->assertEquals(array('127.0.0.1'), $this->detection->getCurrentlyBlockedIps());
        $this->assertTrue($this->detection->isAllowedToLogin('10.99.99.99'));
        $this->assertFalse($this->detection->isAllowedToLogin('127.0.0.1'));
    }

    public function test_getCurrentlyBlockedIps_isAllowedToLogin_blacklistedIpCanNeverLogIn_EvenWhenNoFailedAttempts()
    {
        $this->assertEquals(array(), $this->detection->getCurrentlyBlockedIps());
        $this->assertEquals(array(), $this->detection->getAll());
        $this->assertFalse($this->detection->isAllowedToLogin('10.55.55.55'));
    }

    public function test_isUserLoginBlocked_returnsTrueIfThereAreMoreThanTheThresholdNumOfAttempts()
    {
        $this->detection->setNow(Date::now());

        $sentMail = null;
        Piwik::addAction('Mail.send', function (Mail $mail) use (&$sentMail) {
            $sentMail = $mail;
        });

        $this->addFailedAttemptsWithLogin();

        $this->assertTrue($this->detection->isUserLoginBlocked('theuser'));
        $this->assertNull($sentMail); // user does not exist so no mail sent
    }

    public function test_isUserLoginBlocked_sendsEmailIfLoginIsForRealUser()
    {
        $this->detection->setNow(Date::now());

        API::getInstance()->addUser('theuser', 'averybadpwd', 'someemail@email.com');

        /** @var SuspiciousLoginAttemptsInLastHourEmail $sentMail */
        $sentMail = null;
        Piwik::addAction('Mail.send', function (Mail $mail) use (&$sentMail) {
            $sentMail = $mail;
        });

        $this->addFailedAttemptsWithLogin();

        $this->assertTrue($this->detection->isUserLoginBlocked('theuser'));
        $this->assertNotNull($sentMail);
        $this->assertInstanceOf(SuspiciousLoginAttemptsInLastHourEmail::class, $sentMail);
        $this->assertEquals(['someemail@email.com' => ''], $sentMail->getRecipients());
    }

    public function test_isUserLoginBlocked_returnsFalseIfThereAreLessThanTheThresholdNumOfAttempts()
    {
        $this->detection->setNow(Date::now());

        $this->addFailedAttemptsWithLogin();

        $this->assertFalse($this->detection->isUserLoginBlocked('anotheruser'));
    }

    private function addFailedLoginInPast($ipAddress, $minutes, $login = null)
    {
        $now = $this->detection->getNow();
        $this->detection->setNow($now->subPeriod($minutes, 'minute'));
        $this->detection->addFailedAttempt($ipAddress, $login);
        $this->detection->setNow($now);
    }

    private function addFailedAttemptsWithLogin()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addFailedLoginInPast('10.99.99.' . $i, $i * 5, 'theuser');
        }

        for ($i = 0; $i < 2; $i++) {
            $this->addFailedLoginInPast('10.99.99.' . $i, $i * 5, 'anotheruser');
        }

        $this->addFailedLoginInPast('10.99.88.1', 75, 'theuser');
    }

}
