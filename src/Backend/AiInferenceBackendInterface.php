<?php

namespace Drupal\ai_provider_universal\Backend;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_provider_universal\Entity\AiUniversalServerInterface;

/**
 * Opt-in interface for backends that execute chat over a native protocol.
 *
 * By default the provider dispatches every chat request over the OpenAI REST
 * protocol, which is what the vast majority of servers speak (llama.cpp,
 * vLLM, Ollama, Groq, OpenRouter, LiteLLM, ...). A backend whose service
 * speaks a different protocol — Anthropic's Messages API, Gemini's
 * generateContent, ... — implements this interface to own execution itself.
 *
 * Implementing it is optional and additive: backends that do not are
 * dispatched exactly as before, so existing backends (including ones in
 * other modules) keep working untouched.
 *
 * Everything around the call stays generic and is applied by the provider
 * whichever path a request takes: the pre-call gate and model swapping,
 * per-server usage limits, usage recording, the post-call event, smart
 * routing, fact check and governance.
 *
 * @see \Drupal\ai_provider_universal\Plugin\AiProvider\UniversalProvider::doChat()
 * @see \Drupal\ai_provider_universal\Plugin\AiServerBackend\Anthropic
 */
interface AiInferenceBackendInterface {

  /**
   * Executes one chat request against the server's native protocol.
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat input, in any of the shapes AI core passes to a provider.
   * @param string $modelId
   *   The raw model id as the service knows it (not the entity id).
   * @param \Drupal\ai_provider_universal\Entity\AiUniversalServerInterface $server
   *   The server owning the model: endpoint, key, timeout and headers.
   * @param array $configuration
   *   The provider configuration for this call, already merged with the
   *   model's reasoning effort, sampling overrides and extra request
   *   parameters. Keys are OpenAI-compatible names; implementations
   *   translate the ones their protocol spells differently and SHOULD pass
   *   unknown keys through verbatim, so per-model extra request parameters
   *   reach the native API.
   * @param bool $streamed
   *   Whether the caller asked for a streamed response. Implementations that
   *   cannot stream MUST throw
   *   \Drupal\ai\Exception\AiMissingFeatureException rather than silently
   *   returning a complete response.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The normalized output. Its token usage feeds usage limits and the
   *   savings report, so implementations MUST populate it when the API
   *   reports token counts.
   *
   * @throws \Drupal\ai\Exception\AiRequestErrorException
   *   When the request fails or the service answers with an error.
   */
  public function chat(array|string|ChatInput $input, string $modelId, AiUniversalServerInterface $server, array $configuration = [], bool $streamed = FALSE): ChatOutput;

}
