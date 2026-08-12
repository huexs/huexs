# RotulaMax · Nika

Asistente de presupuestos y operaciones de RotulaMax.

Recibe correos de leads, los analiza **en el contexto del hilo completo**, saca
los datos críticos, reúne evidencia de coste de proveedores y prepara una
propuesta. Hace seguimiento hasta que el lead se convierte en cliente o se
descarta con un motivo.

**Nika prepara. Una persona revisa. Una persona envía.** No envía correo, no
cursa pedidos y no confirma pagos.

---

## Arranque rápido

```bash
npm install
npm run check   # comprobación de tipos
npm test        # 86 pruebas
npm run demo    # analiza la bandeja de ejemplo y explica cada decisión
```

`npm run demo` no toca nada externo: lee `fixtures/threads/*.json` y muestra,
para cada hilo, qué decidió Nika y por qué.

---

## El problema que resuelve este diseño

Un asistente de correo mal diseñado falla siempre igual: **responde a todo** y
**escala todo**. Al final alguien tiene que revisar más trabajo que antes de
tener el asistente.

Aquí las dos decisiones están separadas y ambas están **cerradas por defecto**:

```
                  ┌─ ¿preparamos respuesta? ──── reply    (por defecto: NO)
correo entrante ──┤
                  └─ ¿decide una persona? ────── escalate (por defecto: NO)
```

Cada apertura de una de las dos puertas deja un motivo en `decision.trace`, así
que siempre se puede responder a «¿por qué contestó a esto?» y «¿por qué me
mandó esto a mí?».

### Cómo se evita "responde siempre"

| Puerta | Corta |
|---|---|
| Lista cerrada de intenciones respondibles | publicidad, autorespuestas, correo interno, mensajes de proveedor |
| Detección de acuse | «recibido, gracias» no genera respuesta |
| Deduplicación por `messageId` | el mismo correo no se contesta dos veces |
| Un borrador abierto por hilo | no se acumulan respuestas sin revisar |
| Límite diario + enfriamiento | una ráfaga de tres correos produce **un** borrador |
| Nunca se responde a sí misma | los mensajes salientes se ignoran |

### Cómo se evita "escala siempre"

El escalado tiene una lista corta y explícita de motivos. Todo lo demás se
resuelve preparando un borrador:

| Situación | Antes | Ahora |
|---|---|---|
| Faltan medidas / cantidad / dirección | escalado | **se pregunta al cliente** |
| Medida ambigua (`120x80` sin unidad) | escalado | **se pide confirmación** |
| El cliente no contesta | escalado | **recordatorio, y cierre con motivo** |
| Un mensaje del hilo solo aporta un dato | escalado | **se lee en contexto del hilo** |
| Importe alto, riesgo legal, reclamación | escalado | escalado (correcto) |

---

## Flujo

```
Gmail / formulario
  → normalización de entrada
  → QuoteCase canónico
  → clasificación de intención (del hilo, no solo del último correo)
  → extracción de datos críticos con confianza y rastro
  → decisión: reply / escalate
  → evidencia de proveedor + cálculo determinista
  → borrador candidato (pending_review)
  → revisión humana
  → envío humano
```

## Estados del expediente

```
NEW ──► NEEDS_INFO ──► QUOTING ──► QUOTED ──► NEGOTIATING ──► WON
 │           │                        │            │
 └──► NOT_A_LEAD                      └────────────┴──► LOST (con motivo)
                       ON_HOLD ◄── escalado
```

## Mapa del código

| Área | Fichero |
|---|---|
| Modelo canónico | `src/types.ts` |
| **Política ajustable** | `src/config/policy.ts` |
| **Motor de decisión** | `src/triage/decide.ts` |
| Clasificación de intención | `src/triage/classify.ts` |
| Extracción de datos críticos | `src/triage/extract.ts` |
| Proveedores y evidencia | `src/providers/registry.ts` |
| Precio | `src/pricing/estimate.ts` |
| Borradores | `src/email/draft.ts` |
| Seguimiento | `src/followup/schedule.ts` |
| Expedientes | `src/case/store.ts` |
| Orquestación | `src/pipeline.ts` |
| CLI | `src/cli.ts` |

## Ajustes

Todo el comportamiento vive en `src/config/policy.ts`, con un comentario por
valor explicando qué pasa al subirlo o bajarlo. Para ver la política efectiva:

```bash
npm run nika -- policy
```

## Documentación

- `AGENTS.md` — reglas para quien toque el repositorio
- `docs/01_TRIAGE_AND_AUTONOMY_POLICY.md` — las dos puertas, en detalle
- `docs/02_CASE_MODEL.md` — expediente, estados y trazabilidad
- `docs/03_PROVIDER_AND_PRICING.md` — evidencia y precio
- `docs/07_OPEN_QUESTIONS.md` — **decisiones pendientes de la propiedad**

## Lo que falta para producción

Está en `docs/07_OPEN_QUESTIONS.md`. En resumen: conexión real a Gmail, la
lista real de proveedores con tarifas, y los valores comerciales
(margen, IVA, pedido mínimo, umbral de escalado) confirmados por la propiedad.
