/**
 * Pruebas del motor de decision.
 *
 * Los dos bloques centrales son "no responde siempre" y "no escala siempre":
 * son los dos fallos que motivaron este motor. Si alguien relaja la politica y
 * vuelve a aparecer alguno de los dos, estas pruebas fallan.
 */

import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { CaseFacts, Field, IntentResult, ProviderEvidence } from '../src/types.ts';
import { decide, missingCriticalFields, type DecisionInput } from '../src/triage/decide.ts';
import { DEFAULT_POLICY, withPolicy } from '../src/config/policy.ts';
import { inbound, thread, NOW } from './helpers.ts';

function field<T>(value: T, confidence = 0.9, source: Field<T>['source'] = 'explicit'): Field<T> {
  return { value, confidence, source, evidence: 'prueba', messageId: 'm1' };
}

const COMPLETE_FACTS: CaseFacts = {
  product: field('lona'),
  dimensions: field({ widthCm: 300, heightCm: 100 }),
  quantity: field(2),
  deliveryAddress: field('Calle Industria 45, 08025 Barcelona'),
};

const EVIDENCE: ProviderEvidence[] = [
  {
    providerId: 'generico_gran_formato',
    capturedAt: '2026-08-11T08:00:00.000Z',
    unitCost: 45,
    quantity: 1,
    url: 'https://proveedor.test/tarifa',
    method: 'tariff',
  },
];

function input(overrides: Partial<DecisionInput> = {}): DecisionInput {
  const message = overrides.message ?? inbound({ text: 'Necesito presupuesto para una lona.' });
  const intent: IntentResult = overrides.intent ?? {
    intent: 'quote_request',
    confidence: 0.8,
    signals: [],
  };
  return {
    trigger: 'inbound',
    message,
    thread: thread(message),
    intent,
    facts: {},
    state: 'NEW',
    infoRequests: 0,
    followUpsSent: 0,
    hasPendingDraft: false,
    draftTimestamps: [],
    processedMessageIds: [],
    evidence: [],
    productCovered: true,
    lastInboundAt: undefined,
    now: NOW,
    policy: DEFAULT_POLICY,
    ...overrides,
  };
}

/* =================================================================== */

describe('no responde siempre', () => {
  for (const noise of ['auto_reply', 'out_of_office', 'marketing', 'internal'] as const) {
    it(`no prepara respuesta para intencion "${noise}"`, () => {
      const d = decide(input({ intent: { intent: noise, confidence: 0.9, signals: [] } }));
      assert.equal(d.reply, false);
      assert.equal(d.escalate, false);
      assert.equal(d.action, 'ARCHIVE');
    });
  }

  it('no responde a un acuse de recibo', () => {
    const d = decide(input({ intent: { intent: 'ack', confidence: 0.8, signals: [] } }));
    assert.equal(d.action, 'NO_ACTION');
    assert.equal(d.reply, false);
  });

  it('no responde a un mensaje de proveedor', () => {
    const d = decide(input({ intent: { intent: 'provider_message', confidence: 0.8, signals: [] } }));
    assert.equal(d.action, 'NO_ACTION');
    assert.equal(d.reply, false);
  });

  it('no se responde a si misma', () => {
    const message = { ...inbound({ text: 'Os pasamos la propuesta' }), direction: 'outbound' as const };
    const d = decide(input({ message }));
    assert.equal(d.action, 'NO_ACTION');
    assert.equal(d.trace.some((r) => r.code === 'gate1.outbound'), true);
  });

  it('no responde dos veces al mismo mensaje', () => {
    const message = inbound({ id: 'dup1', text: 'Necesito presupuesto para una lona 300x100 cm.' });
    const d = decide(input({ message, processedMessageIds: ['dup1'] }));
    assert.equal(d.action, 'NO_ACTION');
    assert.equal(d.trace.some((r) => r.code === 'gate1.already-processed'), true);
  });

  it('no acumula un segundo borrador si ya hay uno sin revisar', () => {
    const d = decide(input({ facts: COMPLETE_FACTS, hasPendingDraft: true }));
    assert.equal(d.reply, false);
    assert.equal(d.trace.some((r) => r.code === 'gate3.open-draft'), true);
  });

  it('respeta el limite diario de borradores por hilo', () => {
    const d = decide(
      input({
        facts: COMPLETE_FACTS,
        draftTimestamps: ['2026-08-11T09:00:00.000Z'],
      }),
    );
    assert.equal(d.reply, false);
    assert.ok(['gate3.daily-limit', 'gate3.cooldown'].includes(d.trace.at(-1)!.code));
  });

  it('respeta el enfriamiento entre borradores', () => {
    const policy = withPolicy({ reply: { maxDraftsPerThreadPerDay: 5, minHoursBetweenDrafts: 4 } });
    const d = decide(
      input({ facts: COMPLETE_FACTS, policy, draftTimestamps: ['2026-08-11T17:00:00.000Z'] }),
    );
    assert.equal(d.reply, false);
    assert.equal(d.trace.some((r) => r.code === 'gate3.cooldown'), true);
  });
});

/* =================================================================== */

