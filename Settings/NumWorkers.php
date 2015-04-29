<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Settings;

use Piwik\Cache;
use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Settings\SystemSetting;

/**
 * Defines Settings for QueuedTracking.
 */
class NumWorkers extends SystemSetting
{

    public function setValue($value)
    {
        $newNumWorkers = $value;
        $oldNumWorkers = $this->getValue();

        parent::setValue($value);

        if ($newNumWorkers && $oldNumWorkers) {
            try {
                $manager = Factory::makeQueueManager(Factory::makeBackend());
                $manager->setNumberOfAvailableQueues($newNumWorkers);
                $manager->moveSomeQueuesIfNeeded($newNumWorkers, $oldNumWorkers);
            } catch (\Exception $e) {
                // it is ok if this fails. then it is most likely not enabled etc.
            }
        }
    }
}
