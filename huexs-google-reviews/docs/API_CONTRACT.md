# Contrato de la API central de Huexs — v1

Este documento define el contrato **que debe implementar el backend de Huexs** (`api.huexs.com`). El plugin se ha construido contra esta especificación; mientras el backend no exista, el plugin funciona contra un doble de pruebas (`tests/Support/FakeHuexsApi.php`).

- Base URL: `https://api.huexs.com/v1` (configurable con la constante `HGR_API_BASE_URL` para staging).
- Todas las respuestas son JSON con `Content-Type: application/json; charset=utf-8`.
- Todas las peticiones se hacen **desde el servidor de WordPress**, nunca desde el navegador.

## Autenticación

Cada sitio se identifica con una **clave de licencia** emitida por Huexs.

```http
Authorization: Bearer HGR-XXXXXXXX-XXXXXXXX
X-Huexs-Site: https://sitio-del-cliente.com
X-Huexs-Plugin: 0.2.0
```

- `X-Huexs-Site` permite vincular la licencia a un dominio y detectar reventa de claves.
- Una clave inválida, caducada o usada en un dominio no autorizado devuelve `401` con `error.code = invalid_license`.

## Formato de error

Todos los errores usan la misma envoltura, con códigos estables:

```json
{ "error": { "code": "plan_limit", "message": "Tu plan permite un máximo de 1 ubicación." } }
```

| `error.code` | HTTP | Significado en el plugin |
|---|---|---|
| `invalid_license` | 401 | Licencia ausente, caducada o de otro dominio |
| `plan_limit` | 402 | El plan no cubre la acción (más ubicaciones, layout Pro…) |
| `not_found` | 404 | El `place_id` no existe o ya no es accesible |
| `rate_limited` | 429 | Demasiadas peticiones (respetar `Retry-After`) |
| `upstream_error` | 502 | Fallo hablando con Google |
| `configuration_error` | 400 | Petición malformada |

---

## 1. Estado de la cuenta

```http
GET /v1/account
```

```json
{
  "plan": "free",
  "status": "active",
  "expires_at": null,
  "limits": {
    "max_reviews": 5,
    "max_locations": 1,
    "layouts": ["list", "grid", "carousel", "badge"],
    "branding_required": true,
    "min_sync_interval_hours": 24
  }
}
```

- `plan`: `free` | `pro`.
- `limits.max_reviews`: número máximo de reseñas que el backend servirá por ubicación.
- `limits.layouts`: layouts permitidos; el plugin oculta/bloquea el resto en la interfaz.
- `branding_required`: si es `true`, el plugin muestra el enlace discreto "Reseñas por Huexs".
- `min_sync_interval_hours`: suelo de frecuencia; el plugin no permitirá programar por debajo.

El plugin cachea esta respuesta 12 h (transient) y la refresca tras cualquier `402`.

## 2. Buscar un negocio por nombre

Es el corazón de la experiencia: el cliente escribe el nombre de su negocio y elige de una lista.

```http
GET /v1/businesses/search?q=Huexs%20Barcelona&language=es&region=ES
```

```json
{
  "results": [
    {
      "place_id": "ChIJ7cv00DwsDogRAMDACa2m4K8",
      "name": "Huexs Señalética",
      "formatted_address": "Carrer d'Exemple 12, 08001 Barcelona",
      "rating": 4.8,
      "review_count": 127
    }
  ]
}
```

- `q` es obligatorio, mínimo 3 caracteres. Máximo 10 resultados.
- El backend resuelve esto con **Places API (New) `places:searchText`**.
- Debe cachearse en el backend: la misma consulta no debe generar una llamada a Google por cada pulsación de tecla.

## 3. Obtener reseñas de una ubicación

```http
GET /v1/locations/{place_id}/reviews?limit=50&cursor=
```

