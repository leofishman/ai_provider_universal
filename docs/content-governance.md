# Content governance (Guardrails, review queue, provenance)

The Guardrail attach, disclosure plugins and provenance events live in the
optional **`ai_provider_universal_governance`** submodule (enable it only where
Art. 50-style transparency applies); scan profiles and the review queue live in
the factcheck submodule.

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
| **Provenance / disclosure** | Was this produced or assisted by AI? Must we tell users? Exceptions? | **Provenance event + fields + ECA** | **No** hard block; policy is site-owned |

### Art. 50: who does what

Division of labour, so nobody expects this module to "be" Art. 50 compliance.
The module supplies **facts** (provenance events, marking) and **mechanisms**
(Guardrail plugins, fields, recipes); **ECA / Workflow always takes the final
decision** — no policy is hard-coded here.

| Art. 50 | Obligation | Whose duty | Our contribution |
|---|---|---|---|
| **50(1)** | Tell users they are interacting with an AI system | Provider of the interactive system | **Disclosure suffix** Guardrail in a post set (chatbot UIs should also disclose in their UI) |
| **50(2)** | Mark synthetic output as artificially generated, machine-readable, interoperable, robust | **Upstream model provider** (watermarks/metadata in the model output) | We do **not** watermark text; we **record** provenance we know and **re-expose** it machine-readable at render (see marking below) |
| **50(4)** deepfakes (image/audio/video) | Visible disclosure; artistic/creative/satirical work → *adapted* disclosure that does not hamper the work (not a full exemption) | Deployer (the site) | Exemption fields + ECA; **media marking (C2PA etc.) is out of scope** for now — this module is text-centric |
| **50(4)** text informing the public | Disclose AI generation **unless** the text underwent human review and a person holds **editorial responsibility** | Deployer (the site) | Provenance event + `editorial_responsibility` exemption field + ECA banner/moderation |

Obligations apply from **2 August 2026**; the Commission's Code of Practice on
marking/labelling (Art. 50(7)) is the reference for "machine-readable" and
"first exposure" expectations below.

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

### Internal tool tags (do not attach Guardrails / provenance)

Calls that use these chat `$tags` are **tool traffic**, not end-user generation.
`GuardrailDefaultsSubscriber` and `ProvenanceRecorder` skip them (see
`InternalChatTags`):

| Tag | Used by |
|---|---|
| `ai_provider_universal_factcheck` | Factcheck extract / detect / verify |
| `complexity_classifier` | Smart-route complexity model |
| `route_verifier` | Smart-route lightweight answer verifier |

Without this filter, a site-wide default Guardrail set (or `emit_provenance`)
would break JSON tool responses or flood provenance with claim-checker calls.

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

### Configuration (landed)

| Setting | Scope | Meaning |
|---|---|---|
| Default Guardrail set | `/admin/config/ai/providers/universal/governance` | Applied when the input has no set |
| Per smart route | `ai_universal_route` `guardrail_set` | Set for that route’s calls; beats the default |
| Optional tool sets | Factcheck extract/detect (later) | Stricter sets for internal LLM tools |

Implementation (`GuardrailDefaultsSubscriber`):

1. `PreGenerateResponseEvent` at priority 150 — above AI core’s global-sets
   subscriber (100), so “caller attached nothing” is checked before
   site-wide globals are prepended, and above the evaluating
   `GuardrailsEventSubscriber` (0).
2. Only for `providerId === 'universal'`. If the input already has a set →
   **leave it** (works on both the ai 1.4 multi-set API and the 1.3
   single-set API).
3. Model id `route__<id>` → that route’s `guardrail_set` wins; else the
   module default from settings. Missing/deleted set ids are a silent no-op.

Document for operators: **PII, topics, injection, Bedrock → configure in AI
Guardrails UI; pick the set here.** We do not ship duplicate PII plugins.

### Optional plugins we may ship (`Plugin/AiGuardrail`)

Only when they reuse our services and fit generate-time semantics:

| Plugin | Status | Configurable | Behaviour |
|---|---|---|---|
| Disclosure suffix (`universal_disclosure_suffix`) | **landed** | Suffix text | `RewriteOutputResult` appends a disclaimer (Art. 50(1) helper); skips streamed output; never doubles up when escalation re-runs the set |
| Machine-readable marker (`universal_ai_origin_marker`) | **landed** | Marker text | `RewriteOutputResult` appends an invisible HTML-comment marker (IPTC `digitalSourceType=trainedAlgorithmicMedia`) — survives copy-paste into a body field, feeds render marking below |
| AI-likelihood (light) | phase 4 (rest) | Score threshold | `StopResult` with score (opt-in; costly) |
| Factcheck (light) | phase 4 (rest) | Min support score, max claims | `StopResult` if verification fails (opt-in; costly) |

Guardrails are the **in-band** execution mechanism (touch the output while it
exists); everything after the output lands in content is events + fields, and
**ECA/Workflow decides**. A Guardrail never asserts an exemption and never
encodes site policy — it disclaims, marks, or stops, per set configuration.

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

