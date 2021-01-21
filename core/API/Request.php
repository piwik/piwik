<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\API;

use Exception;
use Matomo\Cache\Transient;
use Piwik\Access;
use Piwik\Cache;
use Piwik\Common;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\Context;
use Piwik\DataTable;
use Piwik\Exception\PluginDeactivatedException;
use Piwik\IP;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\CoreHome\LoginAllowlist;
use Piwik\Segment;
use Piwik\SettingsServer;
use Piwik\Url;
use Piwik\UrlHelper;
use Psr\Log\LoggerInterface;

/**
 * Dispatches API requests to the appropriate API method.
 *
 * The Request class is used throughout Piwik to call API methods. The difference
 * between using Request and calling API methods directly is that Request
 * will do more after calling the API including: applying generic filters, applying queued filters,
 * and handling the **flat** and **label** query parameters.
 *
 * Additionally, the Request class will **forward current query parameters** to the request
 * which is more convenient than calling {@link Piwik\Common::getRequestVar()} many times over.
 *
 * In most cases, using a Request object to query the API is the correct approach.
 *
 * ### Post-processing
 *
 * The return value of API methods undergo some extra processing before being returned by Request.
 *
 * ### Output Formats
 *
 * The value returned by Request will be serialized to a certain format before being returned.
 *
 * ### Examples
 *
 * **Basic Usage**
 *
 *     $request = new Request('method=UserLanguage.getLanguage&idSite=1&date=yesterday&period=week'
 *                          . '&format=xml&filter_limit=5&filter_offset=0')
 *     $result = $request->process();
 *     echo $result;
 *
 * **Getting a unrendered DataTable**
 *
 *     // use the convenience method 'processRequest'
 *     $dataTable = Request::processRequest('UserLanguage.getLanguage', array(
 *         'idSite' => 1,
 *         'date' => 'yesterday',
 *         'period' => 'week',
 *         'filter_limit' => 5,
 *         'filter_offset' => 0
 *
 *         'format' => 'original', // this is the important bit
 *     ));
 *     echo "This DataTable has " . $dataTable->getRowsCount() . " rows.";
 *
 * @see http://piwik.org/docs/analytics-api
 * @api
 */
class Request
{
    /**
     * The count of nested API request invocations. Used to determine if the currently executing request is the root or not.
     *
     * @var int
     */
    private static $nestedApiInvocationCount = 0;

    private $request = null;

    /**
     * Converts the supplied request string into an array of query parameter name/value
     * mappings. The current query parameters (everything in `$_GET` and `$_POST`) are
     * forwarded to request array before it is returned.
     *
     * @param string|array|null $request The base request string or array, eg,
     *                                   `'module=UserLanguage&action=getLanguage'`.
     * @param array $defaultRequest Default query parameters. If a query parameter is absent in `$request`, it will be loaded
     *                              from this. Defaults to `$_GET + $_POST`.
     * @return array
     */
    public static function getRequestArrayFromString($request, $defaultRequest = null)
    {
        if ($defaultRequest === null) {
            $defaultRequest = self::getDefaultRequest();

            $requestRaw = self::getRequestParametersGET();
            if (!empty($requestRaw['segment'])) {
                $defaultRequest['segment'] = $requestRaw['segment'];
            }

            if (!isset($defaultRequest['format_metrics'])) {
                $defaultRequest['format_metrics'] = 'bc';
            }
        }

        $requestArray = $defaultRequest;

        if (!is_null($request)) {
            if (is_array($request)) {
                $requestParsed = $request;
            } else {
                $request = trim($request);
                $request = str_replace(array("\n", "\t"), '', $request);

                $requestParsed = UrlHelper::getArrayFromQueryString($request);
            }

            $requestArray = $requestParsed + $defaultRequest;
        }

        foreach ($requestArray as &$element) {
            if (!is_array($element)) {
                $element = trim($element);
            }
        }
        return $requestArray;
    }

