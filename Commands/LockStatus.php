<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Access;
use Piwik\Option;
use Piwik\Plugin;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LockStatus extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:lock-status');
        $this->setDescription('Outputs information for the status of the processing lock. Also allows to remove a lock.');
        $this->addOption('unlock', null, InputOption::VALUE_NONE, 'Needed to actually unlock the queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $systemCheck = new SystemCheck();
        $systemCheck->checkRedisIsInstalled();

        $backend   = Queue\Factory::makeBackend();
        $processor = new Processor($backend);
        $lockKey   = $processor->getLockKey();

        if ($backend->get($lockKey)) {
            $time = $backend->getTimeToLive($lockKey);

            $output->writeln(sprintf('Queue is locked for <comment>%d ms</comment>', $time));

            if ($input->getOption('unlock')) {
                $backend->delete($lockKey);
                $this->writeSuccessMessage($output, array('Queue unlocked'));
            } else {
                $output->writeln('Set option <comment>--unlock</comment> to unlock the queue.');
            }
        } else {
            $output->writeln('Queue is not locked');
        }
    }
}
