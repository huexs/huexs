# Decisiones arquitectónicas — Huexs Google Reviews 0.1.0

Fecha: 2026-08-11. Cada decisión indica su motivo; la especificación (`ESPECIFICACION_MVP_PLUGIN_GOOGLE_REVIEWS.md`) es la fuente de verdad del producto.

## D1 — Fuente de datos: Google Business Profile API exclusivamente
Según la especificación. Endpoints usados:
- `GET https://mybusinessaccountmanagement.googleapis.com/v1/accounts` (cuentas, paginado).
- `GET https://mybusinessbusinessinformation.googleapis.com/v1/{parent}/locations?readMask=name,title,storeCode` (ubicaciones, paginado).
- `GET https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/reviews` (reseñas, paginado; la respuesta incluye `averageRating` y `totalReviewCount`).

Nota de verificación: la API de reseñas sigue siendo la v4 legada (`mybusiness.googleapis.com`); Google no la ha migrado a los servicios v1 escindidos. Verificado contra la documentación oficial listada en la especificación (§19) a fecha del documento. Si Google publica un reemplazo v1 para reviews, solo hay que tocar `GoogleBusinessProfileClient`.

## D2 — Tablas propias, no CPT
`{prefix}hgr_locations`, `{prefix}hgr_reviews`, `{prefix}hgr_sync_logs` con `dbDelta()` y opción `hgr_db_version`. Motivo: datos remotos temporales con índices y purga por fecha.

## D3 — Cifrado de tokens
`Support\Crypto` con detección: libsodium (secretbox) → OpenSSL AES-256-GCM → error (no se guardan tokens). Clave derivada con SHA-256 de `AUTH_KEY + SECURE_AUTH_KEY` + etiqueta de dominio. Prefijos de versión (`v1s:`/`v1o:`) para permitir rotación futura de formato.

## D4 — Idioma fuente: español
La especificación pide "textos traducibles y traducción inicial al español". Los strings fuente están escritos directamente en español y envueltos en las funciones i18n de WordPress con text domain `huexs-google-reviews`. Así la "traducción inicial al español" es el propio código y `languages/` queda listo para generar el `.pot` (`wp i18n make-pot`) cuando se necesite otro idioma. Es un MVP interno para sitios en español; si se distribuye, se migrará a fuente en inglés + `es_ES.po`.

## D5 — Repositorios detrás de interfaces
`LocationRepositoryInterface`, `ReviewRepositoryInterface`, `SyncLogRepositoryInterface` con implementación `$wpdb` en producción y dobles en memoria en tests. Permite probar `SyncService`, retención y lock sin WordPress ni MySQL.

## D6 — Sincronización completa por ubicación
Lectura paginada completa + upsert + `last_seen_at` + borrado de no-vistas **solo si la paginación terminó**. Una excepción a mitad de recorrido aborta el borrado (regla crítica de la spec). Errores de una ubicación no detienen a las demás, salvo `auth_expired`/`configuration_error` que afectan a todas.

## D7 — Lock por opción con add_option
`add_option()` no sobrescribe valores existentes, lo que sirve de operación de adquisición razonablemente atómica en WordPress. Lock con `run_id` + timestamp, caducidad 20 min y recuperación de huérfanos. Limitación conocida: no es un lock perfecto bajo condiciones de carrera extremas de MySQL, pero cumple el requisito de la spec (mecanismo seguro equivalente) sin SQL directo adicional.

## D8 — "Leer más" accesible
El DOM siempre contiene el comentario completo. El recorte visual (line-clamp) y el botón solo se activan con JavaScript; sin JS se ve el texto íntegro. Cumple el requisito de conservar contenido completo en el DOM accesible.

## D9 — Carrusel nativo con scroll-snap
Sin librerías. Botones anterior/siguiente, navegación con flechas del teclado sobre la pista (`tabindex=0`), sin autoplay, `prefers-reduced-motion` respetado. Sin JS degrada a fila desplazable (scroll-snap CSS), cumpliendo "funcionamiento sin JavaScript".

## D10 — URL pública "Ver en Google"
Campo manual por ubicación, validado: HTTPS y dominios de Google (`google.*`, `goo.gl`, `g.page`, `maps.app.goo.gl`). No se fabrican URLs de reseñas individuales.

## D11 — Frescura de datos en frontend
Las consultas de presentación descartan filas con `last_seen_at` > 30 días y los resúmenes con `last_synced_at` > 30 días, además de la purga diaria. Avisos de estado vacío/caducado solo para administradores (configurable).

## D12 — Autoload propio, sin vendor en producción
`spl_autoload_register` PSR-4 en el bootstrap del plugin. Composer solo se usa para dev (PHPUnit). El ZIP no incluye vendor/, tests ni fixtures.

## D13 — Desconexión = borrado total
Al desconectar: revocación best-effort del token en Google, borrado de tokens, reseñas, ubicaciones y logs. Cumple §13 y criterio de aceptación 9.
