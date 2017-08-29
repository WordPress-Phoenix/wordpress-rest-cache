# WP REST Cache

**Please note:** this plugin uses persistant and lazy cache to be the most efficient, see below for how that works.

# Basic Usage

## Installation

### Automated remote installation
* We recommend you install this plugin using [GitHub Updater remote installations](https://github.com/afragen/github-updater/blob/develop/README.md#remote-installation-of-repositories)

### Manual Installation
1. Download the latest [tagged archive](https://github.com/afragen/github-updater/releases) (choose the "zip" option).
2. Unzip the archive, rename the folder correctly to `github-updater`, then re-zip the file.
3. Go to the __Plugins -> Add New__ screen and click the __Upload__ tab.
4. Upload the zipped archive directly.
5. Go to the Plugins screen and click __Activate__.
## Advanced Options
* There are no UIX options related to this plugin, its just plug-and-play

## Controlling cache with wp_remote_get args

### Setting custom cache times / expirations
```PHP
wp_remote_get( $url, array( 
    'wp-rest-cache' => array( 
        'expires' => 12 * HOUR_IN_SECONDS,
        'tag'        => 'mytag'
    )
);
```
### Disable or Exclude cache 
```PHP
wp_remote_get( $url, array( 
    'wp-rest-cache' => 'exclude',
);
```

# How it works details
## Summary
Cache all WordPress REST requests made via `wp_remote_get()` in a custom table. 
After using transients and option rows to cache REST calls with limited success 
due to the unpredictability of how memcached or other setups would handle many 
calls, we moved all REST calls into their own table. This is especially useful 
where the same call in a multisite network may be made on many sites: the same 
response can be reused and only requires one MYSQL lookup.

When serving a previously cached request that is expired, the current request 
will get the expired version stored in the DB until a cron runs in the background 
and checks/updates anything expired. We make the concession of potentially serving 
responses that are out of date for the sake of speed. The end user will not 
have to wait for the request to be made at any point in their experience if the request 
has been previously cached. 

## Persistant Cache
Persistant cache is unlike WordPress transients in such that, when a transient expires, the value is immediately "deleted". Transients produce a null value if its requested and unavaialble. This was undesirable in our plugin, and as such, our values persist in the system even after they expire.

*Best explained in a user story:

Non-Persisting (cache off)
Whats the applicants status?
200 - accepted
500 - null

Persisting (1s cache)
Whats the applicants status?
200 - accepted
500 - pending

What sites are in this division?
Non-Persisting (cache off)
200 - ew,instyle
500 - null

Persisting (1s cache)
200 - ew,instyle
500 - ew,instyle, people

## Lazy Cache
Most cache engines make the end user wait for the system to generate or obtain new content after the cache expires. This means that `some users will experience a slow load time`. This was undesirable, especially when waiting on REST calls on slow web services. To resolve this issue for a great experience for all visitors, we use a lazy caching methodology that creates a "queue" of expired cache data. The queue is handled by WordPress cron, away from the user experience, allowing the cache to be updated in the background on the server while the front end user experience is unaffected.

*User story to explain lazy cache:

User 1: First visitor
What sites are in this division?
Cache Created - expires in 6 hours - `ew, instyle`

*** somebody adds a new value in divisions called `people`

User 2-100: 5 Hours and 59 Minutes
What sites are in this division?
Cache Hit - expires in 1 minute - `ew, instyle`

User 101-110: 6 Hours
What sites are in this division?
Cache Hit - expired and in queue - `ew, instyle`

*** cron runs and fetches new content at REST endpoint

User 101-110: 6 Hours 5 minutes
What sites are in this division?
Cache Hit - Expires in 5 Hours 55 minutes - `ew, instyle, people`
