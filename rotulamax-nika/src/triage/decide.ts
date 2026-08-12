/**
 * Motor de decision de Nika.
 *
 * Este modulo responde a dos preguntas independientes:
 *
 *   1. ¿Hay que preparar una respuesta para este cliente?   -> `reply`
 *   2. ¿Tiene que decidir una persona antes de nada?        -> `escalate`
 *
 * Son independientes a proposito. Confundirlas es lo que produce los dos
 * fallos clasicos:
 *
 *   - "responde siempre": no existe una puerta de salida. Si no hay una lista
 *     cerrada de intenciones respondibles y un antirrebote por hilo, todo
 *     correo entrante acaba generando un correo saliente, incluidos los
 *     acuses, las respuestas automaticas y la publicidad.
 *
 *   - "escala siempre": se usa el escalado como comodin para cualquier
 *     incertidumbre. Faltar una medida NO es un caso para una persona: es un
 *     caso para preguntar al cliente. El escalado se reserva para lo que una
 *     persona tiene que decidir de verdad (importe alto, riesgo legal, queja,
 *     producto sin proveedor, o preguntas agotadas sin respuesta util).
 *
 * Ambas puertas son *cerradas por defecto*: se abren solo con un motivo
 * explicito, y ese motivo queda en `trace`.
 */

import type {
  CaseFacts,
  CaseState,
  CriticalField,
  Decision,
  EmailMessage,
  EmailThread,
  Field,
  Intent,
  IntentResult,
  Iso,
  ProviderEvidence,
  Reason,
} from '../types.ts';
import { CRITICAL_FIELDS } from '../types.ts';
import type { Policy } from '../config/policy.ts';
import { countMatches } from '../email/text.ts';

export interface DecisionInput {
  /** Que dispara la evaluacion. */
  trigger: 'inbound' | 'scheduled';
  /** Mensaje entrante que se evalua. Ausente si `trigger === 'scheduled'`. */
  message?: EmailMessage;
  thread: EmailThread;
  intent: IntentResult;
  facts: CaseFacts;
  state: CaseState;
  /** Veces que ya hemos pedido informacion en este caso. */
  infoRequests: number;
  followUpsSent: number;
  /** Hay un borrador sin revisar en el hilo. */
  hasPendingDraft: boolean;
  /** Fechas de borradores previos del hilo. */
  draftTimestamps: Iso[];
  /** Ids de mensajes ya procesados. */
  processedMessageIds: string[];
  /** Evidencia de coste disponible ahora mismo. */
  evidence: ProviderEvidence[];
  /** false si ningun proveedor del registro cubre el producto detectado. */
  productCovered: boolean;
  /** Importe estimado sin IVA, si ya se ha podido calcular. */
  estimatedAmount?: number;
  lastInboundAt?: Iso;
  now: Date;
  policy: Policy;
}

