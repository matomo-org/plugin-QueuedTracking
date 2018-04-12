<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Plugin;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PrintQueuedRequests extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:print-queued-requests');
        $this->setDescription('Prints the requests of each queue that will be processed next.');
        $this->addOption('queue-id', null, InputOption::VALUE_REQUIRED, 'If set, will print only requests of that queue');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $settings = Queue\Factory::getSettings();
        if ($settings->isRedisBackend()) {
            $systemCheck = new SystemCheck();
            $systemCheck->checkRedisIsInstalled();
        }

        $backend = Queue\Factory::makeBackend();
        $manager = Queue\Factory::makeQueueManager($backend);

        $queueId = $input->getOption('queue-id');

        foreach ($manager->getAllQueues() as $index => $queue) {
            $thisQueueId = $queue->getId();

            if (isset($queueId) && $queueId != $thisQueueId) {
                continue;
            }

            $output->writeln(sprintf('<info>Showing requests of queue %s. Use <comment>--queue-id=%s</comment> to print only information for this queue.</info>', $thisQueueId, $thisQueueId));

            $requests = $queue->getRequestSetsToProcess();
            $output->writeln(var_export($requests, 1));

            $output->writeln(sprintf('<info>These were the requests of queue %s. Use <comment>--queue-id=%s</comment> to print only information for this queue.</info>', $thisQueueId, $thisQueueId));

        }
    }
}
