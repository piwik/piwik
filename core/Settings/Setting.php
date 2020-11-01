<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Settings;

use Piwik\Piwik;
use Piwik\Settings\Storage\Storage;
use Exception;
use Piwik\Validators\BaseValidator;

/**
 * Base setting type class.
 *
 * @api
 */
class Setting
{

    /**
     * The name of the setting
     * @var string
     */
    protected $name;

    /**
     * Null while not initialized, bool otherwise.
     * @var null|bool
     */
    protected $hasWritePermission = null;

    /**
     * @var Storage
     */
    protected $storage;

    /**
     * @var string
     */
    protected $pluginName;

    /**
     * @var FieldConfig
     */
    protected $config;

    /**
     * @var \Closure|null
     */
    protected $configureCallback;

    /**
     * @var mixed
     */
    protected $defaultValue;

    /**
     * @var string
     */
    protected $type;

    /**
     * Constructor.
     *
     * @param string $name    The setting's persisted name. Only alphanumeric characters are allowed, eg,
     *                        `'refreshInterval'`.
     * @param mixed $defaultValue  Default value for this setting if no value was specified.
     * @param string $type Eg an array, int, ... see SettingConfig::TYPE_* constants
     * @param string $pluginName   The name of the plugin the setting belongs to
     * @throws Exception
     */
    public function __construct($name, $defaultValue, $type, $pluginName)
    {
        if (!ctype_alnum(str_replace('_', '', $name))) {
            $msg = sprintf('The setting name "%s" in plugin "%s" is invalid. Only underscores, alpha and numerical characters are allowed', $name, $pluginName);
            throw new Exception($msg);
        }

        $this->name = $name;
        $this->type = $type;
        $this->pluginName = $pluginName;
        $this->setDefaultValue($defaultValue);
    }

