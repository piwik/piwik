/*!
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

/**
 * This file registers the visitor details overlay row action on the user IDs list page.
 */
(function () {

    var actionName = 'visitorDetails';

    function DataTable_RowActions_VisitorDetails(dataTable) {
        this.dataTable = dataTable;
        this.actionName = actionName;
        this.trEventName = 'piwikTriggerVisitorDetailsAction';
    }

    DataTable_RowActions_VisitorDetails.prototype = new DataTable_RowAction();

    DataTable_RowActions_VisitorDetails.prototype.performAction = function (label, tr, e) {
        var visitorId = this.getRowMetadata($(tr)).idvisitor || '';
        visitorId = encodeURIComponent(visitorId);
        if (visitorId.length > 0) {
            DataTable_RowAction.prototype.openPopover.apply(this, ['module=Live&action=getVisitorProfilePopup&visitorId=' + visitorId]);
        }
    };

    DataTable_RowActions_VisitorDetails.prototype.doOpenPopover = function (urlParam) {
        Piwik_Popover.createPopupAndLoadUrl(urlParam, _pk_translate('Live_VisitorProfile'), 'visitor-profile-popup');
    };

    DataTable_RowActions_Registry.register({

        name: actionName,

        instance: null,

        dataTableIcon: 'icon-visitor-profile',

        order: 30,

        dataTableIconTooltip: [
            _pk_translate('Live_ViewVisitorProfile'),
            ''
        ],

        isAvailableOnReport: function (dataTableParams, undefined) {
            return dataTableParams.module == 'UserId' || (dataTableParams.module=='Live' && dataTableParams.action =='getVisitors');
        },

        isAvailableOnRow: function (dataTableParams, tr) {
            return DataTable_RowAction.prototype.getRowMetadata(tr).hasOwnProperty('idvisitor');
        },

        createInstance: function (dataTable, param) {
            if (dataTable !== null && typeof dataTable.visitorDetailsInstance != 'undefined') {
                return dataTable.segmentVisitorLogInstance;
            }

            var instance = new DataTable_RowActions_VisitorDetails(dataTable);
            if (dataTable !== null) {
                dataTable.visitorDetailsInstance = instance;
            }

            this.instance = instance;

            return instance;
        }
    });
})();