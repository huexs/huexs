/**
 * Clasificacion de intencion de un mensaje entrante.
 *
 * Es deterministica a proposito. Un LLM puede refinar el resultado despues
 * (ver `refineWithModel` en docs/02_ARCHITECTURE.md), pero la decision de
 * responder o no se toma con senales verificables: cabeceras, remitente y
 * lexico. Asi el comportamiento es reproducible en tests y auditable.
 */

import type { EmailMessage, EmailThread, Intent, IntentResult, Signal } from '../types.ts';
import { countMatches, domainOf, hasQuestion, localPartOf, normalize } from '../email/text.ts';

const QUOTE_WORDS = [
  'presupuesto',
  'precio',
  'cuanto cuesta',
  'cuanto vale',
  'coste',
  'tarifa',
  'cotizacion',
  'pressupost',
  'quote',
  'quotation',
  'me pasais precio',
  'precio aproximado',
];

const FOLLOWUP_WORDS = [
  'el presupuesto que',
  'sobre el presupuesto',
  'quedamos en',
  'os escribi',
  'no he recibido',
  'sigue en pie',
  'alguna novedad',
  'habeis podido',
  'recordatorio',
];

const ORDER_WORDS = [
  'adelante',
  'lo confirmo',
  'confirmamos',
  'aceptamos',
  'hacemos el pedido',
  'proceder',
  'podeis empezar',
  'de acuerdo con el presupuesto',
  'mandadme la factura',
  'proforma',
];

const INFO_WORDS = [
  'plazo',
  'material',
  'acabado',
  'instalacion',
  'medidas',
  'que recomendais',
  'es posible',
  'admite',
  'resistente',
  'exterior',
  'catalogo',
];

const COMPLAINT_WORDS = [
  'reclamacion',
  'queja',
  'defectuoso',
  'roto',
  'mal impreso',
  'no ha llegado',
  'retraso',
  'devolucion',
  'reembolso',
  'incidencia',
];

const ACK_WORDS = [
  'gracias',
  'recibido',
  'perfecto',
  'ok',
  'de acuerdo',
  'entendido',
  'genial',
  'moltes gracies',
];

const MARKETING_WORDS = [
  'newsletter',
  'darse de baja',
  'unsubscribe',
  'oferta exclusiva',
  'webinar',
  'posicionamiento web',
  'seo',
  'aumentar tus ventas',
  'campana de marketing',
  'colaboracion comercial',
];

const OOO_WORDS = [
  'fuera de la oficina',
  'out of office',
  'estare ausente',
  'vacaciones hasta',
  'respuesta automatica',
  'automatic reply',
  'estare de baja',
];

export interface ClassifyContext {
  /** Dominios propios de RotulaMax. Un remitente propio es correo interno. */
  ownDomains: string[];
  /** Dominios de proveedores conocidos. */
  providerDomains: string[];
  neverReplyDomains: string[];
  neverReplyLocalParts: string[];
  /** true si el hilo ya contiene un presupuesto enviado por nosotros. */
  quoteAlreadySent: boolean;
  /**
   * true si el ultimo mensaje entrante aporta algun dato critico nuevo
   * (producto, medidas, cantidad o direccion). Lo calcula la capa de
   * orquestacion con `src/triage/extract.ts`.
   *
   * Sirve para no confundir "Y el envio a Calle X, 08008 Barcelona. Gracias."
   * con un acuse de recibo: lleva la palabra "gracias" y es corto, pero es la
   * pieza que faltaba para poder presupuestar.
   */
  lastMessageCarriesData?: boolean;
}

