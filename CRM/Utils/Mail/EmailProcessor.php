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
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

// we should consider moving these to the settings table
// before the 4.1 release
define('EMAIL_ACTIVITY_TYPE_ID', NULL);
define('MAIL_BATCH_SIZE', 50);

/**
 * Class CRM_Utils_Mail_EmailProcessor.
 */
class CRM_Utils_Mail_EmailProcessor {

  const MIME_MAX_RECURSION = 10;

  /**
   * Process the default mailbox (ie. that is used by civiMail for the bounce)
   *
   * @param bool $is_create_activities
   *   Should activities be created
   */
  public static function processBounces($is_create_activities) {
    $dao = new CRM_Core_DAO_MailSettings();
    $dao->domain_id = CRM_Core_Config::domainID();
    $dao->is_default = TRUE;
    $dao->find();

    while ($dao->fetch()) {
      self::_process(TRUE, $dao, $is_create_activities);
    }
  }

  /**
   * Process the mailboxes that aren't default (ie. that aren't used by civiMail for the bounce).
   *
   * @return bool
   *
   * @throws CRM_Core_Exception.
   */
  public static function processActivities() {
    $dao = new CRM_Core_DAO_MailSettings();
    $dao->domain_id = CRM_Core_Config::domainID();
    $dao->is_default = FALSE;
    $dao->find();
    $found = FALSE;
    while ($dao->fetch()) {
      $found = TRUE;
      self::_process(FALSE, $dao, TRUE);
    }
    if (!$found) {
      throw new CRM_Core_Exception(ts('No mailboxes have been configured for Email to Activity Processing'));
    }
    return $found;
  }

  /**
   * Process the mailbox for all the settings from civicrm_mail_settings.
   *
   * @param bool|string $civiMail if true, processing is done in CiviMail context, or Activities otherwise.
   */
  public static function process($civiMail = TRUE) {
    $dao = new CRM_Core_DAO_MailSettings();
    $dao->domain_id = CRM_Core_Config::domainID();
    $dao->find();

    while ($dao->fetch()) {
      self::_process($civiMail, $dao);
    }
  }

