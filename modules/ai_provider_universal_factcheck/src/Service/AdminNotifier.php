<?php

namespace Drupal\ai_provider_universal_factcheck\Service;

use Drupal\ai_provider_universal_factcheck\Event\FactcheckNotificationEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Notifies operators about fact-check admin events.
 *
 * Order of operations:
 * 1. Dispatch FactcheckNotificationEvent (always) so ECA / custom modules /
 *    Message Notify can react without depending on mail.
 * 2. Unless a subscriber called suppressMail(), optionally send the default
 *    admin email via Drupal's mail system when notify_email is configured.
 *
 * Failures are logged and never break the calling form/batch flow.
 */
class AdminNotifier {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected LoggerInterface $logger,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Notifies about an admin event (event bus + optional default mail).
   *
   * @param string $key
   *   Stable key (FactcheckNotificationEvent::KEY_*).
   * @param string $subject
   *   Short human title / mail subject.
   * @param string $body
   *   Plain-text body.
   * @param array $context
   *   Optional structured context for event subscribers.
   */
  public function notify(string $key, string $subject, string $body, array $context = []): void {
    $event = new FactcheckNotificationEvent($key, $subject, $body, $context);
    $this->eventDispatcher->dispatch($event, FactcheckNotificationEvent::EVENT_NAME);

    if ($event->isMailSuppressed()) {
      return;
    }

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
