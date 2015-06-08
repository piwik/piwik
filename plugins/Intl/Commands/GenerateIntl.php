<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\Intl\Commands;

use Piwik\Container\StaticContainer;
use Piwik\Development;
use Piwik\Filesystem;
use Piwik\Http;
use Piwik\Plugin\ConsoleCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console Command to generate Intl-data files for Piwik
 *
 * This script uses the master data of unicode-cldr/cldr-localenames-full repository to fetch available translations
 */
class GenerateIntl extends ConsoleCommand
{
    public function isEnabled()
    {
        return Development::isEnabled();
    }

    protected function configure()
    {
        $this->setName('translations:generate-intl-data')
            ->setDescription('Generates Intl-data for Piwik');
    }

    protected function transformLangCode($langCode)
    {
        if (substr_count($langCode, '-') == 1) {
            $langCodeParts = explode('-', $langCode, 2);
            return sprintf('%s-%s', $langCodeParts[0], strtoupper($langCodeParts[1]));
        }
        return $langCode;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $piwikLanguages = \Piwik\Plugins\LanguagesManager\API::getInstance()->getAvailableLanguages();

        $aliasesUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-core/master/supplemental/aliases.json';
        $aliasesData = Http::fetchRemoteFile($aliasesUrl);
        $aliasesData = json_decode($aliasesData, true);
        $aliasesData = $aliasesData['supplemental']['metadata']['alias']['languageAlias'];

        $writePath = Filesystem::getPathToPiwikRoot() . '/plugins/Intl/lang/%s.json';

        foreach ($piwikLanguages AS $langCode) {

            if ($langCode == 'dev') {
                continue;
            }

            $requestLangCode = $transformedLangCode = $this->transformLangCode($langCode);

            if (array_key_exists($requestLangCode, $aliasesData)) {
                $requestLangCode = $aliasesData[$requestLangCode]['_replacement'];
            }

            // fix some locales
            $localFixes = array(
                'pt' => 'pt-PT',
                'pt-br' => 'pt',
                'zh-cn' => 'zh-Hans',
                'zh-tw' => 'zh-Hant'
            );

            if (array_key_exists($langCode, $localFixes)) {
                $requestLangCode = $localFixes[$langCode];
            }

            $translations = (array)@json_decode(file_get_contents(sprintf($writePath, $langCode)), true);

            $this->fetchLanguageData($output, $transformedLangCode, $requestLangCode, $translations);
            $this->fetchTerritoryData($output, $transformedLangCode, $requestLangCode, $translations);
            $this->fetchCalendarData($output, $transformedLangCode, $requestLangCode, $translations);

            ksort($translations['Intl']);

            file_put_contents(sprintf($writePath, $langCode), json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    protected function getEnglishLanguageName($code)
    {
        $languageDataUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-localenames-full/master/main/%s/languages.json';

        static $languageData = array();

        try {
            if (empty($languageData)) {
                $languageData = Http::fetchRemoteFile(sprintf($languageDataUrl, 'en'));
                $languageData = json_decode($languageData, true);
                $languageData = $languageData['main']['en']['localeDisplayNames']['languages'];
            }

            return (array_key_exists($code, $languageData) && $languageData[$code] != $code) ? $languageData[$code] : '';
        } catch (\Exception $e) {
        }

        return '';
    }

    protected function fetchLanguageData(OutputInterface $output, $langCode, $requestLangCode, &$translations)
    {
        $languageCodes = array_keys(StaticContainer::get('Piwik\Intl\Data\Provider\LanguageDataProvider')->getLanguageList());

        $languageDataUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-localenames-full/master/main/%s/languages.json';

        try {
            $languageData = Http::fetchRemoteFile(sprintf($languageDataUrl, $requestLangCode));
            $languageData = json_decode($languageData, true);
            $languageData = $languageData['main'][$requestLangCode]['localeDisplayNames']['languages'];

            if (empty($languageData)) {
                throw new \Exception();
            }

            foreach ($languageCodes AS $code) {
                if (!empty($languageData[$code]) && $languageData[$code] != $code) {
                    $translations['Intl']['Language_' . $code] = $languageData[$code];
                }
            }

            if (array_key_exists($langCode, $languageData) && $languageData[$langCode] != $langCode) {
                $translations['Intl']['OriginalLanguageName'] = $languageData[$langCode];
            } else if (array_key_exists($requestLangCode, $languageData) && $languageData[$requestLangCode] != $requestLangCode) {
                $translations['Intl']['OriginalLanguageName'] = $languageData[$requestLangCode];
            }
            $translations['Intl']['EnglishLanguageName'] = $this->getEnglishLanguageName($langCode) ? $this->getEnglishLanguageName($langCode) : $this->getEnglishLanguageName($requestLangCode);

            $output->writeln('Saved language data for ' . $langCode);
        } catch (\Exception $e) {
            $output->writeln('Unable to import language data for ' . $langCode);
        }
    }

    protected function fetchTerritoryData(OutputInterface $output, $langCode, $requestLangCode, &$translations)
    {
        $territoryDataUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-localenames-full/master/main/%s/territories.json';

        $countryCodes = array_keys(StaticContainer::get('Piwik\Intl\Data\Provider\RegionDataProvider')->getCountryList());
        $countryCodes = array_map('strtoupper', $countryCodes);

        $continentMapping = array(
            "afr" => "002",
            "amc" => "013",
            "amn" => "003",
            "ams" => "005",
            "ant" => "AQ",
            "asi" => "142",
            "eur" => "150",
            "oce" => "009"
        );

        try {
            $territoryData = Http::fetchRemoteFile(sprintf($territoryDataUrl, $requestLangCode));
            $territoryData = json_decode($territoryData, true);
            $territoryData = $territoryData['main'][$requestLangCode]['localeDisplayNames']['territories'];

            foreach ($countryCodes AS $code) {
                if (!empty($territoryData[$code]) && $territoryData[$code] != $code) {
                    $translations['Intl']['Country_' . $code] = $territoryData[$code];
                }
            }

            foreach ($continentMapping as $shortCode => $code) {
                if (!empty($territoryData[$code]) && $territoryData[$code] != $code) {
                    $translations['Intl']['Continent_' . $shortCode] = $territoryData[$code];
                }
            }

            $output->writeln('Saved territory data for ' . $langCode);
        } catch (\Exception $e) {
            $output->writeln('Unable to import territory data for ' . $langCode);
        }
    }

    protected function fetchCalendarData(OutputInterface $output, $langCode, $requestLangCode, &$translations)
    {
        $calendarDataUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-dates-full/master/main/%s/ca-gregorian.json';

        try {
            $calendarData = Http::fetchRemoteFile(sprintf($calendarDataUrl, $requestLangCode));
            $calendarData = json_decode($calendarData, true);
            $calendarData = $calendarData['main'][$requestLangCode]['dates']['calendars']['gregorian'];

            for ($i = 1; $i <= 12; $i++) {
                $translations['Intl']['ShortMonth_' . $i] = $calendarData['months']['format']['abbreviated'][$i];
                $translations['Intl']['LongMonth_' . $i] = $calendarData['months']['format']['wide'][$i];
            }

            $days = array(
                1 => 'mon',
                2 => 'tue',
                3 => 'wed',
                4 => 'thu',
                5 => 'fri',
                6 => 'sat',
                7 => 'sun'
            );

            foreach ($days AS $nr => $day) {
                $translations['Intl']['ShortDay_' . $nr] = $calendarData['days']['format']['abbreviated'][$day];
                $translations['Intl']['LongDay_' . $nr] = $calendarData['days']['format']['wide'][$day];
            }

            $days = array(
                'Mo' => 'mon',
                'Tu' => 'tue',
                'We' => 'wed',
                'Th' => 'thu',
                'Fr' => 'fri',
                'Sa' => 'sat',
                'Su' => 'sun'
            );

            foreach ($days AS $nr => $day) {
                $translations['Intl']['Day' . $nr] = $calendarData['days']['format']['short'][$day];
            }


            $output->writeln('Saved calendar data for ' . $langCode);
        } catch (\Exception $e) {
            $output->writeln('Unable to import calendar data for ' . $langCode);
        }

        $dateFieldsUrl = 'https://raw.githubusercontent.com/unicode-cldr/cldr-dates-full/master/main/%s/dateFields.json';

        try {
            $dateFieldData = Http::fetchRemoteFile(sprintf($dateFieldsUrl, $requestLangCode));
            $dateFieldData = json_decode($dateFieldData, true);
            $dateFieldData = $dateFieldData['main'][$requestLangCode]['dates']['fields'];

            #$translations['Intl']['Period_Week'] = $dateFieldData['week']['displayName'];
            #$translations['Intl']['Period_Year'] = $dateFieldData['year']['displayName'];
            #$translations['Intl']['Period_Day'] = $dateFieldData['day']['displayName'];
            #$translations['Intl']['Period_Month'] = $dateFieldData['month']['displayName'];
            $translations['Intl']['YearShort'] = $dateFieldData['year-narrow']['displayName'];
            $translations['Intl']['Today'] = $dateFieldData['day']['relative-type-0'];
            $translations['Intl']['Yesterday'] = $dateFieldData['day']['relative-type--1'];

            $output->writeln('Saved date fields for ' . $langCode);
        } catch (\Exception $e) {
            $output->writeln('Unable to import date fields for ' . $langCode);
        }
    }


}
