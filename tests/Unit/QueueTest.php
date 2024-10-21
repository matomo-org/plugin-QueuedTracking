<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\QueuedTracking\tests\Unit;

use Piwik\Plugins\QueuedTracking\Queue;

/**
 * @group QueuedTracking
 * @group Plugins
 */
class QueueTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Queue
     */
    private $queue;

    private $requestsString;

    public function setUp(): void
    {
        parent::setUp();

        $this->queue = new Queue(new Queue\Backend\MySQL(), 1);

        $this->requestsString = file_get_contents(__DIR__ . '/Queue/Requests/TestQueuedRequest.json');
    }

    public function test_ensureJsonVarsAreStrings()
    {
        $requests = json_decode($this->requestsString, true);
        $this->queue->ensureJsonVarsAreStrings($requests);
        $this->assertEquals(json_decode($this->requestsString, true), $requests, 'The requests should not have changed');
    }

    public function test_ensureJsonVarsAreStrings_shouldFixUadataType()
    {
        $requests = json_decode($this->requestsString, true);
        $params = $requests['requests'];
        $requests['requests'][0]['uadata'] = json_decode($params[0]['uadata']);
        $this->queue->ensureJsonVarsAreStrings($requests);
        $this->assertEquals(json_decode($this->requestsString, true), $requests, 'The requests should not have changed');
    }
}
