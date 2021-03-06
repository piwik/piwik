/*!
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * The given expression will be executed when the user presses either escape or presses something outside
 * of this element
 *
 * Example:
 * <div piwik-focus-anywhere-but-here="closeDialog()">my dialog</div>
 */
(function () {
    angular.module('piwikApp.directive').directive('piwikFocusAnywhereButHere', piwikFocusAnywhereButHere);

    piwikFocusAnywhereButHere.$inject = ['$document'];

    function piwikFocusAnywhereButHere($document){
        return {
            restrict: 'A',
            link: function(scope, element, attr, ctrl) {

                var isMouseDown = false;
                var hasScrolled = false;

                function onClickOutsideElement (event) {
                    var hadUsedScrollbar = isMouseDown && hasScrolled;
                    isMouseDown = false;
                    hasScrolled = false;

                    if (hadUsedScrollbar) {
                        return;
                    }

                    if (element.has(event.target).length === 0) {
                        setTimeout(function () {
                            scope.$apply(attr.piwikFocusAnywhereButHere);
                        }, 0);
                    }
                }

                function onScroll (event) {
                    hasScrolled = true;
                }

                function onMouseDown (event) {
                    isMouseDown = true;
                    hasScrolled = false;
                }

                function onEscapeHandler (event) {
                    if (event.which === 27) {
                        setTimeout(function () {
                            isMouseDown = false;
                            hasScrolled = false;
                            scope.$apply(attr.piwikFocusAnywhereButHere);
                        }, 0);
                    }
                }

                $document.on('keyup', onEscapeHandler);
                $document.on('mousedown', onMouseDown);
                $document.on('mouseup', onClickOutsideElement);
                $document.on('scroll', onScroll);
                scope.$on('$destroy', function() {
                    $document.off('keyup', onEscapeHandler);
                    $document.off('mousedown', onMouseDown);
                    $document.off('mouseup', onClickOutsideElement);
                    $document.off('scroll', onScroll);
                });
            }
        };
    }
})();
