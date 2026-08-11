# Instalación y operación

## Requisitos

- WordPress 6.5+, PHP 8.1+ (probado hasta 8.4), MySQL 5.7+/MariaDB.
- HTTPS en el sitio (obligatorio para el callback OAuth).
- Extensión Sodium u OpenSSL (presentes en prácticamente cualquier hosting moderno).

## Instalación

1. Genera el ZIP (ver "ZIP reproducible" abajo) o copia la carpeta `huexs-google-reviews/` a `wp-content/plugins/`.
2. Activa el plugin en Plugins. La activación crea las tablas y programa el cron; no contacta con Google.
3. Sigue `GOOGLE_CLOUD_SETUP.md` para credenciales y conexión.
4. Google Reviews → Ubicaciones → "Cargar cuentas y ubicaciones desde Google" → activa las que quieras sincronizar.
5. Google Reviews → Resumen → "Sincronizar ahora".

## Uso en páginas

```
[huexs_google_reviews location="all" layout="grid" limit="6" order="newest"
  show_avatar="true" show_date="true" show_reply="true" show_summary="true"]

[huexs_google_rating location="all" show_count="true"]
```

- `location`: `all` o el ID interno de una ubicación (visible en la pantalla Ubicaciones).
- `layout`: `list`, `grid`, `carousel`.
- `limit`: 1–50. `order`: `newest` | `oldest`.

En **Elementor**: añade el widget **Shortcode** y pega el shortcode.

## Sincronización automática

- WP-Cron cada 6/12/24 h (configurable en Presentación). Purga diaria de contenido caducado (30 días).
- En sitios con poco tráfico WP-Cron puede retrasarse. Recomendado cron de sistema:

```
# wp-config.php
define( 'DISABLE_WP_CRON', true );

# crontab (cada 15 minutos)
*/15 * * * * curl -s https://TU-DOMINIO/wp-cron.php > /dev/null
# o con WP-CLI:
*/15 * * * * cd /ruta/al/wordpress && wp cron event run --due-now > /dev/null
```

## WP-CLI

```
wp hgr sync [--location=<id>] [--force]
wp hgr status
wp hgr purge-expired
```

## Desarrollo

```
composer install
composer test        # PHPUnit (unit tests, sin WordPress)
```

## ZIP reproducible

Desde la raíz del repositorio:

```
cd huexs-google-reviews
git archive --format=zip --prefix=huexs-google-reviews/ -o /tmp/huexs-google-reviews-0.1.0.zip HEAD -- \
  huexs-google-reviews.php uninstall.php readme.txt src templates assets languages
```

Alternativa sin git (excluye tests, docs de desarrollo, vendor y configuración de tooling):

```
zip -r huexs-google-reviews-0.1.0.zip huexs-google-reviews \
  -x 'huexs-google-reviews/vendor/*' 'huexs-google-reviews/tests/*' \
     'huexs-google-reviews/docs/*' 'huexs-google-reviews/.github/*' \
     'huexs-google-reviews/composer.*' 'huexs-google-reviews/phpunit.xml.dist' \
     'huexs-google-reviews/phpcs.xml.dist' 'huexs-google-reviews/phpstan.neon.dist' \
     'huexs-google-reviews/.phpunit.cache/*'
```

## Desinstalación

- Desactivar: desprograma el cron y libera locks; conserva datos y ajustes.
- Desconectar (pantalla Conexión): borra tokens y TODOS los datos sincronizados inmediatamente.
- Desinstalar: borra tablas y opciones solo si activaste "Eliminar todos los datos al desinstalar" en Presentación.