describe('no escala siempre', () => {
  it('faltar datos criticos NO es motivo de escalado: se pregunta', () => {
    // Este es el fallo principal que corrige el motor.
    const d = decide(input({ facts: { product: field('lona') } }));
    assert.equal(d.action, 'ASK_INFO');
    assert.equal(d.escalate, false);
    assert.equal(d.reply, true);
    assert.deepEqual(d.missingFields, ['dimensions', 'quantity', 'deliveryAddress']);
  });

  it('una medida ambigua tampoco escala: se pide confirmacion', () => {
    const facts: CaseFacts = {
      ...COMPLETE_FACTS,
      dimensions: field({ widthCm: 120, heightCm: 80 }, 0.5, 'inferred'),
    };
    const d = decide(input({ facts }));
    assert.equal(d.action, 'ASK_INFO');
    assert.equal(d.escalate, false);
  });

  it('con todos los datos prepara presupuesto sin escalar', () => {
    const d = decide(input({ facts: COMPLETE_FACTS, evidence: EVIDENCE }));
    assert.equal(d.action, 'PREPARE_QUOTE');
    assert.equal(d.escalate, false);
    assert.equal(d.reply, true);
  });

  it('un presupuesto normal no escala aunque el cliente sea nuevo', () => {
    const d = decide(input({ facts: COMPLETE_FACTS, evidence: EVIDENCE, estimatedAmount: 164 }));
    assert.equal(d.escalate, false);
  });
});

/* =================================================================== */

describe('escala solo por motivos reales', () => {
  it('escala por importe sobre el umbral', () => {
    const d = decide(input({ facts: COMPLETE_FACTS, evidence: EVIDENCE, estimatedAmount: 4911 }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.amount'), true);
  });

  it('escala una reclamacion', () => {
    const d = decide(
      input({
        facts: COMPLETE_FACTS,
        intent: { intent: 'complaint', confidence: 0.9, signals: [] },
      }),
    );
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.complaint'), true);
  });

  it('escala ante lenguaje legal', () => {
    const message = inbound({ text: 'Os enviaremos un burofax si no respondeis. Nuestro abogado lo revisa.' });
    const d = decide(input({ message, thread: thread(message), facts: COMPLETE_FACTS }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.legal'), true);
  });

  it('escala si ningun proveedor cubre el producto', () => {
    const d = decide(input({ facts: COMPLETE_FACTS, productCovered: false }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.no-provider'), true);
  });

  it('escala cuando ya se ha preguntado el maximo de veces sin obtener los datos', () => {
    const d = decide(input({ facts: { product: field('lona') }, infoRequests: 2 }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.info-exhausted'), true);
  });

  it('escala un mensaje que no se entiende, sin responder a ciegas', () => {
    const d = decide(input({ intent: { intent: 'unknown', confidence: 0.2, signals: [] } }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.reply, false);
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.unclear-intent'), true);
  });

  it('escala un hilo que se ha alargado sin cerrarse', () => {
    const messages = Array.from({ length: 9 }, (_, i) =>
      inbound({ id: `x${i}`, text: 'Necesito presupuesto para una lona.' }),
    );
    const d = decide(input({ facts: COMPLETE_FACTS, thread: thread(...messages) }));
    assert.equal(d.action, 'ESCALATE');
    assert.equal(d.escalationReasons.some((r) => r.code === 'escalate.long-thread'), true);
  });
});

/* =================================================================== */

describe('seguimiento programado', () => {
  const base = {
    trigger: 'scheduled' as const,
    message: undefined,
    facts: COMPLETE_FACTS,
    state: 'QUOTED' as const,
    lastInboundAt: '2026-08-05T10:00:00.000Z',
  };

  it('programa un recordatorio tras el silencio', () => {
    const d = decide(input(base));
    assert.equal(d.action, 'SEND_FOLLOW_UP');
    assert.equal(d.reply, true);
    assert.equal(d.escalate, false);
  });

  it('no recuerda antes de que se cumpla el silencio', () => {
    const d = decide(input({ ...base, lastInboundAt: '2026-08-11T17:00:00.000Z' }));
    assert.equal(d.action, 'NO_ACTION');
  });

  it('cierra como perdido tras agotar los intentos, sin escalar', () => {
    const d = decide(input({ ...base, followUpsSent: 3 }));
    assert.equal(d.action, 'CLOSE_LOST');
    assert.equal(d.nextState, 'LOST');
    assert.equal(d.escalate, false);
  });

  it('no persigue un caso ya cerrado', () => {
    const d = decide(input({ ...base, state: 'WON' }));
    assert.equal(d.action, 'NO_ACTION');
  });
});

/* =================================================================== */

describe('missingCriticalFields', () => {
  it('cuenta como ausente un campo por debajo de la confianza minima', () => {
    const facts: CaseFacts = { ...COMPLETE_FACTS, quantity: field(1, 0.45, 'inferred') };
    assert.deepEqual(missingCriticalFields(facts, 0.6), ['quantity']);
  });

  it('acepta un campo confirmado por una persona aunque tenga confianza baja', () => {
    const facts: CaseFacts = { ...COMPLETE_FACTS, quantity: field(1, 0.1, 'human') };
    assert.deepEqual(missingCriticalFields(facts, 0.6), []);
  });
});