  /**
   * @param $civiMail
   * @param CRM_Core_DAO_MailSettings $dao
   * @param bool $is_create_activities
   *   Create activities.
   *
   * @throws Exception
   * @throws CRM_Core_Exception
   */
  public static function _process($civiMail, $dao, $is_create_activities) {
    // 0 = activities; 1 = bounce;
    $usedfor = $dao->is_default;

    $emailActivityTypeId
      = (defined('EMAIL_ACTIVITY_TYPE_ID') && EMAIL_ACTIVITY_TYPE_ID)
      ? EMAIL_ACTIVITY_TYPE_ID
      : CRM_Core_PseudoConstant::getKey('CRM_Activity_BAO_Activity', 'activity_type_id', 'Inbound Email');

    if (!$emailActivityTypeId) {
      throw new CRM_Core_Exception(ts('Could not find a valid Activity Type ID for Inbound Email'));
    }

    $config = CRM_Core_Config::singleton();
    $verpSeparator = preg_quote($config->verpSeparator ?? '');
    $twoDigitStringMin = $verpSeparator . '(\d+)' . $verpSeparator . '(\d+)';
    $twoDigitString = $twoDigitStringMin . $verpSeparator;
    $threeDigitString = $twoDigitString . '(\d+)' . $verpSeparator;

    // FIXME: legacy regexen to handle CiviCRM 2.1 address patterns, with domain id and possible VERP part
    $commonRegex = '/^' . preg_quote($dao->localpart ?? '') . '(b|bounce|c|confirm|o|optOut|r|reply|re|e|resubscribe|u|unsubscribe)' . $threeDigitString . '([0-9a-f]{16})(-.*)?@' . preg_quote($dao->domain ?? '') . '$/';
    $subscrRegex = '/^' . preg_quote($dao->localpart ?? '') . '(s|subscribe)' . $twoDigitStringMin . '@' . preg_quote($dao->domain ?? '') . '$/';

    // a common-for-all-actions regex to handle CiviCRM 2.2 address patterns
    $regex = '/^' . preg_quote($dao->localpart ?? '') . '(b|c|e|o|r|u)' . $twoDigitString . '([0-9a-f]{16})@' . preg_quote($dao->domain ?? '') . '$/';

    // a tighter regex for finding bounce info in soft bounces’ mail bodies
    $rpRegex = '/Return-Path:\s*' . preg_quote($dao->localpart ?? '') . '(b)' . $twoDigitString . '([0-9a-f]{16})@' . preg_quote($dao->domain ?? '') . '/';

    // a regex for finding bound info X-Header
    $rpXheaderRegex = '/X-CiviMail-Bounce: ' . preg_quote($dao->localpart ?? '') . '(b)' . $twoDigitString . '([0-9a-f]{16})@' . preg_quote($dao->domain ?? '') . '/i';
    // CiviMail in regex and Civimail in header !!!

    // retrieve the emails
    try {
      $store = CRM_Mailing_MailStore::getStore($dao->name);
    }
    catch (Exception $e) {
      $message = ts('Could not connect to MailStore for ') . $dao->username . '@' . $dao->server . '<p>';
      $message .= ts('Error message: ');
      $message .= '<pre>' . $e->getMessage() . '</pre><p>';
      throw new CRM_Core_Exception($message);
    }

    // process fifty at a time, CRM-4002
    while ($mails = $store->fetchNext(MAIL_BATCH_SIZE)) {
      foreach ($mails as $key => $mail) {

        // for every addressee: match address elements if it's to CiviMail
        $matches = [];
        $action = NULL;

        if ($usedfor == 1) {
          foreach ($mail->to as $address) {
            if (preg_match($regex, ($address->email ?? ''), $matches)) {
              [$match, $action, $job, $queue, $hash] = $matches;
              break;
              // FIXME: the below elseifs should be dropped when we drop legacy support
            }
            elseif (preg_match($commonRegex, ($address->email ?? ''), $matches)) {
              [$match, $action, $_, $job, $queue, $hash] = $matches;
              break;
            }
            elseif (preg_match($subscrRegex, ($address->email ?? ''), $matches)) {
              [$match, $action, $_, $job] = $matches;
              break;
            }
          }

          // CRM-5471: if $matches is empty, it still might be a soft bounce sent
          // to another address, so scan the body for ‘Return-Path: …bounce-pattern…’
          if (!$matches and preg_match($rpRegex, ($mail->generateBody() ?? ''), $matches)) {
            [$match, $action, $job, $queue, $hash] = $matches;
          }

          // if $matches is still empty, look for the X-CiviMail-Bounce header
          // CRM-9855
          if (!$matches and preg_match($rpXheaderRegex, ($mail->generateBody() ?? ''), $matches)) {
            [$match, $action, $job, $queue, $hash] = $matches;
          }
          // With Mandrilla, the X-CiviMail-Bounce header is produced by generateBody
          // is base64 encoded
          // Check all parts
          if (!$matches) {
            $all_parts = $mail->fetchParts();
            foreach ($all_parts as $k_part => $v_part) {
              if ($v_part instanceof ezcMailFile) {
                $p_file = $v_part->__get('fileName');
                $c_file = file_get_contents($p_file);
                if (preg_match($rpXheaderRegex, ($c_file ?? ''), $matches)) {
                  [$match, $action, $job, $queue, $hash] = $matches;
                }
              }
            }
          }

          // if all else fails, check Delivered-To for possible pattern
          if (!$matches and preg_match($regex, ($mail->getHeader('Delivered-To') ?? ''), $matches)) {
            [$match, $action, $job, $queue, $hash] = $matches;
          }
        }

        // preseve backward compatibility
        if ($usedfor == 0 || $is_create_activities) {
          // Mail account may have 'Skip emails which do not have a Case ID
          // or Case hash' option, if its enabled and email is not related
          // to cases - then we need to put email to ignored folder.
          $caseMailUtils = new CRM_Utils_Mail_CaseMail();
          if (!empty($dao->is_non_case_email_skipped) && !$caseMailUtils->isCaseEmail($mail->subject)) {
            $store->markIgnored($key);
            continue;
          }

          // if its the activities that needs to be processed ..
          try {
            $createContact = !($dao->is_contact_creation_disabled_if_no_match ?? FALSE);
            $mailParams = CRM_Utils_Mail_Incoming::parseMailingObject($mail, $createContact, FALSE);
          }
          catch (Exception $e) {
            echo $e->getMessage();
            $store->markIgnored($key);
            continue;
          }

          $params = self::deprecated_activity_buildmailparams($mailParams, $emailActivityTypeId);

          $params['version'] = 3;
          if (!empty($dao->activity_status)) {
            $params['status_id'] = $dao->activity_status;
          }

          $result = civicrm_api('activity', 'create', $params);

          if ($result['is_error']) {
            $matches = FALSE;
            echo "Failed Processing: {$mail->subject}. Reason: {$result['error_message']}\n";
          }
          else {
            $matches = TRUE;
            CRM_Utils_Hook::emailProcessor('activity', $params, $mail, $result);
            echo "Processed as Activity: {$mail->subject}\n";
          }
        }

        // if $matches is empty, this email is not CiviMail-bound
        if (!$matches) {
          $store->markIgnored($key);
          continue;
        }

        // get $replyTo from either the Reply-To header or from From
        // FIXME: make sure it works with Reply-Tos containing non-email stuff
        $replyTo = $mail->getHeader('Reply-To') ? $mail->getHeader('Reply-To') : $mail->from->email;

        // handle the action by passing it to the proper API call
        // FIXME: leave only one-letter cases when dropping legacy support
        if (!empty($action)) {
          $result = NULL;

          switch ($action) {
            case 'b':
            case 'bounce':
              $text = '';
              if ($mail->body instanceof ezcMailText) {
                $text = $mail->body->text;
              }
              elseif ($mail->body instanceof ezcMailMultipart) {
                $text = self::getTextFromMultipart($mail->body);
              }
              elseif ($mail->body instanceof ezcMailFile) {
                $text = file_get_contents($mail->body->__get('fileName'));
              }

              if (
                empty($text) &&
                $mail->subject == "Delivery Status Notification (Failure)"
              ) {
                // Exchange error - CRM-9361
                foreach ($mail->body->getParts() as $part) {
                  if ($part instanceof ezcMailDeliveryStatus) {
                    foreach ($part->recipients as $rec) {
                      if ($rec["Status"] == "5.1.1") {
                        if (isset($rec["Description"])) {
                          $text = $rec["Description"];
                        }
                        else {
                          $text = $rec["Status"] . " Delivery to the following recipients failed";
                        }
                        break;
                      }
                    }
                  }
                }
              }

              if (empty($text)) {
                // If bounce processing fails, just take the raw body. Cf. CRM-11046
                $text = $mail->generateBody();

                // if text is still empty, lets fudge a blank text so the api call below will succeed
                if (empty($text)) {
                  $text = ts('We could not extract the mail body from this bounce message.');
                }
              }

              $params = [
                'job_id' => $job,
                'event_queue_id' => $queue,
                'hash' => $hash,
                'body' => $text,
                'version' => 3,
                // Setting is_transactional means it will rollback if
                // it crashes part way through creating the bounce.
                // If the api were standard & had a create this would be the
                // default. Adding the standard api & deprecating this one
                // would probably be the
                // most consistent way to address this - but this is
                // a quick hack.
                'is_transactional' => 1,
              ];
              $result = civicrm_api('Mailing', 'event_bounce', $params);
              break;

            case 'c':
            case 'confirm':
              // CRM-7921
              $params = [
                'contact_id' => $job,
                'subscribe_id' => $queue,
                'hash' => $hash,
                'version' => 3,
              ];
              $result = civicrm_api('Mailing', 'event_confirm', $params);
              break;

            case 'o':
            case 'optOut':
              $params = [
                'job_id' => $job,
                'event_queue_id' => $queue,
                'hash' => $hash,
                'version' => 3,
              ];
              $result = civicrm_api('MailingGroup', 'event_domain_unsubscribe', $params);
              break;

            case 'r':
            case 'reply':
              // instead of text and HTML parts (4th and 6th params) send the whole email as the last param
              $params = [
                'job_id' => $job,
                'event_queue_id' => $queue,
                'hash' => $hash,
                'bodyTxt' => NULL,
                'replyTo' => $replyTo,
                'bodyHTML' => NULL,
                'fullEmail' => $mail->generate(),
                'version' => 3,
              ];
              $result = civicrm_api('Mailing', 'event_reply', $params);
              break;

            case 'e':
            case 're':
            case 'resubscribe':
              $params = [
                'job_id' => $job,
                'event_queue_id' => $queue,
                'hash' => $hash,
                'version' => 3,
              ];
              $result = civicrm_api('MailingGroup', 'event_resubscribe', $params);
              break;

            case 's':
            case 'subscribe':
              $params = [
                'email' => $mail->from->email,
                'group_id' => $job,
                'version' => 3,
              ];
              $result = civicrm_api('MailingGroup', 'event_subscribe', $params);
              break;

            case 'u':
            case 'unsubscribe':
              $params = [
                'job_id' => $job,
                'event_queue_id' => $queue,
                'hash' => $hash,
                'version' => 3,
              ];
              $result = civicrm_api('MailingGroup', 'event_unsubscribe', $params);
              break;
          }

          if ($result['is_error']) {
            echo "Failed Processing: {$mail->subject}, Action: $action, Job ID: $job, Queue ID: $queue, Hash: $hash. Reason: {$result['error_message']}\n";
          }
          else {
            CRM_Utils_Hook::emailProcessor('mailing', $params, $mail, $result, $action);
          }
        }

        $store->markProcessed($key);
      }
      // CRM-7356 – used by IMAP only
      $store->expunge();
    }
  }

