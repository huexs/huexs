/**
 * Rutas de administración: emitir, listar y revocar licencias sin entrar por SSH.
 * Protegidas por ADMIN_TOKEN.
 */

import { Router } from 'express';
import { randomBytes } from 'node:crypto';
import { config } from '../config.js';
import {
  createLicense,
  findLicense,
  listLicenses,
  purgeCacheOlderThan,
  setLicensePlan,
  setLicenseStatus,
  usageSince,
} from '../db.js';
import { badRequest, notFound } from '../errors.js';

export const adminRouter = Router();

/** Formato HGR-XXXXXXXX-XXXXXXXX, legible y fácil de dictar. */
const generateKey = () => {
  const chunk = () => randomBytes(4).toString('hex').toUpperCase();
  return `HGR-${chunk()}-${chunk()}`;
};

adminRouter.post('/licenses', (req, res, next) => {
  const plan = String(req.body?.plan ?? 'free');
  if (!Object.keys(config.plans).includes(plan)) {
    return next(badRequest(`Plan desconocido: ${plan}`));
  }

  const domain = req.body?.domain ? String(req.body.domain).trim() : null;
  const label = req.body?.label ? String(req.body.label).trim() : null;
  const expiresAt = req.body?.expires_at ? String(req.body.expires_at) : null;

  const key = generateKey();
  createLicense({ key, plan, domain, label, expiresAt });

  res.status(201).json({ key, plan, domain, label, expires_at: expiresAt });
});

adminRouter.get('/licenses', (_req, res) => {
  res.json({ licenses: listLicenses() });
});

adminRouter.patch('/licenses/:key', (req, res, next) => {
  const key = String(req.params.key);
  if (!findLicense(key)) return next(notFound('Licencia no encontrada.'));

  if (req.body?.status) {
    const status = String(req.body.status);
    if (!['active', 'disabled'].includes(status)) {
      return next(badRequest('El estado debe ser active o disabled.'));
    }
    setLicenseStatus(key, status);
  }

  if (req.body?.plan) {
    const plan = String(req.body.plan);
    if (!Object.keys(config.plans).includes(plan)) {
      return next(badRequest(`Plan desconocido: ${plan}`));
    }
    setLicensePlan(key, plan);
  }

  res.json(findLicense(key));
});

adminRouter.get('/usage', (_req, res) => {
  res.json({
    last_24h: usageSince(24 * 3600),
    last_30d: usageSince(30 * 24 * 3600),
  });
});

adminRouter.post('/cache/purge', (_req, res) => {
  const removed = purgeCacheOlderThan(0);
  res.json({ purged: removed });
});
