<?php

namespace Drupal\ai_provider_universal\Plugin\ServerBackend;

use Drupal\ai_provider_universal\Attribute\ServerBackend;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The amazee.ai server backend.
 *
 * The amazee.ai service is a managed LiteLLM gateway with region pinning
 * (CH, DE, US, AU, UK): each account gets a private per-region
 * litellm_api_url,
 * so there is no global endpoint to hardcode — the host is that URL. The
 * whole protocol (discovery via /model/info with pricing and modes,
 * OpenAI-compatible inference) is inherited from the LiteLLM backend; this
 * plugin exists so amazee.ai appears as its own provider with its own
 * guidance in the UI.
 */
#[ServerBackend(
  id: 'amazee',
  label: new TranslatableMarkup('amazee.ai'),
  description: new TranslatableMarkup('amazee.ai private AI gateway (managed LiteLLM, region-pinned). Set the host to the litellm_api_url from your amazee.ai dashboard and use your amazee.ai key. Operation types, pricing and context length are read from /model/info.'),
)]
class Amazee extends LiteLlm {

}
