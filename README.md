# AI Provider: Universal

A universal, multi-instance AI provider for the [Drupal AI module](https://www.drupal.org/project/ai).

Unlike single-endpoint providers, this module models your AI infrastructure as **config entities**:

- **Servers** (`ai_universal_server`) — each server is an independent endpoint with its own backend, host, port, API key, timeout, model filter and daily usage limits. Run as many as you want: a local llama.cpp box, an Ollama instance, a vLLM moderation server and a remote Fireworks/OpenAI account can all coexist under one provider.
- **Models** (`ai_universal_model`) — discovered automatically from each server and persisted as config entities, so they are exportable, deployable and overridable. Operation types (chat, embeddings, moderation, rerank, speech-to-text, text-to-speech, text-to-image) are **detected dynamically** per model. Each model also carries routing metadata (cost per 1M tokens, quality tier, context length, reasoning effort), per-model **sampling overrides** (`temperature`, `top_p`, `frequency_penalty`, `presence_penalty`, sent on every chat call — duplicate a model entity to tune the same model per use case) — all overridable in the UI — and optional **catalog features** (`tools`, `json_mode`, `reasoning`, …) when the server publishes them. Quality tiers for known model families, vendor-recommended sampling and optionally costs are prefilled from a site-overridable YAML (`definitions/model_defaults.yml`).

New to the terminology? See the [glossary](docs/glossary.md).

## Backends

Protocol-specific logic lives in **AiServerBackend plugins**. The module ships with **ten** backends:

| Backend | Typical use | Host/port | Metadata at discovery |
|---|---|---|---|
| `openai_compatible` | llama.cpp, vLLM, LM Studio, OpenAI, generic OpenAI REST | required | Context from llama.cpp/vLLM fields; costs usually empty (use `model_defaults` or the UI) |
| `ollama` | Local Ollama daemon | required (e.g. `http://127.0.0.1:11434`) | `/api/show` enrichment: context, family/capabilities; costs prefilled as **free** |
| `ollama_cloud` | ollama.com hosted | optional (fixed default) | Bare catalog; fill routing metadata manually |
| `groq` | GroqCloud (very fast inference) | fixed `api.groq.com/openai/v1` | **Live** pricing + context + `supported_features` from `/v1/models` |
| `openrouter` | 300+ models, one key | optional (fixed default) | **Live** pricing + context from catalog |
| `fireworks` | Fireworks serverless | optional (fixed default) | Hardcoded price/context table (not live) |
| `huggingface` | HF Inference Providers | optional (fixed default) | Live cheapest-provider offer + modalities |
| `litellm` | Self-hosted LiteLLM proxy | required | `/model/info` mode + costs (fallback: `/v1/models`) |
| `amazee` | amazee.ai managed LiteLLM | required (`litellm_api_url`) | Same as `litellm` (dedicated UX only) |
| `grok` | Grok / xAI | fixed `api.x.ai/v1` | Small hardcoded table for grok-2 family |

Full reference (capability detection, forms, filters, moderation parsers): [docs/servers-and-models.md](docs/servers-and-models.md).

Other modules can contribute more backends (e.g. native Anthropic/Gemini once inference dispatch lands) by dropping a plugin in `Plugin/AiServerBackend` that implements `AiServerBackendInterface` — multi-server UI and discovery come for free. See [docs/adding-a-backend.md](docs/adding-a-backend.md).

## Requirements

- Drupal 11.1+ / 12
- [AI](https://www.drupal.org/project/ai) ^1.3 (Guardrails API; content governance)
- [Key](https://www.drupal.org/project/key)

## Installation

```bash
# Recommended (beta):
composer require 'drupal/ai_provider_universal:^1.0@beta'

# Or track the development branch:
# composer require drupal/ai_provider_universal:1.0.x-dev

drush pm:enable ai_provider_universal
# optional submodules:
drush pm:enable ai_provider_universal_router ai_provider_universal_factcheck ai_provider_universal_governance
```

Release notes for **1.0.0-beta1** (changes since alpha1): [RELEASE_NOTES_1.0.0-beta1.html](RELEASE_NOTES_1.0.0-beta1.html).

### Recommended core AI patches

Two small bugs in the AI module affect this module's provider; fixes ship in `patches/` and are declared in this module's `composer.json`:

- `ai-support-optgrouped-model-options.patch` — the AI settings form rejects models presented in optgroups (this module's provider groups models by server).
- `ai-search-embeddings-engine-explode-limit.patch` — `ai_search` breaks model ids containing double underscores (used here for `server__model` ids).

Composer does **not** apply patches from dependencies by default. To apply them in your site, install [composer-patches](https://github.com/cweagans/composer-patches) and enable dependency patching:

```bash
composer require cweagans/composer-patches
composer config extra.enable-patching true
composer update drupal/ai
```

Without the patches the module works, but route/model selects in the AI settings form may not validate, and `ai_search` cannot use this module's embedding models. Upstream issues are being filed against the AI module.

## Configuration

1. Enable the module (if you haven't already — see Installation above).
2. Go to **Configuration → AI → Providers → Universal** (`/admin/config/ai/providers/universal`) and add a server: backend, host/port (local servers) or just the API key (hosted services with fixed endpoints: OpenRouter, Groq, Fireworks, Hugging Face, Ollama Cloud, Grok/xAI), timeout, optional model filter and daily usage limits. The **Test connection & list models** button previews what the server reports before you save.
3. Saving the server runs model discovery; review detected models (operation types, costs, context, catalog features) and override routing metadata if needed.
4. Select the default provider/model per operation type at **Configuration → AI → AI settings** (`/admin/config/ai/settings`).

| Path | Purpose |
|---|---|
| `/admin/config/ai/providers/universal` | Servers and discovered models |
| `/admin/config/ai/settings` | Default provider/model per operation type |
| `/admin/config/ai/providers/universal/routes` | Smart routes (router submodule) |
| `/admin/config/ai/providers/universal/routes/settings` | Classifier model and routing prompt overrides |
| `/admin/config/ai/factcheck` | Fact check settings (factcheck submodule) |
| `/admin/config/ai/factcheck/scan-profiles` | Async content-scan profiles (factcheck; used by governance review) |
| `/admin/config/ai/providers/universal/governance` | Guardrails defaults, provenance, disclosure (governance submodule) |
| `/admin/reports/ai-router-decisions` | Routing decisions report (Views) |
| `/admin/reports/ai-router-savings` | Estimated routing savings dashboard |

Discovery can be re-run any time with `drush aip:discover-models [server_id]` (alias `aipdm`) or by re-saving the server. Re-discovery **never overwrites** costs/tier/context/reasoning/sampling you set manually; catalog **features** are always refreshed from the server. Per-model enrichment failures (Ollama `/api/show`, LiteLLM `/model/info`, …) log a notice and fall back to the plain catalog entry instead of aborting discovery.

### Authentication / API keys

Servers authenticate through the [Key](https://www.drupal.org/project/key) module: create a Key entity holding the token and select it on the server. It is sent as an `Authorization: Bearer` header on every request.

- Hosted services (OpenRouter, Groq, Hugging Face, Ollama Cloud, Fireworks, Grok/xAI, amazee.ai) always require a key.
- A LiteLLM proxy started with a `master_key` requires a key for *everything*, including listing models — the connection test on the server form will fail with 401 until a valid key is selected.
- Plain local servers (llama.cpp, Ollama, LM Studio) usually **don't need a key** — leave the field empty.
- The fact check submodule needs one extra key for web evidence: a [Tavily](https://tavily.com) API key (also a Key entity), selected in the Fact Check settings — not on a server. Leave it empty to keep verification local-only.

If the connection test fails, the exact server response (e.g. `401 Unauthorized`) is logged to the `ai_provider_universal` channel: see **Reports → Recent log messages**.

### Smart routing (router submodule)

With `ai_provider_universal_router` enabled, a **Smart Route** is a virtual model — pick "Auto: \<label\>" as the provider for an operation type and each request is routed to the cheapest candidate model whose quality tier satisfies the prompt: short/simple prompts get a low tier threshold, long or reasoning-flavored prompts (code fences, "step by step", "prove", "refactor", ...) get a higher one. Candidates on a server that has hit its daily usage limit drop out automatically, so routing doubles as failover.

A route can also name a **verifier model**: before returning, that model (typically a free local one) judges the answer with a single yes/no call, and a rejection retries the request once with the best candidate — a lightweight alternative to full fact-checking that enables local-first/verify/escalate routing at zero cost.

Complexity classification is heuristic by default (free), and can optionally delegate to a **local classifier model** — including a fine-tuned one — for the prompts heuristics consider simple: pick it at **Smart routing settings** (`/admin/config/ai/providers/universal/routes/settings`; empty = heuristics only, any classifier failure falls back to heuristics). Routes can also require **catalog features** (`tools`, `reasoning`, ...) so only capable candidates are considered.

Every tunable **LLM prompt is admin-editable**: the six fact-check prompts on the Fact check settings form and the two routing prompts (classifier, route verifier) on the Smart routing settings form. Empty fields keep the shipped defaults; overrides are validated to preserve the sprintf token order.

Every decision lands in a log table exposed to Views: a packaged **AI routing decisions** report at `/admin/reports/ai-router-decisions` shows timestamp, complexity, chosen model, token estimate and chosen vs. worst-case cost, with exposed filters. The savings dashboard at **Reports → Routing decisions** (`/admin/reports/ai-router-savings`) aggregates estimated spend vs. always using the priciest candidate.

Full details — decision algorithm, route configuration, fact-check escalation, dashboard reference: [docs/smart-routing.md](docs/smart-routing.md).

### Usage limits

Each **server** can carry daily request/token limits — that is where the account/budget actually lives (OpenRouter credits, amazee.ai budget, a LiteLLM master key). Usage is tracked per model per day; the day rolls over at midnight in the site's default timezone. Enforcement lives in the Smart Router submodule: over-limit servers are skipped by routes (failover to another provider) and reject direct calls until the day rolls over. A limit of `0` deliberately blocks the server for the rest of the day. An optional **alert threshold** (default 80%) and **limit grace** dispatch `UsageThresholdEvent`s you can subscribe to for mail/Slack/ECA.

For rules beyond daily limits (business hours, per-role quotas), subscribe to the **pre-call gate**: `ModelPreCallEvent` fires before every inference call and lets any module block the call or swap the model. Its companions: `ModelPostCallEvent` fires after every successful chat call with token usage and latency (custom telemetry, cost alerting), and `ModelsDiscoveredEvent` lets you enrich or correct the discovered model set before it is persisted. See the events table in [docs/smart-routing.md](docs/smart-routing.md).

Full details — enforcement model, thresholds, event reference, pre-call gate: [docs/usage-limits.md](docs/usage-limits.md).

### Fact check & content scan (fact check submodule)

With `ai_provider_universal_factcheck` enabled, answers from smart routes can be verified claim by claim (and escalated to a better model when verification fails), and every node gets a **Content scan** tab running four on-demand checks: fact check, readability, AI likelihood and plagiarism.

Evidence comes from a cascade — your own AI Search index first, then the web via [Tavily](https://tavily.com) — curated by an optional **Trusted site** content type with per-domain reputation (−10 to 10): positive domains are preferred sources; claims echoed by negative-reputation domains are marked tainted and lower the score. Two bundled recipes set it up: `factcheck_trusted_sites` (the content type) and, optionally, `factcheck_trusted_sites_seeds` (example entries, created unpublished — reputation is your editorial call).

Full details — pipeline, scoring, recipes, settings reference, extension points: [docs/factcheck.md](docs/factcheck.md).

### Content governance (governance submodule)

Optional `ai_provider_universal_governance` for **EU AI Act Art. 50-style
transparency**: default Guardrail attach on this provider’s calls, AI-origin
**provenance events**, and two post-generate Guardrail plugins (visible
disclosure suffix + machine-readable origin marker). All off/empty by default
— enable the submodule and configure at
`/admin/config/ai/providers/universal/governance`. Empty config is a no-op;
`node_save` is never blocked.

For Art. 50-style disclosure on published content, the **`ai_content_disclosure`
recipe** ships field storages (`field_ai_origin`, `field_ai_disclosure_req`,
`field_ai_exemption` + audit fields); attach them to your content types and the
governance submodule renders a machine-readable `<meta name="ai-origin">` tag
plus a visible disclosure label on the node page — exemptions
(`editorial_responsibility`, `artistic_creative_satirical`, `assistive_edit`)
are asserted by editors or ECA, never inferred.

Async **content review** (scan profiles, thresholds, content-review events for
ECA) lives in the **factcheck** submodule (`/admin/config/ai/factcheck/scan-profiles`);
governance does not own that queue.

Full design, Art. 50 mapping and phases:
[docs/content-governance.md](docs/content-governance.md).

## Relation to ai_provider_llama_cpp

This module is the evolution of [ai_provider_llama_cpp](https://www.drupal.org/project/ai_provider_llama_cpp) 2.x, which is no longer maintained. Both can be installed side by side; there is no automated migration — re-create your servers here and remove the old provider when done.
