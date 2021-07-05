<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik;

use Exception;
use Monolog\Handler\FingersCrossedHandler;
use Piwik\Application\Environment;
use Piwik\Config\ConfigNotFoundException;
use Piwik\Container\StaticContainer;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\Monolog\Handler\FailureLogMessageDetector;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Console extends Application
{
    /**
     * @var Environment
     */
    private $environment;

    public function __construct(Environment $environment = null)
    {
        $this->setServerArgsIfPhpCgi();

        parent::__construct('Matomo', Version::VERSION);

        $this->environment = $environment;

        $option = new InputOption('matomo-domain',
            null,
            InputOption::VALUE_OPTIONAL,
            'Matomo URL (protocol and domain) eg. "http://matomo.example.org"'
        );

        $this->getDefinition()->addOption($option);

        $option = new InputOption('xhprof',
            null,
            InputOption::VALUE_NONE,
            'Enable profiling with XHProf'
        );

        $this->getDefinition()->addOption($option);
    }

    public function renderException($e, $output)
    {
        $logHandlers = StaticContainer::get('log.handlers');

        $hasFingersCrossed = false;
        foreach ($logHandlers as $handler) {
            if ($handler instanceof FingersCrossedHandler) {
                $hasFingersCrossed = true;
                continue;
            }
        }

        if ($hasFingersCrossed
            && $output->getVerbosity() < OutputInterface::VERBOSITY_VERBOSE
        ) {
            $output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
        }

        parent::renderException($e, $output);
    }

    public function doRun(InputInterface $input, OutputInterface $output)
    {
        try {
            return $this->doRunImpl($input, $output);
        } catch (\Exception $ex) {
            try {
                print "generate safe mode output\n";@ob_flush();
                FrontController::generateSafeModeOutputFromException($ex);
            } catch (\Exception $ex) {
                // ignore, we re-throw the original exception, not a wrapped one
            }

            throw $ex;
        }
    }

    private function doRunImpl(InputInterface $input, OutputInterface $output)
    {
        if ($input->hasParameterOption('--xhprof')) {
            Profiler::setupProfilerXHProf(true, true);
        }

        $this->initMatomoHost($input);
        $this->initEnvironment($output);
        $this->initLoggerOutput($output);

        try {
            self::initPlugins();
        } catch (ConfigNotFoundException $e) {
            // Piwik not installed yet, no config file?
            Log::warning($e->getMessage());
        }

        $this->initAuth();

        $commands = $this->getAvailableCommands();

        foreach ($commands as $command) {
            $this->addCommandIfExists($command);
        }

        $exitCode = null;

        /**
         * @ignore
         */
        Piwik::postEvent('Console.doRun', [&$exitCode, $input, $output]);

        if ($exitCode === null) {
            $self = $this;
            $exitCode = Access::doAsSuperUser(function () use ($input, $output, $self) {
                return
                    call_user_func(array($self, 'Symfony\Component\Console\Application::doRun'), $input, $output);
            });
        }

        $importantLogDetector = StaticContainer::get(FailureLogMessageDetector::class);
        if ($exitCode === 0 && $importantLogDetector->hasEncounteredImportantLog()) {
            $output->writeln("Error: error or warning logs detected, exit 1");
            $exitCode = 1;
        }

        return $exitCode;
    }

    private function addCommandIfExists($command)
    {
        if (!class_exists($command)) {
            Log::warning(sprintf('Cannot add command %s, class does not exist', $command));
        } elseif (!is_subclass_of($command, 'Piwik\Plugin\ConsoleCommand')) {
            Log::warning(sprintf('Cannot add command %s, class does not extend Piwik\Plugin\ConsoleCommand', $command));
        } else {
            /** @var Command $commandInstance */
            $commandInstance = new $command;

            // do not add the command if it already exists; this way we can add the command ourselves in tests
            if (!$this->has($commandInstance->getName())) {
                $this->add($commandInstance);
            }
        }
    }

    /**
     * Returns a list of available command classnames.
     *
     * @return string[]
     */
    private function getAvailableCommands()
    {
        $commands = $this->getDefaultPiwikCommands();
        $detected = PluginManager::getInstance()->findMultipleComponents('Commands', 'Piwik\\Plugin\\ConsoleCommand');

        $commands = array_merge($commands, $detected);

        /**
         * Triggered to filter / restrict console commands. Plugins that want to restrict commands
         * should subscribe to this event and remove commands from the existing list.
         *
         * **Example**
         *
         *     public function filterConsoleCommands(&$commands)
         *     {
         *         $key = array_search('Piwik\Plugins\MyPlugin\Commands\MyCommand', $commands);
         *         if (false !== $key) {
         *             unset($commands[$key]);
         *         }
         *     }
         *
         * @param array &$commands An array containing a list of command class names.
         */
        Piwik::postEvent('Console.filterCommands', array(&$commands));

        $commands = array_values(array_unique($commands));

        return $commands;
    }

    private function setServerArgsIfPhpCgi()
    {
        if (Common::isPhpCgiType()) {
            $_SERVER['argv'] = array();
            foreach ($_GET as $name => $value) {
                $argument = $name;
                if (!empty($value)) {
                    $argument .= '=' . $value;
                }

                $_SERVER['argv'][] = $argument;
            }

            if (!defined('STDIN')) {
                define('STDIN', fopen('php://stdin', 'r'));
            }
        }
    }

    public static function isSupported()
    {
        return Common::isPhpCliMode() && !Common::isPhpCgiType();
    }

    protected function initMatomoHost(InputInterface $input)
    {
        $matomoHostname = $input->getParameterOption('--matomo-domain');

        if (empty($matomoHostname)) {
            $matomoHostname = $input->getParameterOption('--url');
        }

        $matomoHostname = UrlHelper::getHostFromUrl($matomoHostname);
        Url::setHost($matomoHostname);
    }

    protected function initEnvironment(OutputInterface $output)
    {
        try {
            if ($this->environment === null) {
                $this->environment = new Environment('cli');
                $this->environment->init();
            }

            $config = Config::getInstance();
            return $config;
        } catch (\Exception $e) {
            $output->writeln($e->getMessage() . "\n");
        }
    }

    /**
     * Register the console output into the logger.
     *
     * Ideally, this should be done automatically with events:
     * @see http://symfony.com/fr/doc/current/components/console/events.html
     * @see Symfony\Bridge\Monolog\Handler\ConsoleHandler::onCommand()
     * But it would require to install Symfony's Event Dispatcher.
     */
    private function initLoggerOutput(OutputInterface $output)
    {
        /** @var ConsoleHandler $consoleLogHandler */
        $consoleLogHandler = StaticContainer::get('Symfony\Bridge\Monolog\Handler\ConsoleHandler');
        $consoleLogHandler->setOutput($output);
    }

    public static function initPlugins()
    {
        Plugin\Manager::getInstance()->loadActivatedPlugins();
        Plugin\Manager::getInstance()->loadPluginTranslations();
    }

    private function getDefaultPiwikCommands()
    {
        $commands = array(
            'Piwik\CliMulti\RequestCommand'
        );

        $commandsFromPluginsMarkedInConfig = $this->getCommandsFromPluginsMarkedInConfig();
        $commands = array_merge($commands, $commandsFromPluginsMarkedInConfig);

        return $commands;
    }

    private function getCommandsFromPluginsMarkedInConfig()
    {
        $plugins = Config::getInstance()->General['always_load_commands_from_plugin'];
        $plugins = explode(',', $plugins);

        $commands = array();
        foreach($plugins as $plugin) {
            $instance = new Plugin($plugin);
            $commands = array_merge($commands, $instance->findMultipleComponents('Commands', 'Piwik\\Plugin\\ConsoleCommand'));
        }
        return $commands;
    }

    private function initAuth()
    {
        Piwik::postEvent('Request.initAuthenticationObject');
        try {
            StaticContainer::get('Piwik\Auth');
        } catch (Exception $e) {
            $message = "Authentication object cannot be found in the container. Maybe the Login plugin is not activated?
                        You can activate the plugin by adding:
                        Plugins[] = Login
                        under the [Plugins] section in your config/config.ini.php";
            StaticContainer::get(LoggerInterface::class)->warning($message);
        }
    }
}
