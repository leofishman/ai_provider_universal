<?php

namespace Drupal\ai_provider_universal_terms\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\ai_provider_universal_factcheck\Exception\PageFetchException;
use Drupal\ai_provider_universal_factcheck\Service\PageFetcher;
use Drupal\ai_provider_universal_terms\Service\TermsAnalyzer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API: POST {"text": "..."} or {"url": "https://..."}.
 */
class TermsController extends ControllerBase {

  public function __construct(
    protected TermsAnalyzer $analyzer,
    protected PageFetcher $pageFetcher,
    protected FloodInterface $flood,
  ) {}

  /**
   * Analyzes the posted document.
   */
  public function analyze(Request $request): JsonResponse {
    if (!$this->analyzer->isConfigured()) {
      return $this->error(503, 'The terms analyzer has no model configured.');
    }
    $body = json_decode($request->getContent(), TRUE);
    $text = $body['text'] ?? NULL;
    $url = $body['url'] ?? NULL;
    if (!is_array($body) || !(is_string($text) xor is_string($url))) {
      return $this->error(400, 'Send a JSON object with either "text" or "url".');
    }

    // Every analyzed request may cost model calls: limited per user, or per
    // IP for anonymous callers.
    $limit = (int) $this->config('ai_provider_universal_terms.settings')->get('requests_per_hour');
    $who = $this->currentUser()->isAnonymous() ? NULL : 'user:' . $this->currentUser()->id();
    if ($limit > 0) {
      if (!$this->flood->isAllowed('ai_provider_universal_terms.analyze', $limit, 3600, $who)) {
        $response = $this->error(429, sprintf('More than %d requests in the last hour; try again later.', $limit));
        $response->headers->set('Retry-After', '3600');
        return $response;
      }
      $this->flood->register('ai_provider_universal_terms.analyze', 3600, $who);
    }

    if (is_string($url)) {
      try {
        $text = $this->pageFetcher->fetchText($url);
      }
      catch (PageFetchException $e) {
        return $this->error(422, strip_tags((string) $e->userMessage));
      }
    }
    if (trim($text) === '') {
      return $this->error(422, 'The document is empty.');
    }
    if (mb_strlen($text) > $this->analyzer->maxChars()) {
      return $this->error(413, sprintf('The document is longer than %d characters.', $this->analyzer->maxChars()));
    }

    try {
      $result = $this->analyzer->analyze($text);
    }
    catch (\RuntimeException $e) {
      return $this->error(502, $e->getMessage());
    }
    return new JsonResponse($result + [
      'notice' => 'Automated reading of the document, not legal advice. Each finding quotes the clause it comes from.',
    ]);
  }

  /**
   * An error response with a JSON body.
   */
  protected function error(int $status, string $message): JsonResponse {
    return new JsonResponse(['error' => $message], $status);
  }

}