export function classify(message: EmailMessage, ctx: ClassifyContext): IntentResult {
  const signals: Signal[] = [];
  // En una respuesta el asunto es heredado, no escrito por quien envia. Si se
  // puntua, "Re: Presupuesto lona" hace que *todos* los mensajes del hilo
  // parezcan una peticion de presupuesto nueva, incluido un simple "gracias".
  const isReply = /^\s*(re|rv|fwd?|rtr)\s*:/i.test(message.subject);
  const body = normalize(isReply ? message.text : `${message.subject}\n${message.text}`);
  const from = message.from.address.toLowerCase();
  const domain = domainOf(from);
  const local = localPartOf(from);
  const headers = message.headers ?? {};

  /* --- 1. senales de maquina. Tienen prioridad absoluta ----------------- */

  const autoSubmitted = (headers['auto-submitted'] ?? '').toLowerCase();
  if (autoSubmitted && autoSubmitted !== 'no') {
    signals.push({ code: 'header.auto-submitted', detail: autoSubmitted, weight: 1 });
    return { intent: 'auto_reply', confidence: 0.98, signals };
  }
  if ('list-unsubscribe' in headers) {
    signals.push({ code: 'header.list-unsubscribe', detail: 'presente', weight: 1 });
    return { intent: 'marketing', confidence: 0.9, signals };
  }
  const precedence = (headers['precedence'] ?? '').toLowerCase();
  if (['bulk', 'list', 'junk'].includes(precedence)) {
    signals.push({ code: 'header.precedence', detail: precedence, weight: 1 });
    return { intent: 'marketing', confidence: 0.85, signals };
  }
  if (ctx.neverReplyLocalParts.some((p) => local.includes(p))) {
    signals.push({ code: 'sender.no-reply', detail: local, weight: 1 });
    return { intent: 'auto_reply', confidence: 0.95, signals };
  }
  if (ctx.neverReplyDomains.includes(domain)) {
    signals.push({ code: 'sender.blocked-domain', detail: domain, weight: 1 });
    return { intent: 'auto_reply', confidence: 0.9, signals };
  }

  const oooHits = countMatches(body, OOO_WORDS);
  if (oooHits.length > 0 || /^(re:\s*)?(auto|automatic)/i.test(message.subject)) {
    signals.push({ code: 'text.out-of-office', detail: oooHits.join(', '), weight: 1 });
    return { intent: 'out_of_office', confidence: 0.9, signals };
  }

  /* --- 2. origen ------------------------------------------------------- */

  if (ctx.ownDomains.includes(domain)) {
    signals.push({ code: 'sender.own-domain', detail: domain, weight: 1 });
    return { intent: 'internal', confidence: 0.9, signals };
  }
  if (ctx.providerDomains.includes(domain)) {
    signals.push({ code: 'sender.provider-domain', detail: domain, weight: 1 });
    return { intent: 'provider_message', confidence: 0.85, signals };
  }

  /* --- 3. lexico ponderado --------------------------------------------- */

  const scores = new Map<Intent, number>();
  const bump = (intent: Intent, amount: number, code: string, detail: string) => {
    scores.set(intent, (scores.get(intent) ?? 0) + amount);
    signals.push({ code, detail, weight: amount });
  };

  const marketing = countMatches(body, MARKETING_WORDS);
  if (marketing.length) bump('marketing', 0.4 * marketing.length, 'text.marketing', marketing.join(', '));

  const complaint = countMatches(body, COMPLAINT_WORDS);
  if (complaint.length) bump('complaint', 0.5 * complaint.length, 'text.complaint', complaint.join(', '));

  const order = countMatches(body, ORDER_WORDS);
  if (order.length) bump('order_intent', 0.45 * order.length, 'text.order', order.join(', '));

  const quote = countMatches(body, QUOTE_WORDS);
  if (quote.length) bump('quote_request', 0.4 * quote.length, 'text.quote', quote.join(', '));

  const followup = countMatches(body, FOLLOWUP_WORDS);
  if (followup.length) {
    bump('quote_followup', 0.4 * followup.length, 'text.followup', followup.join(', '));
  }

  const info = countMatches(body, INFO_WORDS);
  if (info.length) bump('info_request', 0.25 * info.length, 'text.info', info.join(', '));

  const ack = countMatches(body, ACK_WORDS);
  const shortBody = message.text.trim().length <= 220;
  // "Gracias" al final de una peticion no la convierte en un acuse. Solo es
  // acuse si el mensaje no pide absolutamente nada mas.
  const asksSomething =
    quote.length > 0 || order.length > 0 || info.length > 0 || complaint.length > 0 || followup.length > 0;
  if (ack.length && shortBody && !asksSomething) {
    bump('ack', 0.5 * ack.length, 'text.ack-short', ack.join(', '));
  }

  // Un presupuesto ya enviado convierte una pregunta de precio en seguimiento.
  if (ctx.quoteAlreadySent && (quote.length > 0 || followup.length > 0)) {
    bump('quote_followup', 0.5, 'context.quote-already-sent', 'el hilo ya tiene presupuesto');
  }

  // Un mensaje corto sin palabras de acuse NO es un acuse: es un mensaje que
  // no entendemos. Se deja caer a `unknown` para que lo vea una persona, en
  // lugar de archivarlo en silencio. Archivar por defecto pierde leads reales.
  if (!hasQuestion(message.text) && shortBody && !asksSomething && ack.length === 0) {
    signals.push({
      code: 'text.short-unclear',
      detail: 'mensaje corto, sin pregunta y sin peticion identificable',
      weight: 0,
    });
  }

  if (message.attachments?.length && (quote.length > 0 || info.length > 0)) {
    bump('quote_request', 0.2, 'attachment.present', `${message.attachments.length} adjunto(s)`);
  }

  /* --- 4. resolucion --------------------------------------------------- */

  let best: Intent = 'unknown';
  let bestScore = 0;
  let runnerUp = 0;
  for (const [intent, score] of scores) {
    if (score > bestScore) {
      runnerUp = bestScore;
      bestScore = score;
      best = intent;
    } else if (score > runnerUp) {
      runnerUp = score;
    }
  }

  if (bestScore === 0) {
    signals.push({ code: 'resolve.no-signal', detail: 'ninguna palabra clave', weight: 0 });
    return { intent: 'unknown', confidence: 0.2, signals };
  }

  // La confianza baja cuando dos intenciones puntuan parecido. Un empate no es
  // una certeza: es exactamente el caso que debe revisar una persona.
  const separation = bestScore === 0 ? 0 : (bestScore - runnerUp) / bestScore;
  const confidence = clamp(0.35 + 0.45 * Math.min(bestScore, 1.4) / 1.4 + 0.2 * separation, 0, 0.97);

  signals.push({
    code: 'resolve.best',
    detail: `${best} score=${bestScore.toFixed(2)} runnerUp=${runnerUp.toFixed(2)}`,
    weight: bestScore,
  });

  return { intent: best, confidence: round2(confidence), signals };
}

