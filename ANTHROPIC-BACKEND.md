# Anthropic (Claude) backend — novedades y guía de prueba

Estado: **implementado, sin verificar contra la API real.** 109 tests kernel/unit
en verde con HTTP mockeado, phpcs/phpstan/cspell limpios. Falta exactamente lo
que este documento explica cómo hacer: correrlo contra `api.anthropic.com` con
una key de verdad.

Nada de esto está commiteado todavía.

---

## Qué se agregó

### 1. Inferencia nativa (no-OpenAI) — el seam

Hasta ahora el provider despachaba **todo** chat sobre el protocolo OpenAI, así
que un backend de un servicio con otro protocolo aparecía en el catálogo pero no
podía ejecutar una llamada. Eso era una limitación documentada en el ROADMAP.

Ahora un backend puede **adueñarse de la ejecución** implementando
`AiInferenceBackendInterface` (`src/Backend/AiInferenceBackendInterface.php`, un
solo método). Es **opt-in y aditivo**:

- Un backend que implementa la interfaz ejecuta el chat él mismo.
- Un backend que **no** la implementa —incluidos los diez existentes y cualquiera
  que haya escrito otro módulo— se despacha exactamente como antes. Agregar un
  backend nativo no cambia el comportamiento de ninguno.

Todo lo que rodea la llamada sigue siendo genérico y aplica a los dos caminos:
pre-call gate, límites de uso por servidor, registro de uso, `ModelPostCallEvent`,
smart routing, factcheck y governance.

### 2. Backend `anthropic` (Messages API nativa)

Primera implementación de la interfaz. Va por la Messages API nativa, no por la
capa de compatibilidad OpenAI de Anthropic, para acceder a lo que esa capa no
expone.

| Funcionalidad | Cómo |
|---|---|
| System prompt | Hoisted al campo `system`, desde `setSystemPrompt()` y desde mensajes con rol `system` |
| Multi-turno, visión, PDF | Content blocks de texto / imagen base64 / `document` base64; turnos consecutivos del mismo rol se mergean; archivos no-PDF se descartan |
| Tool calling | Ida y vuelta: definiciones de función de AI core → tools de Anthropic; `tool_use` → salidas de tool de AI core; resultado → bloque `tool_result` en turno user |
| Structured output | Anthropic no tiene `response_format`: se emula con un tool forzado y sus argumentos vuelven como texto del mensaje (mismo JSON que daría OpenAI) |
| Extended thinking | Reasoning effort del modelo → budget `thinking` (low 2048, medium 8192, high 16384); sube `max_tokens` y saca sampling porque la API rechaza ambos juntos |
| Streaming | SSE parseado al iterator de AI core; los thinking deltas no se filtran al texto |
| Prompt caching, tools server-side, `service_tier`, `metadata` | Extra request parameters por modelo: las claves desconocidas pasan verbatim |
| Tokens | `input_tokens`/`output_tokens` alimentan límites y el dashboard de ahorros; `cache_read_input_tokens` se registra como cached |

Dos cosas que **no** son limitaciones nuestras sino de Anthropic:

- **Parámetros solo-OpenAI se descartan**, no se reenvían: `frequency_penalty`,
  `presence_penalty`, `logit_bias`, `seed`, `n`, `response_format`… Anthropic
  rechaza parámetros desconocidos, así que reenviarlos rompería el request.
  `stop` se traduce a `stop_sequences`.
- **Solo chat es nativo.** Anthropic no sirve embeddings, speech ni imágenes, así
  que `detectOperationTypes()` reporta `chat` únicamente. Pedir otra operación a
  un server Anthropic se **rechaza con explicación** (`AiMissingFeatureException`),
  no se manda a un endpoint que no existe. Consecuencia práctica: **`ai_search`
  necesita otro server para embeddings.**

### 3. Arreglos colaterales encontrados en la auditoría

- **`setChatSystemRole()`** ahora llega al backend nativo (el camino OpenAI lo
  inyectaba al armar su payload; el nativo lo perdía en silencio).
- **Operaciones no-chat** contra un backend nativo se rechazan con un mensaje
  claro en vez de un 404 confuso desde un endpoint inexistente.

---

## Cómo probarlo

### Requisitos

