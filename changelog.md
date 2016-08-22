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