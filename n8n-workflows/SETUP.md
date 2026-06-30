# n8n Workflows RIA — Guía de Setup

## Estado actual (junio 2026)

| Workflow | ID | Estado | Trigger |
|----------|----|--------|---------|
| W1 — Nuevo Agente | `xtqsHGzK6z15YfN5` | ✅ Activo | Google Sheets polling 1 min |
| W2 — Guardar Mediciones | `hFWwy6YHp8iq3RIg` | ✅ Activo | POST `/webhook/ria/measurements` |
| W3 — Crear Recursos Manual | `eVB52gyJcYQ1SqZj` | ✅ Activo | POST `/webhook/ria/create-agent` |
| W4 — Subir Fotos | *(pendiente activar)* | ⏳ Pendiente | POST `/webhook/ria/upload-photos` |

**Instancia n8n:** `https://n8n-n8n.te2fhz.easypanel.host`

---

## Qué hace cada workflow

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `W1-nuevo-agente.json` | Nueva fila en Google Sheets | Crea carpeta Drive (+ 6 subcarpetas) + tarjeta Trello + Google Contact + escribe URLs en Sheets |
| `W2-guardar-mediciones.json` | POST `/ria/measurements` | Guarda medidas en Sheets, comenta en Trello con resumen, sube imagen anotada a Drive/Fotografías |
| `W3-crear-recursos-manual.json` | POST `/ria/create-agent` | Crea Drive/Trello/Contact on-demand desde el botón de la herramienta (idempotente: no duplica si ya existen) |
| `W4-subir-fotos.json` | POST `/ria/upload-photos` | Sube una foto (base64) a la subcarpeta 01 Fotografías del agente en Drive |

---

## IDs de recursos reales

### Google Sheets
- **Spreadsheet ID:** `1eyn80V0MSv2cPYYNC2OXkYtjR6tE1rNWe9Buv_gWBss`
- **Sheet name:** `AGENTES RIA 2026`
- **Sheet GID:** `1287637480`

### Columnas del Spreadsheet

**Columnas originales (NO modificar):**
| Col | Nombre exacto |
|-----|--------------|
| A | Número de agente |
| B | Nombre de Contacto |
| C | Dirección |
| D | Ciudad |
| E | Provincia |
| F | Código postal |
| G | Teléfono |
| H | Branding |

**Columnas añadidas para la automatización:**
| Col | Nombre exacto | Qué guarda |
|-----|--------------|------------|
| O | URL Carpeta Drive | URL de la carpeta Drive del agente |
| P | URL Trello | URL de la tarjeta Trello |
| Q | Mediciones | Resumen legible (ej: "Vinilo fachada: 120 x 80 cm") |
| R | Mediciones JSON | JSON completo de todos los productos medidos |
| S | Escala px/cm | Escala de calibración usada |
| T | Referencia usada | Elemento de referencia para calibrar |
| U | Fecha medición | ISO timestamp de la medición |
| V | Última foto Drive | URL de la última imagen anotada subida por W2 (base del viewer) |

**Columnas por tipo de producto (escritas por W2, una por tipo medido):**
| Col | Nombre exacto | Tipo |
|-----|--------------|------|
| W | Rotulo Standard | Ej: "120.0 × 80.0 cm (0.096 m²)" |
| X | Rotulo Panaflex | Igual |
| Y | Vinilo Cristal | Igual |
| Z | Vinilo Microperforado | Igual |
| AA | Forex | Igual |
| AB | Mostrador | Igual |
| AC | Toldo | Igual |
| AD | Placa Metacrilato | Igual |
| AE | Banderola Montaje | Igual |

> Para referencias de coste por m², crear una hoja aparte "PRECIOS RIA" con columnas: Tipo | €/m² | Notas.
> Las columnas W–AE se rellenan automáticamente con cada guardado (W2). Si el tipo no se midió, queda vacío.

> **Importante:** Los nombres de columna deben ser exactamente los indicados. n8n los usa para hacer match por nombre.

### Trello
- **Board ID:** `lSevGfHk` — "Ria Money Transfer AGENTES"
- **Lista "Contactar" ID:** `6577274e636e7cc8419d0d4a`

### Google Drive
- **Carpeta raíz agentes:** `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz`
- **Subcarpetas por agente:** 01 Fotografías / 02 Diseños / 03 Presupuesto / 04 Producción / 05 Instalación / 06 Facturación

---

## Credenciales configuradas en n8n

