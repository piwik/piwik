<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\UsersManager\tests;

use Piwik\Access\Capability\PublishLiveContainer;
use Piwik\Access\Role\View;
use Piwik\Access\Role\Write;
use Piwik\Auth\Password;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\API;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\UsersManager;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Access\Capability\TagManagerWrite;
use Piwik\Access\Capability\UseCustomTemplates;
use Piwik\Access\Role\Admin;

/**
 * @group UsersManager
 * @group APITest
 * @group Plugins
 */
class APITest extends IntegrationTestCase
{
    /**
     * @var API
     */
    private $api;

    /**
     * @var Model
     */
    private $model;
    
    private $login = 'userLogin';

    public function setUp()
    {
        parent::setUp();

        $this->api = API::getInstance();
        $this->model = new Model();

        FakeAccess::$superUser = true;

        Fixture::createWebsite('2014-01-01 00:00:00');
        Fixture::createWebsite('2014-01-01 00:00:00');
        Fixture::createWebsite('2014-01-01 00:00:00');
        $this->api->addUser($this->login, 'password', 'userlogin@password.de');
    }

    public function test_setUserAccess_ShouldTriggerRemoveSiteAccessEvent_IfAccessToAWebsiteIsRemoved()
    {
        $eventTriggered = false;
        $self = $this;
        Piwik::addAction('UsersManager.removeSiteAccess', function ($login, $idSites) use (&$eventTriggered, $self) {
            $eventTriggered = true;
            $self->assertEquals($self->login, $login);
            $self->assertEquals(array(1, 2), $idSites);
        });

        $this->api->setUserAccess($this->login, 'noaccess', array(1, 2));

        $this->assertTrue($eventTriggered, 'UsersManager.removeSiteAccess event was not triggered');
    }

    public function test_setUserAccess_ShouldNotTriggerRemoveSiteAccessEvent_IfAccessIsAdded()
    {
        $eventTriggered = false;
        Piwik::addAction('UsersManager.removeSiteAccess', function () use (&$eventTriggered) {
            $eventTriggered = true;
        });

        $this->api->setUserAccess($this->login, 'admin', array(1, 2));

        $this->assertFalse($eventTriggered, 'UsersManager.removeSiteAccess event was triggered but should not');
    }

    public function test_getAllUsersPreferences_isEmpty_whenNoPreference()
    {
        $preferences = $this->api->getAllUsersPreferences(array('preferenceName'));
        $this->assertEmpty($preferences);
    }

    public function test_getAllUsersPreferences_isEmpty_whenNoPreferenceAndMultipleRequested()
    {
        $preferences = $this->api->getAllUsersPreferences(array('preferenceName', 'otherOne'));
        $this->assertEmpty($preferences);
    }

    public function test_getUserPreference_ShouldReturnADefaultPreference_IfNoneIsSet()
    {
        $siteId = $this->api->getUserPreference($this->login, API::PREFERENCE_DEFAULT_REPORT);
        $this->assertEquals('1', $siteId);
    }

    public function test_getUserPreference_ShouldReturnASetreference_IfNoneIsSet()
    {
        $this->api->setUserPreference($this->login, API::PREFERENCE_DEFAULT_REPORT, 5);

        $siteId = $this->api->getUserPreference($this->login, API::PREFERENCE_DEFAULT_REPORT);
        $this->assertEquals('5', $siteId);
    }

    public function test_initUserPreferenceWithDefault_ShouldSaveTheDefaultPreference_IfPreferenceIsNotSet()
    {
        // make sure there is no value saved so it will use default preference
        $siteId = Option::get($this->getPreferenceId(API::PREFERENCE_DEFAULT_REPORT));
        $this->assertFalse($siteId);

        $this->api->initUserPreferenceWithDefault($this->login, API::PREFERENCE_DEFAULT_REPORT);

        // make sure it did save the preference
        $siteId = Option::get($this->getPreferenceId(API::PREFERENCE_DEFAULT_REPORT));
        $this->assertEquals('1', $siteId);
    }

    public function test_initUserPreferenceWithDefault_ShouldNotSaveTheDefaultPreference_IfPreferenceIsAlreadySet()
    {
        // set value so there will already be a default
        Option::set($this->getPreferenceId(API::PREFERENCE_DEFAULT_REPORT), '999');

        $siteId = Option::get($this->getPreferenceId(API::PREFERENCE_DEFAULT_REPORT));
        $this->assertEquals('999', $siteId);

        $this->api->initUserPreferenceWithDefault($this->login, API::PREFERENCE_DEFAULT_REPORT);

        // make sure it did not save the preference
        $siteId = Option::get($this->getPreferenceId(API::PREFERENCE_DEFAULT_REPORT));
        $this->assertEquals('999', $siteId);
    }

