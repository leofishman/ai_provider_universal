<?php

namespace Drupal\ai_provider_universal_governance\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\ai_provider_universal\Entity\AiUniversalModelInterface;
use Drupal\ai_provider_universal_governance\Event\AiContentProvenanceEvent;
use Drupal\ai_provider_universal\Event\ModelPostCallEvent;
use Drupal\ai_provider_universal_governance\Utility\InternalChatTags;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Emits AI-origin provenance facts (opt-in, see Content governance settings).
 *
 * Two entry points:
 * - Automatic: every successful generation through this provider re-emits as
 *   an AiContentProvenanceEvent (source "generation") when the
 *   emit_provenance setting is on. Off by default: enabling costs one event
 *   dispatch per call and changes nothing until a subscriber reacts.
 *   Internal tool calls (factcheck, classifier, route verifier) never emit
 *   provenance — they are not end-user / published content generation.
 * - recordAssociation(): workflows/ECA assert that AI output was written
 *   into an entity (source "association"). Always dispatches — the caller
 *   asserting the fact is the opt-in.
 */
class ProvenanceRecorder implements EventSubscriberInterface {

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly TimeInterface $time,
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ModelPostCallEvent::EVENT_NAME => 'onModelPostCall',
    ];
  }

  /**
   * Re-emits a successful model call as a provenance fact when enabled.
   */
  public function onModelPostCall(ModelPostCallEvent $event): void {
    if (!$this->configFactory->get('ai_provider_universal_governance.settings')->get('emit_provenance')) {
      return;
    }
    // Factcheck/classifier/verifier are not content-generation provenance.
    if (InternalChatTags::isInternal($event->getTags())) {
      return;
    }
    $model = $this->entityTypeManager->getStorage('ai_universal_model')->load($event->getModelId());
    $server_id = $model instanceof AiUniversalModelInterface ? $model->getServerId() : '';
    $this->eventDispatcher->dispatch(
      new AiContentProvenanceEvent(
        AiContentProvenanceEvent::SOURCE_GENERATION,
        $event->getModelId(),
        $server_id,
        $event->getOperationType(),
        (int) $this->currentUser->id(),
        $this->time->getCurrentTime(),
      ),
      AiContentProvenanceEvent::EVENT_NAME,
    );
  }

  /**
   * Asserts that AI output was written into an entity (source association).
   *
   * For workflows, ECA custom code or modules that write AI output into
   * fields and know it. Model/server may be unknown at that point — pass ''.
   */
  public function recordAssociation(EntityInterface $entity, string $field_name = '', string $model_id = '', string $operation_type = ''): void {
    $model = $model_id !== ''
      ? $this->entityTypeManager->getStorage('ai_universal_model')->load($model_id)
      : NULL;
    $this->eventDispatcher->dispatch(
      new AiContentProvenanceEvent(
        AiContentProvenanceEvent::SOURCE_ASSOCIATION,
        $model_id,
        $model instanceof AiUniversalModelInterface ? $model->getServerId() : '',
        $operation_type,
        (int) $this->currentUser->id(),
        $this->time->getCurrentTime(),
        $entity->getEntityTypeId(),
        $entity->id() ?? '',
        $field_name,
      ),
      AiContentProvenanceEvent::EVENT_NAME,
    );
  }

}
