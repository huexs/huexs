/**
 * Programacion de seguimientos.
 *
 * El seguimiento tiene final. Un agente que recuerda indefinidamente acaba
 * quemando la lista de contactos: tras `maxAttempts` el caso se cierra como
 * perdido por silencio, con motivo registrado.
 */

import type { CaseState, Iso } from '../types.ts';
import type { Policy } from '../config/policy.ts';

/**
 * Devuelve la fecha del proximo seguimiento, o `null` si ya no procede.
 *
 * @param attemptsSent recordatorios ya enviados en el estado actual.
 */
export function nextFollowUpAt(
  state: CaseState,
  attemptsSent: number,
  from: Date,
  policy: Policy,
): Iso | null {
  const cadence = policy.followUp.cadenceDays[state];
  if (!cadence || cadence.length === 0) return null;
  if (attemptsSent >= policy.followUp.maxAttempts) return null;

  const days = cadence[Math.min(attemptsSent, cadence.length - 1)];
  if (days === undefined) return null;

  let target = addDays(from, days);
  if (policy.followUp.skipWeekends) target = nextBusinessDay(target);
  return target.toISOString();
}

export function addDays(date: Date, days: number): Date {
  const out = new Date(date.getTime());
  out.setUTCDate(out.getUTCDate() + days);
  return out;
}

/** Sabado y domingo se mueven al lunes siguiente. */
export function nextBusinessDay(date: Date): Date {
  const out = new Date(date.getTime());
  const day = out.getUTCDay();
  if (day === 6) return addDays(out, 2);
  if (day === 0) return addDays(out, 1);
  return out;
}

export function isDue(nextAt: Iso | undefined, now: Date): boolean {
  if (!nextAt) return false;
  const time = new Date(nextAt).getTime();
  return Number.isFinite(time) && time <= now.getTime();
}
