#### 1.3.1
* Critical update: if you were manually defining an `expires` value as 
  a string, it was automatically setting the expiration time to 1 second.

#### 1.3.0
* Modify new column names to `rest_key` and `rest_status_code`, improve table rename/update
* Add missing filter for new caching of error codes that aren't just 200

#### 1.2.0
* Allow non-200 responses to be stored in the REST Cache. 
  New `status_code` column keeps track of response status
* Set default cache lengths for different response codes
* New `key` column -- this is only being inserted/updated at the moment, not used, 
  but will replace use of the MD5 for cached item lookups in the next version.
* Add admin utility for clearing cache values older than X days 
  (limited to 100 cleared at a time).

#### 1.1.1
* Hotpatch late cache init
* Patch cron to run 5 minutes instead of 15 seconds

#### 1.1.0
* New cache clearing features on admin page
* Rebuilt great deal of infrastructure for easier extending
* Corrected init priority for GHU
* Extended utility class with "contains" query model

#### 1.0.5
* Hotfix more GHU adjustments
* Organized filters into filter class
* run rest_cache->init() on actual wp init hook

#### 1.0.4
* Version bump fixes

#### 1.0.2
* More GHU Transient Changes

#### 1.0.1
* Hotfix GHU cache clear sync

#### 1.0.0
* Tested in production at scale for several days, ready to call plugin released and out of beta
* Fixed github updater double caching issues and removed transients from GHU to reduce transient cache overhead, especially useful for reducing VARNISH / REDIS bandwidth issues.

#### 0.10.0
* Major bugfix: Cached values were never properly updating. Fixed with better code organization 
  and a clearer update-via-cron process!

#### 0.9.1
* Bugfix: correct mis-labeled admin helper table columns

#### 0.9.0
* Major update to core functionality: replace transports filter with `pre_http_response` and `http_response`.
  
  With WP 4.6, the filters previously used to hook into the remote_get functions and 
  cache are now unusable. Functionality has been moved to utilize the `pre_http_response` and 
  `http_response` filters instead, in each case verifying that the remote call needs to be 
  made based on the `is_cacheable_call()` check.
* New admin page helpers to identify old/unused calls and to search and clear specific calls.

#### 0.8.0
* Add an admin (or network admin, in the case of multisite) utility page to add utilities to.
* Adjust caching mechanism to exclude `remote_get` calls made during cron. 
  This is specifically to exclude calls made on links during pingback checks.
* Exclude oEmbed fetching from the caching process

#### 0.7.1
* Add a multisite check before scheduling cron that updates outdated entries. 
  Cron now should only run on the primary blog if we're in a Multisite environment.

#### 0.7.0
* Add a `filename` argument check so we can avoid caching download get requests. 
  This helps handle potential issues with downloads via the download_url() function.

...

#### 0.1.1
* Hooking into HTTP class actions

#### 0.1
* Initial plugin as boilerplate from https://github.com/scarstens/worpress-plugin-boilerplate-redux
