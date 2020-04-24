<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking\Settings;

use Piwik\Cache;
use Piwik\Config;
use Piwik\Settings\Plugin\SystemSetting;

/**
 * Defines Settings for QueuedTracking.
 */
class NumWorkers extends SystemSetting
{
    private $oldValue;

    public function getOldValue()
    {
        return $this->oldValue;
    }

    public function setValue($value)
    {
        $this->oldValue = $this->getValue();

        parent::setValue($value);
    }
}
