# Matomo Platform Changelog

This is the Developer Changelog for Matomo platform developers. All changes in our HTTP API's, Plugins, Themes, SDKs, etc. are listed below.

The Product Changelog at **[matomo.org/changelog](https://matomo.org/changelog)** lets you see more details about any Matomo release, such as the list of new guides and FAQs, security fixes, and links to all closed issues. 

## Matomo 4.4.0

### Breaking Changes

* The `logme` method for automatic logins is now disabled by default for new installations. For existing installations it will be enabled automatically on update. If you do not need it please consider disabling it again for security reasons by setting `login_allow_logme = 0` in `General` section of `config.ini.php`.
* The redirect using the `url` param for the automatic login action `logme`, will no longer do redirects to untrusted hosts. If you need to do redirects to other URLs on purpose, please add the according hosts as `trusted_hosts` entry in `config.ini.php`

### New config.ini.php settings

* When determining the client IP address from proxy headers like X-Forwarded-For, Matomo will by default look at the first IP in the list. If you need to read the last IP instead, the new INI config option `[General] proxy_ip_read_last_in_list` be set to `1`. Using the last IP can be more secure when you are using proxy headers in combination with a load balancer.

### Changes to events

* It is now possible via the Mail.send event to abort sending emails. Set the `$mail` event parameter to null to do this.

## Matomo 4.3.1

### New commands

* Added new command `core:create-security-files` which creates some web server security files if they haven't existed previously (useful when using for example Apache or IIS web server).

## Matomo 4.3.0

### JavaScript Tracker

#### Breaking changes in Matomo JS tracker

* Before the JS tracker method, `enableLinkTracking` did not follow the DOM changes, from this version when the DOM updates, Matomo automatically adds event listeners for new links on the page. It makes it easier to track clicks on links in SPAs. From this version, if we use the `addListener` method to add event listener manually after the DOM has changed and the `enableLinkTracking` is turned on we will track the click event for that element twice.

### Breaking Changes

* Before every JS error was tracked, from this version the same JS error will be only tracked once per page view. If the very same error is happening multiple times, then it will be tracked only once within the same page view. If another page view is tracked or when the page reloads, then the error will be tracked again.
* It's no longer possible to store any class instances directly in the session object. Please use arrays or plain data instead.

### Upcoming Breaking Changes

* In Matomo 4.3.0 we have added a 'passwordConfirmation' parameter to the CorePluginsAdmin.setSystemSettings API method. It is currently optional, but will become mandatory in version 4.4.0. Plugin developers and users of the API should make sure to update their plugins and apps before this happens.

### New config.ini.php settings

* The `password_hash_algorithm`, `password_hash_argon2_threads`, `password_hash_argon2_memory_cost` and `password_hash_argon2_time_cost` INI config options have been added to allow using specific `password_hash` algorithms and options if desired.
* The `enable_php_profiler` INI config option was added. This must now be set to 1 before profiling is allowed in Matomo.

## Matomo 4.2.0

### New config.ini.php settings

* A config setting `geolocation_download_from_trusted_hosts` was introduced. Downloading GeoIP databases will now be limited to those configured hosts only.

## Matomo 4.1.1

### Changed config.ini.php settings

* The config settings `login_password_recovery_email_address` and `login_password_recovery_name` have been removed to avoid possible smtp problems when sending recovery mails. `noreply_email_address` and `noreply_email_name` will be used instead.

## Matomo 4.0.0

### JavaScript Tracker

#### Breaking changes in Matomo JS tracker

* Matomo no longer polyfills the `JSON` object in the JavaScript tracker. This means IE7 and older, Firefox 3 and older will be no longer suppported in the tracker. 
* The JavaScript tracker now uses `sendBeacon` by default if supported by the browser. You can disable this by calling the tracker method `disableAlwaysUseSendBeacon`. As a result, callback parameters won't work anymore and a tracking request might not appear in the developer tools. This will improve the load time of your website. Tracking requests will be sent as POST request instead of GET but the parameters are by default included in the URL so they don't go lost in a redirect. 
* The JS tracker event `PiwikInitialized` has been renamed to `MatomoInitialized`
* Support for tracking and reporting of these browser plugins has been discontinued: Gears, Director
* Plugins that extend the JS tracker should now add their callback to `matomoPluginAsyncInit` instead of `piwikPluginAsyncInit`
* The visitor ID cookie now contains less data (due to the _idvc, _idts, _viewts and _ects tracking parameters no longer being used). This is a breaking change if you use the Matomo PHP Tracker and forward the visitor cookie to it, and you will need to upgrade the PHP tracker to use with Matomo 4.
* The tracker method `setVisitStandardLength` has been removed as there is no need for it anymore.
* The tracker method `setGenerationTimeMs(generationTime)` has been removed as the performance API is now used. Any calls to this method will be ignored. There is currently no replacement available yet.

#### Deprecations in Matomo JS tracker

* The JS Tracker method `getPiwikUrl` has been deprecated and `getMatomoUrl` should be used instead.
* The JS Tracker init method `piwikAsyncInit` has been deprecated and `matomoAsyncInit` should be used instead.
* The JS object `window.Piwik` has been deprecated and `window.Matomo` should be used instead.

#### Recommendations for Matomo JS tracker

These are only recommendations (because we will keep backward compatibility for many more years), but we do recommend you update your code for consistency and for future proofing your tracking:

* If using the `piwik_ignore` css class to ignore outlinks we recommend replacing it with `matomo_ignore` 
* If using the `piwik_download` css class to mark a link as download we recommend replacing it with `matomo_download` 
* If using content tracking, we recommend replacing the following CSS classes should they be used `piwikTrackContent`, `piwikContentPiece`, `piwikContentTarget`, and `piwikContentIgnoreInteraction` with `matomoTrackContent`, `matomoContentPiece`, `matomoContentTarget`, and `matomoContentIgnoreInteraction`. 
* We also encourage using the `matomo.js` JS tracker file instead of `piwik.js` and `matomo.php` tracker endpoint instead of `piwik.php` endpoint.

#### New APIs
* A new JS tracker method `getMatomoUrl` has been added which replaces `getPiwikUrl`.

### HTTP APIs

#### Breaking changes in HTTP API 

##### Format changes
* The `JSON2` API format has now been deprecated and is now applied  by default. The JSON2 renderer will be removed in Matomo 5 and we recommend switching to the `JSON` renderer. 
* The `JSON` renderer now behaves like the previous `JSON2` renderer did. This means arrays like `['a' => 0, 'b' => 1]` will be rendered in JSON as `{"a":0,"b":1}` instead of `[{"a":0,"b":1}]`. This impacts these API methods:
  * API.getSettings
  * Annotations.get
  * Goals.getGoal
  * UsersManager.getUser
  * UsersManager.getUserByEmail
  * SitesManager.getSiteFromId
* The API response format `php` has been removed.
* The response of an individual request within the bulk request of `API.getBulkRequest` may change if the API returns a scalar value (eg `5`). In this case the response will be no longer `5` but for example `{value: 5}`

##### Method changes
* The API method `UsersManager.getTokenAuth` has been removed. Instead you need to use `UsersManager.createAppSpecificTokenAuth` and store this token in your application.
* The API method `UsersManager.createTokenAuth` has been removed. Instead you need to use `UsersManager.createAppSpecificTokenAuth` and store this token in your application.
* The API method `DevicesDetection.getBrowserFamilies` has been removed. Instead you need to use `DevicesDetection.getBrowsers`
* The API method `CustomPiwikJs.doesIncludePluginTrackersAutomatically` has been renamed to `CustomJsTracker.doesIncludePluginTrackersAutomatically`
* The API method `Live.getLastVisitsForVisitor` has been removed. Use `Live.getVisitorProfile` instead.
* The API method `Live.getLastVisits` has been removed. Use `Live.getLastVisitsDetails` instead.
* These API methods have been removed: `API.getDefaultMetricTranslations`, `API.getLogoUrl`, `API.getHeaderLogoUrl`, `API.getSVGLogoUrl`,   `API.hasSVGLogo`
* These API methods have been removed: `SitesManager.getSitesIdWithVisits`, `SitesManager.isSiteSpecificUserAgentExcludeEnabled`, `SitesManager.setSiteSpecificUserAgentExcludeEnabled`
* These API methods have been removed: `Referrers.getKeywordsForPageUrl` and `Referrers.getKeywordsForPageTitle`. Use `Referrers.getKeywords` instead in combination with a `entryPageUrl` or `entryPageTitle` segment.
* The parameter `alias` from the API methods `UsersManager.addUser` and `UsersManager.updateUser` has been removed.

#### HTTP Tracking API

* An optional new tracking parameter called `ca` has been added which can be used for tracking requests that aren't page views see [#16569](https://github.com/matomo-org/matomo/issues/16569)

### PHP Plugin API

#### New PHP events

* Added new event `Db.getTablesInstalled`, plugins should use to register the tables they create.

#### Breaking changes in PHP events

* The event `CustomPiwikJs.piwikJsChanged` has been renamed to `CustomJsTracker.trackerJsChanged`
* The event `CustomPiwikJs.shouldAddTrackerFile` has been renamed to `CustomJsTracker.shouldAddTrackerFile`
* The event `CustomMatomoJs.shouldAddTrackerFile` has been renamed to `CustomJsTracker.manipulateJsTracker`
* The event `Live.getAllVisitorDetails` has been removed. Use a `VisitorDetails` class instead (see Live plugin).
* The event `Live.getExtraVisitorDetails'` has been removed. Use the `VisitorDetails` class within each plugin instead.
* The event `Piwik.getJavascriptCode` has been renamed to `Tracker.getJavascriptCode`.
* The event `LanguageManager.getAvailableLanguages` has been removed. Use `LanguagesManager.getAvailableLanguages` instead.
* The `$completed` parameter for the 'CronArchive.archiveSingleSite.finish' event has been removed. For both this event and the CronArchive.archiveSingleSite.start event, a new
  parameter is added for the process' pid. Multiple processes can now trigger this event for the same site ID.

#### Removed methods and constants in PHP Plugin API

* The method `\Piwik\Plugin::getListHooksRegistered()` has been removed. Use `\Piwik\Plugin::registerEvents()` instead
* The method `\Piwik\Piwik::doAsSuperUser()` has been removed. Use `\Piwik\Access::doAsSuperUser()` instead
* The method `\Piwik\SettingsPiwik::isPiwikInstalled()` has been removed. Use `\Piwik\SettingsPiwik::isMatomoInstalled()` instead
* The method `\Piwik\Updates::getSql()` has been removed. Use `\Piwik\Updates::getMigrations()` instead
* The method `\Piwik\Updates::getMigrationQueries()` has been removed. Use `\Piwik\Updates::getMigrations()` instead
* The method `\Piwik\Updates::executeMigrationQueries()` has been removed. Use `\Piwik\Updates::executeMigrations()` instead
* The method `\Piwik\Updates::update()` has been removed. Use `\Piwik\Updates::doUpdate()` instead
* The method `\Piwik\Updater::updateDatabase()` has been removed. The method is not needed anymore.
* The method `\Piwik\Common::json_encode()` has been removed. Use `json_encode()` instead
* The method `\Piwik\Common::json_decode()` has been removed. Use `json_decode()` instead
* The method `\Piwik\Common::getContinentsList()` has been removed. Use `\Piwik\Intl\Data\Provider\RegionDataProvider::getContinentList()` instead
* The method `\Piwik\Common::getCountriesList()` has been removed. Use `\Piwik\Intl\Data\Provider\RegionDataProvider::getCountriesList()` instead
* The method `\Piwik\Common::getLanguagesList()` has been removed. Use `\Piwik\Intl\Data\Provider\LanguageDataProvider::getLanguagesList()` instead
* The method `\Piwik\Common::getLanguageToCountryList()` has been removed. Use `\Piwik\Intl\Data\Provider\LanguageDataProvider::getLanguageToCountryList()` instead
* The method `\Piwik\Site::getCurrencyList()` has been removed. Use `\Piwik\Intl\Data\Provider\CurrencyDataProvider::getCurrencyList()` instead
* The method `\Piwik\Piwik::setUserHasSuperUserAccess()` has been removed. Use `\Piwik\Access::doAsSuperUser()` instead
* The class `\Piwik\MetricsFormatter` has been removed. Use `Piwik\Metrics\Formatter` or `Piwik\Metrics\Formatter\Html` instead
* The class `\Piwik\Registry` has been removed. Use `\Piwik\Container\StaticContainer` instead
* The class `\Piwik\TaskScheduler` has been removed. Use `\Piwik\Scheduler\Scheduler` instead
* The class `\Piwik\DeviceDetectorFactory` has been removed. Use `\Piwik\DeviceDetector\DeviceDetectorFactory` instead
* The class `\Piwik\ScheduledTask` has been removed. Use `\Piwik\Scheduler\Task` instead.
* The class `\Piwik\Translate` has been removed. Use `\Piwik\Translation\Translator` instead.
* The class `\Piwik\Plugins\Login\SessionInitializer` is no longer considered API as it is no longer needed.
* The class `\Piwik\Container\StaticContainer` still exists but we no longer consider it an API and constructor injection should be used instead where possible.
* The method `Piwik\Columns\Dimension::factory` has been removed. Use `DimensionsProvider::factory` instead.
* The method `Piwik\Config::reset` has been removed. Use the `reload` method instead.
* The method `Piwik\Config::init` has been removed. Use the `reload()` method instead.
* The method `Piwik\Db::getColumnNamesFromTable` has been removed. Use the `TableMetadata::getColumns` method instead.
* The method `Piwik\Session\SessionInitializer::getHashTokenAuth` has been removed. There is no need for this method anymore.
* The method `Piwik\Tracker::getDatetimeFromTimestamp` has been removed. Use `Piwik\Date::getDatetimeFromTimestamp` instead.
* The method `Dimension::addSegment()` has been removed. See new implementation of `DimensionSegmentFactory::createSegment` for a replacement
* The constant `Piwik\Plugins\Goals\API::NEW_VISIT_SEGMENT` has been removed. Use `Piwik\Plugins\VisitFrequency\API::NEW_VISITOR_SEGMENT` instead.
* The signature of `Dimension::configureSegments()` has been changed. Similar to configuring Metrics it now takes two parameters `SegmentsList $segmentsList` and `DimensionSegmentFactory $dimensionSegmentFactory`.
* The signature of the event `Segment.addSegments` has been changed. It now has one parameter `SegmentsList $list`, which allows adding new segments to the list
* The core plugin `CustomPiwikJs` has been renamed to `CustomJsTracker`
* The class `Piwik\Plugins\CustomPiwikJs\TrackerUpdater` has been renamed to `Piwik\Plugins\CustomJsTracker\TrackerUpdater`
* The method `Piwik\Cookie::set` no longer accepts an array as value
* `Zend_Validate` and all subclasses have been completely removed. 
* Matomo's mail component (`Piwik\Mail`) has been rewritten:
  * Zend_Mail has been removed. `Piwik\Mail` is now an independet class.
  * PHPMailer is now used for sending mails in `\Piwik\Mail\Transport` and can be replaced using DI.
  * Various methods in `Piwik\Mail` have been removed or changed their signature.
  
#### New APIs
* A new API `UsersManager.createAppSpecificTokenAuth` has been added to create an app specific token for a user.
* A new method `Common::hashEquals` has been added for timing attack safe string comparisons.
* Reporting API: It is now possible to apply `hideColumns` recursively to nested values by setting `hideColumnsRecursively=1`. For all `Live` api methods this is the default behaviour.

### Other Breaking changes

* When embedding reports (widgets) into a different site, it is no longer possible to use authentication tokens of users with at least write access, unless the `[General] enable_framed_allow_write_admin_token_auth` is set. This means if you currently rely on this functionality, you will need to update your matomo config when updating to Matomo 4. Alternatively, create a user with `view` access and use the token of this user to embed the report.
* The log importer in `misc/log-analytics` now supports Python 3 (3.5, 3.6, 3.7 or 3.8), it will no longer run with Python 2. If you have any automated scripts that run the importer, you will have to change them to use the Python 3 executable instead.
* Deprecated `piwik` font was removed. Use `matomo` font instead
* The JavaScript AjaxHelper does not longer support synchronous requests. All requests will be sent async instead.
* The console option `--piwik-domain` has been removed. Use `--matomo-domain` instead
* The controller action `Proxy.redirect` has been removed. Instead link to the URL directly in HTML and set an attribute `rel="noreferrer noopener"`  
* GeoIP Legacy support has been fully removed. Users of GeoIP Legacy need to set up a new location provider like GeoIP2, otherwise the default location provider will be used.
* Site search category and count are no longer stored as custom variables. That also means they will now have an extra field in action details and no longer appear in custom variables.
* The dimension and `log_link_visit_action` column interaction_position has been renamed to pageview_position. If your database queries rely on the column you can simply replace the name.
* The metric (avg.) page generation time has been deprecated. It is no longer possible to track it. Already tracked values will still be shown in old reports. More detailed performance metrics are now available in PagePerformance plugin.
* Added support for campaign name parameter `matomo_campaign` / `mtm_campaign` and campaign keyword parameter `matomo_kwd` / `mtm_kwd`
* The following dimensions have been removed and replaced with versions that measure seconds: visitor_days_since_first, visitor_days_since_last, visitor_days_since_order
* The _idvc, _idts, _viewts and _ects tracker parameters are no longer used, the values are calculated server side.
  Note: tracking these values server side means replaying log data in the past will result in inaccurate values for these dimensions.
* The Dependency Injection library PHP-DI was updated. [Some definitions need to be updated]((https://php-di.org/doc/migration/6.0.html)):
  * The Method `\DI\object()` has been removed. You can use `\DI\autowire()` or `\DI\create()` instead.
  * The Method `\DI\link()` has been removed. Use `\DI\get()` instead.
  * Defining global observer functions in config now requires the functions to be wrapped in `\DI\value()`, unless they are a factory.

### New config.ini.php settings

* `host_validation_use_server_name = 0`, if set to 1, Matomo will prefer using SERVER_NAME variable over HTTP_HOST. This can add an additional layer of security, as SERVER_NAME can't be manipulated by sending custom host headers when configured correctly.


## Matomo 3.14.0

### New API

The following new JavaScript tracker methods have been added:

* `_paq.push(['setVisitorId', visitorId]);`. This can be used to force a specific visitorId. It takes a 16 digit hexadecimal string.
* `_paq.push(['requireCookieConsent']);`. Call this method if cookies should be only used when consent was given.
* `_paq.push(['rememberCookieConsentGiven']);`. Call this method when a user gives you cookie consent.
* `_paq.push(['forgetCookieConsentGiven']);`. Call this method when a user revokes cookie consent.
* `_paq.push(['setCookieConsentGiven']);`. Call this method to let the tracker know consent was given for the current page view (won't be remembered across requests).
* For more info on consent have a look at https://developer.matomo.org/guides/tracking-javascript-guide#asking-for-consent

## Matomo 3.13.6

### API Changes
* The first parameter `userLogin` in the `UsersManager.getUserPreference` method is now optional and defaults to the currently authenticated user login.

## Matomo 3.13.5

### New API
* A new event `ArchiveProcessor.ComputeNbUniques.getIdSites` was added so plugins can change which site IDs should be included when processing the number unique visitors and users for a specific site.

## Matomo 3.13.1

### Deprecations
* The methods `\Piwik\Plugins\SitesManager\isSiteSpecificUserAgentExcludeEnabled()` and `\Piwik\Plugins\SitesManager\setSiteSpecificUserAgentExcludeEnabled()` have been deprecated.
* The method `\Piwik\SettingsServer::isMatomoForWordPress()` has been added so plugins can detect if the plugin is being executed within Matomo for WordPress or Matomo On-Premise 

## Matomo 3.13.0

### New API
* New tracker method `setVisitStandardLength` which lets you configure a custom visit standard length in case a custom "visit_standard_length" is configured in the config. Setting only applies when heart beat is enabled.
* Added new event `Metrics.isLowerValueBetter` so plugins can define if lower metric values are better for additional metrics.

### Other Changes
* User ID is no longer linked to visitor ID. Actions with different user IDs will still be considered as part of different visits, irrespective of their visitor IDs, but the same visitor ID can be used with different user IDs.

## Matomo 3.12.0

### New API
* Added new event `Visualization.beforeRender`, triggered after immediately before rendering a visualization.
* Added new event `Http.sendHttpRequest` and `Http.sendHttpRequest.end` so plugins can listen to external HTTP requests, monitor them, or resolve the request themselves.
* Added new event `CliMulti.supportsAsync` so plugins can force or disable the usage of archiving through the CLI

## Matomo 3.10.0

### Breaking Changes
* When giving a user superuser access through the `UsersManager.setSuperUserAccess` API, a new parameter `passwordConfirmation` needs to be sent along with the request containing the current password of the user issuing the API request.
* Website referrer URLs are now detected using domain only instead of domain and path. This means if you have two different websites on the same domain, but different paths, and a visitor visits from one to the other, it won't have a referrer website set.
* Custom Dimensions values set with `setCustomDimension` are now URL encoded (they previously weren't). If you were manually URL encoding the custom dimension values before calling `setCustomDimension`, your custom dimension values appearing in reports and Visits log/Visitor profile may now be double URL encoded. To solve the double encoding issue, you can remove your URL encoding and trust that Matomo JavaScript Tracker will URL encode the values correctly.

### New APIs
* A new tracker method `ping` has been added to send a ping request manually instead of using the heart beat timer.
* Added new event `ViewDataTable.configure.end`, triggered after view configuration properties have been overwritten by saved settings and query parameters.

## Matomo 3.9.0

### Breaking Changes
* `Referrers.getKeywordsForPageUrl` and `Referrers.getKeywordsForPageTitle` APIs have been deprecated and will be removed in Matomo 4.0.0
* By default, Matomo [application logs](https://matomo.org/faq/troubleshooting/faq_115/) will now be logged in `tmp/logs/matomo.log` instead of `tmp/logs/piwik.log`. This log file path can be edited in your config/config.ini.php in the INI setting `logger_file_path`.

### New Features
* It is now possible to locate plugins in a custom directory by setting an environment variable `MATOMO_PLUGIN_DIRS` or a `$GLOBALS['MATOMO_PLUGIN_DIRS']` variable in `$MATOMO_ROOT/bootstrap.php`.
* It is now possible to use monolog's FingersCrossedHandler which buffers all logs and logs all of them in case of warning or error.

### New APIs
* New API methods `Piwik\Plugin\Manager::getPluginsDirectories()` and  `Piwik\Plugin\Manager::getPluginDirectory($pluginName)` have been added as it is now possible to locate Matomo plugins in different directories and it should be no longer assumed a plugin is located in the "/plugins" directory.
* A new tracker method `disableQueueRequest` has been added to disable queued requests which may be useful when logs are imported.
* The event `LanguageManager.getAvailableLanguages` has been deprecated. Use `LanguagesManager.getAvailableLanguages` instead.

## Matomo 3.8.0

### Breaking Changes
* When changing the email address or the password through the `UsersManager.updateUser` API, a new parameter `passwordConfirmation` needs to be sent along with the request containing the current password of the user issuing the API request.
* The output type "save on disk" in the API method `ScheduledReport.generateReport` has been replaced by the download output type.
* The method `Piwik\Piwik::doAsSuperUser` has been deprecated and will be removed in Matomo 4. Use `Piwik\Access::doAsSuperUser` instead.

### New APIs

* It is now possible to queue a request on the JavaScript tracker using the method `queueRequest(requestUrl)`. This can be useful to group multiple tracking requests into one bulk request to reduce the number of tracking requests that are sent to your server making the tracking more efficient.
* When specifying a callback in the JavaScript tracker in a tracker method, we now make sure to execute the callback even in error cases or when sentBeacon is used. The callback receives an event parameter to determine which request was sent and whether the request was sent successfully.
* Added new event `Metrics.getEvolutionUnit` which lets you set the unit for a metric used in evolution charts and row evolution.

### New Features
* The log importer now supports the `--tracker-endpoint-path` parameter which allows you to use a different tracker endpoint than `/piwik.php`, if desired.
* It is now possible to define different log levels for different log writers via INI config. Set log_level_file, for example, to set the log level for the file writer, or log_level_screen for the screen writer.

### Internal change
* New Matomo installation will now use by default "matomo.js" and "matomo.php" as tracking endpoints. From Matomo 4.0 all installations will use "matomo.js" and "matomo.php" by default. We recommend you ensure those files can be accessed through the web and are not blocked.

### Deprecations
* The method `Piwik\SettingsPiwik::isPiwikInstalled()` has been deprecated and renamed to `isMatomoInstalled()`. It is still supported to use the method, but the method will be removed in Piwik 4.0.0

## Matomo 3.6.1

### New APIs

* Added new event `Access.modifyUserAccess` which lets plugins modify current user's access levels/permissions.
* Added new event `CustomMatomoJs.manipulateJsTracker` which lets plugins modify the JavaScript tracker.

### New Developer Features

* Logging to a file can now be easily enabled during tests. A new `[tests] enable_logging` INI option has been added, which you can set to `1` to enable logging for all tests. The `tests:run` and `tests:run-ui` commands now both have an `--enable-logging` option to enable logging for a specific run.

## Matomo 3.6.0

### New Features

* A new role has introduced called "write" which has less permissions than an admin but more than a view only user ([see FAQ](https://matomo.org/faq/general/faq_26910/)).
* Custom currencies can now be added using the `currencies[]` configuration key.
* A new segment `eventValue` lets you select all users who tracked a custom event with a given value or range of values.

### New config.ini.php settings

* `archiving_profile = 0`, if set to 1, core:archive profiling information will be recorded in a log file. the log file is determined by the `archive_profiling_log` option.
* `archive_profiling_log = `, if set to an absolute path, core:archive profiling information will be logged to specified file.
* `enable_internet_features=0` will now fully disable Internet access by preventing all outgoing connections. Note: changing this setting is not recommended for security, because you will lose the easy auto-update and email notifications.
* `login_whitelist_ip[]` now supports hostnames so you can [whitelist](https://matomo.org/faq/how-to/faq_25543/) your IP addresses and/or Hostnames and keep your Matomo secure.

### Updated commands

* New parameter `--concurrent-archivers` to define the number of maximum archivers to run in parallel on this server. Useful to prevent archiving processes piling up and ultimately failing.

### New APIs

* Added new event `API.addGlossaryItems` which lets you add items to the glossary.
* Added new event `Tracker.detectReferrerSocialNetwork` which lets you add custom social network detections
* Added new event `Report.unsubscribe` which is triggered whenever someone unsubscribe from a report
* Added new API method `UsersManager.getAvailableRoles` to fetch a list of all available roles that can be granted to a user.
* Added new API method `UsersManager.getAvailableCapabilities` to fetch a list of all available capabilities that can be granted to a user.
* Added new API method `UsersManager.addCapabilities` to grant one or multiple capabilities to a user.
* Added new API method `UsersManager.removeCapabilities` to remove one or multiple capabilities from a user.
* The API method `UsersManager.setUserAccess` now accepts an array to pass a role and multiple capabilities at once.
* Plugin classes can overwrite the method `requiresInternetConnection` to define if they should be automatically unloaded if no internet connection is available (enable_internet_features = 0)
* Added two new methods to the JS tracker: `removeEcommerceItem` and `clearEcommerceCart` to allow better control over what is in the ecommerce cart.
* Tracking API requests now include `&consent=1` in the Tracking API URL When [consent](https://developer.matomo.org/guides/tracking-javascript-guide#asking-for-consent) has been given by a user.

### Breaking Changes
* Changed some menu items to use translation keys instead (see [PR #12885](https://github.com/matomo-org/matomo/pull/12885)).
* The methods `assertResponseCode()` and `assertHttpResponseText()` in `Piwik\Tests\Framework\TestCase\SystemTestCase` have been deprecated and will be removed in Matomo 4.0. Please use `Piwik\Http` instead.
* The classes `PHPUnit\Framework\Constraint\HttpResponseText` and `PHPUnit\Framework\Constraint\ResponseCode` have been deprecated and will be removed in Matomo 4.0. Please use `Piwik\Http` instead.
* Creating links through the Proxy has been deprecated. Use rel="nofollow" instead.
* The console option `--piwik-domain` has been deprecated and will be removed in Matomo 4.0. Use `--matomo-domain` instead
* Social networks are now detected as new referrer type (ID=7), which allows improved reports and better segmentation
* New settings form field UI component "Field Array" that lets users enter multiple values for one setting as a flat array

## Matomo 3.5.1

### New APIs

* Added new method `Piwik\API\Request::isRootRequestApiRequest()` to detect if the root request is an API request.

## Matomo 3.5.0

### Breaking Changes

* Flattened action url reports now always include a leading `/` and will no longer include the `default_action_name`. e.g. `path/to/index` will now be `/path/to/`. This might affect configured custom alerts, as this plugin uses the flattened url reports for comparison.

### New APIs

* New JavaScript tracker functions to [ask for consent](https://developer.matomo.org/guides/tracking-javascript-guide#asking-for-consent): `requireConsent`, `rememberConsentGiven`, `setConsentGiven`, `forgetConsentGiven`.

### New Features
* New events `PrivacyManager.deleteLogsOlderThan`, `PrivacyManager.exportDataSubjects` and `PrivacyManager.deleteDataSubjects` to enable plugins to be GDPR compliant.  
* New event `AssetManager.addStylesheets` to add additional less styles which are not located in a file.
* New event `Archiving.getIdSitesToMarkArchivesAsInvalidated` that lets plugins customize the behaviour of report invalidations.
* Reports and visualizations can now disable the 'all' rows limit selector: `$view->config->disable_all_rows_filter_limit`.
* New settings form field UI component "Multi Tuple" that lets users enter multiple values for one setting

## Matomo 3.4.0

### Breaking Changes
`piwik` font is deprecated and will be removed in Matomo 4.0. Please use new `matomo` font instead
Sending synchronous requests using ajaxHelper is now deprecated. All requests will be send async as of Matomo 4.0

### New APIs
* A new JavaScript tracker method `resetUserId` has been added to allow clearing user and visitor id.
* A new event `Actions.addActionTypes` has been added, to allow plugins to add their custom action types.
* Dashboard API has been extended by the methods `copyDashboardToUser`, `createNewDashboardForUser`, `removeDashboard` and `resetDashboardLayout`
  * It is also now possible to delete the first dashboard for a user for automation purposes. Doing so and not adding a new first dashboard might result in buggy UX.
  * `getDashboards` API method has been extended by additional parameters to fetch dashboards for specific user
* A new event `API.Request.intercept` has been added which allows plugins to intercept API requests to perform custom logic, overriding the original API method.
* A new event `Request.shouldDisablePostProcessing` has been added which allows plugins to disable DataTable post processing for individual API requests.
* A new event `SitesManager.shouldPerformEmptySiteCheck` has been added to allow plugins to disable the empty site check for individual sites.
* A new JavaScript tracker method `getCrossDomainLinkingUrlParameter` has been added so you can add cross domain tracking capability to dynamically created links. [Learn here how to append the result to said links' URLs, see the section "Advanced: Handling Dynamically Generated Links"](https://matomo.org/faq/how-to/faq_23654/) 

## Matomo 3.3.0

Piwik is now Matomo. Read more about this change in the [official announcement](https://matomo.org/blog/2018/01/piwik-is-now-matomo).

### New APIs

* New HTTP API `API.getMatomoVersion` was introduced. The previous HTTP API `API.getPiwikVersion` will still work but will now be hidden from the API reference page.

## Piwik 3.2.2

### Breaking Changes
* The `historyService` along with `broadcast.init`, `broadcast.propagateAjax`, `broadcast.pageLoad` have been deprecated and will be removed in Piwik 4. 


## Piwik 3.2.1

### New APIs

### New Features
 * Themes can now customize the header text color using `@theme-color-header-text`
 * New event `Widgetize.shouldEmbedIframeEmpty` added so plugins can optionally define the output of the widgetized HTML themselves
 * New events added to add and filter visitor details: `Live.addProfileSummaries` and `Live.filterProfileSummaries`
 * New JavaScript method `piwikHelper.registerShortcut` allows plugins to bind keyboard shortcuts. A summary for available shortcuts will be shown by pressing `?`

## Piwik 3.2.0

### New Segments
* New Segment added: `visitStartServerMinute` for Server time - minute (Start of visit)
* New Segment added: `visitEndServerMinute` for Server time - minute (End of visit)
* New events added to add and filter visitor details: `Live.addVisitorDetails` and `Live.filterVisitorDetails`

### New APIs
* Reports and visualizations can now hide the export icons with a new property `$view->config->show_export`.
* Reports and visualizations can now show a message above the report with a new property `$view->config->show_header_message`.
* The following events have been added:
  * `Metric.addMetrics` Triggered to add new metrics that cannot be picked up automatically by the platform.
  * `Metric.addComputedMetrics` Triggered to add computed metrics that are not generated automatically
  * `Metric.filterMetrics` Triggered to filter metrics
* The following new API classes have been added:
 * `Piwik\Columns\MetricsList` Holds a list of all available metrics
 * `Piwik\Columns\ComputedMetricFactory` Can be used to create computed metrics
 * `Piwik\Columns\DimensionMetricFactory` Can be used to create metrics directly within a dimension

### New Features
* New config.ini.php setting `show_update_notification_to_superusers_only` makes it possible to hide update notifications for all users except of superusers

## Piwik 3.1.0

### Breaking Changes
* The event `Live.getAllVisitorDetails` has been deprecated and will be removed in Piwik 4. Use a `VisitorDetails` class instead (see Live plugin). 

### New Features
* New method `setSecureCookie` that sets the cookie's secure parameter

### New APIs
* The events `ScheduledTasks.shouldExecuteTask`, `ScheduledTasks.execute`, `ScheduledTasks.execute.end` have been added to customize the behaviour of scheduled tasks.
* A new event `CustomPiwikJs.shouldAddTrackerFile` has been added to let plugins customize which tracker files should be included in piwik.js JavaScript tracker
* A new event `Login.authenticate.successful` has been added, which is triggered when a user successful signs in
* A new API class `Piwik\Plugins\CustomPiwikJs\TrackerUpdater` has been added to update the piwik.js JavaScript tracker

### New commands
* The commands `plugin:activate` and `plugin:deactivate` can now activate and deactivate multiple plugins at once

## Piwik 3.0.4

### New APIs
* A new event `Db.getActionReferenceColumnsByTable` has been added in case a plugin defines a custom log table which references data to the log_action table 
* The event `System.addSystemSummaryItems` and `System.filterSystemSummaryItems` have been added so plugins can add items and filter items of the system summary widget
* A new JavaScript tracker method `getPiwikUrl` has been added to retrieve the URL of where the Piwik instance is located
* A new JavaScript tracker method `getCurrentUrl` has been added to retrieve the current URL of the website. 
* A new JavaScript tracker method `getNumTrackedPageViews` has been added to retrieve the number of tracked page views within the currently loaded page or web application. 
* New JavaScript tracker methods `setSessionCookie`, `getCookie`, `hasCookies`, `getCookieDomain`, `getCookiePath`, and `getSessionCookieTimeout` have been added for better cookie support in plugins. 
* `email` and `url` form fields can now be used in settings.

## Piwik 3.0.3

### Breaking Changes
* New config setting `enable_plugin_upload` lets you enable uploading and installing a Piwik plugin ZIP file by a Super User. This used to be enabled by default, but it is now disabled by default now for security reasons.
* New Report class property `Report::$supportsFlatten` lets you define if a report supports flattening (defaults to `true`). If set to `false` it will also set `ViewDataTable\Config::$show_flatten_table` to `false`

### New APIs
* A new event `Controller.triggerAdminNotifications` has been added to let plugins know when they are supposed to trigger notifications in the admin.

### Library updates
* pChart library has been removed in favor of [CpChart](https://github.com/szymach/c-pchart), a pChart fork with composer support and PSR standards. 

## Piwik 3.0.2

### New Features
* A new SMS provider for sms reports has been added: [ASPSMS.com](https://www.aspsms.com/en/?REF=227830)

### New APIs
* The JavaScript Tracker now supports CrossDomain tracking. The following tracker methods were added for this: `enableCrossDomainLinking`, `disableCrossDomainLinking`, `isCrossDomainLinkingEnabled`
* Added JavaScript Tracker method `getLinkTrackingTimer` to get the value of the configured link tracking time
* Added JavaScript Tracker method `deleteCustomVariables` to delete all custom variables within a certain scope
* The method `enableLinkTracking` can now be called several times to make Piwik aware of newly added links when your DOM changes
* Added a new method `Piwik\Plugin\Report::getMetricNamesToProcessReportTotals()` that lets you define which metrics should show percentages in the table report visualization on hover. If defined, these percentages will be automatically calculated.
* The event `Tracker.newConversionInformation` now posts a new fourth parameter `$action`
* New HTTP API method `UserCountry.getCountryCodeMapping` to get a list of used country codes to country names

### Changes
* SMS provider now can define their credential fields by overwriting `getCredentialFields()`. This allows to have SMS providers that require more than only an API key.
* Therefore the MobileMessaging API method `setSMSAPICredential()` now takes the second parameter as an array filled with credentials (instead of a string containing an API key)

## Piwik 3.0.1

### New APIs
* Live API responses now return a new field ‘generationTimeMilliseconds’ (the generation time for this page, in milliseconds) which is internally used to process the Average generation time in the [Visitor Profile](https://matomo.org/docs/user-profile/)
* Added new event `MultiSites.filterRowsForTotalsCalculation` to filter which sites will be included in the All Websites Dashboard totals calculation.
* The method `Piwik\Plugin\Archiver::shouldRunEvenWhenNoVisits()` has been added. By overwriting this method and returning true, a plugin archiver can force the archiving to run even when there was no visit for the website/date/period/segment combination (by default, archivers are skipped when there is no visit).

## Piwik 3.0.0

### New guide

Read more about migrating a plugin from Piwik 2.X to Piwik 3 in [our Migration guide](https://developer.matomo.org/guides/migrate-piwik-2-to-3).

### Breaking Changes
* When using the Piwik JavaScript Tracking via `_paq.push`, it is now required to configure the tracker (eg calling `setSiteId` and `setTrackerUrl`) before the `piwik.js` JavaScript tracker is loaded to ensure the tracker works correctly. 
If the tracker is not initialised correctly, the browser console will display the error "_paq.push() was used but Piwik tracker was not initialized before the piwik.js file was loaded. [...]" 
* The UserManager API methods do no longer return any `token_auth` properties when requesting a user
* The menu classes `Piwik\Menu\MenuReporting` and `Piwik\Menu\MenuMain` have been removed
* The class `Piwik\Plugin\Widgets` has been removed and replaced by `Piwik\Widget\Widget`. For each widget one class is needed from now on. You can generate a widget via `./console generate:widget`.
* The class `Piwik\WidgetList` class has been moved to `Piwik\Widget\WidgetsList`.
* The method `Piwik\Plugins\API\API::getLastDate()` has been removed.
* The method `Piwik\Archive::getDataTableFromArchive()` has been removed, use `Piwik\Archive::createDataTableFromArchive` instead.
* The method `Piwik\Plugin\Menu::configureReportingMenu` has been removed. To add something to the reporting menu you need to create widgets
* The method `Report::configureWidget()`, `Report::getWidgetTitle()` and `Report::configureReportingMenu()` have been removed, use the new method `Report::configureWidgets()` instead.
* The method `Report::getCategory()` has been moved to `Report::getCategoryId()` and does no longer return the translated category but the translation key of the category.
* The property `Report::$category` has been renamed to `Report::$categoryId`
* The methods `Report::factory()`, `Report::getAllReportClasses()`, `Report::getAllReports` have been moved to the `Piwik\Plugin\Reports` class.
* The properties `Report::$widgetTitle`, `Report::$widgetParams` and `Report::$menuTitle` were removed, use the method `Report::configureWidgets()` to create widgets instead
* In the HTTP API methods `Dashboard.getDefaultDashboard` and `Dashboard.getUserDashboards` we do no longer remove not existing widgets as it is up to the client which widgets actually exist
* The method `Piwik\Plugin\Controller::getEvolutionHtml` has been removed without a replacement as it should be no longer needed. The evolution is generated by ViewDataTables directly
* The `core:plugin` console command has been removed in favor of the new `plugin:list`, `plugin:activate` and `plugin:deactivate` commands as announced in Piwik 2.11
* The visibility of private properties and methods in `Piwik\Plugins\Login\Controller` were changed to `protected`
* Controller actions are now case sensitive. This means the URL and events have to use the same case as the name of the action defined in a controller. 
* When calling the HTTP Reporting API, a default filter limit of 100 is now always applied. The default filter limit used to be not applied to API calls that do not return reports, such as when requesting sites, users or goals information.
* The "User Menu" was removed and should be replaced by "Admin Menu". Change `configureUserMenu(MenuUser $menu)` to `configureAdminMenu(MenuAdmin $menu)` in your `Menu.php`.
* The method `Piwik\Menu\MenuAbstract::add()` has been removed, use `Piwik\Menu\MenuAbstract::addItem()` instead
* The method `Piwik\Menu\MenuAdmin::addSettingsItem()` was removed, use  `Piwik\Menu\MenuAdmin::addSystemItem()` instead.
* A new method `Piwik\Menu\MenuAdmin::addMeasurablesItem()` was added.
* The class `Piwik\Plugin\Settings` has been split to `Piwik\Settings\Plugin\SystemSettings` and `Piwik\Settings\Plugin\UserSettings`.
* The creation of settings has slightly changed to improve performance. It is now possible to create new settings via the method `$this->makeSetting()` see `Piwik\Plugins\ExampleSettingsPlugin\SystemSettings` for an example.
* It is no longer possible to define an introduction text for settings.
* If requesting multiple periods for one report, the keys that define the range are no longer translated. For example before 3.0 an API response may contain: `<result date="From 2010-02-01 to 2010-02-07">` which is now `<result date="2010-02-01,2010-02-07">`.
* The following deprecated events have been removed as mentioned.
 * `Tracker.existingVisitInformation` Use [dimensions](https://developer.matomo.org/guides/dimensions) instead of using `Tracker` events.
 * `Tracker.newVisitorInformation`
 * `Tracker.recordAction`
 * `Tracker.recordEcommerceGoal`
 * `Tracker.recordStandardGoals`
 * `API.getSegmentDimensionMetadata` Define segments in [Dimension](https://developer.matomo.org/guides/dimensions) instead
 * `Menu.Admin.addItems` Create a [Menu](https://developer.matomo.org/guides/menus) instead of using `Menu` events
 * `Menu.Reporting.addItems`
 * `Menu.Top.addItems`
 * `ViewDataTable.addViewDataTable` Create a [Visualization](https://developer.matomo.org/guides/visualizing-report-data) instead
 * `ViewDataTable.getDefaultType` Specify the default type in a [Report](https://developer.matomo.org/guides/custom-reports) instead
 * `Login.authenticate`  Create a custom SessionInitializer instead of using `Login` events
 * `Login.initSession.end`
 * `Login.authenticate.successful`
* When posting one of the events `API.Request.dispatch`, `API.Request.dispatch.end`, `API.$plugin.$apiAction`, or `API.$plugin.$apiAction.end` the `$finalParameters` parameter is indexed in Piwik 2 (eg `array(1, 6)`), and named in Piwik 3 (eg `array('idSite' => 1, 'idGoal' => 6)`)
* Widgets using the already removed `UserSettings` plugin won't work any longer. Please update the module and action parameter in the widget url according to the following list

   old module | old action | new module | new action
   ---------- | ---------- | ---------- | ----------
   UserSettings | getPlugin | DevicePlugins | getPlugin
   UserSettings | index | DevicesDetection | software
   UserSettings | getBrowser | DevicesDetection | getBrowsers
   UserSettings | getBrowserVersions | DevicesDetection | getBrowserVersions
   UserSettings | getMobileVsDesktop | DevicesDetection | getType
   UserSettings | getOS | DevicesDetection | getOsVersions
   UserSettings | getOSFamily | DevicesDetection | getOsFamilies
   UserSettings | getBrowserType | DevicesDetection | getBrowserEngines
   UserSettings | getResolution | Resolution | getResolution
   UserSettings | getConfiguration | Resolution | getConfiguration
   UserSettings | getLanguage | UserLanguage | getLanguage
   UserSettings | getLanguageCode | UserLanguage | getLanguageCode


Read more about migrating a plugin from Piwik 2.X to Piwik 3 on our [Migration guide](https://developer.matomo.org/guides/migrate-piwik-2-to-3).

### Deprecations
* The method `Piwik\Updates::getMigrationQueries()` has been deprecated and renamed to `getMigrations()`. It is still supported to use the method, but the method will be removed in Piwik 4.0.0
* The method `Piwik\Updater::executeMigrationQueries()` has been deprecated and renamed to `executeMigrations`. It is still supported to use the method, but the method will be removed in Piwik 4.0.0.

### New APIs
* Multiple widgets for one report can now be created via the `Report::configureWidgets()` method via the new classes `Piwik\Widget\ReportWidgetFactory` and `Piwik\Widget\ReportWidgetConfig`
* There is a new property `Report::$subCategory` that lets you add a report to the reporting UI. If a page having that name does not exist yet, it will be created automatically. The newly added method `Report::getSubCategory()` lets you get this value.
* The new classes `Piwik\Widget\Widget`, `Piwik\Widget\WidgetConfig` and `Piwik\Widget\WidgetContainerConfig` lets you create a new widget.
* The new class `Piwik\Category\Subcategory` let you change the name and order of menu items
* New HTTP API method `API.getWidgetMetadata` to get a list of available widgets
* New HTTP API method `API.getReportPagesMetadata` to get a list of all available pages that exist including the widgets they include
* New HTTP API method `SitesManager.getSiteSettings` to get a list of all available settings for a specific site
* The JavaScript AjaxHelper has a new method `ajaxHelper.withTokenInUrl()` to easily send a token along a XHR. Within the Controller the existence of this token can be checked via `$this->checkTokenInUrl();` to prevent CSRF attacks.
* The new class `Piwik\Updater\Migration\Factory` lets you easily create migrations that can be executed during an update. For example database or plugin related migrations. To generate a new update with migrations execute `./console generate:update`.
* The new method `Piwik\Updater::executeMigration` lets you execute a single migration.
* The new method `Piwik\Segment::willBeArchived` lets you detect whether a segment will be archived or not.
* The following events have been added:
 * `ViewDataTable.filterViewDataTable` lets you filter available visualizations
 * `Dimension.addDimension` lets you add custom dimensions
 * `Dimension.filterDimension` lets you filter any dimensions
 * `Report.addReports` lets you add dynamically created reports
 * `Report.filterReports` lets you filter any report
 * `Updater.componentUpdated` triggered after core or a plugin has been updated
 * `PluginManager.pluginInstalled` triggered after a plugin was installed
 * `PluginManager.pluginUninstalled` triggered after a plugin was uninstalled
 * `Updater.componentInstalled` triggered after a component was installed
 * `Updater.componentUninstalled` triggered after a component was uninstalled
* New HTTP Tracking API parameter `pv_id` which accepts a six character unique ID that identifies which actions were performed on a specific page view. Read more about it in the [HTTP Tracking API](https://developer.matomo.org/api-reference/tracking-api);
* New event `Segment.addSegments` that lets you add segments.
* New Piwik JavaScript Tracker method `disableHeartBeatTimer()` to disable the heartbeat timer if it was previously enabled.
* The `SitesManager.getJavascriptTag` has a new option `getJavascriptTag` to enable the tracking of users that have JavaScript disabled

### Changes
* New now accept tracking requests for up to 1 day in the past instead of only 4 hours
* If a tracking request has a custom timestamp that is older than one day and the tracking request is not authenticated, we ignore the whole tracking request instead of ignoring the custom timestamp and still tracking the request with the current timestamp

### New features
* Piwik JavaScript Tracking API: we now attempt to track Downloads and Outlinks when the user uses the mouse middle click or the mouse right right click. Previously only left clicks on Downloads and Outlinks were measured. 
* New "Sparklines" visualization that lets you create a widget showing multiple sparklines.
* New config.ini.php setting: `tracking_requests_require_authentication_when_custom_timestamp_newer_than` to change how far back Piwik will track your requests without authentication. By default, value is set to 86400 (one day). The configured value is in seconds.

### Library updates
* Updated AngularJS from 1.2.28 to 1.4.3
* Updated several backend libraries to their latest version: doctrine/cache, php-di.

### Internal change
* Support for IE8 was dropped. This affects only the Piwik UI, not the Piwik.js Tracker.
* Required PHP version was increased from 5.3 to 5.5.9
* We have updated PhantomJS 1.9 to 2.1.1 for our UI screenshot tests.

## Piwik 2.16.3

### New APIs
* The Piwik JavaScript tracker has a new method `trackRequest` that allows you to send any tracking parameters to Piwik. For example  `_paq.push(['trackRequest', 'te=foo&bar=baz'])`

### Internal Changes
* Expected screenshots for UI tests are now stored using Git LFS instead of a submodule. Running, creating or updating UI tests will require Git LFS to be installed.
The folder containing expected screenshots was renamed from `expected-ui-screenshots` to `expected-screenshots`. The UI-Test-Runner is now able to handle both names.

## Piwik 2.16.2

### New APIs
 * Multiple JavaScript trackers can now be created easily via `_paq.push(['addTracker', piwikUrl, piwikSiteId])`. All tracking requests will be then sent to all added Piwik trackers. [Learn more.](http://developer.matomo.org/guides/tracking-javascript-guide#multiple-piwik-trackers)
 * It is possible to get an asynchronously created tracker instance (`addTracker`) via the method `Piwik.getAsyncTracker(optionalPiwikUrl, optionalPiwikSiteId)`. This allows you to get the tracker instance and to send different tracking requests to this Piwik instance and to configure it differently than other tracker instances.
 * Added a new API method `Goals.getGoal($idSite, $idGoal)` to fetch a single goal.
 
### Internal change
 * Piwik is now compatible with PHP7. 
 * `piwik.js`, if you call the method `setDomains` note that that the behavior has slightly changed. The current page domain (hostname) will now be added automatically if none of the given host alias passed as a parameter to `setDomains` contain a path and if no host alias is already given for the current host alias. 
 Say you are on "example.org" and set `hostAlias = ['example.com', 'example.org/test']` then the current "example.org" domain will not be added as there is already a more restrictive hostAlias 'example.org/test' given. 
 We also do not add the current page domain (hostname) automatically if there was any other host specifying any path such as `['example.com', 'example2.com/test']`. 
 In this case we also do not add the current page domain "example.org" automatically as the "path" feature is used. As soon as someone uses the path feature, for Piwik JS Tracker to work correctly in all cases, one needs to specify all hosts manually. [Learn more.](http://developer.matomo.org/guides/tracking-javascript-guide#measuring-domains-andor-sub-domains)
 * `piwik.js`: after an ecommerce order is tracked using `trackEcommerceOrder`, the items in the cart will now be removed from the JavaScript object. Calling `trackEcommerceCartUpdate` will not remove the items in the cart.   


## Piwik 2.16.1

### New features
 * New method `setIsWritableByCurrentUser` for `SystemSetting` to change the writable permission for certain system settings via DI.
 * JS Tracker: `setDomains` function now supports page wildcards matching eg. `example.com/index*` which can be useful when [tracking a group of pages within a domain in a separate website in Piwik](http://developer.matomo.org/guides/tracking-javascript-guide#tracking-a-group-of-pages-in-a-separate-website)
 * To customise the list of URL query parameters to be removed from your URLs, you can now define and overwrite  `url_query_parameter_to_exclude_from_url` INI setting in your `config.ini.php` file. By default, the following query string parameters will be removed: `gclid, fb_xd_fragment, fb_comment_id, phpsessid, jsessionid, sessionid, aspsessionid, doing_wp_cron, sid`.

### Deprecations
* The following PHP functions have been deprecated and will be removed in Piwik 3.0:
 * `SettingsServer::isApache()` 

### New guides
  * JavaScript Tracker: [Measuring domains and/or sub-domains](http://developer.matomo.org/guides/tracking-javascript-guide#measuring-domains-andor-sub-domains)
  
### Breaking Changes
 * Reporting API: when a cell value in a CSV or TSV (excel) data export starts with a character `=`, `-` or `+`, Piwik will now prefix the value with `'` to ensure that it is displayed correctly in Excel or OpenOffice/LibreOffice.   
 
### Internal change
 * Tracking API: by default, when tracking a Page URL, Piwik will now remove the URL query string parameter `sid` if it is found. 
 * In the JavaScript tracker, the function `setDomains` will not anymore attempt to set a cookie path. Learn more about [configuring the tracker correctly](http://developer.matomo.org/guides/tracking-javascript-guide#tracking-one-domain) when tracking one or several domains and/or paths.

## Piwik 2.16.1

### Internal change
 * The setting `[General]enable_marketplace=0/1` was removed, instead the new plugin Marketplace can be disabled/enabled. The updater should automatically migrate an existing setting.

## Piwik 2.16.0

### New features
 * New segment `actionType` lets you segment all actions of a given type, eg. `actionType==events` or `actionType==downloads`. Action types values are: `pageviews`, `contents`, `sitesearches`, `events`, `outlinks`, `downloads`
 * New segment `actionUrl` lets you segment any action that matches a given URL, whether they are Pageviews, Site searches, Contents, Downloads or Events.
 * New segment `deviceBrand` lets you restrict your users to those using a particular device brand such as Apple, Samsung, LG, Google, Nokia, Sony, Lenovo, Alcatel, etc. View the [complete list of device brands.](http://developer.matomo.org/api-reference/segmentation)
 * New segment operators `=^` "Starts with" and `=$` "Ends with" complement the existing segment operators: Contains, Does not contain, Equals, Not equals, Greater than or equal to, Less than or equal to.
 * The JavaScript Tracker method `PiwikTracker.setDomains()` can now handle paths. This means when setting eg `_paq.push(['setDomains, '*.matomo.org/website1'])` all link that goes to the same domain `matomo.org` but to any other path than `website1/*` will be treated as outlink.
 * In Administration > Websites, for each website, there is a checkbox "Only track visits and actions when the action URL starts with one of the above URLs". In Piwik 2.14.0, any action URL starting with one of the Alias URLs or starting with a subdomain of the Alias URL would be tracked. As of Piwik 2.15.0, when this checkbox is enabled, it may track less data: action URLs on an Alias URL subdomain will not be tracked anymore (you must specify each sub-domain as Alias URL).  
 * It is now possible to pass an option `php-cli-options` to the `core:archive` command. The given cli options will be forwarded to the actual PHP command. This allows to for example specify a different memory limit for the archiving process like this: `./console core:archive --php-cli-options="-d memory_limit=8G"`
 * New less variable `@theme-color-menu-contrast-textSelected` that lets you specify the color of a selected menu item.
 * in Administration > Diagnostics, there is a new page `Config file` which lets Super User view all config values from `global.ini.php` in the UI, and whether they were overridden in your `config/config.ini.php`

### New commands
 * New command `config:set` lets you set INI config options from the command line. This command can be used for convenience or for automation.
   
### Internal changes
 * `UsersManager.*` API calls: when an API request specifies a `token_auth` of a user with `admin` permission, the returned dataset will not include all usernames as previously, API will now only return usernames for users with `view` or `admin` permission to website(s) viewable by this `token_auth`. 
 * When generating a new plugin skeleton via `generate:plugin` command, plugin name must now contain only letters and numbers.
 * JavaScript Tracker tests no longer require `SQLite`. The existing MySQL configuration for tests is used now. In order to run the tests make sure Piwik is installed and `[database_tests]` is configured in `config/config.ini.php`.
 * The definitions for search engine and social network detection have been moved from bundled data files to a separate package (see [https://github.com/matomo-org/searchengine-and-social-list](https://github.com/matomo-org/searchengine-and-social-list)).
 * In [UI screenshot tests](https://developer.matomo.org/guides/tests-ui), a test environment `configOverride` setting should be no longer overwritten. Instead new values should be added to the existing `configOverride` array in PHP or JavaScript. For example instead of `testEnvironment.configOverride = {group: {name: 1}}` use `testEnvironment.overrideConfig('group', 'name', '1')`.

### New APIs
 * Add your own SMS/Text provider by creating a new class in the `SMSProvider` directory of your plugin. The class has to extend `Piwik\Plugins\MobileMessaging\SMSProvider` and implement the required methods.
 * Segments can now be composed by a union of multiple segments. To do this set an array of segments that shall be used for that segment `$segment->setUnionOfSegments(array('outlinkUrl', 'downloadUrl'))` instead of defining a SQL column.

### Deprecations
 * The method `DB::tableExists` was un-used and has been removed.


## Piwik 2.15.0 

### New commands
 *  New command `diagnostics:analyze-archive-table` that analyzes archive tables
 *  New command `database:optimize-archive-tables` to optimize archive tables and possibly save disk space (even if on InnoDB)
 *  New Command `core:invalidate-report-data` to invalidate archive data (w/ period cascading) ([FAQ](https://matomo.org/faq/how-to/faq_155/))

### New APIs and features
* Piwik 2.15.0 is now mostly compatible with PHP7. 
* The JavaScript Tracker `piwik.js` got a new method `logAllContentBlocksOnPage` to log all found content blocks within a page to the console. This is useful to debug / test content tracking. It can be triggered via `_paq.push(['logAllContentBlocksOnPage'])`
* The Class `Piwik\Plugins\Login\Controller` is now considered a public API.
* The new method `Piwik\Menu\MenuAbstract::registerMenuIcon()` can be used to define an icon for a menu category to replace the default arrow icon.
* New event `CronArchive.getIdSitesNotUsingTracker` that allows you to set a list of idSites that do not use the Tracker API to make sure we archive these sites if needed.
* New events `CronArchive.init.start` which is triggered when the CLI archiver starts and `CronArchive.end` when the archiver ended.
* Piwik tracker can now be configured with strict Content Security Policy ([CSP FAQ](https://matomo.org/faq/general/faq_20904/)).
* Super Users can choose whether to use the latest stable release or latest Long Term Support release. 

### Breaking Changes
* The method `Dimension::getId()` has been set as `final`. It is not allowed to overwrite this method.
* We fixed a bug where the API method `Sites.getPatternMatchSites` only returned a very limited number of websites by default. We now return all websites by default unless a limit is specified specifically.
* Handling of localized date, time and range formats has been changed. Patterns no longer contain placeholders like %shortDay%, but work with CLDR pattern instead. You can use one of the predefined format constants in Date class for using getLocalized().
* As we are now using CLDR formats for all languages, some time formats were even changed in english. Attributes like prettyDate in API responses might so have been changed slightly.
* The config `enable_measure_piwik_usage_in_idsite` which is used to track the Piwik usage with Piwik was removed and replaced by a new plugin `AnonymousPiwikUsageMeasurement`

### Deprecations
* The following HTTP API methods have been deprecated and will be removed in Piwik 3.0:
 * `SitesManager.getSitesIdWithVisits` 
 * `API.getLastDate` 
* The following events have been deprecated and will be removed in Piwik 3.0. Use [dimensions](https://developer.matomo.org/guides/dimensions) instead.
 * `Tracker.existingVisitInformation`
 * `Tracker.getVisitFieldsToPersist`
 * `Tracker.newConversionInformation`
 * `Tracker.newVisitorInformation`
 * `Tracker.recordAction`
 * `Tracker.recordEcommerceGoal`
 * `Tracker.recordStandardGoals`
* The Platform API method `\Piwik\Plugin::getListHooksRegistered()` has been deprecated and will be removed in Piwik 4.0. Use `\Piwik\Plugin::registerEvents()` instead.


### Internal changes
* When logging in, the username is now case insensitive 
* URLs with emojis and any other unicode character will be tracked, with special characters replaced with `�`
* A permanent warning notification is now displayed when PHP is 5.4.* or older, since it has reached End Of Life 
* In `piwik.js` we replaced [JSON2](https://github.com/douglascrockford/JSON-js) with [JSON3](https://bestiejs.github.io/json3/) to implement CSP (Content Security Policy) as JSON3 does not use `eval()`. JSON3 will be used if a browser does not provide a native JSON API. We are using `JSON3` in a way that it will not conflict if your website is using `JSON3` as well.
* The option `branch` of the console command `development:sync-system-test-processed` was removed as it is no longer needed.
* All numbers in reports will now appear formatted (eg. `1,000,000` instead of `1000000`)
* Database connections now use `UTF-8` charset explicitly to force UTF-8 data handling

## Piwik 2.14.0

### Breaking Changes
* The `UserSettings` API has been removed. The API was deprecated in earlier versions. Use `DevicesDetection`, `Resolution` and `DevicePlugins` API instead.
* Many translations have been moved to the new Intl plugin. Most of them will still work, but please update their usage. See https://github.com/matomo-org/matomo/pull/8101 for a full list 

### New features 
* The JavaScript Tracker does now track outlinks and downloads if a user opens the context menu if the `enabled` parameter of the `enableLinkTracking()` method is set to `true`. To use this new feature use `tracker.enableLinkTracking(true)` or `_paq.push(['enableLinkTracking', true]);`. This is not industry standard and is vulnerable to false positives since not every user will select "Open in a new tab" when the context menu is shown. Most users will do though and it will lead to more accurate results in most cases.
* The JavaScript Tracker now contains the 'heart beat' feature which can be used to obtain more accurate visit lengths by periodically sending 'ping' requests to Piwik. To use this feature use `tracker.enableHeartBeatTimer();` or `_paq.push(['enableHeartBeatTimer']);`. By default, a ping request will be sent every 15 seconds. You can specify a custom ping delay (in seconds) by passing an argument, eg, `tracker.enableHeartBeatTimer(10);` or `_paq.push(['enableHeartBeatTimer', 10]);`.
* New custom segment `languageCode` that lets you segment visitors that are using a particular language. Example values: `de`, `fr`, `en-gb`, `zh-cn`, etc.
* Segment `userId` now supports any segment operator (previously only operator Contains `=@` was supported for this segment).

### Commands updates
* The command `core:archive` now has two new parameter: `--force-idsegments` and `--skip-idsegments` that let you force (or skip) processing archives for one or several custom segments.
* The command `scheduled-tasks:run` now has an argument `task` that lets you force run a particular scheduled task.

### Library updates
* Updated pChart library from 2.1.3 to 2.1.4. The files were moved from the directory `libs/pChart2.1.3` to `libs/pChart`

### Internal change
* To execute UI tests "ImageMagick" is now required.
* The Q JavaScript promise library is now distributed with tests and can be used in the piwik.js tests.

## Piwik 2.13.0

### Breaking Changes
* The API method `Live.getLastVisitsDetails` does no longer support the API parameter `filter_sort_column` to prevent possible memory issues when `filter_offset` is large.
* The Event `Site.setSite` was removed as it causes performance problems.
* `piwik.php` does now return a HTTP 400 (Bad request) if requested without any tracking parameters (GET/POST). If you still want to use `piwik.php` for checks please use `piwik.php?rec=0`.

### Deprecations
* The method `Piwik\Archive::getBlob()` has been deprecated and will be removed from June 1st 2015. Use one of the methods `getDataTable*()` methods instead.
* The API parameter `countVisitorsToFetch` of the API method `Live.getLastVisitsDetails` has been deprecated as `filter_offset` and `filter_limit` work correctly now.

### New commands
* There is now a `diagnostic:run` command to run the system check from the command line.
* There is now an option `--xhprof` that can be used with any command to profile that command via XHProf.

### APIs Improvements
* Visitor details now additionally contain: `deviceTypeIcon`, `deviceBrand` and `deviceModel`
* In 2.6.0 we added the possibility to use `filter_limit` and `filter_offset` if an API returns an indexed array. This was not working in all cases and is fixed now. 
* The API parameter `filter_pattern` and `filter_offset[]` can now be used if an API returns an indexed array.

### Internal changes

* The referrer spam filter has moved from the `referrer_urls_spam` INI option (in `global.ini.php`) to a separate package (see [https://github.com/matomo-org/referrer-spam-list](https://github.com/matomo-org/referrer-spam-list)).

## Piwik 2.12.0

### Breaking Changes
* The deprecated method `Period::factory()` has been removed. Use `Period\Factory` instead.
* The deprecated method `Config::getConfigSuperUserForBackwardCompatibility()` has been removed.
* The deprecated methods `MenuAdmin::addEntry()` and `MenuAdmin::removeEntry()` have been removed. Use `Piwik\Plugin\Menu` instead.
* The deprecated methods `MenuTop::addEntry()` and `MenuTop::removeEntry()` have been removed. Use `Piwik\Plugin\Menu` instead.
* The deprecated method `SettingsPiwik::rewriteTmpPathWithInstanceId()` has been removed.
* The following deprecated methods from the `Piwik\IP` class have been removed, use `Piwik\Network\IP` instead:
  * `sanitizeIp()`
  * `sanitizeIpRange()`
  * `P2N()`
  * `N2P()`
  * `prettyPrint()`
  * `isIPv4()`
  * `long2ip()`
  * `isIPv6()`
  * `isMappedIPv4()`
  * `getIPv4FromMappedIPv6()`
  * `getIpsForRange()`
  * `isIpInRange()`
  * `getHostByAddr()`

### Deprecations
* `API` classes should no longer have a protected constructor. Classes with a protected constructor will generate a notice in the logs and should expose a public constructor instead.
* Update classes should not declare static `getSql()` and `update()` methods anymore. It is still supported to use those, but developers should instead override the `Updates::getMigrationQueries()` and `Updates::doUpdate()` instance methods.

### New features
* `API` classes can now use dependency injection in their constructor to inject other instances.

### New commands
* There is now a command `core:purge-old-archive-data` that can be used to manually purge temporary, error-ed and invalidated archives from one or more archive tables.
* There is now a command `usercountry:attribute` that can be used to re-attribute geolocated location data to existing visits and conversions. If you have visits that were tracked before setting up GeoIP, you can use this command to add location data to them.

## Piwik 2.11.0

### Breaking Changes
* The event `User.getLanguage` has been removed.
* The following deprecated event has been removed: `TaskScheduler.getScheduledTasks`
* Special handling for operating system `Windows` has been removed. Like other operating systems all versions will now only be reported as `Windows` with versions like `XP`, `7`, `8`, etc.
* Reporting for operating systems has been adjusted to report information according to browser information. Visitor details now contain: `operatingSystemName`, `operatingSystemIcon`, `operatingSystemCode` and `operatingSystemVersion`

### Deprecations
* The following methods have been deprecated in favor of the new `Piwik\Intl` component:
  * `Piwik\Common::getContinentsList()`: use `RegionDataProvider::getContinentList()` instead
  * `Piwik\Common::getCountriesList()`: use `RegionDataProvider::getCountryList()` instead
  * `Piwik\Common::getLanguagesList()`: use `LanguageDataProvider::getLanguageList()` instead
  * `Piwik\Common::getLanguageToCountryList()`: use `LanguageDataProvider::getLanguageToCountryList()` instead
  * `Piwik\Metrics\Formatter::getCurrencyList()`: use `CurrencyDataProvider::getCurrencyList()` instead
* The `Piwik\Translate` class has been deprecated in favor of `Piwik\Translation\Translator`.
* The `core:plugin` console has been deprecated in favor of the new `plugin:list`, `plugin:activate` and `plugin:deactivate` commands
* The following classes have been deprecated:
  * `Piwik\TaskScheduler`: use `Piwik\Scheduler\Scheduler` instead
  * `Piwik\ScheduledTask`: use `Piwik\Scheduler\Task` instead
* The API method `UserSettings.getLanguage` is deprecated and will be removed from May 1st 2015. Use `UserLanguage.getLanguage` instead
* The API method `UserSettings.getLanguageCode` is deprecated and will be removed from May 1st 2015. Use `UserLanguage.getLanguageCode` instead
* The `Piwik\Registry` class has been deprecated in favor of using the container:
  * `Registry::get('auth')` should be replaced with `StaticContainer::get('Piwik\Auth')`
  * `Registry::set('auth', $auth)` should be replaced with `StaticContainer::getContainer()->set('Piwik\Auth', $auth)`
 
### New features
* You can now generate UI / screenshot tests using the command `generate:test`
* During UI tests we do now add a CSS class to the HTML element called `uiTest`. This allows you do hide content when screenshots are captured.

### New commands
* A new command (core:fix-duplicate-log-actions) has been added which can be used to remove duplicate actions and correct references to them in other tables. Duplicates were caused by this bug: [#6436](https://github.com/matomo-org/matomo/issues/6436)

### Library updates
* Updated AngularJS from 1.2.26 to 1.2.28
* Updated piwik/device-detector from 2.8 to 3.0

### Internal change
* UI specs were moved from `tests/PHPUnit/UI` to `tests/UI`. We also moved the UI specs directly into the Piwik repository meaning the [piwik-ui-tests](https://github.com/matomo-org/piwik-ui-tests) repository contains only the expected screenshots from now on.
* There is a new command `development:sync-system-test-processed` for core developers that allows you to copy processed test results from travis to your local dev environment.

## Piwik 2.10.0

### Breaking Changes
* API responses containing visitor information will no longer contain the fields `screenType` and `screenTypeIcon` as those reports have been completely removed
* os, browser and browser plugin icons are now located in the DevicesDetection and DevicePlugins plugin. If you are not using the Reporting or Metadata API to get the icon locations please update your paths.
* The deprecated method `Piwik\SettingsPiwik::rewriteTmpPathWithHostname()` has been removed.
* The following events have been removed:
  * `Log.formatFileMessage`
  * `Log.formatDatabaseMessage`
  * `Log.formatScreenMessage`
  * These events have been removed as Piwik now uses the Monolog logging library. [Learn more.](http://developer.matomo.org/guides/logging)
* The event `Log.getAvailableWriters` has been removed: to add custom log backends, you now need to configure Monolog handlers
* The INI options `log_only_when_cli` and `log_only_when_debug_parameter` have been removed

### Library updates
* We added the `symfony/var-dumper` library allowing you to better print any arbitrary PHP variable via `dump($var1, $var2, ...)`.
* Piwik now uses [Monolog](https://github.com/Seldaek/monolog) as a logger.
* The tracker proxy (previously in `misc/proxy-hide-piwik-url/`) has been moved to a separate repository: [https://github.com/matomo-org/tracker-proxy](https://github.com/matomo-org/tracker-proxy).

### Deprecations
* Some duplicate reports from UserSettings plugin have been removed. Widget URLs for those reports will still work till May 1st 2015. Please update those to the new reports of DevicesDetection plugin.
* The API method `UserSettings.getBrowserVersion` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getBrowserVersions` instead
* The API method `UserSettings.getBrowser` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getBrowsers` instead
* The API method `UserSettings.getOSFamily` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getOsFamilies` instead
* The API method `UserSettings.getOS` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getOsVersions` instead
* The API method `UserSettings.getMobileVsDesktop` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getType` instead
* The API method `UserSettings.getBrowserType` is deprecated and will be removed from May 1st 2015. Use `DevicesDetection.getBrowserEngines` instead
* The API method `UserSettings.getResolution` is deprecated and will be removed from May 1st 2015. Use `Resolution.getResolution` instead
* The API method `UserSettings.getConfiguration` is deprecated and will be removed from May 1st 2015. Use `Resolution.getConfiguration` instead
* The API method `UserSettings.getPlugin` is deprecated and will be removed from May 1st 2015. Use `DevicePlugins.getPlugin` instead
* The API method `UserSettings.getWideScreen` has been removed. Use `UserSettings.getScreenType` instead.
* `Piwik\SettingsPiwik::rewriteTmpPathWithInstanceId()` has been deprecated. Instead of hardcoding the `tmp/` path everywhere in the codebase and then calling `rewriteTmpPathWithInstanceId()`, developers should get the `path.tmp` configuration value from the DI container (e.g. `StaticContainer::getContainer()->get('path.tmp')`).
* The method `Piwik\Log::setLogLevel()` has been deprecated
* The method `Piwik\Log::getLogLevel()` has been deprecated

## Piwik 2.9.1

### Breaking Changes
* The HTTP Tracker API does now respond with a HTTP 400 instead of a HTTP 500 in case an invalid `idsite` is used

### New APIs
* New URL parameter `send_image=0` in the [HTTP Tracking API](http://developer.matomo.org/api-reference/tracking-api) to receive a HTTP 204 response code instead of a GIF image. This improves performance and can fix errors if images are not allowed to be obtained directly (eg Chrome Apps).

### New commands
* `core:plugin list` lists all plugins currently activated in Piwik.

## Piwik 2.9.0

### Breaking Changes
* Development related [console commands](http://developer.matomo.org/guides/piwik-on-the-command-line) are only available if the development mode is enabled. To enable the development mode execute `./console development:enable`.
* The command `php console core:update` does no longer have a parameter `--dry-run`. A dry run is now executed by default followed by a question whether one actually wants to execute the updates. To skip this confirmation step one can use the `--yes` option.

### Deprecations
* Most methods of `Piwik\IP` have been deprecated in favor of the new [piwik/network](https://github.com/matomo-org/component-network) component.
* The file `tests/PHPUnit/phpunit.xml` is no longer needed in order to run tests and we suggest to delete it. The test configuration is now done automatically if possible. In case the tests do no longer work check out the `[tests]` section in `config/global.ini.php`

### Library updates
* Code for manipulating IP addresses has been moved to a separate standalone component: [piwik/network](https://github.com/matomo-org/component-network). Backward compatibility is kept in Piwik core.

## Piwik 2.8.2

### Library updates
* Updated AngularJS from 1.2.25 to 1.2.26
* Updated jQuery from 1.11.0 to 1.11.1

## Piwik 2.8.0

### Breaking Changes
* The Auth interface has been modified, existing Auth implementations will have to be modified. Changes include:
  * The initSession method has been moved. Since this behavior must be executed for every Auth implementation, it has been put into a new class: SessionInitializer.
    If your Auth implementation implements its own session logic you will have to extend and override SessionInitializer.
  * The following methods have been added: setPassword, setPasswordHash, getTokenAuthSecret and getLogin.
  * Clarifying semantics of each method and what they must support and can support.
  * **Read the documentation for the [Auth interface](http://developer.matomo.org/2.x/api-reference/Piwik/Auth) to learn more.**
* The `Piwik\Unzip\*` classes have been extracted out of the Piwik repository into a separate component named [Decompress](https://github.com/matomo-org/component-decompress).
  * `Piwik\Unzip` has not moved, it is kept for backward compatibility. If you have been using that class, you don't need to change anything.
  * The `Piwik\Unzip\*` classes (Tar, PclZip, Gzip, ZipArchive) have moved to the `Piwik\Decompress\*` namespace (inside the new repository).
  * `Piwik\Unzip\UncompressInterface` has been moved and renamed to `Piwik\Decompress\DecompressInterface` (inside the new repository).

### Deprecations
* The `Piwik::setUserHasSuperUserAccess` method is deprecated, instead use Access::doAsSuperUser. This method will ensure that super user access is properly rescinded after the callback finishes.
* The class `\IntegrationTestCase` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\TestCase\SystemTestCase` instead.
* The class `\DatabaseTestCase` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\TestCase\IntegrationTestCase` instead.
* The class `\BenchmarkTestCase` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\TestCase\BenchmarkTestCase` instead.
* The class `\ConsoleCommandTestCase` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\TestCase\ConsoleCommandTestCase` instead.
* The class `\FakeAccess` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\Mock\FakeAccess` instead.
* The class `\Piwik\Tests\Fixture` is deprecated and will be removed from February 6th 2015. Use `\Piwik\Tests\Framework\Fixture` instead.
* The class `\Piwik\Tests\OverrideLogin` is deprecated and will be removed from February 6ths 2015. Use `\Piwik\Framework\Framework\OverrideLogin` instead.

### New API Features
* The pivotBy and related query parameters can be used to pivot reports by another dimension. Read more about the new query parameters [here](http://developer.matomo.org/api-reference/reporting-api#optional-api-parameters).

### Library updates
* Updated AngularJS from 1.2.13 to 1.2.25

### New commands
* `generate:angular-directive` lets you easily generate a template for a new angular directive for any plugin.

### Internal change
* Piwik 2.8.0 now requires PHP >= 5.3.3. 
 * If you use an older PHP version, please upgrade now to the latest PHP so you can enjoy improvements and security fixes in Piwik. 

## Piwik 2.7.0

### Reporting APIs
* Several APIs will now expose a new metric `nb_users` which measures the number of unique users when a [User ID](http://matomo.org/docs/user-id/) is set.
* New APIs have been added for [Content Tracking](https://matomo.org/docs/content-tracking/) feature: Contents.getContentNames, Contents.getContentPieces

### Deprecations
* The `Piwik\Menu\MenuAbstract::add()` method is deprecated in favor of `addItem()`. Read more about this here: [#6140](https://github.com/matomo-org/matomo/issues/6140). We do not plan to remove the deprecated method before Piwik 3.0.

### New APIs
* It is now easier to generate the URL for a menu item see [#6140](https://github.com/matomo-org/matomo/issues/6140), [urlForDefaultAction()](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Menu#urlfordefaultaction), [urlForAction()](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Menu#urlforaction), [urlForModuleAction()](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Menu#urlformoduleaction)

### New commands
* `core:clear-caches` Lets you easily delete all caches. This command can be useful for instance after updating Piwik files manually.


## Piwik 2.6.0

### Deprecations
* The `'json'` API format is considered deprecated. We ask all new code to use the `'json2'` format. Eventually when Piwik 3.0 is released the `'json'` format will be replaced with `'json2'`. Differences in the json2 format include:
  * A bug in JSON formatting was fixed so API methods that return simple associative arrays like `array('name' => 'value', 'name2' => 'value2')` will now appear correctly as `{"name":"value","name2":"value2"}` in JSON API output instead of `[{"name":"value","name2":"value2"}]`. API methods like **SitesManager.getSiteFromId** & **UsersManager.getUser** are affected.

#### Reporting API
* If an API returns an indexed array, it is now possible to use `filter_limit` and `filter_offset`. This was before only possible if an API returned a DataTable.
* The Live API now returns only visitor information of activated plugins. So if for instance the Referrers plugin is deactivated a visitor won't contain any referrers related properties. This is a bugfix as the API was crashing before if some core plugins were deactivated. Affected methods are for instance `getLastVisitDetails` or `getVisitorProfile`. If all core plugins are enabled as by default there will be no change at all except the order of the properties within one visitor.

### New commands
* `core:run-scheduled-tasks` lets you run all scheduled tasks due to run at this time. Useful for instance when testing tasks.

#### Internal change
 * We removed our own autoloader that was used to load Piwik files in favor of the composer autoloader which we already have been using for some libraries. This means the file `core/Loader.php` will no longer exist. In case you are using Piwik from Git make sure to run `php composer.phar self-update && php composer.phar install` to make your Piwik work again. Also make sure to no longer include `core/Loader.php` in case it is used in any custom script.
 * We do no longer store the list of plugins that are used during tracking in the config file. They are dynamically detect instead. The detection of a tracker plugin works the same as before. A plugin has to either listen to any `Tracker.*` or `Request.initAuthenticationObject` event or it has to define dimensions in order to be detected as a tracker plugin.

## Piwik 2.5.0

### Breaking Changes
* Javascript Tracking API: if you are using `getCustomVariable` function to access custom variables values that were set on previous page views, you now must also call `storeCustomVariablesInCookie` before the first call to `trackPageView`. Read more about [Javascript Tracking here](http://developer.matomo.org/api-reference/tracking-javascript).
* The [settings](http://developer.matomo.org/guides/piwik-configuration) API will receive the actual entered value and will no longer convert characters like `&` to `&amp;`. If you still want this behavior - for instance to prevent XSS - you can define a filter by setting the `transform` property like this:
  `$setting->transform = function ($value) { return Common::sanitizeInputValue($value); }`
* Config setting `disable_merged_assets` moved from `Debug` section to `Development`. The updater will automatically change the section for you.
* `API.getRowEvolution` will throw an exception if a report is requested that does not have a dimension, for instance `VisitsSummary.get`. This is a fix as an invalid format was returned before see [#5951](https://github.com/matomo-org/matomo/issues/5951)
* `MultiSites.getAll` returns from now on always an array of websites. In the past it returned a single object and it didn't contain all properties in case only one website was found which was a bug see [#5987](https://github.com/matomo-org/matomo/issues/5987)

### Deprecations
The following events are considered as deprecated and the new structure should be used in the future. We have not scheduled when those events will be removed but probably in Piwik 3.0 which is not scheduled yet and won't be soon. New features will be added only to the new classes.

* `API.getReportMetadata`, `API.getSegmentDimensionMetadata`, `Goals.getReportsWithGoalMetrics`, `ViewDataTable.configure`, `ViewDataTable.getDefaultType`: use [Report](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Report) class instead to define new reports. There is an updated guide as well [Part1](http://developer.matomo.org/guides/getting-started-part-1)
* `WidgetsList.addWidgets`: use [Widgets](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Widgets) class instead to define new widgets
* `Menu.Admin.addItems`, `Menu.Reporting.addItems`, `Menu.Top.addItems`: use [Menu](http://developer.matomo.org/api-reference/Piwik/Plugin/Menu) class instead
* `TaskScheduler.getScheduledTasks`: use [Tasks](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Tasks) class instead to define new tasks
* `Tracker.recordEcommerceGoal`, `Tracker.recordStandardGoals`, `Tracker.newConversionInformation`: use [Conversion Dimension](http://developer.matomo.org/api-reference/Piwik/Plugin/Dimension/ConversionDimension) class instead
* `Tracker.existingVisitInformation`, `Tracker.newVisitorInformation`, `Tracker.getVisitFieldsToPersist`: use [Visit Dimension](http://developer.matomo.org/api-reference/Piwik/Plugin/Dimension/VisitDimension) class instead
* `ViewDataTable.addViewDataTable`: This event is no longer needed. Visualizations are automatically discovered if they are placed within a `Visualizations` directory inside the plugin.

### New features

#### Translation search
As a plugin developer you might want to reuse existing translation keys. You can now find all available translations and translation keys by opening the page "Settings => Development:Translation search" in your Piwik installation. Read more about [internationalization](http://developer.matomo.org/guides/internationalization) here.

#### Reporting API
It is now possible to use the `filter_sort_column` parameter when requesting `Live.getLastVisitDetails`. For instance `&filter_sort_column=visitCount`. 

#### @since annotation
We are using `@since` annotations in case we are introducing new API's to make it easy to see in which Piwik version a new method was added. This information is now displayed in the [Classes API-Reference](http://developer.matomo.org/api-reference/classes). 

### New APIs
* [Report](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Report) to add a new report
* [Action Dimension](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Dimension/ActionDimension) to add a dimension that tracks action related information
* [Visit Dimension](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Dimension/VisitDimension) to add a dimension that tracks visit related information
* [Conversion Dimension](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Dimension/ConversionDimension) to add a dimension that tracks conversion related information
* [Dimension](http://developer.matomo.org/2.x/api-reference/Piwik/Columns/Dimension) to add a basic non tracking dimension that can be used in `Reports`
* [Widgets](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Widgets) to add or modify widgets
* These Menu classes got new methods that make it easier to add new items to a specific section
  * [MenuAdmin](http://developer.matomo.org/2.x/api-reference/Piwik/Menu/MenuAdmin) to add or modify admin menu items. 
  * [MenuReporting](http://developer.matomo.org/2.x/api-reference/Piwik/Menu/MenuReporting) to add or modify reporting menu items
  * [MenuUser](http://developer.matomo.org/2.x/api-reference/Piwik/Menu/MenuUser) to add or modify user menu items
* [Tasks](http://developer.matomo.org/2.x/api-reference/Piwik/Plugin/Tasks) to add scheduled tasks

### New commands
* `generate:theme` lets you easily generate a new theme and customize colors, see the [Theming guide](http://developer.matomo.org/guides/theming)
* `generate:update` lets you generate an update file
* `generate:report` lets you generate a report
* `generate:dimension` lets you enhance the tracking by adding new dimensions
* `generate:menu` lets you generate a menu class to add or modify menu items
* `generate:widgets` lets you generate a widgets class to add or modify widgets
* `generate:tasks` lets you generate a tasks class to add or modify tasks
* `development:enable` lets you enable the development mode which will will disable some caching to make code changes directly visible and it will assist developers by performing additional checks to prevent for instance typos. Should not be used in production.
* `development:disable` lets you disable the development mode 

<!--
## Template: Matomo version number

### Breaking Changes
### Deprecations
### API Changes
### New features
### New APIs
### New commands
### New guides
### Library updates
### Internal change
 -->

Find the general Matomo Changelogs for each release at [matomo.org/changelog](https://matomo.org/changelog/)
