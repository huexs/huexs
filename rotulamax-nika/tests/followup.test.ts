import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import { addDays, isDue, nextBusinessDay, nextFollowUpAt } from '../src/followup/schedule.ts';
import { DEFAULT_POLICY, withPolicy } from '../src/config/policy.ts';

// 2026-08-11 es martes.
const TUESDAY = new Date('2026-08-11T10:00:00.000Z');

describe('cadencia de seguimiento', () => {
  it('programa el primer recordatorio segun el estado', () => {
    const at = nextFollowUpAt('QUOTED', 0, TUESDAY, DEFAULT_POLICY);
    assert.ok(at);
    assert.equal(new Date(at).toISOString().slice(0, 10), '2026-08-14');
  });

  it('espacia mas el segundo recordatorio que el primero', () => {
    const first = new Date(nextFollowUpAt('QUOTED', 0, TUESDAY, DEFAULT_POLICY)!).getTime();
    const second = new Date(nextFollowUpAt('QUOTED', 1, TUESDAY, DEFAULT_POLICY)!).getTime();
    assert.ok(second > first);
  });

  it('deja de programar al agotar los intentos', () => {
    assert.equal(nextFollowUpAt('QUOTED', 3, TUESDAY, DEFAULT_POLICY), null);
  });

  it('no programa seguimiento en estados cerrados', () => {
    assert.equal(nextFollowUpAt('WON', 0, TUESDAY, DEFAULT_POLICY), null);
    assert.equal(nextFollowUpAt('LOST', 0, TUESDAY, DEFAULT_POLICY), null);
    assert.equal(nextFollowUpAt('NOT_A_LEAD', 0, TUESDAY, DEFAULT_POLICY), null);
  });

  it('mueve el fin de semana al lunes', () => {
    const saturday = new Date('2026-08-15T10:00:00.000Z');
    assert.equal(nextBusinessDay(saturday).toISOString().slice(0, 10), '2026-08-17');
    const sunday = new Date('2026-08-16T10:00:00.000Z');
    assert.equal(nextBusinessDay(sunday).toISOString().slice(0, 10), '2026-08-17');
  });

  it('puede desactivarse el salto de fin de semana', () => {
    const policy = withPolicy({ followUp: { skipWeekends: false } });
    // Miercoles + 3 dias = sabado.
    const at = nextFollowUpAt('QUOTED', 0, new Date('2026-08-12T10:00:00.000Z'), policy);
    assert.equal(new Date(at!).toISOString().slice(0, 10), '2026-08-15');
  });
});

describe('vencimiento', () => {
  it('vence cuando la fecha ya paso', () => {
    assert.equal(isDue('2026-08-10T10:00:00.000Z', TUESDAY), true);
  });

  it('no vence si aun falta', () => {
    assert.equal(isDue('2026-08-20T10:00:00.000Z', TUESDAY), false);
  });

  it('sin fecha no vence nada', () => {
    assert.equal(isDue(undefined, TUESDAY), false);
  });

  it('addDays no muta la fecha original', () => {
    const original = TUESDAY.toISOString();
    addDays(TUESDAY, 5);
    assert.equal(TUESDAY.toISOString(), original);
  });
});
