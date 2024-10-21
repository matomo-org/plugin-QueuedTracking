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

class LockStatus extends ConsoleCommand
{
    protected function configure()
    {
        $this->setName('queuedtracking:lock-status');
        $this->setDescription('Outputs information for the status of each locked queue. Unlocking a queue is possible as well.');
        $this->addRequiredValueOption('unlock', null, 'If set will unlock the given queue.');
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

        $backend = Queue\Factory::makeBackend();
        $lock    = Queue\Factory::makeLock($backend);
        $keys    = $lock->getAllAcquiredLockKeys();

        $keyToUnlock = $input->getOption('unlock');

        if ($keyToUnlock && in_array($keyToUnlock, $keys)) {
            $backend->delete($keyToUnlock);
            $this->writeSuccessMessage(array(sprintf('Key %s unlocked', $keyToUnlock)));
        } elseif ($keyToUnlock) {
            $output->writeln(sprintf('<error>%s is not or no longer locked</error>', $keyToUnlock));
            $output->writeln(' ');
        }

        foreach ($keys as $lockKey) {
            $time = $backend->getTimeToLive($lockKey);
            if (!empty($time)) {
                $output->writeln(sprintf('"%s" is locked for <comment>%d ms</comment>', $lockKey, $time));
                $output->writeln(sprintf('Set option <comment>--unlock=%s</comment> to unlock the queue.', $lockKey));
                $output->writeln(' ');
            }
        }

        return self::SUCCESS;
    }
}
