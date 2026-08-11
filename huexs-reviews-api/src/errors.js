/**
 * Errores con la envoltura del contrato (docs/API_CONTRACT.md del plugin).
 */

export class ApiError extends Error {
  constructor(status, code, message) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export const badRequest = (message) => new ApiError(400, 'configuration_error', message);
export const unauthorized = (message) => new ApiError(401, 'invalid_license', message);
export const planLimit = (message) => new ApiError(402, 'plan_limit', message);
export const notFound = (message) => new ApiError(404, 'not_found', message);
export const rateLimited = (message) => new ApiError(429, 'rate_limited', message);
export const upstream = (message) => new ApiError(502, 'upstream_error', message);

/** Middleware final: cualquier error sale con la misma forma. */
export const errorHandler = (err, req, res, _next) => {
  const status = err instanceof ApiError ? err.status : 500;
  const code = err instanceof ApiError ? err.code : 'upstream_error';
  const message =
    err instanceof ApiError ? err.message : 'Error interno del servidor.';

  if (!(err instanceof ApiError)) {
    console.error('[error]', err);
  }

  res.status(status).json({ error: { code, message } });
};
