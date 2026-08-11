/**
 * Rutas del contrato v1 que consume el plugin.
 */

import { Router } from 'express';
import { config } from '../config.js';
import { logRequest, readCache, writeCache } from '../db.js';
import { badRequest, notFound } from '../errors.js';
import { fetchPlace, searchBusinesses } from '../google/places.js';

export const apiRouter = Router();

// ---- GET /v1/account ----

apiRouter.get('/account', (req, res) => {
  logRequest({ licenseKey: req.license.key, route: 'account', upstream: false, status: 200 });

  res.json({
    plan: req.license.plan,
    status: 'active',
    expires_at: req.license.expires_at ?? null,
    limits: req.plan,
  });
});

// ---- GET /v1/businesses/search ----

apiRouter.get('/businesses/search', async (req, res, next) => {
  const query = String(req.query.q ?? '').trim();
  if (query.length < 3) {
    return next(badRequest('La consulta debe tener al menos 3 caracteres.'));
  }

  const language = String(req.query.language ?? config.defaultLanguage).slice(0, 5);
  const region = String(req.query.region ?? config.defaultRegion).slice(0, 2);
  const cacheKey = `search:${language}:${region}:${query.toLowerCase()}`;

  try {
    const cached = readCache(cacheKey, config.cacheTtlSeconds);
    if (cached) {
      logRequest({ licenseKey: req.license.key, route: 'search', upstream: false, status: 200 });
      return res.json(cached);
    }

    const results = await searchBusinesses(query, { language, region });
    const payload = { results };

    writeCache(cacheKey, payload);
    logRequest({ licenseKey: req.license.key, route: 'search', upstream: true, status: 200 });

    res.json(payload);
  } catch (err) {
    logRequest({
      licenseKey: req.license.key,
      route: 'search',
      upstream: true,
      status: err.status ?? 500,
    });
    next(err);
  }
});

// ---- GET /v1/locations/:placeId/reviews ----

apiRouter.get('/locations/:placeId/reviews', async (req, res, next) => {
  const placeId = String(req.params.placeId ?? '').trim();
  if (placeId === '' || placeId.length > 256) {
    return next(badRequest('Identificador de negocio no válido.'));
  }

  const language = String(req.query.language ?? config.defaultLanguage).slice(0, 5);
  const cacheKey = `place:${language}:${placeId}`;

  try {
    let place = readCache(cacheKey, config.cacheTtlSeconds);
    let fromCache = true;

    if (!place) {
      place = await fetchPlace(placeId, { language });
      writeCache(cacheKey, place);
      fromCache = false;
    }

    if (!place) return next(notFound('Ese negocio no está disponible.'));

    // El recorte por plan se aplica AQUÍ. El plugin es GPL y no es de fiar para esto.
    const limit = req.plan.max_reviews;
    const all = place.reviews ?? [];
    const served = all.slice(0, limit);

    // `truncated` cubre los dos motivos: recorte de plan y recorte de la propia fuente.
    const truncated =
      all.length > served.length ||
      (typeof place.review_count === 'number' && place.review_count > served.length);

    logRequest({
      licenseKey: req.license.key,
      route: 'reviews',
      upstream: !fromCache,
      status: 200,
    });

    res.json({
      place_id: place.place_id,
      name: place.name,
      public_url: place.public_url,
      rating: place.rating,
      review_count: place.review_count,
      source: place.source,
      truncated,
      fetched_at: new Date().toISOString(),
      next_cursor: null,
      reviews: served,
    });
  } catch (err) {
    logRequest({
      licenseKey: req.license.key,
      route: 'reviews',
      upstream: true,
      status: err.status ?? 500,
    });
    next(err);
  }
});

// ---- OAuth Pro: reservado para la siguiente fase ----

apiRouter.post('/oauth/start', (_req, _res, next) => {
  next(
    Object.assign(new Error('El flujo OAuth Pro todavía no está implementado en este servidor.'), {
      status: 501,
      code: 'upstream_error',
    }),
  );
});
