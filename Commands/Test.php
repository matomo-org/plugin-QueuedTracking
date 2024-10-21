<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\QueuedTracking\Commands;

use Piwik\Application\Environment;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugins\QueuedTracking\SystemCheck;
use Piwik\Tracker;
use Piwik\Plugins\QueuedTracking\Queue;

/**
 * This class lets you define a new command. To read more about commands have a look at our Piwik Console guide on
 * http://developer.piwik.org/guides/piwik-on-the-command-line
 *
 * As Piwik Console is based on the Symfony Console you might also want to have a look at
 * http://symfony.com/doc/current/components/console/index.html
 */
class Test extends ConsoleCommand
{
    /**
     * This methods allows you to configure your command. Here you can define the name and description of your command
     * as well as all options and arguments you expect when executing it.
     */
    protected function configure()
    {
        $this->setName('queuedtracking:test');
        $this->addRequiredValueOption('skip-max-memory-config-check', null, 'Do not check for MaxMemory config values ');
        $this->setDescription('Test your Redis connection get some information about your current system.');
    }

    /**
     * The actual task is defined in this method. Here you can access any option or argument that was defined on the
     * command line via $input and write anything to the console via $output argument.
     * In case anything went wrong during the execution you should throw an exception to make sure the user will get a
     * useful error message and to make sure the command does not exit with the status code 0.
     *
     * Ideally, the actual command is quite short as it acts like a controller. It should only receive the input values,
     * execute the task by calling a method of another class and output any useful information.
     *
     * Execute the command like: ./console queuedtracking:test --name="The Piwik Team"
     * @return int
     */

