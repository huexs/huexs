# Instalación y puesta en marcha

Guía operativa de la instalación concreta. El diseño y los contratos están en
`README.md`, `HANDOFF.md`, `AGENTS.md` y `docs/PRODUCTION_SETUP.md`; aquí solo
está lo que hay que ejecutar.

---

## 1. Entorno

```bash
cd email-automations
python -m venv .venv
.venv/bin/pip install -r requirements.txt          # dominio + tests
.venv/bin/pip install -r requirements-google.txt   # adaptadores de Google
.venv/bin/pip install -r requirements-claude.txt   # solo si usas resumen con Claude
.venv/bin/pytest -q
```

Los tests no necesitan credenciales ni red: los servicios de Google y el
cliente de Claude se sustituyen por dobles.

## 2. Configuración local

```bash
cp config.example.json config.local.json
```

`config.local.json` está ignorado por Git y **solo** contiene configuración de
negocio (etiquetas, destinatarios, reglas, límites). Ningún secreto vive aquí.

| Sección | Qué define |
|---|---|
| `database_path` | Fichero SQLite compartido (idempotencia y reintentos) |
| `google.auth_mode` | `oauth_user` (Gmail personal) o `service_account` (Workspace con delegación) |
| `google.client_secrets_path` / `token_path` | Rutas locales fuera del repositorio |
| `google.delegated_user` | Obligatorio con `service_account` |
| `storage.type` | `drive` (necesita `drive_folder_id`) o `local` (`local_root`) |
| `summarizer.type` | `keyword` (local, sin coste) o `claude` (API de Anthropic) |
| `gmail_invoices.providers` | Una regla por proveedor: `slug`, `sender_contains`, `subject_contains` |
| `gmail_school.targets` | Criterios genéricos: curso, grupo, tipo de aviso |
| `youtube_to_mail.lookback_hours` | Ventana corta, entre 24 y 72 horas |

## 3. Credenciales

Fuera del repositorio, con permisos restrictivos:

```bash
mkdir -p credentials && chmod 700 credentials
# copia aquí el client_secret.json descargado de Google Cloud
chmod 600 credentials/client_secret.json
```

**Gmail personal (`oauth_user`):**

```bash
.venv/bin/python -m tools.authorize --config config.local.json
```

Abre el consentimiento en el navegador y guarda el token en
`google.token_path` con permisos `600`. Ámbitos mínimos que se piden:

- `gmail.readonly` — listar y leer los mensajes de la etiqueta
- `gmail.send` — enviar resúmenes y avisos de revisión
- `drive.file` — crear ficheros **solo** en la carpeta que crea la herramienta
- `youtube.readonly` — leer suscripciones y vídeos

Si una automatización no se usa, elimina su ámbito con `--scope` repetido.

**Google Workspace (`service_account`):** cuenta de servicio con delegación de
dominio autorizada por el administrador para esos mismos ámbitos, y
`delegated_user` con el buzón a suplantar. No hace falta `tools.authorize`.

**Resumen con Claude:** exporta la clave en el entorno del servicio, nunca en
un fichero del repositorio:

```bash
export ANTHROPIC_API_KEY=...     # nombre configurable en summarizer.api_key_env
```

## 4. Etiquetas de Gmail

Crea en Gmail las dos etiquetas dedicadas y los filtros que llevan el correo
hasta ellas:

- `Automation/Invoices` — facturas de proveedores
- `Automation/School` — comunicaciones escolares

El nombre debe coincidir exactamente con `label` en la configuración; si no
existe, la ejecución falla con un error explícito en lugar de procesar de más.

## 5. Comprobación de salud y prueba en seco

```bash
.venv/bin/python -m email_automations.gmail_invoices.cli   --health-check
.venv/bin/python -m email_automations.gmail_school.cli     --health-check
.venv/bin/python -m email_automations.youtube_to_mail.cli  --health-check

.venv/bin/python -m email_automations.gmail_invoices.cli   --dry-run
.venv/bin/python -m email_automations.gmail_school.cli     --dry-run
.venv/bin/python -m email_automations.youtube_to_mail.cli  --dry-run
```

`--health-check` valida la configuración, abre SQLite y hace una lectura
mínima contra cada servicio. `--dry-run` enumera lo pendiente sin escribir
ficheros, sin enviar correos y sin tocar el estado. Ambos imprimen solo
contadores, nunca contenido.

## 6. Primera ejecución real

En este orden:

1. `gmail_invoices` con una sola regla de proveedor y `storage.type = "local"`.
2. `gmail_school` con `summarizer.type = "keyword"` y destinatario de prueba.
3. `youtube_to_mail` **una vez** con inicialización, para no generar una
   avalancha de correos con los vídeos ya existentes:

```bash
.venv/bin/python -m email_automations.youtube_to_mail.cli --initialize-without-sending
```

Revisa resultados, cambia a `storage.type = "drive"` y a
`summarizer.type = "claude"` cuando el propietario lo apruebe, y solo entonces
activa los temporizadores.

## 7. Temporizadores (systemd)

Los ficheros de ejemplo están en `deploy/`. Instalación por usuario:

```bash
mkdir -p ~/.config/systemd/user
cp deploy/*.service deploy/*.timer ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now email-invoices.timer email-school.timer email-youtube.timer
systemctl --user list-timers 'email-*'
```

Cadencia inicial: facturas y escuela cada 5 minutos, YouTube cada hora. Las
unidades usan `RuntimeMaxSec` y no se solapan; además, la reclamación con
alquiler de SQLite impide el doble procesamiento aunque dos ejecuciones
coincidan.

## 8. Operación

- **Copia de seguridad:** copia consistente del SQLite, sin parar el servicio:
  ```bash
  sqlite3 data/automations.sqlite3 ".backup 'backups/automations-$(date +%F).sqlite3'"
  ```
- **Elementos fallidos** (reintentables en la siguiente ejecución):
  ```bash
  sqlite3 data/automations.sqlite3 \
    "SELECT automation, item_id, attempts, last_error FROM items WHERE status='failed';"
  ```
- **Reintento manual:** basta con esperar a la siguiente ejecución; el elemento
  `failed` no tiene alquiler vigente y se vuelve a reclamar.
- **Alertas:** revisa el código de salida (1 si hay elementos fallidos) y los
  logs del `journalctl --user -u email-invoices.service`. Un token caducado o
  la cuota de YouTube agotada aparecen como error de autorización explícito.
- **Revisión periódica:** etiquetas, destinatarios, reglas de proveedor y
  ámbitos concedidos.

## 9. Límites conocidos

- Un PDF escaneado no tiene texto extraíble: haría falta un paso de OCR, que no
  está incluido.
- Solo se procesan adjuntos PDF; las descargas desde portales de proveedores
  son conectores separados que se añaden caso por caso.
- Los adjuntos por encima de `max_attachment_bytes` se omiten y quedan
  registrados en el log.
