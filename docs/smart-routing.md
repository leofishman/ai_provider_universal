# Smart routing (`ai_provider_universal_router`)

A **smart route** is a virtual model that picks a real model per request: cheapest candidate that is capable enough for the prompt. Enable the submodule and manage routes at **Configuration → AI → Providers → Universal → Smart Routes** (`/admin/config/ai/providers/universal/routes`).

## What a route is

A route (`universal_route` config entity, `modules/ai_provider_universal_router/src/Entity/UniversalRoute.php`) appears in every AI settings model dropdown as `Auto: <label>`, grouped under a **"Smart Routing"** optgroup, with the internal model id `route__<route id>`. Selecting it as the provider for an operation type means every request to that operation type is decided per-request instead of pinned to one model.

| Field | Meaning | Default |
|---|---|---|
| Operation type | Which operation this route serves (chat, embeddings, moderation, rerank). | `chat` |
| Candidate models | Explicit model pool. Empty = every model that supports the operation type. | empty |
| Minimum tier for simple prompts | Quality tier (1–5) a candidate must reach for a prompt classified as simple. | 2 |
| Minimum tier for complex prompts | Quality tier a candidate must reach for a prompt classified as complex. | 4 |
| Fact-check answers and escalate on failure | Chat-only, requires the factcheck submodule. See [Fact-check escalation](#fact-check-escalation) below. | off |
| Minimum support score | Fraction of claims that must be SUPPORTED before accepting the answer. | 0.7 |

The candidate checkbox list is filtered to the selected operation type and rebuilds via AJAX when you change it; each option shows the model's tier, input cost, and effective operation types (with `⚙ (detected: ...)` when you've overridden them — see [docs/servers-and-models.md](servers-and-models.md#model-capability-overrides)).

## How `RouteDecider` picks a model

All decision logic lives in `modules/ai_provider_universal_router/src/Service/RouteDecider.php`, called by the provider (`UniversalProvider::chat/embeddings/rerank/moderation`) whenever the resolved model id starts with `route__`.

1. **Classify the prompt** (`classify()`): complex if the estimated token count exceeds **1500**, or the text matches reasoning/code cues —
   ```` ``` ````, "step by step", "prove", "derive", "theorem", "refactor",
   "architect" (case-insensitive). Otherwise simple. Token count is a rough
   `strlen / 4` estimate (`estimateTokens()`), not an exact tokenizer.
2. **Pick the required tier**: the route's simple or complex tier,
   depending on step 1.
3. **Load candidates** (`candidateModels()`): the route's explicit list, or
   every model, filtered to those supporting the operation type — and with
   one more cut: **models on a server that has exhausted its daily usage
   limit are dropped from the pool**, so a route naturally fails over to
   another provider once a server maxes out (see
   [docs/usage-limits.md](usage-limits.md)).
4. **Filter eligible candidates**: a model survives if (a) its context
   length can fit the estimated prompt tokens **+ 512 assumed output
   tokens** (models with no known context length are never excluded on this
   basis), and (b) its quality tier (default 3 when unrated) is at least
   the required tier from step 2.
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
models win ties against unrated remote ones unless you set explicit costs.

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

**Configuration → AI → Providers → Universal → Smart Routes → Routing
decisions** (`/admin/config/ai/providers/universal/routes/log`) shows the
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

## Known limitations

- Complexity classification is a fixed heuristic (token threshold + regex
  cues), not a learned or configurable classifier. A classifier-based
  alternative is on the roadmap.
- Token estimates are `chars / 4`, not a real tokenizer — good enough for
  relative cost comparison, not for exact context-fit guarantees near a
  model's limit.
- No time-of-day pricing: a route can't yet prefer a model only during its
  off-peak pricing window (also on the roadmap).
