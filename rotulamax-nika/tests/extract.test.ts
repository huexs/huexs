import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import { extractFacts, extractFromMessage } from '../src/triage/extract.ts';
import { stripQuotedText } from '../src/email/text.ts';
import { inbound, thread } from './helpers.ts';

describe('extract — medidas', () => {
  it('lee medidas con unidad explicita como dato explicito', () => {
    const facts = extractFromMessage(inbound({ text: 'Una lona de 300 x 100 cm' }));
    assert.deepEqual(facts.dimensions?.value, { widthCm: 300, heightCm: 100 });
    assert.equal(facts.dimensions?.source, 'explicit');
  });

  it('convierte metros a centimetros', () => {
    const facts = extractFromMessage(inbound({ text: 'Necesito 2,5 m x 1 m de lona' }));
    assert.deepEqual(facts.dimensions?.value, { widthCm: 250, heightCm: 100 });
  });

  it('marca como inferida una medida sin unidad y le baja la confianza', () => {
    // 120x80 puede ser cm o mm. No se adivina: se marca para preguntar.
    const facts = extractFromMessage(inbound({ text: 'Un cartel de 120x80' }));
    assert.equal(facts.dimensions?.source, 'inferred');
    assert.ok(facts.dimensions!.confidence < 0.6);
  });
});

describe('extract — cantidad, producto y direccion', () => {
  it('lee una cantidad explicita', () => {
    const facts = extractFromMessage(inbound({ text: 'Cantidad: 12' }));
    assert.equal(facts.quantity?.value, 12);
    assert.equal(facts.quantity?.source, 'explicit');
  });

  it('supone 1 unidad pero lo marca como supuesto', () => {
    const facts = extractFromMessage(inbound({ text: 'Quiero una lona' }));
    assert.equal(facts.quantity?.value, 1);
    assert.equal(facts.quantity?.source, 'inferred');
  });

  it('identifica el producto', () => {
    const facts = extractFromMessage(inbound({ text: 'Presupuesto de letras corporeas' }));
    assert.equal(facts.product?.value, 'rotulo_corporeo');
  });

  it('lee una direccion completa con codigo postal', () => {
    const facts = extractFromMessage(
      inbound({ text: 'Entrega en Calle Industria 45, 08025 Barcelona' }),
    );
    assert.equal(facts.deliveryAddress?.source, 'explicit');
    assert.ok(facts.deliveryAddress!.value.includes('08025'));
  });

  it('un codigo postal suelto no vale como direccion de entrega', () => {
    const facts = extractFromMessage(inbound({ text: 'Somos de 08025, cerca de vosotros' }));
    assert.equal(facts.deliveryAddress?.source, 'inferred');
    assert.ok(facts.deliveryAddress!.confidence < 0.6);
  });
});

describe('extract — falsos positivos por subcadena', () => {
  it('"Barcelona" no es el producto "lona"', () => {
    // Regresion: la busqueda por subcadena convertia una direccion de entrega
    // en un producto, y ese producto llegaba hasta el precio final.
    const facts = extractFromMessage(inbound({ text: 'Entrega en Calle Mallorca 220, 08008 Barcelona.' }));
    assert.equal(facts.product, undefined);
    assert.equal(facts.material, undefined);
  });

  it('"pancarta" si es el producto lona', () => {
    const facts = extractFromMessage(inbound({ text: 'Necesito una pancarta grande.' }));
    assert.equal(facts.product?.value, 'lona');
  });
});

describe('extract — hilo completo', () => {
  it('acumula datos repartidos entre varios mensajes', () => {
    const facts = extractFacts(
      thread(
        inbound({ id: 'a', text: 'Hola, necesito precio para un roll up.' }),
        inbound({ id: 'b', text: 'Cantidad: 4, medidas 85 x 200 cm.' }),
        inbound({ id: 'c', text: 'Envio a Calle Mallorca 220, 08008 Barcelona.' }),
      ),
    );
    assert.equal(facts.product?.value, 'display');
    assert.equal(facts.quantity?.value, 4);
    assert.deepEqual(facts.dimensions?.value, { widthCm: 85, heightCm: 200 });
    assert.ok(facts.deliveryAddress?.value.includes('08008'));
  });

  it('un dato inferido posterior no pisa un dato explicito anterior', () => {
    const facts = extractFacts(
      thread(
        inbound({ id: 'a', text: 'Necesito una lona. Cantidad: 10' }),
        inbound({ id: 'b', text: 'Gracias por la lona' }),
      ),
    );
    assert.equal(facts.quantity?.value, 10);
    assert.equal(facts.quantity?.source, 'explicit');
  });

  it('ignora el texto citado del mensaje anterior', () => {
    const body = 'Confirmo la cantidad: 3\n\nEl 10/08/2026 escribio:\n> Cantidad: 99\n> Medidas 10x10 cm';
    assert.equal(stripQuotedText(body).includes('99'), false);
    const facts = extractFromMessage(inbound({ text: body }));
    assert.equal(facts.quantity?.value, 3);
  });
});
