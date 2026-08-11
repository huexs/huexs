#!/usr/bin/env node
/**
 * Emite una clave de licencia desde la línea de comandos.
 *
 * Uso:
 *   npm run license:issue -- --plan=pro --domain=cliente.com --label="Cliente X"
 */

import { randomBytes } from 'node:crypto';
import { config } from '../src/config.js';
import { createLicense } from '../src/db.js';

const args = Object.fromEntries(
  process.argv.slice(2).map((arg) => {
    const [key, ...rest] = arg.replace(/^--/, '').split('=');
    return [key, rest.join('=') || true];
  }),
);

const plan = String(args.plan ?? 'free');
if (!Object.keys(config.plans).includes(plan)) {
  console.error(`Plan desconocido: ${plan}. Válidos: ${Object.keys(config.plans).join(', ')}`);
  process.exit(1);
}

const chunk = () => randomBytes(4).toString('hex').toUpperCase();
const key = `HGR-${chunk()}-${chunk()}`;

createLicense({
  key,
  plan,
  domain: args.domain ? String(args.domain) : null,
  label: args.label ? String(args.label) : null,
  expiresAt: args.expires ? String(args.expires) : null,
});

console.log('');
console.log('  Licencia emitida');
console.log('  ─────────────────────────────────────');
console.log(`  Clave:   ${key}`);
console.log(`  Plan:    ${plan}`);
console.log(`  Dominio: ${args.domain ?? '(cualquiera)'}`);
console.log(`  Etiqueta:${args.label ? ` ${args.label}` : ' —'}`);
console.log('');
console.log('  Pégala en el WordPress del cliente:');
console.log('  Google Reviews → Conexión → Clave de licencia');
console.log('');
