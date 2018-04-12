<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Monitor extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:monitor');
        $this->setDescription('Shows and updates the current state of the queue every 2 seconds.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $settings = Queue\Factory::getSettings();

        if ($settings->isRedisBackend()) {
            $systemCheck = new SystemCheck();
            $systemCheck->checkRedisIsInstalled();
        }

        if ($settings->queueEnabled->getValue()) {
            $output->writeln('Queue is enabled');
        } else {
            $output->writeln('<comment>' . strtoupper('Queue is disabled: ') . 'No new requests will be written into the queue, processing the remaining requests is still possible.</comment>');
        }

        $backend = Queue\Factory::makeBackend();
        $manager = Queue\Factory::makeQueueManager($backend);
        $queues  = $manager->getAllQueues();
        $lock    = Queue\Factory::makeLock($backend);

        if ($settings->processDuringTrackingRequest->getValue()) {
            $output->writeln('Request sets in the queue will be processed automatically after a tracking request');
        } else {
            $output->writeln('The command <comment>./console queuedtracking:process</comment> has to be executed to process request sets within queue');
        }

        $output->writeln(sprintf('Up to %d workers will be used', $manager->getNumberOfAvailableQueues()));
        $output->writeln(sprintf('Processor will start once there are at least %s request sets in the queue',
                                 $manager->getNumberOfRequestsToProcessAtSameTime()));

        while (1) {
            $memory = $backend->getMemoryStats(); // I know this will only work with redis currently as it is not defined in backend interface etc. needs to be refactored once we add another backend

            $numInQueue = array();
            foreach ($queues as $queue) {
                $numInQueue[] = $queue->getNumberOfRequestSetsInQueue();
            }

            $message = sprintf('%s (%s) request sets left in queue. %s used memory (%s peak). %d workers active.        ',
                               array_sum($numInQueue),
                               implode('+', $numInQueue),
                               $memory['used_memory_human'],
                               $memory['used_memory_peak_human'],
                               $lock->getNumberOfAcquiredLocks());
            $output->write("\x0D");
            $output->write($message);
            sleep(2);
        }

    }

}
