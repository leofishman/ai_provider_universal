# Usage tracking and limits

## Where limits live and why

Each **server** can carry a **daily request limit** and a **daily token limit** (input + output, all its models combined) — that is where the account/budget actually lives (OpenRouter credits, Groq free-tier/developer caps, amazee.ai budget, a LiteLLM master key). Set them on the server form under "Usage limits". Leave a limit empty for unlimited; a limit of `0` deliberately blocks the server until the next day (useful to pause a server without deleting it).

Counters stay **per model** per day in the `ai_provider_universal_usage` table; the per-model breakdown is shown in the model overrides section of the server form, the server total in the "Usage limits" section.

**When does "daily" reset?** Days are keyed by calendar date (`Ymd`) in the **site's default timezone** (Configuration → Regional settings), so limits reset at local midnight — not a rolling 24-hour window, and not UTC unless your site is configured that way.

## Enforcement

Enforcement lives in the **Smart Router submodule** (`ai_provider_universal_router`): when a server exhausts a limit,

- routes drop all its models from the candidate pool and **fail over to another provider**;
- direct calls to its models fail with `AiQuotaException` until the day rolls over.

Without the submodule, usage is still recorded but never blocks. The main module reaches the enforcer through the optional `ai_provider_universal_router.limits` service alias, so there is no hard dependency.

## Thresholds

Two optional per-server thresholds refine the hard limit (same form section):

- **Alert threshold (%)** — default 80. When usage crosses this percentage of a limit, a warning is logged and `UsageThresholdEvent::ALERT` is dispatched. Leave empty to disable alerts.
- **Limit grace (%)** — default off. Lets usage exceed the limit by up to this percentage before blocking (e.g. 10 blocks at 110%). Empty or 0 blocks exactly at the limit. Exhaustion logs a warning and dispatches `UsageThresholdEvent::EXHAUSTED`.

Each event fires **once per server per day**, not on every call: once a server is over its threshold every subsequent request would re-qualify, so dispatches are deduplicated in State (see below). Blocked calls themselves keep failing with `AiQuotaException` — only the event is deduplicated.

## Reacting to events

Both events (`Drupal\ai_provider_universal_router\Event\UsageThresholdEvent`) carry:

| Property | Meaning |
|---|---|
| `serverId` | the `ai_universal_server` entity id |
| `metric` | which limit was crossed: `requests` or `tokens` |
| `usage` | today's usage for that metric (requests, or input + output tokens combined) |
| `limit` | the configured daily limit |

Subscribe with a normal event subscriber to send mail/Slack notifications or trigger ECA workflows:

```php
public static function getSubscribedEvents(): array {
  return [
    UsageThresholdEvent::ALERT => 'onAlert',
    UsageThresholdEvent::EXHAUSTED => 'onExhausted',
  ];
}
```

The once-per-day deduplication is tracked in State (`ai_provider_universal_router.usage_alert.<server>` / `...usage_exhausted.<server>`), so re-saving config or clearing caches does not re-fire alerts.

## Gating calls yourself (pre-call event)

For custom rules beyond the built-in daily limits (business hours, per-role quotas, compliance), subscribe to `ModelPreCallEvent` — dispatched by the main module before **every** inference call, after smart-route resolution, so you always see the concrete model id. You can block the call or swap the model:

```php
use Drupal\ai_provider_universal\Event\ModelPreCallEvent;

public static function getSubscribedEvents(): array {
  return [ModelPreCallEvent::EVENT_NAME => 'onPreCall'];
}

public function onPreCall(ModelPreCallEvent $event): void {
  if ($event->getOperationType() === 'chat' && $this->outsideBusinessHours()) {
    // Either block the call entirely (throws AiRequestErrorException) ...
    $event->block('Chat is disabled outside business hours.');
    // ... or swap to a cheaper model instead:
    // $event->setModelId('local.llama3_8b');
  }
}
```

Unlike the threshold events above, this event fires on every call (no deduplication) — it is a gate, not a notification.

Related events on the main module (full table in [smart-routing.md](smart-routing.md#events)):

- `ModelPostCallEvent` — after a successful chat call (tokens + latency + tags) for custom telemetry.
- `ModelsDiscoveredEvent` — during discovery, before models are saved, to inject site pricing or drop models.