- Una API key de Anthropic (https://console.anthropic.com/).
- El módulo habilitado. El submódulo `router` solo si querés probar smart routing
  / límites; `factcheck` y `governance` solo si querés probar esas piezas.

### Paso 0 — Cargar la key

**Configuración → Sistema → Keys** (`/admin/config/system/keys`) → *Add key*:
guardá el token de Anthropic como una Key entity. (Igual que cualquier otra key
del módulo; se manda como header `x-api-key`, no como Bearer — el backend lo hace
solo.)

### Paso 1 — Crear el server

**Configuración → AI → Providers → Universal** (`/admin/config/ai/providers/universal`)
→ *Add server*:

- **Backend:** `Anthropic (Claude)`
- **Host / Port:** dejar vacío (endpoint fijo `api.anthropic.com/v1`). Solo se
  completa si apuntás a un gateway/proxy que hable Messages API.
- **API Key:** la del paso 0.
- **Timeout:** 600 está bien.

Al guardar se prueba la conexión (llama a `listModels()`) y corre discovery. Si la
key está mal, el guardado se bloquea con el error exacto de la API, logueado en el
canal `ai_provider_universal` (**Reports → Recent log messages**).

**Qué verificar acá — esto valida catálogo, paginación, headers y auth:**

```bash
drush aip:discover-models <server_id>   # alias: aipdm
```

Deberías ver los modelos Claude que tu cuenta puede llamar.

### Paso 2 — Revisar la metadata prellenada ⚠️

En la sección **Models** del formulario del server, abrí un par de modelos. El
discovery prellena costo, context y catalog features desde una **tabla en código
por generación de Claude** (Anthropic no publica precios en `/v1/models`).

**Verificá los precios contra https://www.anthropic.com/pricing** — si están
desactualizados, el smart router compara costos mal. Se editan a mano por modelo;
tus ediciones sobreviven a re-discovery.

### Paso 3 — Chat básico (mapeo + contadores)

```bash
drush aip:chat "Explain Drupal's render cache in two sentences." <server_id>.<model>
```

Ej.: `drush aip:chat "Hola, ¿quién sos?" claude.claude_sonnet_4_5_20250929`

Al final imprime `tokens in/out` — si aparecen números y no `?/?`, el `usage` de
Anthropic se mapeó bien y los límites diarios van a poder contar.

Con system prompt:

```bash
drush aip:chat "¿Quién sos?" <server>.<model> --system="Respondé siempre en catalán."
```

### Paso 4 — Extended thinking

Duplicá el modelo para tener una config aparte (así no tocás la base):

```php
// drush php:script thinking.php
$s = \Drupal::entityTypeManager()->getStorage('ai_universal_model');
$base = $s->load('<server>.<model>');
$dup = $base->createDuplicate();
$dup->set('id', '<server>.<model>_thinking')->set('label', 'Claude (thinking)');
$dup->setReasoning('high');
$dup->save();
```

```bash
drush aip:chat "Prove that sqrt(2) is irrational." <server>.<model>_thinking
```

Debería tardar más y razonar mejor. (Los tokens de thinking cuentan como output.)

### Paso 5 — Extra request parameters (passthrough)

Otra duplicación, esta vez con web search server-side de Anthropic. En el modelo,
campo **Extra request parameters (YAML)**:

```yaml
tools:
  - type: web_search_20250305
    name: web_search
    max_uses: 3
```

```bash
drush aip:chat "What did Anthropic announce this week?" <server>.<model>_websearch
```

Si contesta con información actual, el passthrough de extra params llega a la API
nativa. (Verificá el `type`/versión de la tool contra los docs de Anthropic —
cambian.)

### Paso 6 — Que lo genérico sigue funcionando

- **Streaming:** cualquier consumidor que pida stream (un chatbot de AI, un bloque)
  contra el modelo Claude. El texto debe llegar incremental.
- **Smart routing** (submódulo router): poné el modelo Claude como candidato de una
  ruta junto a otros; el router debería elegirlo según tier/costo. **Ponele un costo
  más alto que a los locales**, si no lo elige para todo.
- **Límites de uso** (router): poné un límite diario bajo en el server y disparalo;
  la siguiente llamada debe fallar con `AiQuotaException`.
- **Factcheck / governance:** si los tenés activos, corren por fuera de nuestro
  `chat()`, así que aplican igual — verificalo con un modelo Claude como checker.

### Paso 7 — Que rechaza lo que no puede

```bash
# Esto DEBE fallar con un mensaje claro, no con un 404:
drush ev '$p = \Drupal::service("ai.provider")->createInstance("universal");
print_r($p->embeddings("hola", "<server>.<model>"));'
```

Esperado: `AiMissingFeatureException` diciendo que el backend solo sirve chat.

---

## Checklist de verificación en vivo

- [ ] `drush aipdm <server>` lista modelos Claude
- [ ] Precios prellenados verificados contra anthropic.com/pricing
- [ ] `drush aip:chat` responde y muestra tokens in/out reales
- [ ] `--system` cambia el comportamiento
- [ ] Modelo con `reasoning: high` razona (tarda más)
- [ ] Extra params (web search) llegan a la API
- [ ] Streaming entrega texto incremental
- [ ] Smart routing elige el modelo por tier/costo
- [ ] Límite diario dispara `AiQuotaException`
- [ ] Operación no-chat rechazada con mensaje claro

Cuando esto pase, quitar de ROADMAP.md y CHANGELOG.md la nota "not yet verified
against the live API".

---

## Referencia de archivos

| Archivo | Qué |
|---|---|
| `src/Backend/AiInferenceBackendInterface.php` | El seam (opt-in) |
| `src/Plugin/AiServerBackend/Anthropic.php` | El backend |
| `src/Chat/AnthropicStreamedChatMessageIterator.php` | Parser SSE de streaming |
| `src/Plugin/AiProvider/UniversalProvider.php` | `executeChat()` (dispatch), `withChatSystemRole()`, `assertOpenAiProtocolOperation()` |
| `tests/src/Kernel/Plugin/AnthropicBackendTest.php` | 15 tests del mapeo request/response |
| `docs/adding-a-backend.md` | Guía para escribir otro backend nativo (Gemini) |
| `docs/servers-and-models.md` | Sección "Native inference backends" |
