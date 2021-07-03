<?php declare(strict_types=1);

/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CoreAdminHome\Commands\DeleteConfig;

use \RuntimeException;
use \InvalidArgumentException;
use Piwik\Config;

/**
 * Representation of a INI config manipulation operation. Only supports three types
 * of manipulations: removing a config array, remove a config or remove a config array position.
 */
class ConfigDeletingManipulation
{
    /**
     * @var string
     */
    private $sectionName;

    /**
     * @var string|null
     */
    private $name;

    /**
     * @param string      $sectionName
     * @param string|null $name
     */
    public function __construct(string $sectionName, string $name)
    {
        $this->sectionName = $sectionName;
        $this->name = $name;
    }

    /**
     * Performs the INI config manipulation.
     *
     * @param Config $config
     * @throws RuntimeException If trying to delete not existing section or config
     */
    public function manipulate(Config $config): void
    {
        $this->deleteConfigSetting($config);
    }

    /**
     * @param Config $config
     *
     * @throws RuntimeException When trying to delete not existing config
     */
    private function deleteConfigSetting(Config $config): void
    {
        $sectionName = $this->sectionName;
        $section = $config->$sectionName;

        if (!isset($section[$this->name])) {
            throw new RuntimeException("Trying to delete not existing config in array setting ".$this->getSettingString().".");
        }

        unset($section[$this->name]);

        $config->$sectionName = $section;
    }

    /**
     * Creates a ConfigSettingManipulation instance from a string like:
     *
     * `sectionName`
     *
     * @param string $assignment
     *
     * @return self
     */
    public static function make(string $assignment): self
    {
        if (!preg_match("/^(?'section'[\w]+)\.?(?'setting_key'[\w]+)?/", $assignment, $matches)) {
            throw new InvalidArgumentException("Invalid assignment string '$assignment': expected section or section.config_setting_key");
        }

        return new self($matches['section'], $matches['setting_key']);
    }

    /**
     * @return string
     */
    private function getSettingString(): string
    {
        return "[{$this->sectionName}] {$this->name}";
    }

    /**
     * @return string
     */
    public function getSectionName(): string
    {
        return $this->sectionName;
    }

    /**
     * @return string
     */
    public function getName(): ?string
    {
        return $this->name;
    }
}