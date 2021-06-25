<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\UsersManager;

use Piwik\Access;

/**
 * This class offers methods to filter a list of users, logins, or anything that is related to users/logins.
 *
 * * By default a super user is allowed to see all users.
 * * A user having admin access is allowed to see all other users that have view or admin access to the same access.
 * * A user not having any admin access is only allowed to see the own user.
 *
 * The methods in this class make sure to only return the data for logins / users the current user actually has
 * permission to see.
 *
 * FYI: The anonymous user is not treated in any special way. The anonymous user is a regular user with no access or
 * view access only and can only see itself.
 */
class UserAccessFilter
{
    /**
     * @var Model
     */
    private $model;

    /**
     * @var Access
     */
    private $access;

    /**
     * Holds a list of all idSites the current user has view access to. Only used for caching.
     * @var array
     */
    private $idSitesWithAdmin;

    /**
     * Holds a list of all user logins that have admin access. Only used for caching
     * @var array  Array ('loginName' => array(idsites...))
     */
    private $usersWithAdminAccess;

    /**
     * Holds a list of all user logins that have view access. Only used for caching
     * @var array  Array ('loginName' => array(idsites...))
     */
    private $usersWithViewAccess;

    public function __construct(Model $model, Access $access)
    {
        $this->model  = $model;
        $this->access = $access;
    }

    /**
     * Removes all array values where the current user has no permission to see the existence of a given login index/key.
     * @param array $arrayIndexedByLogin  An array that is indexed by login / usernames. Eg:
     *                                    array('username1' => 5, 'username2' => array(...), ...)
     * @return array
     */
    public function filterLoginIndexedArray($arrayIndexedByLogin)
    {
        if ($this->access->hasSuperUserAccess()) {
            return $arrayIndexedByLogin; // this part is not needed but makes it faster for super user.
        }

        $allowedLogins = $this->filterLogins(array_keys($arrayIndexedByLogin));

        return array_intersect_key($arrayIndexedByLogin, array_flip($allowedLogins));
    }

    /**
     * Removes all users from the list of the given users where the current user has no permission to see the existence
     * of that other user.
     * @param array $users  An array of arrays. Each inner array must have a key 'login'. Eg:
     *                      array(array('login' => 'username1'), array('login' => 'username2'), ...)
     * @return array
     */
    public function filterUsers($users)
    {
        if ($this->access->hasSuperUserAccess()) {
            return $users;
        }

        if (!$this->access->isUserHasSomeAdminAccess()) {
            // keep only own user if it is in the list
            foreach ($users as $user) {
                if ($this->isOwnLogin($user['login'])) {
                    return array($user);
                }
            }

            return array();
        }

        foreach ($users as $index => $user) {
            if (!$this->isNonSuperUserAllowedToSeeThisLogin($user['login'])) {
                unset($users[$index]);
            }
        }

        return array_values($users);
    }

    /**
     * Returns the given user only if the current user has permission to see the given user
     * @param array $user  An array containing a key 'login'
     * @return array|null
     */
    public function filterUser($user)
    {
        if (empty($user)) {
            return null;
        }

        if ($this->access->hasSuperUserAccess() || $this->isNonSuperUserAllowedToSeeThisLogin($user['login'])) {
            return $user;
        }

        return null;
    }

    /**
     * Removes all logins from the list of logins where the current user has no permission to see them.
     *
     * @param string[] $logins An array of logins / usernames. Eg array('username1', 'username2')
     * @return array
     */
    public function filterLogins($logins)
    {
        if ($this->access->hasSuperUserAccess()) {
            return $logins;
        }

        if (!$this->access->isUserHasSomeAdminAccess()) {
            // keep only own user if it is in the list
            foreach ($logins as $login) {
                if ($this->isOwnLogin($login)) {
                    return array($login);
                }
            }

            return array();
        }

        foreach ($logins as $index => $login) {
            if (!$this->isNonSuperUserAllowedToSeeThisLogin($login)) {
                unset($logins[$index]);
            }
        }

        return array_values($logins);
    }

    protected function isNonSuperUserAllowedToSeeThisLogin($login)
    {
        // we do not test for super user access here for better performance as we would otherwise test for access for
        // each single login in the other calling methods.
        return $this->hasAccessToSameSite($login) || $this->isOwnLogin($login);
    }

    private function isOwnLogin($login)
    {
        return $login === $this->access->getLogin();
    }

    private function hasAccessToSameSite($login)
    {
        // users is allowed to see other users having view or admin access to these sites
        if (!isset($this->idSitesWithAdmin)) {
            $this->idSitesWithAdmin     = $this->access->getSitesIdWithAdminAccess();
            $this->usersWithAdminAccess = $this->model->getUsersSitesFromAccess('admin');
            $this->usersWithViewAccess  = $this->model->getUsersSitesFromAccess('view');
        }

        return (
            (isset($this->usersWithViewAccess[$login]) && array_intersect($this->idSitesWithAdmin, $this->usersWithViewAccess[$login]))
           ||
            (isset($this->usersWithAdminAccess[$login]) && array_intersect($this->idSitesWithAdmin, $this->usersWithAdminAccess[$login]))
        );
    }
}
