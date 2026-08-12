# Email automations handoff

Base reutilitzable per muntar tres automatitzacions:

- `gmail_invoices`: detecta factures en una etiqueta de Gmail, classifica el
  proveïdor, desa els PDF i deriva els casos desconeguts a revisió.
- `gmail_school`: extreu i resumeix comunicacions escolars rellevants, evitant
  tornar a processar un mateix missatge o fil.
- `youtube_to_mail`: detecta vídeos recents de les subscripcions de YouTube i
  envia una notificació per correu una sola vegada.

El paquet no conté credencials, claus API, variables d'entorn, adreces reals,
identificadors de Google, dades personals ni rutes del sistema d'origen. Tampoc
inclou un client concret de Google o d'un model de llenguatge: aquests serveis
es connecten mitjançant els ports descrits a `docs/PRODUCTION_SETUP.md`.

## Prova ràpida

Requereix Python 3.11 o posterior.

```bash
python -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/pytest -q
.venv/bin/python -m tools.run_demo
```

La demo només usa dades fictícies d'`examples/` i escriu estat local a
`data/demo.sqlite3`.

## Adaptadors implementats

Els processadors segueixen depenent només dels protocols d'`ports.py`. Els
adaptadors reals viuen a `email_automations/adapters/`:

| Port | Implementació | Notes |
|---|---|---|
| `Mailbox` | `adapters.gmail.GmailMailbox` | Etiqueta, paginació, dates UTC, adjunts amb límit de mida |
| `Mailer` | `adapters.gmail.GmailMailer` | Text pla; propaga els errors remots |
| `FileStore` | `adapters.storage.DriveFileStore` / `LocalFileStore` | Idempotents pel nom del fitxer |
| `Summarizer` | `adapters.summarizer.ClaudeSummarizer` / `KeywordSummarizer` | Sortida estructurada; `None` quan és irrellevant |
| `PdfTextExtractor` | `adapters.pdf.PypdfTextExtractor` | Error explícit en PDF corrupte; sense OCR |
| `YouTubeSource` | `adapters.youtube.YouTubeApiSource` | Cacheja la llista de pujades per canal |

Les credencials s'obtenen a `adapters/google_auth.py` amb els permisos mínims i
sempre des de rutes locals fora del repositori.

## Ordres

```bash
python -m email_automations.gmail_invoices.cli   [--dry-run] [--health-check] [--limit N]
python -m email_automations.gmail_school.cli     [--dry-run] [--health-check] [--limit N]
python -m email_automations.youtube_to_mail.cli  [--dry-run] [--health-check] [--initialize-without-sending]
python -m tools.authorize [--check]              # comprova el client OAuth / consentiment
```

Les ordres només mostren comptadors de `RunResult` i retornen 1 si algun
element ha quedat en estat `failed`.

## Per on començar

1. Llegiu `HANDOFF.md` per entendre l'abast i les decisions preses.
2. Doneu `AGENTS.md` a l'agent que farà la integració.
3. Seguiu `docs/INSTALACION.md` (operativa concreta) i
   `docs/PRODUCTION_SETUP.md` (contractes i criteris).
4. Copieu `config.example.json` a `config.local.json` i substituïu únicament
   els valors de negoci. Aquest fitxer local està ignorat per Git.
5. Executeu tests, comprovació de salut, prova en sec i, finalment, activeu els
   temporitzadors de `deploy/`.

