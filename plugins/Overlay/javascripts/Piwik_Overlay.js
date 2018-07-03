/*!
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

var Piwik_Overlay = (function () {

    var $body, $iframe, $sidebar, $main, $location, $loading, $errorNotLoading;
    var $rowEvolutionLink, $transitionsLink, $visitorLogLink;

    var idSite, period, date, segment;

    var iframeSrcBase;
    var iframeDomain = '';
    var iframeCurrentPage = '';
    var iframeCurrentPageNormalized = '';
    var iframeCurrentActionLabel = '';
    var updateComesFromInsideFrame = false;

    /** Load the sidebar for a url */
    function loadSidebar(currentUrl) {
        showLoading();

        $location.html('&nbsp;').unbind('mouseenter').unbind('mouseleave');

        iframeCurrentPage = currentUrl;
        iframeDomain = currentUrl.match(/http(s)?:\/\/(www\.)?([^\/]*)/i)[3];

        var params = {
            module: 'Overlay',
            action: 'renderSidebar',
            currentUrl: currentUrl
        };

        if (segment) {
            params.segment = segment;
        }

        globalAjaxQueue.abort();
        var ajaxRequest = new ajaxHelper();
        ajaxRequest.addParams(params, 'get');
        ajaxRequest.setCallback(
            function (response) {
                hideLoading();

                var $response = $(response);

                var $responseLocation = $response.find('.overlayLocation');
                var $url = $responseLocation.find('span');
                iframeCurrentPageNormalized = $url.data('normalizedUrl');
                iframeCurrentActionLabel = $url.data('label');
                $url.html(piwikHelper.addBreakpointsToUrl($url.text()));
                $location.html($responseLocation.html()).show();
                $responseLocation.remove();

                var $locationSpan = $location.find('span');
                $locationSpan.html(piwikHelper.addBreakpointsToUrl($locationSpan.text()));
                if (iframeDomain) {
                    // use addBreakpointsToUrl because it also encoded html entities
                    $locationSpan.tooltip({
                        track: true,
                        items: '*',
                        tooltipClass: 'overlayTooltip',
                        content: '<strong>' + Piwik_Overlay_Translations.domain + ':</strong> ' +
                                  piwikHelper.addBreakpointsToUrl(iframeDomain),
                        show: false,
                        hide: false
                    });
                }

                $sidebar.empty().append($response).show();

                if (!$sidebar.find('.overlayNoData').length) {
                    $rowEvolutionLink.show();
                    $transitionsLink.show();
                    if ($('#segment').val()) {
                        $visitorLogLink.show();
                    }
                }

            }
        );
        ajaxRequest.setErrorCallback(function () {
            hideLoading();
            $errorNotLoading.show();
        });
        ajaxRequest.setFormat('html');
        ajaxRequest.send();
    }

    /** Adjust the dimensions of the iframe */
    function adjustDimensions() {
        $iframe.height($(window).height());
        $iframe.width($body.width() - $iframe.offset().left - 2); // -2 because of 2px border
    }

    /** Display the loading message and hide other containers */
    function showLoading() {
        $loading.show();

        $sidebar.hide();
        $location.hide();

        $rowEvolutionLink.hide();
        $transitionsLink.hide();
        $visitorLogLink.hide();

        $errorNotLoading.hide();
    }

    /** Hide the loading message */
    function hideLoading() {
        $loading.hide();
    }

    function getOverlaySegment(url) {
        var location = broadcast.getParamValue('segment', url);

        // angular will encode the value again since it is added as the fragment path, not the fragment query parameter,
        // so we have to decode it again after getParamValue
        location = decodeURIComponent(location);

        return location;
    }

    function getOverlayLocationFromHash(urlHash) {
        var location = broadcast.getParamValue('l', urlHash);

        // angular will encode the value again since it is added as the fragment path, not the fragment query parameter,
        // so we have to decode it again after getParamValue
        location = decodeURIComponent(location);

        return location;
    }

    /** $.history callback for hash change */
    function hashChangeCallback(urlHash) {
        var location = getOverlayLocationFromHash(urlHash);
        location = Overlay_Helper.decodeFrameUrl(location);

        if (location == iframeCurrentPageNormalized) {
            return;
        }

        if (!updateComesFromInsideFrame) {
            var iframeUrl = iframeSrcBase;
            if (location) {
                iframeUrl += '#' + location;
            }
            $iframe.attr('src', iframeUrl);
            showLoading();
        } else {
            loadSidebar(location);
        }

        updateComesFromInsideFrame = false;
    }

    return {

        /** This method is called when Overlay loads  */
        init: function (iframeSrc, pIdSite, pPeriod, pDate, pSegment) {
            iframeSrcBase = iframeSrc;
            idSite = pIdSite;
            period = pPeriod;
            date = pDate;
            segment = pSegment;

            $body = $('body');
            $iframe = $('#overlayIframe');
            $sidebar = $('#overlaySidebar');
            $location = $('#overlayLocation');
            $main = $('#overlayMain');
            $loading = $('#overlayLoading');
            $errorNotLoading = $('#overlayErrorNotLoading');

            $rowEvolutionLink = $('#overlayRowEvolution');
            $transitionsLink = $('#overlayTransitions');
            $visitorLogLink = $('#overlaySegmentedVisitorLog');

            adjustDimensions();

            showLoading();

            // apply initial dimensions
            window.setTimeout(function () {
                adjustDimensions();
            }, 50);

            // handle window resize
            $(window).resize(function () {
                adjustDimensions();
            });

            angular.element(document).injector().invoke(function ($rootScope) {
                $rootScope.$on('$locationChangeSuccess', function () {
                    hashChangeCallback(broadcast.getHash());
                });

                hashChangeCallback(broadcast.getHash());
            });

            if (window.location.href.split('#').length == 1) {
                hashChangeCallback('');
            }

            // handle date selection
            var $select = $('select#overlayDateRangeSelect').change(function () {
                var parts = $(this).val().split(';');
                if (parts.length == 2) {
                    period = parts[0];
                    date = parts[1];
                    window.location.href = Overlay_Helper.getOverlayLink(idSite, period, date, segment, iframeCurrentPage);
                }
            });

            var optionMatchFound = false;
            $select.find('option').each(function () {
                if ($(this).val() == period + ';' + date) {
                    $(this).prop('selected', true);
                    optionMatchFound = true;
                }
            });

            if (optionMatchFound) {
                $select.material_select();
            } else {
                $select.prepend('<option selected="selected">');
            }

            // handle transitions link
            $transitionsLink.click(function () {
                var unescapedSegment = null;
                if (segment) {
                    unescapedSegment = unescape(segment);
                }
                if (window.DataTable_RowActions_Transitions) {
                    DataTable_RowActions_Transitions.launchForUrl(iframeCurrentPageNormalized, unescapedSegment);
                }
                return false;
            });

            // handle row evolution link
            $rowEvolutionLink.click(function () {
                if (window.DataTable_RowActions_RowEvolution) {
                    DataTable_RowActions_RowEvolution.launch('Actions.getPageUrls', iframeCurrentActionLabel);
                }
                return false;
            });

            // handle segmented visitor log link
            $visitorLogLink.click(function () {
                SegmentedVisitorLog.show('Actions.getPageUrls', $('#segment').val(), {});
                return false;
            });
        },

        /** This callback is used from within the iframe */
        setCurrentUrl: function (currentUrl) {
            showLoading();

            var locationParts = location.href.split('#');
            var currentLocation = '';
            if (locationParts.length > 1) {
                currentLocation = getOverlayLocationFromHash(locationParts[1]);
            }

            var newFrameLocation = Overlay_Helper.encodeFrameUrl(currentUrl);

            if (newFrameLocation != currentLocation) {
                updateComesFromInsideFrame = true;

                // available in global scope
                var currentHashStr = broadcast.getHash();

                if (currentHashStr.charAt(0) == '?') {
                    currentHashStr = currentHashStr.substr(1);
                }

                currentHashStr = broadcast.updateParamValue('l=' + newFrameLocation, currentHashStr);

                var newLocation = window.location.href.split('#')[0] + '#?' + currentHashStr;
                // window.location.replace changes the current url without pushing it on the browser's history stack
                window.location.replace(newLocation);
            } else {
                // happens when the url is changed by hand or when the l parameter is there on page load
                loadSidebar(currentUrl);
            }
        }

    };

})();