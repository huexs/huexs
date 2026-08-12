# 01 — Política de triaje y autonomía

Fecha: 2026-08-12. Estado: `IMPLEMENTADO`.

Este documento describe cuándo Nika actúa, cuándo calla y cuándo pide a una
persona que decida. Es la referencia de comportamiento; el código que lo
implementa es `src/triage/decide.ts` y los valores están en
`src/config/policy.ts`.

## 1. Principio

Nika toma **dos decisiones independientes** por cada evaluación:

1. `reply` — ¿hay que preparar un borrador para el cliente?
2. `escalate` — ¿tiene que decidir una persona antes de que exista borrador?

Ambas son **falsas por defecto**. Cada una solo se abre con un motivo
explícito, y ese motivo se escribe en `decision.trace` (o
`decision.escalationReasons`).

La razón de separarlas: mezcladas, cualquier duda acaba siendo o bien una
respuesta automática innecesaria, o bien una tarea para una persona. Las dos
cosas destruyen el valor del asistente.

## 2. Las siete puertas

La evaluación atraviesa siete puertas en orden. La primera que corta, decide.

### Puerta 1 — ¿es esto una conversación con un cliente?

Se descarta sin responder:

| Caso | Acción |
|---|---|
| El mensaje es nuestro (`direction: outbound`) | `NO_ACTION` |
| Ya se procesó ese `messageId` | `NO_ACTION` |
| Autorespuesta, ausencia, publicidad, correo interno | `ARCHIVE` |
| Mensaje de un proveedor | `NO_ACTION` (se registra como evidencia) |
| Acuse de recibo del cliente | `NO_ACTION` |

Señales usadas, en orden de prioridad: cabeceras `Auto-Submitted`,
`List-Unsubscribe` y `Precedence`; buzón remitente (`no-reply@`,
`postmaster@`…); dominio remitente; y por último el léxico.

Las señales de máquina son **absolutas**: nunca se sobrescriben por contenido.

### Puerta 2 — ¿entendemos qué pide?

Si la intención es `unknown` o la confianza está por debajo de
`minIntentConfidence` (0,55), el caso **escala sin responder**. No se contesta
a ciegas.

Antes de llegar aquí se aplica la lectura de hilo (`classifyThread`): un
mensaje que aislado no dice nada («Envío a Calle X, 08008 Barcelona») hereda la
intención ya establecida en el hilo. Sin esto, cada ampliación de datos de un
lead legítimo acababa escalada.

Si la intención se entiende pero no está en `repliableIntents`, no se responde.

### Puerta 3 — antirrebote

Aunque haya que responder, quizá no toca ahora:

| Regla | Valor por defecto |
|---|---|
| Un borrador abierto por hilo | sí |
| Borradores por hilo y día | 1 |
| Horas mínimas entre borradores | 4 |

Esto es lo que convierte una ráfaga de tres correos del mismo cliente en **un**
borrador, no tres.

### Puerta 4 — escalado

Solo estos motivos escalan:

| Código | Motivo |
|---|---|
| `escalate.amount` | importe estimado > umbral |
| `escalate.complaint` | reclamación o incidencia |
| `escalate.legal` | burofax, abogado, RGPD, demanda… |
| `escalate.custom-product` | fuera de catálogo (obra, homologación…) |
| `escalate.no-provider` | ningún proveedor cubre el producto |
| `escalate.long-thread` | el hilo no avanza tras muchos mensajes |
| `escalate.info-exhausted` | faltan datos **tras** preguntarlos el máximo de veces |
| `escalate.unclear-intent` | no se entiende el mensaje (Puerta 2) |

Nada más. Si aparece un escalado sin uno de estos códigos, es un fallo.

### Puerta 5 — seguimiento programado

Sin correo nuevo, por vencimiento de fecha. Requiere estado seguible
(`NEEDS_INFO`, `QUOTED`, `NEGOTIATING`), silencio cumplido (48 h) y ningún
borrador pendiente. Al agotar `maxAttempts` (3) el caso se cierra como `LOST`
con `lostReason: 'no_response'`. **Cerrar no es escalar.**

### Puerta 6 — faltan datos críticos

Los campos críticos son: producto, medidas, cantidad y dirección de entrega.

Un campo cuenta como ausente si no está **o** si su confianza es menor que
`minFieldConfidence` (0,6). Un campo confirmado por una persona
(`source: 'human'`) siempre vale, tenga la confianza que tenga.

Si falta alguno → `ASK_INFO`: se prepara una petición de datos concreta, con
las preguntas exactas y con lo que ya hemos anotado del mensaje del cliente.
**Esto no es un escalado.**

### Puerta 7 — preparar propuesta

Con todos los datos, `PREPARE_QUOTE`. Si no hay evidencia de coste válida no
se inventa un precio: el caso queda en `QUOTING` con el evento `quote.blocked`
hasta que se capture la evidencia.

## 3. Confianza

- La confianza de una decisión `ASK_INFO` es la de la intención, no la de los
  datos que faltan: por definición, lo que falta tiene confianza cero.
- La confianza de una decisión `PREPARE_QUOTE` es el mínimo entre la confianza
  de la intención y la del campo crítico peor.
- La confianza de la clasificación baja cuando dos intenciones puntúan
  parecido. Un empate no es una certeza: es exactamente el caso que debe ver
  una persona.

## 4. Detalles de implementación con consecuencias comerciales

- **Palabra completa, nunca subcadena.** «Barcelona» contiene «lona». Sin
  límites de palabra, una dirección de entrega se convertía en un producto, y
  ese producto llegaba hasta el precio final.
- **Se tolera el plural** al final de la palabra («lona» encuentra «lonas»),
  pero no el prefijo.
- **Se ignora el texto citado.** Reprocesar la cita del mensaje anterior en
  cada correo hace que Nika reclasifique texto viejo y responda de nuevo a algo
  ya resuelto.
- **Un dato explícito nunca lo pisa uno inferido**, aunque el inferido sea más
  reciente.
- **«Gracias» al final de una petición no la convierte en un acuse.**
- **Un mensaje corto sin palabras de acuse no es un acuse**: es un mensaje que
  no entendemos, y va a revisión en lugar de archivarse en silencio. Archivar
  por defecto pierde leads reales.

## 5. Cómo ajustarlo

Todo en `src/config/policy.ts`.

| Síntoma | Qué tocar |
|---|---|
| Responde a cosas que no debería | quitar intenciones de `repliableIntents`; ampliar `neverReplyDomains` / `neverReplyLocalParts` |
| Contesta demasiado seguido | bajar `maxDraftsPerThreadPerDay`, subir `minHoursBetweenDrafts` |
| Escala demasiado | subir `amountThreshold`, subir `maxInfoRequestsBeforeEscalation`, bajar `minFieldConfidence` |
| Escala poco (deja pasar cosas serias) | bajar `amountThreshold`, ampliar `legalKeywords` / `complaintKeywords` |
| Pregunta datos que no necesita | reducir `CRITICAL_FIELDS` en `src/types.ts` |
| Persigue demasiado a los clientes | bajar `maxAttempts`, ampliar `cadenceDays` |
