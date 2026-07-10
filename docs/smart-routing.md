# Smart routing (`ai_provider_universal_router`)

A **smart route** is a virtual model that picks a real model per request: cheapest candidate that is capable enough for the prompt. Enable the submodule and manage routes at **Configuration → AI → Providers → Universal → Smart Routes** (`/admin/config/ai/providers/universal/routes`).

## What a route is

A route (`ai_universal_route` config entity, `modules/ai_provider_universal_router/src/Entity/AiUniversalRoute.php`) appears in every AI settings model dropdown as `Auto: <label>`, grouped under a **"Smart Routing"** optgroup, with the internal model id `route__<route id>`. Selecting it as the provider for an operation type means every request to that operation type is decided per-request instead of pinned to one model.

| Field | Meaning | Default |
|---|---|---|
| Operation type | Which operation this route serves (chat, embeddings, moderation, rerank). | `chat` |
| Candidate models | Explicit model pool. Empty = every model that supports the operation type. | empty |
| Minimum tier for simple prompts | Quality tier (1–5) a candidate must reach for a prompt classified as simple. | 2 |
| Minimum tier for complex prompts | Quality tier a candidate must reach for a prompt classified as complex. | 4 |
| Required catalog features | Discovered `supported_features` (e.g. `tools`, `json_mode`, `reasoning`) every candidate must report. Models on servers that publish no features (most local servers) are excluded when any feature is required, so leave unchecked unless the route genuinely needs the capability. The options offered are the features your discovered models actually report. | none |
| Verifier model | Model that judges each answer before it is returned (one yes/no call — use a free local model). On rejection the request is retried once with the best candidate. Lighter than fact-checking and independent of the factcheck submodule; a broken verifier fails open (the answer is returned unverified, with a watchdog warning). | disabled |
| Fact-check answers and escalate on failure | Chat-only, requires the factcheck submodule. See [Fact-check escalation](#fact-check-escalation) below. | off |
| Minimum support score | Fraction of claims that must be SUPPORTED before accepting the answer. | 0.7 |

**Local-first pattern**: candidates restricted to local (cost 0) models + a local verifier model + an expensive model in the pool gives "answer locally, verify locally, pay for a remote call only when the local answer demonstrably failed" — the token-cheapest strategy when latency is not a constraint.

The candidate checkbox list is filtered to the selected operation type and rebuilds via AJAX when you change it; each option shows the model's tier, input cost, and effective operation types (with `⚙ (detected: ...)` when you've overridden them — see [docs/servers-and-models.md](servers-and-models.md#model-capability-overrides)).

## How `RouteDecider` picks a model

All decision logic lives in `modules/ai_provider_universal_router/src/Service/RouteDecider.php`, called by the provider (`UniversalProvider::chat/embeddings/rerank/moderation`) whenever the resolved model id starts with `route__`.

1. **Classify the prompt** (`ComplexityClassifier`): complex if the estimated token count exceeds **1500**, or the text matches reasoning/code cues —
   ```` ``` ````, "step by step", "prove", "derive", "theorem", "refactor",
   "architect" (case-insensitive). Otherwise simple. Token count is a rough
   `strlen / 4` estimate (`estimateTokens()`), not an exact tokenizer.

   Optionally a **local model** can adjudicate the prompts the heuristics
   consider simple: pick it at **Smart routing settings**
   (`/admin/config/ai/providers/universal/routes/settings`, or
   `classifier_model` in `ai_provider_universal_router.settings`;
   empty = heuristics only). The same form overrides the two routing
   prompts — classifier system prompt and route-verifier prompt (empty =
   shipped default; `sprintf` token order is validated, and the one-word
   reply contracts `simple`/`complex` and `yes`/`no` must be kept).
   The cheap signals still run first — length/cue hits return `complex`
   without a call — and any classifier failure falls back to the
   heuristics, so classification can never break routing. Routes
   (`route__*`) are refused as classifier to avoid recursion. Point this
   at a small local model (e.g. a fine-tuned Gemma on the dedicated
   `ollama` backend) for a learned router at zero cost.
2. **Pick the required tier**: the route's simple or complex tier,
   depending on step 1.
3. **Load candidates** (`candidateModels()`): the route's explicit list, or
   every model, filtered to those supporting the operation type, then:
   - **required catalog features**: if the route lists any (e.g. `tools`,
     `reasoning`), every candidate must report all of them in its discovered
     `supported_features` (models on servers that publish no features are
     excluded when any feature is required);
   - **usage limits**: models on a server that has exhausted a daily limit
     drop out, so the route fails over to another provider (see
     [docs/usage-limits.md](usage-limits.md)).
4. **Filter eligible candidates**: a model survives if (a) its context
   length can fit the estimated prompt tokens **+ 512 assumed output
   tokens** (models with no known context length are never excluded on this
   basis), and (b) its quality tier (default 3 when unrated) is at least
   the required tier from step 2. Discovery prefills tiers for known model
   families from `definitions/model_defaults.yml` (see
   [servers-and-models](servers-and-models.md)), so few models stay unrated.
5. **No eligible candidate?** Rather than fail the request, the route
   degrades to the best available: sort *all* candidates by tier
   (descending) then cost (ascending), log a warning, and use the top one.
6. **Eligible candidates exist**: sort by cost (ascending), tie-broken by
   tier (descending) — cheapest capable model wins.
7. **Log the decision** to `ai_universal_router_log` (see
   [Savings dashboard](#savings-dashboard)) and return the chosen model's
   entity id.

Cost estimation (`costOf()`): `(cost_input × estimated_input_tokens +
cost_output × 512 assumed_output_tokens) / 1,000,000`. A model with unset
cost fields counts as free, which is intentional — it makes local/self-hosted
models win ties against unrated remote ones unless you set explicit costs
(manually, or via the `costs:` map in your `model_defaults.yml` override —
see [servers-and-models](servers-and-models.md)).

### Escalation (`resolveBest()`)

A separate entry point used by the fact-check cascade: given a route and a
list of already-tried model ids, returns the highest-tier (then cheapest)
remaining candidate — no complexity classification, no context filtering,
just "give me the strongest option left". Also logged, with complexity
`escalated`.

## Fact-check escalation

When a route has **"Fact-check answers and escalate on failure"** enabled
(requires `ai_provider_universal_factcheck` with a checker model
configured):

1. The chosen model answers normally.
2. The answer is verified claim-by-claim
   (`ai_provider_universal_factcheck.checker`).
3. If the support score is **below** the route's minimum, the provider
   calls `resolveBest()` excluding the model just tried, and retries once
   with the strongest remaining candidate.
4. The escalated answer is returned **as-is** — it is not re-verified, to
   bound cost at one escalation per request.

This logic lives in `UniversalProvider::maybeEscalate()`, not in
`RouteDecider` itself. Full fact-check pipeline, scoring and evidence
sourcing: [docs/factcheck.md](factcheck.md).

## Savings dashboard

**Reports → Routing decisions** (`/admin/reports/ai-router-savings`, also
reachable via the action button on the Smart Routes page) shows the
last 100 routing decisions and running totals: request count, estimated
spend, and estimated savings versus always using the most expensive
candidate (`est_cost_worst - est_cost`, summed).

Backing table `ai_universal_router_log` (one row per decision):

| Column | Meaning |
|---|---|
| `route_id`, `operation_type` | Which route and operation served the request. |
| `complexity` | `simple`, `complex`, or `escalated`. |
| `est_tokens` | Estimated prompt tokens at decision time. |
| `chosen_model`, `candidates` | The winning model id and how many candidates were considered. |
| `est_cost`, `est_cost_worst` | Estimated cost of the chosen model vs. the most expensive candidate in the pool, same token estimate. |

Logging failures never break inference — write errors are caught and
logged to the `ai_provider_universal_router` channel.

**Why a dedicated log, when the AI core has `ai_observability`?** They
answer different questions and complement each other. `ai_observability`
records the call that *happened* (provider, model, real token usage,
duration) — use it for actual usage and spend. The decision log records
the *counterfactual* that only exists inside the RouteDecider at decision
time: which candidates were considered, how the prompt was classified, and
what the most expensive candidate would have cost — the data behind
"estimated savings". Neither can be derived from the other.

Routed chat calls are additionally tagged `smart_route:<route_id>` and
`route_complexity:<simple|complex>`, so `ai_observability` log entries
(which record tags) can be attributed to the route and correlated with
the decision log.

## Services and extension points

| Service | Purpose |
|---|---|
| `Drupal\ai_provider_universal_router\Service\RouteDecider` | The decision engine above. Public methods `resolve()`, `resolveBest()`, `classify()`, `estimateTokens()`, `costOf()` are usable directly if you need routing logic outside the provider. |
| `ai_provider_universal_router.decider` | Stable alias to `RouteDecider`, consumed by the main module so it has no hard dependency on this submodule's classes. |
| `Drupal\ai_provider_universal_router\Service\UsageLimitEnforcer` | Per-server limit checks; see [docs/usage-limits.md](usage-limits.md). |
| `ai_provider_universal_router.limits` | Stable alias to `UsageLimitEnforcer`. |

Both aliases exist so `ai_provider_universal` degrades gracefully
(everything works, just without routing/limit enforcement) when this
submodule is disabled.

### Events

| Event | When | Subscribers can |
|---|---|---|
| `ModelPreCallEvent` (`ai_provider_universal.model_pre_call`) | Before every inference call, after smart-route resolution — the model id is always a concrete `ai_universal_model` entity id. | Block the call (`block($reason)`: custom quota schemes, business hours, compliance) or swap the model (`setModelId()`). The usage-limit enforcement in this submodule is a plain service call, but your own policies belong here. |
| `ModelPostCallEvent` (`ai_provider_universal.model_post_call`) | After every successful chat call. | Read model id, token usage, latency (ms) and caller tags for custom telemetry, cost alerting or dashboards. Read-only; failed calls dispatch `AiExceptionEvent` instead. Provenance skips internal tool tags (`InternalChatTags`). |
| `ModelsDiscoveredEvent` (`ai_provider_universal.models_discovered`) | During discovery, after detection and defaults, before persistence. | Enrich or correct the discovered set via `getModels()`/`setModels()` — inject site pricing, adjust tiers, drop models — without writing a backend plugin. Manual UI edits are still never clobbered. |
| `AiExceptionEvent` (AI core) | When a provider call throws. | Implement failover: catch the failure, re-issue against another provider/model. This is the AI-core seam — the module deliberately ships no failover of its own. |

## Recommended model mix for cost / benefit (2026)

Smart routing shines when you have a **tiered pool** of models. The goal is to
send simple prompts to cheap/fast models and only pay for big models on
complex/reasoning work.

### Suggested tiers & example models (local-first)

| Tier | Typical models (quantized)          | Approx. cost (local) | Best for                          | Notes |
|------|-------------------------------------|----------------------|-----------------------------------|-------|
| 1–2  | Llama-3.2-1B/3B, SmolLM2-360M, Qwen2.5-7B, Phi-3-mini | very low            | Simple prompts, fast fact-checks | Excellent price/performance. Use as default for low-tier routes. |
| 3    | Gemma-2-27B, Qwen2.5-14B/32B, Llama-3.1-70B (Q4) | low–medium          | Most chat + factcheck checker    | Sweet spot for many sites. Bespoke-MiniCheck (tier ~3) for verification. |
| 4–5  | Qwen2.5-72B, Llama-3.1-405B (Q3/Q4), large Mixtral | medium–high         | Complex reasoning, discrepancy   | Only for high-tier or escalation. |

**Practical route examples**:
- **General chat route**: candidates = [7B tier2, 32B tier3, 70B tier4], simple=2, complex=4.
- **Factcheck route**: candidates = [MiniCheck tier3, 27B tier3, 70B tier4], simple=2, complex=3 (fact verification rarely needs frontier).
- Always include at least one specialized small model (MiniCheck, embedding rerankers) — they give huge cost savings on narrow tasks.

### API providers worth adding (if not using)

If you want higher quality at still-reasonable cost without managing GPUs:

- **Fireworks.ai** or **Together.ai** — excellent Qwen/Llama/Mixtral hosting, very competitive per-token pricing, fast.
- **Groq** — extreme speed (Llama, GPT-OSS, Qwen, …); dedicated `groq` backend with live catalog pricing + `supported_features`.
- **OpenRouter** — easy access to many providers + automatic fallback; live pricing.

Dedicated backends that feed routing metadata out of the box: `groq`, `fireworks`, `openrouter`, `huggingface`, `litellm`/`amazee`, local `ollama` (costs prefilled as free). Generic `openai_compatible` still works for any OpenAI REST endpoint (fill costs/tier manually or via `model_defaults.yml`).

## Known limitations

- The optional classifier model runs one extra local call per routed
  request for prompts that look simple; the heuristics alone are free.
- Token estimates are `chars / 4`, not a real tokenizer — good enough for
  relative cost comparison, not for exact context-fit guarantees near a
  model's limit.
- No time-of-day pricing: a route can't yet prefer a model only during its
  off-peak pricing window (also on the roadmap).
