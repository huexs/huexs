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

---

# Cambio de alcance — 0.2.0 (11 de agosto de 2026)

El propietario decidió reorientar el producto: de plugin interno con OAuth por instalación a **producto distribuible y monetizable** para todos los negocios y clientes de Huexs. Esto contradice deliberadamente varios puntos de la especificación 1.0, que se registran aquí.

## D14 — La API central de Huexs pasa a ser la fuente por defecto

La especificación 1.0 prohibía Places API (§2). Se revierte esa decisión: la fuente por defecto es la **API central de Huexs** (`api.huexs.com`), que internamente resuelve con **Places API (New)**.

Motivo: el requisito de producto es que cualquier cliente conecte escribiendo solo el nombre de su negocio, sin proyecto de Google Cloud ni OAuth. Places API permite exactamente eso (`places:searchText` → Place ID → nota, total y reseñas). El coste es su límite duro de **5 reseñas por ficha**.

Se descartó el scraping de Google Maps que se planteó inicialmente: incumple los Términos de Google, expone a bloqueos en un producto vendido a terceros, y reintroduce la fragilidad que el proyecto quería eliminar, solo que ahora en el servidor de Huexs. Queda registrado como opción rechazada, no como pendiente.

## D15 — OAuth Pro se ejecuta en el servidor, no en el plugin

Un plugin distribuido no puede contener el `client_secret` de Google: cualquiera que descargue el ZIP lo leería. Por tanto el flujo OAuth de la API de Business Profile (que sí da todas las reseñas) lo ejecuta el backend de Huexs, que custodia las credenciales. El plugin solo redirige y consulta estado (`/v1/oauth/start`, `/v1/oauth/status`).

Es el mismo modelo que usa Trustindex, verificado al auditar su plugin 13.3.1: su cliente WordPress es una carcasa y toda la lógica vive en `admin.trustindex.io`.

## D16 — El código OAuth de la 0.1.0 se conserva como "modo avanzado"

En lugar de eliminarlo, el OAuth directo con proyecto propio de Google Cloud pasa a ser un modo opcional (`advanced_mode`), pensado para los sitios propios de Huexs y para clientes que no quieran depender del servicio central. Da todas las reseñas sin intermediario.

## D17 — Abstracción `ReviewSourceInterface`

El plugin no conoce Google: conoce *fuentes*. `HuexsApiSource` y `SelfHostedGoogleSource` implementan el mismo contrato y cada ubicación recuerda con cuál fue dada de alta (columna `source`). `SyncService` es agnóstico.

Consecuencia buscada: si mañana cambia la fuente (otra API, otro proveedor, Business Profile directo), se escribe una clase nueva y no se toca nada más.

Contrato clave: **una fuente que no puede completar la lectura lanza excepción; nunca devuelve un resultado parcial.** Es lo que preserva la regla crítica de que una sincronización incompleta no borre reseñas.

## D18 — Esquema v2: ubicaciones agnósticas de fuente

`hgr_locations` gana `source`, `ref_key`, `place_id`, `address`, `truncated` y `source_label`. El índice único pasa de `(google_account_name, google_location_name)` a `(source, ref_key)`. Como `dbDelta()` no elimina índices obsoletos, la migración v1→v2 lo hace explícitamente y marca las filas heredadas como `google_oauth`.

## D19 — El gating de plan se aplica en el servidor

El plugin es GPL: cualquier cliente puede editar el PHP y quitarse los límites. Por eso `limits.max_reviews` y `layouts` solo sirven para que la interfaz sea coherente; **el recorte real lo hace la API** al no servir más reseñas de las que cubre el plan. Documentado como requisito en `API_CONTRACT.md`.

## D20 — Catálogo de diseños ampliado

Auditado el plugin de Trustindex (GPLv2+) para extraer aprendizajes. Hallazgo relevante: **sus diseños no están en el ZIP**; se descargan de `cdn.trustindex.io/assets/widget-presetted-css/v2/{styleId}-{setId}.css`. No se ha copiado ningún archivo suyo — la GPL cubre el código del plugin, no los assets servidos desde su CDN, y reimplementar es además más limpio.

Lo que sí se ha tomado es el **catálogo de formatos**, que es una idea de producto, no código: se añaden `badge` (insignia compacta con nota y total), `floating` (burbuja fija en una esquina) y `sidebar` (columna estrecha) a los `list`/`grid`/`carousel` existentes. `badge` y `floating` funcionan solo con nota media y total, datos que Places API sí devuelve completos aunque las reseñas vengan recortadas: son los formatos que mejor rinden en el plan gratuito.

No se ha incorporado su filtro por estrellas: la especificación lo prohíbe (§3.4) y esa prohibición se mantiene.

## D21 — Servidor simulado incluido

`tools/mock-api-server.php` implementa el contrato completo con datos ficticios. Permite probar el plugin de extremo a extremo sin backend y sirve de referencia ejecutable para quien lo implemente. No se distribuye en el ZIP.

---

# 0.3.0 — Modo completo sin licencia (11 de agosto de 2026)

## D22 — El propietario no debe licenciarse a sí mismo

