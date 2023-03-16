<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\Queue;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Monitor extends ConsoleCommand
{

    protected function configure()
    {
        $this->setName('queuedtracking:monitor');
        $this->setDescription('Shows and updates the current state of the queue every 2 seconds.');
        $this->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'If set, will limit the number of monitoring iterations done.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $settings = Queue\Factory::getSettings();

        if ($settings->isRedisBackend()) {
            $systemCheck = new SystemCheck();
            $systemCheck->checkRedisIsInstalled();
        }

        $iterations = $this->getIterationsFromArg($input);
        if ($iterations  !== null) {
            $output->writeln("<info>Only running " . $iterations . " iterations.</info>");
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
        $iterationCount = 0;

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
            if (!is_null($iterations)) {
                $iterationCount += 1;
                if ($iterationCount >= $iterations) {
                    break;
                }
            }
            sleep(2);
        }

        return self::SUCCESS;
    }

    /**
     * Loads the `iteration` argument from the commands arguments. `null` indicates no limit supplied.
     *
     * @param InputInterface $input
     * @return int|null
     */
    private function getIterationsFromArg(InputInterface $input)
    {
        $iterations = $input->getOption('iterations');
        if (empty($iterations) && $iterations !== 0 && $iterations !== '0') {
            $iterations = null;
        } elseif (!is_numeric($iterations)) {
            throw new \Exception('iterations needs to be numeric');
        } else {
            $iterations = (int)$iterations;
            if ($iterations <= 0) {
                throw new \Exception('iterations needs to be a non-zero positive number');
            }
        }
        return $iterations;
    }

}
