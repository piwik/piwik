<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\UsersManager\tests\Integration;

use Piwik\Access;
use Piwik\Auth\Password;
use Piwik\Common;
use Piwik\Option;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Plugins\UsersManager\API;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\NewsletterSignup;
use Piwik\Plugins\UsersManager\UsersManager;
use Piwik\Plugins\UsersManager\UserUpdater;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\Mock\FakeAccess;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Exception;


/**
 * @group UsersManagerTest
 * @group UsersManager
 * @group Plugins
 */
class UsersManagerTest extends IntegrationTestCase
{
    const DATETIME_REGEX = '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/';

    /**
     * @var API
     */
    private $api;

    /**
     * @var Model
     */
    private $model;

    private $backupIdentity;

    public function setUp(): void
    {
        parent::setUp();

        \Piwik\Plugin\Manager::getInstance()->loadPlugin('UsersManager');
        \Piwik\Plugin\Manager::getInstance()->installLoadedPlugins();

        $this->addSites(4);

        // setup the access layer
        FakeAccess::setIdSitesView(array(1, 2));
        FakeAccess::setIdSitesAdmin(array(3, 4));

        //finally we set the user as a Super User by default
        FakeAccess::$superUser = true;
        FakeAccess::$superUserLogin = 'superusertest';
        $this->backupIdentity = FakeAccess::$identity;

        $this->api   = API::getInstance();
        $this->model = new Model();
    }

    public function tearDown(): void
    {
        FakeAccess::$identity = $this->backupIdentity;
        parent::tearDown();
    }

    private function _flatten($sitesAccess)
    {
        $result = array();

        foreach ($sitesAccess as $siteAccess) {
            $result[$siteAccess['site']] = $siteAccess['access'];
        }

        return $result;
    }

    private function _checkUserHasNotChanged($user, $newPassword, $newEmail = null)
    {
        if (is_null($newEmail)) {
            $newEmail = $user['email'];
        }

        $userAfter = $this->model->getUser($user["login"]);

        $this->assertArrayHasKey('date_registered', $userAfter);
        $this->assertRegExp(self::DATETIME_REGEX, $userAfter['date_registered']);

        $this->assertArrayHasKey('ts_password_modified', $userAfter);
        $this->assertRegExp(self::DATETIME_REGEX, $userAfter['date_registered']);

        $this->assertArrayHasKey('password', $userAfter);
        $this->assertNotEmpty($userAfter['password']);

        unset($userAfter['date_registered']);
        unset($userAfter['ts_password_modified']);
        unset($userAfter['password']);

        // implicitly checks password!
        $user['email']            = $newEmail;
        $user['superuser_access'] = 0;
        $user['twofactor_secret'] = '';

        unset($user['password']);

        $this->assertEquals($user, $userAfter);
    }

