/**
 * Pruebas extremo a extremo del flujo.
 *
 * Comprueban lo que el negocio ve: cuantos correos salen de la bandeja hacia
 * revision, y cuantos casos acaban en la cola de una persona.
 */

import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import type { ProviderEvidence } from '../src/types.ts';
import { CaseStore, newCase } from '../src/case/store.ts';
import { DEFAULT_RUNTIME, processScheduled, processThread } from '../src/pipeline.ts';
import { inbound, outbound, thread, NOW } from './helpers.ts';

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

describe('flujo completo', () => {
  it('un lead incompleto genera una peticion de datos, no un escalado', async () => {
    const store = CaseStore.inMemory();
    const t = thread(
      inbound({
        threadId: 'tA',
        subject: 'Presupuesto rotulo luminoso',
        text: 'Hola, queremos presupuesto para un rotulo luminoso para la fachada.',
      }),
    );
    const result = await processThread(t, store, NOW);

    assert.equal(result.decision.action, 'ASK_INFO');
    assert.equal(result.decision.escalate, false);
    assert.equal(result.quoteCase.state, 'NEEDS_INFO');
    assert.equal(result.draft?.kind, 'ask_info');
    assert.equal(result.draft?.status, 'pending_review');
    assert.equal(result.quoteCase.infoRequests, 1);
  });

  it('un lead completo con evidencia genera un presupuesto para revision', async () => {
    const store = CaseStore.inMemory();
    const seeded = newCase({
      id: 'case_tB',
      threadId: 'tB',
      contact: { address: 'cliente@ejemplo.test' },
      subject: 'Presupuesto lonas',
      now: NOW,
    });
    seeded.providerEvidence = EVIDENCE;
    store.putCase(seeded);

    const t = thread(
      inbound({
        threadId: 'tB',
        subject: 'Presupuesto lonas',
        text: 'Necesito 2 lonas de 300 x 100 cm. Cantidad: 2. Entrega en Calle Industria 45, 08025 Barcelona.',
      }),
    );
    const result = await processThread(t, store, NOW);

    assert.equal(result.decision.action, 'PREPARE_QUOTE');
    assert.equal(result.draft?.kind, 'quote');
    assert.equal(result.quoteCase.estimate?.priceTotal, 164);
    // Nunca se marca como enviado: eso solo lo hace una persona.
    assert.equal(result.draft?.status, 'pending_review');
  });

  it('nunca produce un presupuesto sin evidencia de coste', async () => {
    const store = CaseStore.inMemory();
    const t = thread(
      inbound({
        threadId: 'tC',
        subject: 'Presupuesto lonas',
        text: 'Necesito 2 lonas de 300 x 100 cm. Cantidad: 2. Entrega en Calle Industria 45, 08025 Barcelona.',
      }),
    );
    const result = await processThread(t, store, NOW);

    assert.equal(result.decision.action, 'PREPARE_QUOTE');
    assert.equal(result.draft, null);
    assert.equal(result.quoteCase.estimate, undefined);
    assert.ok(result.quoteCase.events.some((e) => e.code === 'quote.blocked'));
  });

  it('una rafaga de tres correos produce un solo borrador', async () => {
    const store = CaseStore.inMemory();
    const t = thread(
      inbound({ threadId: 'tD', id: 'a', subject: 'Presupuesto roll up', text: 'Hola, precio de un roll up.' }),
      inbound({ threadId: 'tD', id: 'b', text: 'Cantidad: 4, medidas 85 x 200 cm.' }),
      inbound({ threadId: 'tD', id: 'c', text: 'Envio a Calle Mallorca 220, 08008 Barcelona.' }),
    );

    const first = await processThread(t, store, NOW);
    const second = await processThread(t, store, NOW);

    assert.ok(first.decision.reply);
    assert.equal(second.decision.reply, false);
    assert.equal(store.listDrafts().length <= 1, true);
  });

  it('no responde a la autorespuesta que provoca nuestro propio correo', async () => {
    const store = CaseStore.inMemory();
    const t = thread(
      outbound({ threadId: 'tE', text: 'Te adjuntamos la propuesta.', subject: 'Re: Presupuesto' }),
      inbound({
        threadId: 'tE',
        subject: 'Respuesta automatica',
        text: 'Estare fuera de la oficina hasta el 25 de agosto.',
        headers: { 'auto-submitted': 'auto-replied' },
      }),
    );
    const result = await processThread(t, store, NOW);

    assert.equal(result.decision.action, 'ARCHIVE');
    assert.equal(result.draft, null);
    assert.equal(store.listDrafts().length, 0);
  });

  it('el asunto del expediente sale del primer mensaje, no del ultimo "Re:"', async () => {
    const store = CaseStore.inMemory();
    const t = thread(
      inbound({ threadId: 'tF', subject: 'Presupuesto toldo', text: 'Hola, precio de un toldo.' }),
      inbound({ threadId: 'tF', subject: 'Re: Re: Presupuesto toldo', text: 'Cantidad: 2.' }),
    );
    const result = await processThread(t, store, NOW);
    assert.equal(result.quoteCase.subject, 'Presupuesto toldo');
  });
});

