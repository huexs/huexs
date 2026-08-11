/**
 * Construcción de la aplicación Express, sin abrir puerto.
 *
 * Separado de server.js para que los tests puedan importarla y levantar su propio
 * listener efímero sin dejar el proceso colgado.
 */

import express from 'express';
import { adminMiddleware, licenseMiddleware } from './auth.js';
import { isConfigured } from './config.js';
import { errorHandler } from './errors.js';
import { adminRouter } from './routes/admin.js';
import { apiRouter } from './routes/api.js';

export function createApp() {
  const app = express();

  app.disable('x-powered-by');
  app.set('trust proxy', 1);
  app.use(express.json({ limit: '32kb' }));

  // Sonda de salud para el orquestador. No expone secretos ni estado de licencias.
  app.get('/health', (_req, res) => {
    res.json({ status: 'ok', google_configured: isConfigured(), version: 1 });
  });

  app.use('/v1', licenseMiddleware, apiRouter);
  app.use('/admin', adminMiddleware, adminRouter);

  app.use((_req, res) => {
    res.status(404).json({ error: { code: 'not_found', message: 'Ruta no encontrada.' } });
  });

  app.use(errorHandler);

  return app;
}
