/**
 * Configuración por variables de entorno. Sin valores secretos por defecto.
 */

const int = (value, fallback) => {
  const parsed = Number.parseInt(value ?? '', 10);
  return Number.isFinite(parsed) ? parsed : fallback;
};

export const config = {
  port: int(process.env.PORT, 8080),

  // Clave de Google Cloud con Places API (New) habilitada. Sin ella el servidor arranca
  // pero devuelve upstream_error en las rutas que necesitan datos reales.
  googleApiKey: process.env.GOOGLE_MAPS_API_KEY ?? '',

  // Ruta del fichero SQLite. En Docker se monta un volumen en /data.
  databasePath: process.env.DATABASE_PATH ?? './data/huexs-reviews.sqlite',

  // Token para las rutas de administración (emitir/revocar licencias por HTTP).
  adminToken: process.env.ADMIN_TOKEN ?? '',

  // Cuánto tiempo se cachea la respuesta de Google por negocio. Places Details se
  // factura por llamada: subir este valor abarata, bajarlo refresca antes.
  cacheTtlSeconds: int(process.env.CACHE_TTL_SECONDS, 6 * 3600),

  // Idioma y región por defecto cuando el plugin no los envía.
  defaultLanguage: process.env.DEFAULT_LANGUAGE ?? 'es',
  defaultRegion: process.env.DEFAULT_REGION ?? 'ES',

  // Límites por plan. El recorte se aplica aquí, en el servidor: el plugin es GPL
  // y un cliente podría editarlo, así que nunca se confía en él para el gating.
  plans: {
    free: {
      max_reviews: 5,
      max_locations: 1,
      layouts: ['list', 'grid', 'carousel', 'badge'],
      branding_required: true,
      min_sync_interval_hours: 24,
    },
    pro: {
      max_reviews: 200,
      max_locations: 25,
      layouts: ['list', 'grid', 'carousel', 'badge', 'floating', 'sidebar'],
      branding_required: false,
      min_sync_interval_hours: 6,
    },
  },
};

export const isConfigured = () => config.googleApiKey.length > 0;
