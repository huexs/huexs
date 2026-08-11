/**
 * Autenticación por clave de licencia y control de plan.
 */

import { config } from './config.js';
import { findLicense, touchLicense } from './db.js';
import { unauthorized } from './errors.js';

/** Extrae el host de una URL, o null si no es una URL utilizable. */
const hostOf = (value) => {
  try {
    return new URL(value).host.replace(/^www\./i, '').toLowerCase();
  } catch {
    return null;
  }
};

export function licenseMiddleware(req, _res, next) {
  const header = req.get('authorization') ?? '';
  const match = header.match(/^Bearer\s+(.+)$/i);
  if (!match) {
    return next(unauthorized('Falta la clave de licencia.'));
  }

  const key = match[1].trim();
  const license = findLicense(key);

  if (!license || license.status !== 'active') {
    return next(unauthorized('Clave de licencia no válida o desactivada.'));
  }

  if (license.expires_at && new Date(license.expires_at).getTime() < Date.now()) {
    return next(unauthorized('La licencia ha caducado.'));
  }

  // Si la licencia está anclada a un dominio, se comprueba contra el sitio que llama.
  const site = req.get('x-huexs-site') ?? '';
  if (license.domain) {
    const allowed = license.domain.replace(/^www\./i, '').toLowerCase();
    const actual = hostOf(site);
    if (!actual || (actual !== allowed && !actual.endsWith(`.${allowed}`))) {
      return next(
        unauthorized('Esta licencia está asignada a otro dominio. Pide una clave para este sitio.'),
      );
    }
  }

  touchLicense(key, site || null);

  req.license = license;
  req.plan = config.plans[license.plan] ?? config.plans.free;

  next();
}

/** Middleware de las rutas de administración. */
export function adminMiddleware(req, _res, next) {
  if (!config.adminToken) {
    return next(unauthorized('El servidor no tiene ADMIN_TOKEN configurado.'));
  }
  const header = req.get('authorization') ?? '';
  const match = header.match(/^Bearer\s+(.+)$/i);
  if (!match || match[1].trim() !== config.adminToken) {
    return next(unauthorized('Token de administración no válido.'));
  }
  next();
}
