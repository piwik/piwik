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
 * plugin:deactivate console command.
 */
class DeactivatePlugin extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('plugin:deactivate');
        $this->setDescription('Deactivate a plugin.');
        $this->addArgument('plugin', InputArgument::IS_ARRAY, 'The plugin name you want to deactivate. Multiple plugin names can be specified separated by a space.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $pluginManager = Manager::getInstance();

        $plugins = $input->getArgument('plugin');

        foreach ($plugins as $plugin) {
            if (!$pluginManager->isPluginActivated($plugin)) {
                $output->writeln(sprintf('<comment>The plugin %s is already deactivated.</comment>', $plugin));
                continue;
            }

            if (!$pluginManager->isPluginInFilesystem($plugin)) {
                $output->writeln("<error>Cannot find plugin files for $plugin.</error>");
                continue;
            }

            $pluginManager->deactivatePlugin($plugin);

            $output->writeln("Deactivated plugin <info>$plugin</info>");
        }
    }
}
