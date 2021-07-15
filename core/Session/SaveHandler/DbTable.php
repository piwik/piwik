<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Session\SaveHandler;

use Piwik\Db;
use Piwik\DbHelper;
use Exception;
use Piwik\SettingsPiwik;
use Piwik\Updater\Migration;
use Zend_Session;
use Zend_Session_SaveHandler_Interface;

/**
 * Database-backed session save handler
 *
 */
class DbTable implements Zend_Session_SaveHandler_Interface
{
    const SESSION_DATA_MAX_LEN = 65535;
    public static $wasSessionToLargeToRead = false;

    protected $config;
    protected $maxLifetime;

    const TABLE_NAME = 'session';
    const TOKEN_HASH_ALGO = 'sha512';

    /**
     * @param array $config
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->maxLifetime = ini_get('session.gc_maxlifetime');
    }

    private function hashSessionId($id)
    {
        $salt = SettingsPiwik::getSalt();
        return hash(self::TOKEN_HASH_ALGO, $id . $salt);
    }


    /**
     * Destructor
     *
     * @return void
     */
    public function __destruct()
    {
        Zend_Session::writeClose();
    }

    /**
     * Open Session - retrieve resources
     *
     * @param string $save_path
     * @param string $name
     * @return boolean
     */
    public function open($save_path, $name)
    {
        Db::get()->getConnection();

        return true;
    }

    /**
     * Close Session - free resources
     *
     * @return boolean
     */
    public function close()
    {
        return true;
    }

    /**
     * Read session data
     *
     * @param string $id
     * @return string
     */
    public function read($id)
    {
        $id = $this->hashSessionId($id);
        $sql = 'SELECT ' . $this->config['dataColumn'] . ' FROM ' . $this->config['name']
            . ' WHERE ' . $this->config['primary'] . ' = ?'
            . ' AND ' . $this->config['modifiedColumn'] . ' + ' . $this->config['lifetimeColumn'] . ' >= ?';

        $result = $this->fetchOne($sql, array($id, time()));

        // if the session was too large to read, we want to notice this rather than just fail to read the session.
        // otherwise, the user would just be logged out and we would have no idea why. the value we set is read
        // later in SessionAuth so we can correctly log the issue.
        if (strlen($result) >= self::SESSION_DATA_MAX_LEN) {
            self::$wasSessionToLargeToRead = true;
        }

        if (!$result) {
            $result = '';
        }

        return $result;
    }

    private function fetchOne($sql, $bind)
    {
        try {
            $result = Db::get()->fetchOne($sql, $bind);
        } catch (Exception $e) {
            if (Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_NOT_EXISTS)) {
                $this->migrateToDbSessionTable();
                $result = Db::get()->fetchOne($sql, $bind);
            } else {
                throw $e;
            }
        }
        return $result;
    }

    private function query($sql, $bind)
    {
        try {
            $result = Db::get()->query($sql, $bind);
        } catch (Exception $e) {
            if (Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_NOT_EXISTS)) {
                $this->migrateToDbSessionTable();
                $result = Db::get()->query($sql, $bind);
            } else {
                throw $e;
            }
        }
        return $result;
    }

    /**
     * Write Session - commit data to resource
     *
     * @param string $id
     * @param mixed $data
     * @return boolean
     */
    public function write($id, $data)
    {
        $id = $this->hashSessionId($id);

        $sql = 'INSERT INTO ' . $this->config['name']
            . ' (' . $this->config['primary'] . ','
            . $this->config['modifiedColumn'] . ','
            . $this->config['lifetimeColumn'] . ','
            . $this->config['dataColumn'] . ')'
            . ' VALUES (?,?,?,?)'
            . ' ON DUPLICATE KEY UPDATE '
            . $this->config['modifiedColumn'] . ' = ?,'
            . $this->config['lifetimeColumn'] . ' = ?,'
            . $this->config['dataColumn'] . ' = ?';

        $this->query($sql, array($id, time(), $this->maxLifetime, $data, time(), $this->maxLifetime, $data));

        return true;
    }

    /**
     * Destroy Session - remove data from resource for
     * given session id
     *
     * @param string $id
     * @return boolean
     */
    public function destroy($id)
    {
        $id = $this->hashSessionId($id);

        $sql = 'DELETE FROM ' . $this->config['name'] . ' WHERE ' . $this->config['primary'] . ' = ?';

        $this->query($sql, array($id));

        return true;
    }

    /**
     * Garbage Collection - remove old session data older
     * than $maxlifetime (in seconds)
     *
     * @param int $maxlifetime timestamp in seconds
     * @return bool  always true
     */
    public function gc($maxlifetime)
    {
        $sql = 'DELETE FROM ' . $this->config['name']
            . ' WHERE ' . $this->config['modifiedColumn'] . ' + ' . $this->config['lifetimeColumn'] . ' < ?';

        $this->query($sql, array(time()));

        return true;
    }

    private function migrateToDbSessionTable()
    {
        // happens when updating from Piwik 1.4 or earlier to Matomo 3.7+
        // in this case on update it will change the session handler to dbtable, but it hasn't performed
        // the DB updates just yet which means the session table won't be available as it was only added in
        // Piwik 1.5 => results in a sql error the session table does not exist
        try {
            $sql = DbHelper::getTableCreateSql(self::TABLE_NAME);
            Db::query($sql);
        } catch (Exception $e) {
            if (!Db::get()->isErrNo($e, Migration\Db::ERROR_CODE_TABLE_EXISTS)) {
                throw $e;
            }
        }
    }

}
