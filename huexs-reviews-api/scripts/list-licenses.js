#!/usr/bin/env node
/**
 * Lista las licencias emitidas.
 *
 * Uso: npm run license:list
 */

import { listLicenses } from '../src/db.js';

const licenses = listLicenses();

if (licenses.length === 0) {
  console.log('No hay licencias emitidas todavía. Crea una con: npm run license:issue');
  process.exit(0);
}

console.table(
  licenses.map((license) => ({
    Clave: license.key,
    Plan: license.plan,
    Estado: license.status,
    Dominio: license.domain ?? '—',
    Etiqueta: license.label ?? '—',
    'Último uso': license.last_seen_at ? license.last_seen_at.slice(0, 16).replace('T', ' ') : 'nunca',
  })),
);
