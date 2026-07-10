# Content governance (Guardrails, review queue, provenance)

How this module (and the factcheck submodule) fit into **Drupal AI Guardrails**,
**async content review**, and **AI-origin transparency** (e.g. EU AI Act Art. 50
style disclosure). This is the product design for the `feature/content-governance`
work; items land in phases (see [ROADMAP.md](../ROADMAP.md)).

**Defaults stay empty:** without a Guardrail set, without a scan profile, and
without provenance emission, behaviour matches today’s beta. Nothing blocks
`node_save`.

## Three problems, three tools

Do not collapse these into one feature. Site builders configure each surface
separately; ECA (or Workflow / mail) can react to our events on all of them.

| Concern | Question | Tool | Blocks? |
|---|---|---|---|
| **Inference safety** | Is this prompt/response allowed (PII, topics, injection, toxic output)? | **AI core Guardrails** (optional plugins from us) | Yes — on the **chat/response**, via `Stop` / rewrite |
| **Content review** | Does this *node* look risky (AI-like, weak claims, plagiarism)? | **Scan profiles + queue + cron** (factcheck) | **No** on save; async review only |
| **Provenance / disclosure** | Was this produced or assisted by AI? Must we tell users? Exceptions (satire, quotation)? | **Provenance event + fields + ECA** | **No** hard block; policy is site-owned |

### What Guardrails do *not* do

Drupal AI **Guardrails** (in the AI module, UI at
`/admin/config/ai/guardrails`) run on **pre/post generate** for a
`ChatInput` that has a **Guardrail set** attached. They return
`Pass` / `Stop` (with score) / `RewriteInput` / `RewriteOutput`, with
aggregation against a set **stop threshold**.

They are **not**:

- A detector of “this node is AI-generated” for legal compliance
- A place to encode Art. 50 **exceptions** (satire, artistic work, quotation,
  substantial human edit)
- A substitute for editorial review of published content
- Something that runs on every `node_save` unless *you* wire that (we do not)

Contrib `ai_guardrails` only adds extra plugins (e.g. AWS Bedrock). Core
already ships **Regexp** and **Restrict to Topic**; recipes cover PII / prompt
safety. We **integrate**, we do not reimplement those.

### Related existing pieces

| Existing | Role after governance lands |
|---|---|
| Manual **Content scan** tab | Still on-demand full checks; queue uses lighter profiles by default |
| `aip_factcheck_result` | History for both manual and scheduled scans |
| `AdminNotifier` + `FactcheckNotificationEvent` | Default mail + ECA seam (already) |
| `ModelPreCallEvent` / `ModelPostCallEvent` | Pre-call gate (quota/model); post-call telemetry — provenance may hook post-call |
| Smart route fact-check escalation | Answer quality on **routes**, not CMS disclosure |
| AI core Guardrails | Inference I/O safety when a set is attached to the input |

---

## Architecture

```
┌─ AI call (chat / automator / API) ─────────────────────────────┐
│  ChatInput ± GuardrailSet (AI core)                            │
│  PreGenerate → provider (Universal) → PostGenerate             │
│  Stop / Rewrite on the response                                │
│  Optional: our AiGuardrail plugins (disclosure, light checks)  │
│  Optional: provenance event after known generation             │
└────────────────────────────────────────────────────────────────┘

┌─ CMS content ──────────────────────────────────────────────────┐
│  node insert/update                                            │
│    → cheap filters (bundle, status, field change, hash, cooldown)
│    → queue item (no LLM in the request)                        │
│  cron / queue worker                                           │
│    → scan profile checks → aip_factcheck_result                │
│    → if threshold → content_review event                       │
│  ECA / Workflow / AdminNotifier → site policy                  │
└────────────────────────────────────────────────────────────────┘
```

**Async** means: Symfony events in the request are still synchronous; the
**consumer** (queue worker, ECA enqueue, cron) does the expensive or delayed
work. The provider never waits on editorial policy.

---

## Surface A — Inference: attach Guardrail sets

### Goal

Make it **configurable** which AI core **Guardrail set** applies on paths
this module owns, without forcing every caller and without overwriting a set
already attached by chatbot / Automators / custom code.

### Configuration (planned)

| Setting | Scope | Meaning |
|---|---|---|
| Default Guardrail set | Provider / factcheck settings | Applied when `ChatInput` has no set |
| Per smart route | `ai_universal_route` (optional) | Set for that route’s chat calls |
| Optional tool sets | Factcheck extract/detect (later) | Stricter sets for internal LLM tools |

Implementation sketch:

