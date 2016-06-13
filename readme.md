# WP REST Cache
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

# Basic Usage
* This plugin can be installed via the GitHub Updater plugin. 