    /**
     * Constructor.
     *
     * @param string|array $request Query string that defines the API call (must at least contain a **method** parameter),
     *                              eg, `'method=UserLanguage.getLanguage&idSite=1&date=yesterday&period=week&format=xml'`
     *                              If a request is not provided, then we use the values in the `$_GET` and `$_POST`
     *                              superglobals.
     * @param array $defaultRequest Default query parameters. If a query parameter is absent in `$request`, it will be loaded
     *                              from this. Defaults to `$_GET + $_POST`.
     */
    public function __construct($request = null, $defaultRequest = null)
    {
        $this->request = self::getRequestArrayFromString($request, $defaultRequest);
        $this->sanitizeRequest();
        $this->renameModuleAndActionInRequest();
    }

    /**
     * For backward compatibility: Piwik API still works if module=Referers,
     * we rewrite to correct renamed plugin: Referrers
     *
     * @param $module
     * @param $action
     * @return array( $module, $action )
     * @ignore
     */
    public static function getRenamedModuleAndAction($module, $action)
    {
        /**
         * This event is posted in the Request dispatcher and can be used
         * to overwrite the Module and Action to dispatch.
         * This is useful when some Controller methods or API methods have been renamed or moved to another plugin.
         *
         * @param $module string
         * @param $action string
         */
        Piwik::postEvent('Request.getRenamedModuleAndAction', array(&$module, &$action));

        return array($module, $action);
    }

    /**
     * Make sure that the request contains no logical errors
     */
    private function sanitizeRequest()
    {
        // The label filter does not work with expanded=1 because the data table IDs have a different meaning
        // depending on whether the table has been loaded yet. expanded=1 causes all tables to be loaded, which
        // is why the label filter can't descend when a recursive label has been requested.
        // To fix this, we remove the expanded parameter if a label parameter is set.
        if (isset($this->request['label']) && !empty($this->request['label'])
            && isset($this->request['expanded']) && $this->request['expanded']
        ) {
            unset($this->request['expanded']);
        }
    }

    /**
     * Dispatches the API request to the appropriate API method and returns the result
     * after post-processing.
     *
     * Post-processing includes:
     *
     * - flattening if **flat** is 0
     * - running generic filters unless **disable_generic_filters** is set to 1
     * - URL decoding label column values
     * - running queued filters unless **disable_queued_filters** is set to 1
     * - removing columns based on the values of the **hideColumns** and **showColumns** query parameters
     * - filtering rows if the **label** query parameter is set
     * - converting the result to the appropriate format (ie, XML, JSON, etc.)
     *
     * If `'original'` is supplied for the output format, the result is returned as a PHP
     * object.
     *
     * @throws PluginDeactivatedException if the module plugin is not activated.
     * @throws Exception if the requested API method cannot be called, if required parameters for the
     *                   API method are missing or if the API method throws an exception and the **format**
     *                   query parameter is **original**.
     * @return DataTable|Map|string The data resulting from the API call.
     */
    public function process()
    {
        $shouldReloadAuth = false;

        try {
            ++self::$nestedApiInvocationCount;

            // read the format requested for the output data
            $outputFormat = strtolower(Common::getRequestVar('format', 'xml', 'string', $this->request));

            $disablePostProcessing = $this->shouldDisablePostProcessing();

            // create the response
            $response = new ResponseBuilder($outputFormat, $this->request);
            if ($disablePostProcessing) {
                $response->disableDataTablePostProcessor();
            }

            $corsHandler = new CORSHandler();
            $corsHandler->handle();

            $tokenAuth = Common::getRequestVar('token_auth', '', 'string', $this->request);

            // IP check is needed here as we cannot listen to API.Request.authenticate as it would then not return proper API format response.
            // We can also not do it by listening to API.Request.dispatch as by then the user is already authenticated and we want to make sure
            // to not expose any information in case the IP is not allowed.
            $list = new LoginAllowlist();
            if ($list->shouldCheckAllowlist() && $list->shouldAllowlistApplyToAPI()) {
                $ip = IP::getIpFromHeader();
                $list->checkIsAllowed($ip);
            }

            // read parameters
            $moduleMethod = Common::getRequestVar('method', null, 'string', $this->request);

            list($module, $method) = $this->extractModuleAndMethod($moduleMethod);
            list($module, $method) = self::getRenamedModuleAndAction($module, $method);

            PluginManager::getInstance()->checkIsPluginActivated($module);

            $apiClassName = self::getClassNameAPI($module);

            if ($shouldReloadAuth = self::shouldReloadAuthUsingTokenAuth($this->request)) {
                $access = Access::getInstance();
                $tokenAuthToRestore = $access->getTokenAuth();
                $hadSuperUserAccess = $access->hasSuperUserAccess();
                self::forceReloadAuthUsingTokenAuth($tokenAuth);
            }

            // call the method
            $returnedValue = Proxy::getInstance()->call($apiClassName, $method, $this->request);

            // get the response with the request query parameters loaded, since DataTablePost processor will use the Report
            // class instance, which may inspect the query parameters. (eg, it may look for the idCustomReport parameters
            // which may only exist in $this->request, if the request was called programmatically)
            $toReturn = Context::executeWithQueryParameters($this->request, function () use ($response, $returnedValue, $module, $method) {
                return $response->getResponse($returnedValue, $module, $method);
            });
        } catch (Exception $e) {
            StaticContainer::get(LoggerInterface::class)->error('Uncaught exception in API: {exception}', [
                'exception' => $e,
                'ignoreInScreenWriter' => true,
            ]);

            if (empty($response)) {
               $response = new ResponseBuilder('console', $this->request);
            }
            
            $toReturn = $response->getResponseException($e);
        } finally {
            --self::$nestedApiInvocationCount;
        }

        if ($shouldReloadAuth) {
            $this->restoreAuthUsingTokenAuth($tokenAuthToRestore, $hadSuperUserAccess);
        }

        return $toReturn;
    }

