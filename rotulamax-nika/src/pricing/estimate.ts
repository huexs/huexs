/**
 * Calculo de precio. Determinista y sin margen de improvisacion.
 *
 * Nika nunca inventa un coste. Si no hay evidencia de proveedor valida, no hay
 * estimacion: devuelve `null` y el flujo lo trata como "falta evidencia", no
 * como "pon un numero razonable".
 */

import type { CaseFacts, Estimate, EstimateLine, ProviderEvidence } from '../types.ts';
import type { Policy } from '../config/policy.ts';
import { validateEvidence } from '../providers/registry.ts';

export interface EstimateResult {
  estimate: Estimate | null;
  problems: string[];
}

export function buildEstimate(
  facts: CaseFacts,
  evidence: ProviderEvidence[],
  policy: Policy,
  now: Date,
): EstimateResult {
  const problems: string[] = [];

  const usable = evidence.filter((e) => {
    const issues = validateEvidence(e, policy, now);
    for (const issue of issues) problems.push(`${e.providerId}: ${issue.message}`);
    return issues.length === 0;
  });

  if (usable.length < policy.provider.minEvidenceCount) {
    problems.push(
      `Evidencia insuficiente: ${usable.length} valida(s) de ${policy.provider.minEvidenceCount} requerida(s).`,
    );
    return { estimate: null, problems };
  }

  const quantity = facts.quantity?.value ?? 1;
  // Con varias evidencias se toma la mas barata; el margen se aplica encima.
  const cheapest = usable.reduce((a, b) => (a.unitCost <= b.unitCost ? a : b));
  const unitCost = cheapest.unitCost;

  const appliedRules: string[] = [
    `evidencia: ${cheapest.providerId} (${cheapest.method}) ${unitCost.toFixed(2)} EUR/ud`,
  ];

  const marginFactor = 1 / (1 - policy.pricing.targetMarginPct / 100);
  let unitPrice = unitCost * marginFactor;
  appliedRules.push(`margen objetivo ${policy.pricing.targetMarginPct}%`);

  unitPrice = roundUpTo(unitPrice, policy.pricing.roundToEuros);
  if (policy.pricing.roundToEuros > 0) {
    appliedRules.push(`redondeo al alza a ${policy.pricing.roundToEuros} EUR`);
  }

  const lines: EstimateLine[] = [
    {
      concept: describe(facts),
      quantity,
      unitCost,
      unitPrice,
    },
  ];

  let costTotal = round2(unitCost * quantity);
  let priceTotal = round2(unitPrice * quantity);

  if (priceTotal < policy.pricing.minimumOrder) {
    appliedRules.push(
      `pedido minimo ${policy.pricing.minimumOrder} EUR aplicado (subtotal era ${priceTotal.toFixed(2)} EUR)`,
    );
    priceTotal = policy.pricing.minimumOrder;
  }

  const vatRate = policy.pricing.vatRate;
  const priceWithVat = round2(priceTotal * (1 + vatRate / 100));
  const marginPct = priceTotal > 0 ? round2(((priceTotal - costTotal) / priceTotal) * 100) : 0;

  const hasInferredInputs = Object.values(facts).some(
    (f) => f && typeof f === 'object' && 'source' in f && f.source === 'inferred',
  );
  if (hasInferredInputs) {
    appliedRules.push('ATENCION: algun dato de entrada es inferido, no confirmado por el cliente');
  }

  return {
    estimate: {
      lines,
      costTotal,
      priceTotal,
      vatRate,
      priceWithVat,
      marginPct,
      currency: 'EUR',
      appliedRules,
      hasInferredInputs,
    },
    problems,
  };
}

function describe(facts: CaseFacts): string {
  const parts: string[] = [facts.product?.value ?? 'producto sin identificar'];
  const dims = facts.dimensions?.value;
  if (dims) parts.push(`${trim(dims.widthCm)}x${trim(dims.heightCm)} cm`);
  if (facts.material) parts.push(facts.material.value);
  return parts.join(' · ');
}

function trim(value: number): string {
  return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

function roundUpTo(value: number, step: number): number {
  if (step <= 0) return round2(value);
  return Math.ceil(value / step) * step;
}

/** Superficie en m2, util para tarifas por metro cuadrado. */
export function areaM2(facts: CaseFacts): number | null {
  const dims = facts.dimensions?.value;
  if (!dims) return null;
  return round2((dims.widthCm / 100) * (dims.heightCm / 100));
}