    /**
     * bad password => exception
     */
    public function testUpdateUserBadpasswd()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionInvalidPassword');

        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        try {
            $this->api->updateUser($login, "pas");
        } catch (Exception $expected) {
            $this->_checkUserHasNotChanged($user, $user['password']);
            throw $expected;
        }
    }

    /**
     * Dataprovider
     */
    public function getAddUserInvalidLoginData()
    {
        return array(
            array(9, "password", "email@email.com"), // wrong login / integer => exception
            array("gegag'ggea'", "password", "email@email.com"), // wrong login / too short => exception
            array("gegag11gge&", "password", "email@email.com"), // wrong login / too long => exception
            array("geg'ag11gge@", "password", "email@email.com"), // wrong login / bad characters => exception
        );
    }

    /**
     * @dataProvider getAddUserInvalidLoginData
     */
    public function testAddUserWrongLogin($userLogin, $password, $email)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionInvalidLogin');

        $this->api->addUser($userLogin, $password, $email);
    }

    public function testAddUserExistingLogin()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionLoginExists');

        $this->api->addUser("test", "password", "email@email.com");
        $this->api->addUser("test", "password2", "em2ail@email.com");
    }

    /**
     * @see https://github.com/piwik/piwik/issues/8548
     */
    public function testAddUserExistingLoginCaseInsensitive()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionLoginExists');

        $this->api->addUser("test", "password", "email@email.com");
        $this->api->addUser("TeSt", "password2", "em2ail@email.com");
    }

    /**
     * Dataprovider for wrong password tests
     */
    public function getWrongPasswordTestData()
    {
        return array(
            array("geggeqgeqag", "pas", "email@email.com"), // too short -> exception
            array("geggeqgeqag", "", "email@email.com"), // empty -> exception
        );
    }

    /**
     * @dataProvider getWrongPasswordTestData
     */
    public function testAddUserWrongPassword($userLogin, $password, $email)
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionInvalidPassword');

        $this->api->addUser($userLogin, $password, $email);
    }

    public function testAddUserWrongEmail()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('mail');

        $this->api->addUser('geggeqgeqag', 'geqgeagae', "ema il@email.com");
    }

    /**
     * long password => should work
     */
    public function testAddUserLongPassword()
    {
        $login = "geggeqgeqag";
        $this->api->addUser($login, "geqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaegeqgeagaeg", "mgeagi@geq.com");
        $user = $this->api->getUser($login);
        $this->assertEquals($login, $user['login']);
    }

    /**
     * normal test case
     */
    public function testAddUser()
    {
        $login = "geggeq55eqag";
        $password = "mypassword";
        $email = "mgeag4544i@geq.com";

        $time = time();
        $this->api->addUser($login, $password, $email);
        $user = $this->model->getUser($login);

        // check that the date registered is correct
        $this->assertTrue($time <= strtotime($user['date_registered']) && strtotime($user['date_registered']) <= time(),
            "the date_registered " . strtotime($user['date_registered']) . " is different from the time() " . time());

        // check that password and token are properly set
        $this->assertEquals(60, strlen($user['password']));

        // check that all fields are the same
        $this->assertEquals($login, $user['login']);
        $this->assertEquals($email, $user['email']);

        $passwordHelper = new Password();

        $this->assertTrue($passwordHelper->verify(UsersManager::getPasswordHash($password), $user['password']));
    }

    public function test_addUser_shouldAllowAdminUsersToCreateUsers()
    {
        FakeAccess::$superUser = false;
        FakeAccess::$idSitesAdmin = [1];

        $login = "geggeq55eqag";
        $password = "mypassword";
        $email = "mgeag4544i@geq.com";

        $this->api->addUser($login, $password, $email, false, 1);

        FakeAccess::$superUser = true;
        $user = $this->api->getUser($login);

        $this->assertEquals($login, $user['login']);
        $this->assertEquals($email, $user['email']);

        FakeAccess::$superUser = true;

        $access = $this->api->getSitesAccessFromUser($login);
        $this->assertEquals([
            ['site' => 1, 'access' => 'view'],
        ], $access);
    }

    public function test_addUser_shouldNotAllowAdminUsersToCreateUsers_WithNoInitialSiteWithAccess()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_AddUserNoInitialAccessError');

        FakeAccess::$superUser = false;
        FakeAccess::$idSitesAdmin = [1];

        $this->api->addUser('userLogin2', 'password', 'userlogin2@email.com');
    }

    public function test_addUser_shouldNotAllowAdminUsersToCreateUsersWithAccessToSite_ThatAdminUserDoesNotHaveAccessTo()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('checkUserHasAdminAccess Fake exception');

        FakeAccess::$superUser = false;
        FakeAccess::$idSitesAdmin = [2];

        $this->api->addUser('userLogin2', 'password', 'userlogin2@email.com', false, 1);
    }

    public function testDeleteUserDoesntExist()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->addUser("geggeqgeqag", "geqgeagae", "test@test.com");
        $this->api->deleteUser("geggeqggnew");
    }

    public function testDeleteUserEmptyUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->deleteUser("");
    }

    public function testDeleteUserNullUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->deleteUser(null);
    }

    public function testDeleteUser_ShouldFail_InCaseTheUserIsTheOnlyRemainingSuperUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionDeleteOnlyUserWithSuperUserAccess');

        //add user and set some rights
        $this->api->addUser("regularuser", "geqgeagae1", "test1@test.com");
        $this->api->addUser("superuser", "geqgeagae2", "test2@test.com");
        $userUpdater = new UserUpdater();
        $userUpdater->setSuperUserAccessWithoutCurrentPassword('superuser', true);

        // delete the user
        $this->api->deleteUser("superuser");
    }

    /**
     * normal case, user deleted
     */
    public function testDeleteUser()
    {
        Fixture::createSuperUser();

        $this->addSites(3);

        //add user and set some rights
        $this->api->addUser("geggeqgeqag", "geqgeagae", "test@test.com");
        $this->api->setUserAccess("geggeqgeqag", "view", array(1, 2));
        $this->api->setUserAccess("geggeqgeqag", "admin", array(1, 3));

        // check rights are set
        $this->assertNotEquals(array(), $this->api->getSitesAccessFromUser("geggeqgeqag"));

        // delete the user
        $this->api->deleteUser("geggeqgeqag");

        // try to get it, it should raise an exception
        try {
            $this->api->getUser("geggeqgeqag");
            $this->fail("Exception not raised.");
        } catch (Exception $expected) {
            $this->assertRegExp("(UsersManager_ExceptionUserDoesNotExist)", $expected->getMessage());
        }

        // add the same user
        $this->api->addUser("geggeqgeqag", "geqgeagae", "test@test.com");

        //checks access have been deleted
        //to do so we recreate the same user login and check if the rights are still there
        $this->assertEquals(array(), $this->api->getSitesAccessFromUser("geggeqgeqag"));
    }

    public function testDeleteUser_deletesUserOptions()
    {
        Fixture::createSuperUser();
        $this->api->addUser("geggeqgeqag", "geqgeagae", "test@test.com");
        Option::set(NewsletterSignup::NEWSLETTER_SIGNUP_OPTION . 'geggeqgeqag', 'yes');

        $this->api->deleteUser("geggeqgeqag");

        $option = Option::get(NewsletterSignup::NEWSLETTER_SIGNUP_OPTION . 'geggeqgeqag');
        $this->assertFalse($option);
    }

    public function testGetUserNoUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        // try to get it, it should raise an exception
        $this->api->getUser("geggeqgeqag");
    }

    /**
     * normal case
     */
    public function test_GetUser()
    {
        $login = "geggeq55eqag";
        $password = "mypassword";
        $email = "mgeag4544i@geq.com";

        $this->api->addUser($login, $password, $email);
        $user = $this->model->getUser($login);

        // check that all fields are the same
        $this->assertEquals($login, $user['login']);
        self::assertIsString($user['password']);
        self::assertIsString($user['date_registered']);
        $this->assertEquals($email, $user['email']);
    }

    /**
     * no user => empty array
     */
    public function testGetUsersNoUser()
    {
        $this->assertEquals($this->api->getUsers(), array());
    }

    /**
     * normal case
     * as well as selecting specific user names, comma separated
     */
    public function testGetUsers()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        $this->api->addUser("geggeqge632ge56a4qag", "geqgegeagae", "tesggt@tesgt.com");
        $this->api->addUser("geggeqgeqagqegg", "geqgeaggggae", "tesgggt@tesgt.com");

        $users = $this->api->getUsers();
        $users = $this->_removeNonTestableFieldsFromUsers($users);
        $user1 = array('login' => "gegg4564eqgeqag", 'email' => "tegst@tesgt.com", 'superuser_access' => 0, 'uses_2fa' => false);
        $user2 = array('login' => "geggeqge632ge56a4qag", 'email' => "tesggt@tesgt.com", 'superuser_access' => 0, 'uses_2fa' => false);
        $user3 = array('login' => "geggeqgeqagqegg", 'email' => "tesgggt@tesgt.com", 'superuser_access' => 0, 'uses_2fa' => false);
        $expectedUsers = array($user1, $user2, $user3);
        $this->assertEquals($expectedUsers, $users);
        $this->assertEquals(array($user1), $this->_removeNonTestableFieldsFromUsers($this->api->getUsers('gegg4564eqgeqag')));
        $this->assertEquals(array($user1, $user2), $this->_removeNonTestableFieldsFromUsers($this->api->getUsers('gegg4564eqgeqag,geggeqge632ge56a4qag')));
    }

    public function testGetUsers_withViewAccess_shouldThrowAnException()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('checkUserHasSomeAdminAccess Fake exception');

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        $this->api->addUser("geggeqge632ge56a4qag", "geqgegeagae", "tesggt@tesgt.com");
        $this->api->addUser("geggeqgeqagqegg", "geqgeaggggae", "tesgggt@tesgt.com");

        FakeAccess::clearAccess($superUser = false, $admin = array(), $view = array(1), 'gegg4564eqgeqag');

        $this->api->getUsers();
    }

    protected function _removeNonTestableFieldsFromUsers($users)
    {
        foreach ($users as &$user) {
            unset($user['password']);
            unset($user['token_auth']);
            unset($user['date_registered']);
            unset($user['ts_password_modified']);
        }
        return $users;
    }

    /**
     * normal case
     */
    public function testGetUsersLogin()
    {
        $this->api->addUser('gegg4564eqgeqag', 'geqgegagae', 'tegst@tesgt.com');
        $this->api->addUser("geggeqge632ge56a4qag", "geqgegeagae", "tesggt@tesgt.com");
        $this->api->addUser("geggeqgeqagqegg", "geqgeaggggae", "tesgggt@tesgt.com");

        $logins = $this->api->getUsersLogin();

        $this->assertEquals(array("gegg4564eqgeqag", "geggeqge632ge56a4qag", "geggeqgeqagqegg"), $logins);
    }

    public function testGetUserLoginFromUserEmail()
    {
        $this->api->addUser('gegg4564eqgeqag', 'geqgegagae', 'tegst@tesgt.com');
        $this->api->addUser("geggeqge632ge56a4qag", "geqgegeagae", "tesggt@tesgt.com");
        $this->api->addUser("geggeqgeqagqegg", "geqgeaggggae", "tesgggt@tesgt.com");

        $this->assertSame('gegg4564eqgeqag', $this->api->getUserLoginFromUserEmail('tegst@tesgt.com'));
        $this->assertSame('geggeqge632ge56a4qag', $this->api->getUserLoginFromUserEmail('tesggt@tesgt.com'));
        // test camel case should still find user
        $this->assertSame('geggeqge632ge56a4qag', $this->api->getUserLoginFromUserEmail('teSGgT@tesgt.com'));
    }

    public function testGetUserLoginFromUserEmail_shouldThrowException_IfUserDoesNotExist()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->getUserLoginFromUserEmail('unknownUser@teSsgt.com');
    }

    public function testGetUserLoginFromUserEmail_shouldThrowException_IfUserDoesNotHaveAtLeastAdminPermission()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('checkUserHasSomeAdminAccess Fake exception');

        FakeAccess::clearAccess($superUser = false, $admin =array(), $view = array(1));
        $this->api->getUserLoginFromUserEmail('tegst@tesgt.com');
    }

    public function testSetUserAccessNoLogin()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        FakeAccess::clearAccess($superUser = false, $admin =array(1), $view = array());
        $this->api->setUserAccess("nologin", "view", 1);
    }

    public function testSetUserAccessWrongAccessSpecified()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionAccessValues');

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        FakeAccess::clearAccess($superUser = false, $admin =array(1), $view = array());
        $this->api->setUserAccess("gegg4564eqgeqag", "viewnotknown", 1);
    }

    public function testSetUserAccess_ShouldFail_SuperUserAccessIsNotAllowed()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionAccessValues');

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        FakeAccess::clearAccess($superUser = false, $admin =array(1), $view = array());
        $this->api->setUserAccess("gegg4564eqgeqag", "superuser", 1);
    }

    public function testSetUserAccess_ShouldFail_IfLoginIsConfigSuperUserLogin()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        FakeAccess::clearAccess($superUser = false, $admin =array(1), $view = array());
        $this->api->setUserAccess('superusertest', 'view', 1);
    }

    public function testSetUserAccess_ShouldFail_IfLoginIsUserWithSuperUserAccess()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserHasSuperUserAccess');

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        $userUpdater = new UserUpdater();
        $userUpdater->setSuperUserAccessWithoutCurrentPassword('gegg4564eqgeqag', true);

        FakeAccess::clearAccess($superUser = false, $idSitesAdmin = array(1));
        $this->api->setUserAccess('gegg4564eqgeqag', 'view', 1);
    }

    /**
     * idsites = all => apply access to all websites with admin access
     */
    public function testSetUserAccessIdsitesIsAll()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");

        FakeAccess::$superUser = false;

        $this->api->setUserAccess("gegg4564eqgeqag", "view", "all");

        FakeAccess::$superUser = true;
        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);

        /** @var Access $accessInstance */
        $accessInstance = self::$fixture->piwikEnvironment->getContainer()->get('Piwik\Access');

        FakeAccess::$superUser = false;
        $this->assertEquals(array_keys($access), $accessInstance->getSitesIdWithAdminAccess());

        // we want to test the case for which we have actually set some rights
        // if this is not OK then change the setUp method and add some admin rights for some websites
        $this->assertGreaterThan(0, count(array_keys($access)));
    }

    /**
     * idsites = all AND user is superuser=> apply access to all websites
     */
    public function testSetUserAccessIdsitesIsAllSuperuser()
    {
        FakeAccess::$superUser = true;

        $this->addSites(1);
        $idSites = [1, 2, 3, 4, 5];

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        $this->api->setUserAccess("gegg4564eqgeqag", "view", "all");

        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);
        $this->assertEquals($idSites, array_keys($access));
    }

    public function testSetUserAccess_ShouldNotBeAbleToSetAnyAccess_IfIdSitesIsEmpty()
    {
        $this->expectException(\Exception::class);

        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");

        $this->api->setUserAccess("gegg4564eqgeqag", "view", array());
    }

    /**
     * normal case, access set for only one site
     */
    public function testSetUserAccessIdsitesOneSite()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        $idSites = $this->addSites(1);

        $this->api->setUserAccess("gegg4564eqgeqag", "view", $idSites);

        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);
        $this->assertEquals($idSites, array_keys($access));
    }

    /**
     * normal case, access set for multiple sites
     */
    public function testSetUserAccessIdsitesMultipleSites()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");
        list($id1, $id2, $id3) = $this->addSites(3);

        $this->api->setUserAccess("gegg4564eqgeqag", "view", array($id1, $id3));

        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);
        $this->assertEquals(array($id1, $id3), array_keys($access));
    }

    /**
     * normal case, string idSites comma separated access set for multiple sites
     */
    public function testSetUserAccessWithIdSitesIsStringCommaSeparated()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");

        $this->api->setUserAccess("gegg4564eqgeqag", "view", "1,3");

        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);
        $this->assertEquals(array(1, 3), array_keys($access));
    }

    /**
     * normal case, set different access to different websites for one user
     */
    public function testSetUserAccessMultipleCallDistinctAccessSameUser()
    {
        $this->api->addUser("gegg4564eqgeqag", "geqgegagae", "tegst@tesgt.com");

        list($id1, $id2) = $this->addSites(2);

        $this->api->setUserAccess("gegg4564eqgeqag", "view", array($id1));
        $this->api->setUserAccess("gegg4564eqgeqag", "admin", array($id2));

        $access = $this->api->getSitesAccessFromUser("gegg4564eqgeqag");
        $access = $this->_flatten($access);
        $this->assertEquals(array($id1 => 'view', $id2 => 'admin'), $access);
    }

    /**
     * normal case, set different access to different websites for multiple users
     */
    public function testSetUserAccessMultipleCallDistinctAccessMultipleUser()
    {
        $this->api->addUser("user1", "geqgegagae", "tegst@tesgt.com");
        $this->api->addUser("user2", "geqgegagae", "tegst2@tesgt.com");

        list($id1, $id2, $id3) = $this->addSites(3);

        $this->api->setUserAccess("user1", "view", array($id1, $id2));
        $this->api->setUserAccess("user2", "admin", array($id1));
        $this->api->setUserAccess("user2", "view", array($id3, $id2));

        $access1 = $this->api->getSitesAccessFromUser("user1");
        $access1 = $this->_flatten($access1);
        $access2 = $this->api->getSitesAccessFromUser("user2");
        $access2 = $this->_flatten($access2);
        $wanted1 = array($id1 => 'view', $id2 => 'view',);
        $wanted2 = array($id1 => 'admin', $id2 => 'view', $id3 => 'view');

        $this->assertEquals($wanted1, $access1);
        $this->assertEquals($wanted2, $access2);

        $access1 = $this->api->getUsersAccessFromSite($id1);
        $access2 = $this->api->getUsersAccessFromSite($id2);
        $access3 = $this->api->getUsersAccessFromSite($id3);
        $wanted1 = array('user1' => 'view', 'user2' => 'admin',);
        $wanted2 = array('user1' => 'view', 'user2' => 'view');
        $wanted3 = array('user2' => 'view');

        $this->assertEquals($wanted1, $access1);
        $this->assertEquals($wanted2, $access2);
        $this->assertEquals($wanted3, $access3);

        $access1 = $this->api->getUsersSitesFromAccess('view');
        $access2 = $this->api->getUsersSitesFromAccess('admin');
        $wanted1 = array('user1' => array($id1, $id2), 'user2' => array($id2, $id3));
        $wanted2 = array('user2' => array($id1));

        $this->assertEquals($wanted1, $access1);
        $this->assertEquals($wanted2, $access2);

        // Test getUsersWithSiteAccess
        $users = $this->api->getUsersWithSiteAccess($id1, $access = 'view');
        $this->assertEquals(1, count($users));
        $this->assertEquals('user1', $users[0]['login']);
        $users = $this->api->getUsersWithSiteAccess($id2, $access = 'view');
        $this->assertEquals(2, count($users));
        $users = $this->api->getUsersWithSiteAccess($id1, $access = 'admin');
        $this->assertEquals(1, count($users));
        $this->assertEquals('user2', $users[0]['login']);
        $users = $this->api->getUsersWithSiteAccess($id3, $access = 'admin');
        $this->assertEquals(0, count($users));
    }

    /**
     * we set access for one user for one site several times and check that it is updated
     */
    public function testSetUserAccessMultipleCallOverwriteSingleUserOneSite()
    {
        $this->api->addUser("user1", "geqgegagae", "tegst@tesgt.com");

        list($id1, $id2) = $this->addSites(2);

        $this->api->setUserAccess("user1", "view", array($id1, $id2));
        $this->api->setUserAccess("user1", "admin", array($id1));

        $access1 = $this->api->getSitesAccessFromUser("user1");
        $access1 = $this->_flatten($access1);
        $wanted1 = array($id1 => 'admin', $id2 => 'view',);

        $this->assertEquals($wanted1, $access1);
    }

    public function testSetSuperUserAccess_ShouldFail_IfUserHasNotSuperUserPermission()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('checkUserHasSuperUserAccess Fake exception');

        $pwd = $this->createCurrentUser();

        FakeAccess::$superUser= false;
        $this->api->setSuperUserAccess('nologin', false, $pwd);
    }

    public function testSetSuperUserAccess_ShouldFail_IfUserWithGivenLoginDoesNotExist()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $pwd = $this->createCurrentUser();
        $this->api->setSuperUserAccess('nologin', false, $pwd);
    }

    public function testSetSuperUserAccess_ShouldFail_IfUserIsAnonymous()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionEditAnonymous');

        $pwd = $this->createCurrentUser();
        $this->api->setSuperUserAccess('anonymous', true, $pwd);
    }

    public function testSetSuperUserAccess_ShouldFail_IfUserIsOnlyRemainingUserWithSuperUserAccess()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionRemoveSuperUserAccessOnlySuperUser');

        $pwd = $this->createCurrentUser();

        $this->api->addUser('login1', 'password1', 'test@example.com', false);
        $this->api->setSuperUserAccess('login1', true, $pwd);

        $this->api->setSuperUserAccess('login1', false, $pwd);
    }

    public function testSetSuperUserAccess_ShouldDeleteAllExistingAccessEntries()
    {
        $pwd = $this->createCurrentUser();

        list($id1, $id2) = $this->addSites(2);
        $this->api->addUser('login1', 'password1', 'test@example.com', false);
        $this->api->setUserAccess('login1', 'view', array($id1));
        $this->api->setUserAccess('login1', 'admin', array($id2));

        // verify user has access before setting Super User access
        $access = $this->_flatten($this->api->getSitesAccessFromUser('login1'));
        $this->assertEquals(array($id1 => 'view', $id2 => 'admin'), $access);

        $this->api->setSuperUserAccess('login1', true, $pwd);

        // verify no longer any access
        $this->assertEquals(array(), $this->model->getSitesAccessFromUser('login1'));
    }

    public function testSetSuperUserAccess_ShouldAddAndRemoveSuperUserAccessOnlyForGivenLogin()
    {
        $pwd = $this->createCurrentUser();

        $this->api->addUser('login1', 'password1', 'test1@example.com', false);
        $this->api->addUser('login2', 'password2', 'test2@example.com', false);
        $this->api->addUser('login3', 'password3', 'test3@example.com', false);

        $this->api->setSuperUserAccess('login2', true, $pwd);

        // test add Super User access
        $users = $this->api->getUsers();

        $this->assertEquals(0, $users[0]['superuser_access']);
        $this->assertEquals(1, $users[1]['superuser_access']);
        $this->assertEquals('login2', $users[1]['login']);
        $this->assertEquals(0, $users[2]['superuser_access']);

        // should also accept string '1' to add Super User access
        $this->api->setSuperUserAccess('login1', '1', $pwd);
        // test remove Super User access
        $this->api->setSuperUserAccess('login2', false, $pwd);

        $users = $this->api->getUsers();
        $this->assertEquals(1, $users[0]['superuser_access']);
        $this->assertEquals('login1', $users[0]['login']);
        $this->assertEquals(0, $users[1]['superuser_access']);
        $this->assertEquals(0, $users[2]['superuser_access']);

        $this->api->setSuperUserAccess('login3', true, $pwd);
        // should also accept string '0' to remove Super User access
        $this->api->setSuperUserAccess('login1', '0', $pwd);

        $users = $this->api->getUsers();
        $this->assertEquals(0, $users[0]['superuser_access']);
        $this->assertEquals(0, $users[1]['superuser_access']);
        $this->assertEquals('login3', $users[2]['login']);
        $this->assertEquals(1, $users[2]['superuser_access']);
    }

    public function testGetSitesAccessFromUserWrongUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->getSitesAccessFromUser("user1");
    }

    public function testGetUsersAccessFromSiteWrongIdSite()
    {
        $this->expectException(\Exception::class);

        FakeAccess::$superUser = false;
        $this->api->getUsersAccessFromSite(1);
    }

    public function testGetUsersSitesFromAccessWrongSite()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionAccessValues');

        $this->api->getUsersSitesFromAccess('unknown');
    }

    public function testUpdateUserNonExistingLogin()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionUserDoesNotExist');

        $this->api->updateUser("lolgin", "password");
    }

    public function testUpdateUserFailsNoCurrentPassword()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ConfirmWithPassword');

        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        FakeAccess::$identity = 'login';
        $this->api->updateUser($login, "passowordOK", false, false, "");
    }

    public function testUpdateUserFailsWrongCurrentPassword()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_CurrentPasswordNotCorrect');

        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        FakeAccess::$identity = 'login';
        $this->api->updateUser($login, "passowordOK", false, false, "geqgeag");
    }

    public function testUpdateUserFailsWrongCurrentPassword_requiresThePasswordOfCurrentLoggedInUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_CurrentPasswordNotCorrect');

        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);
        // currently logged in is a super user and not "login". therefore the password of "login" won't work
        $this->api->updateUser($login, "passowordOK", false, false, "geqgeag");
    }

    /**
     * no email => keep old ones
     */
    public function testUpdateUserNoEmail()
    {
        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        FakeAccess::$identity = 'login';
        $this->api->updateUser($login, "passowordOK", null, false, "geqgeagae");

        $this->_checkUserHasNotChanged($user, "passowordOK", null);
    }

    /**
     * check to modify as the user
     */
    public function testAddUserIAmTheUser()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('UsersManager_ExceptionLoginExists');

        FakeAccess::$identity = 'login';
        $this->testUpdateUserNoEmail();
    }

    /**
     * check to modify as being another user => exception
     */
    public function testUpdateUserIAmNotTheUser()
    {
        $this->expectException(\Exception::class);

        FakeAccess::$identity = 'login2';
        FakeAccess::$superUser = false;
        $this->testUpdateUserNoEmail();
    }

    /**
     * normal case, reused in other tests
     */
    public function testUpdateUser()
    {
        $login = "login";
        $user  = array('login'    => $login,
                       'password' => "geqgeagae",
                       'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        FakeAccess::$identity = 'login';
        $this->api->updateUser($login, "passowordOK", "email@geaga.com", false, "geqgeagae");

        $this->_checkUserHasNotChanged($user, "passowordOK", "email@geaga.com");
    }

    public function testGetUserByEmailInvalidMail()
    {
        $this->expectException(\Exception::class);

        $this->api->getUserByEmail('email@test.com');
    }

    public function testGetUserByEmail()
    {
        $user = array('login'    => "login",
                      'password' => "geqgeagae",
                      'email'    => "test@test.com");

        $this->api->addUser($user['login'], $user['password'], $user['email']);

        $userByMail = $this->api->getUserByEmail($user['email']);

        $this->assertEquals($user['login'], $userByMail['login']);
        $this->assertEquals($user['email'], $userByMail['email']);
    }

    public function testGetUserPreferenceDefault()
    {
        $this->addSites(1);
        $defaultReportPref = API::PREFERENCE_DEFAULT_REPORT;
        $defaultReportDatePref = API::PREFERENCE_DEFAULT_REPORT_DATE;

        $this->assertEquals(1, $this->api->getUserPreference($defaultReportPref, 'someUser'));
        $this->assertEquals('yesterday', $this->api->getUserPreference($defaultReportDatePref, 'someUser'));
    }

    public function testGetAvailableRoles()
    {
        $this->addSites(1);
        $roles = $this->api->getAvailableRoles();
        $expected = array(
            array (
                'id' => 'view',
                'name' => 'UsersManager_PrivView',
                'description' => 'UsersManager_PrivViewDescription',
                'helpUrl' => 'https://matomo.org/faq/general/faq_70/'
            ), array (
                'id' => 'write',
                'name' => 'UsersManager_PrivWrite',
                'description' => 'UsersManager_PrivWriteDescription',
                'helpUrl' => 'https://matomo.org/faq/general/faq_26910'
             ),
            array (
                'id' => 'admin',
                'name' => 'UsersManager_PrivAdmin',
                'description' => 'UsersManager_PrivAdminDescription',
                'helpUrl' => 'https://matomo.org/faq/general/faq_69/',
             )
        );
        $this->assertEquals($expected, $roles);
    }

    public function testGetAvailableCapabilities()
    {
        $this->addSites(1);
        $this->assertSame(array(
            0 => array(
                'id' => 'tagmanager_write',
                'name' => 'UsersManager_PrivWrite',
                'description' => 'TagManager_CapabilityWriteDescription',
                'helpUrl' => '',
                'includedInRoles' => array ('write', 'admin'),
                'category' => 'TagManager_TagManager',
            ),
            1 => array (
                'id' => 'tagmanager_publish_live_container',
                 'name' => 'TagManager_CapabilityPublishLiveContainer',
                'description' => 'TagManager_CapabilityPublishLiveContainerDescription',
                'helpUrl' => '',
                'includedInRoles' => array ('admin'),
                'category' => 'TagManager_TagManager',
            ),
            2 => array (
                'id' => 'tagmanager_use_custom_templates',
                'name' => 'TagManager_CapabilityUseCustomTemplates',
                'description' => 'TagManager_CapabilityUseCustomTemplateDescription',
                'helpUrl' => '',
                'includedInRoles' => array ('admin'),
                'category' => 'TagManager_TagManager',
            )
        ), $this->api->getAvailableCapabilities());
    }

    private function addSites($numberOfSites)
    {
        $idSites = array();

        for ($index = 0; $index < $numberOfSites; $index++) {
            $name      = "test" . ($index + 1);
            $idSites[] = APISitesManager::getInstance()->addSite($name, array("http://piwik.net", "http://piwik.com/test/"));
        }

        return $idSites;
    }

    public function provideContainerConfig()
    {
        return array(
            'Piwik\Access' => new FakeAccess()
        );
    }

    private function assertUserNotExists($login)
    {
        try {
            $this->api->getUser($login);
            $this->fail("User $login still exists!");
        } catch (Exception $expected) {
            $this->assertRegExp("(UsersManager_ExceptionUserDoesNotExist)", $expected->getMessage());
        }
    }

    private function createCurrentUser()
    {
        $identity = FakeAccess::$identity;
        FakeAccess::$identity = 'lskfjs';

        $pwd = 'testpwd';

        try {
            $this->api->addUser($identity, $pwd, 'someuser@email.com');
        } finally {
            FakeAccess::$identity = $identity;
        }

        return $pwd;
    }
}
