/*!
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */
(function () {
    angular.module('piwikApp').controller('ManageGoalsController', ManageGoalsController);

    ManageGoalsController.$inject = ['piwik', 'piwikApi', '$timeout', '$location', 'reportingMenuModel', '$rootScope'];

    function ManageGoalsController(piwik, piwikApi, $timeout, $location, reportingMenuModel, $rootScope) {
        // remember to keep controller very simple. Create a service/factory (model) if needed

        var self = this;

        if (!this.goal) {
            this.goal = {};
        }
        this.showEditGoal = false;
        this.showGoalList = true;

        function scrollToTop()
        {
            $timeout(function () {
                piwik.helper.lazyScrollTo(".pageWrap", 200);
            });
        }

        function initGoalForm(goalMethodAPI, submitText, goalName, description, matchAttribute, pattern, patternType, caseSensitive, revenue, allowMultiple, useEventValueAsRevenue, goalId) {

            $rootScope.$emit('Goals.beforeInitGoalForm', goalMethodAPI, goalId);

            self.goal = {};
            self.goal.name = goalName;
            self.goal.description = description;

            if (matchAttribute == 'manually') {
                self.goal.triggerType = 'manually';
                matchAttribute = 'url';
            } else {
                self.goal.triggerType = 'visitors';
            }

            if (0 === matchAttribute.indexOf('event')) {
                self.goal.eventType = matchAttribute;
                matchAttribute = 'event';
            } else {
                self.goal.eventType = 'event_category';
            }

            self.goal.matchAttribute = matchAttribute;
            self.goal.allowMultiple = allowMultiple;
            self.goal.patternType = patternType;
            self.goal.pattern = pattern;
            self.goal.caseSensitive = caseSensitive;
            self.goal.revenue = revenue;
            self.goal.apiMethod = goalMethodAPI;
            self.goal.useEventValueAsRevenue = useEventValueAsRevenue;

            self.goal.submitText = submitText;
            self.goal.goalId = goalId;

            $timeout(function () {
                var text = _pk_translate('Goals_AddNewGoal');
                if (goalId) {
                    text = _pk_translate('Goals_UpdateGoal')
                }

                $('.addEditGoal .card-title').text(text);
            });
        }

        this.isManuallyTriggered = function () {
            return this.goal.triggerType == 'manually';
        }

        this.save = function () {

            var parameters = {};
            parameters.name = encodeURIComponent(this.goal.name);
            parameters.description = encodeURIComponent(this.goal.description);

            if (this.isManuallyTriggered()) {
                parameters.matchAttribute = 'manually';
                parameters.patternType = 'regex';
                parameters.pattern = '.*';
                parameters.caseSensitive = 0;
            } else {
                parameters.matchAttribute = this.goal.matchAttribute;

                if (parameters.matchAttribute === 'event') {
                    parameters.matchAttribute = this.goal.eventType;
                }

                parameters.patternType = this.goal.patternType;
                parameters.pattern = encodeURIComponent(this.goal.pattern);
                parameters.caseSensitive = this.goal.caseSensitive == true ? 1 : 0;
            }
            parameters.revenue = this.goal.revenue || 0;
            parameters.allowMultipleConversionsPerVisit = this.goal.allowMultiple == true ? 1 : 0;
            parameters.useEventValueAsRevenue = this.goal.useEventValueAsRevenue == true ? 1 : 0;

            parameters.idGoal = this.goal.goalId;
            parameters.method = this.goal.apiMethod;

            var isCreate = parameters.method === 'Goals.addGoal';
            var isUpdate = parameters.method === 'Goals.updateGoal';

            if (isUpdate) {
                $rootScope.$emit('Goals.beforeUpdateGoal', parameters, piwikApi);
            } else if (isCreate) {
                $rootScope.$emit('Goals.beforeAddGoal', parameters, piwikApi);
            }

            if (parameters && 'undefined' !== typeof parameters.cancelRequest && parameters.cancelRequest) {
                return;
            }

            this.isLoading = true;

            piwikApi.fetch(parameters).then(function () {
                var search = $location.search();
                if (search
                    && search.subcategory
                    && search.subcategory == 'Goals_AddNewGoal'
                    && piwik.helper.isAngularRenderingThePage()) {
                    // when adding a goal for the first time we need to load manage goals page afterwards
                    reportingMenuModel.reloadMenuItems().then(function () {
                        $location.search('subcategory', 'Goals_ManageGoals');
                        self.isLoading = false;
                    });
                } else {
                    location.reload();
                }
            }, function () {
                scrollToTop();
                self.isLoading = false;
            });
        };

        this.changedTriggerType = function () {
            if (!this.isManuallyTriggered() && !this.goal.patternType) {
                this.goal.patternType = 'contains';
            }
        }

        this.showListOfReports = function (shouldScrollToTop) {
            $rootScope.$emit('Goals.cancelForm');

            this.showGoalList = true;
            this.showEditGoal = false;
            scrollToTop();
        };

        this.showAddEditForm = function () {
            this.showGoalList = false;
            this.showEditGoal = true;
        };

        this.createGoal = function () {

            var parameters = {isAllowed: true};
            $rootScope.$emit('Goals.initAddGoal', parameters);
            if (parameters && !parameters.isAllowed) {
                return;
            }

            this.showAddEditForm();
            initGoalForm('Goals.addGoal', _pk_translate('Goals_AddGoal'), '', '', 'url', '', 'contains', /*caseSensitive = */false, '', /*allowMultiple = */ false, /*useEventValueAsRevenue = */ false, '0');
            scrollToTop();
        };

        this.editGoal = function (goalId) {
            this.showAddEditForm();
            var goal = piwik.goals[goalId];
            initGoalForm("Goals.updateGoal", _pk_translate('Goals_UpdateGoal'), goal.name, goal.description, goal.match_attribute, goal.pattern, goal.pattern_type, (goal.case_sensitive != '0'), goal.revenue, goal.allow_multiple, (goal.event_value_as_revenue != '0'), goalId);
            scrollToTop();
        };

        this.deleteGoal = function (goalId) {
            var goal = piwik.goals[goalId];

            $('#confirm').find('h2').text(sprintf(_pk_translate('Goals_DeleteGoalConfirm'), '"' + goal.name + '"'));
            piwikHelper.modalConfirm('#confirm', {yes: function () {
                self.isLoading = true;

                piwikApi.fetch({idGoal: goalId, method: 'Goals.deleteGoal'}).then(function () {
                    location.reload();
                }, function () {
                    self.isLoading = false;
                });
                
            }});
        };

        this.isMatchAttributeNumeric = function () {
            return ['visit_duration', 'visit_nb_pageviews'].indexOf(this.goal.matchAttribute) > -1;
        };

        this.initPatternType = function () {
            if (this.isMatchAttributeNumeric()) {
                this.goal.patternType = 'greater_than';
            } else {
                this.goal.patternType = 'contains';
            }
        };

        this.getPatternFieldLabel = function () {
            if (this.goal.matchAttribute === 'visit_duration') {
                return _pk_translate('Goals_TimeInMinutes');
            } else if (this.goal.matchAttribute === 'visit_nb_pageviews') {
                return _pk_translate('Goals_NumberOfPages');
            }

            return  _pk_translate('Goals_Pattern');
        };

        this.showListOfReports(false);
    }
})();
