<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * Test that content produced by CiviMail looks the way it's expected.
 *
 * @package CiviCRM_APIv3
 * @subpackage API_Job
 *
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * Class CRM_Mailing_MailingSystemTest
 * @group headless
 * @see \Civi\FlexMailer\FlexMailerSystemTest
 * @see CRM_Mailing_MailingSystemTest
 */
abstract class CRM_Mailing_BaseMailingSystemTest extends CiviUnitTestCase {
  protected $_apiversion = 3;

  public $DBResetRequired = FALSE;
  public $defaultParams = [];
  private $_groupID;

  /**
   * @var CiviMailUtils
   */
  private $_mut;

  public function setUp(): void {
    $this->useTransaction();
    parent::setUp();
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;

    $this->_groupID = $this->groupCreate();
    $this->createContactsInGroup(2, $this->_groupID);

    $this->defaultParams = [
      'name' => 'mailing name',
      'created_id' => 1,
      'groups' => ['include' => [$this->_groupID]],
      'scheduled_date' => 'now',
    ];
    $this->_mut = new CiviMailUtils($this, TRUE);
    $this->callAPISuccess('mail_settings', 'get',
      ['api.mail_settings.create' => ['domain' => 'chaos.org']]);
  }

  /**
   */
  public function tearDown(): void {
    $this->_mut->stop();
    CRM_Utils_Hook::singleton()->reset();
    // DGW
    CRM_Mailing_BAO_MailingJob::$mailsProcessed = 0;
    parent::tearDown();
  }

  /**
   * Generate a fully-formatted mailing with standard email headers.
   */
  public function testBasicHeaders() {
    $allMessages = $this->runMailingSuccess([
      'subject' => 'Accidents in cars cause children for {contact.display_name}!',
      'body_text' => 'BEWARE children need regular infusions of toys. Santa knows your {domain.address}. There is no {action.optOutUrl}.',
    ]);
    foreach ($allMessages as $k => $message) {
      /** @var ezcMail $message */

      $offset = $k + 1;

      $this->assertEquals("FIXME", $message->from->name);
      $this->assertEquals("info@EXAMPLE.ORG", $message->from->email);
      $this->assertEquals("Mr. Foo{$offset} Anderson II", $message->to[0]->name);
      $this->assertEquals("mail{$offset}@nul.example.com", $message->to[0]->email);

      $this->assertRegExp('#^text/plain; charset=utf-8#', $message->headers['Content-Type']);
      $this->assertRegExp(';^b\.[\d\.a-f]+@chaos.org$;', $message->headers['Return-Path']);
      $this->assertRegExp(';^b\.[\d\.a-f]+@chaos.org$;', $message->headers['X-CiviMail-Bounce'][0]);
      $this->assertRegExp(';^\<mailto:u\.[\d\.a-f]+@chaos.org\>$;', $message->headers['List-Unsubscribe'][0]);
      $this->assertEquals('bulk', $message->headers['Precedence'][0]);
    }
  }

  /**
   * Generate a fully-formatted mailing (with body_text content).
   */
  public function testText() {
    $allMessages = $this->runMailingSuccess([
      'subject' => 'Accidents in cars cause children for {contact.display_name}!',
      'body_text' => 'BEWARE children need regular infusions of toys. Santa knows your {domain.address}. There is no {action.optOutUrl}.',
      'open_tracking' => 1,
      // Note: open_tracking does nothing with text, but we'll just verify that it does nothing
    ]);
    foreach ($allMessages as $message) {
      /** @var ezcMail $message */
      /** @var ezcMailText $textPart */

      $this->assertTrue($message->body instanceof ezcMailText);

      $this->assertEquals('plain', $message->body->subType);
      $this->assertRegExp(
        ";" .
        // Default header
        "Sample Header for TEXT formatted content.\n" .
        "BEWARE children need regular infusions of toys. Santa knows your .*\\. There is no http.*civicrm/mailing/optout.*\\.\n" .
        // Default footer
        "to unsubscribe: http.*civicrm/mailing/optout" .
        ";",
        $message->body->text
      );
    }
  }

