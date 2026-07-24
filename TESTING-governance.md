# Testing manual — Content governance (fases 1, 2, 3 y 4)

Guía para probar a mano todo lo nuevo de la rama `feature/content-governance`.
Archivo sin trackear — borralo cuando termines (`git clean` no lo toca si no
lo agregás).

**Commits a revisar antes de push:**

```bash
git log --oneline 1.0.x..HEAD
git show 4014d1a   # docs: alineación Art. 50
git show aafb77d   # fases 1, 3 (evento), 4 (disclosure + marker)
git show c92ff43   # fase 2 (scan profiles + queue)
git show b59dd00   # fix: tags internos (factcheck/classifier/verifier)
```

**Requisito:** `drupal/ai` ^1.3 (Guardrails).  
**Sitio recomendado:** `drupal-hackathon` (ai 1.4.x, UI completa de Guardrails).
En `drupal-test` (ai 1.3.5) todo funciona pero con un solo set por input.
En ambos, primero:

```bash
ddev drush updb -y    # corre ai_provider_universal_update_10102
ddev drush cr
```

---

## 1. Guardrail set attach (fase 1)

### 1.1 Preparar un set visible

1. `/admin/config/ai/guardrails` → crear un **Guardrail** con el plugin
   **Regexp Guardrail**, patrón `/pelota/i`, mensaje "Bloqueado por regexp".
2. Crear un **Guardrail set** "Set de prueba", agregarlo como **pre**
   guardrail, stop threshold 1.

### 1.2 Default del provider

1. `/admin/config/ai/providers/universal/governance` → elegir "Set de
   prueba" como **Default Guardrail set**. Guardar.
2. Ir al AI Explorer (`/admin/config/ai/explorers/chat_generation`, módulo
   `ai_api_explorer`) con un modelo del provider Universal.
3. Preguntar algo con la palabra "pelota" → la respuesta debe ser el mensaje
   de bloqueo del guardrail. Sin la palabra → respuesta normal.
4. **No-overwrite:** si un caller ya adjunta su propio set (p. ej. un
   chatbot configurado con otro set), el default NO se suma. Verificable en
   código: `GuardrailDefaultsSubscriber` solo actúa con
   `getGuardrailSets() === []`.
5. Vaciar el campo en governance → guardar → todo vuelve al comportamiento
   de beta1 (config vacía = no-op).

### 1.3 Set por ruta

1. Editar una smart route (`/admin/config/ai/providers/universal/routes`).
2. Nuevo campo **Guardrail set** → elegir un set distinto al default.
3. Chatear contra el modelo `route.<id>` → aplica el set de la ruta, no el
   default. Con "- Provider default -" → aplica el default global.

### 1.4 Tags internos (no deben heredar el default set)

Con el **Default Guardrail set** de 1.2 aún activo (el de `/pelota/`):

1. Content scan manual en un node (AI likelihood o fact check) → debe
   completar y devolver JSON/scores **sin** mensaje "Bloqueado por regexp",
   aunque el body o el prompt de herramienta contengan "pelota".
2. Si tenés smart route con classifier model / verifier: una decisión de
   ruta no debe bloquearse por el set default.
3. En código: tags `ai_provider_universal_factcheck`, `complexity_classifier`,
   `route_verifier` → `InternalChatTags::isInternal()`; el attach y el
   provenance los saltan.

---

## 2. Scan profiles + review queue (fase 2)

### 2.1 Perfil liviano

1. `/admin/config/ai/factcheck/scan-profiles` → **Add scan profile**:
   - Bundles: Article. Operations: ambas. Published only: sí.
   - Cooldown: 60 (para poder re-probar rápido).
   - Checks: solo **Readability** habilitado, alert below **100** (así
     siempre cruza el umbral y ves el evento).
   - Event: "Only when a threshold is crossed".
2. Crear un artículo publicado con un body de 3–4 oraciones.
3. Ver la cola: `ddev drush queue:list` → `aip_content_review` debe tener 1.
4. Procesar: `ddev drush queue:run aip_content_review` (o `drush cron`).
5. Resultado en `/admin/content/factcheck/results`: fila nueva con
   readability, y en details `source: scheduled_scan` + `thresholds_hit`.

### 2.2 Filtros baratos (lo que NO debe encolar)

- Guardar el mismo artículo **sin tocar el body** (solo título) → cola en 0.
- Editar el body dentro del cooldown → cola sigue en 0; esperar 60s y editar
  de nuevo → encola.
- Crear un artículo **despublicado** → no encola.
- Crear una página (bundle no incluido) → no encola.
- Deshabilitar el perfil → nada encola.

### 2.3 Evento de revisión (seam para ECA)

Sin armar un modelo ECA, se ve con un listener efímero:

```bash
ddev drush ev "
\Drupal::service('event_dispatcher')->addListener(
  'ai_provider_universal_factcheck.content_review',
  function (\$e) { echo 'REVIEW: nid=' . \$e->getEntityId() . ' perfil=' . \$e->getProfileId() . ' thresholds=' . implode(',', \$e->getThresholdsHit()) . PHP_EOL; }
);
// Procesa la cola en este mismo proceso para que el listener lo vea:
\$worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('aip_content_review');
\$queue = \Drupal::queue('aip_content_review');
while (\$item = \$queue->claimItem()) { \$worker->processItem(\$item->data); \$queue->deleteItem(\$item); }
"
```

