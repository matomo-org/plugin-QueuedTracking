## Changelog

5.0.0
- Compatibility with Matomo 5.0
 
4.0.5
- Translation changes

4.0.4
- Clarify inline help for "Queue enabled" config setting

4.0.3
- Replace Redis::delete() with Redis::del() and fix a warning

4.0.2
- Support new option `--force-num-requests-process-at-once` to the process command

4.0.1
- Improve compatibility with PHP 7.4

4.0.0
- Compatibility with Matomo 4.0

3.3.5
- Improve update script to first add primary key and then remove index

3.3.4
- Use primary key instead of a unique index for mysql backend for better replication

3.3.3
- Add possibility to ignore queued tracking handler and track request directly into the database

3.3.2
- Send branded HTML email

3.3.1
- Support MySQLi adapter

3.3.0 
- When using 3rd party cookies, the 3rd party cookie value will not be overwritten by local site visitor id values
 
3.2.1
- Faster queue locking
- More debug output while processing

3.2.0
- Added possibility to use a MySQL backend instead of redis
- New option `queue-id` for the `queuedtracking:process` command which may improve processing speed as the command would only focus on one queue instead of trying to get the lock for a random queue.
- Various other minor performance improvements
- New feature: Get notified by email when a single queue reaches a specific threshold

3.0.2

- Ensure do not track cookie works

3.0.1

- Added possibility to define a unix socket instead of a host and path.

3.0.0

- Compatibility with Piwik 3.0

0.3.2

- Fixes a bug in the lock-status command where it may report a queue as locked while it was not

0.3.1

- Fixed Redis Sentinel was not working properly. Sentinel can be now configured via the UI and not via config. Also
  multiple servers can be configured now.

0.3.0

- Added support to use Redis Sentinel for automatic failover

0.2.6

- When a request takes more than 2 seconds and debug tracker mode is enabled, log information about the request.

0.2.5

- Use a better random number generator if available on the system to more evenly process queues.

0.2.4

- The command `queuedtracking:monitor` will now work even when the queue is disabled

0.2.3

- Added more tests and information to the `queuedtracking:test` command
- It is now possible to configure up to 16 workers

0.2.2

- Improved output for the new `test` command
- New FAQ entries

0.2.1

- Added a new command to test the connection to Redis. To test yor connection use `./console queuedtracking:test`

0.2.0

- Compatibility w/ Piwik 2.15.

0.1.6
 
- For bulk requests we do no longer skip all tracking requests after a tracking request that has an invalid `idSite` set. The same behaviour was changed in Piwik 2.14 for regular bulk requests.

0.1.5

- Fixed a notice in case an incompatible Redis version is used.

0.1.4

- It is now possible to start multiple workers for faster insertion from Redis to the database. This can be configured in the "Plugin Settings"
- Monitor does now output information whether a processor is currently processing the queue.
- Added a new command `queuedtracking:lock-status` that outputs the status of each queue lock. This command can also unlock a queue by using the option `--unlock`.
- Added a new command `queuedtracking:print-queued-requests` that outputs the next requests to process in each queue.
- If someone passes the option `-vvv` to `./console queuedtracking:process` the Tracker debug mode will be enabled and additional information will be printed to the screen.

0.1.2

- Updated description on Marketplace

0.1.0

- Initial Release
