; <?php exit; ?> DO NOT REMOVE THIS LINE
; If you want to change some of these default values, the best practise is to override
; them in your configuration file in config/config.ini.php. If you directly edit this file,
; you will lose your changes when you upgrade Matomo.
; For example if you want to override action_title_category_delimiter,
; edit config/config.ini.php and add the following:
; [General]
; action_title_category_delimiter = "-"

;--------
; WARNING - YOU SHOULD NOT EDIT THIS FILE DIRECTLY - Edit config.ini.php instead.
;--------

[database]
host =
username =
password =
dbname =
tables_prefix =
port = 3306
adapter = PDO\MYSQL
type = InnoDB
schema = Mysql

; Database SSL Options START
; Turn on or off SSL connection to database, possible values for enable_ssl: 1 or 0
enable_ssl = 0
; Direct path to server CA file, CA bundle supported (required for ssl connection)
ssl_ca =
; Direct path to client cert file (optional)
ssl_cert =
; Direct path to client key file (optional)
ssl_key =
; Direct path to CA cert files directory (optional)
ssl_ca_path =
; List of one or more ciphers for SSL encryption, in OpenSSL format (optional)
ssl_cipher =
; Whether to skip verification of self signed certificates (optional, only supported
; w/ specific PHP versions, and is mostly for testing purposes)
ssl_no_verify =
; Database SSL Options END

; if charset is set to utf8, Matomo will ensure that it is storing its data using UTF8 charset.
; it will add a sql query SET at each page view.
; Matomo should work correctly without this setting but we recommend to have a charset set.
charset = utf8

; If configured, the following queries will be executed on the reader instead of the writer.
; * archiving queries that hit a log table
; * live queries that hit a log table
; You only want to enable a reader if you can ensure there is minimal replication lag / delay on the reader.
; Otherwise you might get corrupt data in the reports.
[database_reader]
host =
username =
password =
dbname =
port = 3306

; If you are using Amazon Aurora you can enable aurora_read_only_read_committed to prevent purge lag which happens
; when internal garbage collection is blocked by long-running archiving queries. The setting will be only applied
; if you are using Amazon Aurora and have configured a reader database.
aurora_readonly_read_committed =

[database_tests]
host = localhost
username = "@USERNAME@"
password =
dbname = matomo_tests
tables_prefix =
port = 3306
adapter = PDO\MYSQL
type = InnoDB
schema = Mysql
charset = utf8mb4
enable_ssl = 0
ssl_ca =
ssl_cert =
ssl_key =
ssl_ca_path =
ssl_cipher =
ssl_no_verify = 1

[tests]
; needed in order to run tests.
; if Matomo is available at http://localhost/dev/matomo/ replace @REQUEST_URI@ with /dev/matomo/
; note: the REQUEST_URI should not contain "plugins" or "tests" in the PATH
http_host   = localhost
remote_addr = "127.0.0.1"
request_uri = "@REQUEST_URI@"
port =
enable_logging = 0

[log]
; possible values for log: screen, database, file
log_writers[] = screen

; log level, everything logged w/ this level or one of greater severity
; will be logged. everything else will be ignored. possible values are:
; ERROR, WARN, INFO, DEBUG
; this setting will apply to every log writer, if there is no specific log level defined for a writer.
log_level = WARN

; you can also set specific log levels for different writers, by appending the writer name to log_level_, like so:
; this allows you to log more information to one backend vs another.
; log_level_screen =
; log_level_file =

; if configured to log in a file, log entries will be made to this file
logger_file_path = tmp/logs/matomo.log

[Cache]
; available backends are 'file', 'array', 'null', 'redis', 'chained'
; 'array' will cache data only during one request
; 'null' will not cache anything at all
; 'file' will cache on the filesystem
; 'redis' will cache on a Redis server, use this if you are running Matomo with multiple servers. Further configuration in [RedisCache] is needed
; 'chained' will chain multiple cache backends. Further configuration in [ChainedCache] is needed
backend = chained

[ChainedCache]
; The chained cache will always try to read from the fastest backend first (the first listed one) to avoid requesting
; the same cache entry from the slowest backend multiple times in one request.
backends[] = array
backends[] = file

[RedisCache]
; Redis server configuration.
host = "127.0.0.1"
port = 6379
; instead of host and port a unix socket path can be configured
unix_socket = ""
timeout = 0.0
password = ""
database = 14
; In case you are using queued tracking: Make sure to configure a different database! Otherwise queued requests might
; be flushed

[Debug]
; if set to 1, the archiving process will always be triggered, even if the archive has already been computed
; this is useful when making changes to the archiving code so we can force the archiving process
always_archive_data_period = 0;
always_archive_data_day = 0;
; Force archiving Custom date range (without re-archiving sub-periods used to process this date range)
always_archive_data_range = 0;

; if set to 1, all the SQL queries will be recorded by the profiler
; and a profiling summary will be printed at the end of the request
; NOTE: you must also set [log] log_writers[] = "screen" to enable the profiler to print on screen
enable_sql_profiler = 0

; If set to 1, all requests to matomo.php will be forced to be 'new visitors'
tracker_always_new_visitor = 0

; if set to 1, all SQL queries will be logged using the DEBUG log level
log_sql_queries = 0

; if set to 1, core:archive profiling information will be recorded in a log file. the log file is determined by the
; archive_profiling_log option.
archiving_profile = 0

; if set to an absolute path, core:archive profiling information will be logged to specified file
archive_profiling_log =

; if set to 1, use of a php profiler will be enabled. the profiler will not be activated unless its installation
; can be detected and the correct query and CLI parameters are supplied to toggle it.
; Note: this setting is not dependent on development mode, since it is often required to run the profiler with
; all optimizations and caches enabled.
enable_php_profiler = 0

[DebugTests]
; When set to 1, standalone plugins (those with their own git repositories)
; will be loaded when executing tests.
enable_load_standalone_plugins_during_tests = 0

[Development]
; Enables the development mode where we avoid most caching to make sure code changes will be directly applied as
; some caches are only invalidated after an update otherwise. When enabled it'll also performs some validation checks.
; For instance if you register a method in a widget we will verify whether the method actually exists and is public.
; If not, we will show you a helpful warning to make it easy to find simple typos etc.
enabled = 0

; if set to 1, javascript files will be included individually and neither merged nor minified.
; this option must be set to 1 when adding, removing or modifying javascript files
; Note that for quick debugging, instead of using below setting, you can add `&disable_merged_assets=1` to the Matomo URL
disable_merged_assets = 0