describe('seguimiento con final', () => {
  it('recuerda y termina cerrando como perdido, sin escalar', () => {
    const store = CaseStore.inMemory();
    const quoteCase = newCase({
      id: 'case_tG',
      threadId: 'tG',
      contact: { address: 'cliente@ejemplo.test' },
      subject: 'Presupuesto lona',
      now: new Date('2026-08-01T10:00:00.000Z'),
    });
    quoteCase.state = 'QUOTED';
    quoteCase.lastInboundAt = '2026-08-01T10:00:00.000Z';
    store.putCase(quoteCase);
    const t = thread(inbound({ threadId: 'tG', text: 'Presupuesto lona 300 x 100 cm.' }));

    const kinds: string[] = [];
    for (let i = 0; i < 4; i += 1) {
      const at = new Date(NOW.getTime() + i * 7 * 24 * 3600 * 1000);
      const result = processScheduled(quoteCase, t, store, at, DEFAULT_RUNTIME);
      kinds.push(result.decision.action);
      quoteCase.pendingDraftId = undefined; // simula que una persona lo revisa
    }

    assert.equal(kinds.filter((k) => k === 'SEND_FOLLOW_UP').length, 3);
    assert.equal(kinds.at(-1), 'CLOSE_LOST');
    assert.equal(quoteCase.state, 'LOST');
    assert.equal(quoteCase.lostReason, 'no_response');
  });
});

describe('proporcion de escalados sobre la bandeja de ejemplo', () => {
  it('la mayoria de los correos no escalan ni generan respuesta automatica', async () => {
    const store = CaseStore.inMemory();
    const cases = [
      thread(inbound({ threadId: 'p1', subject: 'Presupuesto lona', text: 'Precio de una lona de 200 x 100 cm, cantidad: 1, entrega en Calle Aragon 100, 08015 Barcelona.' })),
      thread(inbound({ threadId: 'p2', subject: 'Recibido', text: 'Recibido, gracias.' })),
      thread(inbound({ threadId: 'p3', subject: 'Boletin', text: 'Oferta exclusiva webinar', headers: { 'list-unsubscribe': '<https://x.test>' } })),
      thread(inbound({ threadId: 'p4', subject: 'Fuera', text: 'Estare fuera de la oficina.' })),
      thread(inbound({ threadId: 'p5', subject: 'Presupuesto vinilo', text: 'Queremos presupuesto de vinilo para el escaparate.' })),
    ];

    const actions: string[] = [];
    for (const t of cases) {
      const result = await processThread(t, store, NOW);
      actions.push(result.decision.action);
    }

    assert.equal(actions.filter((a) => a === 'ESCALATE').length, 0);
    assert.deepEqual(actions, ['PREPARE_QUOTE', 'NO_ACTION', 'ARCHIVE', 'ARCHIVE', 'ASK_INFO']);
  });
});
