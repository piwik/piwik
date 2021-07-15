<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik;

use Monolog\Logger;
use Piwik\Container\StaticContainer;
use Psr\Log\LoggerInterface;

/**
 * Logging utility class.
 *
 * Log entries are made with a message and log level. The logging utility will tag each
 * log entry with the name of the plugin that's doing the logging. If no plugin is found,
 * the name of the current class is used.
 *
 * You can log messages using one of the public static functions (eg, 'error', 'warning',
 * 'info', etc.).
 *
 * Currently, Piwik supports the following logging backends:
 *
 * - **screen**: logging to the screen
 * - **file**: logging to a file
 * - **database**: logging to Piwik's MySQL database
 *
 * Messages logged in the console will always be logged to the console output.
 *
 * ### Logging configuration
 *
 * The logging utility can be configured by manipulating the INI config options in the
 * `[log]` section.
 *
 * The following configuration options can be set:
 *
 * - `log_writers[]`: This is an array of log writer IDs. The three log writers provided
 *                    by Piwik core are **file**, **screen**, **database**, **errorlog**, 
 *                    and **syslog**. You can get more by installing plugins. The default 
 *                    value is **screen**.
 * - `log_level`: The current log level. Can be **ERROR**, **WARN**, **INFO**, **DEBUG**,
 *                or **VERBOSE**. Log entries made with a log level that is as or more
 *                severe than the current log level will be outputted. Others will be
 *                ignored. The default level is **WARN**.
 * - `logger_file_path`: For the file log writer, specifies the path to the log file
 *                       to log to or a path to a directory to store logs in. If a
 *                       directory, the file name is piwik.log. Can be relative to
 *                       Piwik's root dir or an absolute path. Defaults to **tmp/logs**.
 * - `logger_syslog_ident`: If configured to log to syslog, mark them with this 
 *                          identifier string.  This acts as an easy-to-find tag in 
 *                          the syslog.
 *
 *
 * @deprecated Inject and use Psr\Log\LoggerInterface instead of this class.
 * @see \Psr\Log\LoggerInterface
 */
class Log extends Singleton
{
    // log levels
    const NONE = 0;
    const ERROR = 1;
    const WARN = 2;
    const INFO = 3;
    const DEBUG = 4;
    const VERBOSE = 5;

    // config option names
    const LOG_LEVEL_CONFIG_OPTION = 'log_level';
    const LOG_WRITERS_CONFIG_OPTION = 'log_writers';
    const LOGGER_FILE_PATH_CONFIG_OPTION = 'logger_file_path';
    const STRING_MESSAGE_FORMAT_OPTION = 'string_message_format';

    /**
     * The backtrace string to use when testing.
     *
     * @var string
     */
    public static $debugBacktraceForTests;

    /**
     * Singleton instance.
     *
     * @var Log
     */
    private static $instance;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = StaticContainer::get(__CLASS__);
        }
        return self::$instance;
    }
    public static function unsetInstance()
    {
        self::$instance = null;
    }
    public static function setSingletonInstance($instance)
    {
        self::$instance = $instance;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Logs a message using the ERROR log level.
     *
     * @param string $message The log message. This can be a sprintf format string.
     * @param ... mixed Optional sprintf params.
     * @api
     *
     * @deprecated Inject and call Psr\Log\LoggerInterface::error() instead.
     * @see \Psr\Log\LoggerInterface::error()
     */
    public static function error($message /* ... */)
    {
        self::logMessage(Logger::ERROR, $message, array_slice(func_get_args(), 1));
    }

    /**
     * Logs a message using the WARNING log level.
     *
     * @param string $message The log message. This can be a sprintf format string.
     * @param ... mixed Optional sprintf params.
     * @api
     *
     * @deprecated Inject and call Psr\Log\LoggerInterface::warning() instead.
     * @see \Psr\Log\LoggerInterface::warning()
     */
    public static function warning($message /* ... */)
    {
        self::logMessage(Logger::WARNING, $message, array_slice(func_get_args(), 1));
    }

    /**
     * Logs a message using the INFO log level.
     *
     * @param string $message The log message. This can be a sprintf format string.
     * @param ... mixed Optional sprintf params.
     * @api
     *
     * @deprecated Inject and call Psr\Log\LoggerInterface::info() instead.
     * @see \Psr\Log\LoggerInterface::info()
     */
    public static function info($message /* ... */)
    {
        self::logMessage(Logger::INFO, $message, array_slice(func_get_args(), 1));
    }

    /**
     * Logs a message using the DEBUG log level.
     *
     * @param string $message The log message. This can be a sprintf format string.
     * @param ... mixed Optional sprintf params.
     * @api
     *
     * @deprecated Inject and call Psr\Log\LoggerInterface::debug() instead.
     * @see \Psr\Log\LoggerInterface::debug()
     */
    public static function debug($message /* ... */)
    {
        self::logMessage(Logger::DEBUG, $message, array_slice(func_get_args(), 1));
    }

    /**
     * Logs a message using the VERBOSE log level.
     *
     * @param string $message The log message. This can be a sprintf format string.
     * @param ... mixed Optional sprintf params.
     * @api
     *
     * @deprecated Inject and call Psr\Log\LoggerInterface::debug() instead (the verbose level doesn't exist in the PSR standard).
     * @see \Psr\Log\LoggerInterface::debug()
     */
    public static function verbose($message /* ... */)
    {
        self::logMessage(Logger::DEBUG, $message, array_slice(func_get_args(), 1));
    }

    /**
     * @param int $logLevel
     * @deprecated Will be removed, log levels are now applied on each Monolog handler.
     */
    public function setLogLevel($logLevel)
    {
    }

    /**
     * @deprecated Will be removed, log levels are now applied on each Monolog handler.
     */
    public function getLogLevel()
    {
    }

    private function doLog($level, $message, $parameters = array())
    {
        // To ensure the compatibility with PSR-3, the message must be a string
        if ($message instanceof \Exception) {
            $parameters['exception'] = $message;
            $message = $message->getMessage();
        }

        if (is_object($message) || is_array($message) || is_resource($message)) {
            $this->logger->warning('Trying to log a message that is not a string', array(
                'exception' => new \InvalidArgumentException('Trying to log a message that is not a string')
            ));
            return;
        }

        $this->logger->log($level, $message, $parameters);
    }

    private static function logMessage($level, $message, $parameters)
    {
        self::getInstance()->doLog($level, $message, $parameters);
    }

    public static function getMonologLevel($level)
    {
        switch ($level) {
            case self::ERROR:
                return Logger::ERROR;
            case self::WARN:
                return Logger::WARNING;
            case self::INFO:
                return Logger::INFO;
            case self::DEBUG:
                return Logger::DEBUG;
            case self::VERBOSE:
                return Logger::DEBUG;
            case self::NONE:
            default:
                // Highest level possible, need to do better in the future...
                return Logger::EMERGENCY;
        }
    }

    public static function getMonologLevelIfValid($level)
    {
        $level = strtoupper($level);
        if (!empty($level) && defined('Piwik\Log::'.strtoupper($level))) {
            return self::getMonologLevel(constant('Piwik\Log::'.strtoupper($level)));
        }
        return null;
    }
}
