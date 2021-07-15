<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace PHPUnit\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Piwik\Notification;
use Piwik\Notification\Manager;

class ManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Manager::cancelAllNotifications();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Manager::cancelAllNotifications();
    }

    public function test_notify_addsNotificationToNotificationArray()
    {
        $notification = new Notification('abcdefg');
        $result = Manager::notify('testid', $notification);

        $this->assertTrue($result);

        $notificationsInArray = Manager::getPendingInMemoryNotifications();

        $expected = [
            'testid' => $notification,
        ];
        $this->assertEquals($expected, $notificationsInArray);
    }

    /**
     * @dataProvider getTestDataForNotify
     */
    public function test_notify_throwsWhenAnInvalidIdIsUsed($id, $expectedMessage)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);

        Manager::notify($id, new Notification('sldjfksdf'));
    }

    public function getTestDataForNotify()
    {
        return [
            ['', 'Notification ID is empty.'],
            ['aabcd a a k', 'Invalid Notification ID given. Only word characters (AlNum + underscore) allowed.'],
            ['a23%$%', 'Invalid Notification ID given. Only word characters (AlNum + underscore) allowed.'],
        ];
    }

    public function test_notify_doesNotAddNotificationIfThereAreAlreadyMoreThanThirty()
    {
        for ($i = 0; $i < Manager::MAX_NOTIFICATIONS_IN_SESSION; ++$i) {
            Manager::notify('not' . $i, new Notification('message ' . $i));
        }

        $notificationsInArray = Manager::getPendingInMemoryNotifications();

        $notification = new Notification('abcdefg');
        $result = Manager::notify('testid', $notification);

        $this->assertFalse($result);

        $notificationsInArray = Manager::getPendingInMemoryNotifications();
        $this->assertCount(30, $notificationsInArray);
        $this->assertArrayNotHasKey('testid', $notificationsInArray);
    }
}