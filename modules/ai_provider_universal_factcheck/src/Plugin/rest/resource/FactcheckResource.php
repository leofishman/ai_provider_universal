<?php

namespace Drupal\ai_provider_universal_factcheck\Plugin\rest\resource;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai_provider_universal_factcheck\Service\AiDetector;
use Drupal\ai_provider_universal_factcheck\Service\FactChecker;
use Drupal\ai_provider_universal_factcheck\Service\PlagiarismChecker;
use Drupal\rest\Attribute\RestResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST {"text": "..."} to /api/factcheck/{claims|ai|plagiarism}?_format=json.
 *
 * Enabled by config/optional/rest.resource.factcheck.yml (JSON, basic_auth
 * and cookie); callers need "restful post factcheck". Every request spends
 * LLM and search API budget.
 */
#[RestResource(
  id: 'factcheck',
  label: new TranslatableMarkup('Fact check'),
  uri_paths: ['create' => '/api/factcheck/{check}'],
)]
class FactcheckResource extends ResourceBase {

  /**
   * Longest text accepted; claim extraction and detection read less anyway.
   */
  public const MAX_CHARS = 20000;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected FactChecker $factChecker,
    protected AiDetector $aiDetector,
    protected PlagiarismChecker $plagiarismChecker,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.channel.ai_provider_universal_factcheck'),
      $container->get(FactChecker::class),
      $container->get(AiDetector::class),
      $container->get(PlagiarismChecker::class),
    );
  }

  /**
   * Runs one check on the posted text.
   */
  public function post(string $check, ?array $data = NULL): ModifiedResourceResponse {
    $text = is_string($data['text'] ?? NULL) ? trim($data['text']) : '';
    if ($text === '') {
      throw new BadRequestHttpException('Send a JSON object with a non-empty "text".');
    }
    if (mb_strlen($text) > self::MAX_CHARS) {
      throw new HttpException(413, sprintf('The text is longer than %d characters.', self::MAX_CHARS));
    }
    $configured = match ($check) {
      'claims' => $this->factChecker->isConfigured(),
      'ai' => $this->aiDetector->isConfigured(),
      'plagiarism' => $this->plagiarismChecker->isConfigured(),
      default => throw new HttpException(404, 'Unknown check; use claims, ai or plagiarism.'),
    };
    if (!$configured) {
      throw new HttpException(503, 'This check is not configured on this site.');
    }
    $result = match ($check) {
      'claims' => $this->factChecker->verify('', $text),
      'ai' => $this->aiDetector->detect($text),
      'plagiarism' => ['matches' => $this->plagiarismChecker->check($text)],
    };
    if ($result === NULL) {
      throw new HttpException(502, 'The model gave no usable answer.');
    }
    // Not cacheable: the same text may be re-checked against newer evidence.
    return new ModifiedResourceResponse($result);
  }

}
