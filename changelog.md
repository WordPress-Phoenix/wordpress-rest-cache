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