/**
 * Clasifica el hilo, no solo el ultimo correo.
 *
 * Un cliente que escribe tres mensajes seguidos ("precio de un roll up" /
 * "cantidad 4" / "enviar a esta direccion") deja un ultimo mensaje que, aislado,
 * no dice nada. Clasificarlo solo a el da `unknown`, y `unknown` escala. El
 * resultado es que las ampliaciones de datos de un lead legitimo acababan en la
 * cola de una persona.
 *
 * Las senales de maquina (autorespuesta, publicidad, ausencia, interno) siguen
 * siendo absolutas: nunca se heredan ni se sobrescriben.
 */
export function classifyThread(thread: EmailThread, ctx: ClassifyContext): IntentResult {
  const inbound = thread.messages.filter((m) => m.direction === 'inbound');
  const last = inbound.at(-1);
  if (!last) return { intent: 'unknown', confidence: 0, signals: [] };

  const result = classify(last, ctx);
  const machine: Intent[] = ['auto_reply', 'out_of_office', 'marketing', 'internal', 'provider_message'];
  // Las senales de maquina son absolutas. El acuse solo lo es si el mensaje no
  // trae ningun dato aprovechable.
  const absolute: Intent[] = ctx.lastMessageCarriesData ? machine : [...machine, 'ack'];
  if (absolute.includes(result.intent)) return result;

  // Se busca contexto del hilo cuando el ultimo mensaje, por si solo, no basta:
  // porque no se entiende, o porque parece un acuse pero trae datos nuevos.
  const needsContext =
    result.intent === 'unknown' ||
    result.confidence < 0.55 ||
    (result.intent === 'ack' && ctx.lastMessageCarriesData === true);
  if (!needsContext) return result;

  // Se busca hacia atras la ultima intencion accionable establecida en el hilo.
  for (let i = inbound.length - 2; i >= 0; i -= 1) {
    const previous = classify(inbound[i]!, ctx);
    if (absolute.includes(previous.intent) || previous.intent === 'unknown') continue;
    return {
      intent: previous.intent,
      // Se hereda con menos confianza que el original: es contexto, no lectura.
      confidence: Math.min(0.75, previous.confidence),
      signals: [
        ...result.signals,
        {
          code: 'context.thread-continuation',
          detail: `continuacion de "${previous.intent}" establecido en ${inbound[i]!.id}`,
          weight: 0.5,
        },
      ],
    };
  }

  return result;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function round2(value: number): number {
  return Math.round(value * 100) / 100;
}
