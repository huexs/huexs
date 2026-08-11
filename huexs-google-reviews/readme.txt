=== Huexs Google Reviews ===
Contributors: huexs
Tags: google reviews, google business profile, reseñas, reviews, shortcode
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Muestra tus reseñas de Google y mantenlas actualizadas solas. Sin configuración técnica: busca tu negocio por su nombre y listo.

== Description ==

Conecta tu negocio en menos de un minuto: activa tu clave de licencia, escribe el nombre de tu negocio tal y como aparece en Google, elígelo de la lista y las reseñas empiezan a sincronizarse solas.

No necesitas cuenta de Google Cloud, ni claves de API, ni procesos de aprobación: de eso se encarga la API de Huexs.

**Seis diseños listos para usar**

* Cuadrícula, lista y carrusel accesible.
* Insignia compacta con la nota media y el número de reseñas.
* Burbuja flotante fija en una esquina de la pantalla.
* Columna lateral para barras y widgets estrechos.

Todos responsive, con fondo claro / oscuro / transparente, colores personalizables y tres estilos de tarjeta (sombra, borde o plano).

**Actualización automática de verdad**

* Sincronización programada cada 6, 12 o 24 horas mediante WP-Cron, más sincronización manual y comandos WP-CLI.
* Una sincronización fallida nunca destruye las reseñas ya guardadas.
* Las reseñas se tratan como caché temporal: se renuevan en cada pasada y se purgan si pasan 30 días sin renovarse, conforme a las políticas de Google.

**Compatible con Elementor**

Los shortcodes `[huexs_google_reviews]` y `[huexs_google_rating]` funcionan en cualquier página, entrada o widget, y dentro del widget Shortcode de Elementor.

**Modo avanzado (opcional)**

Si prefieres no depender del servicio central, puedes conectar tu propio proyecto de Google Cloud por OAuth y obtener todas las reseñas directamente de Google Business Profile API.

No se filtran reseñas por puntuación: se muestran todas las sincronizadas.

== Installation ==

1. Sube el ZIP desde Plugins → Añadir nuevo → Subir plugin, o copia la carpeta a `wp-content/plugins/`.
2. Activa el plugin.
3. Ve a **Google Reviews → Conexión** y pega tu clave de licencia.
4. Escribe el nombre de tu negocio, búscalo y pulsa "Usar este negocio". Las reseñas se sincronizan al instante.
5. Inserta `[huexs_google_reviews]` en cualquier página, o el widget Shortcode de Elementor.

Solo para el modo avanzado (proyecto propio de Google Cloud) consulta `docs/GOOGLE_CLOUD_SETUP.md`.

== Frequently Asked Questions ==

= ¿Cuántas reseñas muestra? =

Depende del plan. El plan gratuito muestra hasta 5 reseñas por negocio (es el límite que impone la fuente de datos pública de Google), pero la **nota media y el número total de reseñas siempre son los reales y completos** — por eso los diseños de insignia y burbuja flotante lucen igual de bien en cualquier plan. El plan Pro conecta tu ficha por OAuth y trae todas las reseñas.

= ¿Necesito una cuenta de Google Cloud? =

No. Solo la clave de licencia. La integración con Google la gestiona la API de Huexs.

= ¿Funciona con Elementor? =

Sí, mediante el widget Shortcode de Elementor. El widget nativo llegará en una versión futura.

= ¿Se pueden ocultar las reseñas malas? =

No. El plugin no filtra por puntuación, por decisión de diseño y por coherencia con las políticas de presentación de Google.

== Changelog ==

= 0.2.0 =
* Nuevo: conexión por clave de licencia y búsqueda del negocio por su nombre. Ya no hace falta configurar Google Cloud.
* Nuevo: diseños insignia, burbuja flotante y columna lateral.
* Nuevo: estilos de tarjeta (sombra, borde, plano) y selector visual de diseño.
* Nuevo: arquitectura de fuentes intercambiables; el OAuth con proyecto propio pasa a ser "modo avanzado".
* Nuevo: servidor simulado de la API para desarrollo y pruebas.

= 0.1.0 =
* Versión inicial del MVP con OAuth propio de Google Business Profile.
