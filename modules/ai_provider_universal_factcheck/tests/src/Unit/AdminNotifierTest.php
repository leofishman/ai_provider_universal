<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_factcheck\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_provider_universal_factcheck\Service\AdminNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Tests AdminNotifier mail gating and error handling.
 */
#[CoversClass(AdminNotifier::class)]
#[Group('ai_provider_universal')]
final class AdminNotifierTest extends UnitTestCase {

  /**
   * Builds a notifier with the given notify_email config and mail result.
   */
  private function buildNotifier(
    string $notifyEmail,
    ?MailManagerInterface $mail = NULL,
    ?LoggerInterface $logger = NULL,
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

    return new AdminNotifier(
      $factory,
      $mail ?? $this->createMock(MailManagerInterface::class),
      $languages,
      $logger ?? $this->createMock(LoggerInterface::class),
    );
  }

  /**
   * Empty notify_email skips MailManager entirely.
   */
  public function testDisabledWhenNoRecipient(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');

    $this->buildNotifier('', $mail)->notify('scan_run', 'Subj', 'Body');
  }

  /**
   * Configured recipient triggers one mail() call with module params.
   */
  public function testSendsViaMailManager(): void {
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->once())
      ->method('mail')
      ->with(
        'ai_provider_universal_factcheck',
        'scan_run',
        'ops@example.com',
        'en',
        ['subject' => 'Hello', 'body' => 'World'],
      )
      ->willReturn(['result' => TRUE]);

    $this->buildNotifier('ops@example.com', $mail)->notify('scan_run', 'Hello', 'World');
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
      ->notify('settings_changed', 'S', 'B');
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
      ->notify('scan_run', 'S', 'B');
  }

}
