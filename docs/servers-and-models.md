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

Every backend is a `AiServerBackendInterface` plugin (`src/Plugin/AiServerBackend/`), auto-discovered via the `#[AiServerBackend]` attribute. They share one execution path (`OpenAiBasedProviderClientBase` — all speak the OpenAI REST protocol) and differ only in: default endpoint, how models are listed, how capabilities are detected, and how routing metadata (cost/tier/context) is prefilled.

| Backend id | Service | Default endpoint | Needs host/port | Discovery source | Capability detection | Pricing/context source |
|---|---|---|---|---|---|---|
| `openai_compatible` | llama.cpp, vLLM, LM Studio and any OpenAI-protocol server | none (required) | yes | `/v1/models` | llama.cpp `status.args` (`--embeddings`, `--reranking`) → HF `pipeline_tag` of `--hf-repo` → name heuristics → `chat` | `--ctx-size` (router mode) → `meta.n_ctx_train` → `max_model_len` (vLLM); cost stays unset |
| `ollama` | Local Ollama | none (required; typical host `http://127.0.0.1`, port `11434`) | yes | `/v1/models` enriched per model with native `POST /api/show` | Ollama `capabilities` / `details.family` → generic heuristics | Free costs (`0`); context from Modelfile `num_ctx` or `model_info.*.context_length` |
| `groq` | GroqCloud | `api.groq.com/openai/v1` | no (fixed) | `/v1/models` (rich catalog) | `output_modalities` (transcription → STT, speech → TTS) → prompt-guard/safeguard → generic heuristics | Live from catalog (`pricing.prompt`/`completion` USD/token × 1M, `context_length` / `context_window`). Quality tier via `model_defaults.yml`. `supported_features` includes `reasoning` as a flag only — not effort levels |
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
- Two core AI-module patches are recommended for optgrouped model selects and `ai_search` compatibility — see the README's "Recommended core AI patches" section.

## Model discovery

Discovery is the **write path**: it calls the backend's `listModels()`, runs the model filter, asks the backend to detect operation types and routing metadata per model, then persists the result as `ai_universal_model` config entities (`src/Service/ModelCatalog.php`).

- **Entity id**: `<server_id>__<sanitized_raw_model_id>`, so ids stay unique across servers even when two servers expose a model with the same raw id (e.g. `local__llama3` vs `openrouter__llama3`).
- **Label**: `<Server label> / <raw model id>`, only set automatically while it still matches the auto-generated pattern — a manually renamed model label survives re-discovery.
- **Removed models are deleted**: any `ai_universal_model` for the server that discovery no longer sees is removed (`hook_entity_delete` also cascades: deleting a server deletes all its models).
- **Manual edits are never clobbered**: `applyDetectedMetadata()` only writes a detected value (cost, quality tier, context length) into a field that is still `NULL`. Once you set a value in the UI, re-discovery leaves it alone.
- **Site-editable defaults**: when the backend detects no quality tier (and optionally no costs), `definitions/model_defaults.yml` fills the gap — named-family regexes (claude-opus → 5, mixtral → 3, ...), a parameter-count fallback (70b → 3, 7b → 2, ...), and a `costs:` map shipped empty for you to maintain (USD per 1M tokens). Same never-clobber rule applies. Don't edit the module file (it is replaced on updates): put site entries in an override file with the same format — its entries win — and declare it in settings.php: `$settings['ai_provider_universal_model_defaults'] = 'sites/default/model_defaults.yml';`.

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
| Operation types | Override the auto-detected types (chat, embeddings, speech to text, rerank, moderation, text to image). Leave unchecked to keep auto-detection. |
| Cost per 1M input/output tokens (USD) | Feeds smart routing's cost comparison. Use `0` for local/self-hosted models — unknown cost also counts as 0, which naturally favors local models, so set explicit costs on remote ones to compare correctly. |
| Quality tier (1–5) | Subjective capability rating used by smart routing (1 Minimal → 5 Frontier). Unrated models default to tier 3 when a route checks eligibility. |
| Context length (tokens) | Auto-detected when the server exposes it; smart routing rejects a candidate whose context can't fit the estimated prompt + 512 assumed output tokens. |
| Reasoning effort | Sent as the OpenAI-compatible `reasoning_effort` request parameter on every chat call to this model (`none`/`low`/`medium`/`high`). Leave as "Server default" to send nothing. Servers that don't support the parameter simply ignore it. |

These are the exact fields `RouteDecider` reads — see [docs/smart-routing.md](smart-routing.md) for how they're used to pick a model per request.

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

Backends are plugins — other modules can contribute one for a service this module doesn't cover natively (Anthropic, Gemini, Groq, Together, ...) without touching the catalog, the provider, or the multi-server UI. See [docs/adding-a-backend.md](adding-a-backend.md) for the contributor guide and interface walkthrough.
