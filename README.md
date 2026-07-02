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
with one backend:

- `openai_compatible` — llama.cpp, Ollama, vLLM, LM Studio, LiteLLM,
  Fireworks, OpenAI, and anything else speaking the OpenAI REST protocol.
  Capability detection uses llama.cpp's per-model `status.args` (router
  mode), HuggingFace `pipeline_tag` lookup for `--hf-repo` models, and
  model-name heuristics.

Other modules can contribute native backends (e.g. Anthropic or Gemini) by
dropping a plugin in `Plugin/ServerBackend` that implements
`ServerBackendInterface` — model discovery, capability detection and the
multi-instance UI come for free.

## Requirements

- Drupal 10.2+ / 11 / 12
- [AI](https://www.drupal.org/project/ai) ^1.2
- [Key](https://www.drupal.org/project/key)

## Setup

1. Enable the module.
2. Go to **Configuration → AI → Providers → Universal** and add a server
   (host, port, optional API key as a Key entity).
3. Saving the server runs model discovery; review detected models and adjust
   per-model operation types if needed.
4. Select provider/models per operation type in the AI module settings.

Discovery can be re-run any time with `drush universal:discover-models
[server_id]` or by re-saving the server.

## Relation to ai_provider_llama_cpp

This module is the evolution of
[ai_provider_llama_cpp](https://www.drupal.org/project/ai_provider_llama_cpp)
2.x. Both can be installed side by side; there is no automated migration —
re-create your servers here and remove the old provider when done.