    public function test_getAllUsersPreferences_shouldGetMultiplePreferences()
    {
        $user2 = 'userLogin2';
        $user3 = 'userLogin3';
        $this->api->addUser($user2, 'password', 'userlogin2@password.de');
        $this->api->setUserPreference($user2, 'myPreferenceName', 'valueForUser2');
        $this->api->setUserPreference($user2, 'RandomNOTREQUESTED', 'RandomNOTREQUESTED');

        $this->api->addUser($user3, 'password', 'userlogin3@password.de');
        $this->api->setUserPreference($user3, 'myPreferenceName', 'valueForUser3');
        $this->api->setUserPreference($user3, 'otherPreferenceHere', 'otherPreferenceVALUE');
        $this->api->setUserPreference($user3, 'RandomNOTREQUESTED', 'RandomNOTREQUESTED');

        $expected = array(
            $user2 => array(
                'myPreferenceName' => 'valueForUser2'
            ),
            $user3 => array(
                'myPreferenceName' => 'valueForUser3',
                'otherPreferenceHere' => 'otherPreferenceVALUE',
            ),
        );
        $result = $this->api->getAllUsersPreferences(array('myPreferenceName', 'otherPreferenceHere', 'randomDoesNotExist'));

        $this->assertSame($expected, $result);
    }

    public function test_getAllUsersPreferences_whenLoginContainsUnderscore()
    {
        $user2 = 'user_Login2';
        $this->api->addUser($user2, 'password', 'userlogin2@password.de');
        $this->api->setUserPreference($user2, 'myPreferenceName', 'valueForUser2');
        $this->api->setUserPreference($user2, 'RandomNOTREQUESTED', 'RandomNOTREQUESTED');

        $expected = array(
            $user2 => array(
                'myPreferenceName' => 'valueForUser2'
            ),
        );
        $result = $this->api->getAllUsersPreferences(array('myPreferenceName', 'otherPreferenceHere', 'randomDoesNotExist'));

        $this->assertSame($expected, $result);
    }

    /**
     * @expectedException \Exception
     */
    public function test_setUserPreference_throws_whenPreferenceNameContainsUnderscore()
    {
        $user2 = 'userLogin2';
        $this->api->addUser($user2, 'password', 'userlogin2@password.de');
        $this->api->setUserPreference($user2, 'ohOH_myPreferenceName', 'valueForUser2');
    }

    public function test_updateUser()
    {
        $this->api->updateUser($this->login, 'newPassword', 'email@example.com', 'newAlias', false);

        $user = $this->api->getUser($this->login);

        $this->assertSame('email@example.com', $user['email']);
        $this->assertSame('newAlias', $user['alias']);

        $passwordHelper = new Password();

        $this->assertTrue($passwordHelper->verify(UsersManager::getPasswordHash('newPassword'), $user['password']));
    }

