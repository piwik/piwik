<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CorePluginsAdmin\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugin\Manager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * plugin:activate console command.
 */
class ActivatePlugin extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('plugin:activate');
        $this->setDescription('Activate a plugin.');
        $this->addArgument('plugin', InputArgument::IS_ARRAY, 'The plugin name you want to activate. Multiple plugin names can be specified separated by a space.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pluginManager = Manager::getInstance();

        $plugins = $input->getArgument('plugin');

        foreach ($plugins as $plugin) {
            if ($pluginManager->isPluginActivated($plugin)) {
                $output->writeln(sprintf('<comment>The plugin %s is already activated.</comment>', $plugin));
                continue;
            }

            if (!$pluginManager->isPluginInFilesystem($plugin)) {
                $output->writeln("<error>Cannot find plugin files for $plugin.</error>");
                continue;
            }

            if ($dependencies = $pluginManager->loadPlugin($plugin)->getMissingDependenciesAsString()) {
                $output->writeln("<error>$dependencies</error>");
                continue;
            }

            $pluginManager->activatePlugin($plugin);

            $output->writeln("Activated plugin <info>$plugin</info>");
        }
    }
}
