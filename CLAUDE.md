# Contexto del proyecto — Huexs / RIA Money Transfer

## Qué es esto

Herramienta interna para técnicos de Huexs que trabajan instalando señalética para agentes de RIA Money Transfer. Permite buscar agentes, fotografiar y medir sus fachadas con calibración px/cm, registrar mediciones en modo manual, subir fotos a Drive, y guardar todo automáticamente en Google Sheets + Trello + Google Drive.

---

## Archivos principales

| Archivo | Descripción |
|---------|-------------|
| `estimador-ria.html` | Herramienta completa (single-file vanilla JS). Servida en huexs.com/ria |
| `n8n-workflows/W1-nuevo-agente.json` | Nueva fila Sheets → Drive + Trello + Contacts + escribe URLs |
| `n8n-workflows/W2-guardar-mediciones.json` | POST mediciones → Sheets + Trello + Drive (imagen anotada) |
| `n8n-workflows/W3-crear-recursos-manual.json` | POST → crea Drive/Trello/Contact on-demand (idempotente) |
| `n8n-workflows/W4-subir-fotos.json` | POST foto base64 → Drive/01 Fotografías del agente |
| `n8n-workflows/W5-trello-status.json` | POST cardId → devuelve nombre de lista Trello actual |
| `n8n-workflows/W7-facturas-proveedores.json` | Cada 15 min: Gmail etiqueta facturas → PDF a Drive + etiquetado idempotente |
| `n8n-workflows/SETUP.md` | Guía de configuración completa de n8n |

---

## IDs y URLs críticos

### Google Sheets
- **Spreadsheet ID:** `1eyn80V0MSv2cPYYNC2OXkYtjR6tE1rNWe9Buv_gWBss`
- **Sheet GID:** `1287637480`
- **Sheet name:** `AGENTES RIA 2026`
- **Columnas originales (A–H):** Número de agente, Nombre de Contacto, Dirección, Ciudad, Provincia, Código postal, Teléfono, Branding
- **Columnas añadidas (O–AE):** URL Carpeta Drive (O), URL Trello (P), Mediciones (Q), Mediciones JSON (R), Escala px/cm (S), Referencia usada (T), Fecha medición (U), Última foto Drive (V), Rotulo Standard (W), Rotulo Panaflex (X), Vinilo Cristal (Y), Vinilo Microperforado (Z), Forex (AA), Mostrador (AB), Toldo (AC), Placa Metacrilato (AD), Banderola Montaje (AE)
- **Hoja PRECIOS RIA:** columnas Tipo | Producto | IVA % | Coste Imprenta €/m² | Precio Huexs €/m² | Precio Huexs con IVA €/m² | Margen €/m² | Margen % | Notas

### n8n
- **Base URL:** `https://n8n-n8n.te2fhz.easypanel.host`
- **W1 ID:** `xtqsHGzK6z15YfN5` — trigger: Google Sheets polling 1 min
- **W2 ID:** `hFWwy6YHp8iq3RIg` — webhook: `POST /webhook/ria/measurements`
- **W3 ID:** `eVB52gyJcYQ1SqZj` — webhook: `POST /webhook/ria/create-agent`
- **W4 ID:** *(pendiente activar)* — webhook: `POST /webhook/ria/upload-photos`
- **W5 ID:** `7DkB140aeE7LHdBP` — webhook: `POST /webhook/ria/trello-status`

### Trello
- **Board ID:** `lSevGfHk` — "Ria Money Transfer AGENTES"
- **Lista "Contactar" ID:** `6577274e636e7cc8419d0d4a`
- **Nombre de tarjeta:** solo el número de agente (`ES12345`) — sin nombre de contacto
- **Descripción de tarjeta:** nombre, dirección, ciudad/provincia, CP, teléfono, categoría

### Google Drive
- **Carpeta raíz agentes:** `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz`
- **Subcarpetas por agente:** 01 Fotografías, 02 Diseños, 03 Presupuesto, 04 Producción, 05 Instalación, 06 Facturación

### Credenciales n8n
- **Google Sheets/Drive OAuth2:** `B8Oo1U1KfF3e4NNv` (huexss@gmail.com)
- **Google People/Contacts:** `nqbkR2cOGYotmr9Z`
- **Trello:** ver Settings → Credentials en n8n

---

## Cómo funciona el estimador (estimador-ria.html)

### PWA — Instalable en móvil
- Meta tags `apple-mobile-web-app-*` + manifest inline (data URI)
- Instalable desde Safari/Chrome en iOS/Android via "Añadir a pantalla de inicio"

### Carga de agentes
- Google Sheets Visualization API (gviz/tq) via JSONP
- Carga por GID (`gid=1287637480`), NO por nombre de hoja
- Cache-busting con `_: Date.now()`
- También carga la hoja `PRECIOS RIA` para estimaciones de coste

### Modos de trabajo
El técnico elige entre dos modos con el toggle superior:

