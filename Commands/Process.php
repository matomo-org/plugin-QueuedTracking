<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Application\Environment;
use Piwik\Cache;
use Piwik\Container\StaticContainer;
use Piwik\Log;
use Piwik\Piwik;
use Piwik\Plugin;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Process extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:process');
        $this->setDescription('Processes all queued tracking requests in case there are enough requests in the queue and in case they are not already in process by another script. To keep track of the queue use the <comment>--verbose</comment> option or execute the <comment>queuedtracking:monitor</comment> command.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $systemCheck = new SystemCheck();
        $systemCheck->checkRedisIsInstalled();

        $trackerEnvironment = new Environment('tracker');
        $trackerEnvironment->init();

        Log::unsetInstance();
        $trackerEnvironment->getContainer()->get('Piwik\Access')->setSuperUserAccess(false);
        $trackerEnvironment->getContainer()->get('Piwik\Plugin\Manager')->setTrackerPluginsNotToLoad(array('Provider'));
        Tracker::loadTrackerEnvironment();

        if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $output->getVerbosity()) {
            $GLOBALS['PIWIK_TRACKER_DEBUG'] = true;
        }

        $backend      = Queue\Factory::makeBackend();
        $queueManager = Queue\Factory::makeQueueManager($backend);

        if (!$queueManager->canAcquireMoreLocks()) {
            $trackerEnvironment->destroy();

            $this->writeSuccessMessage($output, array("Nothing to proccess. Already max number of workers in process."));
            return;
        }

        $shouldProcess = false;
        foreach ($queueManager->getAllQueues() as $queue) {
            if ($queue->shouldProcess()) {
                $shouldProcess = true;
                break;
            }
        }

        if (!$shouldProcess) {
            $trackerEnvironment->destroy();

            $this->writeSuccessMessage($output, array("No queue currently needs processing"));
            return;
        }

        $output->writeln("<info>Starting to process request sets, this can take a while</info>");

        register_shutdown_function(function () use ($queueManager) {
            $queueManager->unlock();
        });

        $startTime = microtime(true);
        $processor = new Processor($queueManager);
        $processor->setNumberOfMaxBatchesToProcess(1000);
        $tracker   = $processor->process();

        $neededTime = (microtime(true) - $startTime);
        $numRequestsTracked = $tracker->getCountOfLoggedRequests();
        $requestsPerSecond  = $this->getNumberOfRequestsPerSecond($numRequestsTracked, $neededTime);

        Piwik::postEvent('Tracker.end');

        $trackerEnvironment->destroy();

        $this->writeSuccessMessage($output, array(sprintf('This worker finished queue processing with %sreq/s (%s requests in %02.2f seconds)', $requestsPerSecond, $numRequestsTracked, $neededTime)));
    }

    private function getNumberOfRequestsPerSecond($numRequestsTracked, $neededTimeInSeconds)
    {
        if (empty($neededTimeInSeconds)) {
            $requestsPerSecond = $numRequestsTracked;
        } else {
            $requestsPerSecond = round($numRequestsTracked / $neededTimeInSeconds, 2);
        }

        return $requestsPerSecond;
    }
}