[General]
; the following settings control whether Unique Visitors `nb_uniq_visitors` and Unique users `nb_users` will be processed for different period types.
; year and range periods are disabled by default, to ensure optimal performance for high traffic Matomo instances
; if you set it to 1 and want the Unique Visitors to be re-processed for reports in the past, drop all matomo_archive_* tables
; it is recommended to always enable Unique Visitors and Unique Users processing for 'day' periods
enable_processing_unique_visitors_day = 1
enable_processing_unique_visitors_week = 1
enable_processing_unique_visitors_month = 1
enable_processing_unique_visitors_year = 0
enable_processing_unique_visitors_range = 0

; controls whether Unique Visitors will be processed for groups of websites. these metrics describe the number
; of unique visitors across the entire set of websites, so if a visitor visited two websites in the group, she
; would still only be counted as one. only relevant when using plugins that group sites together
enable_processing_unique_visitors_multiple_sites = 0

; The list of periods that are available in the Matomo calendar
; Example use case: custom date range requests are processed in real time,
; so they may take a few minutes on very high traffic website: you may remove "range" below to disable this period
enabled_periods_UI = "day,week,month,year,range"
enabled_periods_API = "day,week,month,year,range"

; whether to enable segment archiving cache
; Note: if you use any plugins, this need to be compliant with Matomo and
; * depending on the segment you create you may need a newer MySQL version (eg 5.7 or newer)
; * use a reader database for archiving in case you have configured a database reader
enable_segments_cache = 1

; whether to enable subquery cache for Custom Segment archiving queries
enable_segments_subquery_cache = 0
; Any segment subquery that matches more than segments_subquery_cache_limit IDs will not be cached,
; and the original subquery executed instead.
segments_subquery_cache_limit  = 100000
; TTL: Time to live for cache files, in seconds. Default to 60 minutes
segments_subquery_cache_ttl  = 3600

; when set to 1, all requests to Matomo will return a maintenance message without connecting to the DB
; this is useful when upgrading using the shell command, to prevent other users from accessing the UI while Upgrade is in progress
maintenance_mode = 0

; Defines the release channel that shall be used. Currently available values are:
; "latest_stable", "latest_beta", "latest_4x_stable", "latest_4x_beta"
release_channel = "latest_stable"

; character used to automatically create categories in the Actions > Pages, Outlinks and Downloads reports
; for example a URL like "example.com/blog/development/first-post" will create
; the page first-post in the subcategory development which belongs to the blog category
action_url_category_delimiter = /

; similar to above, but this delimiter is only used for page titles in the Actions > Page titles report
action_title_category_delimiter = ""

; the maximum url category depth to track. if this is set to 2, then a url such as
; "example.com/blog/development/first-post" would be treated as "example.com/blog/development".
; this setting is used mainly to limit the amount of data that is stored by Matomo.
action_category_level_limit = 10

; minimum number of websites to run autocompleter
autocomplete_min_sites = 5

; maximum number of websites showed in search results in autocompleter
site_selector_max_sites = 15

; if set to 1, shows sparklines (evolution graph) in 'All Websites' report (MultiSites plugin)
show_multisites_sparklines = 1

; number of websites to display per page in the All Websites dashboard
all_websites_website_per_page = 50

; if set to 0, the anonymous user will not be able to use the 'segments' parameter in the API request
; this is useful to prevent full DB access to the anonymous user, or to limit performance usage
anonymous_user_enable_use_segments_API = 1

; if browser trigger archiving is disabled, API requests with a &segment= parameter will still trigger archiving.
; You can force the browser archiving to be disabled in most cases by setting this setting to 1
; The only time that the browser will still trigger archiving is when requesting a custom date range that is not pre-processed yet
browser_archiving_disabled_enforce = 0

; Add custom currencies to Sites Manager.
currencies[BTC] = Bitcoin

; By default, users can create Segments which are to be processed in Real-time.
; Setting this to 0 will force all newly created Custom Segments to be "Pre-processed (faster, requires archive.php cron)"
; This can be useful if you want to prevent users from adding much load on the server.
; Notes:
;  * any existing Segment set to "processed in Real time", will still be set to Real-time.
;    this will only affect custom segments added or modified after this setting is changed.
;  * users with at least 'view' access will still be able to create pre-processed segments, regardless
;    of what this is set to.
enable_create_realtime_segments = 1

; Whether to enable the "Suggest values for segment" in the Segment Editor panel.
; Set this to 0 in case your Matomo database is very big, and suggested values may not appear in time
enable_segment_suggested_values = 1

; By default, any user with a "view" access for a website can create segment assigned to this website.
; Set this to "admin" or "superuser" to require that users should have at least this access to create new segments.
; Note: anonymous user (even if it has view access) is not allowed to create or edit segment.
; Possible values are "view", "admin", "superuser"
adding_segment_requires_access = "view"

; Whether it is allowed for users to add segments that affect all websites or not. If there are many websites
; this admin option can be used to prevent users from performing an action that will have a major impact
; on Matomo performance.
allow_adding_segments_for_all_websites = 1

; When archiving segments for the first time, this determines the oldest date that will be archived.
; This option can be used to avoid archiving (for instance) the lastN years for every new segment.
; Valid option values include: "beginning_of_time" (start date of archiving will not be changed)
;                              "segment_last_edit_time" (start date of archiving will be the earliest last edit date found,
;                                                        if none is found, the created date is used)
;                              "segment_creation_time" (start date of archiving will be the creation date of the segment)
;                              editLastN where N is an integer (eg "editLast10" to archive for 10 days before the segment last edit date)
;                              lastN where N is an integer (eg "last10" to archive for 10 days before the segment creation date)
process_new_segments_from = "beginning_of_time"

; this action name is used when the URL ends with a slash /
; it is useful to have an actual string to write in the UI
action_default_name = index

; default language to use in Matomo
default_language = en

; default number of elements in the datatable
datatable_default_limit = 10

; Each datatable report has a Row Limit selector at the bottom right.
; By default you can select from 5 to 500 rows. You may customise the values below
; -1 will be displayed as 'all' and it will export all rows (filter_limit=-1)
datatable_row_limits = "5,10,25,50,100,250,500,-1"

; default number of rows returned in API responses
; this value is overwritten by the '# Rows to display' selector.
; if set to -1, a click on 'Export as' will export all rows independently of the current '# Rows to display'.
API_datatable_default_limit = 100

