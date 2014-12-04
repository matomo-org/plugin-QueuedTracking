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
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Process extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:process');
        $this->setDescription('Processes all queued tracking requests in case there are enough requests in the queue and in case they are not already in process by another script. To keep track of the queue use the <comment>--verbose</comment> option or execute the <comment>queuedtracking:monitor</comment> command.');
        $this->setHelp('Use the <comment>--verbose</comment> parameter if you want to keep track of the live state of the queue while it is being processed. This slows down the tracking performance a tiny bit therefore it is disabled by default and it should not be used when the command is executed as a cronjob. You can still keep track of the queue by using the <comment>queuedtracking:monitor</comment> command if needed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        Access::getInstance()->setSuperUserAccess(false);
        Tracker::loadTrackerEnvironment();

        $backend   = Queue\Factory::makeBackend();
        $queue     = Queue\Factory::makeQueue($backend);
        $processor = new Processor($backend);

        $numRequestsQueued = $queue->getNumberOfRequestSetsInQueue();

        if (!$queue->shouldProcess()) {
            $numRequestsNeeded = $queue->getNumberOfRequestsToProcessAtSameTime();
            $this->writeSuccessMessage($output, array("Nothing to process. Only $numRequestsQueued request sets are queued, $numRequestsNeeded are needed to start processing the queue."));
        } elseif (!$processor->acquireLock()) {
            $this->writeSuccessMessage($output, array("Nothing to proccess. $numRequestsQueued request sets are queued and they are already in process by another script."));
        } else {
            $output->writeln("<info>Starting to process $numRequestsQueued request sets, this can take a while</info>");

            if ($input->getOption('verbose')) {
                $this->setProgressCallback($processor, $output, $numRequestsQueued);
            }

            try {
                $processor->process($queue);
                $processor->unlock();
            } catch (\Exception $e) {
                $processor->unlock();

                throw $e;
            }

            $this->writeSuccessMessage($output, array('Queue processed'));
        }
    }

    private function setProgressCallback(Processor $processor, OutputInterface $output, $numRequests)
    {
        $processor->setOnProcessNewRequestSetCallback(function (Queue $queue, Tracker $tracker) use ($output, $numRequests) {
            $message = sprintf('%s requests tracked, %s request sets left in queue        ',
                               $tracker->getCountOfLoggedRequests(),
                               $queue->getNumberOfRequestSetsInQueue());

            $output->write("\x0D");
            $output->write($message);
        });

    }
}
