# Roadmap

Working plan for the AMD Developer Hackathon ACT II (Track 1: Hybrid
Token-Efficient Routing Agent, starts 2026-07-06) and beyond. Team:
"Drupal AI Router".

## Done

- [x] Universal provider port with AiServerBackend plugin architecture
      (`openai_compatible` reference backend).
- [x] Per-model routing metadata: cost in/out (USD/1M tokens), quality tier
      (1-5), context length; backend prefill at discovery, never clobbers
      manual edits.
- [x] `fireworks` backend: default endpoint, Fireworks-tuned capability
      detection, published pricing/context prefilled.
- [x] Router submodule: `ai_universal_route` entity, cheapest-capable decision
      engine (simple/complex tiers), virtual `route__<id>` models, decision
      log + savings dashboard.
- [x] Raw-JSON discovery fix: llama.cpp `status.args` (--ctx-size) and vLLM
      `max_model_len` now feed context detection. Verified live.
- [x] Hackathon site: DDEV + Drupal CMS + pgvector service +
      ai_search/ai_vdb_provider_postgres + this module (volume-mounted).
- [x] `openrouter` backend: default endpoint, capability detection from
      `architecture.output_modalities`, pricing/context prefilled live from
      the catalog (no hardcoded table). 340 models verified against the API.
- [x] `litellm` backend (covers amazee.ai, which is managed LiteLLM):
      discovery via /model/info (mode, per-token costs, context), graceful
      fallback to /v1/models. Payload shape cross-checked against
      ai_provider_amazeeio's DTO; not yet tested against a live proxy (no
      account).

## Hackathon week (Jul 2–6 prep, Jul 6+ event)

- [x] **Factcheck submodule** (`ai_provider_universal_factcheck`): claim
      extraction + verification services, optional RAG evidence from an
      ai_search index (pgvector), routes can require verification and
      escalate to the best candidate on failure.
- [x] Configure ai_search index on the hackathon site (nomic-embed local →
      pgvector), index demo content, wire factcheck to it.
- [x] Per-server usage limits with alert threshold (%) + grace (%) and
      UsageThresholdEvent (router submodule enforces; provider records).
- [x] Content scan tab on nodes (factcheck submodule): fact check,
      readability (Flesch), AI-likelihood, plagiarism via Serper.dev.
- [x] Web evidence cascade: local index → Tavily, curated by the
      trusted_site content type (factcheck_trusted_sites recipe) with
      per-domain reputation (positive = preferred, negative = excluded).
- [x] Add ollama + vLLM (Qwen 0.5B, shieldgemma) servers to the hackathon
      site; set tiers/costs; demo route across the full local fleet.
- [x] `grok` backend (xAI): fixed endpoint, grok-2 family metadata.
- [x] Smart Routes usable as factcheck checker/extractor/detector models;
      router invalidates the provider definition cache on route save/delete
      (no `drush cr` after creating a route).
- [x] Content scan through the Batch API: one step per check with a
      progress bar; results via private tempstore; scanned text shown with
      the results; verdict icons; long analyses collapsed.
- [x] Claim-extraction hardening: object-array JSON and bullet-list
      fallbacks (language-neutral), title passed as extractor context only,
      extraction failure fails open (score 1.0) by design.
- [x] AI-detection check can be disabled ("- Disabled -" detector option);
      plagiarism already off without a Serper key.
- [x] Spanish interface translation for all three modules (.po files +
      interface translation keys).
- [x] First-release prep: 1.0.0-alpha1 release notes draft
      and project page draft, breaking-vs-dev explanation, provider
      segmentation guidance.
- [x] Publish 1.0.0-alpha1: push, tag, drupal.org release node + updated
      project page.
- [x] 1.0.0-beta1 release notes (`RELEASE_NOTES_1.0.0-beta1.html`, since
      alpha1), project page (`PROJECT_PAGE.html`), README install
      constraint `^1.0@beta`.
- [X] **Docker deliverable**: pre-configured demo site image
      (`docker compose up` for judges — sanitize API keys out of the DB
      dump) + from-scratch path documented (DDEV + recipes + discovery).
