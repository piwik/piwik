<?php

use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Piwik\Log;
use Piwik\Plugins\Monolog\Handler\FileHandler;
use Piwik\Plugins\Monolog\Handler\LogCaptureHandler;

return array(

    'Monolog\Logger' => DI\create('Monolog\Logger')
        ->constructor('piwik', DI\get('log.handlers'), DI\get('log.processors')),

    'Psr\Log\LoggerInterface' => DI\get('Monolog\Logger'),

    'log.handler.classes' => array(
        'file'     => 'Piwik\Plugins\Monolog\Handler\FileHandler',
        'screen'   => 'Piwik\Plugins\Monolog\Handler\WebNotificationHandler',
        'database' => 'Piwik\Plugins\Monolog\Handler\DatabaseHandler',
        'errorlog' => '\Monolog\Handler\ErrorLogHandler',
        'syslog' => '\Monolog\Handler\SyslogHandler',
    ),
    'log.handlers' => DI\factory(function (\DI\Container $c) {
        if ($c->has('ini.log.log_writers')) {
            $writerNames = $c->get('ini.log.log_writers');
        } else {
            return array();
        }

        $classes = $c->get('log.handler.classes');

        $logConfig = $c->get(\Piwik\Config::class)->log;
        $enableFingersCrossed = isset($logConfig['enable_fingers_crossed_handler']) && $logConfig['enable_fingers_crossed_handler'] == 1;
        $fingersCrossedStopBuffering = isset($logConfig['fingers_crossed_stop_buffering_on_activation']) && $logConfig['fingers_crossed_stop_buffering_on_activation'] == 1;
        $enableLogCaptureHandler = isset($logConfig['enable_log_capture_handler']) && $logConfig['enable_log_capture_handler'] == 1;

        $isLogBufferingAllowed = !\Piwik\Common::isPhpCliMode()
            || \Piwik\SettingsServer::isArchivePhpTriggered()
            || \Piwik\CliMulti::isCliMultiRequest();

        $writerNames = array_map('trim', $writerNames);

        $writers = [];
        foreach ($writerNames as $writerName) {
            if ($writerName === 'screen'
                && \Piwik\Common::isPhpCliMode()
                && !defined('PIWIK_TEST_MODE')
                && !\Piwik\SettingsServer::isTrackerApiRequest()
            ) {
                continue; // screen writer is only valid for web requests (except for tracker CLI requests)
            }

            if (isset($classes[$writerName])) {
                // wrap the handler in FingersCrossedHandler if we can and this isn't the screen handler

                /** @var \Monolog\Handler\HandlerInterface $handler */
                $handler = $c->make($classes[$writerName]);
                if ($enableFingersCrossed
                    && $writerName !== 'screen'
                    && $handler instanceof \Monolog\Handler\AbstractHandler
                    && $isLogBufferingAllowed
                ) {
                    $passthruLevel = $handler->getLevel();

                    $handler->setLevel(Logger::DEBUG);

                    $handler = new \Monolog\Handler\FingersCrossedHandler($handler, $activationStrategy = null, $bufferSize = 0,
                        $bubble = true, $fingersCrossedStopBuffering, $passthruLevel);
                }

                $writers[$writerName] = $handler;
            }
        }

        if ($enableLogCaptureHandler
            && $isLogBufferingAllowed
        ) {
            $writers[] = $c->get(LogCaptureHandler::class);
        }

        // we always add the null handler to make sure there is at least one handler specified. otherwise Monolog will
        // add a stream handler to stderr w/ a DEBUG log level, which will cause archiving requests to fail.
        if (empty($writers)) {
            $writers[] = $c->get(\Monolog\Handler\NullHandler::class);
        }

        return array_values($writers);
    }),

    'log.processors' => array(
        DI\get('Piwik\Plugins\Monolog\Processor\SprintfProcessor'),
        DI\get('Piwik\Plugins\Monolog\Processor\ClassNameProcessor'),
        DI\get('Piwik\Plugins\Monolog\Processor\RequestIdProcessor'),
        DI\get('Piwik\Plugins\Monolog\Processor\ExceptionToTextProcessor'),
        DI\get('Monolog\Processor\PsrLogMessageProcessor'),
        DI\get('Piwik\Plugins\Monolog\Processor\TokenProcessor'),
    ),

    'Piwik\Plugins\Monolog\Handler\FileHandler' => DI\create()
        ->constructor(DI\get('log.file.filename'), DI\get('log.level.file'))
        ->method('setFormatter', DI\get('log.lineMessageFormatter.file')),
    
    '\Monolog\Handler\ErrorLogHandler' => DI\create()
        ->constructorParameter('level', DI\get('log.level.errorlog'))
        ->method('setFormatter', DI\get('log.lineMessageFormatter.file')),

    '\Monolog\Handler\SyslogHandler' => DI\create()
        ->constructor(DI\get('log.syslog.ident'), 'syslog', DI\get('log.level.syslog'))

    'Piwik\Plugins\Monolog\Handler\DatabaseHandler' => DI\create()
        ->constructor(DI\get('log.level.database'))
        ->method('setFormatter', DI\get('log.lineMessageFormatter')),

    'Piwik\Plugins\Monolog\Handler\WebNotificationHandler' => DI\create()
        ->constructor(DI\get('log.level.screen'))
        ->method('setFormatter', DI\get('log.lineMessageFormatter')),

    'log.level' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level')) {
            $level = strtoupper($c->get('ini.log.log_level'));
            if (!empty($level) && defined('Piwik\Log::'.strtoupper($level))) {
                return Log::getMonologLevel(constant('Piwik\Log::'.strtoupper($level)));
            }
        }

        return Logger::WARNING;
    }),

    'log.level.file' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level_file')) {
            $level = Log::getMonologLevelIfValid($c->get('ini.log.log_level_file'));
            if ($level !== null) {
                return $level;
            }
        }
        return $c->get('log.level');
    }),

    'log.level.screen' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level_screen')) {
            $level = Log::getMonologLevelIfValid($c->get('ini.log.log_level_screen'));
            if ($level !== null) {
                return $level;
            }
        }
        return $c->get('log.level');
    }),

    'log.level.database' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level_database')) {
            $level = Log::getMonologLevelIfValid($c->get('ini.log.log_level_database'));
            if ($level !== null) {
                return $level;
            }
        }
        return $c->get('log.level');
    }),

    'log.level.syslog' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level_syslog')) {
            $level = Log::getMonologLevelIfValid($c->get('ini.log.log_level_syslog'));
            if ($level !== null) {
                return $level;
            }
        }
        return $c->get('log.level');
    }),

    'log.level.errorlog' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.log_level_errorlog')) {
            $level = Log::getMonologLevelIfValid($c->get('ini.log.log_level_errorlog'));
            if ($level !== null) {
                return $level;
            }
        }
        return $c->get('log.level');
    }),

    'log.file.filename' => DI\factory(function (ContainerInterface $c) {
        $logPath = $c->get('ini.log.logger_file_path');

        // Absolute path
        if (strpos($logPath, '/') === 0) {
            return $logPath;
        }

        // Remove 'tmp/' at the beginning
        if (strpos($logPath, 'tmp/') === 0) {
            $logPath = substr($logPath, strlen('tmp'));
        }

        if (empty($logPath)) {
            // Default log file
            $logPath = '/logs/piwik.log';
        }

        $logPath = $c->get('path.tmp') . $logPath;
        if (is_dir($logPath)) {
            $logPath .= '/piwik.log';
        }

        return $logPath;
    }),
    
    'log.syslog.ident' => DI\factory(function (ContainerInterface $c) {
        $ident = $c->get('ini.log.logger_syslog_ident');
        if (empty($ident)) {
            $ident = 'matomo';
        }
        return $ident;
    }),

    'Piwik\Plugins\Monolog\Formatter\LineMessageFormatter' => DI\create('Piwik\Plugins\Monolog\Formatter\LineMessageFormatter')
                                                                ->constructor(DI\get('log.short.format')),
    'log.lineMessageFormatter' => DI\create('Piwik\Plugins\Monolog\Formatter\LineMessageFormatter')
        ->constructor(DI\get('log.short.format')),

    'log.lineMessageFormatter.file' => DI\autowire('Piwik\Plugins\Monolog\Formatter\LineMessageFormatter')
        ->constructor(DI\get('log.trace.format'))
        ->constructorParameter('allowInlineLineBreaks', false),

    'log.short.format' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.string_message_format')) {
            return $c->get('ini.log.string_message_format');
        }
        return '%level% %tag%[%datetime%] %message%';
    }),

    'log.trace.format' => DI\factory(function (ContainerInterface $c) {
        if ($c->has('ini.log.string_message_format_trace')) {
            return $c->get('ini.log.string_message_format_trace');
        }
        return '%level% %tag%[%datetime%] %message% %trace%';
    }),

    'archiving.performance.handlers' => function (ContainerInterface $c) {
        $logFile = trim($c->get('ini.Debug.archive_profiling_log'));
        if (empty($logFile)) {
            return [new \Monolog\Handler\NullHandler()];
        }

        $fileHandler = new FileHandler($logFile, \Psr\Log\LogLevel::INFO);
        $fileHandler->setFormatter($c->get('log.lineMessageFormatter.file'));
        return [$fileHandler];
    },

    'archiving.performance.logger' => DI\create(Logger::class)
        ->constructor('matomo.archiving.performance', DI\get('archiving.performance.handlers'), DI\get('log.processors')),
);
