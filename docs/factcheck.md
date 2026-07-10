# Fact check submodule

`ai_provider_universal_factcheck` verifies AI answers and site content claim by claim, and powers the per-node **Content scan** tab. It has two consumers: smart routes (verify an answer, escalate to a better model when it fails) and editors (scan a node on demand).

**Planned:** async **content review** (scan profiles + queue on entity save) and optional ties to AI Guardrails / provenance events — see [content-governance.md](content-governance.md). Manual Content scan and route escalation stay as they are; governance adds opt-in automation without blocking save.

Any chat model reachable through a configured server works as extractor or checker — the pipeline is model-agnostic. Verified in practice with Google **Gemma 3** (1B/4B via Ollama; 12B via vLLM on ROCm/AMD GPUs), **Qwen 2.5** (vLLM), and Bespoke **MiniCheck** as a dedicated checker (see below). Small models (≤1B) extract claims unreliably; 4B-class and up are dependable.

## The verification pipeline

1. **Claim extraction.** The *extractor model* splits the text into atomic factual claims (JSON list, capped by *Maximum claims per answer*). Opinions and hedges are ignored. If extraction fails the text passes — verification must never break inference.
2. **Evidence retrieval** per claim — a cascade (see below).
3. **Verdict** per claim by the *checker model*: `SUPPORTED`, `UNSUPPORTED` or `CONTRADICTED`. Checkers whose id contains `minicheck` (Bespoke-MiniCheck) are driven through their native `Document/Claim → Yes/No` interface; they need an evidence source and a separate extractor.
4. **Distrust check** per claim (only when distrusted domains and a Tavily key are configured): the claim is searched *only on negative-reputation domains*. If those sites assert it, the claim is marked **tainted** — misinformation sites echoing a claim is evidence against it.
5. **Score**: `(supported − 0.5 × tainted) / total`, floored at 0. An answer with no factual claims scores 1.0.

Routes with *require verification* compare the score against their minimum and escalate to the best remaining candidate model when it falls short.

## Evidence cascade

For each claim, evidence is retrieved in order:

