<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\ScheduledReports\tests\Integration\ReportEmailGenerator;


use PHPMailer\PHPMailer\PHPMailer;
use Piwik\Mail;
use Piwik\Plugins\ScheduledReports\GeneratedReport;
use Piwik\Plugins\ScheduledReports\ReportEmailGenerator\AttachedFileReportEmailGenerator;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Plugins\SegmentEditor\API as APISegmentEditor;

/**
 * @group AttachedFileReportEmailGeneratorTest
 * @group ScheduledReports
 * @group Plugins
 */
class AttachedFileReportEmailGeneratorTest extends IntegrationTestCase
{
    /**
     * @var AttachedFileReportEmailGenerator
     */
    private $testInstance;

    /**
     * @var PHPMailer
     */
    private $mail;

    public function setUp(): void
    {
        parent::setUp();

        $this->testInstance = new AttachedFileReportEmailGenerator('.thing', 'generic/thing');

        Fixture::createWebsite('2011-01-01 00:00:00', $ecommerce = 0, 'sitename');
    }

    public function test_makeEmail_ReturnsCorrectlyConfiguredEmailInstance()
    {
        $reportDetails = [
            'format' => 'html',
            'period' => 'day',
            'idsite' => '1',
        ];

        $generatedReport = new GeneratedReport(
            $reportDetails,
            'report',
            'pretty date',
            'report contents',
            []
        );

        $mail = $this->testInstance->makeEmail($generatedReport);
        $mailContent = $this->getMailContent($mail);

        $this->assertStringStartsWith('<html', $mail->getBodyHtml());
        $this->assertEquals('General_Report report - pretty date', $mail->getSubject());
        self::assertStringContainsString('ScheduledReports_PleaseFindAttachedFile', $mailContent);
        self::assertStringContainsString('ScheduledReports_SentFromX', $mailContent);
        self::assertStringContainsString("Content-Type: text/html; charset=utf-8" . PHPMailer::getLE() . "Content-Transfer-Encoding: quoted-printable" . PHPMailer::getLE(), $this->mail->createBody());

        $attachments = $this->mail->getAttachments();
        $this->assertEquals([
            [
                'report contents',
                'General_Report report - pretty date.thing',
                'General_Report report - pretty date.thing',
                'base64',
                'generic/thing',
                true,
                'attachment',
                0
            ],
        ], $attachments);
    }

    public function test_makeEmail_OmitsSentFrom_IfPiwikUrlDoesNotExist()
    {
        $this->testInstance = new AttachedFileReportEmailGenerator('.thing', 'generic/thing', false);

        $reportDetails = [
            'format' => 'html',
            'period' => 'week',
            'idsite' => '1',
        ];

        $generatedReport = new GeneratedReport(
            $reportDetails,
            'report',
            'pretty date',
            'report contents',
            []
        );

        $mail = $this->testInstance->makeEmail($generatedReport);
        $mailContent = $this->getMailContent($mail);

        $this->assertStringStartsWith('<html', $mailContent);
        self::assertStringContainsString('ScheduledReports_PleaseFindAttachedFile', $mailContent);
        self::assertStringContainsString("Content-Type: text/html; charset=utf-8" . PHPMailer::getLE() . "Content-Transfer-Encoding: quoted-printable" . PHPMailer::getLE(), $this->mail->createBody());
    }

    public function test_makeEmail_AddsSegmentInformation_IfReportIsForSavedSegment()
    {
        $idsegment = APISegmentEditor::getInstance()->add('testsegment', 'browserCode==ff');

        $reportDetails = [
            'format' => 'html',
            'period' => 'week',
            'idsite' => '1',
            'idsegment' => $idsegment,
        ];

        $generatedReport = new GeneratedReport(
            $reportDetails,
            'report',
            'pretty date',
            'report contents',
            []
        );

        $mail = $this->testInstance->makeEmail($generatedReport);
        $mailContent = $this->getMailContent($mail);

        $this->assertStringStartsWith('<html', $mailContent);
        self::assertStringContainsString("ScheduledReports_PleaseFindAttachedFile", $mailContent);
        self::assertStringContainsString('ScheduledReports_SentFromX', $mailContent);
        self::assertStringContainsString('ScheduledReports_CustomVisitorSegment', $mailContent);
        self::assertStringContainsString("Content-Type: text/html; charset=utf-8" . PHPMailer::getLE() . "Content-Transfer-Encoding: quoted-printable" . PHPMailer::getLE(), $this->mail->createBody());
    }

    private function getMailContent(Mail $mail)
    {
        $mail->addTo('noreply@localhost');
        $mail->send();
        return $mail->getBodyHtml();
    }


    public function provideContainerConfig()
    {
        return [
            'observers.global' => \DI\add([
                ['Test.Mail.send', \DI\value(function (PHPMailer $mail) {
                    $this->mail = $mail;
                    $this->mail->preSend();
                })],
            ]),
        ];
    }

}