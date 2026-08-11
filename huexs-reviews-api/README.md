# Huexs Reviews API

Servidor central que alimenta el plugin **Huexs Google Reviews**. Es lo que permite que un cliente conecte su negocio escribiendo solo su nombre, sin tocar Google Cloud.

Implementa el contrato definido en `../huexs-google-reviews/docs/API_CONTRACT.md`.

```
WordPress del cliente  ──►  esta API  ──►  Google Places API
   (clave de licencia)      (tu clave de Google, tu caché, tus planes)
```

**La clave de Google vive solo aquí.** Ningún cliente la ve ni la puede extraer del plugin.

---

## 1. Desplegar en easypanel

1. En easypanel: **Create Service → App**.
2. Origen: este repositorio, carpeta `huexs-reviews-api/`. Detectará el `Dockerfile`.
3. **Environment**: añade estas variables (ver punto 2 para la de Google).

   | Variable | Valor |
   |---|---|
   | `GOOGLE_MAPS_API_KEY` | *(tu clave de Google Places)* |
   | `ADMIN_TOKEN` | genera uno: `openssl rand -hex 32` |
   | `CACHE_TTL_SECONDS` | `21600` |
   | `DATABASE_PATH` | `/data/huexs-reviews.sqlite` |

4. **Volumes**: monta un volumen persistente en `/data`.
   Esto es importante: ahí viven las licencias. Sin volumen, un redespliegue las borra.
5. **Domains**: asigna `api.huexs.com` y activa el certificado HTTPS.
6. Despliega y comprueba: `https://api.huexs.com/health` debe responder
   `{"status":"ok","google_configured":true,...}`.

Si `google_configured` sale en `false`, la variable `GOOGLE_MAPS_API_KEY` no llegó al contenedor.

### Alternativa: cualquier servidor con Docker

```bash
cp .env.example .env     # rellena GOOGLE_MAPS_API_KEY y ADMIN_TOKEN
docker compose up -d
```

### Alternativa: sin Docker

```bash
npm install
cp .env.example .env
node --env-file=.env src/server.js
```

---

## 2. La clave de Google (única tarea manual, ~10 min)

1. Entra en <https://console.cloud.google.com/> y crea un proyecto (o usa uno existente).
2. **APIs y servicios → Biblioteca** → busca **Places API (New)** → **Habilitar**.
3. **Facturación**: hay que asociar una tarjeta. Google da 200 $/mes de crédito gratuito,
   que para este uso es de sobra (ver *Costes* abajo).
4. **APIs y servicios → Credenciales → Crear credenciales → Clave de API**.
5. **Restringe la clave** (importante, no te saltes esto):
   - *Restricciones de API* → **Restringir clave** → marca solo **Places API (New)**.
   - *Restricciones de aplicación* → **Direcciones IP** → añade la IP pública de tu servidor.

   Sin restringir, una clave filtrada la puede gastar cualquiera contra tu tarjeta.
6. Copia la clave y **pégala directamente en la variable de entorno de easypanel**.
   No la escribas en ningún archivo del repositorio ni la envíes por chat o correo.

---

## 3. Emitir licencias para clientes

**Desde la línea de comandos** (terminal del contenedor):

```bash
npm run license:issue -- --plan=free --domain=cliente.com --label="Peluquería Ana"
npm run license:issue -- --plan=pro  --domain=otrocliente.es --label="Hotel Mar"
npm run license:list
```

**Por HTTP**, con el `ADMIN_TOKEN`:

```bash
# Emitir
curl -X POST https://api.huexs.com/admin/licenses \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan":"pro","domain":"cliente.com","label":"Hotel Mar"}'

# Listar
curl https://api.huexs.com/admin/licenses -H "Authorization: Bearer $ADMIN_TOKEN"

# Revocar (deja de funcionar al instante)
curl -X PATCH https://api.huexs.com/admin/licenses/HGR-XXXX-XXXX \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"disabled"}'

# Cuánto estás gastando
curl https://api.huexs.com/admin/usage -H "Authorization: Bearer $ADMIN_TOKEN"
```

`--domain` ancla la clave a un dominio: si el cliente la copia en otra web, deja de funcionar.
Se aceptan subdominios (`cliente.com` cubre `www.cliente.com`). Omítelo para una clave libre.

---

## 4. Conectar un WordPress

En el WordPress del cliente, con el plugin instalado:

1. **Google Reviews → Conexión** → pega la clave → *Activar*.
2. Escribe el nombre del negocio → *Buscar* → *Usar este negocio*.

Si el plugin apunta a otro servidor (staging, pruebas locales), en su `wp-config.php`:

```php
define( 'HGR_API_BASE_URL', 'https://api.huexs.com/v1' );
```

Por defecto ya usa `https://api.huexs.com/v1`, así que en producción no hace falta tocar nada.

---

## 5. Planes

Se definen en `src/config.js`. El recorte se aplica **en el servidor**: el plugin es GPL y un
cliente podría editarlo, así que nunca se confía en él para los límites.

| | free | pro |
|---|---|---|
| Reseñas por negocio | 5 | 200 |
| Negocios | 1 | 25 |
| Diseños | lista, cuadrícula, carrusel, insignia | todos |
| Marca "por Huexs" | sí | no |
| Frecuencia mínima | 24 h | 6 h |

El tope de 5 en `free` no es arbitrario: es el máximo que devuelve Places API. Para superarlo
hace falta la fuente Business Profile con OAuth, que es lo que justifica el plan Pro (pendiente
de implementar, ver *Limitaciones*).

---

## 6. Costes

Places API se factura por llamada, pero la caché hace casi todo el trabajo:

- Con `CACHE_TTL_SECONDS=21600` (6 h), cada negocio genera **4 llamadas al día** como mucho.
- La caché es **por negocio, no por cliente**: dos clientes con la misma ficha comparten llamada.
- Places Details ronda los **17 $ por cada 1.000 llamadas**.

Cuentas redondas: 50 negocios × 4 llamadas/día × 30 días ≈ 6.000 llamadas ≈ **100 $/mes**,
que entra dentro del crédito gratuito de 200 $/mes de Google.

Si creces, sube `CACHE_TTL_SECONDS`. Con 24 h, ese mismo escenario baja a ~1.500 llamadas (~25 $).
Las reseñas no cambian tan rápido como para necesitar refrescos cada 6 horas.

---

## 7. Limitaciones actuales

Honestamente, lo que **todavía no** hace:

- **OAuth Pro sin implementar.** `/v1/oauth/start` devuelve 501. Hasta que se implemente, el plan
  `pro` sirve los mismos datos de Places (máximo 5 reseñas) aunque permita más diseños y negocios.
  Es el siguiente paso natural.
- **Sin respuestas del propietario.** Places API no las expone; llegan siempre como `null`.
  Con Business Profile sí vendrían.
- **Sin límite de peticiones por licencia.** Un cliente con un cron agresivo podría gastar cuota.
  La caché lo amortigua, pero conviene añadir rate limiting antes de abrir a muchos clientes.
- **SQLite.** Perfecto hasta unos cientos de clientes. Más allá, migrar a Postgres.

---

## 8. Desarrollo

```bash
npm install
npm test        # 20 pruebas; Google se sustituye por un doble, no gasta cuota
npm run dev
```

Las pruebas cubren autenticación, anclaje a dominio, recorte por plan, estabilidad de los
identificadores de reseña, caché, y que un error de Google nunca filtre la API key al cliente.
