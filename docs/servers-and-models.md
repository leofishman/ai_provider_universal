# Servers, models and backends

## Servers: `ai_universal_server` config entities

A **server** is one endpoint: a host, a backend (protocol), optional authentication, a timeout, an optional model filter, and optional daily usage limits. Manage them at **Configuration → AI → Providers → Universal** (`/admin/config/ai/providers/universal`).

Fields on the server form:

| Field | Meaning |
|---|---|
| Server name / machine name | Label and permanent id (`ai_universal_server.id`). |
| Backend | Protocol plugin — see the catalog below. Hidden when only one backend is installed. |
| Host name | `http://host` or `https://host`. Hidden for backends with a fixed endpoint (OpenRouter, Hugging Face, Fireworks, Groq, Ollama Cloud, Grok), which show their endpoint as a hint instead. |
| Port | Optional; common local defaults are documented inline (Ollama 11434, llama.cpp 8080, vLLM 8000, LM Studio 1234, LiteLLM 4000). |
| API Key | A [Key](https://www.drupal.org/project/key) entity, sent as `Authorization: Bearer`. Required for hosted services; optional for unauthenticated local servers. The "create a new key" link opens in a new tab; the **Refresh keys** button re-populates the select without losing your form input. |
| Timeout | Request timeout in seconds (default 600). |
| Model filter pattern | See [Model filtering](#model-filtering) below. |
| Usage limits | Daily request/token caps — see [docs/usage-limits.md](usage-limits.md). |

The **Test connection & list models** button previews the server's model catalog (and any connection error) without saving anything. Saving a server **tests the connection** (the backend's `listModels()` is called during validation; failure blocks the save with the exact protocol error logged to the `ai_provider_universal` channel) and then **runs model discovery**, persisting the result as `ai_universal_model` entities.

## Backend catalog

Every backend is a `AiServerBackendInterface` plugin (`src/Plugin/AiServerBackend/`), auto-discovered via the `#[AiServerBackend]` attribute. They share one execution path (`OpenAiBasedProviderClientBase` — all speak the OpenAI REST protocol) and differ only in: default endpoint, how models are listed, how capabilities are detected, and how routing metadata (cost/tier/context) and optional catalog features are prefilled.

| Backend id | Service | Default endpoint | Needs host/port | Discovery source | Capability detection | Pricing/context source |
|---|---|---|---|---|---|---|
| `openai_compatible` | llama.cpp, vLLM, LM Studio and any OpenAI-protocol server | none (required) | yes | `/v1/models` | llama.cpp `status.args` (`--embeddings`, `--reranking`) → HF `pipeline_tag` of `--hf-repo` → name heuristics → `chat` | `--ctx-size` (router mode) → `meta.n_ctx_train` → `max_model_len` (vLLM); cost stays unset |
| `ollama` | Local Ollama | none (required; typical host `http://127.0.0.1`, port `11434`) | yes | `/v1/models` enriched per model with native `POST /api/show` | Ollama `capabilities` / `details.family` → generic heuristics | Free costs (`0`); context from Modelfile `num_ctx` or `model_info.*.context_length` |
| `groq` | GroqCloud | `api.groq.com/openai/v1` | no (fixed) | `/v1/models` (rich catalog) | `output_modalities` (transcription → STT, speech → TTS) → prompt-guard/safeguard → generic heuristics | Live: costs, context, and `supported_features` (tools / json_mode / structured_outputs / reasoning) persisted on the model entity. Quality tier via `model_defaults.yml`. Reasoning *effort* stays manual |
| `fireworks` | Fireworks AI serverless | `api.fireworks.ai/inference/v1` | optional | `/v1/models` | Name heuristics tuned to `accounts/fireworks/models/*` ids | Hardcoded table of published serverless prices by model-family substring (`src/Plugin/AiServerBackend/Fireworks.php::MODEL_METADATA`) |
| `openrouter` | OpenRouter unified API | `openrouter.ai/api/v1` | optional | `/v1/models` | `architecture.output_modalities` (image → `text_to_image`) → generic heuristics | Live from the catalog payload (`pricing.prompt`/`completion`, `context_length`) — no hardcoded table |
| `litellm` | Self-hosted LiteLLM proxy | none (required) | yes | `/model/info` (proxy root, not `/v1`); falls back to `/v1/models` if the key can't read it | Structured `model_info.mode` field → generic heuristics on fallback | `model_info.input_cost_per_token`/`output_cost_per_token` (× 1M) and `max_input_tokens` |
| `amazee` | amazee.ai (managed LiteLLM, region-pinned) | none — host is your `litellm_api_url` | yes | Same as `litellm` (subclass, no protocol differences) | Same as `litellm` | Same as `litellm` |
| `huggingface` | Hugging Face Inference Providers | `router.huggingface.co/v1` | optional | `/v1/models` | `architecture.output_modalities` → generic heuristics | Cheapest **live** provider offer by input price; context length is the max any live provider serves |
| `ollama_cloud` | Ollama Cloud (ollama.com) | `ollama.com/v1` | optional (needs API key) | `/v1/models` | Generic heuristics only (bare ids, no metadata) | None — fill in manually |
| `grok` | Grok (xAI) | `api.x.ai/v1` | no (fixed) | `/v1/models` | Generic heuristics | Basic hardcoded table for grok-2 / grok-beta |

> ⚠️ **Fireworks pricing is a maintained lookup table, not live data.** Verify against [fireworks.ai/pricing](https://fireworks.ai/pricing) when Fireworks ships a new model generation — stale prices skew smart-routing cost comparisons. **Groq** reads prices live from `/v1/models` (same idea as OpenRouter).

Notes:
- **LiteLLM discovery degrades gracefully**: if the configured key cannot read `/model/info` (common with scoped virtual keys), the backend falls back to the plain `/v1/models` catalog with name-based detection instead of failing discovery outright.
- **amazee.ai** is a thin subclass of `litellm` purely so it shows up as its own option in the backend select with amazee-specific description text — there is no protocol difference.
- **Ollama vs Ollama Cloud**: use `ollama` for a self-hosted daemon (LAN/Docker/host.docker.internal); use `ollama_cloud` for ollama.com. Local Ollama still works under `openai_compatible`, but you lose `/api/show` context enrichment and free-cost prefill.
- No AI core patches are required: model entity ids use a **dot** separator (`server.model`) so AI core's `provider__model` simple options and `ai_search` parse them correctly, and `getConfiguredModels()` returns a flat `model_id => label` map.

## Model discovery

Discovery is the **write path**: it calls the backend's `listModels()`, runs the model filter, asks the backend to detect operation types and routing metadata per model, then persists the result as `ai_universal_model` config entities (`src/Service/ModelCatalog.php`).

- **Entity id**: `<server_id>.<sanitized_raw_model_id>`, so ids stay unique across servers even when two servers expose a model with the same raw id (e.g. `local.llama3` vs `openrouter.llama3`).
- **Label**: `<Server label> / <raw model id>`, only set automatically while it still matches the auto-generated pattern — a manually renamed model label survives re-discovery.
- **Removed models are deleted**: any `ai_universal_model` for the server that discovery no longer sees is removed (`hook_entity_delete` also cascades: deleting a server deletes all its models).
- **Manual edits are never clobbered** for cost, quality tier, context length and reasoning effort: `applyDetectedMetadata()` only writes a detected value into a field that is still `NULL`. Once you set a value in the UI, re-discovery leaves it alone.
- **Catalog features always refresh**: `supported_features` (when the backend reports them) is rewritten on every discovery — there is no manual override. Empty when the backend does not publish features.
- **Site-editable defaults**: when the backend detects no quality tier (and optionally no costs), `definitions/model_defaults.yml` fills the gap — named-family regexes (claude-opus → 5, mixtral → 3, ...), a parameter-count fallback (70b → 3, 7b → 2, ...), a last-resort price-band fallback for live-pricing catalogs (`prices:` — cost_output ≥ $20/1M → 5, ≥ $5 → 4, ..., so OpenRouter's 300+ models don't all land unrated), and a `costs:` map shipped empty for you to maintain (USD per 1M tokens). A `sampling:` map ships vendor-documented recommendations (qwen3 and deepseek-r1 → temperature 0.6 / top_p 0.95, gpt-oss → 1.0 / 1.0) applied only while the model has no sampling overrides yet. Same never-clobber rule applies. Don't edit the module file (it is replaced on updates): put site entries in an override file with the same format — its entries win — and declare it in settings.php: `$settings['ai_provider_universal_model_defaults'] = 'sites/default/model_defaults.yml';`.
- **Discovery failures are logged, not fatal**: per-model enrichment problems (Ollama `/api/show`, LiteLLM `/model/info`, Hugging Face pipeline-tag lookups) log a notice to the `ai_provider_universal` channel and fall back to the plain catalog entry, so one unreachable metadata endpoint never aborts discovery.

Trigger discovery with:

```bash
drush aip:discover-models              # all configured servers
drush aip:discover-models my_server    # one server
# alias: aipdm
```

or by re-saving the server (its form runs discovery on every save).

## Model capability overrides

Each server's edit form has a **"Models: capabilities and routing metadata"** section (one collapsed fieldset per discovered model) with:

| Field | Effect |
|---|---|
| Catalog features | Read-only list from discovery (`supported_features`: e.g. `tools`, `json_mode`, `structured_outputs`, `reasoning`). Refreshed every re-discovery; no manual override. Empty when the backend does not publish features. |
| Operation types | Override the auto-detected types (chat, embeddings, speech to text, rerank, moderation, text to image). Leave unchecked to keep auto-detection. |
| Cost per 1M input/output tokens (USD) | Feeds smart routing's cost comparison. Use `0` for local/self-hosted models — unknown cost also counts as 0, which naturally favors local models, so set explicit costs on remote ones to compare correctly. |
| Quality tier (1–5) | Subjective capability rating used by smart routing (1 Minimal → 5 Frontier). Unrated models default to tier 3 when a route checks eligibility. |
| Context length (tokens) | Auto-detected when the server exposes it; smart routing rejects a candidate whose context can't fit the estimated prompt + 512 assumed output tokens. |
| Reasoning effort | Sent as the OpenAI-compatible `reasoning_effort` request parameter on every chat call to this model (`none`/`low`/`medium`/`high`). Leave as "Server default" to send nothing. Servers that don't support the parameter simply ignore it. Distinct from the catalog `reasoning` *feature* flag (capability, not effort level). |
| Sampling overrides | Optional `temperature`, `top_p`, `frequency_penalty`, `presence_penalty`, sent on every chat call to this model, overriding the generic provider configuration. Empty fields send nothing (server default). Prefilled at discovery from the vendor recommendations in `model_defaults.yml` when the family is known; your edits are never clobbered. To tune the *same* model differently per use case, duplicate the model entity — see [one model, two configurations](#example-one-model-two-configurations). |
| Extra request parameters (YAML) | Free-form mapping merged verbatim into every chat request to this model, for parameters the module does not model itself — most usefully a provider's built-in tools (`tools: [{type: web_search}]`). The **Add a known parameter set** select above it merges a documented example from `definitions/extra_params.yml` into the field on save. `model`, `messages`, `stream` and `stream_options` are stripped (owned by the provider). A server that does not know a parameter usually ignores it; strict ones return an error. If the calling module passes Drupal function-calling tools on the `ChatInput`, AI core overwrites the `tools` key, so a native `tools` entry here only applies to calls that carry no Drupal tools. |

**Smart routing** (`RouteDecider`) uses cost, quality tier, context length and optional **required catalog features** on each route — see [docs/smart-routing.md](smart-routing.md). Catalog features also remain queryable in code (`$model->supportsFeature('tools')`, etc.). Reasoning effort and sampling overrides are applied by the provider on chat calls, not by the route decider.

## Example: one model, two configurations

Model entities are per-configuration, not per-model-name: nothing stops two entities from pointing at the same `raw_model_id` on the same server. That is how you offer the same model twice — once plain, once with the provider's web search enabled — and let each calling module (or each smart route candidate) pick the one it wants.

Discovery creates the plain one. Duplicate it:

```php
// drush php:script duplicate_model.php
$storage = \Drupal::entityTypeManager()->getStorage('ai_universal_model');
$plain = $storage->load('openai.gpt_5');

$search = $plain->createDuplicate();
$search->set('id', 'openai.gpt_5_websearch');
$search->set('label', 'gpt-5 (web search)');
$search->setExtraParams(['tools' => [['type' => 'web_search']]]);
$search->save();
```

Both entities now show up on the server form, and `openai.gpt_5_websearch` carries the extra parameters in its own **Extra request parameters** field, where you can edit them without touching the plain one. The two are independent everywhere downstream:

| | `openai.gpt_5` | `openai.gpt_5_websearch` |
|---|---|---|
| `raw_model_id` sent to the API | `gpt-5` | `gpt-5` |
| Extra request parameters | *(none)* | `tools: [{type: web_search}]` |
| Selectable at `/admin/config/ai/settings` | yes | yes |
| Usable as a smart route candidate | yes | yes |
| Usage counters and daily limits | shared (limits live on the **server**) | shared |

Typical use: set the plain entity as the default chat provider, and point only the modules that need current information (a news summarizer, a fact-check verifier) at the web search one. Costs differ — a provider-side search usually bills extra — so set the duplicate's cost per 1M tokens higher than the plain one, otherwise smart routing will treat them as interchangeable and pick the search variant for everything.

Re-discovery leaves the duplicate alone: it never deletes model entities, and it only refreshes catalog features on the ones whose raw id it finds.

The same recipe works for any per-use-case difference — a cold `temperature: 0` entity for extraction next to a `temperature: 0.8` one for drafting, or a `reasoning: high` entity for hard prompts.

## Model filtering

The server's **model filter pattern** is a comma-separated glob list, evaluated case-insensitively, applied during discovery (filtered-out models are never persisted):

```
llama3*, *mistral*, !*old*
```

- No `!` prefix → an **include** pattern (at least one include must match, if any includes are present).
- `!` prefix → an **exclude** pattern, checked first; any match rejects the model regardless of includes.
- Empty pattern → everything passes.

Implementation: `src/Utility/ModelFilter.php` (`*` compiles to `.*` in a bounded regex, no other glob syntax).

## Moderation models

Two moderation model families get dedicated response parsers (`src/Models/Moderation/`), matched by raw model id substring (`UniversalProvider::MODERATION_PARSERS`):

- **LlamaGuard3** (`llama-guard3`, `llamaguard`, `llama_guard`): parses the `safe` / `unsafe\nSXX` output format into the 14 official MLCommons-style hazard categories. Uses the normal chat-completions path.
- **ShieldGemma** (`shieldgemma`, `shield_gemma`, `shield-gemma`): its chat template requires a `guideline` template variable that a plain chat-completions call can't supply, so the provider posts a fully-built prompt to `/v1/completions` **once per safety policy** (harassment, hate speech, dangerous content, sexually explicit — the four guidelines in `ShieldGemma::getDefaultGuidelines()`) and flags the content if *any* policy returns "Yes". This costs up to 4 requests per moderation call.

Any other model falls back to a generic chat-completions call, flagged by a literal "unsafe" substring match in the response.

## Cache invalidation

`src/Hook/AiProviderUniversalHooks.php` clears the AI module's provider plugin definition cache whenever a `ai_universal_server` entity is inserted, updated, or deleted, so newly added/removed servers and their models appear in the AI settings model dropdowns without a manual cache rebuild. Deleting a server also cascades to delete its `ai_universal_model` entities.

## Extending: adding a backend

Backends are plugins — other modules can contribute one for a service this module doesn't cover natively (e.g. Together, or native Anthropic/Gemini once inference dispatch lands) without touching the catalog, the provider, or the multi-server UI. See [docs/adding-a-backend.md](adding-a-backend.md) for the contributor guide and interface walkthrough.
