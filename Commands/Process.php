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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Process extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:process');
        $this->addOption('queue-id', null, InputOption::VALUE_REQUIRED, 'If set, will only work on that specific queue. For example "0" or "1" (if there are multiple queues). Not recommended when only one worker is in use. If for example 4 workers are in use, you may want to use 0, 1, 2, or 3.');
        $this->setDescription('Processes all queued tracking requests in case there are enough requests in the queue and in case they are not already in process by another script. To keep track of the queue use the <comment>--verbose</comment> option or execute the <comment>queuedtracking:monitor</comment> command.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
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

        if (OutputInterface::VERBOSITY_VERY_VERBOSE <= $output->getVerbosity()) {
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

        $output->writeln("<info>Starting to process request sets, this can take a while</info>");

        register_shutdown_function(function () use ($queueManager) {
            $queueManager->unlock();
        });

        $startTime = microtime(true);
        $processor = new Processor($queueManager);
        $processor->setNumberOfMaxBatchesToProcess(500);
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
