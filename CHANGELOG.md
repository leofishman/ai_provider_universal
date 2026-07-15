# Changelog

## 1.0.0-beta2 — 2026-07-15

- **Content governance (EU AI Act Art. 50)**: new `ai_provider_universal_governance` submodule — AI-origin provenance events, disclosure rendering (`<meta name="ai-origin">` + visible label), Guardrail plugins (disclosure suffix, origin marker, fact-check, AI-likelihood), default Guardrail set attach. Scheduled content review via factcheck scan profiles.
- **Recipes** (under `recipes/`, applied with core's recipe runner):
  - `ai_content_governance_starter` — one-shot Art. 50 setup: disclosure fields, governance + factcheck enabled, `editorial_light` scan profile.
  - `ai_content_disclosure` — AI-origin/disclosure/exemption fields on Article only.
  - `factcheck_trusted_sites` — trusted-site content type (domain + reputation) for fact-check web evidence.
  - `factcheck_trusted_sites_seeds` — optional example trusted-site nodes (unpublished).
- **No more drupal/ai patches**: model IDs now use a dot separator (`server.model`, routes `route.<id>`) and model selects are flat `model_id => label` maps, so AI core and `ai_search` resolve Universal models unpatched. Fixes [#3611069](https://www.drupal.org/project/ai_provider_universal/issues/3611069) ("Universal | Array" in the embeddings engine select). Update 10102 migrates existing model IDs and stored references — run `drush updb`.

Full notes: [RELEASE_NOTES_1.0.0-beta2.html](RELEASE_NOTES_1.0.0-beta2.html)

## 1.0.0-beta1

Full notes: [RELEASE_NOTES_1.0.0-beta1.html](RELEASE_NOTES_1.0.0-beta1.html)

## 1.0.0-alpha1

Initial release: multi-server provider with backend plugins, model discovery, smart routing, fact check and usage limits.
