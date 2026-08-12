# RotulaMax · Nika — reglas para quien toque este repositorio

Nika es el asistente de presupuestos y operaciones de RotulaMax. Convierte un
correo entrante en un expediente (`QuoteCase`), decide si hay que hacer algo,
reúne evidencia de coste y **prepara** una propuesta.

## La regla que no se toca

**Nika prepara. Una persona revisa. Una persona envía.**

Ningún cambio puede hacer que el sistema envíe correo, curse un pedido,
confirme un pago o lance producción. Los borradores nacen y mueren con
`status: 'pending_review'`. Si un cambio necesita saltarse esto, no se hace: se
documenta y se pregunta.

## Las dos preguntas que nunca se mezclan

El motor de decisión (`src/triage/decide.ts`) responde a dos cosas distintas:

| Pregunta | Campo | Por defecto |
|---|---|---|
| ¿Preparamos una respuesta al cliente? | `reply` | **no** |
| ¿Tiene que decidir una persona antes de nada? | `escalate` | **no** |

Las dos puertas están **cerradas por defecto** y solo se abren con un motivo
explícito que queda escrito en `decision.trace`.

Confundirlas produce los dos fallos que motivaron este proyecto:

- **responder siempre** — sin lista cerrada de intenciones respondibles y sin
  antirrebote por hilo, todo correo entrante genera un correo saliente:
  también los acuses, las autorespuestas y la publicidad.
- **escalar siempre** — usar el escalado como comodín ante cualquier duda.
  Que falte una medida **no** es un caso para una persona: es un caso para
  preguntar al cliente.

## Qué escala y qué no

Escala (una persona decide antes de que exista borrador):

- importe estimado por encima del umbral;
- reclamación o incidencia;
- lenguaje legal (burofax, abogado, RGPD…);
- producto sin proveedor que lo cubra;
- mensaje que no se entiende (`unknown` o confianza baja);
- hilo que ya lleva demasiados mensajes sin cerrarse;
- datos que faltan **después** de haberlos pedido el máximo de veces.

No escala (Nika lo resuelve preparando un borrador):

- faltan medidas, cantidad, producto o dirección → se **pregunta**;
- una medida es ambigua (`120x80` sin unidad) → se **pide confirmación**;
- el cliente no contesta → se **recuerda**, y al agotar los intentos se cierra
  como perdido con motivo.

## Dónde se ajusta el comportamiento

Todo en `src/config/policy.ts`. Un solo fichero, con un comentario por valor.
Si Nika responde de más o escala de más, se toca ahí — no en el motor.

Los valores marcados `OWNER_DECISION` (margen, IVA, pedido mínimo, umbral de
importe) son comerciales: los fija la propiedad, no un desarrollador ni el
agente. Están recogidos en `docs/07_OPEN_QUESTIONS.md`.

## Límites técnicos

- Medidas y dirección de entrega **no se adivinan**. Lo que no es explícito se
  marca `inferred` con confianza baja, y esa confianza baja es lo que hace que
  Nika pregunte en lugar de presupuestar a ciegas.
- **Sin evidencia de coste no hay precio.** `buildEstimate` devuelve `null`
  antes que improvisar un número.
- Los enlaces de proveedor deben ser `http(s)` comprobables a mano.
- Las palabras clave se buscan **por palabra completa**, nunca por subcadena
  («Barcelona» no es el producto «lona»).
- No se automatizan formularios de proveedor con selectores sin inspeccionar.
- Sin secretos en el repositorio, en la documentación ni en los prompts.

## Antes de dar por terminado un cambio

```bash
npm run check   # tipos
npm test        # 86 pruebas
npm run demo    # replay sobre la bandeja de ejemplo, sin efectos
```

Las pruebas no crean borradores reales, no envían correo y no acceden a
formularios de proveedor en vivo.

Ante una ambigüedad de negocio: se documenta en
`docs/07_OPEN_QUESTIONS.md` y se pregunta. No se elige por cuenta propia.
