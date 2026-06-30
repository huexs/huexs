# n8n Workflows RIA — Guía de Setup

## Estado actual (junio 2026)

| Workflow | ID | Estado | Trigger |
|----------|----|--------|---------|
| W1 — Nuevo Agente | `xtqsHGzK6z15YfN5` | ✅ Activo | Google Sheets polling 1 min |
| W2 — Guardar Mediciones | `hFWwy6YHp8iq3RIg` | ✅ Activo | POST `/webhook/ria/measurements` |
| W3 — Crear Recursos Manual | `eVB52gyJcYQ1SqZj` | ✅ Activo | POST `/webhook/ria/create-agent` |
| W4 — Subir Fotos | *(pendiente activar)* | ⏳ Pendiente | POST `/webhook/ria/upload-photos` |
| W5 — Trello Status | `7DkB140aeE7LHdBP` | ✅ Activo | POST `/webhook/ria/trello-status` |

**Instancia n8n:** `https://n8n-n8n.te2fhz.easypanel.host`

---

## Qué hace cada workflow

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `W1-nuevo-agente.json` | Nueva fila en Google Sheets | Crea carpeta Drive (+ 6 subcarpetas) + tarjeta Trello + Google Contact + escribe URLs en Sheets |
| `W2-guardar-mediciones.json` | POST `/ria/measurements` | Guarda medidas en Sheets (columnas Q–AE), comenta en Trello, sube imagen anotada a Drive/Fotografías |
| `W3-crear-recursos-manual.json` | POST `/ria/create-agent` | Crea Drive/Trello/Contact on-demand desde el botón del estimador (idempotente: no duplica) |
| `W4-subir-fotos.json` | POST `/ria/upload-photos` | Sube una foto (base64) a la subcarpeta 01 Fotografías del agente en Drive |
| `W5-trello-status.json` | POST `/ria/trello-status` | Recibe cardId, consulta Trello, devuelve nombre de la lista actual |

---

## IDs de recursos reales

### Google Sheets
- **Spreadsheet ID:** `1eyn80V0MSv2cPYYNC2OXkYtjR6tE1rNWe9Buv_gWBss`
- **Sheet name:** `AGENTES RIA 2026`
- **Sheet GID:** `1287637480`

### Columnas del Spreadsheet — AGENTES RIA 2026

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
| Q | Mediciones | Resumen legible (ej: "Rotulo Standard: 120 x 80 cm") |
| R | Mediciones JSON | JSON completo de todos los productos medidos |
| S | Escala px/cm | Escala de calibración usada |
| T | Referencia usada | Elemento de referencia para calibrar |
| U | Fecha medición | ISO timestamp de la medición |
| V | Última foto Drive | URL de la última imagen anotada subida por W2 |

**Columnas por tipo de producto (escritas por W2):**
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

**Columnas de estimación económica (escritas por W2):**
| Col | Nombre exacto | Qué guarda |
|-----|--------------|------------|
| AF | Coste Imprenta (sin IVA) | Suma de costes de imprenta estimados |
| AG | Valoración Huexs (sin IVA) | Suma de precios Huexs estimados |

### Hoja PRECIOS RIA
Hoja separada en el mismo Spreadsheet. Columnas:

| Col | Nombre |
|-----|--------|
| A | Tipo (clave interna: `rotulo_standard`, `toldo`, etc.) |
| B | Producto (nombre visible) |
| C | IVA % |
| D | Coste Imprenta €/m² |
| E | Precio Huexs €/m² |
| F | Precio Huexs con IVA €/m² |
| G | Margen €/m² |
| H | Margen % |
| I | Notas |

Tipos activos: `rotulo_standard`, `rotulo_panaflex`, `vinilo_cristal`, `vinilo_microperforado`, `forex`, `mostrador`, `toldo`, `placa_metacrilato`, `montaje_banderola`

### Trello
- **Board ID:** `lSevGfHk` — "Ria Money Transfer AGENTES"
- **Lista "Contactar" ID:** `6577274e636e7cc8419d0d4a`
- **Nombre de tarjeta:** número de agente únicamente (`ES12345`)
- **Descripción de tarjeta:** nombre contacto, dirección, ciudad/provincia, CP, teléfono, categoría

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

## Webhooks

```
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/measurements    → W2
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/create-agent    → W3
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/upload-photos   → W4
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/trello-status   → W5
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
  → Nombre: ES12345
  → Descripción: nombre, dirección, ciudad, CP, teléfono, categoría
Crear contacto en Google Contacts
Escribir URL Carpeta Drive + URL Trello en la fila del Spreadsheet
    ↓
─── MÁS TARDE — Técnico visita el agente ───
    ↓
Técnico abre estimador-ria.html (huexs.com/ria)
Busca el agente → ve estado Trello en vivo (via W5)
    ↓
OPCIÓN A — Con foto:
  Toma foto → calibra → mide productos en canvas → Guardar
OPCIÓN B — Manual:
  Rellena formulario tipo/ancho/alto → ve costes estimados → Guardar
    ↓
W2: actualiza Sheets (Q–AE + costes) + comenta Trello + sube imagen a Drive/Fotografías
    ↓
OPCIÓN C — Subir fotos libres:
  Técnico usa sección "Fotos del local" → selecciona fotos → Subir fotos a Drive
    ↓
W4: sube cada foto a Drive/01 Fotografías del agente
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
2. Importar en orden: W1 → W2 → W3 → W4 → W5
3. En cada workflow, sustituir todos los `REPLACE_*`:

| Placeholder | Valor real |
|-------------|-----------|
| `REPLACE_GOOGLE_CREDENTIAL_ID` | ID de la credencial Google Sheets/Drive |
| `REPLACE_TRELLO_CREDENTIAL_ID` | ID de la credencial Trello |
| `REPLACE_GOOGLE_PEOPLE_CREDENTIAL_ID` | ID de la credencial Google Contacts |
| `REPLACE_PARENT_FOLDER_ID` | `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz` |
| `REPLACE_INSTANCE_ID` | ID de tu instancia n8n |

### Paso 3 — Activar
1. Activar W1 — el trigger de Sheets empieza el polling
2. Activar W2 — webhook `/ria/measurements` queda vivo
3. Activar W3 — webhook `/ria/create-agent` queda vivo
4. Activar W4 — webhook `/ria/upload-photos` queda vivo
5. Activar W5 — webhook `/ria/trello-status` queda vivo

---

## Pendiente

- Activar W4 en n8n (JSON listo en repo, falta importar y activar)
- Borrar fila de prueba ES99999 del Spreadsheet
