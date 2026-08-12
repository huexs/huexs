/**
 * Generacion de borradores.
 *
 * Nika NUNCA envia. Estas funciones devuelven un `Draft` con
 * `status: 'pending_review'`. Quien lo envia es una persona desde el panel.
 *
 * Los borradores dicen siempre de donde sale cada dato: lo explicito se cita,
 * lo supuesto se marca como supuesto. Un presupuesto que presenta una
 * suposicion como si fuera un dato del cliente es un error comercial.
 */

import type {
  CriticalField,
  Draft,
  EmailAddress,
  Estimate,
  QuoteCase,
} from '../types.ts';

const FIELD_QUESTIONS: Record<CriticalField, string> = {
  product: '¿Qué producto necesitáis exactamente (lona, vinilo, rótulo luminoso, letras corpóreas...)?',
  dimensions: '¿Qué medidas necesitáis, en centímetros (ancho x alto)?',
  quantity: '¿Cuántas unidades?',
  deliveryAddress: '¿A qué dirección completa (con código postal) hay que entregarlo?',
};

export interface DraftContext {
  now: Date;
  /** Firma que se anexa. La define la propiedad. */
  signature: string;
}

export function draftAskInfo(
  quoteCase: QuoteCase,
  missing: CriticalField[],
  ctx: DraftContext,
): Draft {
  const known = describeKnownFacts(quoteCase);
  const questions = missing.map((f) => `  · ${FIELD_QUESTIONS[f]}`).join('\n');

  const body = [
    greeting(quoteCase.contact),
    '',
    'Gracias por escribirnos. Para prepararos un presupuesto ajustado nos faltan',
    'un par de datos:',
    '',
    questions,
    '',
    ...(known.length
      ? ['De vuestro mensaje hemos anotado:', ...known.map((k) => `  · ${k}`), '']
      : []),
    'En cuanto nos lo confirméis os enviamos la propuesta.',
    '',
    ctx.signature,
  ].join('\n');

  return {
    id: `draft_${quoteCase.id}_${ctx.now.getTime()}`,
    caseId: quoteCase.id,
    threadId: quoteCase.threadId,
    createdAt: ctx.now.toISOString(),
    to: [quoteCase.contact],
    subject: replySubject(quoteCase.subject),
    body,
    kind: 'ask_info',
    status: 'pending_review',
  };
}

export function draftQuote(quoteCase: QuoteCase, estimate: Estimate, ctx: DraftContext): Draft {
  const lines = estimate.lines.map(
    (l) => `  · ${l.concept} — ${l.quantity} ud x ${l.unitPrice.toFixed(2)} €`,
  );

  const assumptions = describeAssumptions(quoteCase);

  const body = [
    greeting(quoteCase.contact),
    '',
    'Os pasamos la propuesta:',
    '',
    ...lines,
    '',
    `  Base imponible: ${estimate.priceTotal.toFixed(2)} €`,
    `  IVA (${estimate.vatRate}%): ${(estimate.priceWithVat - estimate.priceTotal).toFixed(2)} €`,
    `  Total: ${estimate.priceWithVat.toFixed(2)} €`,
    '',
    ...(assumptions.length
      ? [
          'Hemos trabajado con estos supuestos; confirmadnos si alguno no encaja:',
          ...assumptions.map((a) => `  · ${a}`),
          '',
        ]
      : []),
    'Quedamos a la espera de vuestros comentarios.',
    '',
    ctx.signature,
  ].join('\n');

  return {
    id: `draft_${quoteCase.id}_${ctx.now.getTime()}`,
    caseId: quoteCase.id,
    threadId: quoteCase.threadId,
    createdAt: ctx.now.toISOString(),
    to: [quoteCase.contact],
    subject: replySubject(quoteCase.subject),
    body,
    kind: 'quote',
    status: 'pending_review',
  };
}

export function draftFollowUp(quoteCase: QuoteCase, attempt: number, ctx: DraftContext): Draft {
  const isLast = attempt >= 3;
  const middle =
    quoteCase.state === 'NEEDS_INFO'
      ? ['Quedamos pendientes de los datos que os pedimos para poder presupuestar.']
      : ['Queríamos saber si habéis podido revisar la propuesta que os enviamos.'];

  const body = [
    greeting(quoteCase.contact),
    '',
    ...middle,
    '',
    isLast
      ? 'Si ya no os encaja, decídnoslo sin problema y cerramos la consulta por nuestra parte.'
      : 'Si necesitáis que ajustemos algo, decidnos y lo revisamos.',
    '',
    ctx.signature,
  ].join('\n');

  return {
    id: `draft_${quoteCase.id}_${ctx.now.getTime()}`,
    caseId: quoteCase.id,
    threadId: quoteCase.threadId,
    createdAt: ctx.now.toISOString(),
    to: [quoteCase.contact],
    subject: replySubject(quoteCase.subject),
    body,
    kind: 'follow_up',
    status: 'pending_review',
  };
}

/* ------------------------------------------------------------- ayudantes */

function greeting(contact: EmailAddress): string {
  const name = contact.name?.split(' ')[0];
  return name ? `Hola ${name},` : 'Hola,';
}

export function replySubject(subject: string): string {
  return /^re:\s/i.test(subject) ? subject : `Re: ${subject}`;
}

function describeKnownFacts(quoteCase: QuoteCase): string[] {
  const out: string[] = [];
  const f = quoteCase.facts;
  if (f.product?.source === 'explicit') out.push(`Producto: ${f.product.value}`);
  if (f.dimensions?.source === 'explicit') {
    out.push(`Medidas: ${f.dimensions.value.widthCm} x ${f.dimensions.value.heightCm} cm`);
  }
  if (f.quantity?.source === 'explicit') out.push(`Cantidad: ${f.quantity.value}`);
  if (f.deadline?.source === 'explicit') out.push(`Plazo: ${f.deadline.value}`);
  return out;
}

function describeAssumptions(quoteCase: QuoteCase): string[] {
  const out: string[] = [];
  const f = quoteCase.facts;
  if (f.quantity?.source === 'inferred') out.push(`Cantidad: ${f.quantity.value} (supuesto)`);
  if (f.dimensions?.source === 'inferred') {
    out.push(
      `Medidas: ${f.dimensions.value.widthCm} x ${f.dimensions.value.heightCm} cm interpretadas en centímetros`,
    );
  }
  if (f.material?.source === 'inferred') out.push(`Material: ${f.material.value} (supuesto)`);
  if (f.installation === undefined) out.push('Precio sin instalación');
  return out;
}
