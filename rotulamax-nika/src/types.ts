/**
 * Modelo canonico de Nika.
 *
 * Regla de oro: nada de este modulo produce efectos externos. Aqui solo se
 * describen los datos. Las decisiones viven en `src/triage/decide.ts` y los
 * efectos (crear borrador, guardar caso) en la capa de aplicacion.
 */

export type Iso = string;

/* ------------------------------------------------------------------ correo */

export interface EmailAddress {
  name?: string;
  address: string;
}

export interface Attachment {
  filename: string;
  mimeType: string;
  size: number;
}

export type Direction = 'inbound' | 'outbound';

export interface EmailMessage {
  id: string;
  threadId: string;
  date: Iso;
  direction: Direction;
  from: EmailAddress;
  to: EmailAddress[];
  cc?: EmailAddress[];
  subject: string;
  /** Cuerpo en texto plano, ya sin cita del mensaje anterior si es posible. */
  text: string;
  /** Cabeceras relevantes en minusculas: `auto-submitted`, `precedence`, ... */
  headers?: Record<string, string>;
  attachments?: Attachment[];
}

export interface EmailThread {
  id: string;
  /** Ordenados de mas antiguo a mas reciente. */
  messages: EmailMessage[];
}

/* --------------------------------------------------------------- intencion */

export const INTENTS = [
  'quote_request', // pide precio / presupuesto
  'quote_followup', // pregunta por un presupuesto ya enviado
  'info_request', // duda tecnica, plazos, materiales, sin pedir precio aun
  'order_intent', // acepta, quiere pedir, confirma
  'complaint', // queja, reclamacion, incidencia, devolucion
  'ack', // "gracias", "recibido", "ok" — no pide nada
  'out_of_office', // respuesta automatica de ausencia
  'auto_reply', // acuse automatico, bounce, no-reply
  'marketing', // newsletter, publicidad entrante, prospeccion a nosotros
  'provider_message', // proveedor respondiendo o difundiendo
  'internal', // correo interno del equipo
  'unknown',
] as const;

export type Intent = (typeof INTENTS)[number];

export interface IntentResult {
  intent: Intent;
  /** 0..1 */
  confidence: number;
  signals: Signal[];
}

export interface Signal {
  code: string;
  detail: string;
  /** Positivo empuja hacia la intencion, negativo en contra. */
  weight: number;
}

/* --------------------------------------------------------------- extracion */

export type FieldSource = 'explicit' | 'inferred' | 'attachment' | 'human';

export interface Field<T> {
  value: T;
  /** 0..1 */
  confidence: number;
  source: FieldSource;
  /** Texto literal del que sale el valor. Rastro de auditoria. */
  evidence: string;
  /** Id del mensaje del que se extrajo. */
  messageId: string;
}

export interface Dimensions {
  widthCm: number;
  heightCm: number;
}

/** Datos que Nika necesita para poder presupuestar. */
export interface CaseFacts {
  dimensions?: Field<Dimensions>;
  quantity?: Field<number>;
  product?: Field<string>;
  material?: Field<string>;
  deliveryAddress?: Field<string>;
  deadline?: Field<string>;
  installation?: Field<boolean>;
  artworkReady?: Field<boolean>;
  budgetHint?: Field<number>;
}

export const CRITICAL_FIELDS = [
  'product',
  'dimensions',
  'quantity',
  'deliveryAddress',
] as const;

export type CriticalField = (typeof CRITICAL_FIELDS)[number];

/* ------------------------------------------------------------------- caso */

export const CASE_STATES = [
  'NEW',
  'NEEDS_INFO',
  'QUOTING',
  'QUOTED',
  'NEGOTIATING',
  'WON',
  'LOST',
  'ON_HOLD',
  'NOT_A_LEAD',
] as const;

export type CaseState = (typeof CASE_STATES)[number];

export const LOST_REASONS = [
  'no_response',
  'price',
  'out_of_scope',
  'competitor',
  'client_cancelled',
  'not_a_lead',
] as const;

export type LostReason = (typeof LOST_REASONS)[number];

export interface CaseEvent {
  at: Iso;
  code: string;
  detail: string;
  /** Quien lo hizo: `nika` o el correo de la persona. */
  actor: string;
}

