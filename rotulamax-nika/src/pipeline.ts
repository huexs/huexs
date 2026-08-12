/**
 * Orquestacion: hilo entrante -> expediente -> decision -> efecto.
 *
 * El unico efecto que produce esta capa es escribir en el almacen local
 * (expediente + borrador pendiente de revision). No envia correo, no cursa
 * pedidos y no toca formularios de proveedores.
 */

import type {
  CaseEvent,
  Decision,
  Draft,
  EmailMessage,
  EmailThread,
  Provider,
  QuoteCase,
} from './types.ts';
import { CRITICAL_FIELDS } from './types.ts';
import { DEFAULT_POLICY, type Policy } from './config/policy.ts';
import { classifyThread, type ClassifyContext } from './triage/classify.ts';
import { extractFacts, extractFromMessage } from './triage/extract.ts';
import { decide, missingCriticalFields, type DecisionInput } from './triage/decide.ts';
import { buildEstimate } from './pricing/estimate.ts';
import { PROVIDERS, isProductCovered } from './providers/registry.ts';
import { draftAskInfo, draftFollowUp, draftQuote } from './email/draft.ts';
import { nextFollowUpAt } from './followup/schedule.ts';
import { CaseStore, newCase } from './case/store.ts';

export interface RuntimeConfig {
  policy: Policy;
  ownDomains: string[];
  providerDomains: string[];
  providers: Provider[];
  signature: string;
}

export const DEFAULT_RUNTIME: RuntimeConfig = {
  policy: DEFAULT_POLICY,
  ownDomains: ['rotulamax.com', 'rotulamax.es'],
  providerDomains: [],
  providers: PROVIDERS,
  signature: 'Un saludo,\nNika · RotulaMax\n(borrador preparado para revisión interna)',
};

export interface ProcessResult {
  quoteCase: QuoteCase;
  decision: Decision;
  draft: Draft | null;
  /** Problemas de precio o de evidencia detectados en el camino. */
  problems: string[];
}

/** Procesa el ultimo mensaje entrante de un hilo. */
export async function processThread(
  thread: EmailThread,
  store: CaseStore,
  now: Date,
  config: RuntimeConfig = DEFAULT_RUNTIME,
): Promise<ProcessResult> {
  const message = lastInbound(thread);
  const quoteCase = ensureCase(thread, store, now, message);
  const problems: string[] = [];

  const ctx: ClassifyContext = {
    ownDomains: config.ownDomains,
    providerDomains: config.providerDomains,
    neverReplyDomains: config.policy.reply.neverReplyDomains,
    neverReplyLocalParts: config.policy.reply.neverReplyLocalParts,
    quoteAlreadySent: quoteCase.state === 'QUOTED' || quoteCase.state === 'NEGOTIATING',
    lastMessageCarriesData: message ? carriesCriticalData(message) : false,
  };

  const intent = classifyThread(thread, ctx);

  quoteCase.facts = extractFacts(thread);

  // Solo se calcula precio cuando estan los datos criticos. Estimar sin ellos
  // genera avisos de "falta evidencia" en hilos que ni siquiera son un lead.
  const missing = missingCriticalFields(quoteCase.facts, config.policy.escalation.minFieldConfidence);
  const estimateResult =
    missing.length === 0
      ? buildEstimate(quoteCase.facts, quoteCase.providerEvidence, config.policy, now)
      : { estimate: null, problems: [] };
  problems.push(...estimateResult.problems);

  const input: DecisionInput = {
    trigger: 'inbound',
    message,
    thread,
    intent,
    facts: quoteCase.facts,
    state: quoteCase.state,
    infoRequests: quoteCase.infoRequests,
    followUpsSent: quoteCase.followUpsSent,
    hasPendingDraft: Boolean(quoteCase.pendingDraftId),
    draftTimestamps: quoteCase.draftTimestamps,
    processedMessageIds: quoteCase.processedMessageIds,
    evidence: quoteCase.providerEvidence,
    productCovered: isProductCovered(quoteCase.facts.product?.value, config.providers),
    estimatedAmount: estimateResult.estimate?.priceTotal,
    lastInboundAt: quoteCase.lastInboundAt,
    now,
    policy: config.policy,
  };

  const decision = decide(input);
  const draft = applyDecision(quoteCase, decision, estimateResult.estimate, store, now, config);

  if (message) {
    if (!quoteCase.processedMessageIds.includes(message.id)) {
      quoteCase.processedMessageIds.push(message.id);
    }
    quoteCase.lastInboundAt = message.date;
  }
  quoteCase.updatedAt = now.toISOString();
  store.putCase(quoteCase);

  return { quoteCase, decision, draft, problems };
}