; When period=range, below the datatables, when user clicks on "export", the data will be aggregate of the range.
; Here you can specify the comma separated list of formats for which the data will be exported aggregated by day
; (ie. there will be a new "date" column). For example set to: "rss,tsv,csv"
datatable_export_range_as_day = "rss"

; This setting is overridden in the UI, under "User Settings".
; The date and period loaded by Matomo uses the defaults below. Possible values: yesterday, today.
default_day = yesterday
; Possible values: day, week, month, year.
default_period = day

; Time in seconds after which an archive will be computed again. This setting is used only for today's statistics.
; This setting is overridden in the UI, under "General Settings".
; This setting is only used if it hasn't been overridden via the UI yet, or if enable_general_settings_admin=0
time_before_today_archive_considered_outdated = 900

; Time in seconds after which an archive will be computed again. This setting is used only for week's statistics.
; If set to "-1" (default), it will fall back to the UI setting under "General settings" unless enable_general_settings_admin=0
; is set. In this case it will default to "time_before_today_archive_considered_outdated";
time_before_week_archive_considered_outdated = -1

; Same as config setting "time_before_week_archive_considered_outdated" but it is only applied to monthly archives
time_before_month_archive_considered_outdated = -1

; Same as config setting "time_before_week_archive_considered_outdated" but it is only applied to yearly archives
time_before_year_archive_considered_outdated = -1

; Same as config setting "time_before_week_archive_considered_outdated" but it is only applied to range archives
time_before_range_archive_considered_outdated = -1

; This setting is overridden in the UI, under "General Settings".
; The default value is to allow browsers to trigger the Matomo archiving process.
; This setting is only used if it hasn't been overridden via the UI yet, or if enable_general_settings_admin=0
enable_browser_archiving_triggering = 1

; By default, Matomo will force archiving of range periods from browser requests, even if enable_browser_archiving_triggering
; is set to 0. This can sometimes create too much of a demand on system resources. Setting this option to 0 and
; disabling browser trigger archiving will make sure ranges are not archived on browser request. Since the cron
; archiver does not archive any custom date ranges, you must either disable range (using enabled_periods_API and enabled_periods_UI)
; or make sure the date ranges users' want to see will be processed somehow.
archiving_range_force_on_browser_request = 1

; By default Matomo will automatically archive all date ranges any user has chosen in their account settings.
; This is limited to the available options last7, previous7, last30 and previous30.
; If you need any other period, or want to ensure one of those is always archived, you can define them here
archiving_custom_ranges[] =

; If configured, archiving queries will be aborted after the configured amount of seconds. Set it to -1 if the query time
; should not be limited. Note: This feature requires a recent MySQL version (5.7 or newer). Some MySQL forks like MariaDB
; might not support this feature which uses the MAX_EXECUTION_TIME hint.
archiving_query_max_execution_time = 7200

; By default Matomo runs OPTIMIZE TABLE SQL queries to free spaces after deleting some data.
; If your Matomo tracks millions of pages, the OPTIMIZE TABLE queries might run for hours (seen in "SHOW FULL PROCESSLIST \g")
; so you can disable these special queries here:
enable_sql_optimize_queries = 1

; By default Matomo is purging complete date range archives to free spaces after deleting some data.
; If you are pre-processing custom ranges using CLI task to make them easily available in UI,
; you can prevent this action from happening by setting this parameter to value bigger than 1
purge_date_range_archives_after_X_days = 1

; MySQL minimum required version
; note: timezone support added in 4.1.3
minimum_mysql_version = 4.1

; PostgreSQL minimum required version
minimum_pgsql_version = 8.3

; Minimum advised memory limit in Mb in php.ini file (see memory_limit value)
; Set to "-1" to always use the configured memory_limit value in php.ini file.
minimum_memory_limit = 128

; Minimum memory limit in Mb enforced when archived via ./console core:archive
; Set to "-1" to always use the configured memory_limit value in php.ini file.
minimum_memory_limit_when_archiving = 768

; Matomo will check that usernames and password have a minimum length, and will check that characters are "allowed"
; This can be disabled, if for example you wish to import an existing User database in Matomo and your rules are less restrictive
disable_checks_usernames_attributes = 0

; Matomo will use the configured hash algorithm where possible.
; For legacy data, fallback or non-security scenarios, we use md5.
hash_algorithm = whirlpool

; set the algorithm used by password_hash()
; "default" for the algorithm used by the PHP version or one of ["bcrypt", "argon2i", "argon2id"]
; "argon2id" requires at least PHP 7.3.0
; for all argon2 algorithms, additional parameters can be changed below
; any changes are applied to the stored hash on the next login of a user
; see https://www.php.net/manual/en/function.password-hash.php and https://wiki.php.net/rfc/argon2_password_hash
; for more information
password_hash_algorithm = default

; The number of CPU threads used for calculating the hash
password_hash_argon2_threads = default

; The amount of memory (in KB) used for calculating the hash
; a minimum of 8 times the number of threads
password_hash_argon2_memory_cost = default

; The number of iterations for calculating the hash
password_hash_argon2_time_cost = default

; If set to 1, Matomo will automatically redirect all http:// requests to https://
; If SSL / https is not correctly configured on the server, this will break Matomo
; If you set this to 1, and your SSL configuration breaks later on, you can always edit this back to 0
; it is recommended for security reasons to always use Matomo over https
force_ssl = 0

; If set to 1 Matomo will prefer using SERVER_NAME variable over HTTP_HOST.
; This can add an additional layer of security as SERVER_NAME can not be manipulated by sending custom host headers when configure correctly.
host_validation_use_server_name = 0

; This list defines the hostnames that a valid sources to download GeoIP databases from. Subdomains of those hostnames will be accepted automatically.
geolocation_download_from_trusted_hosts[] = maxmind.com
geolocation_download_from_trusted_hosts[] = db-ip.com
geolocation_download_from_trusted_hosts[] = ip2location.com

; Session garbage collection on (as on some operating systems, i.e. Debian, it may be off by default)
session_gc_probability = 1

; (DEPRECATED) has no effect
login_cookie_name = matomo_auth

; By default, the auth cookie is set only for the duration of session.
; if "Remember me" is checked, the auth cookie will be valid for 14 days by default
login_cookie_expire = 1209600

; Sets the session cookie path
login_cookie_path =

; the amount of time before an idle session is considered expired. only affects session that were created without the
; "remember me" option checked
login_session_not_remembered_idle_timeout = 3600

; email address that appears as a Reply-to in the password recovery email
; if specified, {DOMAIN} will be replaced by the current Matomo domain
login_password_recovery_replyto_email_address = "no-reply@{DOMAIN}"
; name that appears as a Reply-to in the password recovery email
login_password_recovery_replyto_email_name = "No-reply"

