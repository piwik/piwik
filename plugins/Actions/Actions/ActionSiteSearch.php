<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Actions\Actions;

use Piwik\Common;
use Piwik\Tracker\Action;
use Piwik\Tracker\PageUrl;
use Piwik\Tracker\Request;
use Piwik\Tracker\Cache;
use Piwik\UrlHelper;

/**
 * This class represents a search on the site.
 * - Its name is the search keyword
 * - by default the URL is not recorded (since it's not used)
 * - tracks site search result count and site search category as custom variables
 *
 */
class ActionSiteSearch extends Action
{
    private $searchCategory = false;
    private $searchCount = false;
    private $originalUrl = '';

    public function __construct(Request $request, $detect = true)
    {
        parent::__construct(Action::TYPE_SITE_SEARCH, $request);
        $this->originalUrl = $request->getParam('url');

        if ($detect) {
            $this->isSearchDetected();
        }
    }

    public static function shouldHandle(Request $request)
    {
        $search = new self($request, false);

        return $search->detectSiteSearch($request->getParam('url'));
    }

    protected function getActionsToLookup()
    {
        return array(
            'idaction_name' => array($this->getActionName(), Action::TYPE_SITE_SEARCH),
        );
    }

    public function getIdActionUrl()
    {
        // Site Search, by default, will not track URL. We do not want URL to appear as "Page URL not defined"
        // so we specifically set it to NULL in the table (the archiving query does IS NOT NULL)
        return null;
    }

    public function getIdActionUrlForEntryAndExitIds()
    {
        return $this->getIdActionUrl();
    }

    public function getIdActionNameForEntryAndExitIds()
    {
        return $this->getIdActionName();
    }

    protected function isSearchDetected()
    {
        $siteSearch = $this->detectSiteSearch($this->originalUrl);

        if (empty($siteSearch)) {
            return false;
        }

        list($actionName, $url, $category, $count) = $siteSearch;

        if (!empty($category)) {
            $this->searchCategory = trim($category);
        }
        if ($count !== false) {
            $this->searchCount = $count;
        }
        $this->setActionName($actionName);
        $this->setActionUrl($url);

        return true;
    }

    public function getSearchCategory()
    {
        $searchCategory = trim($this->searchCategory);
        if (!empty($searchCategory)) {
            // Max length of DB field = 200
            $searchCategory = substr($this->searchCategory, 0, 200);
        }
        return $searchCategory;
    }

    public function getSearchCount()
    {
        if ($this->searchCount !== false) {
            $this->searchCount = (int)$this->searchCount;
        }
        return $this->searchCount;
    }

