<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Settings;
use Piwik\Validators\BaseValidator;

/**
 * Lets you configure a form field.
 *
 * @api
 */
class FieldConfig
{
    /**
     * Shows a radio field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_RADIO = 'radio';

    /**
     * Shows a text field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_TEXT = 'text';

    /**
     * Shows an email text field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_EMAIL = 'email';

    /**
     * Shows a URL text field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_URL = 'url';

    /**
     * Shows a text area. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_TEXTAREA = 'textarea';

    /**
     * Shows a checkbox. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_CHECKBOX = 'checkbox';

    /**
     * Shows a password field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_PASSWORD = 'password';

    /**
     * Shows a select field where a user can select multiple values.
     * The type "Array" is required for this ui control. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_MULTI_SELECT = 'multiselect';

    /**
     * Shows a select field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_SINGLE_SELECT = 'select';

    /**
     * Shows an expandable select field which is useful when each selectable value belongs to a group.
     * To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_SINGLE_EXPANDABLE_SELECT = 'expandable-select';

    /**
     * Lets a user configure an array of form fields.
     */
    const UI_CONTROL_FIELD_ARRAY = 'field-array';

    /**
     * Lets a user configure two form fields next to each other, and add multiple entries of those two pairs.
     */
    const UI_CONTROL_MULTI_TUPLE = 'multituple';

    /**
     * Generates a hidden form field. To use this field assign it to the `$uiControl` property.
     */
    const UI_CONTROL_HIDDEN = 'hidden';

    /**
     * Expects an integer value. Is usually used when creating a setting.
     */
    const TYPE_INT = 'integer';

    /**
     * Expects a float value. Is usually used when creating a setting.
     */
    const TYPE_FLOAT = 'float';

    /**
     * Expects a string. Is usually used when creating a setting.
     */
    const TYPE_STRING = 'string';

    /**
     * Expects a boolean. Is usually used when creating a setting.
     */
    const TYPE_BOOL = 'boolean';

    /**
     * Expects an array containing multiple values.
     */
    const TYPE_ARRAY = 'array';

    /**
     * Describes what HTML element should be used to manipulate the setting through Piwik's UI.
     *
     * See {@link Piwik\Plugin\Settings} for a list of supported control types.
     *
     * @var string
     */
    public $uiControl = null;

    /**
     * Defines a custom template file for a UI control. This file should render a UI control and expose the value in a
     * "formField.value" angular model. For an example see "plugins/CorePluginsAdmin/angularjs/form-field/field-text.html"
     *
     * @var string
     */
    public $customUiControlTemplateFile = '';

    /**
     * Name-value mapping of HTML attributes that will be added HTML form control, eg,
     * `array('size' => 3)`. Attributes will be escaped before outputting.
     *
     * @var array
     */
    public $uiControlAttributes = array();

    /**
     * The list of all available values for this setting. If null, the setting can have any value.
     *
     * If supplied, this field should be an array mapping available values with their prettified
     * display value. Eg, if set to `array('nb_visits' => 'Visits', 'nb_actions' => 'Actions')`,
     * the UI will display **Visits** and **Actions**, and when the user selects one, Piwik will
     * set the setting to **nb_visits** or **nb_actions** respectively.
     *
     * The setting value will be validated if this field is set. If the value is not one of the
     * available values, an error will be triggered.
     *
     * _Note: If a custom validator is supplied (see {@link $validate}), the setting value will
     * not be validated._
     *
     * @var null|array
     */
    public $availableValues = null;

    /**
     * Text that will appear above this setting's section in the _Plugin Settings_ admin page.
     *
     * @var null|string
     */
    public $introduction = null;

    /**
     * Text that will appear directly underneath the setting title in the _Plugin Settings_ admin
     * page. If set, should be a short description of the setting.
     *
     * @var null|string
     */
    public $description = null;

    /**
     * Text that will appear next to the setting's section in the _Plugin Settings_ admin page. If set,
     * it should contain information about the setting that is more specific than a general description,
     * such as the format of the setting value if it has a special format.
     *
     * Be sure to escape any user input as HTML can be used here.
     *
     * @var null|string
     */
    public $inlineHelp = null;

    /**
     * A closure that does some custom validation on the setting before the setting is persisted.
     *
     * The closure should take two arguments: the setting value and the {@link Setting} instance being
     * validated. If the value is found to be invalid, the closure should throw an exception with
     * a message that describes the error.
     *
     * **Example**
     *
     *     $setting->validate = function ($value, Setting $setting) {
     *         if ($value > 60) {
     *             throw new \Exception('The time limit is not allowed to be greater than 60 minutes.');
     *         }
     *     }
     *
     * @var null|\Closure
     */
    public $validate = null;

    /**
     * A closure that transforms the setting value. If supplied, this closure will be executed after
     * the setting has been validated.
     *
     * _Note: If a transform is supplied, the setting's {@link $type} has no effect. This means the
     * transformation function will be responsible for casting the setting value to the appropriate
     * data type._
     *
     * **Example**
     *
     *     $setting->transform = function ($value, Setting $setting) {
     *         if ($value > 30) {
     *             $value = 30;
     *         }
     *
     *         return (int) $value;
     *     }
     *
     * @var null|\Closure
     */
    public $transform = null;

    /**
     * This setting's display name, for example, `'Refresh Interval'`.
     *
     * Be sure to escape any user input as HTML can be used here.
     *
     * @var string
     */
    public $title = '';

    /**
     * Here you can define conditions so that certain form fields will be only shown when a certain condition
     * is true. This condition is supposed to be evaluated on the client side dynamically. This way you can hide
     * for example some fields depending on another field. For example if SiteSearch is disabled, fields to enter
     * site search keywords is not needed anymore and can be disabled.
     *
     * For example 'sitesearch', or 'sitesearch && !use_sitesearch_default' where 'sitesearch' and 'use_sitesearch_default'
     * are both values of fields.
     *
     * @var string
     */
    public $condition;

    /**
     * Here you can add one or multiple instances of `Piwik\Validators\BaseValidator` to avoid having to
     * write the same validators over and over again in {@link $validate}.
     *
     * Examples
     * Want to require a value to be set?
     * $fieldConfig->validators[] = new Piwik\Validators\NotEmpty();
     *
     * Want to require an email?
     * $fieldConfig->validators[] = new Piwik\Validators\NotEmpty();
     * $fieldConfig->validators[] = new Piwik\Validators\Email();
     *
     * The core comes with a set of various validators that can be used.
     *
     * @var BaseValidator[]
     */
    public $validators = [];

    /**
     * Closure to execute when a value is saved. This can be used to, eg, trigger an event
     * or regenerate a file, etc.
     *
     * The function should accept one argument, the Setting being saved.
     *
     * @var \Closure
     */
    public $onValueSaved = null;
}
