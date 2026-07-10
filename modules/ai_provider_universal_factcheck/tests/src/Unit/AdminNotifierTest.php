<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Event\FactcheckNotificationEvent;
use Drupal\ai_provider_universal_factcheck\Service\AdminNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests AdminNotifier event dispatch, mail gating and error handling.
 */
#[CoversClass(AdminNotifier::class)]
#[Group('ai_provider_universal')]
final class AdminNotifierTest extends UnitTestCase {

  /**
   * Builds a notifier with the given notify_email and optional doubles.
   */
  private function buildNotifier(
    string $notifyEmail,
    ?MailManagerInterface $mail = NULL,
    ?LoggerInterface $logger = NULL,
    ?EventDispatcherInterface $dispatcher = NULL,
  ): AdminNotifier {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'notify_email' ? $notifyEmail : NULL,
    );
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getDefaultLanguage')->willReturn($language);

    if ($dispatcher === NULL) {
      $dispatcher = $this->createMock(EventDispatcherInterface::class);
      $dispatcher->method('dispatch')->willReturnArgument(0);
    }

    return new AdminNotifier(
      $factory,
      $mail ?? $this->createMock(MailManagerInterface::class),
      $languages,
      $logger ?? $this->createMock(LoggerInterface::class),
      $dispatcher,
    );
  }

  /**
   * Event is always dispatched; empty notify_email skips MailManager.
   */
  public function testEventDispatchedWithoutMailWhenNoRecipient(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())
      ->method('dispatch')
      ->with(
        $this->callback(static function (FactcheckNotificationEvent $event): bool {
          return $event->getKey() === FactcheckNotificationEvent::KEY_SCAN_RUN
            && $event->getSubject() === 'Subj'
            && $event->getBody() === 'Body'
            && $event->getContext() === ['uid' => 2];
        }),
        FactcheckNotificationEvent::EVENT_NAME,
      )
      ->willReturnArgument(0);

    $this->buildNotifier('', $mail, NULL, $dispatcher)
      ->notify(FactcheckNotificationEvent::KEY_SCAN_RUN, 'Subj', 'Body', ['uid' => 2]);
  }

  /**
   * Configured recipient triggers mail after the event.
   */
  public function testSendsViaMailManagerAfterEvent(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->with(
        'ai_provider_universal_factcheck',
        FactcheckNotificationEvent::KEY_SCAN_RUN,
        'ops@example.com',
        'en',
        ['subject' => 'Hello', 'body' => 'World'],
      )
      ->willReturn(['result' => TRUE]);

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())->method('dispatch')->willReturnArgument(0);

    $this->buildNotifier('ops@example.com', $mail, NULL, $dispatcher)
      ->notify(FactcheckNotificationEvent::KEY_SCAN_RUN, 'Hello', 'World');
  }

  /**
   * Subscribers can suppress the default mail channel.
   */
  public function testSuppressMailSkipsMailManager(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');

    $dispatcher = $this->createMock(EventDispatcherInterface::class);
    $dispatcher->expects($this->once())
      ->method('dispatch')
      ->willReturnCallback(static function (FactcheckNotificationEvent $event) {
        $event->suppressMail();
        return $event;
      });

    $this->buildNotifier('ops@example.com', $mail, NULL, $dispatcher)
      ->notify(FactcheckNotificationEvent::KEY_SETTINGS_CHANGED, 'S', 'B');
  }

  /**
   * Plugin rejection is logged, not thrown.
   */
  public function testFailedDeliveryIsLogged(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturn(['result' => FALSE]);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $this->buildNotifier('ops@example.com', $mail, $logger)
      ->notify(FactcheckNotificationEvent::KEY_SETTINGS_CHANGED, 'S', 'B');
  }

  /**
   * Exceptions from MailManager are swallowed and logged.
   */
  public function testExceptionIsLogged(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willThrowException(new \RuntimeException('smtp down'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        $this->stringContains('Could not send'),
        $this->callback(static fn (array $ctx): bool => ($ctx['@message'] ?? '') === 'smtp down'),
      );

    $this->buildNotifier('ops@example.com', $mail, $logger)
      ->notify(FactcheckNotificationEvent::KEY_SCAN_RUN, 'S', 'B');
  }

}