### Scan profile (landed: `aip_scan_profile` config entity)

Admin UI at `/admin/config/ai/factcheck/scan-profiles` (permission:
`administer factcheck settings`). Exportable shape:

```yaml
id: editorial_light
label: 'Editorial light'
status: true
bundles: [article, page]
operations: [insert, update]   # updates only enqueue when text changed
published_only: true
cooldown: 3600               # seconds; the flag doubles as pending dedupe
checks:
  readability:
    enabled: true
    alert_below: 40          # Flesch reading ease
  ai_likelihood:
    enabled: true
    alert_threshold: 70      # 0–100
  factcheck:
    enabled: false
    alert_below: 0.5         # support score
  plagiarism:
    enabled: false
    alert_min_hits: 1
event_on: threshold          # always | threshold | never
```

### Enqueue rules (landed: `ScanScheduler` on node insert/update)

1. Profile enabled + bundle / operation / publication status match.
2. On update: scannable text differs from the pre-save revision (free
   comparison, the original is already in memory).
3. Cooldown flag (key-value expirable, per profile+node) not present — the
   same flag deduplicates pending items within its TTL.
4. `$queue->createItem(['nid', 'profile_id', 'uid'])` and return. **No LLM.**
   Any failure is logged and swallowed; `node_save` is never blocked.

### Worker (landed: `aip_content_review`, cron)

1. Load node + profile; drop stale items silently (deleted, disabled, no
   longer matching).
2. `ScanRunner` runs enabled checks **cheapest first** (readability →
   AI likelihood → fact check → plagiarism); each check's failure is
   isolated, unconfigured checks report no result.
3. Persists `aip_factcheck_result` (same history as the manual scan tab;
   `details` carries `profile_id`, `source`, `thresholds_hit`).
4. Per `event_on`, dispatches `ContentReviewEvent`
   (`ai_provider_universal_factcheck.content_review`).

### Event payload (landed: `ContentReviewEvent`)

- `entity_type`, `entity_id`, `bundle`, `uid` (the saving user)
- `profile_id`, `scores` (per executed check; NULL = no result)
- `thresholds_hit[]` (empty on a clean scan)
- `result_id` (factcheck result entity)
- `source: scheduled_scan` (distinct from provenance / manual scans)

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

### Payload (landed: `AiContentProvenanceEvent`)

Emitted by `ProvenanceRecorder`: automatically after each successful
generation when **Emit content provenance events** is on (governance
settings), and on demand via the `ai_provider_universal_governance.provenance`
service's `recordAssociation($entity, $field, $model_id, $operation)` for
workflows that write AI output into entities.

- `source`: `generation` | `association` | `detector` (last only if we ever
  dual-purpose; prefer separate content_review event)
- `model_id`, `server_id` / provider — **always present** when the generation
  went through this module (we are the provider; "unknown" is only valid for
  `association` of externally produced content)
- `operation` / feature (`chat`, `summarize`, …)
- `uid`, `timestamp`
- `entity_type`, `entity_id`, `field_name` (nullable)
- correlation / request id if available

Avoid putting full prompts/responses in the event by default (size, PII).

### Disclosure fields (optional recipe — not required)

Shipped as the **`ai_content_disclosure`** recipe (field storages only —
attach them to your content types via the field UI, reusing the existing
storage):

| Field | Role |
|---|---|
| `field_ai_origin` | `generated` / `assisted` / `human` / `unknown` |
| `field_ai_disclosure_req` | bool (default from policy) |
| `field_ai_exemption` | see taxonomy below |
| `field_ai_exemption_by` + `field_ai_exemption_on` | Audit trail (user + time) |

Exemption taxonomy — Art. 50 has **two distinct escape hatches**, do not mix
them:

| Value | Legal basis | Effect on disclosure |
|---|---|---|
| `editorial_responsibility` | 50(4) text: human review + a person/entity holds editorial responsibility | Disclosure **not required** for public-interest text |
| `artistic_creative_satirical` | 50(4) deepfakes: artistic, creative, satirical, fictional work | Disclosure still required but **adapted** — must not hamper the work (e.g. credits instead of banner) |
| `assistive_edit` | 50(2): AI performed a standard assistive/editing function without substantially altering the input | Marking obligation does not apply |

**Exemptions are human- or ECA-asserted**, never inferred by a detector or by
RestrictToTopic. Guardrails do not implement “satire ⇒ skip disclosure”, and
note that for creative works the law asks for *adapted* disclosure, not none —
ECA models should switch the disclosure form, not drop it silently.

### Machine-readable marking at render (Art. 50(2)/(4))

Internal events and fields do not travel with the content. When
`field_ai_origin` is `generated`/`assisted` (and no `assistive_edit`
exemption), the optional recipe/module should also emit the fact
**machine-readable in the rendered output**, so it persists for crawlers,
syndication and API consumers:

- HTML: `<meta>` in `<head>` (e.g. schema.org-style
  `isBasedOn`/`creativeWorkStatus` or an explicit
  `ai-origin`/IPTC *digital source type* value) on the node's canonical page.