    public static function detectSiteSearchFromUrl($website, $parsedUrl, $pageEncoding = null)
    {
        $doRemoveSearchParametersFromUrl = true;
        $separator = '&';
        $count = $actionName = $categoryName = false;

        $keywordParameters = isset($website['sitesearch_keyword_parameters'])
            ? $website['sitesearch_keyword_parameters']
            : array();
        $queryString = !empty($parsedUrl['query']) ? $parsedUrl['query'] : '';
        $fragment = !empty($parsedUrl['fragment']) ? $parsedUrl['fragment'] : '';

        $parsedFragment = parse_url($fragment);

        // check if fragment contains a separate query (beginning with ?) otherwise assume complete fragment as query
        if ($fragment && strpos($fragment, '?') !== false && !empty($parsedFragment['query'])) {
            $fragmentBeforeQuery = !empty($parsedFragment['path']) ? $parsedFragment['path'] : '';
            $fragmentQuery = $parsedFragment['query'];
        } else {
            $fragmentQuery = $fragment;
            $fragmentBeforeQuery = '';
        }

        $parametersRaw = UrlHelper::getArrayFromQueryString($queryString.$separator.$fragmentQuery);

        // strtolower the parameter names for smooth site search detection
        $parameters = array();
        foreach ($parametersRaw as $k => $v) {
            $parameters[Common::mb_strtolower($k)] = $v;
        }
        // decode values if they were sent from a client using another charset
        PageUrl::reencodeParameters($parameters, $pageEncoding);

        // Detect Site Search keyword
        foreach ($keywordParameters as $keywordParameterRaw) {
            $keywordParameter = Common::mb_strtolower($keywordParameterRaw);
            if (!empty($parameters[$keywordParameter])) {
                $actionName = $parameters[$keywordParameter];
                break;
            }
        }

        if (empty($actionName)) {
            return false;
        }

        $categoryParameters = isset($website['sitesearch_category_parameters'])
            ? $website['sitesearch_category_parameters']
            : array();

        foreach ($categoryParameters as $categoryParameterRaw) {
            $categoryParameter = Common::mb_strtolower($categoryParameterRaw);
            if (!empty($parameters[$categoryParameter])) {
                $categoryName = $parameters[$categoryParameter];
                break;
            }
        }

        if (isset($parameters['search_count'])
            && self::isValidSearchCount($parameters['search_count'])
        ) {
            $count = $parameters['search_count'];
        }
        // Remove search kwd from URL
        if ($doRemoveSearchParametersFromUrl) {
            // @see excludeQueryParametersFromUrl()
            // Excluded the detected parameters from the URL
            $parametersToExclude = array($categoryParameterRaw, $keywordParameterRaw);
            if (isset($parsedUrl['query'])) {
                $parsedUrl['query'] = UrlHelper::getQueryStringWithExcludedParameters(UrlHelper::getArrayFromQueryString($parsedUrl['query']), $parametersToExclude);
            }
            if (isset($parsedUrl['fragment'])) {
                $parsedUrl['fragment'] = UrlHelper::getQueryStringWithExcludedParameters(UrlHelper::getArrayFromQueryString($fragmentQuery), $parametersToExclude);
                if ($fragmentBeforeQuery) {
                    if ($parsedUrl['fragment']) {
                        $parsedUrl['fragment'] = $fragmentBeforeQuery.'?'.$parsedUrl['fragment'];
                    } else {
                        $parsedUrl['fragment'] = $fragmentBeforeQuery;
                    }
                }
            }
        }
        $url = UrlHelper::getParseUrlReverse($parsedUrl);
        if (is_array($actionName)) {
            $actionName = reset($actionName);
        }

        $actionName = PageUrl::urldecodeValidUtf8($actionName);
        $actionName = trim($actionName);
        if (empty($actionName)) {
            return false;
        }

        if (is_array($categoryName)) {
            $categoryName = reset($categoryName);
        }
        $categoryName = PageUrl::urldecodeValidUtf8($categoryName);
        $categoryName = trim($categoryName);

        return array($url, $actionName, $categoryName, $count);
    }

    protected static function isValidSearchCount($count)
    {
        return is_numeric($count) && $count >= 0;
    }

    public function detectSiteSearch($originalUrl)
    {
        $website = Cache::getCacheWebsiteAttributes($this->request->getIdSite());
        if (empty($website['sitesearch'])) {
            Common::printDebug("Internal 'Site Search' tracking is not enabled for this site. ");
            return false;
        }

        $actionName = $url = $categoryName = $count = false;

        $originalUrl = PageUrl::cleanupUrl($originalUrl);

        // Detect Site search from Tracking API parameters rather than URL
        $searchKwd = $this->request->getParam('search');
        if (!empty($searchKwd)) {
            $actionName = $searchKwd;
            $isCategoryName = $this->request->getParam('search_cat');
            if (!empty($isCategoryName)) {
                $categoryName = $isCategoryName;
            }
            $isCount = $this->request->getParam('search_count');
            if ($this->isValidSearchCount($isCount)) {
                $count = $isCount;
            }
        }

        if (empty($actionName)) {
            $parsedUrl = @parse_url($originalUrl);

            // Detect Site Search from URL query parameters
            if (!empty($parsedUrl['query']) || !empty($parsedUrl['fragment'])) {
                // array($url, $actionName, $categoryName, $count);
                $searchInfo = $this->detectSiteSearchFromUrl($website, $parsedUrl, $this->request->getParam('cs'));
                if (!empty($searchInfo)) {
                    list ($url, $actionName, $categoryName, $count) = $searchInfo;
                }
            }
        }

        $actionName = trim($actionName);
        $categoryName = trim($categoryName);

        if (empty($actionName)) {
            Common::printDebug("(this is not a Site Search request)");
            return false;
        }

        Common::printDebug("Detected Site Search keyword '$actionName'. ");
        if (!empty($categoryName)) {
            Common::printDebug("- Detected Site Search Category '$categoryName'. ");
        }
        if ($count !== false) {
            Common::printDebug("- Search Results Count was '$count'. ");
        }
        if ($url != $originalUrl) {
            Common::printDebug("NOTE: The Page URL was changed / removed, during the Site Search detection, was '$originalUrl', now is '$url'");
        }

        return array(
            $actionName,
            $url,
            $categoryName,
            $count
        );
    }
}