1. If `InputInterface::getGuardrailSet()` is already non-null → **leave it**.
2. Else if configured id is non-empty →
   `AiGuardrailHelper::applyGuardrailSetToChatInput($id, $input)`.
3. AI core’s `GuardrailsEventSubscriber` runs on
   `PreGenerateResponseEvent` / `PostGenerateResponseEvent` as today.

Document for operators: **PII, topics, injection, Bedrock → configure in AI
Guardrails UI; pick the set here.** We do not ship duplicate PII plugins.

### Optional plugins we may ship (`Plugin/AiGuardrail`)

Only when they reuse our services and fit generate-time semantics:

| Plugin | Phase | Configurable | Behaviour |
|---|---|---|---|
| Disclosure suffix | post | Text, tags filter | `RewriteOutputResult` appends a disclaimer |
| AI-likelihood (light) | post | Score threshold | `StopResult` with score (opt-in; costly) |
| Factcheck (light) | post | Min support score, max claims | `StopResult` if verification fails (opt-in; costly) |

Use `NonDeterministicGuardrailInterface` + `NonStreamableGuardrailInterface`
when the check needs an LLM or the full non-streamed text. Site builders add
these entities to a **core Guardrail set** like any other plugin.

**Not** plugins: satire exemption, “scan all nodes”, full plagiarism on every
chat turn.

---

## Surface B — Content review: scan profiles + queue

### Goal

On entity save, **enqueue** matching nodes for async review. Configurable
**what** to scan, **which** checks, **when** to fire an event. Never block
save; never run multi-LLM pipelines in the HTTP request by default.

### Scan profile (config entity or config schema — TBD)

Conceptual shape:

```yaml
id: editorial_light
label: 'Editorial light'
status: true
bundles: [article, page]
operations: [insert, update]   # not every blind save without filters
require_field_change: [body] # optional; or content fingerprint
entity_status: [1]           # e.g. published only
cooldown: 3600               # seconds; skip re-queue if scanned recently
dedupe_queue: true           # one pending item per entity
checks:
  ai_likelihood:
    enabled: true
    alert_threshold: 70      # 0–100
  factcheck:
    enabled: false
    alert_below: 0.5         # support score
  plagiarism:
    enabled: false
    alert_min_hits: 1
  readability:
    enabled: true
    alert_below: 40          # Flesch-style
event_on: threshold          # always | threshold | never
queue: aip_content_review
```

### Enqueue rules (request path — cheap only)

1. Module + profile enabled.
2. Bundle / operation / entity status match.
3. Relevant fields changed (or fingerprint ≠ last scan).
4. Cooldown and flood limits respected.
5. Deduplicate pending queue items for the same entity.

Then: `$queue->createItem([...])` and return. **No LLM.**

### Worker

1. Load entity; skip if gone or no longer matches profile.
2. Run enabled checks (prefer **light** profiles for cron — e.g. AI likelihood
   only; full factcheck/plagiarism as optional/heavier profiles).
3. Persist `aip_factcheck_result` (same as manual scan history).
4. If `event_on` says so and thresholds hit → dispatch
   **content review event** (name TBD; e.g.
   `ai_provider_universal_factcheck.content_review`).
5. Optional: soft notify via `AdminNotifier` if configured.

### Event payload (for ECA)

- `entity_type`, `entity_id`, `bundle`, `uid` (actor if known)
- `profile_id`, checks run, scores
- `thresholds_hit[]`
- `result_id` (factcheck result entity)
- `source: scheduled_scan` (distinct from provenance / detector-only)

### Relation to per-field backlog

ROADMAP already wanted admin UI for which bundles/fields get which checks.
**Scan profiles** are that UI’s backend: profiles select bundles and fields;
optional later: map profile checks to field-level display or moderation.

### Scan checks as plugins (later)

Move factcheck capabilities to **plugins** (`ScanCheck` or similar) so third
parties can add checks. Same services (`AiDetector`, `FactChecker`, …) can
back both a `ScanCheck` (CMS queue) and an optional `AiGuardrail` (generate
path) without duplicating logic.

---

## Surface C — Provenance and disclosure

### Goal

When the site **knows** content was AI-generated or AI-assisted, emit a
**fact event**. Policy (banner, field, moderation state, legal exception)
lives in **ECA / editorial**, not in a Guardrail `Stop`.

### When to emit (preference order)

1. After a **successful generation** path we control (post-call / automator
   hook) when association to an entity is known or when the product decides
   “this output is AI-origin”.
2. When a workflow **writes** AI output into a field (caller or ECA passes
   context).
3. **Not** as the primary signal: “AI-likelihood score high on a human
   article” — that is content review (`source: scheduled_scan` /
   detector), a **hint**, not legal origin.

