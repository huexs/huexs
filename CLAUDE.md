# Contexto del proyecto — Huexs / RIA Money Transfer

## Qué es esto

Herramienta interna para técnicos de Huexs que trabajan instalando señalética para agentes de RIA Money Transfer. Permite buscar agentes, fotografiar y medir sus fachadas con calibración px/cm, y guardar las mediciones automáticamente en Google Sheets + Trello + Google Drive.

---

## Archivos principales

| Archivo | Descripción |
|---------|-------------|
| `estimador-ria.html` | Herramienta completa (single-file vanilla JS). Servida en huexs.com/ria |
| `n8n-workflows/W1-nuevo-agente.json` | Workflow n8n: nueva fila Sheets → Drive + Trello + Contacts + escribe URLs |
| `n8n-workflows/W2-guardar-mediciones.json` | Workflow n8n: webhook POST → guarda mediciones en Sheets + comenta Trello + sube imagen a Drive |
| `n8n-workflows/W3-crear-recursos-manual.json` | Workflow n8n: webhook POST → crea Drive/Trello/Contact on-demand (idempotente) |
| `n8n-workflows/SETUP.md` | Guía de configuración completa de n8n |

---

## IDs y URLs críticos

### Google Sheets
- **Spreadsheet ID:** `1eyn80V0MSv2cPYYNC2OXkYtjR6tE1rNWe9Buv_gWBss`
- **Sheet GID:** `1287637480`
- **Sheet name:** `AGENTES RIA 2026`
- **Columnas existentes originales:** Número de agente (A), Nombre de Contacto (B), Dirección (C), Ciudad (D), Provincia (E), Código postal (F), Teléfono (G), Branding (H)
- **Columnas añadidas (al final):** URL Carpeta Drive (O), URL Trello (P), Mediciones, Mediciones JSON, Escala px/cm, Referencia usada, Fecha medición

### n8n
- **Base URL:** `https://n8n-n8n.te2fhz.easypanel.host`
- **W1 ID:** `xtqsHGzK6z15YfN5` — trigger: Google Sheets polling 1 min
- **W2 ID:** `hFWwy6YHp8iq3RIg` — webhook: `POST /webhook/ria/measurements`
- **W3 ID:** `eVB52gyJcYQ1SqZj` — webhook: `POST /webhook/ria/create-agent`

### Trello
- **Board ID:** `lSevGfHk` — "Ria Money Transfer AGENTES"
- **Lista "Contactar" ID:** `6577274e636e7cc8419d0d4a`

### Google Drive
- **Carpeta raíz agentes:** `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz`
- **Subcarpetas creadas por agente:** 01 Fotografías, 02 Diseños, 03 Presupuesto, 04 Producción, 05 Instalación, 06 Facturación

### Credenciales n8n
- **Google Sheets/Drive OAuth2:** `B8Oo1U1KfF3e4NNv` (Google Sheets Trigger — Huexs, cuenta huexss@gmail.com)
- **Google People/Contacts:** `nqbkR2cOGYotmr9Z`
- **Trello:** ver Settings → Credentials en n8n

---

## Cómo funciona el estimador (estimador-ria.html)

### Carga de agentes
- Usa Google Sheets Visualization API (gviz/tq) via JSONP
- Carga por GID (`gid=1287637480`), NO por nombre de hoja
- Cache-busting con `_: Date.now()`
- Solo carga la hoja `AGENTES RIA 2026`

### Flujo de uso
1. Técnico busca el agente por nombre o ID
2. Sube foto de la fachada → aparece en canvas
3. Selecciona referencia de calibración (vinilo RIA, puerta, etc.) y marca sus extremos
4. Añade productos a medir (vinilo, lona, LED, etc.)
5. Pulsa "Guardar" → POST a W2 con mediciones + imagen base64
6. Si el agente no tiene Drive/Trello → botón naranja "Crear carpeta Drive y tarjeta Trello" → POST a W3

### Payload W2 (guardar mediciones)
```json
{
  "agent_id": "ES16756",
  "agentName": "Nombre del agente",
  "trelloCardId": "id_de_la_tarjeta",
  "scale_px_cm": 12.5,
  "ref_label": "Vinilo circular RIA (50 cm)",
  "productos": [{"label": "Vinilo fachada", "anchoCm": 120.5, "altoCm": 80.2}],
  "imagen_base64": "data:image/jpeg;base64,...",
  "sheet_name": "AGENTES RIA 2026",
  "timestamp": "2026-06-29T10:00:00Z"
}
```

### Payload W3 (crear recursos)
```json
{
  "agent_id": "ES16756",
  "name": "Nombre del agente",
  "address": "Calle...",
  "city": "Barcelona",
  "province": "Barcelona",
  "postal_code": "08001",
  "phone": "+34...",
  "category": "GOLD",
  "driveUrl": "",
  "trelloUrl": "",
  "sheet_name": "AGENTES RIA 2026"
}
```

---

## Referencias de calibración (BUILTIN_REFS)

| Label | Tamaño real |
|-------|-------------|
| Vinilo circular RIA | 50 cm diámetro |
| Vinilo rectangular RIA | null (manual) |
| Puerta estándar — ancho | 90 cm |
| Puerta estándar — alto | 210 cm |
| Baldosa estándar | 20 cm |
| Personalizado | null (manual) |

---

## Tipos de productos (TIPO_META)

`vinilo_fachada`, `vinilo_escaparate`, `lona_exterior`, `led_caja`, `otro`

---

## Reglas importantes

- **NO modificar columnas existentes del Spreadsheet** — solo añadir al final
- **NO dejar claves API visibles en el HTML**
- **NO generar facturas automáticamente**
- **NO enviar presupuestos sin revisión humana**
- El Spreadsheet es la fuente de verdad principal
- Toda acción irreversible requiere confirmación humana

---

## Estado actual (junio 2026)

- ✅ 28 agentes con Drive + Trello + Google Contact creados
- ✅ Columnas URL Carpeta Drive y URL Trello rellenas en el Spreadsheet
- ✅ W1, W2, W3 activos en n8n
- ✅ Estimador carga agentes correctamente por GID
- ⏳ Pendiente: probar W2 y W3 end-to-end desde el estimador
- ⏳ Pendiente: borrar fila de prueba ES99999 del Spreadsheet
