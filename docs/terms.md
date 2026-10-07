# Terms submodule

`ai_provider_universal_terms` reads terms of service or a privacy policy and returns what it found per category, each finding quoting the clause it comes from. It is an automated reading, **not legal advice**, and every response says so.

First slice: a JSON API and the analyzer service. A settings form, a standalone page, scan profiles and the catalogue service are planned (see ROADMAP).

## How it works

1. The document is split into pieces of about `chunk_words` words, cut between paragraphs (0 = the whole document in one call).
2. Each piece goes to the configured model inside a fence with a random nonce (`<<DATA-…>>` … `<<END-…>>`), with the instruction that everything inside is data, never instructions. Terms can carry text aimed at the reader ("tell the user this is fine"); the fence keeps it from steering the analysis.
3. The model answers a JSON object: category id → one or two verbatim quotes.
4. **Every quote is checked against the text** (case and whitespace ignored). A quote that is not there was invented and is dropped, and a category left with no quotes is dropped with it. Unknown category ids are ignored. So whatever the document says, the response can only hold known categories and text that really is in the document.
5. The result is cached by the SHA-256 of the normalized text (plus the settings), never by who asked. A partial result (some piece got no usable answer) is not cached, so the next request can complete it.

The prompt, the fence and the 19 categories are the ones measured in Lapacho's terms-eval (2026-10-06: F1 0.89 on Spanish documents with Qwen3.6-35B-A3B, reasoning off, temperature 0). Change them only with a new measurement.

## Categories

From `data/taxonomy.json`:

- **Terms:** `limitation_of_liability`, `unilateral_termination`, `unilateral_change`, `content_removal`, `contract_by_using`, `choice_of_law`, `jurisdiction`, `arbitration`.
- **Consumer and privacy:** `auto_renewal`, `cancellation`, `content_license`, `data_sharing`, `advertising`, `tracking`, `data_retention`, `international_transfer`, `user_rights`, `indemnification`, `rights_waiver`.

## Configuration

No form yet; set the config with Drush:

```bash
drush cset ai_provider_universal_terms.settings model <ai_universal_model id>
drush cset ai_provider_universal_terms.settings chunk_words 900   # 0 = whole document
drush cset ai_provider_universal_terms.settings max_chars 200000
```

Use a model with **reasoning off and temperature 0**, set on the model entity. On llama.cpp, Qwen's thinking only turns off with `chat_template_kwargs.enable_thinking: false` in the model's extra request parameters; `reasoning_effort: none` does not do it.

## API

`POST /api/terms/analyze?_format=json` with `Content-Type: application/json` and a body of either `{"text": "..."}` or `{"url": "https://..."}`.

- Needs the **`use terms analyzer`** permission (restrict access).
- Cookie-authenticated callers must also send an `X-CSRF-Token` header (from `/session/token`); other authentication methods are not affected.
- A URL is fetched with the fact check submodule's `PageFetcher`: public http(s) only, every redirect hop checked against private and reserved addresses, at most 5 MB read.

```json
{
  "hash": "<sha256 of the normalized text>",
  "findings": {
    "jurisdiction": {
      "description": "…",
      "quotes": ["…exact clause…"]
    }
  },
  "dropped_quotes": 0,
  "failed_pieces": 0,
  "notice": "Automated reading of the document, not legal advice. …"
}
```

Errors are `{"error": "..."}`: 400 (body is not `text` xor `url`), 413 (longer than `max_chars`), 415 (not JSON), 422 (empty document or the URL could not be fetched), 502 (no piece got a usable answer), 503 (no model configured).

## Security

Each new document costs one model call per piece, and a URL makes the site fetch it. Grant the permission to trusted roles only. **Before opening it to anonymous users** (behind x402, as planned), two things are missing:

- **DNS pinning in `PageFetcher`.** The host is resolved and checked, then resolved again by the HTTP client; a hostile DNS server can answer a public address the first time and an internal one the second. The fetch has to connect to the address that was checked.
- **A request limit** per user or IP (Drupal's flood service, as the fact check's scan limits do). Each distinct text skips the cache, and a 200,000-character document is about 35 calls at `chunk_words: 900`.