    public function test_getSitesAccessFromUser_forSuperUser()
    {
        $user2 = 'userLogin2';
        $this->api->addUser($user2, 'password', 'userlogin2@password.de');

        // new user doesn't have access to anything
        $access = $this->api->getSitesAccessFromUser($user2);
        $this->assertEmpty($access);

        $this->api->setSuperUserAccess($user2, true);

        // super user has admin access for every site
        $access = $this->api->getSitesAccessFromUser($user2);
        $expected = array(
            array(
                'site' => 1,
                'access' => 'admin'
            ),
            array(
                'site' => 2,
                'access' => 'admin'
            ),
            array(
                'site' => 3,
                'access' => 'admin'
            ),
        );
        $this->assertEquals($expected, $access);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionMultipleRoleSet
     */
    public function test_setUserAccess_MultipleRolesCannotBeSet()
    {
        $this->api->setUserAccess($this->login, array('view', 'admin'), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionNoRoleSet
     */
    public function test_setUserAccess_NeedsAtLeastOneRole()
    {
        $this->api->setUserAccess($this->login, array(TagManagerWrite::ID), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_setUserAccess_NeedsAtLeastOneRoleAsString()
    {
        $this->api->setUserAccess($this->login, TagManagerWrite::ID, array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_setUserAccess_InvalidCapability()
    {
        $this->api->setUserAccess($this->login, array('admin', 'foobar'), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionNoRoleSet
     */
    public function test_setUserAccess_NeedsAtLeastOneRoleNoneGiven()
    {
        $this->api->setUserAccess($this->login, array(), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  UsersManager_ExceptionAnonymousAccessNotPossible
     */
    public function test_setUserAccess_CannotSetAdminToAnonymous()
    {
        $this->api->setUserAccess('anonymous', 'admin', array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage  UsersManager_ExceptionAnonymousAccessNotPossible
     */
    public function test_setUserAccess_CannotSetWriteToAnonymous()
    {
        $this->api->setUserAccess('anonymous', 'write', array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionUserDoesNotExist
     */
    public function test_setUserAccess_UserDoesNotExist()
    {
        $this->api->setUserAccess('foobar', Admin::ID, array(1));
    }

    public function test_setUserAccess_SetRoleAndCapabilities()
    {
        $access = array(TagManagerWrite::ID, View::ID, UseCustomTemplates::ID);
        $this->api->setUserAccess($this->login, $access, array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);

        $expected = array(
            array('site' => '1', 'access' => 'view'),
            array('site' => '1', 'access' => TagManagerWrite::ID),
            array('site' => '1', 'access' => UseCustomTemplates::ID),
        );
        $this->assertEquals($expected, $access);
    }

    public function test_setUserAccess_SetRoleAsString()
    {
        $this->api->setUserAccess($this->login, View::ID, array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);
        $this->assertEquals(array(array('site' => '1', 'access' => 'view')), $access);
    }

    public function test_setUserAccess_SetRoleAsArray()
    {
        $this->api->setUserAccess($this->login, array(View::ID), array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);
        $this->assertEquals(array(array('site' => '1', 'access' => 'view')), $access);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_addCapabilities_failsWhenNotCapabilityIsGivenAsString()
    {
        $this->api->addCapabilities($this->login, View::ID, array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_addCapabilities_failsWhenNotCapabilityIsGivenAsArray()
    {
        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, View::ID), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionUserDoesNotExist
     */
    public function test_addCapabilities_failsWhenUserDoesNotExist()
    {
        $this->api->addCapabilities('foobar', array(TagManagerWrite::ID), array(1));
    }

    public function test_addCapabilities_DoesNotAddSameCapabilityTwice()
    {
        $addAccess = array(TagManagerWrite::ID, View::ID, UseCustomTemplates::ID);
        $this->api->setUserAccess($this->login, $addAccess, array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);

        $expected = array(
            array('site' => '1', 'access' => 'view'),
            array('site' => '1', 'access' => TagManagerWrite::ID),
            array('site' => '1', 'access' => UseCustomTemplates::ID),
        );
        $this->assertEquals($expected, $access);

        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, UseCustomTemplates::ID), array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);
        $this->assertEquals($expected, $access);

        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, PublishLiveContainer::ID, UseCustomTemplates::ID), array(1));

        $expected[] = array('site' => '1', 'access' => PublishLiveContainer::ID);
        $access = $this->model->getSitesAccessFromUser($this->login);
        $this->assertEquals($expected, $access);
    }

    public function test_addCapabilities_DoesNotAddCapabilityToUserWithNoRole()
    {
        $access = $this->model->getSitesAccessFromUser($this->login);

        $this->assertEquals(array(), $access);

        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, UseCustomTemplates::ID), array(1));

        $this->assertEquals(array(), $access);
    }

    public function test_addCapabilities_DoesNotAddCapabilitiesWhichAreIncludedInRoleAlready()
    {
        $this->api->setUserAccess($this->login, Write::ID, array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);

        $expected = array(
            array('site' => '1', 'access' => 'write'),
        );
        $this->assertEquals($expected, $access);

        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, UseCustomTemplates::ID), array(1));

        $expected[] = array('site' => '1', 'access' => UseCustomTemplates::ID);
        $access = $this->model->getSitesAccessFromUser($this->login);

        // did not add tagmanagerwrite
        $this->assertEquals($expected, $access);
    }

    public function test_addCapabilities_DoesAddCapabilitiesWhichAreNotIncludedInRoleYetAlready()
    {
        $this->api->setUserAccess($this->login, Admin::ID, array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);

        $expected = array(
            array('site' => '1', 'access' => 'admin'),
        );
        $this->assertEquals($expected, $access);

        $this->api->addCapabilities($this->login, array(TagManagerWrite::ID, PublishLiveContainer::ID, UseCustomTemplates::ID), array(1));

        $access = $this->model->getSitesAccessFromUser($this->login);
        $this->assertEquals($expected, $access);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_removeCapabilities_failsWhenNotCapabilityIsGivenAsString()
    {
        $this->api->removeCapabilities($this->login, View::ID, array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionAccessValues
     */
    public function test_removeCapabilities_failsWhenNotCapabilityIsGivenAsArray()
    {
        $this->api->removeCapabilities($this->login, array(TagManagerWrite::ID, View::ID), array(1));
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage UsersManager_ExceptionUserDoesNotExist
     */
    public function test_removeCapabilities_failsWhenUserDoesNotExist()
    {
        $this->api->removeCapabilities('foobar', array(TagManagerWrite::ID), array(1));
    }

    public function test_removeCapabilities()
    {
        $addAccess = array(View::ID, TagManagerWrite::ID, UseCustomTemplates::ID, PublishLiveContainer::ID);
        $this->api->setUserAccess($this->login, $addAccess, array(1));

        $access = $this->getAccessInSite($this->login, 1);
        $this->assertEquals($addAccess, $access);

        $this->api->removeCapabilities($this->login, array(UseCustomTemplates::ID, TagManagerWrite::ID), 1);

        $access = $this->getAccessInSite($this->login, 1);
        $this->assertEquals(array(View::ID, PublishLiveContainer::ID), $access);
    }

    private function getAccessInSite($login, $idSite)
    {
        $access = $this->model->getSitesAccessFromUser($login);
        $ids = array();
        foreach ($access as $entry) {
            if ($entry['site'] == $idSite) {
                $ids[] = $entry['access'];
            }
        }
        return $ids;
    }

    private function getPreferenceId($preferenceName)
    {
        return $this->login . '_' . $preferenceName;
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess()
        );
    }
}
