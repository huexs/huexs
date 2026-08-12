/**
 * Registro de proveedores y validacion de evidencia.
 *
 * Nika no navega formularios de proveedores por su cuenta. Solo usa fuentes
 * declaradas aqui y evidencia con enlace comprobable a mano. Un proveedor con
 * `requiresForm: true` obliga a que una persona capture el coste; el agente no
 * inventa un precio para rellenar el hueco.
 */

import type { Provider, ProviderEvidence } from '../types.ts';
import type { Policy } from '../config/policy.ts';

/**
 * Catalogo provisional. Sustituir por los proveedores reales de RotulaMax.
 * Ver docs/07_OPEN_QUESTIONS.md Q3: hace falta la lista real con tarifas.
 */
export const PROVIDERS: Provider[] = [
  {
    id: 'generico_gran_formato',
    name: 'Proveedor gran formato (PENDIENTE DE CONFIRMAR)',
    products: ['lona', 'vinilo', 'vinilo_microperforado', 'display'],
    requiresForm: true,
    currency: 'EUR',
  },
  {
    id: 'generico_rigidos',
    name: 'Proveedor soportes rigidos (PENDIENTE DE CONFIRMAR)',
    products: ['forex', 'dibond', 'metacrilato'],
    requiresForm: true,
    currency: 'EUR',
  },
  {
    id: 'generico_luminosos',
    name: 'Proveedor luminosos y corporeos (PENDIENTE DE CONFIRMAR)',
    products: ['rotulo_luminoso', 'rotulo_corporeo', 'banderola'],
    requiresForm: true,
    currency: 'EUR',
  },
];

export function providersFor(product: string | undefined, catalog: Provider[] = PROVIDERS): Provider[] {
  if (!product) return [];
  return catalog.filter((p) => p.products.includes(product));
}

export function isProductCovered(product: string | undefined, catalog: Provider[] = PROVIDERS): boolean {
  // Sin producto identificado no se puede afirmar que no haya cobertura. Esa
  // duda la resuelve la puerta de datos criticos preguntando, no escalando.
  if (!product) return true;
  return providersFor(product, catalog).length > 0;
}

export interface EvidenceProblem {
  code: string;
  message: string;
}

/** Comprobaciones de una evidencia antes de aceptarla para calcular precio. */
export function validateEvidence(
  evidence: ProviderEvidence,
  policy: Policy,
  now: Date,
  catalog: Provider[] = PROVIDERS,
): EvidenceProblem[] {
  const problems: EvidenceProblem[] = [];

  if (!catalog.some((p) => p.id === evidence.providerId)) {
    problems.push({
      code: 'evidence.unknown-provider',
      message: `Proveedor "${evidence.providerId}" no esta en el registro.`,
    });
  }

  let url: URL | null = null;
  try {
    url = new URL(evidence.url);
  } catch {
    problems.push({ code: 'evidence.bad-url', message: `URL no valida: ${evidence.url}` });
  }
  if (url && !policy.provider.allowedUrlSchemes.includes(url.protocol)) {
    problems.push({
      code: 'evidence.bad-scheme',
      message: `Esquema "${url.protocol}" no permitido. Solo ${policy.provider.allowedUrlSchemes.join(', ')}.`,
    });
  }

  if (!(evidence.unitCost > 0)) {
    problems.push({ code: 'evidence.bad-cost', message: 'El coste unitario debe ser mayor que cero.' });
  }
  if (!Number.isInteger(evidence.quantity) || evidence.quantity <= 0) {
    problems.push({ code: 'evidence.bad-quantity', message: 'La cantidad debe ser un entero positivo.' });
  }

  const ageHours = (now.getTime() - new Date(evidence.capturedAt).getTime()) / 3600000;
  if (!Number.isFinite(ageHours)) {
    problems.push({ code: 'evidence.bad-date', message: `Fecha invalida: ${evidence.capturedAt}` });
  } else if (ageHours < 0) {
    problems.push({ code: 'evidence.future-date', message: 'La evidencia esta fechada en el futuro.' });
  } else if (ageHours > policy.provider.evidenceMaxAgeHours) {
    problems.push({
      code: 'evidence.stale',
      message: `Evidencia de hace ${Math.round(ageHours)} h; el maximo es ${policy.provider.evidenceMaxAgeHours} h.`,
    });
  }

  return problems;
}