| Credencial | Tipo n8n | ID | Cuenta |
|------------|----------|----|--------|
| Google Sheets Trigger — Huexs | `googleSheetsTriggerOAuth2Api` | `B8Oo1U1KfF3e4NNv` | huexss@gmail.com |
| Google Sheets — Huexs | `googleSheetsOAuth2Api` | `B8Oo1U1KfF3e4NNv` | huexss@gmail.com |
| Google Drive — Huexs | `googleDriveOAuth2Api` | `B8Oo1U1KfF3e4NNv` | huexss@gmail.com |
| Google Contacts — Huexs | `googleContactsOAuth2Api` | `nqbkR2cOGYotmr9Z` | huexss@gmail.com |
| Trello — Huexs | `trelloApi` | ver Settings → Credentials | — |

---

## Reinstalar desde cero

### Paso 1 — Credenciales
Crear en **Settings → Credentials**:

**Google OAuth2 (Sheets + Drive):**
- Tipo: `Google OAuth2`
- Scopes: `spreadsheets`, `drive`
- Nombre: `Google Sheets — Huexs`

**Google OAuth2 (Contacts):**
- Tipo: `Google OAuth2`
- Scope: `contacts`
- Nombre: `Google Contacts — Huexs`

**Trello:**
- Tipo: `Trello API`
- API Key + Token desde: https://trello.com/app-key

### Paso 2 — Importar workflows
1. **Workflows → Add Workflow → Import from file**
2. Importar en orden: W1 → W2 → W3
3. En cada workflow, sustituir todos los `REPLACE_*`:

| Placeholder | Valor real |
|-------------|-----------|
| `REPLACE_GOOGLE_CREDENTIAL_ID` | ID de la credencial Google Sheets/Drive |
| `REPLACE_TRELLO_CREDENTIAL_ID` | ID de la credencial Trello |
| `REPLACE_GOOGLE_PEOPLE_CREDENTIAL_ID` | ID de la credencial Google Contacts |
| `REPLACE_PARENT_FOLDER_ID` | `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz` |
| `REPLACE_INSTANCE_ID` | ID de tu instancia n8n |

### Paso 3 — Activar
1. Activar W1 (toggle ON) — el trigger de Sheets empieza el polling
2. Activar W2 — el webhook `/ria/measurements` queda vivo
3. Activar W3 — el webhook `/ria/create-agent` queda vivo

---

## Webhooks (W2 y W3)

Los webhooks están configurados en `estimador-ria.html`:
```
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/measurements    → W2
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/create-agent    → W3
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/upload-photos   → W4
```

---

## Flujo completo

```
Gmail (Michelle) recibe solicitud de nuevo agente
    ↓
Michelle copia datos al Spreadsheet (nueva fila)
    ↓
W1 detecta nueva fila (polling 1 min)
    ↓
¿Ya tiene URL Carpeta Drive y URL Trello? → Si sí, termina (no duplica)
    ↓
Crear carpeta Drive con 6 subcarpetas
Crear tarjeta Trello en lista "Contactar"
Crear contacto en Google Contacts
Escribir URL Carpeta Drive + URL Trello en la fila del Spreadsheet
    ↓
─── MÁS TARDE — Técnico visita el agente ───
    ↓
Técnico abre estimador-ria.html (huexs.com/ria)
Busca el agente → toma foto → calibra → mide productos
Pulsa "Guardar"
    ↓
W2: actualiza Sheets (Mediciones, Fecha...) + comenta Trello + sube imagen a Drive/Fotografías
    ↓
─── SI el agente no tiene Drive/Trello ───
Técnico pulsa botón naranja "Crear carpeta Drive y tarjeta Trello"
    ↓
W3: crea lo que falte y devuelve URLs al estimador
```

---

## Labels Trello

| Color | Nombre | Cuándo |
|-------|--------|--------|
| `orange_dark` | Tenemos las medides | W2 lo añade al guardar medición |
| `sky_dark` | Esperando Fotos | Manual |
| `yellow_dark` | FOTOS RECIBIDAS | Manual |
| `orange` | PO - Pendiente | Manual |
| `purple` | FALTA PO | Manual |
| `red` | Pendiente aprobación RIA | Manual |
| `blue` | diseño aprobado RIA | Manual |
| `green` | INSTALADO | Manual |

---

## Fases futuras (no implementadas)

- **F2:** Subida de fotos libres desde la herramienta (W2 ya sube la imagen anotada; falta UI de subida libre)
- **F3:** Generación automática de presupuesto (requiere plantilla Google Docs/Slides)
- **F4:** Sincronización bidireccional Trello ↔ Sheets
- **F5:** Mockup automático con foto del local
