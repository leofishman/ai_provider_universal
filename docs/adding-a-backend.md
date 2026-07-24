# Adding a server backend

A **AiServerBackend plugin** owns everything protocol-specific about one kind of AI server: how to build the base URI, how to list its models, and how to detect each model's capabilities and routing metadata. Everything else — the multi-server UI, model config entities, discovery sync, per-model overrides, smart routing — is generic and comes for free.

Backends can live in this module or in any other module: the plugin discovery picks up any class in `src/Plugin/AiServerBackend/` with the `#[AiServerBackend]` attribute.

## Two kinds of backends

1. **OpenAI-compatible service** (Fireworks, OpenRouter, Ollama, Groq, Together, Mistral, ...): extend `OpenAiCompatible` and override only what differs. Chat, embeddings and streaming already work end to end, because the provider executes requests over the OpenAI protocol. This is the common case — usually under 100 lines. Use `OpenRouter` / `Groq` (live catalog pricing + modalities), `Ollama` (native side-channel enrichment), or `Fireworks` (hardcoded price table + fixed endpoint) as a template.

2. **Native protocol** (Anthropic, Gemini, ...): extend `AiServerBackendPluginBase`, implement `AiServerBackendInterface` for discovery, and additionally implement **`AiInferenceBackendInterface`** to own chat execution. Without that second interface a backend still gets discovery and the full UI, but its chat calls go over the OpenAI protocol — which is exactly right for case 1 and wrong for a native API. Use `Anthropic` as the template.

## The interface

`src/Backend/AiServerBackendInterface.php` — five methods (the base class provides no-op defaults for the last two):

