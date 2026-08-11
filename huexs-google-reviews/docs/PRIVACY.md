# Privacidad

## Qué datos maneja el plugin

- **Reseñas públicas de Google**: nombre visible del autor, URL del avatar, puntuación, comentario, fechas y respuesta del propietario. Se almacenan como **caché temporal** (máximo 30 días sin renovar) en tablas propias de la base de datos de WordPress.
- **Tokens OAuth** de la cuenta de Google conectada: cifrados (Sodium o AES-256-GCM) en la tabla de opciones. Nunca aparecen en HTML, JavaScript, logs ni REST.
- El plugin **no** modifica el texto ni la puntuación recibidos, **no** oculta reseñas por puntuación y **no** descarga avatares a la biblioteca de medios: los avatares se cargan desde dominios de Google con `referrerpolicy="no-referrer"`.

## Ciclo de vida

- Cada sincronización renueva `last_seen_at`; una tarea diaria purga lo no renovado en 30 días.
- Desconectar la cuenta elimina inmediatamente tokens y todo el contenido importado.
- La desinstalación elimina tablas y opciones si el administrador activó la opción explícita.

## Texto sugerido para la política de privacidad del sitio

> Este sitio muestra reseñas públicas obtenidas de Google Business Profile a través de la API oficial de Google. Las reseñas incluyen el nombre público y, en su caso, la foto de perfil del autor tal y como constan en Google. Las imágenes de perfil se cargan directamente desde servidores de Google, por lo que tu navegador puede conectar con dominios de Google al visualizar esta página. Los textos de las reseñas se almacenan temporalmente (máximo 30 días) para su presentación y se actualizan o eliminan de forma automática. Si eres autor de una reseña y deseas modificarla o eliminarla, hazlo desde tu cuenta de Google; los cambios se reflejarán en este sitio en la siguiente sincronización.