Detectado en cuanto se probó la 0.2.0: nada más entrar, el plugin exigía activar una
licencia aunque el sitio fuera del propio Huexs. Es un fallo de diseño: la licencia solo
tiene sentido para **quien consume el servicio central**, no para quien pone su propia
infraestructura.

Nueva regla: **la licencia se exige únicamente si el sitio depende de la API de Huexs.**
`License::isUnlocked()` devuelve verdadero si hay clave propia de Places (modo directo) o
si hay OAuth propio conectado (modo avanzado). En esos casos el plan pasa a `full`, sin
límites de reseñas, ubicaciones, diseños ni marca.

## D23 — Modo directo (`DirectPlacesSource`)

Tercera fuente: el propio WordPress llama a Google Places sin intermediarios. Requisitos:
solo una clave. Ventajas para los sitios propios: cero infraestructura, cero licencia, y
funciona antes de que exista el servidor central.

Prioridad de fuente por defecto: clave propia de Places → OAuth propio → API de Huexs.

## D24 — La clave de Places sigue el patrón de las credenciales OAuth

`Support\PlacesKey`: constante `HGR_GOOGLE_PLACES_KEY` en wp-config.php (recomendado) o,
si el hosting no permite editarlo, opción cifrada mediante `Crypto`. Nunca en texto plano,
nunca en Git, nunca en el ZIP.

Esta indirección resolvió además un problema de pruebas: una constante global no se puede
deshacer entre tests y contaminaba los del modo API. Con la clave inyectada, cada prueba
controla su propio estado.

## D25 — La API key de Google no se hardcodea, en ningún caso

Se planteó pegar la clave en el repositorio. Se descartó por un motivo práctico antes que
formal: GitHub escanea repositorios en busca de credenciales y notifica al proveedor, y
Google revoca automáticamente las claves detectadas. Una clave commiteada se rompe sola en
minutos. A eso se suma que el plugin se distribuye como ZIP: cualquier secreto en el repo
acabaría en manos de cada cliente que lo instale.

La clave vive en una de estas tres, todas fuera de Git: variable de entorno del servidor
(API central), constante en wp-config.php, u opción cifrada en la base de datos del sitio.

## Pendiente de decidir (producto)

El modelo del plan gratuito para terceros: si se limita a actualización manual (como los
plugins freemium del repositorio de WordPress.org) o a un número menor de reseñas con
sincronización automática. Está sin decidir y no bloquea nada.

## D26 — El selector de diseño usa datos reales, no maquetas

Los plugins de la competencia enseñan miniaturas genéricas y el usuario tiene que
imaginarse el resultado. Aquí, en cuanto hay un negocio conectado, cada opción del
selector renderiza el diseño real con las reseñas ya sincronizadas, a escala mediante
`transform: scale()`.

Detalles que hubo que resolver:

- Las miniaturas llevan `pointer-events: none` para que el clic llegue a la etiqueta y
  seleccione el diseño en lugar de interactuar con la vista previa.
- La burbuja flotante es `position: fixed` y un `<details>` plegado. En la miniatura se
  ancla a su contenedor y se abre con el atributo `open` mediante el argumento
  `force_open`. Intentar abrirla solo con CSS no funciona: el navegador oculta el
  contenido de un `details` cerrado a nivel de agente de usuario y ninguna regla de
  autor lo revierte.
- Si todavía no hay reseñas sincronizadas, el selector cae a las miniaturas esquemáticas
  de siempre.

---

# 0.4.1 — Correcciones sobre pruebas reales

## D27 — La pantalla se renderizaba dos veces

Al registrar el mismo slug con `add_menu_page()` y `add_submenu_page()` se pasaban
**dos instancias distintas** de `ConnectionPage`. WordPress las trata como callbacks
diferentes (identidades distintas en `_wp_filter_build_unique_id`), engancha las dos al
hook de la pantalla y ejecuta ambas. Con una única instancia reutilizada, el segundo
registro sustituye al primero en lugar de sumarse.

## D28 — Un error de la clave de Google no es un problema de licencia

`WpHttpClient` traduce 401/403 a `invalid_license` porque su caso de uso principal es la
API de Huexs. En modo directo eso era engañoso: un 403 de Google por API no habilitada o
restricción mal puesta acababa diciéndole al administrador que revisara una clave de
licencia que ni siquiera usa.

`DirectPlacesSource` ahora reetiqueta ese caso como `places_key_rejected`, conserva
íntegro el mensaje de Google y sugiere las tres causas reales (Places API New sin
habilitar, restricción por referente HTTP en lugar de por IP, o facturación inactiva).

Regla general que deja esto: **el mensaje de error de origen nunca se sustituye por uno
genérico.** Un "no se encontró nada" que en realidad era un 403 cuesta horas de
diagnóstico.

## D29 — Búsqueda predictiva con presupuesto en mente

El buscador sugiere mientras se escribe mediante `admin-ajax` (solo `manage_options` y
con nonce; no es un endpoint público). Dos límites deliberados, porque **cada búsqueda es
una llamada facturable a Google**:

- mínimo de 3 caracteres antes de consultar;
- *debounce* de 350 ms, y toda petición en vuelo se cancela al seguir escribiendo.

Verificado en navegador: teclear "Rotula" letra a letra genera **una** llamada, no seis.
Sin JavaScript, el botón "Buscar" sigue funcionando con el envío del formulario.
