<?php

namespace Drupal\ai_provider_universal\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\Guardrail\AiGuardrailRepository;
use Drupal\ai\OperationType\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Attaches a default AI core Guardrail set to calls served by this provider.
 *
 * Sites configure inference safety (PII, topics, injection, …) in the AI
 * Guardrails UI; here they only pick which set applies when the caller did
 * not attach one. A caller-attached set always wins (we never overwrite),
 * and a set on the smart route being called beats the module-wide default.
 * Empty config is a no-op.
 */
class GuardrailDefaultsSubscriber implements EventSubscriberInterface {

  /**
   * Above GlobalGuardrailsEventSubscriber::PRIORITY (100).
   *
   * The "caller attached nothing" check must run before AI core prepends
   * site-wide global sets, or globals would look like caller intent.
   */
  public const PRIORITY = 150;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AiGuardrailRepository $guardrailRepository,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['attachDefaultGuardrailSet', self::PRIORITY],
    ];
  }

  /**
   * Attaches the route or module default Guardrail set when input has none.
   */
  public function attachDefaultGuardrailSet(PreGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'universal') {
      return;
    }
    $input = $event->getInput();
    if (!$input instanceof InputInterface) {
      return;
    }
    // The ai module >= 1.4 supports multiple sets per input; <= 1.3 one.
    $multi_set_api = method_exists($input, 'getGuardrailSets');
    $caller_attached = $multi_set_api
      ? $input->getGuardrailSets() !== []
      : $input->getGuardrailSet() !== NULL;
    if ($caller_attached) {
      return;
    }

    $set_id = $this->routeGuardrailSet($event->getModelId())
      ?? (string) $this->configFactory->get('ai_provider_universal.settings')->get('default_guardrail_set');
    if ($set_id === '') {
      return;
    }

    $set = $this->guardrailRepository->getGuardrailSetById($set_id);
    if ($set !== NULL) {
      $multi_set_api ? $input->addGuardrailSet($set) : $input->setGuardrailSet($set);
    }
  }

  /**
   * Returns the Guardrail set id of the addressed smart route, if any.
   *
   * Smart routes are addressed as model id "route__<id>". The router
   * submodule is optional, so the route entity type may not exist.
   */
  protected function routeGuardrailSet(string $model_id): ?string {
    if (!str_starts_with($model_id, 'route__')
      || !$this->entityTypeManager->hasDefinition('ai_universal_route')) {
      return NULL;
    }
    $route = $this->entityTypeManager->getStorage('ai_universal_route')->load(substr($model_id, 7));
    $set_id = (string) ($route?->get('guardrail_set') ?? '');
    // An empty route setting falls through to the module-wide default.
    return $set_id !== '' ? $set_id : NULL;
  }

}
