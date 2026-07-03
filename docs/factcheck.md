# Fact check submodule

`ai_provider_universal_factcheck` verifies AI answers and site content claim
by claim, and powers the per-node **Content scan** tab. It has two
consumers: smart routes (verify an answer, escalate to a better model when
it fails) and editors (scan a node on demand).

## The verification pipeline

1. **Claim extraction.** The *extractor model* splits the text into atomic
   factual claims (JSON list, capped by *Maximum claims per answer*).
   Opinions and hedges are ignored. If extraction fails the text passes —
   verification must never break inference.
2. **Evidence retrieval** per claim — a cascade (see below).
3. **Verdict** per claim by the *checker model*: `SUPPORTED`,
   `UNSUPPORTED` or `CONTRADICTED`. Checkers whose id contains `minicheck`
   (Bespoke-MiniCheck) are driven through their native
   `Document/Claim → Yes/No` interface; they need an evidence source and a
   separate extractor.
4. **Distrust check** per claim (only when distrusted domains and a Tavily
   key are configured): the claim is searched *only on
   negative-reputation domains*. If those sites assert it, the claim is
   marked **tainted** — misinformation sites echoing a claim is evidence
   against it.
5. **Score**: `(supported − 0.5 × tainted) / total`, floored at 0. An
   answer with no factual claims scores 1.0.

Routes with *require verification* compare the score against their minimum
and escalate to the best remaining candidate model when it falls short.

## Evidence cascade

For each claim, evidence is retrieved in order:

1. **Local index** — the Search API index selected as *Evidence index*
   (typically an AI Search vector index over your own content). The site's
   own knowledge always wins. Note: vector indexes return nearest
   neighbors for *any* query, so with a vector index this step rarely
   comes back empty.
2. **Web via Tavily** — when the local index yields nothing and a
   [Tavily](https://tavily.com) API key is configured (*Web evidence API
   key* setting, a Key entity). No extra Drupal module or library is
   needed; it is a plain HTTP call. Web passages are prefixed with their
   source URL so the checker sees where each one came from.

Web searches honor the **trusted sites** curation below. If a
curated-only search finds nothing, it retries once unrestricted (still
excluding negative domains).

## Trusted sites: reputation-curated sources

Apply the `recipes/factcheck_trusted_sites` recipe to get a **Trusted
site** content type: a domain plus a reputation from −10 to 10. Only
**published** entries are used.

- **Positive reputation**: the domain is a preferred source — supporting
  evidence searches are restricted to these domains (best reputation
  first).
- **Negative reputation**: the domain is excluded from supporting
  evidence, and searched separately as a **counter-signal**: claims it
  asserts are marked tainted and lower the score.
- **No published entries**: web search runs unrestricted.

Reputation is an **editorial decision** — the module ships no opinion
about which sites to trust. The optional
`recipes/factcheck_trusted_sites_seeds` recipe creates a few example
entries (WHO, UN, Wikipedia, one distrusted placeholder), all
**unpublished**: review each one, set the reputation *you* assign to that
source, and publish it.

```bash
# Content type (required for curation):
vendor/bin/dr recipe:apply web/modules/custom/ai_provider_universal/recipes/factcheck_trusted_sites
# Example entries (optional, created unpublished):
vendor/bin/dr recipe:apply web/modules/custom/ai_provider_universal/recipes/factcheck_trusted_sites_seeds
```

Tip: if your evidence index covers all content types, exclude
`trusted_site` from it so curation entries never surface as "evidence".

## Content scan tab

Every node gets a **Content scan** local task (visible to users who can
edit the node). *Run scan* executes up to four checks on the rendered node
and shows the results inline — nothing is stored:

| Check | Engine | Cost | Needs |
|---|---|---|---|
| Fact check | pipeline above | 1 extraction + 1-2 calls per claim | checker model |
| Readability | Flesch reading ease, pure PHP | free | nothing |
| AI likelihood | one prompt to the *AI-detection model* | 1 call | checker or detector model |
| Plagiarism | exact-phrase web search of the 5 longest sentences | 5 Serper calls | Serper.dev key |

The AI-likelihood score is a heuristic LLM judgement, not a trained
detector — treat it as a hint. Plagiarism matches list the URL and snippet
of every page containing a sentence verbatim.

## Settings reference

At **Configuration → AI → Providers → Universal → Fact check settings**
(`ai_provider_universal_factcheck.settings`):

| Setting | Purpose | Empty means |
|---|---|---|
| `checker_model` | judges each claim | fact checking disabled |
| `extractor_model` | splits text into claims | use the checker model |
| `evidence_index` | Search API index for local evidence | model-only verification |
| `max_claims` | claim budget per answer | 5 |
| `detector_model` | AI-likelihood judge | use the checker model |
| `plagiarism_key` | Key entity with the Serper.dev API key | plagiarism check disabled |
| `tavily_key` | Key entity with the Tavily API key | no web evidence fallback |

## Extending

- **Consume it**: inject the `ai_provider_universal_factcheck.checker`
  service alias and call `verify($question, $text)` — returns
  `['score' => float, 'claims' => [{claim, verdict, tainted}]]`. Natural
  fits: a presave gate, a cron QueueWorker over new content, a Views bulk
  action.
- **Replace a piece**: all services are plain DI services; decorate or
  swap `EvidenceRetriever` (e.g. a different search backend) via a service
  provider without touching the rest.
- The 0.5 tainted penalty is fixed for now; per-site weighting by
  reputation value is a planned refinement.