export function decide(input: DecisionInput): Decision {
  const trace: Reason[] = [];
  const escalationReasons: Reason[] = [];
  const missingFields = missingCriticalFields(input.facts, input.policy.escalation.minFieldConfidence);

  const stop = (
    action: Decision['action'],
    nextState: CaseState,
    reason: Reason,
    confidence: number,
  ): Decision => {
    trace.push(reason);
    return {
      action,
      reply: false,
      escalate: false,
      escalationReasons: [],
      trace,
      nextState,
      missingFields,
      confidence,
    };
  };

  /* =================================================================
   * PUERTA 1 — ¿es esto siquiera una conversacion con un cliente?
   * ================================================================= */

  if (input.trigger === 'inbound') {
    const message = input.message;
    if (!message) {
      return stop('NO_ACTION', input.state, {
        code: 'input.no-message',
        message: 'Disparador entrante sin mensaje; no se hace nada.',
      }, 1);
    }

    if (message.direction === 'outbound') {
      return stop('NO_ACTION', input.state, {
        code: 'gate1.outbound',
        message: 'El mensaje es nuestro. Nika no se responde a si misma.',
      }, 1);
    }

    if (input.processedMessageIds.includes(message.id)) {
      return stop('NO_ACTION', input.state, {
        code: 'gate1.already-processed',
        message: `El mensaje ${message.id} ya se proceso. No se duplica la respuesta.`,
      }, 1);
    }

    const noise: Intent[] = ['auto_reply', 'out_of_office', 'marketing', 'internal'];
    if (noise.includes(input.intent.intent)) {
      return stop('ARCHIVE', input.state, {
        code: 'gate1.noise',
        message: `Intencion "${input.intent.intent}": es trafico automatico o interno. No se responde.`,
      }, input.intent.confidence);
    }

    if (input.intent.intent === 'provider_message') {
      return stop('NO_ACTION', input.state, {
        code: 'gate1.provider',
        message: 'Mensaje de proveedor. Se registra como evidencia, no se responde al cliente.',
      }, input.intent.confidence);
    }

    if (input.intent.intent === 'ack') {
      return stop('NO_ACTION', input.state, {
        code: 'gate1.ack',
        message: 'Acuse de recibo del cliente. No pide nada; contestar solo anade ruido.',
      }, input.intent.confidence);
    }

    /* =================================================================
     * PUERTA 2 — ¿entendemos lo suficiente para actuar?
     * ================================================================= */

    if (
      input.intent.intent === 'unknown' ||
      input.intent.confidence < input.policy.reply.minIntentConfidence
    ) {
      trace.push({
        code: 'gate2.low-confidence',
        message: `Confianza de clasificacion ${input.intent.confidence} < ${input.policy.reply.minIntentConfidence}.`,
      });
      escalationReasons.push({
        code: 'escalate.unclear-intent',
        message: 'No se entiende que pide el mensaje. Lo clasifica una persona; no se responde a ciegas.',
      });
      return {
        action: 'ESCALATE',
        reply: false,
        escalate: true,
        escalationReasons,
        trace,
        nextState: input.state === 'NEW' ? 'NEW' : input.state,
        missingFields,
        confidence: input.intent.confidence,
      };
    }

    if (!input.policy.reply.repliableIntents.includes(input.intent.intent)) {
      return stop('NO_ACTION', input.state, {
        code: 'gate2.not-repliable',
        message: `La intencion "${input.intent.intent}" no esta en la lista de intenciones respondibles.`,
      }, input.intent.confidence);
    }

    /* =================================================================
     * PUERTA 3 — antirrebote. Aunque haya que responder, ¿toca ahora?
     * ================================================================= */

    const throttle = checkThrottle(input);
    if (throttle) return stop('NO_ACTION', input.state, throttle, 1);
  }

  /* =================================================================
   * PUERTA 4 — escalado. Solo motivos reales, nunca por incertidumbre
   * sobre datos que se le pueden preguntar al cliente.
   * ================================================================= */

  const bodyText = input.message
    ? `${input.message.subject}\n${input.message.text}`
    : input.thread.messages.map((m) => m.text).join('\n');
  const esc = input.policy.escalation;

  const legal = countMatches(bodyText, esc.legalKeywords);
  if (legal.length > 0) {
    escalationReasons.push({
      code: 'escalate.legal',
      message: `Terminos de riesgo legal: ${legal.join(', ')}.`,
    });
  }

  if (input.intent.intent === 'complaint') {
    escalationReasons.push({
      code: 'escalate.complaint',
      message: 'Reclamacion o incidencia. La respuesta la decide una persona.',
    });
  } else {
    const complaint = countMatches(bodyText, esc.complaintKeywords);
    if (complaint.length >= 2) {
      escalationReasons.push({
        code: 'escalate.complaint-terms',
        message: `Lenguaje de incidencia: ${complaint.join(', ')}.`,
      });
    }
  }

  const custom = countMatches(bodyText, esc.customProductKeywords);
  if (custom.length > 0) {
    escalationReasons.push({
      code: 'escalate.custom-product',
      message: `Producto o alcance fuera de catalogo: ${custom.join(', ')}.`,
    });
  }

  if (input.estimatedAmount !== undefined && input.estimatedAmount > esc.amountThreshold) {
    escalationReasons.push({
      code: 'escalate.amount',
      message: `Importe estimado ${input.estimatedAmount} EUR supera el umbral ${esc.amountThreshold} EUR.`,
    });
  }

  if (input.facts.product && !input.productCovered) {
    escalationReasons.push({
      code: 'escalate.no-provider',
      message: `Ningun proveedor del registro cubre "${input.facts.product.value}". Hace falta decidir como se fabrica.`,
    });
  }

  const inboundCount = input.thread.messages.filter((m) => m.direction === 'inbound').length;
  if (inboundCount > esc.maxInboundMessagesBeforeEscalation) {
    escalationReasons.push({
      code: 'escalate.long-thread',
      message: `${inboundCount} mensajes entrantes sin cerrar el caso. El flujo automatico no esta avanzando.`,
    });
  }

  // Faltan datos Y ya hemos preguntado el maximo de veces sin obtenerlos.
  // Este es el unico caso en el que la falta de datos escala.
  if (missingFields.length > 0 && input.infoRequests >= esc.maxInfoRequestsBeforeEscalation) {
    escalationReasons.push({
      code: 'escalate.info-exhausted',
      message: `Se ha pedido informacion ${input.infoRequests} vez/veces y siguen faltando: ${missingFields.join(', ')}.`,
    });
  }

  if (escalationReasons.length > 0) {
    trace.push({
      code: 'gate4.escalated',
      message: `${escalationReasons.length} motivo(s) de escalado.`,
    });
    return {
      action: 'ESCALATE',
      reply: false,
      escalate: true,
      escalationReasons,
      trace,
      nextState: 'ON_HOLD',
      missingFields,
      confidence: input.intent.confidence,
    };
  }

  trace.push({
    code: 'gate4.no-escalation',
    message: 'Ningun motivo de escalado. Nika puede preparar un borrador para revision.',
  });

  /* =================================================================
   * PUERTA 5 — seguimiento programado (sin mensaje entrante nuevo)
   * ================================================================= */

  if (input.trigger === 'scheduled') {
    if (!isFollowUpState(input.state)) {
      return stop('NO_ACTION', input.state, {
        code: 'gate5.state-not-followable',
        message: `El estado ${input.state} no admite seguimiento automatico.`,
      }, 1);
    }
    if (input.hasPendingDraft) {
      return stop('NO_ACTION', input.state, {
        code: 'gate5.pending-draft',
        message: 'Ya hay un borrador esperando revision. No se acumula otro.',
      }, 1);
    }
    if (!isSilent(input)) {
      return stop('NO_ACTION', input.state, {
        code: 'gate5.not-silent',
        message: `Aun no se cumple el silencio de ${input.policy.followUp.silenceHours} h.`,
      }, 1);
    }
    if (input.followUpsSent >= input.policy.followUp.maxAttempts) {
      trace.push({
        code: 'gate5.max-attempts',
        message: `${input.followUpsSent} recordatorios sin respuesta. Se cierra como perdido por silencio.`,
      });
      return {
        action: 'CLOSE_LOST',
        reply: false,
        escalate: false,
        escalationReasons: [],
        trace,
        nextState: 'LOST',
        missingFields,
        confidence: 0.9,
      };
    }
    trace.push({
      code: 'gate5.follow-up',
      message: `Recordatorio ${input.followUpsSent + 1} de ${input.policy.followUp.maxAttempts}.`,
    });
    return {
      action: 'SEND_FOLLOW_UP',
      reply: true,
      escalate: false,
      escalationReasons: [],
      trace,
      nextState: input.state,
      missingFields,
      confidence: 0.85,
    };
  }

  /* =================================================================
   * PUERTA 6 — faltan datos criticos: se pregunta, no se escala
   * ================================================================= */

  if (missingFields.length > 0) {
    trace.push({
      code: 'gate6.ask-info',
      message: `Faltan datos criticos (${missingFields.join(', ')}). Se prepara peticion de datos al cliente.`,
    });
    return {
      action: 'ASK_INFO',
      reply: true,
      escalate: false,
      escalationReasons: [],
      trace,
      nextState: 'NEEDS_INFO',
      missingFields,
      // La confianza aqui es la de "hay que preguntar", no la de los datos que
      // faltan: si faltan, su confianza es cero por definicion.
      confidence: input.intent.confidence,
    };
  }

  /* =================================================================
   * PUERTA 7 — hay datos: se prepara presupuesto para revision humana
   * ================================================================= */

  const fresh = freshEvidence(input);
  if (fresh.length < input.policy.provider.minEvidenceCount) {
    trace.push({
      code: 'gate7.need-evidence',
      message: `Faltan evidencias de coste (${fresh.length}/${input.policy.provider.minEvidenceCount}). Se recopilan antes de calcular.`,
    });
  }

  trace.push({
    code: 'gate7.prepare-quote',
    message: 'Datos completos. Se prepara propuesta. El envio lo hace una persona.',
  });
  return {
    action: 'PREPARE_QUOTE',
    reply: true,
    escalate: false,
    escalationReasons: [],
    trace,
    nextState: 'QUOTING',
    missingFields,
    confidence: combinedConfidence(input),
  };
}

