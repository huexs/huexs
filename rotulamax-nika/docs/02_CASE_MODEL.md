# 02 — Expediente, estados y trazabilidad

Fecha: 2026-08-12. Estado: `IMPLEMENTADO`.

## El expediente (`QuoteCase`)

Un hilo de correo produce **un** expediente. Es la unidad de trabajo y la
fuente de verdad del caso.

| Campo | Para qué |
|---|---|
| `state` | dónde está el caso |
| `facts` | datos críticos, cada uno con valor, confianza, origen y evidencia |
| `infoRequests` | veces que hemos pedido datos; alimenta el escalado por agotamiento |
| `followUpsSent` | recordatorios enviados; alimenta el cierre por silencio |
| `processedMessageIds` | evita responder dos veces al mismo correo |
| `draftTimestamps` | alimenta el límite diario y el enfriamiento |
| `pendingDraftId` | hay un borrador esperando revisión |
| `providerEvidence` | costes capturados, con enlace comprobable |
| `estimate` | precio calculado, con las reglas aplicadas |
| `events` | rastro de auditoría |

## Estados

```
NEW ──► NEEDS_INFO ──► QUOTING ──► QUOTED ──► NEGOTIATING ──► WON
 │           │                        │            │
 └──► NOT_A_LEAD                      └────────────┴──► LOST
                       ON_HOLD ◄── escalado
```

- `NEEDS_INFO` — se pidieron datos y esperamos respuesta.
- `QUOTING` — datos completos; falta capturar evidencia de coste.
- `QUOTED` — propuesta preparada y revisada por una persona.
- `ON_HOLD` — escalado; espera decisión humana.
- `LOST` — cerrado, siempre con `lostReason`.

`WON` y `LOST` no admiten seguimiento automático.

## Datos con procedencia

Cada dato crítico es un `Field<T>` y lleva:

| Campo | Significado |
|---|---|
| `value` | el valor |
| `confidence` | 0..1 |
| `source` | `explicit` (lo dijo el cliente) · `inferred` (lo dedujimos) · `attachment` · `human` |
| `evidence` | el texto literal del que sale |
| `messageId` | de qué correo |

Esto es lo que permite que un borrador diga «cantidad: 1 (supuesto)» en lugar
de presentar una suposición como si fuera un dato del cliente. Un presupuesto
que confunde las dos cosas es un error comercial, no un detalle de formato.

**Medidas y dirección de entrega no se adivinan.** `120x80` sin unidad se
guarda como `inferred` con confianza 0,5, por debajo del umbral, y eso hace que
Nika pregunte.

## Trazabilidad

Toda decisión devuelve un `trace` con códigos estables (`gate1.noise`,
`gate6.ask-info`, `escalate.amount`…). Con eso siempre se puede responder:

- ¿por qué contestó a esto?
- ¿por qué **no** contestó a esto?
- ¿por qué me lo mandó a mí?

Los eventos del expediente (`events`) guardan lo mismo de forma persistente.

## Almacenamiento

`src/case/store.ts`, fichero JSON con escritura atómica (temporal + `rename`).
La interfaz es estrecha a propósito para poder cambiar a SQLite sin tocar el
resto.

Reglas operativas:

- los datos reales de producción **no** se usan como fixture de test;
- no se reinicializa el almacén «para empezar de cero».
