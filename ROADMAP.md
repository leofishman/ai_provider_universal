# Roadmap

Working plan for the AMD Developer Hackathon ACT II (Track 1: Hybrid
Token-Efficient Routing Agent, starts 2026-07-06) and beyond. Team:
"Drupal AI Router".

## Done

- [x] Universal provider port with ServerBackend plugin architecture
      (`openai_compatible` reference backend).
- [x] Per-model routing metadata: cost in/out (USD/1M tokens), quality tier
      (1-5), context length; backend prefill at discovery, never clobbers
      manual edits.
- [x] `fireworks` backend: default endpoint, Fireworks-tuned capability
      detection, published pricing/context prefilled.
- [x] Router submodule: `universal_route` entity, cheapest-capable decision
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
- [ ] Add ollama + vLLM (Qwen 0.5B, shieldgemma) servers to the hackathon
      site; set tiers/costs; demo route across the full local fleet +
      Fireworks (account access expected 2026-07-07).
- [ ] Verify Fireworks pricing table against fireworks.ai/pricing once
      credits arrive; live test the `fireworks` backend.
- [ ] Demo polish: savings dashboard numbers, recipe packaging
      ("Drupal AI Router" recipe on Drupal CMS), README/screencast.

## Post-hackathon

- [ ] **Time-of-day pricing** (DeepSeek already bills off-peak hours
      cheaper): optional per-model/server cost schedule by hour range; the
      smart router uses the price in effect when comparing candidates.
- [ ] **Inference dispatch through backends**: move chat/embeddings
      execution behind ServerBackendInterface so non-OpenAI backends work
      end to end ("earn the universal name").
- [ ] **`llama_cpp` backend** (thin, extends openai_compatible): modality
      detection from `architecture.input_modalities`, `/props`
      health/timings, capability detection that today lives in the generic
      backend moves here.
- [ ] **`ollama` backend**: context length + family metadata via
      `/api/show`, keep-alive handling.
- [ ] **`anthropic` backend** (native, needs inference dispatch): Messages
      API mapping, discovery via GET /v1/models, static capabilities.
- [ ] OpenRouter embeddings discovery: embedding models are not in
      `/v1/models` but on a separate `/embeddings/models` catalog endpoint;
      override `listModels()` in the `openrouter` backend to fetch both and
      merge. Skipped for now — local nomic-embed and Fireworks cover
      embeddings.
- [ ] OpenRouter quality tiers: the catalog publishes no quality signal;
      tiers stay manual per model. Evaluate deriving a default from price
      band if manual entry becomes a burden with 300+ models.
- [ ] Classifier-based complexity (tiny local model) as alternative to
      heuristics in RouteDecider.
- [ ] Latency/throughput capture from llama.cpp `timings` into decisions.
- [ ] drupal.org project creation + 1.0 alpha release; migration notes from
      ai_provider_llama_cpp (manual, 2 known installs).
