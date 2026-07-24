# Changelog

## Unreleased

- **Native (non-OpenAI) inference**: backends can now own chat execution through the opt-in `AiInferenceBackendInterface`, so a service that does not speak the OpenAI REST protocol works end to end instead of only appearing in the catalog. Opt-in and additive — backends that do not implement it, including ones contributed by other modules, are dispatched exactly as before. The pre-call gate, per-server usage limits, usage recording, smart routing, fact check and governance stay shared by both paths.
- **`anthropic` backend** (native Messages API): system-prompt hoisting, multi-turn content blocks, tool calling both ways, vision and PDF input, structured output emulated with a forced tool, extended thinking driven by the model's reasoning effort, SSE streaming and cache-aware token accounting. Per-model extra request parameters are forwarded verbatim, so prompt caching, server-side tools and `service_tier` are reachable from the UI. Discovery reads the paginated `/v1/models` catalog and prefills price, context and catalog features per Claude generation. Mapping is covered by kernel tests with mocked HTTP; not yet verified against the live API.
- **Non-chat operations on a native backend are refused with an explanation** (`AiMissingFeatureException`) instead of being sent over the OpenAI protocol to a server that does not speak it — previously a confusing 404 from a non-existent endpoint.
- **Provider-native request parameters**: new per-model **Extra request parameters (YAML)** field, merged verbatim into every chat request. Reaches parameters the module does not model itself — most usefully a provider's built-in tools (OpenAI `tools: [{type: web_search}]`) — with no code in the calling module. Documented presets in `definitions/extra_params.yml` (OpenAI web search, OpenRouter web plugin, xAI Live Search). `model`, `messages`, `stream` and `stream_options` are stripped. See [#3613029](https://www.drupal.org/project/ai_provider_openai/issues/3613029).
- **Re-discovery no longer deletes hand-made model duplicates**: a second model entity for the same raw model (the supported way to run one model under two configurations) survives as long as the server still offers that model. Previously every re-discovery deleted it.

## 1.0.0-beta2 — 2026-07-15

- **Content governance (EU AI Act Art. 50)**: new `ai_provider_universal_governance` submodule — AI-origin provenance events, disclosure rendering (`<meta name="ai-origin">` + visible label), Guardrail plugins (disclosure suffix, origin marker, fact-check, AI-likelihood), default Guardrail set attach. Scheduled content review via factcheck scan profiles.
- **Recipes** (under `recipes/`, applied with core's recipe runner):
  - `ai_content_governance_starter` — one-shot Art. 50 setup: disclosure fields, governance + factcheck enabled, `editorial_light` scan profile.
  - `ai_content_disclosure` — AI-origin/disclosure/exemption fields on Article only.
  - `factcheck_trusted_sites` — trusted-site content type (domain + reputation) for fact-check web evidence.
  - `factcheck_trusted_sites_seeds` — optional example trusted-site nodes (unpublished).
- **No more drupal/ai patches**: model IDs now use a dot separator (`server.model`, routes `route.<id>`) and model selects are flat `model_id => label` maps, so AI core and `ai_search` resolve Universal models unpatched. Fixes [#3611069](https://www.drupal.org/project/ai_provider_universal/issues/3611069) ("Universal | Array" in the embeddings engine select). Update 10102 migrates existing model IDs and stored references — run `drush updb`.

Full notes: [RELEASE_NOTES_1.0.0-beta2.html](RELEASE_NOTES_1.0.0-beta2.html)

## 1.0.0-beta1

Full notes: [RELEASE_NOTES_1.0.0-beta1.html](RELEASE_NOTES_1.0.0-beta1.html)

## 1.0.0-alpha1

Initial release: multi-server provider with backend plugins, model discovery, smart routing, fact check and usage limits.
