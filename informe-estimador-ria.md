# Informe Técnico: Estimador de Fachadas RIA · Huexs
**URL de producción:** `https://huexs.com/ria`
**Versión:** 3.0 · Junio 2026
**Equipo destinatario:** Marketing / Automatización n8n

---

## 1. ¿Qué es esta herramienta?

El **Estimador de Fachadas RIA** es una herramienta interna de Huexs para que el equipo de campo pueda tomar medidas preliminares de fachadas de agentes RIA Money Transfer directamente desde el móvil o tablet, sin necesidad de app instalada ni conexión especial.

El técnico sube una foto de la fachada, marca una referencia conocida (ej. el vinilo circular RIA de 50 cm), y después mide los elementos del rótulo con precisión. La herramienta genera una imagen anotada con las medidas y envía todo a n8n, que orquesta el guardado en Google Sheets, Drive y Trello.

**Usuarios:** Técnicos de Huexs en campo (móvil), gestores internos (desktop).

---

## 2. Arquitectura general

```
[Técnico en campo]
        │
        ▼
[huexs.com/ria]  ← HTML single-file, sin backend propio
        │
        ├── Lee agentes ──► Google Sheets (JSONP público, sin API key)
        │                   3 hojas: "Agentes RIA", "AGENTES RIA 2025", "AGENTES RIA 2026"
        │
        ├── POST medición ──► n8n webhook /ria/measurements
        │                     n8n escribe en Sheets + sube a Drive + actualiza Trello
        │
        └── POST crear recursos ──► n8n webhook /ria/create-agent
                                    n8n crea carpeta Drive + tarjeta Trello + escribe URLs en Sheets
```

**Principios de diseño que NO se deben violar:**
- La herramienta NO escribe directamente en Sheets (sin API key en el HTML)
- La herramienta NO genera facturas automáticamente
- La herramienta NO envía presupuestos sin revisión humana
- Google Sheets es la fuente de verdad
- Toda acción irreversible requiere confirmación humana
- Trello es el panel visual de seguimiento

---

## 3. Datos que la herramienta lee del Spreadsheet

**ID del Spreadsheet:** `1eyn80V0MSv2cPYYNC2OXkYtjR6tE1rNWe9Buv_gWBss`

**Hojas leídas en paralelo:**
| Hoja | Propósito |
|------|-----------|
| `Agentes RIA` | Agentes activos principales |
| `AGENTES RIA 2025` | Agentes histórico 2025 |
| `AGENTES RIA 2026` | Agentes incorporados 2026 |

**Columnas que la herramienta espera encontrar** (insensible a mayúsculas/tildes):

| Campo lógico | Columnas buscadas en el Spreadsheet |
|---|---|
| ID de agente | `Número de agente`, `Numero de agente` |
| Nombre contacto | `Nombre de Contacto`, `Nombre`, `Contacto` |
| Dirección | `Dirección`, `Direccion` |
| Ciudad | `Ciudad` |
| Provincia | `Provincia` |
| Código postal | `Código postal`, `Codigo postal`, `CP` |
| Teléfono | `Teléfono`, `Telefono` |
| Branding | `Branding` |
| Estado | `Estado` |
| **URL carpeta Drive** | `URL Carpeta Drive` ← escrita por n8n |
| **URL tarjeta Trello** | `URL Trello` ← escrita por n8n |
| **ID tarjeta Trello** | `ID Trello Card` ← escrita por n8n |

> Las columnas marcadas con ← son las que n8n debe escribir tras crear los recursos.

**Deduplicación:** Si un agente aparece en varias hojas, gana la última hoja (AGENTES RIA 2026 > AGENTES RIA 2025 > Agentes RIA). La clave de deduplicación es `agentId + nombre`.

---

## 4. Flujo completo de uso — paso a paso

### PASO 0: Carga inicial
1. El técnico abre `https://huexs.com/ria`
2. La herramienta carga las 3 hojas del Spreadsheet en paralelo (JSONP sin auth)
3. Lista de agentes disponible en segundos

### PASO 1: Seleccionar agente
- Búsqueda por texto (nombre, ciudad, provincia, branding)
- Orden alfabético-numérico A→Z (ej. Es.1, Es.2… Es.10, no Es.1, Es.10, Es.2)
- Paginación de 8 en 8 en móvil
- Al seleccionar → se muestra tarjeta con: nombre, dirección, teléfono, estado, links a Drive y Trello
- **Si el agente no tiene carpeta Drive o tarjeta Trello** → aparece el botón `＋ Crear carpeta Drive y tarjeta Trello` (ver sección 6)

