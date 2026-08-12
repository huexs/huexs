import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { CaseFacts, Field, ProviderEvidence } from '../src/types.ts';
import { buildEstimate } from '../src/pricing/estimate.ts';
import { validateEvidence } from '../src/providers/registry.ts';
import { DEFAULT_POLICY, withPolicy } from '../src/config/policy.ts';
import { NOW } from './helpers.ts';

function field<T>(value: T, source: Field<T>['source'] = 'explicit'): Field<T> {
  return { value, confidence: 0.9, source, evidence: 'prueba', messageId: 'm1' };
}

const FACTS: CaseFacts = {
  product: field('lona'),
  dimensions: field({ widthCm: 300, heightCm: 100 }),
  quantity: field(2),
};

function evidence(overrides: Partial<ProviderEvidence> = {}): ProviderEvidence {
  return {
    providerId: 'generico_gran_formato',
    capturedAt: '2026-08-11T08:00:00.000Z',
    unitCost: 45,
    quantity: 1,
    url: 'https://proveedor.test/tarifa',
    method: 'tariff',
    ...overrides,
  };
}

describe('precio', () => {
  it('sin evidencia no hay estimacion: no se inventa un precio', () => {
    const result = buildEstimate(FACTS, [], DEFAULT_POLICY, NOW);
    assert.equal(result.estimate, null);
    assert.ok(result.problems.some((p) => p.includes('Evidencia insuficiente')));
  });

  it('aplica el margen objetivo sobre el coste', () => {
    const result = buildEstimate(FACTS, [evidence()], DEFAULT_POLICY, NOW);
    // 45 / (1 - 0.45) = 81,81 -> redondeo al alza a 82
    assert.equal(result.estimate?.lines[0]?.unitPrice, 82);
    assert.equal(result.estimate?.priceTotal, 164);
  });

  it('calcula el IVA sobre la base imponible', () => {
    const result = buildEstimate(FACTS, [evidence()], DEFAULT_POLICY, NOW);
    assert.equal(result.estimate?.priceWithVat, 198.44);
  });

  it('elige la evidencia mas barata cuando hay varias', () => {
    const result = buildEstimate(
      FACTS,
      [evidence({ unitCost: 60 }), evidence({ providerId: 'generico_rigidos', unitCost: 30 })],
      DEFAULT_POLICY,
      NOW,
    );
    assert.equal(result.estimate?.lines[0]?.unitCost, 30);
  });

  it('aplica el pedido minimo', () => {
    const facts: CaseFacts = { ...FACTS, quantity: field(1) };
    const result = buildEstimate(facts, [evidence({ unitCost: 5 })], DEFAULT_POLICY, NOW);
    assert.equal(result.estimate?.priceTotal, DEFAULT_POLICY.pricing.minimumOrder);
    assert.ok(result.estimate?.appliedRules.some((r) => r.includes('pedido minimo')));
  });

  it('avisa cuando algun dato de entrada es inferido', () => {
    const facts: CaseFacts = { ...FACTS, quantity: field(1, 'inferred') };
    const result = buildEstimate(facts, [evidence()], DEFAULT_POLICY, NOW);
    assert.equal(result.estimate?.hasInferredInputs, true);
  });

  it('deja rastro de que evidencia se uso', () => {
    const result = buildEstimate(FACTS, [evidence()], DEFAULT_POLICY, NOW);
    assert.ok(result.estimate?.appliedRules[0]?.includes('generico_gran_formato'));
  });
});

describe('validacion de evidencia', () => {
  it('rechaza un proveedor que no esta en el registro', () => {
    const problems = validateEvidence(evidence({ providerId: 'inventado' }), DEFAULT_POLICY, NOW);
    assert.ok(problems.some((p) => p.code === 'evidence.unknown-provider'));
  });

  it('rechaza enlaces que no son http(s)', () => {
    const problems = validateEvidence(
      evidence({ url: 'file:///home/user/tarifa.pdf' }),
      DEFAULT_POLICY,
      NOW,
    );
    assert.ok(problems.some((p) => p.code === 'evidence.bad-scheme'));
  });

  it('rechaza evidencia caducada', () => {
    const policy = withPolicy({ provider: { evidenceMaxAgeHours: 1 } });
    const problems = validateEvidence(
      evidence({ capturedAt: '2026-01-01T00:00:00.000Z' }),
      policy,
      NOW,
    );
    assert.ok(problems.some((p) => p.code === 'evidence.stale'));
  });

  it('rechaza evidencia fechada en el futuro', () => {
    const problems = validateEvidence(
      evidence({ capturedAt: '2027-01-01T00:00:00.000Z' }),
      DEFAULT_POLICY,
      NOW,
    );
    assert.ok(problems.some((p) => p.code === 'evidence.future-date'));
  });

  it('acepta una evidencia correcta', () => {
    assert.deepEqual(validateEvidence(evidence(), DEFAULT_POLICY, NOW), []);
  });
});