| Method | Contract |
|---|---|
| `getBaseUri($server)` | Absolute base URI, e.g. `https://api.example.com/v1`. Return a constant default when the host field is empty if the service has a fixed endpoint. |
| `listModels($server)` | One array per model. Each entry MUST have an `id` key (raw model id); include any extra protocol-specific fields — they are passed verbatim to the two detect methods. Throw on connection/auth errors; the discovery UI reports them. |
| `detectOperationTypes($entry)` | Operation type ids: `chat`, `embeddings`, `moderation`, `rerank`, `speech_to_text`, `text_to_speech`, `text_to_image`. Prefer structured catalog fields over model-name regexes; fall back to `parent::detectOperationTypes()` for the generic name heuristics. |
| `detectModelMetadata($entry)` | Any subset of `cost_input` / `cost_output` (USD per **million** tokens), `quality_tier` (1–5), `context_length` (tokens), `supported_features` (string list of catalog flags such as `tools`, `json_mode`, `reasoning`). Empty array when nothing can be inferred — the generic defaults in `definitions/model_defaults.yml` (family/size tier guesses, site-maintained costs) then fill tier/cost gaps. **Costs / tier / context** only fill fields that are still unset (re-discovery never clobbers manual edits). **`supported_features` is always rewritten** on discovery (read-only catalog flags, no UI override). |
| `getHttpHeaders($server)` | Extra headers for every request to the server (e.g. OpenRouter's attribution headers). Default: none. Not for authentication — that comes from the Key entity. |

## Walkthrough: the OpenRouter backend

`src/Plugin/AiServerBackend/OpenRouter.php` is the smallest real example. The essential shape:

```php
#[AiServerBackend(
  id: 'my_service',
  label: new TranslatableMarkup('My Service'),
  description: new TranslatableMarkup('Shown in the server form backend select.'),
)]
class MyService extends OpenAiCompatible {

  protected const DEFAULT_BASE_URI = 'https://api.my-service.ai/v1';

  public function getBaseUri(AiUniversalServerInterface $server): string {
    // Make host/port optional when the service has one public endpoint.
    return $server->getHostName() ? parent::getBaseUri($server) : self::DEFAULT_BASE_URI;
  }

  public function detectOperationTypes(array $modelEntry): array {
    // Use structured catalog data when the service publishes it ...
    if (in_array('image', $modelEntry['architecture']['output_modalities'] ?? [], TRUE)) {
      return ['text_to_image'];
    }
    // ... and fall back to the generic name heuristics.
    return parent::detectOperationTypes($modelEntry);
  }

  public function detectModelMetadata(array $modelEntry): array {
    // Read pricing/context from the catalog payload when available: it
    // stays current automatically. Hardcode a table (see Fireworks) only
    // when the API publishes nothing. Optionally pass supported_features
    // when the catalog lists capability flags (see Groq).
    return [...];
  }

}
```

Reference implementations in this module:

| Pattern | Example |
|---|---|
| Live catalog pricing + modalities | `OpenRouter`, `Groq` |
| Fixed endpoint + hardcoded price table | `Fireworks`, `Grok` |
| Native side-channel enrichment (`/api/show`) | `Ollama` |
| Proxy structured `/model/info` | `LiteLlm` / `Amazee` |

Notes:

- **Authentication** is inherited: the server entity references a Key entity and `OpenAiCompatible::listModels()` sends it as a Bearer token. Only override if your service uses a different auth scheme.
- **Streaming, whitelisting/model filters, the admin UI** are not backend concerns — do not reimplement them.
- If models live on more than one catalog endpoint, override `listModels()`, fetch all of them and merge (each entry still needs `id`).
- **`supported_features`**: pass lowercase feature ids when the API exposes them. They land on `ai_universal_model` and show as “Catalog features” in the server form. Do not confuse catalog `reasoning` with the model’s **reasoning effort** select (`reasoning_effort` request param).
- Dependency injection: `OpenAiCompatible` already injects `http_client_factory`, `state`, `key.repository` and `logger.factory`. Add a constructor + `create()` override only if you need more services.
- **Logging**: never swallow an enrichment failure silently. Catch, call `$this->log('notice', '...', [...])` (no-op in unit tests, `ai_provider_universal` channel at runtime) and fall back gracefully — see the `catch` blocks in `Ollama::listModels()` and `LiteLlm::listModels()`. Only the main `/models` fetch may throw: the form and the Drush command catch and report it.

## Owning inference: `AiInferenceBackendInterface`

By default the provider executes every chat request over the OpenAI protocol, through AI core's OpenAI client. A backend whose service speaks a different protocol implements `src/Backend/AiInferenceBackendInterface.php` — one method — and the provider hands execution to it instead:

```php
public function chat(
  array|string|ChatInput $input,
  string $modelId,
  AiUniversalServerInterface $server,
  array $configuration = [],
  bool $streamed = FALSE,
): ChatOutput;
```

Implementing it is **opt-in and additive**. Backends that do not implement it — including any in other modules — are dispatched exactly as before; adding a native backend cannot change how an existing one behaves.

Everything that wraps the call stays generic and applies to both paths: the pre-call gate (`ModelPreCallEvent`, model swapping, blocking), per-server daily usage limits, usage recording, `ModelPostCallEvent`, smart routing, fact check and content governance.

What your implementation owns:

| Concern | Contract |
|---|---|
| Input | Whatever AI core passed (`ChatInput`, array or string). The provider injects any `setChatSystemRole()` value as a leading system message before handing it over, so you do not have to read it off the provider — but you do have to hoist system messages yourself if your protocol keeps them outside the message list. |
| `$configuration` | Provider settings already merged with the model's reasoning effort, sampling overrides and **extra request parameters**. Keys use OpenAI-compatible names. Translate the ones your protocol spells differently, drop the ones it would reject, and **pass unknown keys through verbatim** — that is what lets the per-model extra parameters field reach your API. |
| Token usage | Populate `TokenUsageDto` on the returned `ChatOutput` whenever the API reports counts. Usage limits, the savings dashboard and `ModelPostCallEvent` all read it; skipping it silently disables per-server limits for your backend. |
| Streaming | When `$streamed` is TRUE, return a `ChatOutput` wrapping a `StreamedChatMessageIterator` subclass. If you cannot stream, throw `AiMissingFeatureException` — never silently return a complete response. |
| Errors | Map HTTP failures onto AI core exceptions: `AiRateLimitException`, `AiQuotaException` (smart routing treats it as "try the next candidate"), `AiSetupFailureException` for auth/config, `AiRequestErrorException` otherwise. |

`Anthropic` is the reference implementation: system-prompt hoisting, content blocks, tool calling both ways, vision and PDF input, structured output emulated with a forced tool, extended thinking from reasoning effort, SSE streaming (`src/Chat/AnthropicStreamedChatMessageIterator.php`) and cache-aware token accounting.

Operation types other than chat still go over the OpenAI protocol. A native backend should therefore report only the operation types its protocol actually serves from `detectOperationTypes()`.

## Checklist before opening an MR

- [ ] Plugin class in `src/Plugin/AiServerBackend/`, `#[AiServerBackend]` attribute with translatable label/description.
- [ ] `detectModelMetadata()` costs are USD per 1M tokens (convert if the API reports per-token prices); optional `supported_features` list when the catalog has flags.
- [ ] Native backends: kernel test for the request/response mapping with mocked HTTP (see `tests/src/Kernel/Plugin/AnthropicBackendTest.php`).
- [ ] Unit test for the two detect methods (see `tests/src/Unit/Plugin/AiServerBackend/OpenRouterTest.php` or `GroqTest.php` — the detect methods are pure, so mocked services suffice).
- [ ] Verified against the live API at least once: create a server with the new backend and run `drush aip:discover-models <server_id>`.
- [ ] `phpstan` (module's `phpstan.neon`), `phpcs --standard=Drupal,DrupalPractice` and `cspell` pass (add product names to `.cspell.json`).
- [ ] README backend list, [docs/servers-and-models.md](servers-and-models.md) catalog row, glossary if needed, and ROADMAP updated.