- [X] Demo hardening: pre-warm the verdict cache on the demo node, remote
      fallback server with credits in the demo route (failover as a demo
      feature), lower local-server timeouts (600s → ~60s).
- [X] Verify Fireworks pricing table against fireworks.ai/pricing once
      credits arrive (2026-07-07); live test the `fireworks` backend; live
      test OpenRouter.
- [X] Demo polish: savings dashboard numbers, demo script/screencast,
      recipe packaging ("Drupal AI Router" recipe on Drupal CMS).

## Post-hackathon

- [ ] **Time-of-day pricing** (DeepSeek already bills off-peak hours
      cheaper): optional per-model/server cost schedule by hour range; the
      smart router uses the price in effect when comparing candidates.
- [ ] **Inference dispatch through backends**: move chat/embeddings
      execution behind AiServerBackendInterface so non-OpenAI backends work
      end to end ("earn the universal name").
- [ ] **`llama_cpp` backend** (thin, extends openai_compatible): modality
      detection from `architecture.input_modalities`, `/props`
      health/timings, capability detection that today lives in the generic
      backend moves here.
- [x] **`ollama` backend**: discovery enriches `/v1/models` via native
      `/api/show` (context length from `num_ctx` or `model_info.*.context_length`,
      family/capabilities for operation types); costs prefilled as free for
      smart routing. Keep-alive remains Ollama server-side (Modelfile /
      daemon defaults) until inference dispatch can pass per-request options.
- [x] **`groq` backend** (GroqCloud): fixed `api.groq.com/openai/v1`; live
      catalog pricing/context/modalities from `/v1/models` (verified against
      free-tier key). `supported_features.reasoning` is a capability flag,
      not effort levels — model entity reasoning stays manual.
- [ ] **`anthropic` backend** (native, needs inference dispatch): Messages
      API mapping, discovery via GET /v1/models, static capabilities.
- [ ] OpenRouter embeddings discovery: embedding models are not in
      `/v1/models` but on a separate `/embeddings/models` catalog endpoint;
      override `listModels()` in the `openrouter` backend to fetch both and
      merge. Skipped for now — local nomic-embed and Fireworks cover
      embeddings.
- [x] OpenRouter quality tiers: derived from price band as a last-resort
      discovery fallback (`prices:` map in model_defaults.yml, site-
      overridable) after family and size heuristics; never clobbers manual
      edits. Verified live: 343/347 OpenRouter models rated.
- [ ] **Verdict quorum (GenLayer-inspired, no blockchain)**: optional
      multi-model consensus in FactChecker. Default stays one checker; on
      CONTRADICTED/tainted verdicts, "appeal" by re-running the claim
      across N checker models (each with its own evidence retrieval) and
      taking majority vote. The 3-label verdict already gives semantic
      equivalence for free; the router's model catalog supplies the
      validator pool. Escalation ladder mirrors Optimistic Democracy's
      finality window: 1 → 3 → 5 models.
- [x] Classifier-based complexity: ComplexityClassifier service — heuristics
      flag complex fast, an optional model (`classifier_model` in
      ai_provider_universal_router.settings) adjudicates the rest; any
      failure falls back to heuristics. Hackathon plan: LoRA fine-tune a
      tiny Gemma as the classifier on AMD cloud GPU.
- [ ] Use a real tokenizer (not the chars/4 estimate) when classifying
      prompts.
- [ ] Latency/throughput capture from llama.cpp `timings` into decisions.
- [x] drupal.org project creation; alpha release in flight (see hackathon
      section). Migration notes from ai_provider_llama_cpp still pending
      (manual, 2 known installs).

## UX & platform improvements

- [x] Rename to namespaced ids: `ai_universal_server`, `ai_universal_model`,
      `ai_universal_route` entities; `AiServerBackend` plugin family.
- [x] Server form: "Test connection & list models" button (preview before
      saving); key creation via new tab + AJAX "Refresh keys".
