/**
 * Cliente de Google Places API (New).
 *
 * Solo dos operaciones:
 *   - places:searchText   → buscar un negocio por nombre
 *   - places/{placeId}    → ficha con nota, total y hasta 5 reseñas
 *
 * Limitaciones conocidas de esta fuente, que el contrato refleja:
 *   - Devuelve como máximo 5 reseñas, elegidas por Google.
 *   - No incluye las respuestas del propietario (reply siempre null).
 * Ambas desaparecen con la fuente Business Profile (plan Pro).
 */

import { createHash } from 'node:crypto';
import { config } from '../config.js';
import { notFound, rateLimited, upstream } from '../errors.js';

const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';
const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

const SEARCH_FIELDS = [
  'places.id',
  'places.displayName',
  'places.formattedAddress',
  'places.rating',
  'places.userRatingCount',
].join(',');

const DETAILS_FIELDS = [
  'id',
  'displayName',
  'formattedAddress',
  'rating',
  'userRatingCount',
  'googleMapsUri',
  'reviews',
].join(',');

const TIMEOUT_MS = 15000;

async function call(url, { method = 'GET', body = null, fieldMask }) {
  if (!config.googleApiKey) {
    throw upstream('El servidor no tiene configurada GOOGLE_MAPS_API_KEY.');
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

  let response;
  try {
    response = await fetch(url, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Goog-Api-Key': config.googleApiKey,
        'X-Goog-FieldMask': fieldMask,
      },
      body: body ? JSON.stringify(body) : undefined,
      signal: controller.signal,
    });
  } catch (err) {
    throw upstream(
      err.name === 'AbortError'
        ? 'Google no respondió a tiempo.'
        : `No se pudo contactar con Google: ${err.message}`,
    );
  } finally {
    clearTimeout(timer);
  }

  if (response.status === 404) throw notFound('Google no conoce esa ficha.');
  if (response.status === 429) throw rateLimited('Google está limitando las peticiones.');

  const text = await response.text();

  if (!response.ok) {
    // El detalle de Google puede incluir la API key: nunca se propaga al cliente.
    console.error('[places] HTTP', response.status, text.slice(0, 500));
    throw upstream(`Google respondió con HTTP ${response.status}.`);
  }

  try {
    return JSON.parse(text);
  } catch {
    throw upstream('Google devolvió una respuesta que no es JSON válido.');
  }
}

/** Identificador estable de reseña, requisito del contrato. */
function stableReviewId(placeId, review) {
  if (typeof review.name === 'string' && review.name.includes('/reviews/')) {
    return review.name;
  }
  // Sin `name`, se deriva de forma determinista para que no cambie entre llamadas.
  const author = review.authorAttribution?.displayName ?? '';
  const seed = `${placeId}|${author}|${review.publishTime ?? ''}`;
  return `derived-${createHash('sha1').update(seed).digest('hex').slice(0, 24)}`;
}

function mapReview(placeId, review) {
  const rating = Number.parseInt(review.rating, 10);
  if (!Number.isFinite(rating) || rating < 1 || rating > 5) return null;

  const author = review.authorAttribution ?? {};
  const text = review.originalText?.text ?? review.text?.text ?? null;

  return {
    id: stableReviewId(placeId, review),
    author_name: author.displayName ?? null,
    author_photo_url:
      typeof author.photoUri === 'string' && author.photoUri.startsWith('https://')
        ? author.photoUri
        : null,
    rating,
    text: text && text.trim() !== '' ? text : null,
    language: review.originalText?.languageCode ?? review.text?.languageCode ?? null,
    created_at: review.publishTime ?? null,
    updated_at: review.publishTime ?? null,
    // Places API (New) no expone las respuestas del propietario.
    reply: null,
  };
}

export async function searchBusinesses(query, { language, region }) {
  const data = await call(SEARCH_URL, {
    method: 'POST',
    fieldMask: SEARCH_FIELDS,
    body: {
      textQuery: query,
      languageCode: language ?? config.defaultLanguage,
      regionCode: region ?? config.defaultRegion,
      maxResultCount: 10,
    },
  });

  return (data.places ?? [])
    .filter((place) => place?.id && place?.displayName?.text)
    .map((place) => ({
      place_id: place.id,
      name: place.displayName.text,
      formatted_address: place.formattedAddress ?? '',
      rating: typeof place.rating === 'number' ? place.rating : null,
      review_count: typeof place.userRatingCount === 'number' ? place.userRatingCount : null,
    }));
}

export async function fetchPlace(placeId, { language }) {
  const url = `${DETAILS_URL}${encodeURIComponent(placeId)}?languageCode=${encodeURIComponent(
    language ?? config.defaultLanguage,
  )}`;

  const place = await call(url, { fieldMask: DETAILS_FIELDS });

  const reviews = (place.reviews ?? [])
    .map((review) => mapReview(placeId, review))
    .filter(Boolean);

  return {
    place_id: place.id ?? placeId,
    name: place.displayName?.text ?? null,
    public_url: place.googleMapsUri ?? null,
    rating: typeof place.rating === 'number' ? place.rating : null,
    review_count: typeof place.userRatingCount === 'number' ? place.userRatingCount : null,
    source: 'places',
    reviews,
  };
}
