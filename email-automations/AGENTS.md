# Instruccions per a l'agent receptor

L'objectiu és desplegar `gmail_invoices`, `gmail_school` i `youtube_to_mail`
per a una persona nova, mantenint el codi de domini independent dels serveis.

## Regles de seguretat

- No demanis ni enganxis secrets al xat.
- No escriguis claus, tokens, cookies, JSON de credencials o adreces personals
  al repositori, tests, documentació o logs.
- Desa les credencials fora del repositori amb permisos restrictius i passa als
  adaptadors només la ruta local necessària.
- No imprimeixis el cos complet dels correus ni adjunts en producció.
- Usa els permisos mínims i separa lectura, enviament i emmagatzematge quan
  sigui possible.
- No activis temporitzadors ni webhooks fins que el propietari aprovi una prova
  en sec.

## Arquitectura que s'ha de preservar

- `processor.py`: decisions de negoci i ordre dels efectes.
- `ports.py`: contractes dels serveis externs.
- `state.py`: idempotència i reintents.
- Adaptadors reals: en un mòdul separat, substituïble i testat per contracte.
- Configuració no secreta: fitxer local ignorat per Git.
- Secrets: fora del repositori; mai en `config.local.json`.

No posis crides SDK dins dels processadors. No marquis un element com a
completat abans que el fitxer o el correu s'hagi creat correctament.

## Ordre recomanat

1. Executa els tests i la demo sense modificar res.
2. Implementa l'adaptador Gmail i els seus contract tests.
3. Posa en marxa `gmail_invoices` en mode sec.
4. Implementa el magatzem i activa una única regla de prova.
5. Implementa el resumidor i prova `gmail_school` amb missatges ficticis.
6. Implementa YouTube i inicialitza vídeos antics sense enviar notificacions.
7. Afegeix CLIs, timers, observabilitat i còpies de seguretat.
8. Fes una revisió de secrets i permisos abans d'activar producció.

## Verificació mínima

```bash
.venv/bin/pytest -q
.venv/bin/python -m compileall -q email_automations tests tools
git diff --check
```

Abans de lliurar, executa també:

```bash
rg -n -i 'api.?key|client.?secret|refresh.?token|private.?key|authorization:'
```

Qualsevol coincidència ha de ser documentació genèrica o una comprovació, mai
un valor real.

