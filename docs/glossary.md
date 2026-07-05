# Glossary

| Term | Meaning |
|---|---|
| **Server** (`ai_universal_server`) | A config entity describing one AI endpoint: backend, host/port (or a fixed default endpoint), API key, timeout, model filter and daily usage limits. The unit that owns an account/budget. |
| **Model** (`ai_universal_model`) | A config entity for one model discovered on a server. Id format `<server>__<model>`. Carries detected + overridden operation types and routing metadata. |
| **Backend** (`AiServerBackend` plugin) | Protocol adapter for one kind of server (openai_compatible, fireworks, openrouter, litellm, amazee, huggingface, ollama_cloud). Owns base URI, model listing and capability/metadata detection. |
| **Discovery** | The write path that asks a backend for its model catalog and persists the result as model entities. Runs on server save or `drush aip:discover-models` (`aipdm`). |
| **Operation type** | What a model can do, in AI-module terms: `chat`, `embeddings`, `moderation`, `rerank`, `speech_to_text`, `text_to_speech`, `text_to_image`. Detected per model, overridable in the UI. |
| **Model filter** | Comma-separated globs on the server (`llama3*, !*old*`) restricting which discovered models are kept. |
| **Routing metadata** | Per-model fields smart routing reads: cost per 1M input/output tokens (USD), quality tier, context length, reasoning effort. |
| **Quality tier** | Subjective 1–5 capability rating (1 Minimal → 5 Frontier). Prefilled for known model families at discovery; unrated models count as tier 3 in routing. |
| **Smart route** (`ai_universal_route`) | Router submodule config entity: a virtual model (`route__<id>`, shown as "Auto: label") that resolves each request to the cheapest candidate whose tier satisfies the prompt's complexity. |
| **Candidate** | A model listed on a route as eligible for selection. |
| **Simple/complex tier** | The route's two thresholds: short/simple prompts must meet the simple tier, long or reasoning-flavored prompts the complex tier. |
| **Escalation** | Fact-check driven retry: when a routed answer fails verification, the request is re-run once on the best candidate. |
| **Usage limit** | Per-server daily request/token caps. Empty = unlimited, `0` = pause the server. Enforced by the router submodule; resets at local midnight (site timezone). |
| **Alert threshold / limit grace** | Percentage of a limit that triggers `UsageThresholdEvent::ALERT`, and the percentage a limit may be exceeded before blocking (`EXHAUSTED`). |
| **Pre-call gate** | `ModelPreCallEvent`, dispatched before every inference call: subscribers can block the call or swap the model. |
| **Claim / verdict** | Fact-check units: an answer is split into claims, each verified against evidence to SUPPORTED / CONTRADICTED / NO_EVIDENCE. |
| **Trusted site** | Optional content type with per-domain reputation (−10 to 10) curating web evidence: positive preferred, negative excluded/tainting. |
| **Content scan** | Node tab (factcheck submodule) with four on-demand checks: fact check, readability, AI likelihood, plagiarism. |