- [ ] **Inline key creation**: create the Key entity in a modal from the
      server form and refresh the select on close (today's two-tab flow is
      the stopgap).
- [ ] **Hourly usage limits** alongside daily ones (tracking + enforcement +
      thresholds); document when the daily window resets and in which
      timezone.
- [x] **Pre-call gate**: `ModelPreCallEvent` dispatched before every
      inference call; subscribers can block the call or swap the model
      (see docs/usage-limits.md).
- [ ] Set limits via Rules/ECA (the pre-call gate + UsageThresholdEvent are
      the seams; ship an ECA example or dedicated actions).
- [x] **Prompt configurator**: all 8 tunable LLM prompts editable from the
      admin UI (empty = shipped default; sprintf token order validated by
      `PromptPlaceholders`). Six factcheck prompts in the Fact check
      settings form ("Prompts" section); classifier + route-verifier
      prompts in the new Smart routing settings form, which also exposes
      `classifier_model` (previously drush-only). LlamaGuard3/ShieldGemma
      templates deliberately excluded: they are model protocol, editing
      them breaks the response parsers.
- [ ] Per-field configuration on content types to enforce fact-check
      features (plagiarism, AI-likelihood, ...) per field — see **Content
      governance** (scan profiles); field-level UI can refine profiles later.
- [x] Default quality-tier list for known models: site-editable YAML
      (`definitions/model_defaults.yml`) with named-family regexes +
      parameter-count heuristic, plus optional cost defaults for backends
      that publish no pricing. Prefilled at discovery, never overwrites
      manual edits.
- [x] Glossary of module terms: [docs/glossary.md](docs/glossary.md).

## Content governance

Design: [docs/content-governance.md](docs/content-governance.md).
Branch: `feature/content-governance`.

**Principles:** empty config = no behaviour change; never block `node_save`
for detectors or Art. 50; AI core Guardrails for inference I/O safety;
queue + events for CMS review; provenance events for known AI origin;
ECA/Workflow own site policy (including satire/quotation exemptions).

### Phase 0 — Design (this branch)

- [x] Design doc: three surfaces (Guardrails attach, content review queue,
      provenance), config map, non-goals, phases.
- [x] ROADMAP + glossary entries for governance terms.

### Phase 1 — Guardrail set attach