- JSON:API / REST: the origin/exemption fields are ordinary fields — exposed
  automatically; document that consumers must carry them.
- Media (images/audio/video) with embedded C2PA/watermarks: **out of scope**
  for now; do not strip metadata the upstream provider embedded.

### Visible label at first exposure

The Code of Practice expects the *human-visible* disclosure at **first
exposure** to the content. For the recipe this is a requirement, not an
example: when `field_disclosure_required` is true and no exemption applies,
the label renders **on the published node itself** (extra field /
pseudo-field in the default view mode), not only as an internal flag. ECA may
replace *how* it looks; the recipe guarantees *that* it shows by default.

### ECA examples (documentation only unless we ship models)

- On provenance with `disclosure_required` and empty exemption → set banner
  field / show message.
- On content_review threshold → set moderation state `needs_review`, mail
  editors (or rely on `AdminNotifier`).
- On exemption `editorial_responsibility` (editor took ownership after
  review) → clear `disclosure_required`, record who asserted it.
- On exemption `artistic_creative_satirical` → swap the banner for an
  *adapted* disclosure (e.g. a credits line) — do not remove disclosure
  entirely.
- On provenance for a bundle that informs the public → force moderation state
  `needs_review` until an editor either asserts `editorial_responsibility` or
  publishes with the label. **This is the recommended Art. 50(4) text
  workflow: the human decision is the compliance step, ECA just routes it.**

---

## Configuration map (“where do I set X?”)

| I want… | Configure in… |
|---|---|
| Block PII in public chat | AI Guardrails (+ recipe) + Guardrail set; attach set via chatbot **or** our default/route setting |
| Stay on-topic | Restrict to Topic + set |
| Disclaimer on assistant replies | Our disclosure **AiGuardrail** in a **post** set |
| Review articles when published | Scan profile (bundles, published, light checks) |
| Public “AI-assisted” label | Provenance event + ECA + field (recipe renders it at first exposure) |
| Machine-readable AI-origin mark | Marker Guardrail (in-band) + render `<meta>` from origin fields |
| Skip the label after human editorial review | Editor asserts `editorial_responsibility`; ECA clears the flag |
| Adapted disclosure for satire/art | Editor sets `artistic_creative_satirical`; ECA swaps banner for credits |
| Skip LLM cost on drafts | Profile: published only + cooldown |
| Hard verify routed answers | Smart route fact-check escalation (existing) and/or strict post Guardrail set |

---

## What we will not do

1. **Block `node_save`** because a detector score is high.
2. Treat **AI-likelihood** as Art. 50 compliance.
3. Encode **legal exemptions** inside Guardrail plugins — exemptions are
   asserted by humans/ECA on the entity, never decided at generate time.
4. Run full factcheck + plagiarism on **every** entity update without filters.
5. Depend on ECA or on contrib `ai_guardrails` for core paths (soft/optional
   only).
6. Overwrite a Guardrail set already set on the input by another module.
7. Claim **text watermarking** (Art. 50(2) robust marking is the upstream
   model provider's duty; we record, mark at render, and never strip
   upstream marks).
8. Take the **final disclosure decision** — the module proposes defaults;
   ECA/Workflow and editors decide.

---

## Implementation phases

Aligned with [ROADMAP.md](../ROADMAP.md) section **Content governance**.

| Phase | Deliverable | Status |
|---|---|---|
| **0** | This doc + ROADMAP + glossary | done |
| **1** | Optional Guardrail set attach (global ± per route); no overwrite; tests; short operator note | **done** (`GuardrailDefaultsSubscriber`, governance settings form, route `guardrail_set`) |
| **2** | Scan profiles + enqueue + queue worker + threshold event + result entity | **done** (`aip_scan_profile`, `ScanScheduler`, `aip_content_review` worker, `ContentReviewEvent`) |
| **3** | Provenance event + field recipe (origin/disclosure/exemption taxonomy) + render marking (`<meta>` + visible label at first exposure) + ECA examples | **done** (`AiContentProvenanceEvent`, `ProvenanceRecorder`, `ai_content_disclosure` recipe, `hook_node_view` marking in the governance submodule, ECA examples above) |
| **4** | Optional `AiGuardrail` plugins (disclosure; machine-readable marker; light likelihood/factcheck) reusing services | **disclosure + marker landed**; light likelihood/factcheck pending |
| **5** | Scan checks as plugins; refine per-field UI | pending |

Each phase stays mergeable alone; empty config = no behaviour change.

---

## Testing notes

- **Phase 1:** unit/kernel — set applied when empty; not applied when already set; empty config no-op.
- **Phase 2:** enqueue filters (bundle, cooldown, hash); worker writes result; event only on threshold when configured.
- **Phase 3:** event payload; no save side effects in the dispatcher; render
  marking present when origin set and absent when `assistive_edit` /
  `editorial_responsibility` applies; visible label renders by default.
- **Phase 4:** plugin Pass/Stop/Rewrite with mocked detector/checker; marker
  plugin output contains the machine-readable marker.
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
