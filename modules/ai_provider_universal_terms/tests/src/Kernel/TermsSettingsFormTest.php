<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_provider_universal_terms\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_provider_universal_terms\Form\TermsSettingsForm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the settings form saves to the module's config.
 */
#[CoversClass(TermsSettingsForm::class)]
#[Group('ai_provider_universal')]
#[IgnoreDeprecations]
#[RunTestsInSeparateProcesses]
final class TermsSettingsFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'views',
    'key',
    'ai',
    'ai_provider_universal',
    'ai_provider_universal_factcheck',
    'ai_provider_universal_terms',
  ];

  /**
   * Submitted values land in ai_provider_universal_terms.settings.
   */
  public function testSubmitSavesConfig(): void {
    $this->installConfig(['ai_provider_universal_terms']);
    $form_state = (new FormState())->setValues([
      'model' => '',
      'chunk_words' => 900,
      'max_chars' => 150000,
      'requests_per_hour' => 0,
    ]);
    $this->container->get('form_builder')->submitForm(TermsSettingsForm::class, $form_state);

    $this->assertSame([], $form_state->getErrors());
    $config = $this->config('ai_provider_universal_terms.settings');
    $this->assertSame(900, $config->get('chunk_words'));
    $this->assertSame(150000, $config->get('max_chars'));
    $this->assertSame(0, $config->get('requests_per_hour'));
  }

}