### PASO 2: Subir foto de la fachada
- Upload clásico (click) o drag & drop en desktop
- En móvil: selector de archivo o cámara directamente
- La imagen queda en memoria (no se sube a ningún servidor en este paso)
- Al cargar → la herramienta cambia automáticamente a la pestaña "Medir" en móvil

### PASO 3: Establecer referencia de escala
El técnico marca sobre la foto un elemento cuya medida real conoce. Esto calibra el sistema de píxeles/cm.

**Referencias predefinidas:**
| ID | Nombre | Medida |
|----|--------|--------|
| `vinilo_circular_ria` | Vinilo circular RIA | 50 cm |
| `banderola_circular_ria` | Banderola circular RIA | manual |
| `linea_ria_basic` | Línea RIA — basic dress | 20 cm |
| `adoquines_calle` | Adoquines calle | manual |
| `custom` | Personalizado… | manual |

**Referencias personalizadas** (guardadas en localStorage del navegador):
- Nombre libre
- Medida en cm (opcional, si se deja en blanco pide input al medir)
- Foto de referencia (base64 en localStorage)
- Se pueden crear y eliminar desde la interfaz
- Persisten entre sesiones en el mismo dispositivo

**Mecánica de calibración:**
1. Seleccionar referencia en el selector
2. Si la referencia tiene medida fija → se usa directamente
3. Si es manual → se pide la medida en cm
4. Clic en punto 1 → clic en punto 2 sobre la referencia
5. La herramienta calcula px/cm automáticamente
6. Cambia automáticamente a modo medición

### PASO 4: Medir productos
Se pueden medir N productos independientes, cada uno con su propio color.

**Tipos de producto disponibles:**
| Valor | Etiqueta |
|-------|---------|
| `rotulo_standard` | Rótulo Standard |
| `rotulo_panaflex` | Rótulo Panaflex |
| `vinilo_cristal` | Vinilo Cristal |
| `vinilo_microperforado` | Vinilo Microperforado |
| `forex` | Forex |

**Mecánica de medición:**
1. Seleccionar tipo de producto
2. Clic/toque en extremo izquierdo → clic/toque en extremo derecho → ancho capturado
3. Pulsar "Añadir como ancho" → el producto queda en estado "pendiente de alto"
4. Medir el alto del mismo elemento
5. Pulsar "Añadir alto" → producto completo registrado (ancho × alto)
6. Alternativa: "Guardar solo ancho" si no es posible medir el alto
7. Repetir para cada elemento

**En móvil/tablet — sistema de lupa táctil:**
- Al tocar la imagen → aparece una lupa circular de 180px en la parte superior del canvas (nunca cubierta por el dedo)
- Al arrastrar → la lupa actualiza en tiempo real, muestra la distancia medida en vivo
- Al soltar → el punto se fija
- La lupa muestra crosshairs y anillo de color naranja (referencia) o verde (medición)

**Precisión:**
- El resultado incluye un margen de error del ±12% (estimación preliminar de campo)
- Las medidas se muestran en cm y metros según escala

### PASO 5: Guardar
**Requisitos para poder guardar:**
- Agente seleccionado
- Al menos 1 producto medido

**Lo que ocurre al pulsar "Guardar en Sheets + Trello":**
1. Se genera una imagen JPEG anotada (resolución original de la foto, con líneas de medida, etiquetas y marca de agua "Huexs · Estimador RIA")
2. Se construye el payload JSON y se envía a `n8n /ria/measurements`
3. n8n procesa y responde → la herramienta muestra el estado (guardado / error)

---

## 5. Payload que la herramienta envía a n8n al guardar

**Endpoint:** `POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/measurements`

**Content-Type:** `application/json`

```json
{
  "agentId":      "Es.123",
  "agentName":    "Juan García",
  "agentCity":    "Barcelona",
  "trelloCardId": "abc123xyz",
  "driveFolderId": "https://drive.google.com/...",
  "referenceCm":  50,
  "scalePxPerCm": 12.4500,
  "margenErrorPct": 12,
  "productos": [
    {
      "tipo":    "rotulo_standard",
      "label":   "Rótulo Standard",
      "anchoCm": 180.5,
      "altoCm":  45.2
    },
    {
      "tipo":    "vinilo_cristal",
      "label":   "Vinilo Cristal",
      "anchoCm": 92.0,
      "altoCm":  null
    }
  ],
  "imagenAnotadaBase64": "data:image/jpeg;base64,/9j/4AAQ...",
  "timestamp": "2026-06-28T10:30:00.000Z"
}
```