    private function restoreAuthUsingTokenAuth($tokenToRestore, $hadSuperUserAccess)
    {
        // if we would not make sure to unset super user access, the tokenAuth would be not authenticated and any
        // token would just keep super user access (eg if the token that was reloaded before had super user access)
        Access::getInstance()->setSuperUserAccess(false);

        // we need to restore by reloading the tokenAuth as some permissions could have been removed in the API
        // request etc. Otherwise we could just store a clone of Access::getInstance() and restore here
        self::forceReloadAuthUsingTokenAuth($tokenToRestore);

        if ($hadSuperUserAccess && !Access::getInstance()->hasSuperUserAccess()) {
            // we are in context of `doAsSuperUser()` and need to restore this behaviour
            Access::getInstance()->setSuperUserAccess(true);
        }
    }

    /**
     * Returns the name of a plugin's API class by plugin name.
     *
     * @param string $plugin The plugin name, eg, `'Referrers'`.
     * @return string The fully qualified API class name, eg, `'\Piwik\Plugins\Referrers\API'`.
     */
    public static function getClassNameAPI($plugin)
    {
        return sprintf('\Piwik\Plugins\%s\API', $plugin);
    }

    /**
     * @ignore
     * @internal
     * @param string $currentApiMethod
     */
    public static function setIsRootRequestApiRequest($currentApiMethod)
    {
        Cache::getTransientCache()->save('API.setIsRootRequestApiRequest', $currentApiMethod);
    }

    /**
     * @ignore
     * @internal
     * @return string current Api Method if it is an api request
     */
    public static function getRootApiRequestMethod()
    {
        return Cache::getTransientCache()->fetch('API.setIsRootRequestApiRequest');
    }

    /**
     * Detect if the root request (the actual request) is an API request or not. To detect whether an API is currently
     * request within any request, have a look at {@link isApiRequest()}.
     *
     * @return bool
     * @throws Exception
     */
    public static function isRootRequestApiRequest()
    {
        $apiMethod = Cache::getTransientCache()->fetch('API.setIsRootRequestApiRequest');
        return !empty($apiMethod);
    }

    /**
     * Checks if the currently executing API request is the root API request or not.
     *
     * Note: the "root" API request is the first request made. Within that request, further API methods
     * can be called programmatically. These requests are considered "child" API requests.
     *
     * @return bool
     * @throws Exception
     */
    public static function isCurrentApiRequestTheRootApiRequest()
    {
        return self::$nestedApiInvocationCount == 1;
    }

