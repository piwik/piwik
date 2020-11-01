<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\CustomJsTracker;

use Piwik\Common;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\CustomJsTracker\TrackingCode\PiwikJsManipulator;
use Piwik\Plugins\CustomJsTracker\TrackingCode\PluginTrackerFiles;
use Piwik\Piwik;

/**
 * Updates the Piwik JavaScript Tracker "piwik.js" in case plugins extend the tracker.
 *
 * Usage:
 * StaticContainer::get('Piwik\Plugins\CustomJsTracker\TrackerUpdater')->update();
 */
class TrackerUpdater
{
    const DEVELOPMENT_PIWIK_JS = '/js/piwik.js';
    const ORIGINAL_PIWIK_JS = '/js/piwik.min.js';
    const TARGET_MATOMO_JS = '/matomo.js';

    /**
     * @var File
     */
    private $fromFile;

    /**
     * @var File
     */
    private $toFile;

    private $trackerFiles = array();

    /**
     * @param string|null $fromFile If null then the minified JS tracker file in /js fill be used
     * @param string|null $toFile If null then the minified JS tracker will be updated.
     */
    public function __construct($fromFile = null, $toFile = null)
    {
        if (!isset($fromFile)) {
            $fromFile = PIWIK_DOCUMENT_ROOT . self::ORIGINAL_PIWIK_JS;
        }

        if (!isset($toFile)) {
            $toFile = PIWIK_DOCUMENT_ROOT . self::TARGET_MATOMO_JS;
        }

        $this->setFromFile($fromFile);
        $this->setToFile($toFile);
        $this->trackerFiles = StaticContainer::get('Piwik\Plugins\CustomJsTracker\TrackingCode\PluginTrackerFiles');
    }

    public function setFromFile($fromFile)
    {
        if (is_string($fromFile)) {
            $fromFile = new File($fromFile);
        }
        $this->fromFile = $fromFile;
    }

    public function getFromFile()
    {
        return $this->fromFile;
    }

    public function setToFile($toFile)
    {
        if (is_string($toFile)) {
            $toFile = new File($toFile);
        }
        $this->toFile = $toFile;
    }

    public function getToFile()
    {
        return $this->toFile;
    }

    public function setTrackerFiles(PluginTrackerFiles $trackerFiles)
    {
        $this->trackerFiles = $trackerFiles;
    }

    /**
     * Checks whether the Piwik JavaScript tracker file "piwik.js" is writable.
     * @throws \Exception In case the piwik.js file is not writable.
     *
     * @api
     */
    public function checkWillSucceed()
    {
        $this->fromFile->checkReadable();
        $this->toFile->checkWritable();
    }

    public function getCurrentTrackerFileContent()
    {
        return $this->toFile->getContent();
    }

    public function getUpdatedTrackerFileContent()
    {
        $trackingCode = new PiwikJsManipulator($this->fromFile->getContent(), $this->trackerFiles);
        $newContent = $trackingCode->manipulateContent();

        return $newContent;
    }

    /**
     * Updates / re-generates the Piwik JavaScript tracker "piwik.js".
     *
     * It may not be possible to update the "piwik.js" tracker file if the file is not writable. It won't throw
     * an exception in such a case and instead just to nothing. To check if the update would succeed, call
     * {@link checkWillSucceed()}.
     *
     * @api
     */
    public function update()
    {
        if (!$this->toFile->hasWriteAccess() || !$this->fromFile->hasReadAccess()) {
            return; // TODO: maybe report this in a diagnostic?
        }

        $newContent = $this->getUpdatedTrackerFileContent();

        if (!$this->toFile->isFileContentSame($newContent)) {
            $savedFiles = $this->toFile->save($newContent);
            foreach ($savedFiles as $savedFile) {

                /**
                 * Triggered after the tracker JavaScript content (the content of the piwik.js file) is changed.
                 *
                 * @param string $absolutePath The path to the new piwik.js file.
                 */
                Piwik::postEvent('CustomJsTracker.trackerJsChanged', [$savedFile]);
            }

        }

        // we need to make sure to sync matomo.js / piwik.js
        $this->updateAlternative('piwik.js', 'matomo.js', $newContent);
        $this->updateAlternative('matomo.js', 'piwik.js', $newContent);
    }

    private function updateAlternative($fromFile, $toFile, $newContent)
    {
        if (Common::stringEndsWith($this->toFile->getName(), $fromFile)) {
            $alternativeFilename = dirname($this->toFile->getPath()) . DIRECTORY_SEPARATOR . $toFile;
            $file = $this->toFile->setFile($alternativeFilename);
            if ($file->hasWriteAccess() && !$file->isFileContentSame($newContent)) {
                $savedFiles = $file->save($newContent);
                foreach ($savedFiles as $savedFile) {
                    Piwik::postEvent('CustomJsTracker.trackerJsChanged', [$savedFile]);
                }
            }
        }
    }
}
