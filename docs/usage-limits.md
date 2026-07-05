# Usage tracking and limits

## Where limits live and why

Each **server** can carry a **daily request limit** and a **daily token limit** (input + output, all its models combined) — that is where the account/budget actually lives (OpenRouter credits, amazee.ai budget, a LiteLLM master key). Set them on the server form under "Usage limits".

Counters stay **per model** per day (site timezone) in the `ai_provider_universal_usage` table; the per-model breakdown is shown in the model overrides section of the server form, the server total in the "Usage limits" section.

## Enforcement

Enforcement lives in the **Smart Router submodule** (`ai_provider_universal_router`): when a server exhausts a limit,

- routes drop all its models from the candidate pool and **fail over to another provider**;
- direct calls to its models fail with `AiQuotaException` until the day rolls over.

Without the submodule, usage is still recorded but never blocks. The main module reaches the enforcer through the optional `ai_provider_universal_router.limits` service alias, so there is no hard dependency.

## Thresholds

Two optional per-server thresholds refine the hard limit (same form section):

- **Alert threshold (%)** — default 80. When usage crosses this percentage of a limit, a warning is logged and `UsageThresholdEvent::ALERT` is dispatched (once per server and day). Leave empty to disable alerts.
- **Limit grace (%)** — default off. Lets usage exceed the limit by up to this percentage before blocking (e.g. 10 blocks at 110%). Empty or 0 blocks exactly at the limit. Exhaustion logs a warning and dispatches `UsageThresholdEvent::EXHAUSTED` (once per server and day).

## Reacting to events

Both events (`Drupal\ai_provider_universal_router\Event\UsageThresholdEvent`) carry:

| Property | Meaning |
|---|---|
| `serverId` | the `universal_server` entity id |
| `metric` | which limit was crossed: `requests` or `tokens` |
| `usage` | today's usage for that metric |
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
