/**
 * Persistencia SQLite: licencias y caché de respuestas de Google.
 *
 * SQLite es suficiente para este servicio: las escrituras son escasas (altas de
 * licencia y refrescos de caché) y las lecturas son puntuales.
 */

import Database from 'better-sqlite3';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { config } from './config.js';

mkdirSync(dirname(config.databasePath), { recursive: true });

export const db = new Database(config.databasePath);

db.pragma('journal_mode = WAL');

db.exec(`
  CREATE TABLE IF NOT EXISTS licenses (
    key          TEXT PRIMARY KEY,
    plan         TEXT NOT NULL DEFAULT 'free',
    status       TEXT NOT NULL DEFAULT 'active',
    domain       TEXT,
    label        TEXT,
    expires_at   TEXT,
    created_at   TEXT NOT NULL,
    last_seen_at TEXT,
    last_site    TEXT
  );

  CREATE TABLE IF NOT EXISTS place_cache (
    cache_key   TEXT PRIMARY KEY,
    payload     TEXT NOT NULL,
    fetched_at  INTEGER NOT NULL
  );

  CREATE TABLE IF NOT EXISTS request_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key  TEXT,
    route        TEXT NOT NULL,
    upstream     INTEGER NOT NULL DEFAULT 0,
    status       INTEGER NOT NULL,
    created_at   INTEGER NOT NULL
  );

  CREATE INDEX IF NOT EXISTS request_log_created ON request_log (created_at);
`);

// ---- Licencias ----

export const findLicense = (key) =>
  db.prepare('SELECT * FROM licenses WHERE key = ?').get(key);

export const touchLicense = (key, site) =>
  db
    .prepare('UPDATE licenses SET last_seen_at = ?, last_site = ? WHERE key = ?')
    .run(new Date().toISOString(), site ?? null, key);

export const createLicense = ({ key, plan, domain, label, expiresAt }) =>
  db
    .prepare(
      `INSERT INTO licenses (key, plan, status, domain, label, expires_at, created_at)
       VALUES (?, ?, 'active', ?, ?, ?, ?)`,
    )
    .run(key, plan, domain ?? null, label ?? null, expiresAt ?? null, new Date().toISOString());

export const setLicenseStatus = (key, status) =>
  db.prepare('UPDATE licenses SET status = ? WHERE key = ?').run(status, key);

export const setLicensePlan = (key, plan) =>
  db.prepare('UPDATE licenses SET plan = ? WHERE key = ?').run(plan, key);

export const listLicenses = () =>
  db.prepare('SELECT * FROM licenses ORDER BY created_at DESC').all();

// ---- Caché ----

export const readCache = (cacheKey, ttlSeconds) => {
  const row = db.prepare('SELECT payload, fetched_at FROM place_cache WHERE cache_key = ?').get(cacheKey);
  if (!row) return null;
  if (Date.now() / 1000 - row.fetched_at > ttlSeconds) return null;
  try {
    return JSON.parse(row.payload);
  } catch {
    return null;
  }
};

export const writeCache = (cacheKey, payload) =>
  db
    .prepare(
      `INSERT INTO place_cache (cache_key, payload, fetched_at) VALUES (?, ?, ?)
       ON CONFLICT(cache_key) DO UPDATE SET payload = excluded.payload, fetched_at = excluded.fetched_at`,
    )
    .run(cacheKey, JSON.stringify(payload), Math.floor(Date.now() / 1000));

export const purgeCacheOlderThan = (seconds) =>
  db
    .prepare('DELETE FROM place_cache WHERE fetched_at < ?')
    .run(Math.floor(Date.now() / 1000) - seconds).changes;

// ---- Métricas mínimas ----

export const logRequest = ({ licenseKey, route, upstream, status }) =>
  db
    .prepare('INSERT INTO request_log (license_key, route, upstream, status, created_at) VALUES (?, ?, ?, ?, ?)')
    .run(licenseKey ?? null, route, upstream ? 1 : 0, status, Math.floor(Date.now() / 1000));

export const usageSince = (seconds) =>
  db
    .prepare(
      `SELECT COUNT(*) AS total, SUM(upstream) AS upstream_calls
       FROM request_log WHERE created_at > ?`,
    )
    .get(Math.floor(Date.now() / 1000) - seconds);