    /**
     * Detect if request is an API request. Meaning the module is 'API' and an API method having a valid format was
     * specified. Note that this method will return true even if the actual request is for example a regular UI
     * reporting page request but within this request we are currently processing an API request (eg a
     * controller calls Request::processRequest('API.getMatomoVersion')). To find out if the root request is an API
     * request or not, call {@link isRootRequestApiRequest()}
     *
     * @param array $request  eg array('module' => 'API', 'method' => 'Test.getMethod')
     * @return bool
     * @throws Exception
     */
    public static function isApiRequest($request)
    {
        $method = self::getMethodIfApiRequest($request);
        return !empty($method);
    }

    /**
     * Returns the current API method being executed, if the current request is an API request.
     *
     * @param array $request  eg array('module' => 'API', 'method' => 'Test.getMethod')
     * @return string|null
     * @throws Exception
     */
    public static function getMethodIfApiRequest($request)
    {
        $module = Common::getRequestVar('module', '', 'string', $request);
        $method = Common::getRequestVar('method', '', 'string', $request);

        $isApi = $module === 'API' && !empty($method) && (count(explode('.', $method)) === 2);
        return $isApi ? $method : null;
    }

    /**
     * If the token_auth is found in the $request parameter,
     * the current session will be authenticated using this token_auth.
     * It will overwrite the previous Auth object.
     *
     * @param array $request If null, uses the default request ($_GET)
     * @return void
     * @ignore
     */
    public static function reloadAuthUsingTokenAuth($request = null)
    {
        // if a token_auth is specified in the API request, we load the right permissions
        $token_auth = Common::getRequestVar('token_auth', '', 'string', $request);

        if (self::shouldReloadAuthUsingTokenAuth($request)) {
            self::forceReloadAuthUsingTokenAuth($token_auth);
        }
    }

    /**
     * The current session will be authenticated using this token_auth.
     * It will overwrite the previous Auth object.
     *
     * @param string $tokenAuth
     * @return void
     */
    private static function forceReloadAuthUsingTokenAuth($tokenAuth)
    {
        /**
         * Triggered when authenticating an API request, but only if the **token_auth**
         * query parameter is found in the request.
         *
         * Plugins that provide authentication capabilities should subscribe to this event
         * and make sure the global authentication object (the object returned by `StaticContainer::get('Piwik\Auth')`)
         * is setup to use `$token_auth` when its `authenticate()` method is executed.
         *
         * @param string $token_auth The value of the **token_auth** query parameter.
         */
        Piwik::postEvent('API.Request.authenticate', array($tokenAuth));
        if (!Access::getInstance()->reloadAccess() && $tokenAuth && $tokenAuth !== 'anonymous') {
            /**
             * @ignore
             * @internal
             */
            Piwik::postEvent('API.Request.authenticate.failed');
        }
        SettingsServer::raiseMemoryLimitIfNecessary();
    }

    /**
     * Needs to be called AFTER the user has been authenticated using a token.
     *
     * @internal
     * @ignore
     * @param string $module
     * @param string $action
     * @throws Exception
     */
    public static function checkTokenAuthIsNotLimited($module, $action)
    {
        $isApi = ($module === 'API' && (empty($action) || $action === 'index'));
        if ($isApi
            || Common::isPhpCliMode()
        ) {
            return;
        }

        if (Access::getInstance()->hasSuperUserAccess()) {
            $ex = new \Piwik\Exception\Exception(Piwik::translate('Widgetize_TooHighAccessLevel', ['<a href="https://matomo.org/faq/troubleshooting/faq_147/" rel="noreferrer noopener">', '</a>']));
            $ex->setIsHtmlMessage();
            throw $ex;
        }

        $allowWriteAmin = Config::getInstance()->General['enable_framed_allow_write_admin_token_auth'] == 1;
        if (Piwik::isUserHasSomeWriteAccess()
            && !$allowWriteAmin
        ) {
            // we allow UI authentication/ embedding widgets / reports etc only for users that have only view
            // access. it's mostly there to get users to use auth tokens of view users when embedding reports
            // token_auth is fine for API calls since they would be always authenticated later anyway
            // token_auth is also fine in CLI mode as eg doAsSuperUser might be used etc
            //
            // NOTE: this does not apply if the [General] enable_framed_allow_write_admin_token_auth INI
            // option is set.
            throw new \Exception(Piwik::translate('Widgetize_ViewAccessRequired', ['https://matomo.org/faq/troubleshooting/faq_147/']));
        }
    }

