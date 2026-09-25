# Model capabilities: filtering and the coming `decision` operation

Status: **implemented** (2026-09-25) for chat capabilities. The `decision` part (last section) waits for AI core.

This is a decision record: what changes, why, what was discussed and rejected, and what else it touches. Update it when the change lands or when AI core's `decision` operation moves.

## Why this came up

AI core is getting a `decision` operation type (Jev-style models that answer typed questions with probabilities instead of text). The work is in [ai !2046](https://git.drupalcode.org/project/ai/-/merge_requests/2046), paired with `ai_provider_typesafeai` 1.1.x, and Marcus Johansson targets AI 1.6.0. As of 2026-09-24 the MR requires every decision provider to:

- declare, **per model**, which of 11 new `AiModelCapability::Decision*` cases it supports (`decision_yes_no`, `decision_choice`, `decision_score`, multiple questions, criteria, structured descriptions, ...), plus optional limits (max options, max levels, max questions; `NULL` means unknown, not unlimited);
- validate every request against that declaration before it is sent, including bridges from other operation types (our `textClassification()` and `decide()`);
- filter `getConfiguredModels()` and `isUsable()` by those capabilities. An undeclared capability counts as unsupported.

The MR's shapes are expected to change once or twice before release, so the `decision` operation itself is **not** implemented here yet. `decide()` keeps riding chat (see the `ponytail:` note in `UniversalProvider::decide()`).

What we can do now is the part that does not depend on the MR: AI core already asks providers for capabilities, and we ignore the question.

## Current state (before the change)

Per model, `ai_universal_model` already stores:

| Data | Written by | Manual override |
|---|---|---|
| Operation types | discovery (`detected_operation_types`) | yes: the "Operation types" checkboxes (`operation_types`); `getEffectiveOperationTypes()` prefers them |
| Catalog features (`tools`, `vision`, `reasoning`, `response_format`, ...) | discovery only (`supported_features`), overwritten on every re-discovery | no, shown read-only on the server form |

`UniversalProvider::getConfiguredModels($operation_type, $capabilities)` and `isUsable($operation_type, $capabilities)` filter by operation type only. **The `$capabilities` argument is ignored**, so every model reports every capability.

Who asks AI core providers for capabilities today:

| Caller | Effect of us ignoring it |
|---|---|
| `AiProviderPluginManager::getSimpleProviderModelOptions()` / `getProvidersForOperationType()` (model selects that require e.g. vision) | every chat model is offered |
| `AiProviderClientBase::modelSupportsCapabilities()` | always TRUE for any listed model |
| `AiProviderClientBase::loadModelConfig()` (fills `chat_*` flags in model config) | every flag TRUE |
| `ai_automators` `RuleBase` (vision check before sending images) | images are sent to models that cannot read them |

## Change

One place: the model filter behind `getConfiguredModels()` and `isUsable()`. No new config fields, no schema change, no update hook.

When `$capabilities` is not empty, a model is dropped only when we **know** it lacks a capability:

Checked in this order; the first rule that applies decides:

| Model | Result |
|---|---|
| AI core's stored model settings (`ai.settings:models`) have a value for the capability | that value: TRUE keeps, FALSE drops |
| Has a manual operation-type override | always kept (the user vouched for it) |
| Discovery reported features, including the mapped one | kept |
| Discovery reported features, but not the mapped one | **dropped** |
| Discovery reported no features at all (server without a catalog) | kept, as today |
| Capability we have no mapping for | kept, as today |

Mapping from AI core capabilities to our catalog features (starting set; extend as backends report more). Today only the `groq` and `anthropic` backends report features, so every other backend is unaffected. `groq` publishes vision as an input modality, so its discovery adds `vision` when `input_modalities` contains `image`. `anthropic` reports `tools` and `vision` but no JSON mode, which matches its backend: it does not send `response_format`.

| `AiModelCapability` | Feature |
|---|---|
| `chat_tools` | `tools` |
| `chat_with_image_vision` | `vision` |
| `chat_json_output` | `json_mode` |
| `chat_structured_response` | `structured_outputs` |
| `decision_*` (later) | same key: the `typesafe` backend reports them at discovery |

Filtering by operation type still happens first, exactly as today. Smart routes (virtual `route.*` models) are not filtered by capability.

## Debate: alternatives considered

1. **Ignore capabilities until `decision` lands.** Rejected: the gap already produces wrong answers today (vision table above), and the `decision` contract rests on the same filter.
2. **Strict filter (undeclared = unsupported, the MR's rule) for chat too.** Rejected: many local servers publish no feature catalog; every model on them would vanish from capability-filtered selects. The strict rule is kept only for `decision_*`, where our own backend declares everything.
3. **A new per-model "capabilities" override field** (detected + override + effective, mirroring operation types). Works, but adds a config field, schema and form section for a problem not yet seen. Deferred: add it if a wrong detection shows up that the rule below cannot fix.
4. **A global "filter models by capability" switch on the provider.** Simple, but all or nothing. Rejected in favour of 5.
5. **Chosen: reuse the operation-type override as the escape hatch.** Ticking operation types by hand on a model means "trust me about this model", so capability filtering skips it. No new configuration. Cost: the checkbox now carries two meanings; its description on the server form must say so.
6. **Also chosen: honour AI core's own capability override.** AI core already stores per-model settings, capabilities included, in `ai.settings:models` (`[provider][operation_type][model_id]`), edited through the provider's model form (`AbstractModelFormBase`). `loadModelConfig()` prefers a stored entry over asking the provider. Two limits, and how we handle them:
   - *Only `loadModelConfig()` reads it; `getConfiguredModels()`, `modelSupportsCapabilities()` and `isUsable()` never do.* We read `getModelsConfig()` inside our filter, so a stored value wins there too. This is plain config read with AI core's own getter: no core patch, no new storage, no recursion (it does not call `getConfiguredModels()`).
   - *For providers with predefined models (ours) the model form is locked unless `$settings['ai_override_models'] = TRUE;` is in `settings.php`.* So this path is for sites that already use AI core's override; option 5 stays as the override that needs no `settings.php` change.

   Why it was chosen: it is where anyone who knows AI core looks first, and it is per capability (it can also say "no").

   **The dot problem, and how it is solved.** `AiModelSettingsForm::submitForm()` stores the entry under the submitted model id as a config key, and Drupal config keys cannot contain a dot. Every `ai_universal_model` id is `<server>.<model>`, so out of the box saving the form throws `ConfigValueException` ("key contains a dot which is not supported"); the kernel test found it. It was briefly dropped for that reason, then solved without touching AI core: `Utility\CoreModelConfig` owns the key (dots become `__`, so `laya.laya` is stored as `laya__laya`) and both ends of AI core's form already go through the provider:
   - `validateModelsForm()` swaps the submitted id for the encoded key before AI core saves it (AI core's own validation only allows letters, digits, `-` and `_`, so the encoded key also passes it);
   - `loadModelConfig()` reads the encoded entry back and shows the real id.
   The filter reads the same entry through `CoreModelConfig::read()`. The key is never decoded (`__` may appear in a real id); every lookup starts from the raw id.

   Limits, verified against AI 1.4.3 by submitting the real form:
   - For providers with predefined models (ours) the form is locked unless `$settings['ai_override_models'] = TRUE;` is in `settings.php`. Locked, its checkboxes are disabled.
   - Saving the form stores **every** capability of the model, not only the ones changed, so from then on that model ignores discovery for all of them. Deleting the entry from the same form returns it to discovery.

   Why both 5 and 6: 6 is where anyone who knows AI core looks first, and it is per capability (it can also say "no"); 5 needs no settings flag and lives next to the rest of our model settings. Order: 6, then 5, then discovery.

## Impact and risks

- **Sites that save model settings through AI core's form** (with `ai_override_models`) now see them respected everywhere, not only in `loadModelConfig()`; a capability left unchecked hides the model.
- **Behaviour change:** selects that require a capability (vision, tools, JSON) will show fewer models on servers that publish a catalog. That is the point, but a site may notice a model "disappearing". Fix: tick its operation types by hand.
- **Wrong or partial catalogs:** a backend that reports features but omits one the model has will hide that model for that capability until the override is ticked.
- **`loadModelConfig()` flags** become real instead of all TRUE; code reading `chat_*` flags from model config gets honest values.
- **Not touched:** chat execution, routing, usage limits, the pre-call gate, discovery, stored config.

## Later: when `decision` lands in AI core

1. `typesafe` discovery reports the `decision_*` features (all 11 for Jev; Laya only what is verified).
2. Optional submodule `ai_provider_universal_decision`: provider implementing `DecisionInterface`; `getDecisionCapabilities()` builds `DecisionCapabilities` from the model's features; `validateDecisionInput()` delegates to `DecisionRequestValidator`.
3. `decide()` and `textClassification()` validate before transport; `nextRouteCandidate()` skips candidates that do not cover the request.
4. `decide()` moves from the chat bridge to the `decision` operation, keeping its public signature.

Open upstream questions that may change this plan: where the enum cases live, and whether enforcement moves into `ProviderProxy` (our `decide()` bypasses the proxy, so provider-side validation must stay).
