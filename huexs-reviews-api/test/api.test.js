/**
 * Pruebas de la API. Google se sustituye por un doble de `fetch`: no se gasta cuota
 * ni hace falta clave real.
 */

import assert from 'node:assert/strict';
import { after, before, describe, it, mock } from 'node:test';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const tempDir = mkdtempSync(join(tmpdir(), 'huexs-api-test-'));

process.env.DATABASE_PATH = join(tempDir, 'test.sqlite');
process.env.GOOGLE_MAPS_API_KEY = 'test-key';
process.env.ADMIN_TOKEN = 'test-admin-token';
process.env.PORT = '0';

const { createApp } = await import('../src/app.js');
const app = createApp();
const { createLicense } = await import('../src/db.js');

const FREE_KEY = 'HGR-FREE-0001';
const PRO_KEY = 'HGR-PRO-0001';
const DOMAIN_KEY = 'HGR-DOMAIN-0001';

createLicense({ key: FREE_KEY, plan: 'free' });
createLicense({ key: PRO_KEY, plan: 'pro' });
createLicense({ key: DOMAIN_KEY, plan: 'free', domain: 'cliente.com' });

let server;
let baseUrl;

before(async () => {
  await new Promise((resolve) => {
    server = app.listen(0, () => {
      baseUrl = `http://127.0.0.1:${server.address().port}`;
      resolve();
    });
  });
});

after(() => {
  server?.close();
  rmSync(tempDir, { recursive: true, force: true });
});

const call = (path, { key = FREE_KEY, site = 'https://cliente.com', headers = {} } = {}) =>
  fetch(`${baseUrl}${path}`, {
    headers: {
      ...(key ? { Authorization: `Bearer ${key}` } : {}),
      ...(site ? { 'X-Huexs-Site': site } : {}),
      ...headers,
    },
  });

/** Respuesta de Places con `count` reseñas. */
const placeDetailsPayload = (count) => ({
  id: 'ChIJtest',
  displayName: { text: 'Huexs Señalética' },
  formattedAddress: 'Carrer Exemple 12, Barcelona',
  rating: 4.8,
  userRatingCount: 127,
  googleMapsUri: 'https://maps.google.com/?cid=123',
  reviews: Array.from({ length: count }, (_, i) => ({
    name: `places/ChIJtest/reviews/rev-${i}`,
    rating: 5,
    originalText: { text: `Reseña número ${i}`, languageCode: 'es' },
    authorAttribution: {
      displayName: `Autor ${i}`,
      photoUri: 'https://lh3.googleusercontent.com/a/foto',
    },
    publishTime: '2026-07-01T10:15:30Z',
  })),
});

/**
 * Intercepta SOLO las llamadas a Google; el resto (las del propio test contra el
 * servidor local) pasan al fetch real.
 */
