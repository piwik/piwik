<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\Monolog\tests\Unit\Processor;

use PHPUnit\Runner\Version;
use Piwik\Access;
use Piwik\Common;
use Piwik\Db;
use Piwik\Log;
use Piwik\Piwik;
use Piwik\Plugins\Monolog\Processor\ExceptionToTextProcessor;

/**
 * @group Log
 * @covers \Piwik\Plugins\Monolog\Processor\ExceptionToTextProcessor
 */
class ExceptionToTextProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var bool
     */
    private $hasSuperUserAccess;

    protected function setUp(): void
    {
        parent::setUp();

        Common::$isCliMode = true;
        $this->hasSuperUserAccess = Access::getInstance()->hasSuperUserAccess();
        Access::getInstance()->setSuperUserAccess(true);
    }

    protected function tearDown(): void
    {
        Access::getInstance()->setSuperUserAccess($this->hasSuperUserAccess);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function it_should_skip_if_no_exception()
    {
        $processor = new ExceptionToTextProcessor();

        $record = array('message' => 'Hello world');

        $this->assertEquals($record, $processor($record));
    }

    /**
     * @test
     */
    public function it_should_replace_message_with_formatted_exception()
    {
        $processor = new ExceptionToTextProcessor();
        Log::$debugBacktraceForTests = '[message and stack trace]';

        $exception = new \Exception('Hello world');
        $record = array(
            'context' => array(
                'exception' => $exception,
            ),
        );

        $result = $processor($record);

        $expected = array(
            'message' => __FILE__ . "(65): [message and stack trace] [Query: , CLI mode: 1]",
            'context' => array(
                'exception' => $exception,
            ),
        );

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_should_add_severity_for_errors()
    {
        $processor = new ExceptionToTextProcessor();
        Log::$debugBacktraceForTests = '[message and stack trace]';

        $exception = new \ErrorException('Hello world', 0, 1, 'file.php', 123);
        $record = array(
            'context' => array(
                'exception' => $exception,
            ),
        );

        $result = $processor($record);

        $expected = array(
            'message' => "file.php(123): [message and stack trace] [Query: , CLI mode: 1]",
            'context' => array(
                'exception' => $exception,
            ),
        );

        $this->assertEquals($expected, $result);
    }

    public function test_getMessageAndWholeBacktrace_doesNotPrintBacktraceIfInCliMode_AndNotCoreArchive()
    {
        $ex = new \Exception('test message');

        Common::$isCliMode = true;
        unset($_GET['trigger']);

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex);

        $expected = <<<EOI
test message
EOI;

        $this->assertEquals($expected, $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_doesNotPrintBacktraceIfNotInCliMode_AndInCoreArchive()
    {
        $ex = new \Exception('test message');

        Common::$isCliMode = false;
        $_GET['trigger'] = 'archivephp';

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex);

        $expected = <<<EOI
test message
EOI;

        $this->assertEquals($expected, $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_printsBacktraceIfInCliMode_AndInCoreArchive_EvenIfGlobalVarIsNotSet()
    {
        $ex = new \Exception('test message');

        Common::$isCliMode = true;
        $_GET['trigger'] = 'archivephp';

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex);
        $wholeTrace = preg_replace('/\\(\\d+\\)/', '', $wholeTrace);
        $wholeTrace = str_replace(PIWIK_INCLUDE_PATH, '', $wholeTrace);

        $expected = <<<EOI
test message
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_printsBacktraceIfInCliMode_AndInCoreArchive_EvenIfGlobalVarIsNotSet()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main}
EOI;

        $this->assertEquals($this->handleNewerPHPUnitTrace($expected), $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_printsBacktraceIf_PIWIK_PRINT_ERROR_BACKTRACE_isDefined()
    {
        $ex = new \Exception('test message');

        $GLOBALS['PIWIK_PRINT_ERROR_BACKTRACE'] = 1;

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex);
        $wholeTrace = preg_replace('/\\(\\d+\\)/', '', $wholeTrace);
        $wholeTrace = str_replace(PIWIK_INCLUDE_PATH, '', $wholeTrace);

        $expected = <<<EOI
test message
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_printsBacktraceIf_PIWIK_PRINT_ERROR_BACKTRACE_isDefined()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main}
EOI;

        $this->assertEquals($this->handleNewerPHPUnitTrace($expected), $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_printsBacktraceIf_PIWIK_TRACKER_DEBUG_globalIsSet()
    {
        $ex = new \Exception('test message');

        $GLOBALS['PIWIK_TRACKER_DEBUG'] = 1;

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex);
        $wholeTrace = preg_replace('/\\(\\d+\\)/', '', $wholeTrace);
        $wholeTrace = str_replace(PIWIK_INCLUDE_PATH, '', $wholeTrace);

        $expected = <<<EOI
test message
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_printsBacktraceIf_PIWIK_TRACKER_DEBUG_globalIsSet()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main}
EOI;

        $this->assertEquals($this->handleNewerPHPUnitTrace($expected), $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_handlesArrayInput_whenBacktraceIsEnabled()
    {
        $GLOBALS['PIWIK_PRINT_ERROR_BACKTRACE'] = 1;

        $exArray = [
            'message' => 'themessage',
            'backtrace' => 'thestacktrace',
        ];

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($exArray);

        $expected = <<<EOI
themessage
thestacktrace
EOI;

        $this->assertEquals($expected, $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_handlesArrayInput_whenBacktraceIsDisabled()
    {
        $exArray = [
            'message' => 'themessage',
            'backtrace' => 'thestacktrace',
        ];

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($exArray);

        $expected = 'themessage';

        $this->assertEquals($expected, $wholeTrace);
    }

    public function test_getMessageAndWholeBacktrace_shouldCombineCausedByExceptionBacktraces()
    {
        $ex1 = new \Exception('caused by 2');
        $ex2 = new \Exception('caused by 1', 0, $ex1);
        $ex3 = new \Exception('test message', 0, $ex2);

        $GLOBALS['PIWIK_TRACKER_DEBUG'] = 1;

        $wholeTrace = ExceptionToTextProcessor::getMessageAndWholeBacktrace($ex3);
        $wholeTrace = preg_replace('/\\(\\d+\\)/', '', $wholeTrace);
        $wholeTrace = str_replace(PIWIK_INCLUDE_PATH, '', $wholeTrace);

        $expected = <<<EOI
test message
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_shouldCombineCausedByExceptionBacktraces()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main},
caused by: caused by 1
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_shouldCombineCausedByExceptionBacktraces()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main},
caused by: caused by 2
#0 /vendor/phpunit/phpunit/src/Framework/TestCase.php: Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest->test_getMessageAndWholeBacktrace_shouldCombineCausedByExceptionBacktraces()
#1 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestCase->runTest()
#2 /vendor/phpunit/phpunit/src/Framework/TestResult.php: PHPUnit\\Framework\\TestCase->runBare()
#3 /vendor/phpunit/phpunit/src/Framework/TestCase.php: PHPUnit\\Framework\\TestResult->run(Object(Piwik\\Plugins\\Monolog\\tests\\Unit\\Processor\\ExceptionToTextProcessorTest))
#4 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestCase->run(Object(PHPUnit\\Framework\\TestResult))
#5 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#6 /vendor/phpunit/phpunit/src/Framework/TestSuite.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#7 /vendor/phpunit/phpunit/src/TextUI/TestRunner.php: PHPUnit\\Framework\\TestSuite->run(Object(PHPUnit\\Framework\\TestResult))
#8 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\TestRunner->doRun(Object(PHPUnit\\Framework\\TestSuite), Array, Array, true)
#9 /vendor/phpunit/phpunit/src/TextUI/Command.php: PHPUnit\\TextUI\\Command->run(Array, true)
#10 /vendor/phpunit/phpunit/phpunit: PHPUnit\\TextUI\\Command::main()
#11 {main}
EOI;

        $this->assertEquals($this->handleNewerPHPUnitTrace($expected), $wholeTrace);
    }

    private function handleNewerPHPUnitTrace($input)
    {
        if (version_compare(Version::id(), '9.0', '>=')) {
            $input = str_replace('TestRunner->doRun', 'TestRunner->run', $input);
        }

        return $input;
    }
}
