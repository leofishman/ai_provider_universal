<?php

namespace Drupal\ai_provider_universal_factcheck\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Dispatched when factcheck wants to notify operators about an admin event.
 *
 * Fired for every notification attempt (scan run, settings change, …), even
 * when no notify_email is configured. Subscribers (ECA, Message Notify,
 * custom Slack listeners, …) can react without hard-depending on mail.
 *
 * After subscribers run, AdminNotifier may also send the default email if
 * notify_email is set and mail was not suppressed.
 */
class FactcheckNotificationEvent extends Event {

  /**
   * Event name for the dispatcher and ECA "Drupal core: event" plugin.
   */
  const EVENT_NAME = 'ai_provider_universal_factcheck.notification';

  /**
   * A content scan batch was started.
   */
  const KEY_SCAN_RUN = 'scan_run';

  /**
   * Fact-check settings form was saved.
   */
  const KEY_SETTINGS_CHANGED = 'settings_changed';

  /**
   * Whether AdminNotifier should skip the default mail after dispatch.
   */
  protected bool $mailSuppressed = FALSE;

  /**
   * Constructs the event.
   *
   * @param string $key
   *   Stable machine key (see KEY_* constants).
   * @param string $subject
   *   Short human title (also used as mail subject).
   * @param string $body
   *   Plain-text body.
   * @param array $context
   *   Optional structured context for subscribers (e.g. uid, subject label).
   */
  public function __construct(
    protected readonly string $key,
    protected readonly string $subject,
    protected readonly string $body,
    protected readonly array $context = [],
  ) {}

  /**
   * Gets the notification key.
   */
  public function getKey(): string {
    return $this->key;
  }

  /**
   * Gets the subject / title line.
   */
  public function getSubject(): string {
    return $this->subject;
  }

  /**
   * Gets the plain-text body.
   */
  public function getBody(): string {
    return $this->body;
  }

  /**
   * Gets optional structured context for subscribers.
   *
   * @return array<string, mixed>
   *   Context map (may be empty).
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Suppresses AdminNotifier's default email for this notification.
   *
   * Use when a subscriber fully owns delivery (ECA → Slack only, Message
   * Notify, etc.) and the site does not want a second mail.
   */
  public function suppressMail(): void {
    $this->mailSuppressed = TRUE;
  }

  /**
   * Whether the default mail channel should be skipped.
   */
  public function isMailSuppressed(): bool {
    return $this->mailSuppressed;
  }

}
