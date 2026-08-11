# Resolución de problemas

Empieza siempre por **Google Reviews → Estado**: identifica credenciales, criptografía, OAuth, cron y última sincronización.

## Conexión

| Síntoma | Causa probable | Acción |
|---|---|---|
| "Configura primero el Client ID…" | Faltan credenciales | Define las constantes en `wp-config.php` o guárdalas en Conexión |
| "El estado OAuth no es válido o ha caducado" | Tardaste >10 min en el consentimiento, o cookies/transients purgados | Reintenta la conexión del tirón |
| "No se pudo canjear el código…" | Redirect URI distinta a la registrada, o Client Secret erróneo | Copia la Redirect URI exacta de la pantalla Conexión en Google Cloud |
| "Google no entregó un refresh token" | Ya existía un consentimiento previo sin refresh token | En https://myaccount.google.com/permissions revoca el acceso de la app y reconecta |
| "No hay criptografía segura disponible" | PHP sin Sodium ni OpenSSL AES-256-GCM | Pide al hosting habilitar Sodium/OpenSSL |
| Conexión caducada recurrente | App OAuth en modo "Testing" (los refresh tokens caducan a los 7 días) | Publica la app en la pantalla de consentimiento |

## Sincronización

| Síntoma | Código | Acción |
|---|---|---|
| Todas las ubicaciones fallan | `auth_expired` | Reconecta la cuenta en Conexión |
| 403 | `permission_denied` | La cuenta no administra la ficha, o el proyecto no tiene aprobado Basic API Access |
| 403 con quota | `quota` | Espera a la renovación de cuota; reduce frecuencia |
| 429 | `rate_limited` | El plugin ya reintenta con backoff; si persiste, reduce frecuencia |
| "Ya hay una sincronización en curso" | `locked` | Espera ≤20 min; el lock huérfano se recupera solo |
| Reseñas a 0 tras sincronizar | — | La ubicación no está activada, o la ficha no tiene reseñas |

## Cron

- "El evento de cron no está programado" → desactiva y reactiva el plugin.
- Próxima ejecución atrasada → el sitio tiene poco tráfico; configura cron de sistema (ver INSTALLATION.md).
- `wp hgr status` muestra el estado desde consola.

## Frontend

- No aparece nada y eres visitante → correcto si no hay datos; los avisos solo los ven administradores.
- No aparece nada y eres admin → lee el aviso: sin ubicaciones activas o sin reseñas sincronizadas/frescas.
- El carrusel se ve como fila estática → JavaScript bloqueado; es la degradación prevista.
- Los estilos no cargan → algún optimizador de assets puede estar excluyendo `hgr-frontend`; añade una excepción.

## HTTPS y proxies

El callback OAuth usa `admin_url()`, que respeta `siteurl`. Detrás de un proxy/CDN asegúrate de que WordPress ve HTTPS (por ejemplo `$_SERVER['HTTPS']='on'` a partir de `X-Forwarded-Proto` en `wp-config.php`, solo si tu proxy es de confianza). Si la Redirect URI generada sale con `http://`, Google la rechazará.
