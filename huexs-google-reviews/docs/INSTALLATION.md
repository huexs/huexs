# Instalación y operación

## Requisitos

- WordPress 6.5+, PHP 8.1+ (probado hasta 8.4), MySQL 5.7+/MariaDB.
- HTTPS en el sitio.
- Extensión Sodium u OpenSSL (presentes en prácticamente cualquier hosting moderno).

## Instalación para un cliente (modo normal)

1. Plugins → Añadir nuevo → Subir plugin → el ZIP → Activar.
2. **Google Reviews → Conexión**: pega la clave de licencia y pulsa *Activar*.
3. En el mismo panel, escribe el nombre del negocio tal y como aparece en Google (añade la ciudad si hay varios) y pulsa *Buscar*.
4. Pulsa *Usar este negocio* en el resultado correcto. Se conecta y sincroniza al momento.
5. **Google Reviews → Diseño**: elige el formato y los colores.
6. Pega el shortcode donde quieras mostrarlas.

No hace falta cuenta de Google Cloud ni ninguna clave de API: eso lo resuelve la API de Huexs.

## Shortcodes

```
[huexs_google_reviews layout="grid" limit="6"]
[huexs_google_reviews layout="carousel" limit="10"]
[huexs_google_reviews layout="sidebar" limit="8"]
[huexs_google_reviews layout="badge"]
[huexs_google_reviews layout="floating" limit="8" position="bottom-right"]
[huexs_google_rating]
```

| Atributo | Valores | Por defecto |
|---|---|---|
| `location` | `all` o el ID interno de un negocio | `all` |
| `layout` | `grid`, `list`, `carousel`, `badge`, `floating`, `sidebar` | el de la pantalla Diseño |
| `limit` | 1–50 | 6 |
| `order` | `newest`, `oldest` | `newest` |
| `position` | `bottom-right`, `bottom-left`, `top-right`, `top-left` (solo `floating`) | `bottom-right` |
| `show_avatar`, `show_date`, `show_reply`, `show_summary`, `show_count` | `true` / `false` | según ajustes |

**Elementor**: añade el widget **Shortcode** y pega cualquiera de los anteriores.

**Burbuja flotante**: como se ancla a una esquina de la pantalla, ponla una sola vez en el pie de página (o en una plantilla global de Elementor) para que aparezca en todo el sitio.

## Sincronización automática

WP-Cron cada 6/12/24 h (según plan y ajuste) más una purga diaria del contenido caducado. En sitios con poco tráfico WP-Cron puede retrasarse; se recomienda cron de sistema:

```
# wp-config.php
define( 'DISABLE_WP_CRON', true );

# crontab, cada 15 minutos
*/15 * * * * curl -s https://TU-DOMINIO/wp-cron.php > /dev/null
```

## WP-CLI

```
wp hgr sync [--location=<id>] [--force]
wp hgr status
wp hgr purge-expired
```

## Modo avanzado (proyecto propio de Google Cloud)

Para sitios que no quieran depender de la API central y necesiten **todas** las reseñas:

1. **Google Reviews → Diseño** → marca *Modo avanzado*.
2. Aparece la pantalla **Avanzado**: sigue `GOOGLE_CLOUD_SETUP.md` para crear el proyecto, aprobar Business Profile API y configurar las credenciales.
3. Conecta con Google y pulsa *Cargar mis fichas de Google*.

Los dos modos conviven: cada negocio recuerda por qué vía se dio de alta y se sincroniza en consecuencia.

---

# Desarrollo

```
composer install
composer test        # PHPUnit, sin necesidad de WordPress ni MySQL
```

## Probar sin el backend real

El plugin habla con `https://api.huexs.com/v1`. Mientras esa API no exista, hay un simulador que implementa el contrato completo (`docs/API_CONTRACT.md`):

```
php -S localhost:8787 tools/mock-api-server.php
```

Y en el `wp-config.php` del WordPress de pruebas:

```php
define( 'HGR_API_BASE_URL', 'http://localhost:8787/v1' );
```

Claves de licencia del simulador:

- `HGR-DEMO-0000-0000` → plan gratuito (5 reseñas, 1 negocio, con marca).
- `HGR-PRO-0000-0000` → plan Pro (paginado, todos los diseños, sin marca).

Busca "huexs" o "cafè" para obtener resultados de prueba.

## ZIP reproducible

```
cd huexs-google-reviews
zip -r ../huexs-google-reviews-0.2.0.zip . \
  -x 'vendor/*' 'tests/*' 'docs/*' 'tools/*' '.github/*' \
     'composer.*' 'phpunit.xml.dist' 'phpcs.xml.dist' 'phpstan.neon.dist' \
     '.gitignore' '.phpunit.cache/*'
```

## Desinstalación

- **Desactivar**: desprograma el cron y libera locks; conserva datos y ajustes.
- **Quitar un negocio**: borra ese negocio y sus reseñas.
- **Desconectar (modo avanzado)**: borra tokens y los negocios dados de alta por esa vía; no toca los conectados por la API central.
- **Desinstalar**: borra tablas y opciones solo si activaste *Eliminar todos los datos al desinstalar*.