**Notas para n8n:**
- `imagenAnotadaBase64`: imagen JPEG completa. n8n debe extraer el base64 (sin el prefijo `data:image/jpeg;base64,`) y subirla a Google Drive
- `altoCm: null` indica que solo se midió el ancho (producto sin alto)
- `trelloCardId` puede ser `null` si el agente no tenía tarjeta creada
- `driveFolderId` es la URL completa de la carpeta, no el ID — n8n debe extraer el ID si necesita operar con la API de Drive

---

## 6. Payload para crear recursos (carpeta Drive + tarjeta Trello)

**Endpoint:** `POST https://n8n-n8n.te2fhz.easypanel.host/webhook/ria/create-agent`

**Se dispara cuando:** el técnico pulsa "＋ Crear carpeta Drive y tarjeta Trello" en la tarjeta del agente.

**Payload enviado:**
```json
{
  "agentId":   "Es.456",
  "agentName": "María López",
  "address":   "Calle Mayor 12, Barcelona, Cataluña, 08001, España",
  "phone":     "+34 600 123 456",
  "city":      "Barcelona",
  "province":  "Cataluña",
  "sheet":     "AGENTES RIA 2026"
}
```

**Respuesta esperada de n8n** (la herramienta actualiza la tarjeta en memoria inmediatamente):
```json
{
  "driveUrl":  "https://drive.google.com/drive/folders/FOLDER_ID",
  "trelloUrl": "https://trello.com/c/CARD_ID/nombre-agente",
  "trelloId":  "CARD_ID"
}
```

**Lo que n8n debe hacer en este flujo:**
1. Crear carpeta en Google Drive → dentro de la carpeta madre "Agentes RIA"
   - Nombre: `[agentId] - [agentName] - [city]`
   - Compartir con los permisos necesarios
2. Crear tarjeta en Trello
   - Tablero: el de seguimiento RIA
   - Lista: "Pendiente" o "Nuevo"
   - Descripción: dirección, teléfono, hoja de origen
3. Escribir en Google Sheets las URLs generadas:
   - Columna `URL Carpeta Drive` → URL de la carpeta
   - Columna `URL Trello` → URL de la tarjeta
   - Columna `ID Trello Card` → ID de la tarjeta Trello
   - Identificar la fila por `agentId` en la hoja correcta (`sheet` del payload)
4. Devolver JSON con `{ driveUrl, trelloUrl, trelloId }`

---

## 7. Lo que n8n debe hacer al recibir una medición (/ria/measurements)

Flujo recomendado en n8n:

```
[Webhook trigger]
        │
        ▼
[Extraer base64 de imagenAnotadaBase64]
        │
        ▼
[Subir imagen a Google Drive]
  → En la carpeta del agente (driveFolderId)
  → Nombre del archivo: [agentId]_[timestamp]_medicion.jpg
  → Guardar URL pública / URL de descarga
        │
        ▼
[Añadir fila en Google Sheets]
  → Hoja: puede ser una hoja "Mediciones" separada o añadir columnas al final del agente
  → Datos: agentId, timestamp, productos (JSON), anchoCm, altoCm, referenceCm, scalePxPerCm, urlImagenDrive
  → NUNCA modificar columnas existentes, solo añadir al final
        │
        ▼
[Actualizar tarjeta Trello]
  → Si trelloCardId existe: añadir comentario o adjunto con la imagen
  → Mover a lista "Medición recibida" o similar
  → Adjuntar imagen anotada
        │
        ▼
[Responder 200 OK]
  → La herramienta muestra "Guardado · Sheets + Trello actualizados"
```

---

## 8. Campos disponibles en Sheets para el equipo de operaciones

Propuesta de columnas para una hoja "Mediciones":

| Columna | Descripción | Fuente |
|---------|-------------|--------|
| `Timestamp` | Fecha y hora ISO | Payload |
| `ID Agente` | Código agente (Es.123) | Payload |
| `Nombre Agente` | Nombre de contacto | Payload |
| `Ciudad` | Ciudad del agente | Payload |
| `Tipo Producto 1` | Primer producto medido | Payload |
| `Ancho 1 (cm)` | Ancho del primer producto | Payload |
| `Alto 1 (cm)` | Alto (null si no medido) | Payload |
| `Tipo Producto 2` | Segundo producto (si existe) | Payload |
| `Ancho 2 (cm)` | … | Payload |
| `Alto 2 (cm)` | … | Payload |
| `Referencia usada` | cm de la referencia de escala | Payload |
| `Error estimado` | 12% por defecto | Payload |
| `URL Imagen Drive` | Enlace a la foto anotada | n8n tras subir |
| `URL Trello` | Enlace a la tarjeta | De datos del agente |
| `Estado` | Pendiente / En proceso / Completado | Manual / Trello |

