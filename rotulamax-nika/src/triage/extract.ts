/**
 * Extraccion de los datos criticos de un hilo.
 *
 * Regla del proyecto: medidas y direccion de entrega no se adivinan. Cuando un
 * valor no es explicito se marca `inferred` con confianza baja, y esa confianza
 * baja es lo que hace que Nika *pregunte* en lugar de presupuestar a ciegas.
 */

import type { CaseFacts, EmailMessage, EmailThread, Field } from '../types.ts';
import { containsWord, normalize, stripQuotedText } from '../email/text.ts';

const PRODUCTS: Array<{ key: string; words: string[] }> = [
  { key: 'rotulo_luminoso', words: ['rotulo luminoso', 'letrero luminoso', 'luminoso led'] },
  { key: 'rotulo_corporeo', words: ['letras corporeas', 'corporeo', 'letras 3d'] },
  { key: 'lona', words: ['lona', 'banner', 'pancarta'] },
  { key: 'vinilo', words: ['vinilo', 'vinilos', 'rotulacion de vinilo'] },
  { key: 'vinilo_microperforado', words: ['microperforado', 'micro perforado'] },
  { key: 'forex', words: ['forex', 'pvc espumado'] },
  { key: 'dibond', words: ['dibond', 'aluminio compuesto'] },
  { key: 'metacrilato', words: ['metacrilato', 'placa metacrilato', 'plexiglas'] },
  { key: 'toldo', words: ['toldo', 'toldos'] },
  { key: 'banderola', words: ['banderola', 'bandera'] },
  { key: 'rotulacion_vehiculo', words: ['rotulacion de vehiculo', 'furgoneta', 'rotular el coche'] },
  { key: 'display', words: ['roll up', 'rollup', 'photocall', 'display'] },
];

const MATERIALS = [
  'pvc',
  'forex',
  'dibond',
  'metacrilato',
  'aluminio',
  'lona',
  'vinilo',
  'poliester',
  'acero',
  'madera',
];

/** `120x80`, `120 x 80 cm`, `2,5m x 1m`, `1.20 x 0.80 m` */
const DIMENSION_RE =
  /(\d+(?:[.,]\d+)?)\s*(cm|mm|m|metros|metro)?\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*(cm|mm|m|metros|metro)?/gi;

/** `3 uds`, `x4`, `cantidad: 10`, `2 unidades` */
const QUANTITY_RES: RegExp[] = [
  /\bcantidad\s*[:=]?\s*(\d{1,4})\b/i,
  /\b(\d{1,4})\s*(?:uds?|unidades?|piezas?|ejemplares?)\b/i,
  /\bx\s*(\d{1,3})\b/i,
  /\bnecesito\s+(\d{1,4})\b/i,
];

/** Direccion postal espanola con codigo postal de 5 digitos. */
const ADDRESS_RE =
  /((?:c\/|calle|avda?\.?|avenida|plaza|pl\.|paseo|carrer|ctra\.?|carretera|pol[ií]gono)[^\n]{4,90}?\b\d{5}\b[^\n]{0,40})/i;
const POSTCODE_RE = /\b(0[1-9]|[1-4]\d|5[0-2])\d{3}\b/;

const DEADLINE_RES: RegExp[] = [
  /\bpara\s+el\s+(\d{1,2}\s*(?:de)?\s*[a-zá-ú]+(?:\s*(?:de)?\s*\d{4})?)/i,
  /\bantes\s+del?\s+(\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?)/i,
  /\bpara\s+el\s+(\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?)/i,
  /\b(urgente|para\s+ya|lo\s+antes\s+posible|esta\s+semana|la\s+semana\s+que\s+viene)\b/i,
];

export function extractFacts(thread: EmailThread): CaseFacts {
  const facts: CaseFacts = {};
  // Se recorre de antiguo a reciente: lo mas reciente y explicito gana.
  for (const message of thread.messages) {
    if (message.direction !== 'inbound') continue;
    mergeInto(facts, extractFromMessage(message));
  }
  return facts;
}