    protected function doExecute(): int
    {
        $input = $this->getInput();
        $output = $this->getOutput();
        $trackerEnvironment = new Environment('tracker');
        $trackerEnvironment->init();

        $shouldSkipCheckingMemoryConfigValues = $input->getOption('skip-max-memory-config-check');

        $settings = Queue\Factory::getSettings();
        $isUsingRedis = $settings->isRedisBackend();

        Tracker::loadTrackerEnvironment();

        $output->writeln('<comment>Settings that will be used:</comment>');
        $output->writeln('Backend: ' .  $settings->backend->getValue());
        $output->writeln('NumQueueWorkers: ' . $settings->numQueueWorkers->getValue());
        $output->writeln('NumRequestsToProcess: ' . $settings->numRequestsToProcess->getValue());
        $output->writeln('ProcessDuringTrackingRequest: ' . (int) $settings->processDuringTrackingRequest->getValue());
        $output->writeln('QueueEnabled: ' . (int) $settings->queueEnabled->getValue());
        $output->writeln('');

        $output->writeln('<comment>Redis backend only settings (does not apply when using MySQL backend):</comment>');
        $output->writeln('Host: ' . $settings->redisHost->getValue());
        $output->writeln('Port: ' . $settings->redisPort->getValue());
        $output->writeln('Timeout: ' . $settings->redisTimeout->getValue());
        $output->writeln('Password: ' . $settings->redisPassword->getValue());
        $output->writeln('Database: ' . $settings->redisDatabase->getValue());
        $output->writeln('RedisBackendType: ' . $settings->getRedisType());
        $output->writeln('SentinelMasterName: ' . $settings->sentinelMasterName->getValue());

        $output->writeln('');
        $output->writeln('<comment>Version / stats:</comment>');

        $output->writeln('PHP version: ' . phpversion());
        $output->writeln('Uname: ' . php_uname());

        if ($isUsingRedis) {
            try {
                $systemCheck = new SystemCheck();
                $systemCheck->checkRedisIsInstalled();

                $extension = new \ReflectionExtension('redis');
                $output->writeln('PHPRedis version: ' . $extension->getVersion());
            } catch (\Exception $e) {
                $output->writeln('No PHPRedis extension (not a problem if sentinel is used):' . $e->getMessage());
            }
        }

        $backend = Queue\Factory::makeBackend();

        if ($backend instanceof Queue\Backend\Sentinel) {
            $output->writeln('Redis backend is using sentinel');
        }

        $output->writeln('Backend version: ' . $backend->getServerVersion());
        $output->writeln('Memory: ' . var_export($backend->getMemoryStats(), 1));

        $redis = $backend->getConnection();
        if ($isUsingRedis && !$shouldSkipCheckingMemoryConfigValues) {
            $evictionPolicy = $this->getRedisConfig($redis, 'maxmemory-policy');
            $output->writeln('MaxMemory Eviction Policy config: ' . $evictionPolicy);

            if ($evictionPolicy !== 'allkeys-lru' && $evictionPolicy !== 'noeviction') {
                $output->writeln(
                    '<error>The eviction policy can likely lead to errors when memory is low. We recommend to use eviction policy <comment>allkeys-lru</comment> or alternatively <comment>noeviction</comment>.' .
                    ' Read more here: http://redis.io/topics/lru-cache</error>'
                );
            }

            $evictionPolicy = $this->getRedisConfig($redis, 'maxmemory');
            $output->writeln('MaxMemory config: ' . $evictionPolicy);
        }

        $output->writeln('');
        $output->writeln('<comment>Performing some tests:</comment>');

        if ($isUsingRedis && method_exists($redis, 'isConnected')) {
            $output->writeln('Redis is connected: ' . (int) $redis->isConnected());
        }

        if ($backend->testConnection()) {
            $output->writeln('Connection works in general');
        } else {
            $output->writeln('Connection does not actually work: ' . $redis->getLastError());
        }

        if ($isUsingRedis) {
            $this->testRedis($redis, 'set', ['testKey', 'value'], 'testKey');
            $this->testRedis($redis, 'setnx', array('testnxkey', 'value'), 'testnxkey');
            $this->testRedis($redis, 'setex', array('testexkey', 5, 'value'), 'testexkey');
            $this->testRedis($redis, 'set', array('testKeyWithNx', 'value', array('nx')), 'testKeyWithNx');
            $this->testRedis($redis, 'set', array('testKeyWithEx', 'value', array('ex' => 5)), 'testKeyWithEx');
        }

        $backend->delete('foo');
        if (!$backend->setIfNotExists('foo', 'bar', 5)) {
            $message = "setIfNotExists(foo, bar, 1) does not work, most likely we won't be able to acquire a lock: " . $backend->getLastError();
            $output->writeln($message);
        } else {
            $initialTtl = $backend->getTimeToLive('foo');
            if ($initialTtl >= 3000 && $initialTtl <= 5000) {
                $output->writeln('Initial expire seems to be set correctly');
            } else {
                $output->writeln('<error>Initial expire seems to be not set correctly: ' . $initialTtl . ' </error>');
            }

            if ($backend->get('foo') == 'bar') {
                $output->writeln('setIfNotExists works fine');
            } else {
                $output->writeln('There might be a problem with setIfNotExists');
            }

            if ($backend->expireIfKeyHasValue('foo', 'bar', 10)) {
                $output->writeln('expireIfKeyHasValue seems to work fine');
            } else {
                $output->writeln('<error>There might be a problem with expireIfKeyHasValue: ' . $redis->getLastError() . '</error>');
            }

            $extendedTtl = $backend->getTimeToLive('foo');
            if ($extendedTtl >= 8000 && $extendedTtl <= 10000) {
                $output->writeln('Extending expire seems to be set correctly');
            } else {
                $output->writeln('<error>Extending expire seems to be not set correctly: ' . $extendedTtl . ' </error>');
            }

            if ($backend->expireIfKeyHasValue('foo', 'invalidValue', 10)) {
                $output->writeln('<error>expireIfKeyHasValue expired a key which it should not have since values does not match</error>');
            } else {
                $output->writeln('expireIfKeyHasValue correctly expires only when the value is correct');
            }

            $extendedTtl = $backend->getTimeToLive('foo');
            if ($extendedTtl >= 7000 && $extendedTtl <= 10000) {
                $output->writeln('Expire is still set which is correct');
            } else {
                $output->writeln('<error>Expire missing after a wrong extendExpire: ' . $extendedTtl . ' </error>');
            }

            if ($backend->deleteIfKeyHasValue('foo', 'bar')) {
                $output->writeln('deleteIfKeyHasValue seems to work fine');
            } else {
                $output->writeln('<error>There might be a problem with deleteIfKeyHasValue: ' . $redis->getLastError() . '</error>');
            }
        }

        $backend->delete('fooList');
        $backend->appendValuesToList('fooList', array('value1', 'value2', 'value3'));
        $values = $backend->getFirstXValuesFromList('fooList', 2);
        if ($values == array('value1', 'value2')) {
            $backend->removeFirstXValuesFromList('fooList', 1);
            $backend->removeFirstXValuesFromList('fooList', 1);
            $values = $backend->getFirstXValuesFromList('fooList', 2);
            if ($values == array('value3')) {
                $output->writeln('List feature seems to work fine');
            } else {
                $output->writeln('List feature seems to work only partially: ' . var_export($values, 1));
            }
        } else {
            $output->writeln('<error>List feature seems to not work fine: ' . $redis->getLastError() . '</error>');
        }

        $output->writeln('');
        $output->writeln('<comment>Done</comment>');

        return self::SUCCESS;
    }

    /**
     * @param \Redis $redis
     * @param $configName
     * @return string
     */
    private function getRedisConfig($redis, $configName)
    {
        if ($redis instanceof \RedisCluster) {
            $config = $redis->config('CONFIG', 'GET', $configName);
            unset($config[0]);
        } else {
            $config = $redis->config('GET', $configName);
        }

        $value = strtolower(array_shift($config));
        return $value;
    }

    /**
     * @param \Redis $redis
     * @param $method
     * @param $params
     * @param $keyToCleanUp
     */
    private function testRedis($redis, $method, $params, $keyToCleanUp)
    {
        if ($keyToCleanUp) {
            $redis->del($keyToCleanUp);
        }

        $result = call_user_func_array(array($redis, $method), $params);

        $paramsMapped = array_map(function ($item) {
            if (is_string($item)) {
                return $item;
            }

            return str_replace(["\r", "\n", "  "], '', var_export($item, true));
        }, $params);
        $paramsInline = implode(', ', $paramsMapped);

        if ($result) {
            $this->getOutput()->writeln("Success for method $method($paramsInline)");
        } else {
            $errorMessage = $redis->getLastError();
            $this->getOutput()->writeln("<error>Failure for method $method($paramsInline): $errorMessage</error>");
        }

        if ($keyToCleanUp) {
            $redis->del($keyToCleanUp);
        }
    }
}