; When configured, only users from a configured IP can log into your Matomo. You can define one or multiple
; IPv4, IPv6, and IP ranges. You may also define hostnames. However, resolving hostnames in each request
; may slightly slow down your Matomo.
; This allowlist also affects API requests unless you disabled it via the setting
; "login_allowlist_apply_to_reporting_api_requests" below. Note that neither this setting, nor the
; "login_allowlist_apply_to_reporting_api_requests" restricts authenticated tracking requests (tracking requests
; with a "token_auth" URL parameter).
;
; Examples:
; login_allowlist_ip[] = 204.93.240.*
; login_allowlist_ip[] = 204.93.177.0/24
; login_allowlist_ip[] = 199.27.128.0/21
; login_allowlist_ip[] = 2001:db8::/48
; login_allowlist_ip[] = matomo.org

; By default, if an allowlisted IP address is specified via "login_allowlist_ip[]", the reporting user interface as
; well as HTTP Reporting API requests will only work for these allowlisted IPs.
; Set this setting to "0" to allow HTTP Reporting API requests from any IP address.
login_allowlist_apply_to_reporting_api_requests = 1

; By default when user logs out they are redirected to Matomo "homepage" usually the Login form.
; Uncomment the next line to set a URL to redirect the user to after they log out of Matomo.
; login_logout_url = http://...

; By default the logme functionality to automatically log in users using url params is disabled
; You can enable that by setting this to "1". See https://matomo.org/faq/how-to/faq_30/ for more details
login_allow_logme = 0

; Set to 1 to disable the framebuster on standard Non-widgets pages (a click-jacking countermeasure).
; Default is 0 (i.e., bust frames on all non Widget pages such as Login, API, Widgets, Email reports, etc.).
enable_framed_pages = 0

; Set to 1 to disable the framebuster on Admin pages (a click-jacking countermeasure).
; Default is 0 (i.e., bust frames on the Settings forms).
enable_framed_settings = 0

; Set to 1 to allow using token_auths with write or admin access in iframes that embed Matomo.
; Note that the token used will be in the URL in the iframe, and thus will be stored in webserver
; logs and possibly other places. Using write or admin token_auths can be seen as a security risk,
; though it can be necessary in some use cases. We do not recommend enabling this setting, for more
; information view the FAQ: https://matomo.org/faq/troubleshooting/faq_147/
enable_framed_allow_write_admin_token_auth = 0

; language cookie name for session
language_cookie_name = matomo_lang

; standard email address displayed when sending emails
noreply_email_address = "noreply@{DOMAIN}"

; standard email name displayed when sending emails. If not set, a default name will be used.
noreply_email_name = ""

; set to 0 to disable sending of all emails. useful for testing.
emails_enabled = 1

; set to 0 to disable sending of emails when a password or email is changed
enable_update_users_email = 1

; feedback email address;
; when testing, use your own email address or "nobody"
feedback_email_address = "feedback@matomo.org"

; using to set reply_to in reports e-mail to login of report creator
scheduled_reports_replyto_is_user_email_and_alias = 0

; scheduled reports truncate limit
; the report will be rendered with the first 23 rows and will aggregate other rows in a summary row
; 23 rows table fits in one portrait page
scheduled_reports_truncate = 23

; during archiving, Matomo will limit the number of results recorded, for performance reasons
; maximum number of rows for any of the Referrers tables (keywords, search engines, campaigns, etc.)
datatable_archiving_maximum_rows_referrers = 1000
; maximum number of rows for any of the Referrers subtable (search engines by keyword, keyword by campaign, etc.)
datatable_archiving_maximum_rows_subtable_referrers = 50

; maximum number of rows for the Users report
datatable_archiving_maximum_rows_userid_users = 50000

; maximum number of rows for the Custom Dimensions report
datatable_archiving_maximum_rows_custom_dimensions = 1000
; maximum number of rows for the Custom Dimensions subtable reports
datatable_archiving_maximum_rows_subtable_custom_dimensions = 1000

; maximum number of rows for any of the Actions tables (pages, downloads, outlinks)
datatable_archiving_maximum_rows_actions = 500
; maximum number of rows for pages in categories (sub pages, when clicking on the + for a page category)
; note: should not exceed the display limit in Piwik\Actions\Controller::ACTIONS_REPORT_ROWS_DISPLAY
; because each subdirectory doesn't have paging at the bottom, so all data should be displayed if possible.
datatable_archiving_maximum_rows_subtable_actions = 100
; maximum number of rows for the Site Search table
datatable_archiving_maximum_rows_site_search = 500

; maximum number of rows for any of the Events tables (Categories, Actions, Names)
datatable_archiving_maximum_rows_events = 500
; maximum number of rows for sub-tables of the Events tables (eg. for the subtables Categories>Actions or Categories>Names).
datatable_archiving_maximum_rows_subtable_events = 500

; maximum number of rows for other tables (Providers, User settings configurations)
datatable_archiving_maximum_rows_standard = 500

; maximum number of rows to fetch from the database when archiving. if set to 0, no limit is used.
; this can be used to speed up the archiving process, but is only useful if you're site has a large
; amount of actions, referrers or custom variable name/value pairs.
archiving_ranking_query_row_limit = 50000

; maximum number of actions that is shown in the visitor log for each visitor
visitor_log_maximum_actions_per_visit = 500

; by default, the real time Live! widget will update every 5 seconds and refresh with new visits/actions/etc.
; you can change the timeout so the widget refreshes more often, or not as frequently
live_widget_refresh_after_seconds = 5

; by default, the Live! real time visitor count widget will check to see how many visitors your
; website received in the last 3 minutes. changing this value will change the number of minutes
; the widget looks in.
live_widget_visitor_count_last_minutes = 3

; by default visitor profile will show aggregated information for the last up to 100 visits of a visitor
; this limit can be adjusted by changing this value
live_visitor_profile_max_visits_to_aggregate = 100

; If configured, will abort a MySQL query after the configured amount of seconds and show an error in the UI to for
; example lower the date range or tweak the segment (if one is applied). Set it to -1 if the query time should not be
; limited. Note: This feature requires a recent MySQL version (5.7 or newer). Some MySQL forks like MariaDB might not
; support this feature which uses the MAX_EXECUTION_TIME hint.
live_query_max_execution_time = -1