export function extractFromMessage(message: EmailMessage): CaseFacts {
  const raw = `${message.subject}\n${stripQuotedText(message.text)}`;
  const text = normalize(raw);
  const facts: CaseFacts = {};
  const id = message.id;

  /* producto */
  for (const product of PRODUCTS) {
    const hit = product.words.find((w) => containsWord(text, w));
    if (hit) {
      facts.product = field(product.key, 0.85, 'explicit', hit, id);
      break;
    }
  }

  /* medidas */
  const dims = [...raw.matchAll(DIMENSION_RE)];
  if (dims.length > 0) {
    const match = dims[0]!;
    const unitA = match[2];
    const unitB = match[4];
    const unit = (unitB ?? unitA ?? '').toLowerCase();
    const width = toCm(match[1]!, unit);
    const height = toCm(match[3]!, unit);
    if (width && height) {
      // Sin unidad explicita el numero es ambiguo: 120x80 puede ser cm o mm.
      const explicitUnit = Boolean(unitA ?? unitB);
      facts.dimensions = field(
        { widthCm: width, heightCm: height },
        explicitUnit ? 0.9 : 0.5,
        explicitUnit ? 'explicit' : 'inferred',
        match[0]!.trim(),
        id,
      );
    }
  }

  /* cantidad */
  for (const re of QUANTITY_RES) {
    const match = re.exec(raw);
    if (match?.[1]) {
      const value = Number(match[1]);
      if (Number.isFinite(value) && value > 0 && value < 100000) {
        // `x4` es mucho mas ambiguo que `cantidad: 4`.
        const strong = re !== QUANTITY_RES[2];
        facts.quantity = field(value, strong ? 0.85 : 0.5, strong ? 'explicit' : 'inferred', match[0], id);
        break;
      }
    }
  }
  if (!facts.quantity && facts.product) {
    // Suponer 1 unidad es razonable, pero se marca como inferido para que
    // aparezca en el borrador como supuesto y no como dato del cliente.
    facts.quantity = field(1, 0.45, 'inferred', 'no indicado; se asume 1', id);
  }

  /* material */
  const material = MATERIALS.find((m) => containsWord(text, m));
  if (material) facts.material = field(material, 0.75, 'explicit', material, id);

  /* direccion de entrega */
  const address = ADDRESS_RE.exec(raw);
  if (address?.[1]) {
    facts.deliveryAddress = field(address[1].trim(), 0.85, 'explicit', address[1].trim(), id);
  } else if (POSTCODE_RE.test(raw)) {
    const cp = POSTCODE_RE.exec(raw)![0];
    facts.deliveryAddress = field(cp, 0.4, 'inferred', `solo codigo postal: ${cp}`, id);
  }

  /* plazo */
  for (const re of DEADLINE_RES) {
    const match = re.exec(raw);
    if (match?.[1]) {
      facts.deadline = field(match[1].trim(), 0.7, 'explicit', match[0], id);
      break;
    }
  }

  /* instalacion */
  if (/\binstalaci[oó]n|instalar|montaje|colocaci[oó]n\b/i.test(raw)) {
    const wantsIt = !/\bsin\s+(instalaci[oó]n|montaje)\b/i.test(raw);
    facts.installation = field(wantsIt, 0.7, 'explicit', 'menciona instalacion/montaje', id);
  }

  /* arte final */
  if (/\barte\s*final|archivo\s+listo|adjunto\s+el\s+dise|pdf\s+en\s+curvas|vectorizado\b/i.test(raw)) {
    facts.artworkReady = field(true, 0.7, 'explicit', 'menciona arte final', id);
  } else if (/\bnecesito\s+(el\s+)?dise|no\s+tengo\s+dise|nos\s+lo\s+diseñ/i.test(raw)) {
    facts.artworkReady = field(false, 0.7, 'explicit', 'pide diseno', id);
  }

  /* presupuesto orientativo del cliente */
  const budget = /(?:presupuesto|budget|maximo|tope)[^\d]{0,20}(\d{2,6})\s*(?:€|eur|euros)/i.exec(raw);
  if (budget?.[1]) {
    facts.budgetHint = field(Number(budget[1]), 0.7, 'explicit', budget[0], id);
  }

  return facts;
}

/**
 * Fusiona respetando la evidencia: un valor explicito nunca es sustituido por
 * uno inferido, aunque el inferido sea mas reciente.
 */
export function mergeInto(target: CaseFacts, incoming: CaseFacts): CaseFacts {
  for (const key of Object.keys(incoming) as Array<keyof CaseFacts>) {
    const next = incoming[key] as Field<unknown> | undefined;
    const prev = target[key] as Field<unknown> | undefined;
    if (!next) continue;
    if (!prev) {
      Object.assign(target, { [key]: next });
      continue;
    }
    const prevExplicit = prev.source === 'explicit' || prev.source === 'human';
    const nextExplicit = next.source === 'explicit' || next.source === 'human';
    if (prev.source === 'human') continue;
    if (nextExplicit && !prevExplicit) {
      Object.assign(target, { [key]: next });
    } else if (nextExplicit === prevExplicit && next.confidence >= prev.confidence) {
      Object.assign(target, { [key]: next });
    }
  }
  return target;
}

function field<T>(
  value: T,
  confidence: number,
  source: Field<T>['source'],
  evidence: string,
  messageId: string,
): Field<T> {
  return { value, confidence, source, evidence, messageId };
}

function toCm(raw: string, unit: string): number | null {
  const value = Number(raw.replace(',', '.'));
  if (!Number.isFinite(value) || value <= 0) return null;
  switch (unit) {
    case 'mm':
      return value / 10;
    case 'm':
    case 'metro':
    case 'metros':
      return value * 100;
    case 'cm':
      return value;
    default:
      // Sin unidad, se interpreta cm. La confianza baja lo refleja.
      return value;
  }
}