### Payload (illustrative)

- `source`: `generation` | `association` | `detector` (last only if we ever
  dual-purpose; prefer separate content_review event)
- `model_id`, `server_id` / provider when known
- `operation` / feature (`chat`, `summarize`, …)
- `uid`, `timestamp`
- `entity_type`, `entity_id`, `field_name` (nullable)
- correlation / request id if available

Avoid putting full prompts/responses in the event by default (size, PII).

### Disclosure fields (optional recipe — not required)

Suggested content fields (names illustrative):

| Field | Role |
|---|---|
| `field_ai_origin` | `generated` / `assisted` / `human` / `unknown` |
| `field_disclosure_required` | bool (default from policy) |
| `field_exemption_reason` | `satire` / `art` / `quotation` / `human_substantial_edit` / … |
| `field_exemption_asserted_by` + time | Audit trail |

**Exemptions are human- or ECA-asserted**, never inferred by a detector or by
RestrictToTopic. Guardrails do not implement “satire ⇒ skip disclosure”.

### ECA examples (documentation only unless we ship models)

- On provenance with `disclosure_required` and empty exemption → set banner
  field / show message.
- On content_review threshold → set moderation state `needs_review`, mail
  editors (or rely on `AdminNotifier`).
- On exemption `satire` → do not show public AI banner.

---

## Configuration map (“where do I set X?”)

| I want… | Configure in… |
|---|---|
| Block PII in public chat | AI Guardrails (+ recipe) + Guardrail set; attach set via chatbot **or** our default/route setting |
| Stay on-topic | Restrict to Topic + set |
| Disclaimer on assistant replies | Our disclosure **AiGuardrail** in a **post** set |
| Review articles when published | Scan profile (bundles, published, light checks) |
| Public “AI-assisted” label | Provenance event + ECA + field |
| Exempt satire from the label | Editor sets exemption field; ECA skips banner |
| Skip LLM cost on drafts | Profile: published only + cooldown |
| Hard verify routed answers | Smart route fact-check escalation (existing) and/or strict post Guardrail set |

---

## What we will not do

1. **Block `node_save`** because a detector score is high.
2. Treat **AI-likelihood** as Art. 50 compliance.
3. Encode **legal exemptions** (satire, quotation) inside Guardrail plugins.
4. Run full factcheck + plagiarism on **every** entity update without filters.
5. Depend on ECA or on contrib `ai_guardrails` for core paths (soft/optional
   only).
6. Overwrite a Guardrail set already set on the input by another module.

---

## Implementation phases

Aligned with [ROADMAP.md](../ROADMAP.md) section **Content governance**.

| Phase | Deliverable | Depends on |
|---|---|---|
| **0** | This doc + ROADMAP + glossary | — |
| **1** | Optional Guardrail set attach (global ± per route); no overwrite; tests; short operator note | AI core Guardrails API |
| **2** | Scan profiles + enqueue + queue worker + threshold event + result entity | Factcheck services |
| **3** | Provenance event (+ optional field recipe + ECA examples in docs) | Post-call / association hooks |
| **4** | Optional `AiGuardrail` plugins (disclosure; light likelihood/factcheck) reusing services | Phase 1; factcheck services |
| **5** | Scan checks as plugins; refine per-field UI | Phase 2 |

Each phase stays mergeable alone; empty config = no behaviour change.

---

## Testing notes

- **Phase 1:** unit/kernel — set applied when empty; not applied when already set; empty config no-op.
- **Phase 2:** enqueue filters (bundle, cooldown, hash); worker writes result; event only on threshold when configured.
- **Phase 3:** event payload; no save side effects in the dispatcher.
- **Phase 4:** plugin Pass/Stop/Rewrite with mocked detector/checker.
- Do not require live LLM in unit tests; kernel tests may use the existing HTTP mock traits where needed.

## Permissions (planned)

- Reuse `administer factcheck settings` / `administer ai providers` for scan
  profiles and provenance toggles where possible.
- Queue runs as cron/anonymous-capable services (same care as evidence index:
  only scan content you accept server-side).
- Guardrail entity admin remains AI core permissions
  (`administer guardrails`, `administer guardrail sets`).

## See also

- [factcheck.md](factcheck.md) — verification pipeline, content scan tab, notifications
- [usage-limits.md](usage-limits.md) — pre-call gate, threshold events
- [smart-routing.md](smart-routing.md) — routes, escalation, provider events
- [glossary.md](glossary.md) — short definitions
- AI module docs: `docs/developers/guardrails.md` (in `drupal/ai`)