  /**
   * Generate a fully-formatted mailing (with body_html content).
   */
  public function testHtmlWithOpenTracking() {
    $allMessages = $this->runMailingSuccess([
      'subject' => 'Example Subject',
      'body_html' => '<p>You can go to <a href="http://example.net/first?{contact.checksum}">Google</a> or <a href="{action.optOutUrl}">opt out</a>.</p>',
      'open_tracking' => 1,
      'url_tracking' => 0,
    ]);
    foreach ($allMessages as $message) {
      /** @var ezcMail $message */
      /** @var ezcMailText $htmlPart */
      /** @var ezcMailText $textPart */

      $this->assertTrue($message->body instanceof ezcMailMultipartAlternative);

      [$textPart, $htmlPart] = $message->body->getParts();

      $this->assertEquals('html', $htmlPart->subType);
      $this->assertRegExp(
        ";" .
        // Default header
        "Sample Header for HTML formatted content.\n" .
        // FIXME: CiviMail puts double " after hyperlink!
        // body_html
        "<p>You can go to <a href=\"http://example.net/first\\?cs=[0-9a-f_]+\"\"?>Google</a> or <a href=\"http.*civicrm/mailing/optout.*\">opt out</a>.</p>\n" .
        // Default footer
        "Sample Footer for HTML formatted content" .
        ".*\n" .
        "<img src=\".*(extern/open.php|civicrm/mailing/open).*\"" .
        ";",
        $htmlPart->text
      );

      $this->assertEquals('plain', $textPart->subType);
      $this->assertRegExp(
        ";" .
        // Default header
        "Sample Header for TEXT formatted content.\n" .
        //  body_html, filtered
        "You can go to Google \\[1\\] or opt out \\[2\\]\\.\n" .
        "\n" .
        "\n" .
        "Links:\n" .
        "------\n" .
        "\\[1\\] http://example.net/first\\?cs=[0-9a-f_]+\n" .
        "\\[2\\] http.*civicrm/mailing/optout.*\n" .
        "\n" .
        // Default footer
        "to unsubscribe: http.*civicrm/mailing/optout" .
        ";",
        $textPart->text
      );
    }
  }

  /**
   * Generate a fully-formatted mailing (with body_html content).
   */
  public function testHtmlWithOpenAndUrlTracking() {
    $allMessages = $this->runMailingSuccess([
      'subject' => 'Example Subject',
      'body_html' => '<p>You can go to <a href="http://example.net">Google</a> or <a href="{action.optOutUrl}">opt out</a>.</p>',
      'open_tracking' => 1,
      'url_tracking' => 1,
    ]);
    foreach ($allMessages as $message) {
      /** @var ezcMail $message */
      /** @var ezcMailText $htmlPart */
      /** @var ezcMailText $textPart */

      $this->assertTrue($message->body instanceof ezcMailMultipartAlternative);

      [$textPart, $htmlPart] = $message->body->getParts();

      $this->assertEquals('html', $htmlPart->subType);
      $this->assertRegExp(
        ";" .
        // body_html
        "<p>You can go to <a href=['\"].*(extern/url.php|civicrm/mailing/url)(\?|&amp\\;)u=\d+&amp\\;qid=\d+['\"] rel='nofollow'>Google</a>" .
        " or <a href=\"http.*civicrm/mailing/optout.*\">opt out</a>.</p>\n" .
        // Default footer
        "Sample Footer for HTML formatted content" .
        ".*\n" .
        // Open-tracking code
        "<img src=\".*(extern/open.php|civicrm/mailing/open).*\"" .
        ";",
        $htmlPart->text
      );

      $this->assertEquals('plain', $textPart->subType);
      $this->assertRegExp(
        ";" .
        //  body_html, filtered
        "You can go to Google \\[1\\] or opt out \\[2\\]\\.\n" .
        "\n" .
        "\n" .
        "Links:\n" .
        "------\n" .
        "\\[1\\] http.*(extern/url.php|civicrm/mailing/url)(\?|&)u=\d+&qid=\d+\n" .
        "\\[2\\] http.*civicrm/mailing/optout.*\n" .
        "\n" .
        // Default footer
        "to unsubscribe: http.*civicrm/mailing/optout" .
        ";",
        $textPart->text
      );
    }
  }