  /**
   * @param \ezcMailMultipart $multipart
   * @param int $recursionLevel
   *
   * @return array
   */
  protected static function getTextFromMultipart($multipart, $recursionLevel = 0) {
    if ($recursionLevel >= self::MIME_MAX_RECURSION) {
      return NULL;
    }
    $recursionLevel += 1;
    $text = NULL;
    if ($multipart instanceof ezcMailMultipartReport) {
      $text = self::getTextFromMulipartReport($multipart, $recursionLevel);
    }
    elseif ($multipart instanceof ezcMailMultipartRelated) {
      $text = self::getTextFromMultipartRelated($multipart, $recursionLevel);
    }
    else {
      foreach ($multipart->getParts() as $part) {
        if (isset($part->subType) and $part->subType === 'plain') {
          $text = $part->text;
        }
        elseif ($part instanceof ezcMailMultipart) {
          $text = self::getTextFromMultipart($part, $recursionLevel);
        }
        if ($text) {
          break;
        }
      }
    }
    return $text;
  }

  /**
   * @param \ezcMailMultipartRelated $related
   * @param int $recursionLevel
   *
   * @return array
   */
  protected static function getTextFromMultipartRelated($related, $recursionLevel) {
    $text = NULL;
    foreach ($related->getRelatedParts() as $part) {
      if (isset($part->subType) and $part->subType === 'plain') {
        $text = $part->text;
      }
      elseif ($part instanceof ezcMailMultipart) {
        $text = self::getTextFromMultipart($part, $recursionLevel);
      }
      if ($text) {
        break;
      }
    }
    return $text;
  }

