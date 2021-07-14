<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreAdminHome\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Version;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


class VersionInfo extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('core:version');
        $this->setDescription('Returns the current version information of this Matomo instance.');
        $this->setHelp("This command can be used to set get the version information of the current Matomo instance.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(Version::VERSION);
    }
}
