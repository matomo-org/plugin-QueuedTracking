# Piwik QueuedTracking Plugin

[![Build Status](https://travis-ci.org/matomo/plugin-QueuedTracking.svg?branch=master)](https://travis-ci.org/matomo/plugin-QueuedTracking)

## Description

This plugin writes all tracking requests into a [Redis](http://redis.io/) instance or a MySQL queue instead of directly into the database.
This is useful if you have too many requests per second and your server cannot handle all of them directly (eg too many connections in nginx or MySQL).
It is also useful if you experience peaks sometimes. Those peaks can be handled much better by using this queue.
Writing a tracking request into the queue is very fast (a tracking request takes in total a few milliseconds) compared to a regular tracking request (that takes multiple hundreds of milliseconds). The queue makes sure to process the tracking requests whenever possible even if it takes a while to process all requests after there was a peak.

Have a look at the FAQ for more information.

## Support

In case of any issues with the plugin or feature wishes create a new issues here: 
https://github.com/piwik/plugin-QueuedTracking/issues . In case you experience
any problems please post the output of `./console queuedtracking:test` in the issue.

## TODO

For usage with multiple redis servers we should lock differently:
http://redis.io/topics/distlock eg using https://github.com/ronnylt/redlock-php
