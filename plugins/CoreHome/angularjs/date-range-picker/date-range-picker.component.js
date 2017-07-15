/*!
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * Combines two jquery UI datepickers to provide a date range picker (that picks inclusive
 * ranges).
 *
 * Properties:
 * - startDate: The start of the date range. Should be a string in the YYYY-MM-DD format.
 * - endDate: The end of the date range. Should be a string in the YYYY-MM-DD format. Note:
 *            date ranges are inclusive.
 * - rangeChange: Called when one or both dates bounding the range change.
 * - submit: Called if the 'enter' key is pressed in either of the inputs.
 *
 * Usage:
 * <piwik-date-range-picker>
 */
(function () {
    angular.module('piwikApp').component('piwikDateRangePicker', {
        templateUrl: 'plugins/CoreHome/angularjs/date-range-picker/date-range-picker.component.html?cb=' + piwik.cacheBuster,
        bindings: {
            startDate: '<',
            endDate: '<',
            rangeChange: '&',
            submit: '&'
        },
        controller: DateRangePickerController
    });

    DateRangePickerController.$inject = [];

    function DateRangePickerController() {
        var vm = this;

        vm.fromPickerSelectedDates = null;
        vm.toPickerSelectedDates = null;
        vm.fromPickerHighlightedDates = null;
        vm.toPickerHighlightedDates = null;

        vm.$onChanges = $onChanges;
        vm.setStartRangeDate = setStartRangeDate;
        vm.setEndRangeDate = setEndRangeDate;
        vm.onRangeInputChanged = onRangeInputChanged;
        vm.getNewHighlightedDates = getNewHighlightedDates;

        function $onChanges() {
            try {
                var startDateParsed = $.datepicker.parseDate('yy-mm-dd', vm.startDate);
                vm.fromPickerSelectedDates = [startDateParsed, startDateParsed];
            } catch (e) {
                // ignore
            }

            try {
                var endDateParsed = $.datepicker.parseDate('yy-mm-dd', vm.endDate);
                vm.toPickerSelectedDates = [endDateParsed, endDateParsed];
            } catch (e) {
                // ignore
            }
        }

        function onRangeInputChanged(source, $event) {
            var dateStr = source === 'from' ? vm.startDate : vm.endDate;

            var date;
            try {
                date = $.datepicker.parseDate('yy-mm-dd', dateStr);
            } catch (e) {
                return;
            }

            if (source === 'from') {
                vm.fromPickerSelectedDates = [date, date];
            } else {
                vm.toPickerSelectedDates = [date, date];
            }

            rangeChanged();

            if ($event.keyCode === 13 && vm.submit) {
                vm.submit({
                    start: vm.startDate,
                    end: vm.endDate
                });
            }
        }

        function setStartRangeDate(date) {
            vm.startDate = $.datepicker.formatDate('yy-mm-dd', date);
            vm.fromPickerSelectedDates = [date, date];

            rangeChanged();
        }

        function setEndRangeDate(date) {
            vm.endDate = $.datepicker.formatDate('yy-mm-dd', date);
            vm.toPickerSelectedDates = [date, date];

            rangeChanged();
        }

        function rangeChanged() {
            if (!vm.rangeChange) {
                return;
            }

            vm.rangeChange({
                start: vm.startDate,
                end: vm.endDate
            });
        }

        function getNewHighlightedDates(date, $cell) {
            if ($cell.hasClass('ui-datepicker-unselectable')) {
                return null;
            }

            return [date, date];
        }
    }
})();
