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

## Hackathon week (Jul 2–6 prep, Jul 6+ event)

- [x] **Factcheck submodule** (`ai_provider_universal_factcheck`): claim
      extraction + verification services, optional RAG evidence from an
      ai_search index (pgvector), routes can require verification and
      escalate to the best candidate on failure.
- [ ] Configure ai_search index on the hackathon site (nomic-embed local →
      pgvector), index demo content, wire factcheck to it.
- [ ] Add ollama + vLLM (Qwen 0.5B, shieldgemma) servers to the hackathon
      site; set tiers/costs; demo route across the full local fleet +
      Fireworks (account access expected 2026-07-07).
- [ ] Verify Fireworks pricing table against fireworks.ai/pricing once
      credits arrive; live test the `fireworks` backend.
- [ ] Demo polish: savings dashboard numbers, recipe packaging
      ("Drupal AI Router" recipe on Drupal CMS), README/screencast.

## Post-hackathon

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
- [ ] Classifier-based complexity (tiny local model) as alternative to
      heuristics in RouteDecider.
- [ ] Latency/throughput capture from llama.cpp `timings` into decisions.
- [ ] drupal.org project creation + 1.0 alpha release; migration notes from
      ai_provider_llama_cpp (manual, 2 known installs).