/** Evalua un caso ya existente por vencimiento de seguimiento, sin correo nuevo. */
export function processScheduled(
  quoteCase: QuoteCase,
  thread: EmailThread,
  store: CaseStore,
  now: Date,
  config: RuntimeConfig = DEFAULT_RUNTIME,
): ProcessResult {
  const input: DecisionInput = {
    trigger: 'scheduled',
    thread,
    intent: { intent: 'quote_followup', confidence: 0.9, signals: [] },
    facts: quoteCase.facts,
    state: quoteCase.state,
    infoRequests: quoteCase.infoRequests,
    followUpsSent: quoteCase.followUpsSent,
    hasPendingDraft: Boolean(quoteCase.pendingDraftId),
    draftTimestamps: quoteCase.draftTimestamps,
    processedMessageIds: quoteCase.processedMessageIds,
    evidence: quoteCase.providerEvidence,
    productCovered: isProductCovered(quoteCase.facts.product?.value, config.providers),
    estimatedAmount: quoteCase.estimate?.priceTotal,
    lastInboundAt: quoteCase.lastInboundAt,
    now,
    policy: config.policy,
  };

  const decision = decide(input);
  const draft = applyDecision(quoteCase, decision, quoteCase.estimate ?? null, store, now, config);
  quoteCase.updatedAt = now.toISOString();
  store.putCase(quoteCase);
  return { quoteCase, decision, draft, problems: [] };
}

/* -------------------------------------------------------------- efectos */

function applyDecision(
  quoteCase: QuoteCase,
  decision: Decision,
  estimate: ReturnType<typeof buildEstimate>['estimate'],
  store: CaseStore,
  now: Date,
  config: RuntimeConfig,
): Draft | null {
  const ctx = { now, signature: config.signature };
  let draft: Draft | null = null;

  switch (decision.action) {
    case 'ASK_INFO':
      draft = draftAskInfo(quoteCase, decision.missingFields, ctx);
      quoteCase.infoRequests += 1;
      break;

    case 'PREPARE_QUOTE':
      if (!estimate) {
        // Sin evidencia de coste no hay presupuesto. No se improvisa un precio:
        // el caso queda a la espera de que se capture la evidencia.
        record(quoteCase, now, 'quote.blocked', 'Faltan evidencias de coste validas.');
        quoteCase.state = 'QUOTING';
        return null;
      }
      quoteCase.estimate = estimate;
      draft = draftQuote(quoteCase, estimate, ctx);
      break;

    case 'SEND_FOLLOW_UP':
      draft = draftFollowUp(quoteCase, quoteCase.followUpsSent + 1, ctx);
      quoteCase.followUpsSent += 1;
      break;

    case 'CLOSE_LOST':
      quoteCase.state = 'LOST';
      quoteCase.lostReason = 'no_response';
      quoteCase.nextFollowUpAt = undefined;
      record(quoteCase, now, 'case.lost', 'Cerrado por silencio tras agotar los recordatorios.');
      return null;

    case 'ESCALATE':
      quoteCase.state = decision.nextState;
      record(
        quoteCase,
        now,
        'case.escalated',
        decision.escalationReasons.map((r) => r.message).join(' | '),
      );
      return null;

    case 'ARCHIVE':
    case 'IGNORE':
    case 'NO_ACTION':
      record(quoteCase, now, `decision.${decision.action.toLowerCase()}`, traceText(decision));
      return null;
  }

  if (draft) {
    store.putDraft(draft);
    quoteCase.pendingDraftId = draft.id;
    quoteCase.draftTimestamps.push(draft.createdAt);
    quoteCase.state = decision.nextState;
    quoteCase.nextFollowUpAt =
      nextFollowUpAt(quoteCase.state, quoteCase.followUpsSent, now, config.policy) ?? undefined;
    record(quoteCase, now, 'draft.created', `${draft.kind}: ${draft.id}`);
  }

  return draft;
}

/* -------------------------------------------------------------- ayudantes */

function ensureCase(
  thread: EmailThread,
  store: CaseStore,
  now: Date,
  message: EmailMessage | undefined,
): QuoteCase {
  const existing = store.findCaseByThread(thread.id);
  if (existing) return existing;
  // El asunto y el contacto del expediente salen del primer mensaje del hilo,
  // no del ultimo: "Re: Re: ..." no describe el caso.
  const first = thread.messages[0] ?? message;
  const created = newCase({
    id: `case_${thread.id}`,
    threadId: thread.id,
    contact: first?.from ?? { address: 'desconocido@sin-remitente' },
    subject: first?.subject ?? '(sin asunto)',
    now,
  });
  store.putCase(created);
  return created;
}

/** true si el mensaje aporta al menos un dato critico dicho de forma explicita. */
function carriesCriticalData(message: EmailMessage): boolean {
  const facts = extractFromMessage(message);
  return CRITICAL_FIELDS.some((key) => {
    const value = facts[key];
    return value !== undefined && value.source === 'explicit';
  });
}

function lastInbound(thread: EmailThread): EmailMessage | undefined {
  for (let i = thread.messages.length - 1; i >= 0; i -= 1) {
    const message = thread.messages[i];
    if (message?.direction === 'inbound') return message;
  }
  return undefined;
}

function record(quoteCase: QuoteCase, now: Date, code: string, detail: string): void {
  const event: CaseEvent = { at: now.toISOString(), code, detail, actor: 'nika' };
  quoteCase.events.push(event);
}

function traceText(decision: Decision): string {
  return decision.trace.map((r) => `${r.code}: ${r.message}`).join(' | ');
}