(Encolá antes un artículo nuevo.) Con `event_on: never` en el perfil, el
resultado se guarda igual pero no imprime nada.

### 2.4 Checks pesados (opcional, gasta presupuesto LLM/API)

Con el checker model configurado en `/admin/config/ai/factcheck`, habilitar
**AI likelihood** y/o **Fact check** en el perfil y repetir 2.1 — los scores
aparecen en la misma fila de resultados.

---

## 3. Provenance (fase 3)

1. En `/admin/config/ai/providers/universal/governance` activar **Emit
   content provenance events**.
2. Ver el evento de generación (listener + chat en el mismo proceso):

```bash
ddev drush ev "
\Drupal::service('event_dispatcher')->addListener(
  'ai_provider_universal.content_provenance',
  function (\$e) { echo 'PROVENANCE: source=' . \$e->getSource() . ' model=' . \$e->getModelId() . ' server=' . \$e->getServerId() . ' op=' . \$e->getOperationType() . PHP_EOL; }
);
\$provider = \Drupal::service('ai.provider')->createInstance('universal');
\$input = new \Drupal\ai\OperationType\Chat\ChatInput([new \Drupal\ai\OperationType\Chat\ChatMessage('user', 'Say hi in three words.')]);
\$provider->chat(\$input, 'MODEL_ENTITY_ID', ['test']);
" # reemplazar MODEL_ENTITY_ID por un id real (drush aipdm o la UI de modelos)
```

Debe imprimir `source=generation` con modelo y server resueltos.

3. **Asociación a entidad** (la API para ECA/workflows):

```bash
ddev drush ev "
\Drupal::service('event_dispatcher')->addListener(
  'ai_provider_universal.content_provenance',
  function (\$e) { echo 'PROVENANCE: source=' . \$e->getSource() . ' entity=' . \$e->getEntityTypeId() . ':' . \$e->getEntityId() . ' field=' . \$e->getFieldName() . PHP_EOL; }
);
\$node = \Drupal::entityTypeManager()->getStorage('node')->load(1); // un nid existente
\Drupal::service('ai_provider_universal_governance.provenance')->recordAssociation(\$node, 'body', '', 'chat');
"
```

4. Desactivar el toggle → el chat ya no emite nada (default de beta1).

5. **Sin ruido de factcheck:** con provenance ON y default Guardrail set
   activo, corré un content scan (o el worker de un perfil con AI
   likelihood). **No** deben aparecer N líneas `PROVENANCE` por claim/tool
   call — solo chats “de usuario” (Explorer, chatbot, etc.).

```bash
# Escuchar provenance y forzar una llamada etiquetada como factcheck:
ddev drush ev "
\Drupal::service('event_dispatcher')->addListener(
  'ai_provider_universal.content_provenance',
  function (\$e) { echo 'UNEXPECTED: ' . \$e->getModelId() . PHP_EOL; }
);
\$provider = \Drupal::service('ai.provider')->createInstance('universal');
\$input = new \Drupal\ai\OperationType\Chat\ChatInput([new \Drupal\ai\OperationType\Chat\ChatMessage('user', 'ping')]);
\$provider->chat(\$input, 'MODEL_ENTITY_ID', ['ai_provider_universal_factcheck']);
echo \"done (should print nothing above)\\n\";
"
```

---

## 4. Plugins Guardrail: disclosure + marker (fase 4)

1. `/admin/config/ai/guardrails` → crear dos Guardrails:
   - **AI disclosure suffix (Universal)** — dejar el texto por defecto o
     personalizarlo.
   - **AI origin marker (Universal)** — default:
     `<!-- ai-origin: generated; digitalSourceType=trainedAlgorithmicMedia -->`.
2. Crear un set "Disclosure post", agregar ambos como **post** guardrails.
3. Elegir ese set como default en governance (o en una ruta).
4. Chatear por el AI Explorer:
   - La respuesta termina con el texto de disclosure (visible).
   - El marker es un comentario HTML: se ve en el texto crudo de la
     respuesta (en el Explorer se ve literal; en un chatbot renderizado,
     mirar el código fuente).
5. **No duplicación:** repreguntar / forzar una escalación de ruta — el
   suffix aparece una sola vez.
6. **Streaming:** con salida streamed los dos plugins pasan sin tocar nada
   (por diseño; divulgar en la UI del chat en ese caso).

---

## 5. Limpieza

```bash
ddev drush ev "
\Drupal::entityTypeManager()->getStorage('aip_scan_profile')->load('ID_DEL_PERFIL')?->delete();
"
# + borrar los artículos de prueba, los guardrails/sets de prueba,
# y vaciar el default en /admin/config/ai/providers/universal/governance
```

## 6. Qué mirar en el CI de drupal.org

- `phpunit`: corre contra ai 1.4.x (constraint `^1.3`) —

  los tests nuevos son duales 1.3/1.4, deberían pasar igual que acá.
- `phpstan`/`phpcs`/`cspell`: pasados localmente; el phpstan del CI tiene el
  autoloader de PHPUnit así que no verás los falsos positivos locales.
- Si `next major` (D12) falla en algo nuevo, sospechar de
  `EntityBase::$original` (el fallback de `ScanScheduler::textChanged()` ya
  prefiere `getOriginal()` cuando existe).