; In "All Websites" dashboard, when looking at today's reports (or a date range including today),
; the page will automatically refresh every 5 minutes. Set to 0 to disable automatic refresh
multisites_refresh_after_seconds = 300

; by default, an update notification for a new version of Matomo is shown to every user. Set to 1 if only
; the superusers should see the notification.
show_update_notification_to_superusers_only = 0

; Set to 1 if you're using https on your Matomo server and Matomo can't detect it,
; e.g., a reverse proxy using https-to-http, or a web server that doesn't
; set the HTTPS environment variable.
assume_secure_protocol = 0

; Set to 1 if you're using more than one server for your Matomo installation. For example if you are using Matomo in a
; load balanced environment, if you have configured failover or if you're just using multiple servers in general.
; By enabling this flag we will for example not allow the installation of a plugin via the UI as a plugin would be only
; installed on one server or a config one change would be only made on one server instead of all servers.
; This flag doesn't need to be enabled when the config file is on a shared filesystem such as NFS or EFS.
multi_server_environment = 0

; List of proxy headers for client IP addresses
; Matomo will determine the user IP by extracting the first IP address found in this proxy header.
;
; CloudFlare (CF-Connecting-IP)
;proxy_client_headers[] = HTTP_CF_CONNECTING_IP
;
; ISP proxy (Client-IP)
;proxy_client_headers[] = HTTP_CLIENT_IP
;
; de facto standard (X-Forwarded-For)
;proxy_client_headers[] = HTTP_X_FORWARDED_FOR

; List of proxy headers for host IP addresses
;
; de facto standard (X-Forwarded-Host)
;proxy_host_headers[] = HTTP_X_FORWARDED_HOST

; List of proxy IP addresses (or IP address ranges) to skip (if present in the above headers).
; Generally, only required if there's more than one proxy between the visitor and the backend web server.
;
; Examples:
;proxy_ips[] = 204.93.240.*
;proxy_ips[] = 204.93.177.0/24
;proxy_ips[] = 199.27.128.0/21
;proxy_ips[] = 173.245.48.0/20

; Set to 1 if you're using a proxy which is rewriting the URI.
; By enabling this flag the header HTTP_X_FORWARDED_URI will be considered for the current script name.
proxy_uri_header = 0

; If set to 1 we use the last IP in the list of proxy IPs when determining the client IP. Using the last IP can be more
; secure when using proxy headers in combination with a load balancer. By default the first IP is read according to RFC7239
; which is required when the client sends the IP through a proxy header as well as the load balancer.
proxy_ip_read_last_in_list = 0

; Whether to enable trusted host checking. This can be disabled if you're running Matomo
; on several URLs and do not wish to constantly edit the trusted host list.
enable_trusted_host_check = 1

; List of trusted hosts (eg domain or subdomain names) when generating absolute URLs.
;
; Examples:
;trusted_hosts[] = example.com
;trusted_hosts[] = stats.example.com

; List of Cross-origin resource sharing domains (eg domain or subdomain names) when generating absolute URLs.
; Described here: https://en.wikipedia.org/wiki/Cross-origin_resource_sharing
;
; Examples:
;cors_domains[] = http://example.com
;cors_domains[] = http://stats.example.com
;
; Or you may allow cross domain requests for all domains with:
;cors_domains[] = *