  /**
   * @param \ezcMailMultipartReport $multipart
   * @param $recursionLevel
   *
   * @return array
   */
  protected static function getTextFromMulipartReport($multipart, $recursionLevel) {
    $text = NULL;
    $part = $multipart->getMachinePart();
    if ($part instanceof ezcMailDeliveryStatus) {
      foreach ($part->recipients as $rec) {
        if (isset($rec["Diagnostic-Code"])) {
          $text = $rec["Diagnostic-Code"];
          break;
        }
        elseif (isset($rec["Description"])) {
          $text = $rec["Description"];
          break;
        }
        // no diagnostic info present - try getting the human readable part
        elseif (isset($rec["Status"])) {
          $text = $rec["Status"];
          $textpart = $multipart->getReadablePart();
          if ($textpart !== NULL and isset($textpart->text)) {
            $text .= " " . $textpart->text;
          }
          else {
            $text .= " Delivery failed but no diagnostic code or description.";
          }
          break;
        }
      }
    }
    elseif ($part !== NULL and isset($part->text)) {
      $text = $part->text;
    }
    elseif (($part = $multipart->getReadablePart()) !== NULL) {
      if (isset($part->text)) {
        $text = $part->text;
      }
      elseif ($part instanceof ezcMailMultipart) {
        $text = self::getTextFromMultipart($part, $recursionLevel);
      }
    }
    return $text;
  }

  /**
   * @param array $result
   * @param int $activityTypeID
   *
   * @return array
   *   <type> $params
   */
  protected static function deprecated_activity_buildmailparams($result, $activityTypeID) {
    // get ready for collecting data about activity to be created
    $params = [];

    $params['activity_type_id'] = $activityTypeID;

    $params['status_id'] = 'Completed';
    if (!empty($result['from']['id'])) {
      $params['source_contact_id'] = $params['assignee_contact_id'] = $result['from']['id'];
    }
    $params['target_contact_id'] = [];
    $keys = ['to', 'cc', 'bcc'];
    foreach ($keys as $key) {
      if (is_array($result[$key])) {
        foreach ($result[$key] as $key => $keyValue) {
          if (!empty($keyValue['id'])) {
            $params['target_contact_id'][] = $keyValue['id'];
          }
        }
      }
    }
    $params['subject'] = $result['subject'];
    $params['activity_date_time'] = $result['date'];
    $params['details'] = $result['body'];

    $numAttachments = Civi::settings()->get('max_attachments_backend') ?? CRM_Core_BAO_File::DEFAULT_MAX_ATTACHMENTS_BACKEND;
    for ($i = 1; $i <= $numAttachments; $i++) {
      if (isset($result["attachFile_$i"])) {
        $params["attachFile_$i"] = $result["attachFile_$i"];
      }
      else {
        // No point looping 100 times if there's only one attachment
        break;
      }
    }

    return $params;
  }

}
