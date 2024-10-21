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

class Updates_3_2_0 extends PiwikUpdates
{
    /**
     * @var MigrationFactory
     */
    private $migration;

    public function __construct(MigrationFactory $factory)
    {
        $this->migration = $factory;
    }

    public function getMigrations(Updater $updater)
    {
        $migration1 = $this->migration->db->createTable('queuedtracking_queue', array(
            'queue_key' => 'VARCHAR(70) NOT NULL',
            'queue_value' => 'VARCHAR(255) NULL DEFAULT NULL',
            'expiry_time' => 'BIGINT UNSIGNED DEFAULT 9999999999'
        ));
        $migration2 = $this->migration->db->addUniqueKey('queuedtracking_queue', array('queue_key'), 'unique_queue_key');

        return array(
            $migration1, $migration2
        );
    }

    public function doUpdate(Updater $updater)
    {
        $updater->executeMigrations(__FILE__, $this->getMigrations($updater));

        $config = new Configuration();
        $config->install();
    }
}