1. **Local index** — the Search API index selected as *Evidence index* (typically an AI Search vector index over your own content). The site's own knowledge always wins. Note: vector indexes return nearest neighbors for *any* query, so with a vector index this step rarely comes back empty.
2. **Web via Tavily** — when the local index yields nothing and a [Tavily](https://tavily.com) API key is configured (*Web evidence API key* setting, a Key entity). No extra Drupal module or library is needed; it is a plain HTTP call. Web passages are prefixed with their source URL so the checker sees where each one came from.

Web searches honor the **trusted sites** curation below. If a curated-only search finds nothing, it retries once unrestricted (still excluding negative domains).

## The evidence index in detail

Any **Search API index** works as the evidence index — the module has no hard dependency on `ai_search`. What matters is how passages are extracted from each result, in fallback order:

1. **`content` extra data** — what ai_search attaches to a result: the matched vector *chunk*. Requested explicitly with the `search_api_ai_get_chunks_result` query option. Best quality: the checker sees exactly the passage that matched.
2. **Excerpt** — what classic backends (database, Solr) return when the *Highlight* processor is enabled on the index.
3. **Entity label** — last resort when the backend returns neither; enough to confirm a topic exists, too thin to verify a claim.

So a plain database index works, but works much better with the Highlight processor enabled; a vector index works best out of the box.

How the query is built (`EvidenceRetriever::retrieveLocal()`):

- `$index->query()->keys($claim)->range(0, $limit)` — the claim text is the query, `$limit` comes from the verification profile (2/3/5 passages).
- `search_api_bypass_access` is set: verification is a server-side judgement against *published, indexed* content, and runs in cron/anonymous contexts where entity access would silently return nothing. Consequence: **only index content you would show to the checker** — don't index private fields into the evidence index.
- Everything is best-effort: any exception degrades to the web fallback rather than failing verification.

Setting up a good index:

- **Recommended**: an [AI Search](https://project.pages.drupalcode.org/ai/modules/ai_search/) index (vector DB + embedding model from this provider) over your published content. Nearest-neighbor retrieval means claims phrased differently from your content still find it.
- Index the **rendered output or body text**, not just titles — the checker needs passages, not headlines.
- **Exclude the `trusted_site` content type** (and any other curation/config-like content) so curation entries never surface as "evidence".
- With a vector index, remember step 1 of the cascade almost never comes back empty (nearest neighbors always exist), so the Tavily fallback effectively only fires when no index is configured. If you want web evidence to compete with weak local matches, that's a customization — see below.

Extending retrieval:

- **Different backend or scoring**: decorate or replace the `EvidenceRetriever` service (`Drupal\ai_provider_universal_factcheck\Service\EvidenceRetriever`) via a service provider or `hook_service_alter`. The contract is small: `retrieve(string $claim, int $limit): string[]` (plain-text passages) and `retrieveDistrusted(string $claim, int $limit): string[]` (counter-evidence from negative-reputation domains). Everything downstream — checker, scoring, escalation — only sees arrays of strings.
- **Multiple indexes / federated evidence**: a decorator can merge passages from several indexes (or an external RAG service) before returning; keep the strongest passages first, the checker reads them in order.
- **Minimum-relevance threshold**: a decorator can drop low-score vector matches so the cascade actually falls through to web evidence instead of feeding the checker weak neighbors.

## Trusted sites: reputation-curated sources

Apply the `recipes/factcheck_trusted_sites` recipe to get a **Trusted site** content type: a domain plus a reputation from −10 to 10. Only **published** entries are used.

- **Positive reputation**: the domain is a preferred source — supporting evidence searches are restricted to these domains (best reputation first).
- **Negative reputation**: the domain is excluded from supporting evidence, and searched separately as a **counter-signal**: claims it asserts are marked tainted and lower the score.
- **No published entries**: web search runs unrestricted.

Each entry also takes three **optional profile fields**:

- **Editorial bias** (left … right): used to summarize the bias spread of the sources behind each verified claim, Ground-News-style — the scan shows a per-claim *Coverage* column ("3 sources, 2 independent") and flags a **blindspot** when every leaning source falls on one side.
- **Owner / parent organization**: domains sharing an owner count as *one* independent source — a wire story republished by sibling outlets is not independent confirmation (near-duplicate passages are also collapsed automatically before verification).
- **External assessments**: what media watchdogs say about the outlet, one per item, **with the rater named and linked**. Watchdogs have viewpoints too — recording *who* said it keeps the assessment auditable instead of laundering it into the reputation number. These notes are shown to the discrepancy-analysis model when sources disagree.

Reputation is an **editorial decision** — the module ships no opinion about which sites to trust. The optional `recipes/factcheck_trusted_sites_seeds` recipe creates example entries — institutions (WHO, UN), wire services (Reuters, AP), scientific sources (PubMed, Nature, Science, and arXiv, flagged as *not peer-reviewed*: preprints support "a paper claims X" more than "X is established"), Wikipedia, and one distrusted placeholder — all **unpublished**: review each one, set the reputation *you* assign to that source, and publish it.

```bash
# Content type (required for curation):
vendor/bin/dr recipe:apply web/modules/custom/ai_provider_universal/recipes/factcheck_trusted_sites
# Example entries (optional, created unpublished):
vendor/bin/dr recipe:apply web/modules/custom/ai_provider_universal/recipes/factcheck_trusted_sites_seeds
```

Tip: if your evidence index covers all content types, exclude `trusted_site` from it so curation entries never surface as "evidence".

**Performance**: the domain/reputation map is cached persistently and invalidated automatically whenever a `trusted_site` node is created, updated or deleted (core's `node_list:trusted_site` cache tag) — curating entries never requires a manual cache clear, and scans never re-query the nodes.

## Standalone fact check (page + block)

Besides the per-node tab, **Content → Fact check** (`/admin/content/factcheck`) scans anything: give it a URL (this site or any other — the page is fetched and its text extracted) or paste text directly. Same checks, same result rendering. A **Fact check** block (category "AI") exposes the same form for placement anywhere; both are gated by the `use standalone fact check` permission. PDF upload is planned (needs a text-extraction library — see ROADMAP).

URL fetches are **SSRF-hardened**: only `http`/`https`, and the resolved host must not be a private or reserved address (localhost, RFC1918, link-local, …). Invalid or non-public targets fail with a form error instead of being requested.

Every scan (tab or standalone) is also stored as an `aip_factcheck_result` entity, and the shipped **Fact check results** view lists the history at **Content → Fact check results** (`/admin/content/factcheck/results`) with a matching block. It is a normal view: edit columns, filters, path and displays at **Structure → Views** like any other. Rows are plain audit data (subject, scores, who ran it, full details) — deleting them is safe, and uninstalling the module removes them.

## Permissions

| Permission | Grants |
|---|---|
| `administer factcheck settings` | The settings form (checker models, evidence index, API keys). `administer ai providers` also works, so provider admins need no extra grant. |
| `run content scan` | The Content scan tab. The user **also** needs update access to the node — the permission narrows who may spend LLM/API budget, it does not widen content access. |
| `use standalone fact check` | The standalone page and block above. |
| `view factcheck results` | The stored scan history (results view, page and block). |

After enabling the module, grant `run content scan` to your editor roles — the tab is not visible without it.

## Content scan tab

Every node gets a **Content scan** local task (visible to users who can edit the node). *Run scan* collects the node's text fields (body, summary, …— the title is passed to the extractor as context only, so it never shows up as a claim of its own) and runs up to four checks through Drupal's Batch API: each check is its own step with a progress bar, so slow model calls never hit a PHP timeout. Results render inline (with the scanned text in a collapsible section) and each scan is also stored as an `aip_factcheck_result` row for the results view:

| Check | Engine | Cost | Needs |
|---|---|---|---|
| Fact check | pipeline above | 1 extraction + 1-2 calls per claim | checker model |
| Readability | Flesch reading ease, pure PHP | free | nothing |
| AI likelihood | one prompt to the *AI-detection model* | 1 call | checker or detector model |
| Plagiarism | exact-phrase web search of the 5 longest sentences | 5 Serper calls | Serper.dev key |

The AI-likelihood score is a heuristic LLM judgement, not a trained detector — treat it as a hint. Plagiarism matches list the URL and snippet of every page containing a sentence verbatim.

### Admin notifications

Whenever factcheck wants to alert operators (a content scan starts, or settings are saved), `AdminNotifier` runs in this order:

1. **Dispatch** `FactcheckNotificationEvent`  
   (`ai_provider_universal_factcheck.notification`) — always, even with no email configured.  
2. **Optional default mail** — if `notify_email` is set in Fact check settings and no subscriber called `$event->suppressMail()`.

| Piece | Detail |
|---|---|
| Event class | `Drupal\ai_provider_universal_factcheck\Event\FactcheckNotificationEvent` |
| Event name | `ai_provider_universal_factcheck.notification` |
| Keys | `scan_run`, `settings_changed` (constants on the event class) |
| Payload | `getKey()`, `getSubject()`, `getBody()`, `getContext()` (e.g. `uid`, `scan_subject`) |
| Default mail | `plugin.manager.mail` + OOP `hook_mail` (`AiProviderUniversalFactcheckHooks`) |

Delivery failures are logged and never break the scan/settings form. Use any site mail plugin (PHP mail, SMTP, Symfony Mailer, …).

#### Extending (ECA, Message Notify, Slack, …)

No hard dependency on a notification contrib module. Subscribe to the event:

- **ECA**: “Drupal core → Dispatch event” / event plugin for Symfony events → action: send email, create Message, HTTP webhook, etc.  
- **Custom subscriber**: `EventSubscriberInterface` on `FactcheckNotificationEvent::EVENT_NAME`.  
- **Replace mail only**: call `$event->suppressMail()` in the subscriber and handle delivery yourself.

```php
// Example subscriber skeleton.
public static function getSubscribedEvents(): array {
  return [FactcheckNotificationEvent::EVENT_NAME => 'onNotify'];
}
public function onNotify(FactcheckNotificationEvent $event): void {
  // e.g. post to Slack using $event->getSubject() / getBody() / getContext().
  // $event->suppressMail(); // optional: skip AdminNotifier mail.
}
```

## Recommended models (cost / benefit)

For fact-checking the key is **specialization + low per-claim cost**, because you may run 5–20 LLM calls per scan.

| Role            | Recommended (local / self-hosted)          | Why (cost/benefit)                          | Alternative (API)          |
|-----------------|--------------------------------------------|---------------------------------------------|----------------------------|
| **Checker**     | `bespoke-minicheck` (or any MiniCheck)    | Tiny specialized NLI model. Extremely cheap, excellent at SUPPORTED/NO for claims. | — (local is best here)    |
| **Extractor**   | Qwen2.5-14B / Gemma-2-27B / Llama-3.1-8B  | Good JSON/structured output at low cost. Use 7-14B quantized for speed. | Groq (`groq` backend) Llama-3.3-70B / 3.1-8B-instant (very fast) |
| **AI-detection** (detector) | Same as checker or any cheap 7B model | Heuristic only — no need for a strong model. | Use checker as fallback |
| **Evidence embedding** | nomic-embed-text, bge-large-en-v1.5     | Strong retrieval quality vs size. Use in your AI Search index. | —                         |
| **General fallback** | Qwen2.5-32B or Gemma-2-27B               | Solid reasoning when MiniCheck is not enough or for extractor. | Fireworks or Together Qwen-72B |

**Rule of thumb for routes**:
- Low tier (simple prompts / fast factchecks): 7–14B models (cost ~$0.10–0.30 / M tokens).
- High tier (complex claims, discrepancy analysis): 27–72B (cost ~$0.40–1.00 / M).
- Always prefer quantized GGUF/Q4_K_M or vLLM for local inference.

If you only have large frontier models, use a small local checker + a strong API model only for escalation.

## Importing bias ratings (MediaBiasFactCheck and similar)

The `trusted_site` content type has a `field_bias` field (left / center / right etc.) and `field_reputation`.

You can populate them from external raters using:

```bash
drush factcheck:sync-bias-ratings
```

This command reads `data/mbfc-ratings-sample.json` (in the factcheck submodule) and creates/updates trusted sites with:

- `field_reputation` derived from factual reporting + bias
- `field_assessments` containing the source rating (e.g. "Media Bias / Fact Check — Bias: Right | Factual: Mixed")
- `field_bias` normalized

You can extend the JSON file with more domains from https://mediabiasfactcheck.com/ or other raters (AllSides, Ad Fontes, etc.).

To fetch ratings live instead of maintaining JSON, subscribe to the [MBFC Ratings API on RapidAPI](https://rapidapi.com/mbfcnews/api/media-bias-fact-check-ratings-api2) (or the commercial direct API), store the key in a Key entity, select it under **Fact check settings → Media bias ratings API key**, and run:

```bash
drush factcheck:sync-bias-ratings --fetch=example.com,othersite.org
```

Note MBFC's terms: RapidAPI access is for testing, research and small non-commercial projects, with attribution to MediaBiasFactCheck.com.

The bias information is already used in:
- Discrepancy analysis (shows the spread of biases behind a claim)
- Blindspot detection (when all evidence comes from one side)

This makes it easy to curate sources with ideological awareness without hard-coding everything.

### Fallbacks for extractor and detector

In **Fact check settings**:

- If `extractor_model` is empty → the module automatically uses the `checker_model`.
- If `detector_model` is empty → the module automatically uses the `checker_model`.
- Selecting **"- Disabled -"** as the AI-detection model turns the AI-likelihood check off entirely (the section disappears from scans).

You only need to set `detector_model` if you want a **different** (usually cheaper/faster) model just for the "how AI-like is this text?" heuristic — or to disable the check.

## Settings reference

At **Configuration → AI → Providers → Universal → Fact check settings** (`ai_provider_universal_factcheck.settings`):

| Setting | Purpose | Empty means |
|---|---|---|
| `profile` | cost/latency vs. depth trade-off (see below) | `balanced` |
| `checker_model` | judges each claim | fact checking disabled |
| `extractor_model` | splits text into claims | use the checker model |
| `evidence_index` | Search API index for local evidence | model-only verification |
| `max_claims` | claim budget per answer | 5 |
| `detector_model` | AI-likelihood judge (also called AI-detection model); `none` disables the check | use the checker model |
| `plagiarism_key` | Key entity with the Serper.dev API key | plagiarism check disabled |
| `tavily_key` | Key entity with the Tavily API key | no web evidence fallback |
| `prompts.*` | overrides for the six LLM prompt templates (see below) | shipped defaults |

### Prompt overrides

The **Prompts** section of the settings form exposes every LLM prompt the
submodule sends: claim extraction, per-claim verdict, batched verdicts,
distrusted-site echo check, discrepancy analysis, and the AI-likelihood
detector. Each textarea shows the shipped default greyed out; leave it
empty to keep using that default (upgrades then pick up improved shipped
prompts automatically — an override freezes your wording).

Prompts are `sprintf` templates: runtime values (the text, the claims,
the evidence) are substituted into the `%s` / `%d` tokens, and the form
rejects an override whose token sequence differs from the default. Keep
the machine-readable reply contract (the exact verdict words, the JSON
shapes) intact — the parsers depend on it. Rewording, translating, or
adding domain guidance is the intended use.

The routing prompts (complexity classifier, route verifier) live on the
router's own settings form — see [docs/smart-routing.md](smart-routing.md).

### Verification profiles

One knob moves all the cost/quality levers together:

| | `fast` | `balanced` (default) | `thorough` |
|---|---|---|---|
| Verdict calls | 1 batched for all claims | 1 batched | 1 per claim |
| Evidence passages per claim | 2 | 3 | 5 |
| Distrusted-site check | off | 1 per answer | 1 per claim |
| Discrepancy analysis | off | unsettled claims | unsettled claims |
| Verdict cache | 6 h | 1 h | none |

Worst-case LLM calls for a 5-claim answer: ~2 (`fast`), ~4 + analyses (`balanced`), ~16 (`thorough`). Cached claims cost nothing on re-scan; any settings change invalidates the cache. MiniCheck checkers cannot batch and always use per-claim verdict calls.

## Smart routing + factcheck

### Using a Smart Route as the checker model

Smart Routes ("Auto: …") appear directly in the "Checker model", "Extractor" and "AI-detection model" dropdowns in **Fact check settings**.

The router submodule invalidates the AI provider definition cache (`clearCachedDefinitions()`) whenever a route is created, updated or deleted — the same pattern the module already uses for servers (see `src/Hook/AiProviderUniversalHooks.php`). No `drush cr` is needed after creating a route: the new "Auto: …" option shows up immediately in the AI settings dropdowns and in Fact check settings.

To use one:

1. Create or edit a Smart Route (Operation type = Chat).
2. In Fact check settings, select "Auto: YourRouteName" in the relevant field.

### Smart Routes with factcheck + escalation

- Create a normal Chat Smart Route.
- On that route, enable **"Fact-check answers and escalate on failure"** and set the minimum support score (e.g. 0.7).
- In **AI settings** → Chat, select that route as the provider.
- In Fact check settings, set a checker model (another cheap smart route or a specific model).

Chat answers through that route are then verified automatically and escalate to a stronger candidate when the score falls short. See [docs/smart-routing.md](smart-routing.md) for route creation details.

## Extending

- **Consume it**: inject the `ai_provider_universal_factcheck.checker` service alias and call `verify($question, $text)` — returns `['score' => float, 'claims' => [{claim, verdict, tainted}]]`. Natural fits: a presave gate, a cron QueueWorker over new content, a Views bulk action.
- **Replace a piece**: all services are plain DI services; decorate or swap `EvidenceRetriever` (e.g. a different search backend, multiple indexes, a relevance threshold) via a service provider without touching the rest — see [The evidence index in detail](#the-evidence-index-in-detail).
- The 0.5 tainted penalty is fixed for now; per-site weighting by reputation value is a planned refinement.