- [x] Optional default Guardrail set applied only when the input has no set
      yet (`GuardrailDefaultsSubscriber` on PreGenerate at priority 150,
      before core's global sets; never overwrites; ai 1.3/1.4 compatible).
      Settings form at `/admin/config/ai/providers/universal/governance`.
- [x] Optional Guardrail set on smart routes (`ai_universal_route`
      `guardrail_set`; route setting beats the provider-wide default).
- [x] Kernel tests: apply / skip-if-present / empty no-op / other provider
      ignored / route override / route fallback / missing set no-op.
- [x] Operator note: PII/topics/injection live in AI Guardrails UI; we only
      select which set to attach (form description + README).

### Phase 2 — Content review queue

- [ ] Scan profiles (exportable config): bundles, insert/update, status,
      field-change or content hash, cooldown, dedupe, enabled checks +
      alert thresholds, `event_on` (always | threshold | never).
- [ ] Enqueue on entity insert/update (cheap filters only; no LLM in request).
- [ ] Queue worker runs profile checks; always persist `aip_factcheck_result`
      when a scan runs; dispatch content-review event per profile rules.
- [ ] Default light profile guidance (e.g. AI-likelihood only on published
      articles); full factcheck/plagiarism as heavier optional profiles.
- [ ] Replaces/fulfills: admin UI for which types get which checks and what
      happens on failure (reaction = event → ECA/AdminNotifier, not block save).

### Phase 3 — Provenance / disclosure

- [x] Provenance event on known AI generation / association (not detector as
      legal origin); payload without full prompt/response by default
      (`AiContentProvenanceEvent`; `ProvenanceRecorder` re-emits post-call
      when `emit_provenance` is on, `recordAssociation()` for workflows).
- [ ] Docs: ECA examples (banner field, moderation state, needs_review until
      editor asserts responsibility); optional recipe for origin / disclosure
      / exemption fields.
- [ ] Exemption taxonomy per Art. 50: `editorial_responsibility` (text, no
      disclosure), `artistic_creative_satirical` (adapted disclosure, not
      none), `assistive_edit` (marking N/A) — human/ECA-asserted, never
      inferred by Guardrails or AI-likelihood.
- [ ] Render marking: machine-readable `<meta>` on the node page + visible
      label at first exposure when disclosure required and no exemption.

### Phase 4 — Optional AiGuardrail plugins

- [x] Post-generate **disclosure suffix** (`universal_disclosure_suffix`,
      `RewriteOutputResult`; skips streamed output; never doubles up).
- [x] Post-generate **machine-readable marker** (`universal_ai_origin_marker`,
      IPTC digitalSourceType HTML comment) for outputs destined for
      publication.
- [ ] Optional light **AI-likelihood** / **factcheck** Guardrail plugins
      reusing factcheck services (`NonDeterministic` / `NonStreamable` as
      needed); off by default (latency/cost).

### Phase 5 — Extensibility

- [ ] Scan checks as plugins (shared services with optional Guardrail plugins).
- [ ] Per-field refinements on top of scan profiles if still needed.

## Factcheck backlog

- [x] Standalone fact-check page + block: URL (internal/external) or pasted
      text at /admin/content/factcheck, plus a placeable "Fact check"
      block; gated by the `use standalone fact check` permission.
- [x] Scan history as Views: every scan persists an `aip_factcheck_result`
      entity; shipped, fully editable "Fact check results" view (page at
      /admin/content/factcheck/results + block).
- [ ] PDF upload for the standalone fact check (needs a text-extraction
      library, e.g. smalot/pdfparser).
- [ ] Admin UI for content types/fields/checks/failure — **superseded by
      Content governance Phase 2** (scan profiles + events); keep this
      line only until profiles land, then mark done.
- [ ] Ship a default search_api index for internal content knowledge?
- [x] Cache + invalidation for trusted-site lookups: persistent cache keyed
      by the `node_list:trusted_site` tag — zero manual invalidation.
- [x] Permission granularity, first pass: `administer factcheck settings` +
      `run content scan` (on top of node update access). Per check type /
      per bundle granularity can come with scan profiles.
- [ ] Move fact-check capabilities to plugins (vs services) — **Content
      governance Phase 5** (ScanCheck plugins; evaluate with Guardrail reuse).
- [ ] MCP integration, the Drupal AI way.
- [x] Factcheck admin notifications: AdminNotifier + FactcheckNotificationEvent
      (ECA/Message seam) with optional default mail via notify_email.

## Documentation backlog

- [x] README: server limits, all backends listed, per-model routing
      metadata, "this module's provider" wording, setup steps (AI settings
      path, detect button), local servers don't need a key, Tavily key.
- [x] usage-limits: daily reset time/timezone; 0 = pause server; events
      fire once per server per day (not per call); token metric wording.
- [x] servers-and-models: warning box under Fireworks costs (lookup table,
      not live); Groq live catalog + supported_features; Ollama dedicated
      backend; catalog features field on model form.
- [x] glossary + adding-a-backend + smart-routing: ten backends, live vs
      table pricing, supported_features vs reasoning effort.
- [x] Content governance design: [docs/content-governance.md](docs/content-governance.md)
      (Guardrails vs review queue vs provenance; phases 0–5 in ROADMAP).
- [ ] Recommended patches: file the upstream issues in the AI module queue
      and link them from the README.
- [ ] Hourly limits in README/usage-limits/servers-and-models once the
      feature lands (see UX & platform improvements).
- [x] smart-routing: savings dashboard moved to Views (ai_router_log view with Page + JSON/REST export displays)
- [x] Route filters on catalog features: `required_features` on the route
      entity (checkboxes fed by discovered features); RouteDecider excludes
      candidates missing any required feature.