const mockGoogle = (payload, { status = 200 } = {}) => {
  const realFetch = globalThis.fetch;

  return mock.method(globalThis, 'fetch', async (url, options) => {
    const href = typeof url === 'string' ? url : String(url?.url ?? url);
    if (!href.includes('googleapis.com')) {
      return realFetch(url, options);
    }
    return new Response(JSON.stringify(payload), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  });
};

/** Cuántas de las llamadas registradas fueron a Google. */
const googleCalls = (spy) =>
  spy.mock.calls.filter((call) => String(call.arguments[0]).includes('googleapis.com')).length;

describe('salud y autenticación', () => {
  it('/health responde sin licencia', async () => {
    const res = await fetch(`${baseUrl}/health`);
    const body = await res.json();

    assert.equal(res.status, 200);
    assert.equal(body.status, 'ok');
    assert.equal(body.google_configured, true);
  });

  it('rechaza peticiones sin clave', async () => {
    const res = await call('/v1/account', { key: null });
    const body = await res.json();

    assert.equal(res.status, 401);
    assert.equal(body.error.code, 'invalid_license');
  });

  it('rechaza una clave inexistente', async () => {
    const res = await call('/v1/account', { key: 'HGR-NO-EXISTE' });
    assert.equal(res.status, 401);
  });

  it('respeta el anclaje a dominio', async () => {
    const ok = await call('/v1/account', { key: DOMAIN_KEY, site: 'https://cliente.com' });
    assert.equal(ok.status, 200);

    const wrong = await call('/v1/account', { key: DOMAIN_KEY, site: 'https://otro-sitio.com' });
    assert.equal(wrong.status, 401);
  });

  it('acepta subdominios del dominio autorizado', async () => {
    const res = await call('/v1/account', { key: DOMAIN_KEY, site: 'https://www.cliente.com' });
    assert.equal(res.status, 200);
  });
});

describe('GET /v1/account', () => {
  it('devuelve los límites del plan gratuito', async () => {
    const res = await call('/v1/account', { key: FREE_KEY });
    const body = await res.json();

    assert.equal(body.plan, 'free');
    assert.equal(body.limits.max_reviews, 5);
    assert.equal(body.limits.max_locations, 1);
    assert.equal(body.limits.branding_required, true);
  });

  it('devuelve los límites del plan Pro', async () => {
    const res = await call('/v1/account', { key: PRO_KEY });
    const body = await res.json();

    assert.equal(body.plan, 'pro');
    assert.equal(body.limits.branding_required, false);
    assert.ok(body.limits.layouts.includes('floating'));
  });
});

describe('GET /v1/businesses/search', () => {
  it('rechaza consultas de menos de 3 caracteres', async () => {
    const res = await call('/v1/businesses/search?q=ab');
    const body = await res.json();

    assert.equal(res.status, 400);
    assert.equal(body.error.code, 'configuration_error');
  });

  it('mapea los resultados al formato del contrato', async () => {
    mockGoogle({
      places: [
        {
          id: 'ChIJabc',
          displayName: { text: 'Huexs Señalética' },
          formattedAddress: 'Barcelona',
          rating: 4.8,
          userRatingCount: 127,
        },
        { displayName: { text: 'Sin id' } },
      ],
    });

    const res = await call('/v1/businesses/search?q=huexs-unico-1');
    const body = await res.json();
    mock.restoreAll();

    assert.equal(res.status, 200);
    assert.equal(body.results.length, 1, 'los resultados sin id se descartan');
    assert.equal(body.results[0].place_id, 'ChIJabc');
    assert.equal(body.results[0].review_count, 127);
  });
});

describe('GET /v1/locations/:placeId/reviews', () => {
  it('recorta a 5 reseñas en el plan gratuito y marca truncated', async () => {
    mockGoogle(placeDetailsPayload(5));

    const res = await call('/v1/locations/ChIJfree1/reviews', { key: FREE_KEY });
    const body = await res.json();
    mock.restoreAll();

    assert.equal(res.status, 200);
    assert.equal(body.reviews.length, 5);
    assert.equal(body.truncated, true, 'hay 127 reseñas en Google y solo servimos 5');

    // El total y la nota son los REALES, no los de la muestra servida.
    assert.equal(body.rating, 4.8);
    assert.equal(body.review_count, 127);
  });

  it('los identificadores de reseña son estables entre llamadas', async () => {
    mockGoogle(placeDetailsPayload(3));
    const first = await (await call('/v1/locations/ChIJstable/reviews')).json();
    mock.restoreAll();

    mockGoogle(placeDetailsPayload(3));
    const second = await (await call('/v1/locations/ChIJstable/reviews')).json();
    mock.restoreAll();

    assert.deepEqual(
      first.reviews.map((r) => r.id),
      second.reviews.map((r) => r.id),
      'si los id cambiaran, el plugin borraría e insertaría las mismas reseñas sin parar',
    );
  });

  it('deriva un id estable cuando Google no envía name', async () => {
    const payload = placeDetailsPayload(1);
    delete payload.reviews[0].name;
    mockGoogle(payload);

    const body = await (await call('/v1/locations/ChIJderived/reviews')).json();
    mock.restoreAll();

    assert.match(body.reviews[0].id, /^derived-[a-f0-9]{24}$/);
  });

  it('descarta reseñas con puntuación fuera de rango', async () => {
    const payload = placeDetailsPayload(2);
    payload.reviews[0].rating = 9;
    mockGoogle(payload);

    const body = await (await call('/v1/locations/ChIJbadrating/reviews')).json();
    mock.restoreAll();

    assert.equal(body.reviews.length, 1);
  });

  it('sirve desde caché sin volver a llamar a Google', async () => {
    const spy = mockGoogle(placeDetailsPayload(2));

    await call('/v1/locations/ChIJcached/reviews');
    const callsAfterFirst = googleCalls(spy);

    await call('/v1/locations/ChIJcached/reviews');
    const callsAfterSecond = googleCalls(spy);
    mock.restoreAll();

    assert.equal(callsAfterFirst, 1);
    assert.equal(callsAfterSecond, 1, 'la segunda petición debe salir de la caché');
  });

  it('traduce un fallo de Google a upstream_error sin filtrar detalles', async () => {
    mockGoogle({ error: { message: 'API key AIzaSyFAKE inválida' } }, { status: 403 });

    const res = await call('/v1/locations/ChIJerror/reviews');
    const body = await res.json();
    mock.restoreAll();

    assert.equal(res.status, 502);
    assert.equal(body.error.code, 'upstream_error');
    assert.ok(!body.error.message.includes('AIza'), 'la clave nunca debe llegar al cliente');
  });

  it('las respuestas del propietario vienen a null en esta fuente', async () => {
    mockGoogle(placeDetailsPayload(1));
    const body = await (await call('/v1/locations/ChIJreply/reviews')).json();
    mock.restoreAll();

    assert.equal(body.reviews[0].reply, null);
  });
});

describe('rutas de administración', () => {
  it('rechaza sin token de admin', async () => {
    const res = await fetch(`${baseUrl}/admin/licenses`);
    assert.equal(res.status, 401);
  });

  it('emite una licencia con el formato esperado', async () => {
    const res = await fetch(`${baseUrl}/admin/licenses`, {
      method: 'POST',
      headers: {
        Authorization: 'Bearer test-admin-token',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ plan: 'pro', domain: 'nuevo.com', label: 'Cliente nuevo' }),
    });
    const body = await res.json();

    assert.equal(res.status, 201);
    assert.match(body.key, /^HGR-[0-9A-F]{8}-[0-9A-F]{8}$/);
    assert.equal(body.plan, 'pro');

    // Y la clave recién emitida funciona de inmediato.
    const check = await call('/v1/account', { key: body.key, site: 'https://nuevo.com' });
    assert.equal(check.status, 200);
  });

  it('rechaza planes desconocidos', async () => {
    const res = await fetch(`${baseUrl}/admin/licenses`, {
      method: 'POST',
      headers: {
        Authorization: 'Bearer test-admin-token',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ plan: 'ilimitado-gratis' }),
    });

    assert.equal(res.status, 400);
  });

  it('permite desactivar una licencia', async () => {
    const created = await (
      await fetch(`${baseUrl}/admin/licenses`, {
        method: 'POST',
        headers: {
          Authorization: 'Bearer test-admin-token',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ plan: 'free' }),
      })
    ).json();

    await fetch(`${baseUrl}/admin/licenses/${created.key}`, {
      method: 'PATCH',
      headers: {
        Authorization: 'Bearer test-admin-token',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ status: 'disabled' }),
    });

    const res = await call('/v1/account', { key: created.key });
    assert.equal(res.status, 401, 'una licencia desactivada deja de funcionar al instante');
  });
});
