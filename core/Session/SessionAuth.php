<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Session;

use Piwik\Auth;
use Piwik\AuthResult;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Plugins\UsersManager\Model;
use Piwik\Plugins\UsersManager\Model as UsersModel;
use Piwik\Session;
use Psr\Log\LoggerInterface;

/**
 * Validates already authenticated sessions.
 *
 * See {@link \Piwik\Session\SessionFingerprint} for more info.
 */
class SessionAuth implements Auth
{
    /**
     * For tests, since there's no actual session there.
     *
     * @var bool
     */
    private $shouldDestroySession;

    /**
     * @var UsersModel
     */
    private $userModel;

    /**
     * Set internally so it can be queried in FrontController.
     *
     * @var array
     */
    private $user;

    private $tokenAuth;

    public function __construct(UsersModel $userModel = null, $shouldDestroySession = true)
    {
        $this->userModel = $userModel ?: new UsersModel();
        $this->shouldDestroySession = $shouldDestroySession;
    }

    public function getName()
    {
        // empty
    }

    public function setTokenAuth($token_auth)
    {
        $this->tokenAuth = $token_auth;
    }

    public function getLogin()
    {
        if (isset($this->user['login'])) {
            return $this->user['login'];
        }
    }

    public function getTokenAuthSecret()
    {
        // empty
    }

    public function setLogin($login)
    {
        // empty
    }

    public function setPassword($password)
    {
        // empty
    }

    public function setPasswordHash($passwordHash)
    {
        // empty
    }

    public function authenticate()
    {
        $sessionFingerprint = new SessionFingerprint();
        $userModel = $this->userModel;

        $this->checkIfSessionFailedToRead();

        if ($this->isExpiredSession($sessionFingerprint)) {
            $sessionFingerprint->clear();
            return $this->makeAuthFailure();
        }

        $userForSession = $sessionFingerprint->getUser();
        if (empty($userForSession)) {
            return $this->makeAuthFailure();
        }

        $user = $userModel->getUser($userForSession);
        if (empty($user)
            || $user['login'] !== $userForSession // sanity check in case there's a bug in getUser()
        ) {
            return $this->makeAuthFailure();
        }

        $tsPasswordModified = !empty($user['ts_password_modified']) ? $user['ts_password_modified'] : null;
        if ($this->isSessionStartedBeforePasswordChange($sessionFingerprint, $tsPasswordModified)) {
            $this->destroyCurrentSession($sessionFingerprint);
            return $this->makeAuthFailure();
        }

        $this->updateSessionExpireTime($sessionFingerprint);

        if ($this->tokenAuth !== null
            && $this->tokenAuth !== false
            && $this->tokenAuth !== $sessionFingerprint->getSessionTokenAuth()) {
            return $this->makeAuthFailure();
        }

        if ($sessionFingerprint->getSessionTokenAuth()) {
            $tokenAuth = $sessionFingerprint->getSessionTokenAuth();
        } else {
            $tokenAuth = $this->userModel->generateRandomTokenAuth();
        }

        return $this->makeAuthSuccess($user, $tokenAuth);
    }

    private function isSessionStartedBeforePasswordChange(SessionFingerprint $sessionFingerprint, $tsPasswordModified)
    {
        // sanity check, make sure users can still login if the ts_password_modified column does not exist
        if ($tsPasswordModified === null) {
            return false;
        }

        // if the session start time doesn't exist for some reason, log the user out
        $sessionStartTime = $sessionFingerprint->getSessionStartTime();
        if (empty($sessionStartTime)) {
            return true;
        }

        return $sessionStartTime < Date::factory($tsPasswordModified)->getTimestampUTC();
    }

    private function makeAuthFailure()
    {
        return new AuthResult(AuthResult::FAILURE, null, null);
    }

    private function makeAuthSuccess($user, $tokenAuth)
    {
        $this->user = $user;
        $this->tokenAuth = $tokenAuth;

        $isSuperUser = (int) $user['superuser_access'];
        $code = $isSuperUser ? AuthResult::SUCCESS_SUPERUSER_AUTH_CODE : AuthResult::SUCCESS;

        return new AuthResult($code, $user['login'], $tokenAuth);
    }

    protected function initNewBlankSession(SessionFingerprint $sessionFingerprint)
    {
        // this user should be using a different session, so generate a new ID
        // NOTE: Zend_Session cannot be used since it will destroy the old
        // session.
        if ($this->shouldDestroySession) {
            session_regenerate_id();
        }

        // regenerating the ID will create a new session w/ a new ID, but will
        // copy over the existing session data. we want the new session for the
        // unauthorized user to be different, so we clear the session fingerprint.
        $sessionFingerprint->clear();
    }

    protected function destroyCurrentSession(SessionFingerprint $sessionFingerprint)
    {
        // Note: Piwik will attempt to create another session in the LoginController
        // when rendering the login form (the nonce for the form is stored in the session).
        // So we can't use Session::destroy() since Zend prohibits starting a new session
        // after session_destroy() is called. Instead we clear the session fingerprint for
        // the existing session and generate a new session. Both the old session &
        // new session should have no stored data.
        $sessionFingerprint->clear();
        if ($this->shouldDestroySession) {
            Session::regenerateId();
        }
    }

    public function getTokenAuth()
    {
        return $this->tokenAuth;
    }

    private function updateSessionExpireTime(SessionFingerprint $sessionFingerprint)
    {
        $sessionParams = session_get_cookie_params();

        // we update the session cookie to make sure expired session cookies are not available client side...
        $sessionCookieLifetime = Config::getInstance()->General['login_cookie_expire'];
        Session::writeCookie(
            session_name(), 
            session_id(), 
            time() + $sessionCookieLifetime,
            $sessionParams['path'],
            $sessionParams['domain'],
            $sessionParams['secure'],
            $sessionParams['httponly'],
            Session::getSameSiteCookieValue()
        );

        // ...and we also update the expiration time stored server side so we can prevent expired sessions from being reused
        $sessionFingerprint->updateSessionExpirationTime();
    }

    private function isExpiredSession(SessionFingerprint $sessionFingerprint)
    {
        $expirationTime = $sessionFingerprint->getExpirationTime();
        if (empty($expirationTime)) {
            return true;
        }

        $isExpired = Date::now()->getTimestampUTC() > $expirationTime;
        return $isExpired;
    }

    private function checkIfSessionFailedToRead()
    {
        if (Session\SaveHandler\DbTable::$wasSessionToLargeToRead) {
            StaticContainer::get(LoggerInterface::class)->warning(
                "Too much data stored in the session so it could not be read properly. If you were logged out, this is why.");
        }
    }
}
