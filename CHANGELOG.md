## Changelog

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
