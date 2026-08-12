import { strict as assert } from 'node:assert';
import { describe, it } from 'node:test';
import { classify, classifyThread } from '../src/triage/classify.ts';
import { CTX, inbound, thread } from './helpers.ts';

describe('classify — trafico que nunca debe generar respuesta', () => {
  it('detecta autorespuesta por cabecera Auto-Submitted', () => {
    const result = classify(
      inbound({ text: 'Estare fuera', headers: { 'auto-submitted': 'auto-replied' } }),
      CTX,
    );
    assert.equal(result.intent, 'auto_reply');
  });

  it('detecta newsletter por List-Unsubscribe aunque hable de presupuestos', () => {
    const result = classify(
      inbound({
        subject: 'Presupuesto gratis para tu negocio',
        text: 'Pide tu presupuesto sin compromiso. Precio especial.',
        headers: { 'list-unsubscribe': '<https://x.test/baja>' },
      }),
      CTX,
    );
    assert.equal(result.intent, 'marketing');
  });

  it('detecta buzones no-reply por la parte local', () => {
    const result = classify(
      inbound({ from: { address: 'no-reply@plataforma.test' }, text: 'Tienes una notificacion' }),
      CTX,
    );
    assert.equal(result.intent, 'auto_reply');
  });

  it('detecta ausencia de oficina por el texto', () => {
    const result = classify(
      inbound({ text: 'Estare fuera de la oficina hasta el 25 de agosto.' }),
      CTX,
    );
    assert.equal(result.intent, 'out_of_office');
  });

  it('marca como interno el correo de nuestro propio dominio', () => {
    const result = classify(
      inbound({ from: { address: 'juan@rotulamax.com' }, text: 'Mira este presupuesto' }),
      CTX,
    );
    assert.equal(result.intent, 'internal');
  });

  it('marca como proveedor el correo de un dominio de proveedor', () => {
    const result = classify(
      inbound({ from: { address: 'ventas@proveedor.test' }, text: 'Te paso precio de la lona' }),
      CTX,
    );
    assert.equal(result.intent, 'provider_message');
  });
});

describe('classify — peticiones reales', () => {
  it('reconoce una peticion de presupuesto', () => {
    const result = classify(
      inbound({ subject: 'Presupuesto lona', text: 'Necesito precio para una lona de 3x1 m.' }),
      CTX,
    );
    assert.equal(result.intent, 'quote_request');
    assert.ok(result.confidence >= 0.55);
  });

  it('no confunde un "gracias" de cortesia con un acuse de recibo', () => {
    // Regresion: este es el caso que hacia que un lead real se archivase.
    const result = classify(
      inbound({
        subject: 'Presupuesto rotulos',
        text: 'Hola, necesitamos presupuesto para tres rotulos luminosos. Gracias.',
      }),
      CTX,
    );
    assert.equal(result.intent, 'quote_request');
  });

  it('reconoce un acuse puro', () => {
    const result = classify(inbound({ text: 'Recibido, muchas gracias.' }), CTX);
    assert.equal(result.intent, 'ack');
  });

  it('reconoce una reclamacion', () => {
    const result = classify(
      inbound({ text: 'El rotulo ha llegado defectuoso y mal impreso, queremos la devolucion.' }),
      CTX,
    );
    assert.equal(result.intent, 'complaint');
  });

  it('trata como seguimiento la pregunta de precio cuando ya enviamos presupuesto', () => {
    const result = classify(
      inbound({ text: 'Sobre el presupuesto que nos pasasteis, ¿sigue en pie el precio?' }),
      { ...CTX, quoteAlreadySent: true },
    );
    assert.equal(result.intent, 'quote_followup');
  });

  it('deja en unknown un mensaje sin senales y con confianza baja', () => {
    const result = classify(inbound({ text: 'Hola, hablamos el otro dia.' }), CTX);
    assert.equal(result.intent, 'unknown');
    assert.ok(result.confidence < 0.55);
  });
});

describe('classifyThread — continuidad del hilo', () => {
  it('hereda la intencion del hilo cuando el ultimo mensaje solo aporta un dato', () => {
    // Regresion: "Envio a Calle X, 08008 Barcelona." aislado es `unknown`, y
    // `unknown` escala. Eso mandaba a revision humana ampliaciones de datos de
    // leads perfectamente normales.
    const result = classifyThread(
      thread(
        inbound({ id: 'a', subject: 'Presupuesto roll up', text: 'Hola, precio de un roll up.' }),
        inbound({ id: 'b', text: 'Envio a Calle Mallorca 220, 08008 Barcelona.' }),
      ),
      CTX,
    );
    assert.equal(result.intent, 'quote_request');
    assert.ok(result.signals.some((s) => s.code === 'context.thread-continuation'));
  });

  it('no hereda nada por encima de una autorespuesta', () => {
    const result = classifyThread(
      thread(
        inbound({ id: 'a', subject: 'Presupuesto lona', text: 'Precio de una lona.' }),
        inbound({
          id: 'b',
          text: 'Estare fuera de la oficina.',
          headers: { 'auto-submitted': 'auto-replied' },
        }),
      ),
      CTX,
    );
    assert.equal(result.intent, 'auto_reply');
  });

  it('un hilo sin ninguna intencion accionable sigue siendo unknown', () => {
    const result = classifyThread(
      thread(
        inbound({ id: 'a', text: 'Hola, hablamos el otro dia.' }),
        inbound({ id: 'b', text: 'Sobre lo que comentamos.' }),
      ),
      CTX,
    );
    assert.equal(result.intent, 'unknown');
  });
});

describe('el asunto heredado no contamina el hilo', () => {
  it('un "gracias" en un hilo titulado "Re: Presupuesto..." sigue siendo un acuse', () => {
    // Regresion: al puntuar el asunto, la palabra "Presupuesto" arrastrada por
    // el "Re:" hacia que cada mensaje del hilo pareciera una peticion nueva.
    const result = classify(
      inbound({ subject: 'Re: Presupuesto cartel forex', text: 'Recibido, muchas gracias. Lo miramos.' }),
      CTX,
    );
    assert.equal(result.intent, 'ack');
  });

  it('un mensaje con "gracias" que aporta datos no es un acuse', () => {
    // Regresion: "Y el envio a Calle X, 08008 Barcelona. Gracias." es la pieza
    // que faltaba para presupuestar, no un acuse de recibo.
    const result = classifyThread(
      thread(
        inbound({ id: 'a', subject: 'Presupuesto roll up', text: 'Hola, precio de un roll up.' }),
        inbound({
          id: 'b',
          subject: 'Re: Presupuesto roll up',
          text: 'Y el envio a Calle Mallorca 220, 08008 Barcelona. Gracias.',
        }),
      ),
      { ...CTX, lastMessageCarriesData: true },
    );
    assert.equal(result.intent, 'quote_request');
  });
});
