# Matomo QueuedTracking Plugin

[![Plugin QueuedTracking Tests](https://github.com/matomo-org/plugin-QueuedTracking/actions/workflows/matomo-tests.yml/badge.svg)](https://github.com/matomo-org/plugin-QueuedTracking/actions/workflows/matomo-tests.yml)

## Description

This plugin writes all tracking requests into a [Redis](http://redis.io/) instance or a MySQL queue instead of directly into the database.
This is useful if you have too many requests per second and your server cannot handle all of them directly (eg too many connections in nginx or MySQL).
It is also useful if you experience peaks sometimes. Those peaks can be handled much better by using this queue.
Writing a tracking request into the queue is very fast (a tracking request takes in total a few milliseconds) compared to a regular tracking request (that takes multiple hundreds of milliseconds). The queue makes sure to process the tracking requests whenever possible even if it takes a while to process all requests after there was a peak.

Have a look at the FAQ for more information.

## Support

In case of any issues with the plugin or feature wishes create a new issues here: 
https://github.com/matomo-org/plugin-QueuedTracking/issues . In case you experience
any problems please post the output of `./console queuedtracking:test` in the issue.

## TODO

For usage with multiple Redis servers we should lock differently:
http://redis.io/topics/distlock e.g. using https://github.com/ronnylt/redlock-php
