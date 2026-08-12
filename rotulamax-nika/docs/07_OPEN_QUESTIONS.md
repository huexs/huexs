# 07 — Decisiones pendientes de la propiedad

Fecha: 2026-08-12.

Nika funciona hoy con valores provisionales. Estos son los que hacen falta para
pasar a producción. **Ninguno lo decide un desarrollador ni el agente.**

Mientras estén sin responder, el sistema funciona: solo funciona con los
valores de relleno documentados aquí.

---

## Q1 · Umbral de escalado por importe · `escalation.amountThreshold`

Provisional: **1.500 € (base imponible)**.

Por encima de esa cifra, Nika no prepara nada: pasa el caso a una persona.

- ¿Cuál es el importe a partir del cual quieres verlo tú antes?
- ¿Es el mismo para un cliente nuevo que para uno recurrente?

**Impacto de equivocarse:** demasiado bajo → vuelve el «escala siempre».
Demasiado alto → salen propuestas grandes sin criterio comercial.

---

## Q2 · Reglas de precio · `pricing.*`

Provisionales: margen **45 %**, pedido mínimo **60 €**, IVA **21 %**, redondeo
al alza a **1 €**.

- ¿Margen único, o distinto por familia de producto?
- ¿Pedido mínimo real?
- ¿Hay producto con IVA distinto del 21 %?
- ¿El transporte va incluido o es una línea aparte? (hoy **no** se calcula)
- ¿La instalación se presupuesta aparte? (hoy solo se detecta y se declara
  como «precio sin instalación»)

---

## Q3 · Catálogo real de proveedores

`src/providers/registry.ts` tiene tres proveedores **inventados** como relleno.

Hace falta, por cada proveedor real:

- nombre y familias de producto que cubre;
- de dónde sale el coste: tarifa PDF, catálogo web, configurador, correo;
- si requiere formulario o login;
- vigencia razonable de sus precios (hoy: 30 días).

**Esto es lo que más bloquea.** Sin catálogo real, cualquier producto fuera de
las tres familias de relleno escala por «sin proveedor».

---

## Q4 · Datos críticos

Hoy Nika considera críticos: **producto, medidas, cantidad y dirección de
entrega**. Sin los cuatro, pregunta en vez de presupuestar.

- ¿Es correcta esa lista?
- ¿La dirección de entrega hace falta para presupuestar, o solo para pedir?
  Si solo hace falta para pedir, quitarla de la lista reduce mucho el número
  de correos de «faltan datos».
- ¿Hay que pedir siempre el material, o se propone uno por defecto?

---

## Q5 · Cadencia de seguimiento

Provisional: presupuesto enviado → recordatorio a los **3, 7 y 14 días**;
petición de datos → **3 y 7 días**; máximo **3 intentos**; después se cierra
como perdido por silencio. No se programa en fin de semana.

- ¿Te encaja esa cadencia?
- ¿3 intentos es lo correcto, o prefieres 2?
- ¿Un caso cerrado por silencio debe reabrirse solo si el cliente escribe, o
  quieres verlo tú antes?

---

## Q6 · Conexión con Gmail

Hoy Nika lee hilos de ficheros JSON. No hay conexión real.

- ¿Qué buzón? ¿Uno solo o varios?
- ¿Sobre qué etiqueta trabaja?
- ¿El borrador se crea como borrador de Gmail en el hilo, o se revisa en un
  panel propio y se envía desde ahí?
- ¿Quién revisa y aprueba? ¿Una persona o varias?

**Nota:** un borrador de Gmail creado **no** es un correo enviado, ni una
respuesta, ni una venta. Cualquier métrica futura debe distinguirlo.

---

## Q7 · Dominios propios y de proveedor

Provisional: `rotulamax.com`, `rotulamax.es` como dominios propios; lista de
proveedores vacía.

- ¿Hay más dominios propios (alias, tiendas, marcas)?
- ¿Qué dominios son de proveedor? Los correos de esos dominios no se
  responden como si fueran clientes.

---

## Q8 · Firma y tono

Provisional: firma genérica que declara que es un borrador interno.

- ¿Qué firma quieres en los correos que salen tras revisión?
- ¿Tuteo o trato de usted? (hoy: tuteo en plural, «vosotros»)
- ¿Hay condiciones fijas que deban ir en todo presupuesto (validez, forma de
  pago, plazo de entrega)?

---

## Q9 · Alcance del proyecto anterior

El handoff describe un proyecto Nika ya desplegado en un VPS
(`147.93.55.220`, `/home/codexdev/projects/agente-rotulamax`), con panel
privado en `127.0.0.1:4173` y SQLite en
`~/.local/state/nika/email-pilot.sqlite`.

**No tengo acceso a ese VPS ni a ese repositorio desde esta sesión**, así que
este proyecto es una base nueva y limpia, no una modificación de aquel.

Hace falta decidir:

- ¿Esto sustituye al proyecto del VPS, o convive con él?
- Si sustituye: ¿hay que migrar los expedientes del SQLite existente?
- Si convive: ¿qué parte se queda con cuál?

Lo que sí es reutilizable tal cual es el sistema de diseño del panel
(`42_PANEL_DESIGN_SYSTEM.md`): la paleta navy/dorado, la semántica de color y
las reglas de accesibilidad siguen siendo válidas para el panel de revisión de
este proyecto.
