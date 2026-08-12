/**
 * Politica de autonomia de Nika.
 *
 * Este fichero es el unico sitio donde se ajusta "cuando responde" y "cuando
 * escala". Si Nika responde de mas o escala de mas, se toca aqui, no en el
 * motor. Cada valor tiene un comentario que explica que pasa si se sube o baja.
 *
 * Los valores marcados con OWNER_DECISION son comerciales y no los decide un
 * desarrollador ni el agente: los fija la propiedad y quedan registrados en
 * docs/07_OPEN_QUESTIONS.md.
 */

import type { Intent } from '../types.ts';

export interface Policy {
  reply: ReplyPolicy;
  escalation: EscalationPolicy;
  followUp: FollowUpPolicy;
  pricing: PricingPolicy;
  provider: ProviderPolicy;
}

export interface ReplyPolicy {
  /**
   * Intenciones para las que se prepara un borrador. Cualquier intencion que
   * no este en esta lista NO genera respuesta. Esta lista es la razon numero
   * uno por la que un agente "contesta siempre": si no existe, todo contesta.
   */
  repliableIntents: Intent[];
  /**
   * Confianza minima de clasificacion para actuar sin intervencion humana.
   * Por debajo de esto el caso se marca para revision, no se responde a ciegas.
   */
  minIntentConfidence: number;
  /**
   * Maximo de borradores generados por hilo y dia. Corta bucles con
   * autorespondedores y con clientes que escriben cinco correos seguidos.
   */
  maxDraftsPerThreadPerDay: number;
  /**
   * Si ya hay un borrador pendiente de revision en el hilo, no se genera otro.
   * Se actualiza el existente cuando una persona lo descarta.
   */
  oneOpenDraftPerThread: boolean;
  /**
   * Horas minimas entre dos borradores del mismo hilo. Evita responder a una
   * rafaga de tres correos del mismo cliente con tres correos nuestros.
   */
  minHoursBetweenDrafts: number;
  /** Dominios cuyos correos nunca generan respuesta al cliente. */
  neverReplyDomains: string[];
  /** Prefijos de buzon que indican maquina y no persona. */
  neverReplyLocalParts: string[];
}

export interface EscalationPolicy {
  /**
   * Importe estimado (base imponible, EUR) por encima del cual una persona
   * decide antes de que Nika prepare nada. OWNER_DECISION.
   */
  amountThreshold: number;
  /**
   * Numero de veces que se puede pedir informacion al cliente antes de
   * escalar. Faltar datos NO es motivo de escalado: es motivo de preguntar.
   * Solo cuando preguntar ha fallado repetidamente interviene una persona.
   */
  maxInfoRequestsBeforeEscalation: number;
  /**
   * Confianza minima en los campos criticos. Por debajo se pregunta al
   * cliente; no se escala salvo que ya se haya preguntado el maximo de veces.
   */
  minFieldConfidence: number;
  /** Terminos que obligan a criterio humano inmediato. */
  legalKeywords: string[];
  /** Terminos de incidencia grave. */
  complaintKeywords: string[];
  /** Productos fuera de catalogo que siempre requieren una persona. */
  customProductKeywords: string[];
  /**
   * Escalar si el hilo tiene mas de N mensajes entrantes sin cerrarse. Un hilo
   * largo suele significar que el flujo automatico no esta funcionando.
   */
  maxInboundMessagesBeforeEscalation: number;
}

export interface FollowUpPolicy {
  /** Dias de espera antes del primer recordatorio, por estado. */
  cadenceDays: Record<string, number[]>;
  /** Maximo de recordatorios antes de cerrar como perdido. */
  maxAttempts: number;
  /** No programar seguimientos en fin de semana. */
  skipWeekends: boolean;
  /** Horas desde el ultimo mensaje entrante antes de considerar silencio. */
  silenceHours: number;
}

export interface PricingPolicy {
  /** OWNER_DECISION. Margen objetivo sobre coste. */
  targetMarginPct: number;
  /** OWNER_DECISION. Importe minimo de pedido, base imponible EUR. */
  minimumOrder: number;
  /** OWNER_DECISION. IVA aplicable. */
  vatRate: number;
  /** Redondeo del precio de venta al alza, en euros. */
  roundToEuros: number;
}

