# AI Provider: Universal

A universal, multi-instance AI provider for the [Drupal AI module](https://www.drupal.org/project/ai).

Unlike single-endpoint providers, this module models your AI infrastructure as **config entities**:

- **Servers** (`ai_universal_server`) — each server is an independent endpoint with its own backend, host, port, API key, timeout, model filter and daily usage limits. Run as many as you want: a local llama.cpp box, an Ollama instance, a vLLM moderation server and a remote Fireworks/OpenAI account can all coexist under one provider.
- **Models** (`ai_universal_model`) — discovered automatically from each server and persisted as config entities, so they are exportable, deployable and overridable. Operation types (chat, embeddings, moderation, rerank, speech-to-text, text-to-image) are **detected dynamically** per model, and each model carries routing metadata (cost per 1M tokens, quality tier, context length, reasoning effort) — all overridable per model in the UI. Quality tiers for known model families (and optionally costs) are prefilled from a site-overridable YAML (`definitions/model_defaults.yml`).

New to the terminology? See the [glossary](docs/glossary.md).

## Backends

Protocol-specific logic lives in **AiServerBackend plugins**. The module ships with seven backends:

- `fireworks` — Fireworks AI serverless inference: fixed default endpoint, Fireworks-specific capability detection, and published pricing + context lengths prefilled at discovery for smart routing.
- `openai_compatible` — llama.cpp, Ollama, vLLM, LM Studio, LiteLLM, Fireworks, OpenAI, and anything else speaking the OpenAI REST protocol. Capability detection uses llama.cpp's per-model `status.args` (router mode), HuggingFace `pipeline_tag` lookup for `--hf-repo` models, and model-name heuristics.
- `litellm` — LiteLLM proxy servers. Discovery uses LiteLLM's `/model/info` endpoint: operation types from the structured `mode` field, per-token costs and context window read live — falling back to the plain OpenAI catalog when the key cannot read `/model/info`.
- `amazee` — **amazee.ai** (managed, region-pinned LiteLLM): same protocol as `litellm`, shipped as its own backend so it appears with amazee-specific guidance in the server form. Point the host at your private `litellm_api_url` and use your amazee.ai key.
- `openrouter` — OpenRouter unified API (openrouter.ai): 300+ models from OpenAI, Anthropic, Google, Meta and others behind one endpoint. Fixed default endpoint, capability detection from the catalog's `architecture.output_modalities`, and pricing + context length prefilled from the live catalog for smart routing (no hardcoded price table). OpenRouter's embedding models live on a separate catalog endpoint and are not discovered yet — see ROADMAP.
- `huggingface` — Hugging Face Inference Providers (router.huggingface.co): capability detection from the catalog's output modalities, pricing prefilled from the cheapest live provider offer per model.
- `ollama_cloud` — Ollama Cloud (ollama.com): plain catalog with bare model ids; routing metadata is filled in manually.
- `grok` — Grok by xAI: fixed endpoint, basic metadata for grok-2 family.

Full catalog — default endpoints, capability detection sources, pricing/context prefill — and the server configuration reference: [docs/servers-and-models.md](docs/servers-and-models.md).

Other modules can contribute native backends (e.g. Anthropic or Gemini) by dropping a plugin in `Plugin/AiServerBackend` that implements `AiServerBackendInterface` — model discovery, capability detection and the multi-instance UI come for free. See [docs/adding-a-backend.md](docs/adding-a-backend.md) for a contributor guide with a full walkthrough.

## Requirements

- Drupal 11.1+ / 12
- [AI](https://www.drupal.org/project/ai) ^1.2
- [Key](https://www.drupal.org/project/key)

## Installation

```bash
composer require drupal/ai_provider_universal:1.0.x-dev
drush pm:enable ai_provider_universal
# optional submodules:
drush pm:enable ai_provider_universal_router ai_provider_universal_factcheck
```

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

## Setup

1. Enable the module (if you haven't already — see Installation above).
2. Go to **Configuration → AI → Providers → Universal** and add a server: backend, host/port (local servers) or just the API key (hosted services), timeout, optional model filter and daily usage limits. The **Test connection & list models** button previews what the server reports before you save.
3. Saving the server runs model discovery; review detected models and adjust per-model operation types and routing metadata if needed.
4. Select the default provider/model per operation type at **Configuration → AI → AI settings** (`/admin/config/ai/settings`).

Discovery can be re-run any time with `drush aip:discover-models [server_id]` (alias `aipdm`) or by re-saving the server.

### Authentication / API keys

Servers authenticate through the [Key](https://www.drupal.org/project/key) module: create a Key entity holding the token and select it on the server. It is sent as an `Authorization: Bearer` header on every request.

- Hosted services (OpenRouter, Hugging Face, Ollama Cloud, Fireworks, amazee.ai) always require a key.
- A LiteLLM proxy started with a `master_key` requires a key for *everything*, including listing models — the connection test on the server form will fail with 401 until a valid key is selected.
- Plain local servers (llama.cpp, Ollama, LM Studio) usually **don't need a key** — leave the field empty.
- The fact check submodule needs one extra key for web evidence: a [Tavily](https://tavily.com) API key (also a Key entity), selected in the Fact Check settings — not on a server. Leave it empty to keep verification local-only.

If the connection test fails, the exact server response (e.g. `401 Unauthorized`) is logged to the `ai_provider_universal` channel: see **Reports → Recent log messages**.

### Smart routing (router submodule)

With `ai_provider_universal_router` enabled, a **Smart Route** is a virtual model — pick "Auto: \<label\>" as the provider for an operation type and each request is routed to the cheapest candidate model whose quality tier satisfies the prompt: short/simple prompts get a low tier threshold, long or reasoning-flavored prompts (code fences, "step by step", "prove", "refactor", ...) get a higher one. Candidates on a server that has hit its daily usage limit drop out automatically, so routing doubles as failover.

Complexity classification is heuristic by default (free), and can optionally delegate to a **local classifier model** — including a fine-tuned one — for the prompts heuristics consider simple: set `classifier_model` in `ai_provider_universal_router.settings` to a model entity id (empty = heuristics only; any classifier failure falls back to heuristics).

Every decision lands in a log table exposed to Views: a packaged **AI routing decisions** report at `/admin/reports/ai-router-decisions` shows timestamp, complexity, chosen model, token estimate and chosen vs. worst-case cost, with exposed filters. The savings dashboard at **Smart Routes → Routing decisions** aggregates estimated spend vs. always using the priciest candidate.

Full details — decision algorithm, route configuration, fact-check escalation, dashboard reference: [docs/smart-routing.md](docs/smart-routing.md).

### Usage limits

Each **server** can carry daily request/token limits — that is where the account/budget actually lives (OpenRouter credits, amazee.ai budget, a LiteLLM master key). Usage is tracked per model per day; the day rolls over at midnight in the site's default timezone. Enforcement lives in the Smart Router submodule: over-limit servers are skipped by routes (failover to another provider) and reject direct calls until the day rolls over. A limit of `0` deliberately blocks the server for the rest of the day. An optional **alert threshold** (default 80%) and **limit grace** dispatch `UsageThresholdEvent`s you can subscribe to for mail/Slack/ECA.

For rules beyond daily limits (business hours, per-role quotas), subscribe to the **pre-call gate**: `ModelPreCallEvent` fires before every inference call and lets any module block the call or swap the model.

Full details — enforcement model, thresholds, event reference, pre-call gate: [docs/usage-limits.md](docs/usage-limits.md).

### Fact check & content scan (fact check submodule)

With `ai_provider_universal_factcheck` enabled, answers from smart routes can be verified claim by claim (and escalated to a better model when verification fails), and every node gets a **Content scan** tab running four on-demand checks: fact check, readability, AI likelihood and plagiarism.

Evidence comes from a cascade — your own AI Search index first, then the web via [Tavily](https://tavily.com) — curated by an optional **Trusted site** content type with per-domain reputation (−10 to 10): positive domains are preferred sources; claims echoed by negative-reputation domains are marked tainted and lower the score. Two bundled recipes set it up: `factcheck_trusted_sites` (the content type) and, optionally, `factcheck_trusted_sites_seeds` (example entries, created unpublished — reputation is your editorial call).

Full details — pipeline, scoring, recipes, settings reference, extension points: [docs/factcheck.md](docs/factcheck.md).

## Relation to ai_provider_llama_cpp

This module is the evolution of [ai_provider_llama_cpp](https://www.drupal.org/project/ai_provider_llama_cpp) 2.x, which is no longer maintained. Both can be installed side by side; there is no automated migration — re-create your servers here and remove the old provider when done.