```json
{
  "place_id": "ChIJ7cv00DwsDogRAMDACa2m4K8",
  "name": "Huexs Señalética",
  "public_url": "https://maps.google.com/?cid=1234567890",
  "rating": 4.8,
  "review_count": 127,
  "source": "places",
  "truncated": true,
  "fetched_at": "2026-08-11T10:00:00Z",
  "next_cursor": null,
  "reviews": [
    {
      "id": "places/ChIJ.../reviews/abc123",
      "author_name": "María García",
      "author_photo_url": "https://lh3.googleusercontent.com/a/...",
      "rating": 5,
      "text": "Servicio excelente.\nMuy recomendable.",
      "language": "es",
      "created_at": "2026-07-01T10:15:30Z",
      "updated_at": "2026-07-01T10:15:30Z",
      "reply": { "text": "¡Gracias María!", "updated_at": "2026-07-02T09:00:00Z" }
    }
  ]
}
```

Reglas que el backend **debe** cumplir:

- `id` debe ser **estable entre sincronizaciones** para la misma reseña. Si Google no da un ID estable (caso de Places API), derívalo de forma determinista, p. ej. `sha1(place_id + author_name + created_at)`. Si el `id` cambia entre llamadas, el plugin borrará e insertará las mismas reseñas continuamente.
- `rating` de cada reseña es **entero 1–5**.
- `text` es texto plano. **No devuelvas HTML**: el plugin lo escapa y cualquier marcado se mostrará literal.
- `rating` y `review_count` de la ubicación son los **totales reales** de Google, aunque `reviews` venga recortado. Son los datos que alimentan los widgets `badge`/`floating`.
- `truncated: true` indica que hay más reseñas de las servidas (por límite de plan o de la propia fuente). El plugin lo usa para mostrar el aviso de upgrade al administrador.
- `source`: `places` (máx. 5 reseñas) o `business_profile` (todas). El plugin lo muestra en diagnóstico.
- Paginación opcional con `next_cursor`; si es `null`, la lectura terminó. **Importante:** el plugin solo borra reseñas locales ausentes cuando la paginación termina correctamente.

## 4. OAuth Pro (todas las reseñas)

El `client_secret` de Google vive **solo en el backend de Huexs**; nunca en el plugin. El flujo es:

```http
POST /v1/oauth/start
{ "return_url": "https://sitio.com/wp-admin/admin.php?page=hgr-connection" }
```

```json
{ "authorize_url": "https://api.huexs.com/v1/oauth/redirect?t=...", "state": "abc123" }
```

El plugin redirige el navegador del administrador a `authorize_url`. Huexs hace el baile OAuth con Google, guarda el refresh token **en su lado**, y devuelve al usuario a `return_url` con `?hgr_oauth=ok&state=abc123`.

```http
GET /v1/oauth/status?state=abc123
```

```json
{
  "status": "connected",
  "locations": [
    { "place_id": "ChIJ...", "name": "Huexs Señalética", "location_name": "locations/456" }
  ]
}
```

`status`: `pending` | `connected` | `error`. Tras `connected`, las llamadas del punto 3 para esas ubicaciones devolverán `source: "business_profile"` y todas las reseñas.

```http
POST /v1/oauth/disconnect   { "place_id": "ChIJ..." }
```

Debe revocar el token en Google y borrar los datos asociados en el backend.

---

## Notas de implementación para el backend

1. **Cachea agresivamente.** Places Details se factura por llamada. Con `min_sync_interval_hours` de 24 h en el plan gratis, un cliente cuesta céntimos al mes. Cachea por `place_id`, no por sitio: varios clientes pueden compartir negocio.
2. **Nunca devuelvas más de `limits.max_reviews`** aunque la fuente dé más: el gating de plan se aplica en el servidor, no en el plugin (el plugin es código GPL que el cliente puede modificar).
3. **No inventes URLs de reseña individual.** `public_url` debe ser la URL real de la ficha; si no la tienes, devuelve `null`.
4. **Respeta el borrado.** Si un cliente desconecta, borra sus datos; el plugin borra los suyos en el mismo acto.
5. **`Retry-After` en los 429**: el plugin lo respeta y hace backoff exponencial con jitter.
