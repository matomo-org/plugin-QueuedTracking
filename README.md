# Piwik QueuedTracking Plugin

## Description

This plugin writes all tracking requests into a [Redis](http://redis.io/) instance instead of directly into the database. 
This is useful if you have too many requests per second and your server cannot handle all of them directly (eg too many connections in nginx or MySQL). 
It is also useful if you experience peaks sometimes. Those peaks can be handled much better by using this queue. 
Writing a tracking request into the queue is very fast (a few milliseconds) compared to a regular tracking request that 
takes multiple hundreds milliseconds. The queue makes sure to process the tracking requests whenever possible even if it may
take a while to process all requests within the queue.

*This plugin is currently BETA and there might be issues causing not tracked requests, wrongly tracked requests or duplicated tracked requests.*

Have a look at the FAQ for more information.

## FAQ

__What are the requirements for this plugin?__

* [Redis server 2.8+](http://redis.io/), [Redis quickstart](http://redis.io/topics/quickstart)
* [phpredis PHP extension](https://github.com/nicolasff/phpredis), [Install](https://github.com/nicolasff/phpredis#installingconfiguring)
* Transactions are used and must be supported by the SQL database.

__Where can I configure and enable the queue?__

In your Piwik instance go to "Settings => Plugin Settings". There will be a section for this plugin.

__I do not want to process tracking requests within a tracking request, what shall I do?__

First make sure to disable the setting "Process during tracking request" in "Plugin Settings". Then setup a cronjob that 
executes the command `./console queuedtracking:process` for instance every 30 seconds or every minute. That's it. This command
will make sure to process all queued tracking requests whenever possible.

Example crontab entry that starts the processor every minute:

`* * * * * cd /piwik && ./console queuedtracking:process >/dev/null 2>&1`

__Can I keep track of the state of the queue?__

Yes, you can. Just execute the command `./console queuedtracking:monitor`. This will show the current state of the queue.

__How should the redis server be configured?__

Make sure to have enough space to save all tracking requests in the queue. One tracking request in the queue takes about 2KB, 20.000 tracking requests take about 50MB. 
All tracking requests of all websites are stored in the same queue.
There should be only one Redis server to make sure the data will be replayed in the same order as they were recorded. If you want
to configure Redis HA (High Availability) it should be possible to use Redis Cluser, Redis Sentinel, ...

__Why do some tests fail on my local Piwik instance?__

Make sure the requirements mentioned above are met and Redis needs to run on 127.0.0.1:6379 with no password for the
integration tests to work. It will use the database "15" and the tests may flush all data it contains. Make sure
it does not contain any important data.

__What if I want to disable the queue?__

It might be possible that you disable the queue but there are still some pending requests in the queue. We recommend to 
change the "Number of requests to process" in plugin settings to "1" and process all requests using the command 
`./console queuedtracking:process` shortly before disabling the queue and directly afterwards.

__How can I access Redis data?__

You can either acccess data on the command line via `redis-cli` or use a Redis monitor like [phpRedisAdmin](https://github.com/ErikDubbelboer/phpRedisAdmin).
In case you are using something like a Redis monitor make sure it is not accessible by everyone.


__Are there any known issues?__

In case you are using bulk tracking the response varies compared to the normal bulk tracking. We will always return either
an image or a 204 HTTP response code in case the parameter `send_image=0` is sent.

## Changelog

0.1.0 Initial Release

## Support

Please direct any feedback to [hello@piwik.org](mailto:hello@piwik.org)

## TODO

For usage with multiple redis servers we should lock differently: 
http://redis.io/topics/distlock eg using https://github.com/ronnylt/redlock-php 