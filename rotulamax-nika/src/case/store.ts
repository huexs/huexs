/**
 * Almacen de expedientes y borradores.
 *
 * Fichero JSON por ahora, con una interfaz estrecha para poder cambiar a
 * SQLite sin tocar el resto del codigo. Escritura atomica (fichero temporal +
 * rename) para no dejar el estado a medias si el proceso muere.
 *
 * Regla operativa: los datos reales de produccion no se usan como fixture de
 * test, y no se reinicializa el almacen "para empezar de cero".
 */

import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import type { Draft, QuoteCase } from '../types.ts';

interface StoreShape {
  version: 1;
  cases: Record<string, QuoteCase>;
  drafts: Record<string, Draft>;
}

const EMPTY: StoreShape = { version: 1, cases: {}, drafts: {} };

export class CaseStore {
  readonly path: string;
  private data: StoreShape = structuredClone(EMPTY);
  private loaded = false;

  constructor(path: string) {
    this.path = path;
  }

  static inMemory(): CaseStore {
    const store = new CaseStore(':memory:');
    store.loaded = true;
    return store;
  }

  async load(): Promise<void> {
    if (this.loaded) return;
    try {
      const raw = await readFile(this.path, 'utf8');
      const parsed = JSON.parse(raw) as StoreShape;
      if (parsed.version !== 1) throw new Error(`Version de almacen no soportada: ${parsed.version}`);
      this.data = { version: 1, cases: parsed.cases ?? {}, drafts: parsed.drafts ?? {} };
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
      this.data = structuredClone(EMPTY);
    }
    this.loaded = true;
  }

  async save(): Promise<void> {
    if (this.path === ':memory:') return;
    await mkdir(dirname(this.path), { recursive: true });
    const tmp = join(dirname(this.path), `.${Date.now()}.tmp`);
    await writeFile(tmp, JSON.stringify(this.data, null, 2), 'utf8');
    await rename(tmp, this.path);
  }

  getCase(id: string): QuoteCase | undefined {
    return this.data.cases[id];
  }

  findCaseByThread(threadId: string): QuoteCase | undefined {
    return Object.values(this.data.cases).find((c) => c.threadId === threadId);
  }

  listCases(): QuoteCase[] {
    return Object.values(this.data.cases);
  }

  putCase(quoteCase: QuoteCase): void {
    this.data.cases[quoteCase.id] = quoteCase;
  }

  getDraft(id: string): Draft | undefined {
    return this.data.drafts[id];
  }

  listDrafts(): Draft[] {
    return Object.values(this.data.drafts);
  }

  putDraft(draft: Draft): void {
    this.data.drafts[draft.id] = draft;
  }
}

export function newCase(params: {
  id: string;
  threadId: string;
  contact: QuoteCase['contact'];
  subject: string;
  now: Date;
}): QuoteCase {
  const iso = params.now.toISOString();
  return {
    id: params.id,
    threadId: params.threadId,
    createdAt: iso,
    updatedAt: iso,
    state: 'NEW',
    contact: params.contact,
    subject: params.subject,
    facts: {},
    infoRequests: 0,
    followUpsSent: 0,
    processedMessageIds: [],
    draftTimestamps: [],
    providerEvidence: [],
    events: [],
  };
}
