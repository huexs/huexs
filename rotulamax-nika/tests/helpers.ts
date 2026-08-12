import type { EmailMessage, EmailThread } from '../src/types.ts';
import type { ClassifyContext } from '../src/triage/classify.ts';
import { DEFAULT_POLICY } from '../src/config/policy.ts';

let counter = 0;

export function inbound(partial: Partial<EmailMessage> & { text: string }): EmailMessage {
  counter += 1;
  return {
    id: partial.id ?? `m${counter}`,
    threadId: partial.threadId ?? 't1',
    date: partial.date ?? '2026-08-11T10:00:00.000Z',
    direction: 'inbound',
    from: partial.from ?? { name: 'Cliente Prueba', address: 'cliente@ejemplo.test' },
    to: partial.to ?? [{ address: 'info@rotulamax.com' }],
    subject: partial.subject ?? 'Consulta',
    text: partial.text,
    headers: partial.headers,
    attachments: partial.attachments,
  };
}

export function outbound(partial: Partial<EmailMessage> & { text: string }): EmailMessage {
  return { ...inbound(partial), direction: 'outbound', from: { address: 'info@rotulamax.com' } };
}

export function thread(...messages: EmailMessage[]): EmailThread {
  const id = messages[0]?.threadId ?? 't1';
  return { id, messages: messages.map((m) => ({ ...m, threadId: id })) };
}

export const CTX: ClassifyContext = {
  ownDomains: ['rotulamax.com'],
  providerDomains: ['proveedor.test'],
  neverReplyDomains: DEFAULT_POLICY.reply.neverReplyDomains,
  neverReplyLocalParts: DEFAULT_POLICY.reply.neverReplyLocalParts,
  quoteAlreadySent: false,
};

export const NOW = new Date('2026-08-11T18:00:00.000Z');