---

## 9. Flujos futuros a modelar (roadmap)

### Fase 2 (siguiente sprint)
| Flujo | Trigger | n8n hace |
|-------|---------|----------|
| **Sync Trello → Sheets** | Webhook de Trello al mover tarjeta | Actualiza columna "Estado" en Sheets |
| **Nuevo agente detectado** | Apps Script trigger en Sheets (nueva fila sin Drive URL) | Llama a `/ria/create-agent` automáticamente |
| **Notificación al equipo** | Al recibir medición | Envía email / Slack al gestor con imagen anotada y medidas |

### Fase 3 (presupuesto)
| Flujo | Trigger | n8n hace |
|-------|---------|----------|
| **Borrador de presupuesto** | Manual desde Trello o desde la herramienta | Genera PDF con medidas + precios unitarios de catálogo |
| **Envío para revisión** | Manual (requiere aprobación humana) | Envía borrador al gestor para validar antes de enviar al cliente |
| **Presupuesto aprobado** | Manual (gestor aprueba) | Genera versión final y envía al agente |

---

## 10. Endpoints n8n — resumen completo

| Endpoint | Método | Trigger | Payload clave |
|----------|--------|---------|---------------|
| `/webhook/ria/measurements` | POST | Botón "Guardar" en la herramienta | `agentId, productos[], imagenAnotadaBase64` |
| `/webhook/ria/create-agent` | POST | Botón "＋ Crear..." en tarjeta del agente | `agentId, agentName, address, sheet` |
| `/webhook/ria/sync-trello` *(futuro)* | POST | Webhook de Trello | `cardId, listName, action` |

**URL base n8n:** `https://n8n-n8n.te2fhz.easypanel.host`

---

## 11. Datos persistidos localmente (localStorage)

La herramienta guarda en el navegador del usuario (sin backend):

| Clave | Contenido | Cuándo se usa |
|-------|-----------|---------------|
| `rm_custom_refs_v1` | Array JSON de referencias personalizadas con foto base64 | Se restaura al abrir la herramienta en el mismo dispositivo |

Esto permite que el técnico cree sus propias referencias de escala con foto (ej. "tarjeta bancaria estándar" = 8,56 cm) y las tenga disponibles sin conexión.

---

## 12. Imagen anotada — qué contiene

La imagen generada (JPEG, calidad 82%) incluye:
- La foto original en resolución nativa
- Líneas de medida con flechas bidireccionales en ambos extremos
- Puntos de anclaje blancos con borde de color
- Etiqueta flotante sobre cada línea con la medida (en cm y metros si > 100 cm)
- Color diferente por producto para identificarlos visualmente
- Línea de referencia en color ámbar (#f59e0b)
- Marca de agua: "Huexs · Estimador RIA" en esquina inferior derecha

**Esta imagen es el documento de trabajo** que se sube a Drive y se adjunta a la tarjeta Trello.

---

## 13. Consideraciones técnicas para n8n

### Imagen base64
```
"imagenAnotadaBase64": "data:image/jpeg;base64,/9j/4AAQ..."
```
Para subir a Drive con la API:
1. Extraer la parte tras la coma: `{{ $json.imagenAnotadaBase64.split(",")[1] }}`
2. Decodificar con `Buffer.from(base64, 'base64')`
3. Subir como multipart/form-data con mimeType `image/jpeg`

### Nombre de archivo recomendado
```
{{ $json.agentId }}_{{ $json.timestamp.replace(/:/g,"-").substring(0,19) }}_medicion.jpg
```

### Identificar fila en Sheets para actualizar
Usar el `agentId` como clave de búsqueda en la hoja correcta (`$json.sheet` indica de qué hoja viene el agente).

### CORS
El webhook de n8n debe tener los headers CORS correctos para aceptar peticiones desde `https://huexs.com`:
```
Access-Control-Allow-Origin: https://huexs.com
Access-Control-Allow-Methods: POST, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

---

## 14. Glosario

| Término | Significado |
|---------|-------------|
| Agente | Establecimiento comercial que ofrece el servicio RIA Money Transfer |
| Referencia de escala | Elemento conocido en la foto usado para calibrar el sistema px/cm |
| Producto medido | Elemento del rótulo a fabricar (rótulo, vinilo, forex…) |
| Imagen anotada | Foto original con las líneas de medida superpuestas |
| n8n | Plataforma de automatización que orquesta los flujos de datos |
| JSONP | Técnica para leer Sheets sin API key (lectura pública solo) |

---

*Documento generado el 28 de junio de 2026 · Huexs · Para uso interno del equipo de automatización.*
