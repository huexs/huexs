/**
 * CLI de Nika. Solo lectura sobre correo: no envia nada.
 *
 *   npm run nika -- replay <dir|fichero.json>   analiza hilos y explica la decision
 *   npm run nika -- cases [--store <ruta>]      lista expedientes del almacen
 *   npm run nika -- drafts [--store <ruta>]     lista borradores pendientes
 *   npm run nika -- policy                      imprime la politica efectiva
 */

import { readFile, readdir, stat } from 'node:fs/promises';
import { join } from 'node:path';
import type { EmailThread, ProviderEvidence } from './types.ts';
import { DEFAULT_POLICY } from './config/policy.ts';
import { CaseStore, newCase } from './case/store.ts';
import { DEFAULT_RUNTIME, processThread } from './pipeline.ts';

const DEFAULT_STORE = '.nika/store.json';

async function main(argv: string[]): Promise<number> {
  const command = argv[0] ?? 'help';
  const storePath = argValue(argv, '--store') ?? DEFAULT_STORE;

  switch (command) {
    case 'replay':
      return replay(argv[1], storePath, argv.includes('--persist'));
    case 'cases':
      return listCases(storePath);
    case 'drafts':
      return listDrafts(storePath);
    case 'policy':
      console.log(JSON.stringify(DEFAULT_POLICY, null, 2));
      return 0;
    default:
      console.log(usage());
      return command === 'help' ? 0 : 1;
  }
}

async function replay(target: string | undefined, storePath: string, persist: boolean): Promise<number> {
  if (!target) {
    console.error('Falta la ruta de hilos. Ej: npm run nika -- replay fixtures/threads');
    return 1;
  }

  const files = await collectJsonFiles(target);
  if (files.length === 0) {
    console.error(`No se han encontrado ficheros .json en ${target}`);
    return 1;
  }

  const store = persist ? new CaseStore(storePath) : CaseStore.inMemory();
  await store.load();
  const now = new Date();

  for (const file of files) {
    const raw = JSON.parse(await readFile(file, 'utf8')) as EmailThread & {
      _evidence?: ProviderEvidence[];
    };
    const thread: EmailThread = { id: raw.id, messages: raw.messages };

    // Los fixtures pueden traer evidencia de coste ya capturada, para poder
    // ver el camino completo hasta la propuesta sin salir a ningun proveedor.
    if (raw._evidence?.length) {
      const seeded = newCase({
        id: `case_${thread.id}`,
        threadId: thread.id,
        contact: thread.messages[0]?.from ?? { address: 'desconocido@sin-remitente' },
        subject: thread.messages[0]?.subject ?? '(sin asunto)',
        now,
      });
      seeded.providerEvidence = raw._evidence;
      store.putCase(seeded);
    }

    const result = await processThread(thread, store, now, DEFAULT_RUNTIME);

    console.log('');
    console.log('─'.repeat(72));
    console.log(`hilo      ${thread.id}   (${file})`);
    console.log(`asunto    ${result.quoteCase.subject}`);
    console.log(`accion    ${result.decision.action}`);
    console.log(
      `responde  ${yesNo(result.decision.reply)}      escala  ${yesNo(result.decision.escalate)}      confianza ${result.decision.confidence}`,
    );
    if (result.decision.missingFields.length) {
      console.log(`faltan    ${result.decision.missingFields.join(', ')}`);
    }
    for (const reason of result.decision.trace) {
      console.log(`  · ${reason.code} — ${reason.message}`);
    }
    for (const reason of result.decision.escalationReasons) {
      console.log(`  ! ${reason.code} — ${reason.message}`);
    }
    for (const problem of result.problems) {
      console.log(`  ? ${problem}`);
    }
    if (result.draft) {
      console.log('');
      console.log(`borrador (${result.draft.kind}, ${result.draft.status}):`);
      console.log(indent(result.draft.body));
    }
  }

  if (persist) {
    await store.save();
    console.log(`\nAlmacen guardado en ${store.path}`);
  }
  return 0;
}

async function listCases(storePath: string): Promise<number> {
  const store = new CaseStore(storePath);
  await store.load();
  const cases = store.listCases();
  if (cases.length === 0) {
    console.log('No hay expedientes.');
    return 0;
  }
  for (const c of cases) {
    console.log(
      `${c.id.padEnd(28)} ${c.state.padEnd(12)} ${c.contact.address.padEnd(32)} ${c.subject}`,
    );
  }
  return 0;
}

async function listDrafts(storePath: string): Promise<number> {
  const store = new CaseStore(storePath);
  await store.load();
  const drafts = store.listDrafts();
  if (drafts.length === 0) {
    console.log('No hay borradores pendientes.');
    return 0;
  }
  for (const d of drafts) {
    console.log(`${d.id.padEnd(34)} ${d.kind.padEnd(16)} ${d.status.padEnd(16)} ${d.subject}`);
  }
  return 0;
}

/* ------------------------------------------------------------- ayudantes */

async function collectJsonFiles(target: string): Promise<string[]> {
  const info = await stat(target);
  if (info.isFile()) return [target];
  const entries = await readdir(target);
  return entries.filter((e) => e.endsWith('.json')).sort().map((e) => join(target, e));
}

function argValue(argv: string[], flag: string): string | undefined {
  const index = argv.indexOf(flag);
  return index === -1 ? undefined : argv[index + 1];
}

function yesNo(value: boolean): string {
  return value ? 'si ' : 'no ';
}

function indent(text: string): string {
  return text
    .split('\n')
    .map((line) => `    ${line}`)
    .join('\n');
}

function usage(): string {
  return [
    'nika — asistente de presupuestos de RotulaMax (solo prepara; no envia)',
    '',
    '  replay <ruta> [--persist] [--store <f>]   analiza hilos json y explica la decision',
    '  cases  [--store <f>]                      lista expedientes',
    '  drafts [--store <f>]                      lista borradores pendientes de revision',
    '  policy                                    imprime la politica efectiva',
  ].join('\n');
}

process.exitCode = await main(process.argv.slice(2));