    /**
     * @internal
     * @ignore
     * @param $request
     * @return bool
     * @throws Exception
     */
    public static function shouldReloadAuthUsingTokenAuth($request)
    {
        if (is_null($request)) {
            $request = self::getDefaultRequest();
        }

        if (!isset($request['token_auth'])) {
            // no token is given so we just keep the current loaded user
            return false;
        }

        // a token is specified, we need to reload auth in case it is different than the current one, even if it is empty
        $tokenAuth = Common::getRequestVar('token_auth', '', 'string', $request);

        // not using !== is on purpose as getTokenAuth() might return null whereas $tokenAuth is '' . In this case
        // we do not need to reload.

        return $tokenAuth != Access::getInstance()->getTokenAuth();
    }

    /**
     * Returns array($class, $method) from the given string $class.$method
     *
     * @param string $parameter
     * @throws Exception
     * @return array
     */
    private function extractModuleAndMethod($parameter)
    {
        $a = explode('.', $parameter);
        if (count($a) != 2) {
            throw new Exception("The method name is invalid. Expected 'module.methodName'");
        }
        return $a;
    }

    /**
     * Helper method that processes an API request in one line using the variables in `$_GET`
     * and `$_POST`.
     *
     * @param string $method The API method to call, ie, `'Actions.getPageTitles'`.
     * @param array $paramOverride The parameter name-value pairs to use instead of what's
     *                             in `$_GET` & `$_POST`.
     * @param array $defaultRequest Default query parameters. If a query parameter is absent in `$request`, it will be loaded
     *                              from this. Defaults to `$_GET + $_POST`.
     *
     *                              To avoid using any parameters from $_GET or $_POST, set this to an empty `array()`.
     * @return mixed The result of the API request. See {@link process()}.
     */
    public static function processRequest($method, $paramOverride = array(), $defaultRequest = null)
    {
        $params = array();
        $params['format'] = 'original';
        $params['serialize'] = '0';
        $params['module'] = 'API';
        $params['method'] = $method;
        $params['compare'] = '0';
        $params = $paramOverride + $params;

        // process request
        $request = new Request($params, $defaultRequest);
        return $request->process();
    }

    /**
     * Returns the original request parameters in the current query string as an array mapping
     * query parameter names with values. The result of this function will not be affected
     * by any modifications to `$_GET` and will not include parameters in `$_POST`.
     *
     * @return array
     */
    public static function getRequestParametersGET()
    {
        if (empty($_SERVER['QUERY_STRING'])) {
            return array();
        }
        $GET = UrlHelper::getArrayFromQueryString($_SERVER['QUERY_STRING']);
        return $GET;
    }

    /**
     * Returns the URL for the current requested report w/o any filter parameters.
     *
     * @param string $module The API module.
     * @param string $action The API action.
     * @param array $queryParams Query parameter overrides.
     * @return string
     */
    public static function getBaseReportUrl($module, $action, $queryParams = array())
    {
        $params = array_merge($queryParams, array('module' => $module, 'action' => $action));
        return Request::getCurrentUrlWithoutGenericFilters($params);
    }

    /**
     * Returns the current URL without generic filter query parameters.
     *
     * @param array $params Query parameter values to override in the new URL.
     * @return string
     */
    public static function getCurrentUrlWithoutGenericFilters($params)
    {
        // unset all filter query params so the related report will show up in its default state,
        // unless the filter param was in $queryParams
        $genericFiltersInfo = DataTableGenericFilter::getGenericFiltersInformation();
        foreach ($genericFiltersInfo as $filter) {
            foreach ($filter[1] as $queryParamName => $queryParamInfo) {
                if (!isset($params[$queryParamName])) {
                    $params[$queryParamName] = null;
                }
            }
        }

        $params['compareDates'] = null;
        $params['comparePeriods'] = null;
        $params['compareSegments'] = null;

        return Url::getCurrentQueryStringWithParametersModified($params);
    }

