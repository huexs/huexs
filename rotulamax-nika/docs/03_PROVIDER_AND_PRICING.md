# 03 — Proveedores, evidencia y precio

Fecha: 2026-08-12. Estado: `IMPLEMENTADO CON VALORES PROVISIONALES`.

## Regla base

**Sin evidencia de coste no hay precio.**

`buildEstimate` devuelve `null` antes que improvisar un número. Un asistente
que rellena el hueco con una cifra «razonable» produce presupuestos que nadie
puede defender delante de un cliente.

## Qué cuenta como evidencia

Un `ProviderEvidence` válido necesita:

| Requisito | Comprobación |
|---|---|
| Proveedor en el registro | `evidence.unknown-provider` |
| Enlace `http(s)` comprobable a mano | `evidence.bad-url`, `evidence.bad-scheme` |
| Coste unitario > 0 | `evidence.bad-cost` |
| Cantidad entera positiva | `evidence.bad-quantity` |
| Fecha válida, no futura | `evidence.bad-date`, `evidence.future-date` |
| Dentro de la vigencia (30 días) | `evidence.stale` |

Métodos aceptados: `catalog`, `tariff`, `manual`, `email`.

Un proveedor marcado `requiresForm: true` obliga a que **una persona** capture
el coste. Nika no navega formularios de proveedor con selectores no
inspeccionados.

## Cálculo

```
precio unitario = coste unitario / (1 - margen objetivo)
                → redondeo al alza
subtotal        = precio unitario × cantidad
                → se aplica el pedido mínimo si procede
total           = subtotal × (1 + IVA)
```

Con varias evidencias se toma **la más barata**; el margen se aplica encima.

Cada estimación guarda `appliedRules` con qué evidencia se usó, qué margen se
aplicó y qué reglas entraron. Si algún dato de entrada es inferido, se marca
`hasInferredInputs` y el borrador lo declara como supuesto.

## Estado del catálogo

`src/providers/registry.ts` contiene **tres proveedores genéricos de relleno**,
marcados `PENDIENTE DE CONFIRMAR`. No son proveedores reales de RotulaMax.

Mientras el catálogo sea provisional, cualquier producto que no encaje en las
familias listadas escalará con `escalate.no-provider`. Eso es correcto: es
mejor que inventar un proveedor.

Para poner esto en producción hace falta la lista real. Ver
`docs/07_OPEN_QUESTIONS.md` Q3.

## Valores comerciales

Todos provisionales y marcados `OWNER_DECISION` en `src/config/policy.ts`:

| Valor | Provisional |
|---|---|
| Margen objetivo | 45 % |
| Pedido mínimo | 60 € |
| IVA | 21 % |
| Umbral de escalado por importe | 1.500 € |

**No se cambian sin decisión documentada de la propiedad.**