**📷 Modo Con foto** (por defecto)
1. Busca el agente → al seleccionarlo la lista se colapsa y aparece la card del agente abajo
2. La card muestra Drive, Trello y el nombre de la lista Trello actual (estado del agente, consultado en vivo via W5)
3. Sube foto de fachada → calibra con referencia → mide productos en canvas
4. Pulsa "Guardar" → POST a W2

**📋 Modo Manual** (sin foto)
1. Busca y selecciona el agente
2. Rellena el formulario: tipo de producto + ancho cm + alto cm
3. Ve el coste/precio estimado en vivo desde PRECIOS RIA
4. Pulsa "Guardar" → POST a W2 con flag `modoManual: true`

### Subida de fotos (Paso 04 — panel derecho)
- Botones Cámara y Galería (inputs file, múltiples)
- Compresión automática client-side a max 1600px, JPEG 82%
- Miniaturas con botón de eliminar antes de subir
- Subida secuencial a Drive via W4 (una foto por llamada)

### Precios y estimaciones (PRECIOS RIA)
- Se cargan al inicio desde la hoja `PRECIOS RIA` via gviz/tq JSONP
- Por cada producto medido se muestra: 🏭 coste imprenta, 💶 precio Huexs, ▲ margen
- El payload a W2 incluye `costeEstimadoImprenta` y `valoracionHuexsEstimada`

### Payload W2 (guardar mediciones)
```json
{
  "agent_id": "ES16756",
  "agentName": "Nombre del agente",
  "trelloCardId": "id_de_la_tarjeta",
  "scalePxPerCm": 12.5,
  "ref_label": "Vinilo circular RIA (50 cm)",
  "productos": [{"tipo": "rotulo_standard", "label": "Rotulo Standard", "anchoCm": 120.5, "altoCm": 80.2}],
  "imagen_base64": "data:image/jpeg;base64,...",
  "modoManual": false,
  "costeEstimadoImprenta": 168.0,
  "valoracionHuexsEstimada": 336.0,
  "timestamp": "2026-06-30T10:00:00Z"
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

### Payload W4 (subir foto)
```json
{
  "agent_id": "ES16756",
  "agentName": "Nombre del agente",
  "driveUrl": "https://drive.google.com/drive/folders/...",
  "foto_base64": "data:image/jpeg;base64,...",
  "filename": "foto_fachada.jpg",
  "timestamp": "2026-06-30T10:00:00Z"
}
```

### Payload W5 (estado Trello)
```json
{ "cardId": "trello_card_id" }
```
Respuesta: `{ "listName": "Contactar" }`

---

## Tipos de productos (TIPO_META)

| Clave | Label | Col Sheets |
|-------|-------|-----------|
| `rotulo_standard` | Rotulo Standard | W |
| `rotulo_panaflex` | Rotulo Panaflex | X |
| `vinilo_cristal` | Vinilo Cristal | Y |
| `vinilo_microperforado` | Vinilo Microperforado | Z |
| `forex` | Forex | AA |
| `mostrador` | Mostrador | AB |
| `toldo` | Toldo Exterior | AC |
| `placa_metacrilato` | Placa Metacrilato | AD |
| `montaje_banderola` | Banderola (Montaje) | AE |

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

## Comportamiento móvil

- Al seleccionar un agente: la lista se comprime (~80px) y la card del agente aparece fija en la parte inferior del panel, sin necesidad de scroll
- Badge con el estado actual de Trello (nombre de lista) junto al enlace Trello, consultado en vivo via W5
- Header con padding superior ampliado para evitar que el logo toque el borde de la pantalla
- Todos los iconos son SVG monocromo (sin emojis de color)

---

## Reglas importantes

- **NO modificar columnas existentes del Spreadsheet** — solo añadir al final
- **NO dejar claves API visibles en el HTML**
- **NO generar facturas automáticamente**
- **NO enviar presupuestos sin revisión humana**
- **NO crear agentes de prueba en Drive/Trello** — cada creación es irreversible
- El Spreadsheet es la fuente de verdad principal
- W1 es idempotente: no duplica si la fila ya tiene URL Drive + URL Trello
- Toda acción irreversible requiere confirmación humana

---

## Estado actual (junio 2026)

- ✅ W1 activo — crea Drive + Trello (nombre: ES12345, descripción: datos completos) + Contacts
- ✅ W2 activo — guarda mediciones, agrupa por tipo de producto, escribe columnas W–AE
- ✅ W3 activo — crea recursos on-demand desde el estimador (idempotente)
- ✅ W5 activo — devuelve nombre de lista Trello en vivo al seleccionar agente
- ✅ Estimador con modo foto + modo manual + subida de fotos (W4 pendiente activar)
- ✅ PRECIOS RIA cargada con 9 tipos de producto y costes reales
- ✅ Tipos de producto actualizados: toldo / placa_metacrilato / montaje_banderola
- ✅ Columnas Sheets AC/AD/AE renombradas: Toldo / Placa Metacrilato / Banderola Montaje
- ⏳ W4 pendiente de activar en n8n (JSON listo en repo)
- ⏳ Borrar fila de prueba ES99999 del Spreadsheet