    /**
     * Returns whether the DataTable result will have to be expanded for the
     * current request before rendering.
     *
     * @return bool
     * @ignore
     */
    public static function shouldLoadExpanded()
    {
        // if filter_column_recursive & filter_pattern_recursive are supplied, and flat isn't supplied
        // we have to load all the child subtables.
        return Common::getRequestVar('filter_column_recursive', false) !== false
            && Common::getRequestVar('filter_pattern_recursive', false) !== false
            && !self::shouldLoadFlatten();
    }

    /**
     * @return bool
     */
    public static function shouldLoadFlatten()
    {
        return Common::getRequestVar('flat', false) == 1;
    }

    /**
     * Returns the segment query parameter from the original request, without modifications.
     *
     * @return array|bool
     */
    public static function getRawSegmentFromRequest()
    {
        // we need the URL encoded segment parameter, we fetch it from _SERVER['QUERY_STRING'] instead of default URL decoded _GET
        $segmentRaw = false;
        $segment = Common::getRequestVar('segment', '', 'string');
        if (!empty($segment)) {
            $request = Request::getRequestParametersGET();
            if (!empty($request['segment'])) {
                $segmentRaw = $request['segment'];
            }
        }
        return $segmentRaw;
    }

    private function renameModuleAndActionInRequest()
    {
        if (empty($this->request['apiModule'])) {
            return;
        }
        if (empty($this->request['apiAction'])) {
            $this->request['apiAction'] = null;
        }
        list($this->request['apiModule'], $this->request['apiAction']) = $this->getRenamedModuleAndAction($this->request['apiModule'], $this->request['apiAction']);
    }

    /**
     * @return array
     */
    private static function getDefaultRequest()
    {
        return $_GET + $_POST;
    }

    private function shouldDisablePostProcessing()
    {
        $shouldDisable = false;

        /**
         * After an API method returns a value, the value is post processed (eg, rows are sorted
         * based on the `filter_sort_column` query parameter, rows are truncated based on the
         * `filter_limit`/`filter_offset` parameters, amongst other things).
         *
         * If you're creating a plugin that needs to disable post processing entirely for
         * certain requests, use this event.
         *
         * @param bool &$shouldDisable Set this to true to disable datatable post processing for a request.
         * @param array $request The request parameters.
         */
        Piwik::postEvent('Request.shouldDisablePostProcessing', [&$shouldDisable, $this->request]);

        if (!$shouldDisable) {
            $shouldDisable = self::isCurrentApiRequestTheRootApiRequest() &&
                Common::getRequestVar('disable_root_datatable_post_processor', 0, 'int', $this->request) == 1;
        }

        return $shouldDisable;
    }

    public static function isCurrentPeriodProfilable($idSite = null, $period = null, $date = null, $segment = null)
    {
        if (self::isProfilableCheckDisabledInTests()) {
            return true;
        }

        $idSite = $idSite ?: Common::getRequestVar('idSite', $default = false);
        $period = $period ?: Common::getRequestVar('period', $default = false);
        $date = $date ?: Common::getRequestVar('date', $default = false);
        $segment = $segment ?: Request::getRawSegmentFromRequest();

        if ($idSite === false
            || $period === false
            || $date === false
            || !is_numeric($idSite)
            || Period::isMultiplePeriod($date, $period)
        ) {
            return true;
        }

        if ($period == 'day') {
            $period = 'week';
        }

        $transientCache = StaticContainer::get(Transient::class);

        $segmentObj = new Segment($segment, [$idSite]);

        $cacheKey = "VisitsSummary.isProfilable.$idSite.$period.$date." . $segmentObj->getHash();
        if (!$transientCache->contains($cacheKey)) {
            $isProfilable = Request::processRequest('VisitsSummary.isProfilable', [
                'idSite' => $idSite,
                'period' => $period,
                'date' => $date,
                'segment' => $segment,
            ]);

            $transientCache->save($cacheKey, $isProfilable);
        } else {
            $isProfilable = (bool)$transientCache->fetch($cacheKey);
        }

        return $isProfilable;
    }

    private static function isProfilableCheckDisabledInTests()
    {
        if (!defined('PIWIK_TEST_MODE')) {
            return false;
        }

        try {
            $isDisabled = (bool) StaticContainer::get('tests.isProfilableCheckDisabled');
            return $isDisabled;
        } catch (\Exception $ex) {
            return false;
        }
    }
}
