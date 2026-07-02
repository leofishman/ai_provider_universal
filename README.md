# AI Provider: Universal

A universal, multi-instance AI provider for the [Drupal AI module](https://www.drupal.org/project/ai).

Unlike single-endpoint providers, this module models your AI infrastructure as
**config entities**:

- **Servers** (`universal_server`) — each server is an independent endpoint
  with its own host, port, API key, timeout and model filter. Run as many as
  you want: a local llama.cpp box, an Ollama instance, a vLLM moderation
  server and a remote Fireworks/OpenAI account can all coexist under one
  provider.
- **Models** (`universal_model`) — discovered automatically from each server
  and persisted as config entities, so they are exportable, deployable and
  overridable. Operation types (chat, embeddings, moderation, rerank,
  speech-to-text, text-to-image) are **detected dynamically** per model and
  can be overridden per model in the UI.

## Backends

Protocol-specific logic lives in **ServerBackend plugins**. The module ships
with two backends:

- `fireworks` — Fireworks AI serverless inference: fixed default endpoint,
  Fireworks-specific capability detection, and published pricing + context
  lengths prefilled at discovery for smart routing.
- `openai_compatible` — llama.cpp, Ollama, vLLM, LM Studio, LiteLLM,
  Fireworks, OpenAI, and anything else speaking the OpenAI REST protocol.
  Capability detection uses llama.cpp's per-model `status.args` (router
  mode), HuggingFace `pipeline_tag` lookup for `--hf-repo` models, and
  model-name heuristics.
- `openrouter` — OpenRouter unified API (openrouter.ai): 300+ models from
  OpenAI, Anthropic, Google, Meta and others behind one endpoint. Fixed
  default endpoint, capability detection from the catalog's
  `architecture.output_modalities`, and pricing + context length prefilled
  from the live catalog for smart routing (no hardcoded price table).
  OpenRouter's embedding models live on a separate catalog endpoint and
  are not discovered yet — see ROADMAP.

Other modules can contribute native backends (e.g. Anthropic or Gemini) by
dropping a plugin in `Plugin/ServerBackend` that implements
`ServerBackendInterface` — model discovery, capability detection and the
multi-instance UI come for free. See
[docs/adding-a-backend.md](docs/adding-a-backend.md) for a contributor
guide with a full walkthrough.

## Requirements

- Drupal 10.2+ / 11 / 12
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

Two small bugs in the AI module affect this provider; fixes ship in
`patches/` and are declared in this module's `composer.json`:

- `ai-support-optgrouped-model-options.patch` — the AI settings form
  rejects models presented in optgroups (this provider groups models by
  server).
- `ai-search-embeddings-engine-explode-limit.patch` — `ai_search` breaks
  model ids containing double underscores (used here for
  `server__model` ids).

Composer does **not** apply patches from dependencies by default. To apply
them in your site, install [composer-patches](https://github.com/cweagans/composer-patches)
and enable dependency patching:

```bash
composer require cweagans/composer-patches
composer config extra.enable-patching true
composer update drupal/ai
```

Without the patches the provider works, but route/model selects in the AI
settings form may not validate, and `ai_search` cannot use this provider's
embedding models. Upstream issues are being filed against the AI module.

## Setup

1. Enable the module.
2. Go to **Configuration → AI → Providers → Universal** and add a server
   (host, port, optional API key as a Key entity).
3. Saving the server runs model discovery; review detected models and adjust
   per-model operation types if needed.
4. Select provider/models per operation type in the AI module settings.

Discovery can be re-run any time with `drush aip:discover-models
[server_id]` (alias `aipdm`) or by re-saving the server.

## Relation to ai_provider_llama_cpp

This module is the evolution of
[ai_provider_llama_cpp](https://www.drupal.org/project/ai_provider_llama_cpp)
2.x, which is no longer maintained. Both can be installed side by side;
there is no automated migration — re-create your servers here and remove
the old provider when done.
