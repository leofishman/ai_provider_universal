<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends optional admin alert emails for fact-check events.
 *
 * Uses Drupal core's mail system (plugin.manager.mail + hook_mail). Sites can
 * route delivery through any Mail plugin (PHP mail, SMTP, Symfony Mailer,
 * …). Empty notify_email disables sending. Failures are logged and never
 * break the calling form/batch flow.
 */
class AdminNotifier {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Sends a notification if a recipient is configured.
   *
   * @param string $key
   *   Mail key (scan_run, settings_changed, …); used by hook_mail and themes.
   * @param string $subject
   *   Email subject line.
   * @param string $body
   *   Plain-text body.
   */
  public function notify(string $key, string $subject, string $body): void {
    $to = (string) ($this->configFactory->get('ai_provider_universal_factcheck.settings')->get('notify_email') ?? '');
    if ($to === '') {
      return;
    }

    try {
      $result = $this->mailManager->mail(
        'ai_provider_universal_factcheck',
        $key,
        $to,
        $this->languageManager->getDefaultLanguage()->getId(),
        [
          'subject' => $subject,
          'body' => $body,
        ],
      );
      // mail() returns ['result' => FALSE] when the plugin refused delivery.
      if (isset($result['result']) && $result['result'] === FALSE) {
        $this->logger->warning('Notification email was not accepted for delivery (key=@key).', [
          '@key' => $key,
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not send notification email: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
