/**
 * API central de Huexs — sirve reseñas de Google al plugin Huexs Google Reviews.
 *
 * Contrato: huexs-google-reviews/docs/API_CONTRACT.md
 */

import { createApp } from './app.js';
import { config, isConfigured } from './config.js';
import { purgeCacheOlderThan } from './db.js';

const app = createApp();

// Limpieza periódica de la caché caducada, para que el fichero SQLite no crezca sin fin.
setInterval(
  () => {
    const removed = purgeCacheOlderThan(config.cacheTtlSeconds * 4);
    if (removed > 0) console.log(`[cache] ${removed} entradas caducadas eliminadas`);
  },
  24 * 3600 * 1000,
).unref();

const server = app.listen(config.port, () => {
  console.log(`[huexs-reviews-api] escuchando en :${config.port}`);
  if (!isConfigured()) {
    console.warn('[aviso] GOOGLE_MAPS_API_KEY no está configurada: las rutas de datos fallarán.');
  }
  if (!config.adminToken) {
    console.warn('[aviso] ADMIN_TOKEN no está configurado: las rutas /admin están cerradas.');
  }
});

const shutdown = (signal) => {
  console.log(`[huexs-reviews-api] ${signal} recibido, cerrando…`);
  server.close(() => process.exit(0));
};

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