    /**
     * Get the name of the setting.
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the PHP type of the setting.
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @internal
     * @ignore
     * @param $callback
     */
    public function setConfigureCallback($callback)
    {
        $this->configureCallback = $callback;
        $this->config = null;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue()
    {
        return $this->defaultValue;
    }

    /**
     * Sets/overwrites the current default value
     * @param string $defaultValue
     */
    public function setDefaultValue($defaultValue)
    {
        $this->defaultValue = $defaultValue;
    }

    /**
     * @internal
     * @param Storage $storage
     */
    public function setStorage(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * @internal
     * @ignore
     * @return FieldConfig
     * @throws Exception
     */
    public function configureField()
    {
        if (!$this->config) {
            $this->config = new FieldConfig();

            if ($this->configureCallback) {
                call_user_func($this->configureCallback, $this->config);
            }

            $this->setUiControlIfNeeded($this->config);
            $this->checkType($this->config);
        }

        return $this->config;
    }

    /**
     * Set whether setting is writable or not. For example to hide setting from the UI set it to false.
     *
     * @param bool $isWritable
     */
    public function setIsWritableByCurrentUser($isWritable)
    {
        $this->hasWritePermission = (bool) $isWritable;
    }

    /**
     * Returns `true` if this setting is writable for the current user, `false` if otherwise. In case it returns
     * writable for the current user it will be visible in the Plugin settings UI.
     *
     * @return bool
     */
    public function isWritableByCurrentUser()
    {
        return (bool) $this->hasWritePermission;
    }

    /**
     * Saves (persists) the value for this setting in the database if a value has been actually set.
     */
    public function save()
    {
        $this->storage->save();

        if ($this->config->onValueSaved) {
            $cb = $this->config->onValueSaved;
            $cb($this);
        }
    }

    /**
     * Returns the previously persisted setting value. If no value was set, the default value
     * is returned.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->storage->getValue($this->name, $this->defaultValue, $this->type);
    }

    /**
     * Sets and persists this setting's value overwriting any existing value.
     *
     * Before a value is actually set it will be made sure the current user is allowed to change the value. The value
     * will be first validated either via a system built-in validate method or via a set {@link FieldConfig::$validate}
     * custom method. Afterwards the value will be transformed via a possibly specified {@link FieldConfig::$transform}
     * method. Before storing the actual value, the value will be converted to the actually specified {@link $type}.
     *
     * @param mixed $value
     * @throws \Exception If the current user is not allowed to change the value of this setting.
     */
    public function setValue($value)
    {
        $this->checkHasEnoughWritePermission();

        $config = $this->configureField();

        $this->validateValue($value);

        if ($config->transform && $config->transform instanceof \Closure) {
            $value = call_user_func($config->transform, $value, $this);
        }

        if (isset($this->type) && !is_null($value)) {
            settype($value, $this->type);
        }

        $this->storage->setValue($this->name, $value);
    }

    private function validateValue($value)
    {
        $config = $this->configureField();

        if (!empty($config->validators)) {
            BaseValidator::check($config->title, $value, $config->validators);
        }

        if ($config->validate && $config->validate instanceof \Closure) {
            call_user_func($config->validate, $value, $this);
        } elseif (is_array($config->availableValues)) {
            if (is_bool($value) && $value) {
                $value = '1';
            } elseif (is_bool($value)) {
                $value = '0';
            }

            // TODO move error message creation to a subclass, eg in MeasurableSettings we do not want to mention plugin name
            $errorMsg = Piwik::translate('CoreAdminHome_PluginSettingsValueNotAllowed',
                                         array(strip_tags($config->title), $this->pluginName));

            if (is_array($value) && $this->type === FieldConfig::TYPE_ARRAY) {
                foreach ($value as $val) {
                    if (!array_key_exists($val, $config->availableValues)) {
                        throw new \Exception($errorMsg);
                    }
                }
            } else {
                if (!array_key_exists($value, $config->availableValues)) {
                    throw new \Exception($errorMsg);
                }
            }
        } elseif ($this->type === FieldConfig::TYPE_INT || $this->type === FieldConfig::TYPE_FLOAT) {

            if (!is_numeric($value)) {
                $errorMsg = Piwik::translate('CoreAdminHome_PluginSettingsValueNotAllowed',
                                             array(strip_tags($config->title), $this->pluginName));
                throw new \Exception($errorMsg);
            }

        } elseif ($this->type === FieldConfig::TYPE_BOOL) {

            if (!in_array($value, array(true, false, '0', '1', 0, 1), true)) {
                $errorMsg = Piwik::translate('CoreAdminHome_PluginSettingsValueNotAllowed',
                                             array(strip_tags($config->title), $this->pluginName));
                throw new \Exception($errorMsg);
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function checkHasEnoughWritePermission()
    {
        if (!$this->isWritableByCurrentUser()) {
            $errorMsg = Piwik::translate('CoreAdminHome_PluginSettingChangeNotAllowed', array($this->name, $this->pluginName));
            throw new \Exception($errorMsg);
        }
    }

    private function setUiControlIfNeeded(FieldConfig $field)
    {
        if (!isset($field->uiControl)) {
            $defaultControlTypes = array(
                FieldConfig::TYPE_INT    => FieldConfig::UI_CONTROL_TEXT,
                FieldConfig::TYPE_FLOAT  => FieldConfig::UI_CONTROL_TEXT,
                FieldConfig::TYPE_STRING => FieldConfig::UI_CONTROL_TEXT,
                FieldConfig::TYPE_BOOL   => FieldConfig::UI_CONTROL_CHECKBOX,
                FieldConfig::TYPE_ARRAY  => FieldConfig::UI_CONTROL_MULTI_SELECT,
            );

            if (isset($defaultControlTypes[$this->type])) {
                $field->uiControl = $defaultControlTypes[$this->type];
            } else {
                $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            }
        }
    }

    private function checkType(FieldConfig $field)
    {
        if ($field->uiControl === FieldConfig::UI_CONTROL_MULTI_SELECT &&
            $this->type !== FieldConfig::TYPE_ARRAY) {
            throw new Exception('Type must be an array when using a multi select');
        }

        if ($field->uiControl === FieldConfig::UI_CONTROL_MULTI_TUPLE &&
            $this->type !== FieldConfig::TYPE_ARRAY) {
            throw new Exception('Type must be an array when using a multi pair');
        }

        $types = array(
            FieldConfig::TYPE_INT,
            FieldConfig::TYPE_FLOAT,
            FieldConfig::TYPE_STRING,
            FieldConfig::TYPE_BOOL,
            FieldConfig::TYPE_ARRAY
        );

        if (!in_array($this->type, $types)) {
            throw new Exception('Type does not exist');
        }
    }

}
