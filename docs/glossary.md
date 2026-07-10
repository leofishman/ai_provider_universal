# Glossary

| Term | Meaning |
|---|---|
| **Server** (`ai_universal_server`) | A config entity describing one AI endpoint: backend, host/port (or a fixed default endpoint), API key, timeout, model filter and daily usage limits. The unit that owns an account/budget. |
| **Model** (`ai_universal_model`) | A config entity for one model discovered on a server. Id format `<server>__<model>`. Carries operation types, routing metadata, optional catalog features and sampling overrides. |
| **Backend** (`AiServerBackend` plugin) | Protocol adapter for one kind of server. Shipped ids: `openai_compatible`, `ollama`, `ollama_cloud`, `groq`, `openrouter`, `fireworks`, `huggingface`, `litellm`, `amazee`, `grok`. Owns base URI, model listing and capability/metadata detection. |
| **Discovery** | The write path that asks a backend for its model catalog and persists the result as model entities. Runs on server save or `drush aip:discover-models` (`aipdm`). `ModelsDiscoveredEvent` fires before persistence. |
| **Operation type** | What a model can do, in AI-module terms: `chat`, `embeddings`, `moderation`, `rerank`, `speech_to_text`, `text_to_speech`, `text_to_image`. Detected per model, overridable in the UI. |
| **Supported features** (`supported_features`) | Catalog capability flags on a model (e.g. `tools`, `json_mode`, `structured_outputs`, `reasoning`) when the backend publishes them (e.g. Groq). Refreshed every discovery; read-only in the UI. Routes can require them. Distinct from **reasoning effort**. |
| **Required catalog features** | Per-route checklist: candidates must report every checked feature. Models without published features (most local servers) drop out when any feature is required. |
| **Model filter** | Comma-separated globs on the server (`llama3*, !*old*`) restricting which discovered models are kept. |
| **Routing metadata** | Per-model fields smart routing reads: cost per 1M input/output tokens (USD), quality tier, context length. Reasoning effort and sampling are applied at chat time, not by the route decider. |
| **Sampling overrides** | Optional per-model `temperature`, `top_p`, `frequency_penalty`, `presence_penalty` sent on every chat call. Prefilled from `model_defaults.yml` for known families when unset. |
| **Quality tier** | Subjective 1–5 capability rating (1 Minimal → 5 Frontier). Prefill order: backend → family/size regexes → parameter-count heuristic → price-band fallback for live catalogs; unrated models count as tier 3 in routing. |
| **Model defaults** | `definitions/model_defaults.yml`: tier guesses, optional costs, vendor sampling recommendations, price-band map. Site override via `$settings['ai_provider_universal_model_defaults']`. |
| **Reasoning effort** | Optional per-model `none` / `low` / `medium` / `high`, sent as OpenAI-compatible `reasoning_effort` on chat. Not the same as a catalog `reasoning` feature flag. |
| **Prompt overrides** | Admin-editable LLM templates (factcheck + routing classifier/verifier). Empty = shipped default; sprintf token order is validated. |
| **Smart route** (`ai_universal_route`) | Router submodule config entity: a virtual model (`route__<id>`, shown as "Auto: label") that resolves each request to the cheapest candidate whose tier (and optional features) satisfy the prompt. |
| **Candidate** | A model listed on a route as eligible for selection. |
| **Simple/complex tier** | The route's two thresholds: short/simple prompts must meet the simple tier, long or reasoning-flavored prompts the complex tier. |
| **Escalation** | Fact-check driven retry: when a routed answer fails verification, the request is re-run once with the best candidate. |
| **Usage limit** | Per-server daily request/token caps. Empty = unlimited, `0` = pause the server. Enforced by the router submodule; resets at local midnight (site timezone). |
| **Alert threshold / limit grace** | Percentage of a limit that triggers `UsageThresholdEvent::ALERT`, and the percentage a limit may be exceeded before blocking (`EXHAUSTED`). |
| **Pre-call gate** | `ModelPreCallEvent`, dispatched before every inference call: subscribers can `block()` the call or `setModelId()` swap. |
| **Post-call / discovery events** | `ModelPostCallEvent` (after successful chat: tokens, latency); `ModelsDiscoveredEvent` (mutate the discovered set before save). |
| **Claim / verdict** | Fact-check units: an answer is split into claims, each verified against evidence to SUPPORTED / CONTRADICTED / NO_EVIDENCE. |
| **Trusted site** | Optional content type with per-domain reputation (−10 to 10) curating web evidence: positive preferred, negative excluded/tainting. |
| **Content scan** | Node tab (factcheck submodule) with four on-demand checks: fact check, readability, AI likelihood, plagiarism. |
| **Factcheck notification** | `FactcheckNotificationEvent` fired by `AdminNotifier` on scan run / settings change. Optional default mail via `notify_email`; subscribers (ECA, Message Notify, …) can add channels or suppress mail. |