  /**
   * Each case comes in four parts:
   * 1. Mailing HTML (body_html)
   * 2. Regex to run against final HTML
   * 3. Regex to run against final text
   * 4. Additional mailing options
   *
   * @return array
   */
  public function urlTrackingExamples() {
    $cases = [];

    // Tracking disabled
    $cases[0] = [
      '<p><a href="http://example.net/">Foo</a></p>',
      ';<p><a href="http://example\.net/">Foo</a></p>;',
      ';\\[1\\] http://example\.net/;',
      ['url_tracking' => 0],
    ];
    $cases[1] = [
      '<p><a href="http://example.net/?id={contact.contact_id}">Foo</a></p>',
      // FIXME: Legacy tracker adds extra quote after URL
      ';<p><a href="http://example\.net/\?id=\d+""?>Foo</a></p>;',
      ';\\[1\\] http://example\.net/\?id=\d+;',
      ['url_tracking' => 0],
    ];
    $cases[2] = [
      '<p><a href="{action.optOutUrl}">Foo</a></p>',
      ';<p><a href="http.*civicrm/mailing/optout.*">Foo</a></p>;',
      ';\\[1\\] http.*civicrm/mailing/optout.*;',
      ['url_tracking' => 0],
    ];
    $cases[3] = [
      '<p>Look at <img src="http://example.net/foo.png">.</p>',
      ';<p>Look at <img src="http://example\.net/foo\.png">\.</p>;',
      ';Look at \.;',
      ['url_tracking' => 0],
    ];
    $cases[4] = [
      // Plain-text URL's are tracked in plain-text emails...
      // but not in HTML emails.
      "<p>Please go to: http://example.net/</p>",
      ";<p>Please go to: http://example\.net/</p>;",
      ';Please go to: http://example\.net/;',
      ['url_tracking' => 0],
    ];

    // Tracking enabled
    $cases[5] = [
      '<p><a href="http://example.net/">Foo</a></p>',
      ';<p><a href=[\'"].*(extern/url.php|civicrm/mailing/url)(\?|&amp\\;)u=\d+.*[\'"]>Foo</a></p>;',
      ';\\[1\\] .*(extern/url.php|civicrm/mailing/url)[\?&]u=\d+.*;',
      ['url_tracking' => 1],
    ];
    $cases[6] = [
      // FIXME: CiviMail URL tracking doesn't track tokenized links.
      '<p><a href="http://example.net/?id={contact.contact_id}">Foo</a></p>',
      // FIXME: Legacy tracker adds extra quote after URL
      ';<p><a href="http://example\.net/\?id=\d+""?>Foo</a></p>;',
      ';\\[1\\] http://example\.net/\?id=\d+;',
      ['url_tracking' => 1],
    ];
    $cases[7] = [
      // It would be redundant/slow to track the action URLs?
      '<p><a href="{action.optOutUrl}">Foo</a></p>',
      ';<p><a href="http.*civicrm/mailing/optout.*">Foo</a></p>;',
      ';\\[1\\] http.*civicrm/mailing/optout.*;',
      ['url_tracking' => 1],
    ];
    $cases[8] = [
      // It would be excessive/slow to track every embedded image.
      '<p>Look at <img src="http://example.net/foo.png">.</p>',
      ';<p>Look at <img src="http://example\.net/foo\.png">\.</p>;',
      ';Look at \.;',
      ['url_tracking' => 1],
    ];
    $cases[9] = [
      // Plain-text URL's are tracked in plain-text emails...
      // but not in HTML emails.
      "<p>Please go to: http://example.net/</p>",
      ";<p>Please go to: http://example\.net/</p>;",
      ';Please go to: .*(extern/url.php|civicrm/mailing/url)[\?&]u=\d+&qid=\d+;',
      ['url_tracking' => 1],
    ];

    return $cases;
  }

  /**
   * Generate a fully-formatted mailing (with body_html content).
   *
   * @dataProvider urlTrackingExamples
   * @throws \CRM_Core_Exception
   */
  public function testUrlTracking($inputHtml, $htmlUrlRegex, $textUrlRegex, $params) {

    $allMessages = $this->runMailingSuccess($params + [
      'subject' => 'Example Subject',
      'body_html' => $inputHtml,
    ]);
    foreach ($allMessages as $message) {
      /** @var ezcMail $message */
      /** @var ezcMailText $htmlPart */
      /** @var ezcMailText $textPart */

      $this->assertTrue($message->body instanceof ezcMailMultipartAlternative);

      [$textPart, $htmlPart] = $message->body->getParts();

      if ($htmlUrlRegex) {
        $caseName = print_r(['inputHtml' => $inputHtml, 'params' => $params, 'htmlUrlRegex' => $htmlUrlRegex, 'htmlPart' => $htmlPart->text], 1);
        $this->assertEquals('html', $htmlPart->subType, "Should have HTML part in case: $caseName");
        $this->assertRegExp($htmlUrlRegex, $htmlPart->text, "Should have correct HTML in case: $caseName");
      }

      if ($textUrlRegex) {
        $caseName = print_r(['inputHtml' => $inputHtml, 'params' => $params, 'textUrlRegex' => $textUrlRegex, 'textPart' => $textPart->text], 1);
        $this->assertEquals('plain', $textPart->subType, "Should have text part in case: $caseName");
        $this->assertRegExp($textUrlRegex, $textPart->text, "Should have correct text in case: $caseName");
      }
    }
  }

  /**
   * Create contacts in group.
   *
   * @param int $count
   * @param int $groupID
   * @param string $domain
   */
  protected function createContactsInGroup(
    $count,
    $groupID,
    $domain = 'nul.example.com'
  ) {
    for ($i = 1; $i <= $count; $i++) {
      $contactID = $this->individualCreate([
        'first_name' => "Foo{$i}",
        'email' => 'mail' . $i . '@' . $domain,
      ]);
      $this->callAPISuccess('group_contact', 'create', [
        'contact_id' => $contactID,
        'group_id' => $groupID,
        'status' => 'Added',
      ]);
    }
  }

  /**
   * Create and execute a mailing. Return the matching messages.
   *
   * @param array $params
   *   List of parameters to send to Mailing.create API.
   *
   * @return array<ezcMail>
   * @throws \CRM_Core_Exception
   */
  protected function runMailingSuccess($params) {
    $mailingParams = array_merge($this->defaultParams, $params);
    $this->callAPISuccess('mailing', 'create', $mailingParams);
    $this->_mut->assertRecipients([]);
    $this->callAPISuccess('job', 'process_mailing', ['runInNonProductionEnvironment' => TRUE]);

    $allMessages = $this->_mut->getAllMessages('ezc');
    // There are exactly two contacts produced by setUp().
    $this->assertEquals(2, count($allMessages));

    return $allMessages;
  }

}
