# Adding a server backend

A **AiServerBackend plugin** owns everything protocol-specific about one kind of AI server: how to build the base URI, how to list its models, and how to detect each model's capabilities and routing metadata. Everything else — the multi-server UI, model config entities, discovery sync, per-model overrides, smart routing — is generic and comes for free.

Backends can live in this module or in any other module: the plugin discovery picks up any class in `src/Plugin/AiServerBackend/` with the `#[AiServerBackend]` attribute.

## Two kinds of backends

1. **OpenAI-compatible service** (Fireworks, OpenRouter, Ollama, Groq, Together, Mistral, ...): extend `OpenAiCompatible` and override only what differs. Chat, embeddings and streaming already work end to end, because the provider executes requests over the OpenAI protocol. This is the common case — usually under 100 lines. Use `OpenRouter` / `Groq` (live catalog pricing + modalities), `Ollama` (native side-channel enrichment), or `Fireworks` (hardcoded price table + fixed endpoint) as a template.

2. **Native protocol** (Anthropic, Gemini, ...): extend `AiServerBackendPluginBase` and implement `AiServerBackendInterface` from scratch. **Current limitation:** the provider dispatches inference over the OpenAI protocol only, so a native backend today gets discovery and the UI, but not chat execution. Moving inference dispatch behind the backend interface is on the ROADMAP ("Inference dispatch through backends"); until then, stick to case 1 or help with that item first.

## The interface

`src/Backend/AiServerBackendInterface.php` — five methods (the base class provides no-op defaults for the last two):

| Method | Contract |
|---|---|
| `getBaseUri($server)` | Absolute base URI, e.g. `https://api.example.com/v1`. Return a constant default when the host field is empty if the service has a fixed endpoint. |
| `listModels($server)` | One array per model. Each entry MUST have an `id` key (raw model id); include any extra protocol-specific fields — they are passed verbatim to the two detect methods. Throw on connection/auth errors; the discovery UI reports them. |
| `detectOperationTypes($entry)` | Operation type ids: `chat`, `embeddings`, `moderation`, `rerank`, `speech_to_text`, `text_to_speech`, `text_to_image`. Prefer structured catalog fields over model-name regexes; fall back to `parent::detectOperationTypes()` for the generic name heuristics. |
| `detectModelMetadata($entry)` | Any subset of `cost_input` / `cost_output` (USD per **million** tokens), `quality_tier` (1–5), `context_length` (tokens). Empty array when nothing can be inferred — the generic defaults in `definitions/model_defaults.yml` (family/size tier guesses, site-maintained costs) then fill the gaps. Values only fill fields that are still unset — re-discovery never clobbers manual edits, so prefilling is always safe. |
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
    // when the API publishes nothing.
    return [...];
  }

}
```

Notes:

- **Authentication** is inherited: the server entity references a Key entity and `OpenAiCompatible::listModels()` sends it as a Bearer token. Only override if your service uses a different auth scheme.
- **Streaming, whitelisting/model filters, the admin UI** are not backend concerns — do not reimplement them.
- If models live on more than one catalog endpoint, override `listModels()`, fetch all of them and merge (each entry still needs `id`).
- Dependency injection: `OpenAiCompatible` already injects `http_client_factory`, `state` and `key.repository`. Add a constructor + `create()` override only if you need more services.

## Checklist before opening an MR

- [ ] Plugin class in `src/Plugin/AiServerBackend/`, `#[AiServerBackend]` attribute with translatable label/description.
- [ ] `detectModelMetadata()` costs are USD per 1M tokens (convert if the API reports per-token prices).
- [ ] Unit test for the two detect methods (see `tests/src/Unit/Plugin/AiServerBackend/OpenRouterTest.php` — the detect methods are pure, so mocked services suffice).
- [ ] Verified against the live API at least once: create a server with the new backend and run `drush aip:discover-models <server_id>`.
- [ ] `phpstan` (module's `phpstan.neon`), `phpcs --standard=Drupal,DrupalPractice` and `cspell` pass (add product names to `.cspell.json`).
- [ ] README backend list and ROADMAP updated.
