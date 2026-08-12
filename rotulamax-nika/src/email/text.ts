/** Utilidades de texto. Sin dependencias, deterministas y testeables. */

/** Minusculas y sin acentos, para comparar palabras clave en es/ca/en. */
export function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

/**
 * Elimina la cita del mensaje anterior. Los agentes que leen el hilo completo
 * en cada mensaje reclasifican una y otra vez el texto viejo; eso genera
 * respuestas duplicadas.
 */
export function stripQuotedText(body: string): string {
  const lines = body.split(/\r?\n/);
  const out: string[] = [];
  for (const line of lines) {
    const t = line.trim();
    if (t.startsWith('>')) break;
    if (/^-{2,}\s*mensaje original\s*-{2,}/i.test(t)) break;
    if (/^_{5,}$/.test(t)) break;
    if (/^el .+ escribio:$/i.test(normalize(t))) break;
    if (/^on .+ wrote:$/i.test(t)) break;
    if (/^de:\s/i.test(t) && out.length > 0) break;
    if (/^from:\s/i.test(t) && out.length > 0) break;
    out.push(line);
  }
  return out.join('\n').trim() || body.trim();
}

/**
 * Busca una palabra o frase completa, no una subcadena.
 *
 * Sin limites de palabra, "Barcelona" contiene "lona" y una direccion de
 * entrega acaba clasificada como producto. Este tipo de falso positivo llega
 * hasta el precio final, asi que la comparacion es siempre por palabra.
 */
export function containsWord(haystack: string, needle: string): boolean {
  const phrase = normalize(needle);
  if (!phrase) return false;
  const escaped = phrase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const boundary = '[^\\p{L}\\p{N}]';
  // Se tolera el plural al final ("lona" encuentra "lonas", "rotulos"), pero
  // no el prefijo: "Barcelona" sigue sin ser "lona".
  const re = new RegExp(`(?<=^|${boundary})${escaped}(?:es|s)?(?=$|${boundary})`, 'u');
  return re.test(normalize(haystack));
}

/** Devuelve las palabras clave que aparecen como palabra completa. */
export function countMatches(haystack: string, needles: string[]): string[] {
  const n = normalize(haystack);
  return needles.filter((needle) => containsWord(n, needle));
}

export function hasQuestion(text: string): boolean {
  if (text.includes('?') || text.includes('¿')) return true;
  const n = normalize(text);
  return /\b(podrias|podeis|podriais|necesito saber|me gustaria saber|cuanto|cuando|como seria|que precio|dime)\b/.test(
    n,
  );
}

export function domainOf(address: string): string {
  const at = address.lastIndexOf('@');
  return at === -1 ? '' : address.slice(at + 1).toLowerCase();
}

export function localPartOf(address: string): string {
  const at = address.lastIndexOf('@');
  return (at === -1 ? address : address.slice(0, at)).toLowerCase();
}
