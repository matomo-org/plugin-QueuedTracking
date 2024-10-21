<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking;

use Piwik\Updater;
use Piwik\Updates as PiwikUpdates;
use Piwik\Updater\Migration\Factory as MigrationFactory;
use Piwik\Settings\Plugin\SystemSetting;
use Piwik\Settings\FieldConfig;

/**
 * Update for version 5.1.0.
 */
class Updates_5_1_0 extends PiwikUpdates
{
    public function __construct(MigrationFactory $factory)
    {
    }

    public function doUpdate(Updater $updater)
    {
        $old_useSentinelBackend = new SystemSetting('useSentinelBackend', $default = false, FieldConfig::TYPE_BOOL, 'QueuedTracking');
        $tmp_useWhatRedisBackendType = new SystemSetting('useWhatRedisBackendType', $default = 0, FieldConfig::TYPE_INT, 'QueuedTracking');
        if ($tmp_useWhatRedisBackendType->getValue() == 0) {
            $tmp_useWhatRedisBackendType->setValue($old_useSentinelBackend->getValue() == true ? 2 : 1);
            $tmp_useWhatRedisBackendType->save();
        }
    }
}
