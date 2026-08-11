# Handoff: tres automatitzacions de correu

## Objectiu

Convertir aquesta base genèrica en una instal·lació pròpia amb:

- `gmail_invoices`
- `gmail_school`
- `youtube_to_mail`

El codi de domini i la persistència ja són al paquet. Falta connectar-los als
comptes i serveis de la persona receptora.

## Estat actual

- Els tres processadors són executables amb adaptadors en memòria.
- SQLite implementa reclamació amb lloguer, reintents i idempotència duradora.
- Hi ha tests per a èxit, duplicats, revisió i errors recuperables.
- La configuració d'exemple només conté dominis reservats `.invalid`.
- No s'ha copiat cap configuració, regla comercial, credencial, identificador,
  adreça, nom personal, base de dades ni ruta de la instal·lació d'origen.
- No s'ha fet cap desplegament.

## Decisions importants

1. Els noms compartits són genèrics; no inclouen prefixos de cap projecte.
2. El domini depèn de protocols (`ports.py`), no de les SDK de Google ni d'un
   proveïdor d'IA concret.
3. Per defecte es recomana sondeig programat. Pub/Sub és una ampliació opcional,
   no un requisit per començar.
4. Un element només queda completat després que acabin tots els efectes externs.
   Si falla, queda reintentable.
5. Els secrets han de viure fora del repositori. El paquet no prescriu ni
   distribueix valors secrets.
6. `gmail_invoices` només implementa adjunts PDF genèrics. Descàrregues des de
   portals de proveïdors són connectors separats i s'han d'afegir cas per cas.
7. `gmail_school` rep un `Summarizer`; el receptor tria un model local o remot.
   La base no incorpora cap clau ni client d'un proveïdor.

## Estat de la integració (agost 2026)

Fet en aquest repositori:

- Adaptadors reals de Gmail (`Mailbox`/`Mailer`), Drive i disc local
  (`FileStore`), pypdf (`PdfTextExtractor`), Claude i paraules clau
  (`Summarizer`) i YouTube Data API (`YouTubeSource`), amb contract tests.
- Credencials amb permisos mínims: OAuth d'usuari o compte de servei amb
  delegació, sempre des de rutes locals fora del repositori.
- Tres ordres CLI amb `--dry-run`, `--health-check` i, per a YouTube,
  `--initialize-without-sending`.
- Unitats systemd d'exemple a `deploy/` i guia operativa a
  `docs/INSTALACION.md`.

Pendent del propietari (requereix accés al compte):

1. Decidir si el compte és Google Workspace o Gmail personal.
2. Crear les credencials pròpies i executar `python -m tools.authorize`.
3. Crear les etiquetes de Gmail i definir regles de proveïdor i objectius.
4. Executar la comprovació de salut i la prova en sec, revisar-ne el resultat i
   només llavors activar els temporitzadors.
5. Programar la còpia de seguretat del SQLite i les alertes.

## Feina original prevista per a l'agent receptor

1. Confirmar si el compte és Google Workspace o Gmail personal.
2. Crear l'autenticació pròpia amb els permisos mínims descrits a
   `docs/PRODUCTION_SETUP.md`.
3. Implementar:
   - `Mailbox` i `Mailer` per Gmail.
   - `FileStore` per Drive o un altre magatzem.
   - `Summarizer` per al sistema de resum escollit.
   - `YouTubeSource` per YouTube Data API.
4. Construir tres ordres CLI que carreguin configuració local, obrin SQLite,
   instanciïn adaptadors i invoquin cada processador.
5. Fer una execució `dry-run`, revisar resultats i activar temporitzadors.
6. Afegir alertes i còpia de seguretat de la base de dades.

## Criteris d'acceptació

- Cap secret o dada personal apareix a Git, logs o documentació.
- Repetir una execució no duplica fitxers ni correus.
- Un error temporal es pot reintentar.
- `gmail_invoices` deriva proveïdors o missatges desconeguts a revisió.
- `gmail_school` no resumeix dues vegades el mateix fil.
- `youtube_to_mail` no notifica dues vegades el mateix vídeo.
- Les tres ordres tenen mode sec i una comprovació de salut.

## Riscos i punts oberts

- Gmail personal i Workspace tenen fluxos d'autorització diferents.
- La delegació de domini només és aplicable a Workspace.
- La quota de YouTube obliga a limitar freqüència i nombre de canals.
- L'extracció de text de PDF escanejat necessita OCR, que no està inclòs.
- Les regles de proveïdors i els criteris escolars són necessàriament personals;
  cal definir-los a la configuració local.

## Skills suggerides

- `tdd`: implementar cada adaptador amb contract tests abans de connectar-lo.
- `review`: revisar permisos, idempotència i filtracions abans de producció.
- `diagnose`: investigar problemes d'OAuth, quotes o missatges no processats.