export interface ProviderPolicy {
  /**
   * Nika no puede preparar un presupuesto sin al menos esta cantidad de
   * evidencias de coste. Sin evidencia no hay precio: se escala.
   */
  minEvidenceCount: number;
  /** Horas tras las que una evidencia de coste se considera caducada. */
  evidenceMaxAgeHours: number;
  /** Solo se aceptan enlaces con estos esquemas. */
  allowedUrlSchemes: string[];
}

export const DEFAULT_POLICY: Policy = {
  reply: {
    repliableIntents: [
      'quote_request',
      'quote_followup',
      'info_request',
      'order_intent',
      'complaint',
    ],
    minIntentConfidence: 0.55,
    maxDraftsPerThreadPerDay: 1,
    oneOpenDraftPerThread: true,
    minHoursBetweenDrafts: 4,
    neverReplyDomains: [
      'mailer-daemon.com',
      'bounces.google.com',
      'sendgrid.net',
      'mailchimp.com',
      'facebookmail.com',
      'linkedin.com',
    ],
    neverReplyLocalParts: [
      'no-reply',
      'noreply',
      'no_reply',
      'donotreply',
      'do-not-reply',
      'mailer-daemon',
      'postmaster',
      'bounce',
      'notifications',
      'notificaciones',
      'automated',
      'newsletter',
    ],
  },

  escalation: {
    // OWNER_DECISION: valor provisional. Ver docs/07_OPEN_QUESTIONS.md Q1.
    amountThreshold: 1500,
    maxInfoRequestsBeforeEscalation: 2,
    minFieldConfidence: 0.6,
    legalKeywords: [
      'abogado',
      'demanda',
      'burofax',
      'juzgado',
      'reclamacion previa',
      'consumo',
      'lopd',
      'rgpd',
      'proteccion de datos',
      'incumplimiento de contrato',
    ],
    complaintKeywords: [
      'devolucion',
      'reembolso',
      'defectuoso',
      'roto',
      'mal impreso',
      'no funciona',
      'reclamo',
      'inaceptable',
      'queja formal',
    ],
    customProductKeywords: [
      'a medida especial',
      'proyecto llave en mano',
      'estructura metalica',
      'obra civil',
      'licencia de obra',
      'homologacion',
    ],
    maxInboundMessagesBeforeEscalation: 8,
  },

  followUp: {
    cadenceDays: {
      NEEDS_INFO: [3, 7],
      QUOTED: [3, 7, 14],
      NEGOTIATING: [2, 5],
    },
    maxAttempts: 3,
    skipWeekends: true,
    silenceHours: 48,
  },

  pricing: {
    // OWNER_DECISION: valores provisionales. Ver docs/07_OPEN_QUESTIONS.md Q2.
    targetMarginPct: 45,
    minimumOrder: 60,
    vatRate: 21,
    roundToEuros: 1,
  },

  provider: {
    minEvidenceCount: 1,
    evidenceMaxAgeHours: 24 * 30,
    allowedUrlSchemes: ['http:', 'https:'],
  },
};

/** Copia profunda con sobrescrituras parciales, para tests y para el panel. */
export function withPolicy(overrides: DeepPartial<Policy>): Policy {
  return merge(DEFAULT_POLICY, overrides) as Policy;
}

export type DeepPartial<T> = {
  [K in keyof T]?: T[K] extends object ? DeepPartial<T[K]> : T[K];
};

function merge(base: unknown, over: unknown): unknown {
  if (Array.isArray(over)) return over.slice();
  if (over === undefined) return structuredCloneSafe(base);
  if (typeof base !== 'object' || base === null) return over;
  if (typeof over !== 'object' || over === null) return over;
  const out: Record<string, unknown> = {};
  const b = base as Record<string, unknown>;
  const o = over as Record<string, unknown>;
  for (const key of new Set([...Object.keys(b), ...Object.keys(o)])) {
    out[key] = key in o ? merge(b[key], o[key]) : structuredCloneSafe(b[key]);
  }
  return out;
}

function structuredCloneSafe<T>(value: T): T {
  if (Array.isArray(value)) return value.slice() as unknown as T;
  if (typeof value === 'object' && value !== null) {
    return merge(value, {}) as T;
  }
  return value;
}
