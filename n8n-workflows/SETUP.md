# n8n Workflows RIA — Guía de Setup

## Workflows incluidos

| Archivo | Trigger | Qué hace |
|---------|---------|----------|
| `W1-nuevo-agente.json` | Nueva fila en Google Sheets | Drive + Trello + Google Contacts + escribe URLs en Sheets |
| `W2-guardar-mediciones.json` | POST `/ria/measurements` | Guarda medidas en Sheets, comenta en Trello, sube imagen a Drive |
| `W3-crear-recursos-manual.json` | POST `/ria/create-agent` | Crea Drive/Trello/Contacts on-demand desde el botón de la herramienta |

---

## Paso 1 — Columnas nuevas en Google Sheets

Añadir al final de cada hoja (`AGENTES RIA 2026`, `AGENTES RIA 2025`), **sin tocar las columnas existentes**:

| Columna | Nombre | Descripción |
|---------|--------|-------------|
| I (o la siguiente libre) | `Drive URL` | URL de la carpeta de Drive del agente |
| J | `Trello URL` | URL de la tarjeta de Trello |
| K | `Mediciones` | Resumen legible de las medidas |
| L | `Mediciones JSON` | JSON completo de todos los productos medidos |
| M | `Escala px/cm` | Escala de la medición |
| N | `Referencia usada` | Elemento de referencia usado para calibrar |
| O | `Fecha medición` | ISO timestamp de la medición |

> **Importante:** Los nombres de columna deben ser exactamente los indicados arriba, n8n los usa para hacer match.

---

## Paso 2 — Credenciales en n8n

Crear estas credenciales en **Settings → Credentials** de n8n:

### Google Sheets + Drive (misma credencial OAuth2)
- Tipo: `Google OAuth2`
- Scopes necesarios:
  - `https://www.googleapis.com/auth/spreadsheets`
  - `https://www.googleapis.com/auth/drive`
- Nombre sugerido: `Google — Huexs`

### Google People API (para Contacts)
- Tipo: `Google OAuth2`  
- Scopes necesarios:
  - `https://www.googleapis.com/auth/contacts`
- Nota: puede ser la misma credencial si se añade el scope

### Trello
- Tipo: `Trello API`
- API Key + Token desde: https://trello.com/app-key
- Board: `Ria Money Transfer AGENTES` (`lSevGfHk`)

---

## Paso 3 — Importar workflows

1. En n8n: **Workflows → Add Workflow → Import from file**
2. Importar en este orden: W1 → W2 → W3
3. En cada workflow, buscar todos los nodos con `REPLACE_*` y sustituir:

| Placeholder | Valor real |
|-------------|-----------|
| `REPLACE_GOOGLE_CREDENTIAL_ID` | ID de la credencial Google creada en Paso 2 |
| `REPLACE_TRELLO_CREDENTIAL_ID` | ID de la credencial Trello |
| `REPLACE_GOOGLE_PEOPLE_CREDENTIAL_ID` | ID credencial Google con scope contacts |
| `REPLACE_PARENT_FOLDER_ID` | ID de la carpeta Drive donde se crearán los agentes |
| `REPLACE_INSTANCE_ID` | ID de tu instancia n8n |

### ¿Cuál es el PARENT_FOLDER_ID?
La carpeta raíz donde deben crearse todas las subcarpetas de agentes.
Puedes usar la carpeta template como referencia: `1JvhNaT1YTKCra-Nu9grJjeiN9RczAJGz`
o crear una carpeta nueva llamada `Agentes RIA` y copiar su ID desde la URL de Drive.

---

## Paso 4 — Nombres de columnas del Spreadsheet

El workflow W1 mapea estas columnas (ajustar si difieren en tu Spreadsheet):

```
A → ID Agente     (ej: ES16756)
B → Nombre
C → Dirección
D → Ciudad
E → Provincia
F → CP
G → Teléfono
H → Categoría
```

Si los nombres de cabecera son diferentes, editar el nodo **"Normalizar datos agente"** en W1 y ajustar los fallbacks `$json['NombreColumna']`.

---

## Paso 5 — Activar W1 (trigger automático)

- Abrir W1 en n8n
- El trigger `Google Sheets — Nueva fila` hace polling cada **1 minuto**
- En la primera ejecución, n8n registra el estado actual; solo filas **nuevas** dispararán el flujo
- Activar el workflow con el toggle

---

## Paso 6 — Webhooks (W2 y W3)

Los webhooks ya están configurados en la herramienta `estimador-ria.html`:
```
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/measurements   → W2
POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/create-agent   → W3
```

Solo hay que **activar** W2 y W3 en n8n para que los webhooks queden vivos.

---

## Flujo completo

```
Gmail (Michelle)
    ↓
Copiar datos al Spreadsheet
    ↓
W1 detecta nueva fila (polling 1 min)
    ↓
┌─────────────────────────────────┐
│ Crear carpeta Drive             │
│   01 Fotografías                │
│   02 Diseños                    │
│   03 Presupuesto                │
│   04 Producción                 │
│   05 Instalación                │
│   06 Facturación                │
└─────────────────────────────────┘
    ↓
Crear tarjeta Trello (lista: Contactar)
con datos completos del agente
    ↓
Crear contacto en Google Contacts
    ↓
Escribir Drive URL + Trello URL en Sheets
    ↓
─────────────── MÁS TARDE ───────────────
    ↓
Técnico abre estimador-ria.html
Busca el agente, toma foto, mide
Pulsa "Guardar"
    ↓
W2 recibe mediciones
Actualiza Sheets (columnas M-O)
Comenta en Trello + añade label "Tenemos las medides"
Sube imagen anotada a Drive/Fotografías
    ↓
─────────────── MANUAL ──────────────────
Si el agente ya existe en Sheets pero no
tiene Drive/Trello, el técnico pulsa
"＋ Crear carpeta Drive y tarjeta Trello"
    ↓
W3 crea solo lo que falta y devuelve URLs
```

---

## Labels Trello disponibles

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

- **F2:** Subida de fotos desde la herramienta → W2 ya sube la imagen anotada, falta UI de subida libre
- **F3:** Generación automática de presupuesto → requiere plantilla Google Docs/Slides
- **F4:** Sincronización bidireccional Trello ↔ Sheets (webhook Trello → n8n → actualizar columna estado)
- **F5:** Mockup automático con foto del local
