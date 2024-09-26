<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Application\Environment;
use Piwik\Log;
use Piwik\Piwik;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\Queue\Processor;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;

class Process extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:process');
        $this->addRequiredValueOption('queue-id', null, 'If set, will only work on that specific queue. For example "0" or "1" (if there are multiple queues). Not recommended when only one worker is in use. If for example 4 workers are in use, you may want to use 0, 1, 2, or 3.');
        $this->addRequiredValueOption('force-num-requests-process-at-once', null, 'If defined, it overwrites the setting of how many requests will be picked out of the queue and processed at once. Must be a number which is >= 1. By default, the configured value from the settings will be used. This can be useful for example if you want to process every single request within the queue. If otherwise a batch size of say 100 is configured, then there may be otherwise 99 requests left in the queue. It can be also useful for testing purposes.');
        $this->addRequiredValueOption('cycle', 'c', 'The proccess will automatically loop for "n" cycle time(s), set "0" to infinite.', 1);
        $this->addRequiredValueOption('sleep', 's', 'Take a nap for "n" second(s) before recycle, minimum is 1 second.', 1);
        $this->addRequiredValueOption('delay', 'd', 'Delay before finished', 0);
        $this->setDescription('Processes all queued tracking requests in case there are enough requests in the queue and in case they are not already in process by another script. To keep track of the queue use the <comment>--verbose</comment> option or execute the <comment>queuedtracking:monitor</comment> command.');
    }

    /**
     * @return int
     */
    protected function doExecute(): int
    {
        $input = $this->getInput();
        $output = $this->getOutput();
        $settings = Queue\Factory::getSettings();
        if ($settings->isRedisBackend()) {
            $systemCheck = new SystemCheck();
            $systemCheck->checkRedisIsInstalled();
        }

        $queueId = $input->getOption('queue-id');
        if (empty($queueId) && $queueId !== 0 && $queueId !== '0') {
            $queueId = null;
        } elseif (!is_numeric($queueId)) {
            throw new \Exception('queue-id needs to be numeric');
        } else {
            $queueId = (int) $queueId;
            $output->writeln("<info>Forcing queue ID: </info>" . $queueId);
        }

        if ($output->isVeryVerbose()) {
            $GLOBALS['PIWIK_TRACKER_DEBUG'] = true;
        }

        $trackerEnvironment = new Environment('tracker');
        $trackerEnvironment->init();

        Log::unsetInstance();
        $trackerEnvironment->getContainer()->get('Piwik\Access')->setSuperUserAccess(false);
        $trackerEnvironment->getContainer()->get('Piwik\Plugin\Manager')->setTrackerPluginsNotToLoad(array('Provider'));
        Tracker::loadTrackerEnvironment();

        $backend      = Queue\Factory::makeBackend();
        $queueManager = Queue\Factory::makeQueueManager($backend);
        $queueManager->setForceQueueId($queueId);

        $forceNumRequests = $input->getOption('force-num-requests-process-at-once');
        if (!empty($forceNumRequests) && is_numeric($forceNumRequests) && $forceNumRequests >= 1) {
            $output->writeln("<info>Force proccess num requests at once: </info>" . $forceNumRequests);
            $queueManager->setNumberOfRequestsToProcessAtSameTime($forceNumRequests);
        } elseif (!empty($forceNumRequests)) {
            throw new \Exception('Number of requests to process must be a number and at least 1');
        }

        register_shutdown_function(function () use ($queueManager) {
            $queueManager->unlock();
        });



        $numberOfProcessCycle = $input->getOption('cycle');
        if (!is_numeric($numberOfProcessCycle)) throw new \Exception('"cycle" needs to be numeric');
        $numberOfProcessCycle = (int)$numberOfProcessCycle;
        $infiniteCycle = $numberOfProcessCycle == 0;
        
        $delayedBeforeFinish = (int)$input->getOption('delay');

        $napster = max(1, $input->getOption('sleep'));
        if (!is_numeric($napster)) throw new \Exception('"nap" needs to be numeric');
        $napster = (int)$napster;

        $lastTimeGotMoreThanZeroTrackedReq = microtime(true);
        $originalNumberOfRequestsToProcessAtSameTime = $queueManager->getNumberOfRequestsToProcessAtSameTime();

        while ($numberOfProcessCycle > 0 || $infiniteCycle) {
            $wipingOutQueue = false;
            if (microtime(true) - $lastTimeGotMoreThanZeroTrackedReq > 10) {
                $queueManager->setNumberOfRequestsToProcessAtSameTime(1);
                $wipingOutQueue = true;
                $lastTimeGotMoreThanZeroTrackedReq = microtime(true);
            }

            if ($wipingOutQueue) $output->writeln("<fg=red;bg=white;options=bold> TRYING TO WIPE OUT THE QUEUE </>");
            $output->writeln("<info>Starting to process request sets, this can take a while</info>");

            $startTime = microtime(true);
            $processor = new Processor($queueManager);
            $processor->setNumberOfMaxBatchesToProcess(500);
            $tracker   = $processor->process();

            $neededTime = (microtime(true) - $startTime);
            $numRequestsTracked = $tracker->getCountOfLoggedRequests();
            $requestsPerSecond  = $this->getNumberOfRequestsPerSecond($numRequestsTracked, $neededTime);

            $this->writeSuccessMessage(
                array(sprintf('This worker finished queue processing with %sreq/s (%s requests in %02.2f seconds)', $requestsPerSecond, $numRequestsTracked, $neededTime))
            );
            Piwik::postEvent('Tracker.end');

            if ($numRequestsTracked > 0) $lastTimeGotMoreThanZeroTrackedReq = microtime(true);

            if (!$infiniteCycle) $numberOfProcessCycle--;
            if ($numberOfProcessCycle > 0 || $infiniteCycle) {
                $cTogo = $infiniteCycle ? "infinite" : $numberOfProcessCycle;
                $output->writeln("===========================================================================");
                $output->writeln("<comment>Taking a nap for {$napster} second(s) before re-run the process. <info>({$cTogo})</info> cyle(s) to go.</comment>");
                $output->writeln("===========================================================================");
                sleep($napster);
            }

            if ($wipingOutQueue) $queueManager->setNumberOfRequestsToProcessAtSameTime($originalNumberOfRequestsToProcessAtSameTime);
        }

        // Piwik::postEvent('Tracker.end');
        $trackerEnvironment->destroy();

        if ($delayedBeforeFinish > 0) sleep($delayedBeforeFinish);
        
        return self::SUCCESS;
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