/* ------------------------------------------------------------- ayudantes */

export function missingCriticalFields(facts: CaseFacts, minConfidence: number): CriticalField[] {
  const missing: CriticalField[] = [];
  for (const key of CRITICAL_FIELDS) {
    const value = facts[key] as Field<unknown> | undefined;
    if (!value) {
      missing.push(key);
      continue;
    }
    if (value.source !== 'human' && value.confidence < minConfidence) missing.push(key);
  }
  return missing;
}

function checkThrottle(input: DecisionInput): Reason | null {
  const { policy, now } = input;

  if (policy.reply.oneOpenDraftPerThread && input.hasPendingDraft) {
    return {
      code: 'gate3.open-draft',
      message: 'Ya hay un borrador de este hilo esperando revision. Se actualiza cuando una persona lo cierre.',
    };
  }

  const stamps = input.draftTimestamps.map((t) => new Date(t).getTime()).filter(Number.isFinite);
  const dayAgo = now.getTime() - 24 * 3600 * 1000;
  const inLastDay = stamps.filter((t) => t >= dayAgo).length;
  if (inLastDay >= policy.reply.maxDraftsPerThreadPerDay) {
    return {
      code: 'gate3.daily-limit',
      message: `Limite de ${policy.reply.maxDraftsPerThreadPerDay} borrador(es) por hilo y dia alcanzado.`,
    };
  }

  const last = stamps.length ? Math.max(...stamps) : null;
  if (last !== null) {
    const hours = (now.getTime() - last) / 3600000;
    if (hours < policy.reply.minHoursBetweenDrafts) {
      return {
        code: 'gate3.cooldown',
        message: `Han pasado ${hours.toFixed(1)} h desde el ultimo borrador; el minimo es ${policy.reply.minHoursBetweenDrafts} h.`,
      };
    }
  }

  return null;
}

function isFollowUpState(state: CaseState): boolean {
  return state === 'NEEDS_INFO' || state === 'QUOTED' || state === 'NEGOTIATING';
}

function isSilent(input: DecisionInput): boolean {
  if (!input.lastInboundAt) return true;
  const hours = (input.now.getTime() - new Date(input.lastInboundAt).getTime()) / 3600000;
  return hours >= input.policy.followUp.silenceHours;
}

function freshEvidence(input: DecisionInput): ProviderEvidence[] {
  const maxAgeMs = input.policy.provider.evidenceMaxAgeHours * 3600000;
  return input.evidence.filter((e) => {
    const age = input.now.getTime() - new Date(e.capturedAt).getTime();
    return age >= 0 && age <= maxAgeMs;
  });
}

function combinedConfidence(input: DecisionInput): number {
  const fieldConfidences = CRITICAL_FIELDS.map((key) => {
    const value = input.facts[key] as Field<unknown> | undefined;
    return value ? value.confidence : 0;
  });
  const min = fieldConfidences.length ? Math.min(...fieldConfidences) : 0;
  return Math.round(Math.min(input.intent.confidence, min) * 100) / 100;
}
