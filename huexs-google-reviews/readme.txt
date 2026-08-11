=== Huexs Google Reviews ===
Contributors: huexs
Tags: google reviews, google business profile, reseñas, reviews, shortcode
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincroniza y muestra reseñas de Google Business Profile mediante OAuth 2.0, con actualización automática cada 6/12/24 horas.

== Description ==

Plugin interno de Huexs que conecta con Google Business Profile API mediante OAuth 2.0, sincroniza todas las reseñas de las ubicaciones que administras y las muestra con shortcodes compatibles con Elementor (widget Shortcode).

Características del MVP:

* Conexión OAuth 2.0 con scope mínimo (business.manage). Tokens cifrados (Sodium o AES-256-GCM).
* Selección de una o varias ubicaciones administradas.
* Sincronización completa y paginada de reseñas, manual o automática (6/12/24 h) con WP-Cron.
* Las reseñas se tratan como caché temporal: se renuevan en cada sincronización y se purgan si superan 30 días sin renovarse.
* Diseños lista, cuadrícula y carrusel accesible (JavaScript nativo, degrada sin JS).
* Shortcodes `[huexs_google_reviews]` y `[huexs_google_rating]` (valoración ponderada entre ubicaciones).
* Opciones de presentación: fondo claro/oscuro/transparente, colores, avatar, fecha, respuesta del propietario, "Leer más" accesible y logo de Google en la atribución.
* Pantalla de estado y diagnóstico, registro de sincronizaciones y comandos WP-CLI (`wp hgr sync|status|purge-expired`).
* Desconexión que elimina inmediatamente tokens y datos sincronizados.

No se filtran reseñas por puntuación: se muestran todas las sincronizadas.

== Installation ==

1. Sube el ZIP desde Plugins → Añadir nuevo → Subir plugin, o copia la carpeta a `wp-content/plugins/`.
2. Activa el plugin.
3. Define en `wp-config.php`:
   `define( 'HGR_GOOGLE_CLIENT_ID', '…' );`
   `define( 'HGR_GOOGLE_CLIENT_SECRET', '…' );`
4. En Google Reviews → Conexión, copia la Redirect URI en tu proyecto de Google Cloud y pulsa "Conectar con Google".
5. En Google Reviews → Ubicaciones, carga y activa tus ubicaciones.
6. Pulsa "Sincronizar ahora" e inserta `[huexs_google_reviews]` en cualquier página o en el widget Shortcode de Elementor.

Consulta `docs/GOOGLE_CLOUD_SETUP.md` para la configuración completa del proyecto de Google Cloud (requiere aprobación de Business Profile API).

== Frequently Asked Questions ==

= ¿Por qué no usa Places API? =

Places API devuelve como máximo cinco reseñas elegidas por Google. Business Profile API permite recuperar todas las reseñas de las ubicaciones verificadas que administras.

= ¿Funciona con Elementor? =

Sí, mediante el widget Shortcode de Elementor. El widget nativo llegará en una versión futura.

== Changelog ==

= 0.1.0 =
* Versión inicial del MVP.
