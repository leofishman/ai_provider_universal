<?php

namespace Drupal\ai_provider_universal\Backend;

use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;

/**
 * Interface for server backend plugins.
 *
 * Implementations own the protocol-specific parts of a server integration.
 * The first (and reference) implementation is 'openai_compatible', which
 * covers llama.cpp, Ollama, vLLM, LM Studio, Fireworks, OpenAI and any
 * other server that speaks the OpenAI REST protocol. Native backends
 * (e.g. Anthropic, Gemini) can be added as further plugins without
 * touching the catalog or the provider.
 */
interface AiServerBackendInterface {

  /**
   * Returns the base URI requests to this server should target.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server entity.
   *
   * @return string
   *   Absolute base URI, e.g. "http://box:8080/v1".
   */
  public function getBaseUri(AiUniversalServerInterface $server): string;

  /**
   * Lists the models the server currently exposes.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server entity.
   *
   * @return array<int, array<string, mixed>>
   *   One entry per model. Each entry MUST contain an 'id' key with the raw
   *   model id; any other protocol-specific metadata may be included and is
   *   passed verbatim to detectOperationTypes().
   *
   * @throws \Throwable
   *   When the server cannot be reached or answers with an error.
   */
  public function listModels(AiUniversalServerInterface $server): array;

  /**
   * Detects the operation types a discovered model supports.
   *
   * @param array $modelEntry
   *   A single entry as returned by listModels().
   *
   * @return string[]
   *   Operation type ids (chat, embeddings, moderation, rerank,
   *   speech_to_text, text_to_speech, text_to_image, ...).
   */
  public function detectOperationTypes(array $modelEntry): array;

  /**
   * Detects routing-relevant metadata for a discovered model.
   *
   * Values are applied to the ai_universal_model entity only when the
   * corresponding field is still unset, so user edits are never clobbered
   * by re-discovery.
   *
   * @param array $modelEntry
   *   A single entry as returned by listModels().
   *
   * @return array{cost_input?: float, cost_output?: float, quality_tier?: int, context_length?: int, supported_features?: string[]}
   *   Any subset of: cost_input / cost_output (USD per million tokens),
   *   quality_tier (1-5), context_length (tokens), supported_features
   *   (catalog capability flags such as tools, reasoning, json_mode). Empty
   *   array when the backend cannot infer anything. Features are always
   *   overwritten on re-discovery (unlike costs/tier/context).
   */
  public function detectModelMetadata(array $modelEntry): array;

  /**
   * Multiplier applied to the model's stored costs at decision time.
   *
   * For providers that bill on a time-of-day schedule (DeepSeek off-peak,
   * ...) stored costs stay at list price and this returns the current
   * discount factor. Default: 1.0 (no adjustment).
   *
   * @param string $rawModelId
   *   The raw model id as sent to the provider.
   * @param int|null $timestamp
   *   Unix timestamp to price for; NULL means now.
   *
   * @return float
   *   Factor to multiply input and output costs by.
   */
  public function getPriceMultiplier(string $rawModelId, ?int $timestamp = NULL): float;

  /**
   * Returns extra HTTP headers every request to this server should carry.
   *
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server entity.
   *
   * @return array<string, string>
   *   Header name => value map, empty when the protocol needs none. Used
   *   for service-specific headers such as OpenRouter's attribution
   *   headers; authentication is handled separately via the Key module.
   */
  public function getHttpHeaders(AiUniversalServerInterface $server): array;

}