export interface QuoteCase {
  id: string;
  threadId: string;
  createdAt: Iso;
  updatedAt: Iso;
  state: CaseState;
  lostReason?: LostReason;
  contact: EmailAddress;
  subject: string;
  facts: CaseFacts;
  /** Cuantas veces hemos pedido informacion sin obtener respuesta util. */
  infoRequests: number;
  /** Cuantos recordatorios de seguimiento llevamos enviados en el estado actual. */
  followUpsSent: number;
  /** Fecha del ultimo mensaje entrante del cliente. */
  lastInboundAt?: Iso;
  /** Fecha del ultimo mensaje que le enviamos (aprobado por humano). */
  lastOutboundAt?: Iso;
  /** Fecha del proximo seguimiento programado. */
  nextFollowUpAt?: Iso;
  /** Hay un borrador esperando revision humana. */
  pendingDraftId?: string;
  /** Mensajes ya procesados. Evita responder dos veces al mismo correo. */
  processedMessageIds: string[];
  /** Fechas de creacion de borradores. Alimenta el limite por hilo y dia. */
  draftTimestamps: Iso[];
  providerEvidence: ProviderEvidence[];
  estimate?: Estimate;
  events: CaseEvent[];
}

/* -------------------------------------------------------------- proveedor */

export interface Provider {
  id: string;
  name: string;
  /** Familias de producto que cubre. */
  products: string[];
  /** URL publica de tarifa o configurador. Debe ser http(s) verificable. */
  quoteUrl?: string;
  /** true = requiere formulario/login; Nika no automatiza estos sin revision. */
  requiresForm: boolean;
  currency: 'EUR';
}

export interface ProviderEvidence {
  providerId: string;
  capturedAt: Iso;
  /** Coste sin IVA en euros para la configuracion consultada. */
  unitCost: number;
  quantity: number;
  /** Enlace comprobable manualmente. */
  url: string;
  /** Como se obtuvo: catalogo versionado, tarifa PDF, formulario manual. */
  method: 'catalog' | 'tariff' | 'manual' | 'email';
  note?: string;
}

/* ------------------------------------------------------------------ precio */

export interface EstimateLine {
  concept: string;
  quantity: number;
  unitCost: number;
  unitPrice: number;
}

export interface Estimate {
  lines: EstimateLine[];
  costTotal: number;
  /** Base imponible. */
  priceTotal: number;
  vatRate: number;
  priceWithVat: number;
  marginPct: number;
  currency: 'EUR';
  /** Reglas aplicadas, para auditoria. */
  appliedRules: string[];
  /** true si algun dato usado es inferido y no explicito. */
  hasInferredInputs: boolean;
}

/* ---------------------------------------------------------------- decision */

export const ACTIONS = [
  'IGNORE', // no es un lead ni requiere nada. No se responde.
  'ARCHIVE', // ruido conocido (marketing, no-reply). No se responde.
  'NO_ACTION', // es un lead, pero este mensaje concreto no pide nada.
  'ASK_INFO', // faltan datos criticos: se prepara peticion de datos.
  'PREPARE_QUOTE', // hay datos: se prepara presupuesto para revision.
  'SEND_FOLLOW_UP', // recordatorio programado.
  'CLOSE_LOST', // agotado el seguimiento: se cierra como perdido.
  'ESCALATE', // requiere criterio humano antes de cualquier borrador.
] as const;

export type Action = (typeof ACTIONS)[number];

export interface Reason {
  code: string;
  message: string;
}

export interface Decision {
  action: Action;
  /** true si se debe preparar un borrador de respuesta al cliente. */
  reply: boolean;
  /** true si una persona debe decidir *antes* de que exista un borrador. */
  escalate: boolean;
  escalationReasons: Reason[];
  /** Rastro completo de por que se decidio esto. */
  trace: Reason[];
  nextState: CaseState;
  missingFields: CriticalField[];
  /** 0..1 — confianza global de la decision. */
  confidence: number;
}

/* ---------------------------------------------------------------- borrador */

export interface Draft {
  id: string;
  caseId: string;
  threadId: string;
  createdAt: Iso;
  to: EmailAddress[];
  subject: string;
  body: string;
  kind: 'ask_info' | 'quote' | 'follow_up' | 'acknowledgement';
  /** Nika nunca envia. Esto lo cambia una persona en el panel. */
  status: 'pending_review';
}