; If you use this Matomo instance over multiple hostnames, Matomo will need to know
; a unique instance_id for this instance, so that Matomo can serve the right custom logo and tmp/* assets,
; independently of the hostname Matomo is currently running under.
; instance_id = stats.example.com

; The API server is an essential part of the Matomo infrastructure/ecosystem to
; provide services to Matomo installations, e.g., getLatestVersion and
; subscribeNewsletter.
api_service_url = http://api.matomo.org

; When the ImageGraph plugin is activated, report metadata have an additional entry : 'imageGraphUrl'.
; This entry can be used to request a static graph for the requested report.
; When requesting report metadata with $period=range, Matomo needs to translate it to multiple periods for evolution graphs.
; eg. $period=range&date=previous10 becomes $period=day&date=previous10. Use this setting to override the $period value.
graphs_default_period_to_plot_when_period_range = day

; When the ImageGraph plugin is activated, enabling this option causes the image graphs to show the evolution
; within the selected period instead of the evolution across the last n periods.
graphs_show_evolution_within_selected_period = 0

; This option controls the default number of days in the past to show in evolution graphs generated by the ImageGraph plugin
graphs_default_evolution_graph_last_days_amount = 30

; The Overlay plugin shows the Top X following pages, Top X downloads and Top X outlinks which followed
; a view of the current page. The value X can be set here.
overlay_following_pages_limit = 300

; With this option, you can disable the framed mode of the Overlay plugin. Use it if your website contains a framebuster.
overlay_disable_framed_mode = 0

; Controls whether the user is able to upload a custom logo for their Matomo install
enable_custom_logo = 1

; By default we check whether the Custom logo is writable or not, before we display the Custom logo file uploader
enable_custom_logo_check = 1

; If php is running in a chroot environment, when trying to import CSV files with createTableFromCSVFile(),
; Mysql will try to load the chrooted path (which is incomplete). To prevent an error, here you can specify the
; absolute path to the chroot environment. eg. '/path/to/matomo/chrooted/'
absolute_chroot_path =

; The path (relative to the Matomo directory) in which Matomo temporary files are stored.
; Defaults to ./tmp (the tmp/ folder inside the Matomo directory)
tmp_path = "/tmp"

; The absolute path to a PHP binary file in case Matomo cannot detect your PHP binary. If async CLI archiving cannot be
; used on your server this may make it work. Ensure the configured PHP binary is of type CLI and not for example cgi or
; litespeed. To find out the type of interface for a PHP binary execute this command: php -r "echo php_sapi_name();"
php_binary_path = ""

; In some rare cases it may be useful to explicitly tell Matomo not to use LOAD DATA INFILE
; This may for example be useful when doing Mysql AWS replication
enable_load_data_infile = 1

; By setting this option to 0:
; - links to Enable/Disable/Uninstall plugins will be hidden and disabled
; - links to Uninstall themes will be disabled (but user can still enable/disable themes)
enable_plugins_admin = 1

; By setting this option to 0 the users management will be disabled
enable_users_admin = 1

; By setting this option to 0 the websites management will be disabled
enable_sites_admin = 1

; By setting this option to 1, it will be possible for Super Users to upload Matomo plugin ZIP archives directly in Matomo Administration.
; Enabling this opens a remote code execution vulnerability where
; an attacker who gained Super User access could execute custom PHP code in a Matomo plugin.
enable_plugin_upload = 0

; By setting this option to 0 (e.g. in common.config.ini.php) the installer will be disabled.
enable_installer = 1

; By setting this option to 0, you can prevent Super User from editing the Geolocation settings.
enable_geolocation_admin = 1

; By setting this option to 0, the old raw data and old report data purging features will be hidden from the UI
; Note: log purging and old data purging still occurs, just the Super User cannot change the settings.
enable_delete_old_data_settings_admin = 1

; By setting this option to 0, the following settings will be hidden and disabled from being set in the UI:
; - Archiving settings
; - Update settings
; - Email server settings
; - Trusted Matomo Hostname
enable_general_settings_admin = 1

; Disabling this will disable features like automatic updates for Matomo,
; its plugins and components like the GeoIP database, referrer spam blacklist or search engines and social network definitions
enable_internet_features = 1

; By setting this option to 0, it will disable the "Auto update" feature
enable_auto_update = 1

; By setting this option to 0, no emails will be sent in case of an available core.
; If set to 0 it also disables the "sent plugin update emails" feature in general and the related setting in the UI.
enable_update_communication = 1

; This option defines the protocols Matomo's Http class is allowed to open.
; If you may need to download GeoIP updates or other stuff using other protocols like ftp you may need to extend this list.
allowed_outgoing_protocols = 'http,https'

; Comma separated list of plugin names for which console commands should be loaded (applies when Matomo is not installed yet)
always_load_commands_from_plugin=

; This controls whether the pivotBy query parameter can be used with any dimension or just subtable
; dimensions. If set to 1, it will fetch a report with a segment for each row of the table being pivoted.
; At present, this is very inefficient, so it is disabled by default.
pivot_by_filter_enable_fetch_by_segment = 0

; This controls the default maximum number of columns to display in a pivot table. Since a pivot table displays
; a table's rows as columns, the number of columns can become very large, which will affect webpage layouts.
; Set to -1 to specify no limit. Note: The pivotByColumnLimit query parameter can be used to override this default
; on a per-request basis;
pivot_by_filter_default_column_limit = 10

; If set to 0 it will disable advertisements for providers of Professional Support for Matomo.
piwik_professional_support_ads_enabled = 1

; The number of days to wait before sending the JavaScript tracking code email reminder.
num_days_before_tracking_code_reminder = 5

; The maximum number of segments that can be compared simultaneously.
data_comparison_segment_limit = 5

; The maximum number of periods that can be compared simultaneously.
data_comparison_period_limit = 5

; The path to a custom cacert.pem file Matomo should use.
; By default Matomo uses a file extracted from the Firefox browser and provided here: https://curl.haxx.se/docs/caextract.html.
; The file contains root CAs and is used to determine if the chain of a SSL certificate is valid and it is safe to connect.
; Most users will not have to use a custom file here, but if you run your Matomo instance behind a proxy server/firewall that
; breaks and reencrypts SSL connections you can set your custom file here.
custom_cacert_pem=

; Whether or not to send weekly emails to superusers about tracking failures.
; Default is 1.
enable_tracking_failures_notification = 1

; Controls how many months in the past reports are re-archived for plugins that support
; doing this (such as CustomReports). Set to 0 to disable the feature. Default is 6.
rearchive_reports_in_past_last_n_months = 6

; If set to 1, when rearchiving reports in the past we do not rearchive segment data with those reports. Default is 0.
rearchive_reports_in_past_exclude_segments = 0

[Tracker]

; When enabled and a userId is set, then the visitorId will be automatically set based on the userId. This allows to
; identify the same user as the same visitor across devices.
; Disabling this feature can be useful for example when using the third party cookie, where all Matomo tracked sites
; use the same "global" visitorId for a device and you want to see when the same user switches between devices.
enable_userid_overwrites_visitorid = 1

; Matomo uses "Privacy by default" model. When one of your users visit multiple of your websites tracked in this Matomo,
; Matomo will create for this user a fingerprint that will be different across the multiple websites.
; If you want to track unique users across websites you may set this setting to 1.
; Note: setting this to 0 increases your users' privacy.
enable_fingerprinting_across_websites = 0

; Matomo uses first party cookies by default. If set to 1,
; the visit ID cookie will be set on the Matomo server domain as well
; this is useful when you want to do cross websites analysis
use_third_party_id_cookie = 0

; If tracking does not work for you or you are stuck finding an issue, you might want to enable the tracker debug mode.
; Once enabled (set to 1) messages will be logged to all loggers defined in "[log] log_writers" config.
debug = 0

; This option is an alternative to the debug option above. When set to 1, you can debug tracker request by adding
; a debug=1 query parameter in the URL. All other HTTP requests will not have debug enabled. For security reasons this
; option should be only enabled if really needed and only for a short time frame. Otherwise anyone can set debug=1 and
; see the log output as well.
debug_on_demand = 0

; This setting is described in this FAQ: https://matomo.org/faq/how-to/faq_175/
; Note: generally this should only be set to 1 in an intranet setting, where most users have the same configuration (browsers, OS)
; and the same IP. If left to 0 in this setting, all visitors will be counted as one single visitor.
trust_visitors_cookies = 0

; name of the cookie used to store the visitor information
; This is used only if use_third_party_id_cookie = 1
cookie_name = _pk_uid

; by default, the Matomo tracking cookie expires in 13 months (365 + 28 days)
; This is used only if use_third_party_id_cookie = 1
cookie_expire = 33955200;

; The path on the server in which the cookie will be available on.
; Defaults to empty. See spec in https://curl.haxx.se/rfc/cookie_spec.html
; This is used for the Ignore cookie, and the third party cookie if use_third_party_id_cookie = 1
cookie_path =

; The domain on the server in which the cookie will be available on.
; Defaults to empty. See spec in https://curl.haxx.se/rfc/cookie_spec.html
; This is used for the third party cookie if use_third_party_id_cookie = 1
cookie_domain =

; set to 0 if you want to stop tracking the visitors. Useful if you need to stop all the connections on the DB.
record_statistics = 1

; length of a visit in seconds. If a visitor comes back on the website visit_standard_length seconds
; after their last page view, it will be recorded as a new visit. In case you are using the Matomo JavaScript tracker to
; calculate the visit count correctly, make sure to call the method "setSessionCookieTimeout" eg
; `_paq.push(['setSessionCookieTimeout', timeoutInSeconds=1800])`
visit_standard_length = 1800

; The amount of time in the past to match the current visitor to a known visitor via fingerprint. Defaults to visit_standard_length.
; If you are looking for higher accuracy of "returning visitors" metrics, you may set this value to 86400 or more.
; This is especially useful when you use the Tracking API where tracking Returning Visitors often depends on this setting.
; The value window_look_back_for_visitor is used only if it is set to greater than visit_standard_length.
; Note: visitors with visitor IDs will be matched by visitor ID from any point in time, this is only for recognizing visitors
; by device fingerprint.
window_look_back_for_visitor = 0

; visitors that stay on the website and view only one page will be considered as time on site of 0 second
default_time_one_page_visit = 0

; Comma separated list of URL query string variable names that will be removed from your tracked URLs
; By default, Matomo will remove the most common parameters which are known to change often (eg. session ID parameters)
url_query_parameter_to_exclude_from_url = "gclid,fbclid,fb_xd_fragment,fb_comment_id,phpsessid,jsessionid,sessionid,aspsessionid,doing_wp_cron,sid,pk_vid"

; if set to 1, Matomo attempts a "best guess" at the visitor's country of
; origin when the preferred language tag omits region information.
; The mapping is defined in core/DataFiles/LanguageToCountry.php,
enable_language_to_country_guess = 1

; When the `./console core:archive` cron hasn't been setup, we still need to regularly run some maintenance tasks.
; Visits to the Tracker will try to trigger Scheduled Tasks (eg. scheduled PDF/HTML reports by email).
; Scheduled tasks will only run if 'Enable Matomo Archiving from Browser' is enabled in the General Settings.
; Tasks run once every hour maximum, they might not run every hour if traffic is low.
; Set to 0 to disable Scheduled tasks completely.
scheduled_tasks_min_interval = 3600

; name of the cookie to ignore visits
ignore_visits_cookie_name = matomo_ignore

; Comma separated list of variable names that will be read to define a Campaign name, for example CPC campaign
; Example: If a visitor first visits 'index.php?matomo_campaign=Adwords-CPC' then it will be counted as a campaign referrer named 'Adwords-CPC'
; Includes by default the GA style campaign parameters
campaign_var_name = "pk_cpn,pk_campaign,piwik_campaign,mtm_campaign,matomo_campaign,utm_campaign,utm_source,utm_medium"

; Comma separated list of variable names that will be read to track a Campaign Keyword
; Example: If a visitor first visits 'index.php?matomo_campaign=Adwords-CPC&matomo_kwd=My killer keyword' ;
; then it will be counted as a campaign referrer named 'Adwords-CPC' with the keyword 'My killer keyword'
; Includes by default the GA style campaign keyword parameter utm_term
campaign_keyword_var_name = "pk_kwd,pk_keyword,piwik_kwd,mtm_kwd,mtm_keyword,matomo_kwd,utm_term"

; if set to 1, actions that contain different campaign information from the visitor's ongoing visit will
; be treated as the start of a new visit. This will include situations when campaign information was absent before,
; but is present now.
create_new_visit_when_campaign_changes = 1

; if set to 1, actions that contain different website referrer information from the visitor's ongoing visit
; will be treated as the start of a new visit. This will include situations when website referrer information was
; absent before, but is present now.
create_new_visit_when_website_referrer_changes = 0

; ONLY CHANGE THIS VALUE WHEN YOU DO NOT USE MATOMO ARCHIVING, SINCE THIS COULD CAUSE PARTIALLY MISSING ARCHIVE DATA
; Whether to force a new visit at midnight for every visitor. Default 1.
create_new_visit_after_midnight = 1

; Will force the creation of a new visit once a visit had this many actions.
; Increasing this number can slow down the tracking in Matomo and put more load on the database.
; Increase this limit if it's expected that you have visits with more than this many actions.
; Set to 0 or a negative value to allow unlimited actions.
create_new_visit_after_x_actions = 10000

; maximum length of a Page Title or a Page URL recorded in the log_action.name table
page_maximum_length = 1024;

; Tracker cache files are the simple caching layer for Tracking.
; TTL: Time to live for cache files, in seconds. Default to 5 minutes.
tracker_cache_file_ttl = 300

; Whether Bulk tracking requests to the Tracking API requires the token_auth to be set.
bulk_requests_require_authentication = 0

; Whether Bulk tracking requests will be wrapped within a DB Transaction.
; This greatly increases performance of Log Analytics and in general any Bulk Tracking API requests.
bulk_requests_use_transaction = 1

; DO NOT USE THIS SETTING ON PUBLICLY AVAILABLE MATOMO SERVER
; !!! Security risk: if set to 0, it would allow anyone to push data to Matomo with custom dates in the past/future and even with fake IPs!
; When using the Tracking API, to override either the datetime and/or the visitor IP,
; token_auth with an "admin" access is required. If you set this setting to 0, the token_auth will not be required anymore.
; DO NOT USE THIS SETTING ON PUBLIC MATOMO SERVERS
tracking_requests_require_authentication = 1

; By default, Matomo accepts only tracking requests for up to 1 day in the past. For tracking requests with a custom date
; date is older than 1 day, Matomo requires an authenticated tracking requests. By setting this config to another value
; You can change how far back Matomo will track your requests without authentication. The configured value is in seconds.
tracking_requests_require_authentication_when_custom_timestamp_newer_than = 86400;

; if set to 1, all the SQL queries will be recorded by the profiler
; and a profiling summary will be printed at the end of the request
; NOTE: you must also set "[Tracker] debug = 1" to enable the profiler.
enable_sql_profiler = 0

; Enables using referrer spam blacklist.
enable_spam_filter = 1

; If a value greater than 0 is configured, Matomo will configure MySQL with the set lock wait timeout in seconds during a
; tracking request. This can be useful if you have a high concurrency load on your server and want to reduce the time of
; lock wait times. For example configuring a value of 3-10 seconds may give your Matomo a performance boost if you have
; many concurrent tracking requests for the same visitor. When enabling this feature, make sure the MySQL
; variable "innodb_rollback_on_timeout" is turned off. Only configure if really needed. The lower the value the more tracking
; requests may be discarded due to too low lock wait time.
innodb_lock_wait_timeout = 0

; Allows you to exclude specific requests from being tracked. The definition is similar to segments.
; The following operands are supported: Equals: `==`, Not equals: `!=`, Contains: `=@`, Not Contains: `!@`, Starts with: `=^`, Ends with: `=$`.
; The structure is as following: {tracking parameter}{operand}{value to match}.
; For example "e_c==Media" means that all tracking requests will be excluded where the event category is Media.
; Multiple exclusions can be configured separated by a comma. The request will be excluded if any expressions matches (not all of them). For example: "e_c==Media,action_name=@privacy".
; This would also exclude any request from being tracked where the page title contains privacy.
; All comparisons are performed case insensitve. The value to match on the right side should be URL encoded.
; For example: "action_name=^foo%2Cbar" would exclude page titles that start with "foo,bar".
; For a list of tracking parameters you can use on the left side view https://developer.matomo.org/api-reference/tracking-api
exclude_requests = ""

[Segments]
; Reports with segmentation in API requests are processed in real time.
; On high traffic websites it is recommended to pre-process the data
; so that the analytics reports are always fast to load.
; You can define below the list of Segments strings
; for which all reports should be Archived during the cron execution
; All segment values MUST be URL encoded.
;Segments[]="visitorType==new"
;Segments[]="visitorType==returning,visitorType==returningCustomer"

; If you define Custom Variables for your visitor, for example set the visit type
;Segments[]="customVariableName1==VisitType;customVariableValue1==Customer"

[Deletelogs]
; delete_logs_enable - enable (1) or disable (0) delete log feature. Make sure that all archives for the given period have been processed (setup a cronjob!),
; otherwise you may lose tracking data.
; delete_logs_schedule_lowest_interval - lowest possible interval between two table deletes, for tables named log_* (in days, 1|7|30). Default: 7.
; delete_logs_older_than - delete data older than XX (days). Default: 180
; delete_logs_unused_actions_schedule_lowest_interval - lowest possible interval between two table deletes, for table log_action (in days, 1|7|30). Default: 30.
; delete_logs_max_rows_per_query and delete_logs_unused_actions_max_rows_per_query can be increased for large sites to speed up delete processes
;
; The higher value one assign to *_schedule_lowest_interval, the longer the data pruning/deletion will take. This is caused by the fact there is more data to evaluate and process every month, than every week.
delete_logs_enable = 0
delete_logs_schedule_lowest_interval = 7
delete_logs_older_than = 180
delete_logs_max_rows_per_query = 100000
delete_logs_unused_actions_max_rows_per_query = 100000
enable_auto_database_size_estimate = 1
enable_database_size_estimate = 1
delete_logs_unused_actions_schedule_lowest_interval = 30

[Deletereports]
delete_reports_enable                = 0
delete_reports_older_than            = 12
delete_reports_keep_basic_metrics    = 1
delete_reports_keep_day_reports      = 0
delete_reports_keep_week_reports     = 0
delete_reports_keep_month_reports    = 1
delete_reports_keep_year_reports     = 1
delete_reports_keep_range_reports    = 0
delete_reports_keep_segment_reports  = 0

[mail]
defaultHostnameIfEmpty = defaultHostnameIfEmpty.example.org ; default Email @hostname, if current host can't be read from system variables
transport = ; smtp (using the configuration below) or empty (using built-in mail() function)
port = ; optional; defaults to 25 when security is none or tls; 465 for ssl
host = ; SMTP server address
type = ; SMTP Auth type. By default: NONE. For example: LOGIN
username = ; SMTP username
password = ; SMTP password
encryption = ; SMTP transport-layer encryption, either 'none', 'ssl', 'tls', or empty (i.e., auto).

[proxy]
type = BASIC ; proxy type for outbound/outgoing connections; currently, only BASIC is supported
host = ; Proxy host: the host name of your proxy server (mandatory)
port = ; Proxy port: the port that the proxy server listens to. There is no standard default, but 80, 1080, 3128, and 8080 are popular
username = ; Proxy username: optional; if specified, password is mandatory
password = ; Proxy password: optional; if specified, username is mandatory

[Plugins]
; list of plugins (in order they will be loaded) that are activated by default in the Matomo platform
Plugins[] = CorePluginsAdmin
Plugins[] = CoreAdminHome
Plugins[] = CoreHome
Plugins[] = WebsiteMeasurable
Plugins[] = IntranetMeasurable
Plugins[] = Diagnostics
Plugins[] = CoreVisualizations
Plugins[] = Proxy
Plugins[] = API
Plugins[] = Widgetize
Plugins[] = Transitions
Plugins[] = LanguagesManager
Plugins[] = Actions
Plugins[] = Dashboard
Plugins[] = MultiSites
Plugins[] = Referrers
Plugins[] = UserLanguage
Plugins[] = DevicesDetection
Plugins[] = Goals
Plugins[] = Ecommerce
Plugins[] = SEO
Plugins[] = Events
Plugins[] = UserCountry
Plugins[] = GeoIp2
Plugins[] = VisitsSummary
Plugins[] = VisitFrequency
Plugins[] = VisitTime
Plugins[] = VisitorInterest
Plugins[] = RssWidget
Plugins[] = Feedback
Plugins[] = Monolog

Plugins[] = Login
Plugins[] = TwoFactorAuth
Plugins[] = UsersManager
Plugins[] = SitesManager
Plugins[] = Installation
Plugins[] = CoreUpdater
Plugins[] = CoreConsole
Plugins[] = ScheduledReports
Plugins[] = UserCountryMap
Plugins[] = Live
Plugins[] = PrivacyManager
Plugins[] = ImageGraph
Plugins[] = Annotations
Plugins[] = MobileMessaging
Plugins[] = Overlay
Plugins[] = SegmentEditor
Plugins[] = Insights
Plugins[] = Morpheus
Plugins[] = Contents
Plugins[] = TestRunner
Plugins[] = BulkTracking
Plugins[] = Resolution
Plugins[] = DevicePlugins
Plugins[] = Heartbeat
Plugins[] = Intl
Plugins[] = Marketplace
Plugins[] = ProfessionalServices
Plugins[] = UserId
Plugins[] = CustomJsTracker
Plugins[] = Tour
Plugins[] = PagePerformance
Plugins[] = CustomDimensions

[PluginsInstalled]
PluginsInstalled[] = Diagnostics
PluginsInstalled[] = Login
PluginsInstalled[] = CoreAdminHome
PluginsInstalled[] = UsersManager
PluginsInstalled[] = SitesManager
PluginsInstalled[] = Installation
PluginsInstalled[] = Monolog
PluginsInstalled[] = Intl

[APISettings]
; Any key/value pair can be added in this section, they will be available via the REST call
; index.php?module=API&method=API.getSettings
; This can be used to expose values from Matomo, to control for example a Mobile app tracking
SDK_batch_size = 10
SDK_interval_value = 30

; NOTE: do not directly edit this file! See notice at the top
