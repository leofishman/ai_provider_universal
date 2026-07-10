<?php

namespace Drupal\ai_provider_universal\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Fact event: content was produced (or touched) by a known AI generation.
 *
 * Dispatched when AI origin is known — after a successful generation through
 * this provider (source "generation"), or when a workflow asserts that AI
 * output landed in an entity (source "association"). It states a fact, never
 * policy: disclosure, AI Act Art. 50 exemptions, moderation states and
 * banners are site-owned decisions taken by ECA/Workflow subscribers.
 * See docs/content-governance.md.
 *
 * The payload deliberately excludes prompt/response text (size, PII).
 */
class AiContentProvenanceEvent extends Event {

  const EVENT_NAME = 'ai_provider_universal.content_provenance';

  /**
   * A generation happened through this provider.
   */
  const SOURCE_GENERATION = 'generation';

  /**
   * A workflow asserted that AI output was written into an entity.
   */
  const SOURCE_ASSOCIATION = 'association';

  /**
   * Constructs the provenance fact.
   *
   * @param string $source
   *   One of the SOURCE_* constants.
   * @param string $modelId
   *   The ai_universal_model entity id that served the call, or '' when the
   *   association does not know it.
   * @param string $serverId
   *   The ai_universal_server entity id, or '' when unknown.
   * @param string $operationType
   *   The AI operation type (chat, ...), or '' when unknown.
   * @param int $uid
   *   The acting user id at dispatch time.
   * @param int $timestamp
   *   Unix timestamp of the generation/association.
   * @param string $entityTypeId
   *   Associated entity type id, '' when no entity association is known.
   * @param string|int $entityId
   *   Associated entity id, '' when unknown.
   * @param string $fieldName
   *   Field the AI output was written to, '' when unknown/not applicable.
   */
  public function __construct(
    protected string $source,
    protected string $modelId,
    protected string $serverId,
    protected string $operationType,
    protected int $uid,
    protected int $timestamp,
    protected string $entityTypeId = '',
    protected string|int $entityId = '',
    protected string $fieldName = '',
  ) {
  }

  /**
   * One of the SOURCE_* constants.
   */
  public function getSource(): string {
    return $this->source;
  }

  /**
   * The ai_universal_model entity id, '' when unknown.
   */
  public function getModelId(): string {
    return $this->modelId;
  }

  /**
   * The ai_universal_server entity id, '' when unknown.
   */
  public function getServerId(): string {
    return $this->serverId;
  }

  /**
   * The AI operation type (chat, ...), '' when unknown.
   */
  public function getOperationType(): string {
    return $this->operationType;
  }

  /**
   * The acting user id.
   */
  public function getUid(): int {
    return $this->uid;
  }

  /**
   * Unix timestamp of the generation/association.
   */
  public function getTimestamp(): int {
    return $this->timestamp;
  }

  /**
   * Associated entity type id, '' when none.
   */
  public function getEntityTypeId(): string {
    return $this->entityTypeId;
  }

  /**
   * Associated entity id, '' when none.
   */
  public function getEntityId(): string|int {
    return $this->entityId;
  }

  /**
   * Field name the output was written to, '' when unknown.
   */
  public function getFieldName(): string {
    return $this->fieldName;
  }

}
