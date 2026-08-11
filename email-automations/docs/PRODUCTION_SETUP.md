# Guia de muntatge en producció

Aquest document està pensat perquè un altre agent pugui completar la
instal·lació sense accés al sistema d'origen.

## 1. Preparar el projecte

```bash
python -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/pytest -q
cp config.example.json config.local.json
```

`config.local.json` només ha de contenir configuració no secreta: etiquetes,
destinataris, regles de classificació i límits. Està ignorat per Git.

No feu servir variables d'entorn per copiar configuració d'una altra persona.
Les credencials pròpies s'han d'injectar als adaptadors amb el mecanisme segur
del servidor receptor, fora d'aquest repositori.

## 2. Escollir l'autenticació de Google

Abans d'escriure codi, determineu el tipus de compte:

- Google Workspace: es pot usar un compte de servei amb delegació de domini,
  sempre que l'administrador ho autoritzi.
- Gmail personal: cal un consentiment OAuth de l'usuari i emmagatzemar el token
  resultant fora del repositori.

Permisos mínims orientatius:

- Llistar i llegir els missatges seleccionats: Gmail read-only.
- Modificar etiquetes, només si l'adaptador ho necessita: Gmail modify.
- Enviar resums i alertes: Gmail send.
- Crear fitxers al magatzem escollit: el permís mínim que permeti escriure a la
  carpeta de destinació.
- Llegir subscripcions i vídeos: YouTube read-only.

No concediu permisos de modificació si el sondeig i SQLite són suficients.

## 3. Implementar els adaptadors

Creeu, per exemple, `email_automations/adapters/google.py` i implementeu els
protocols d'`email_automations/ports.py`.

### `Mailbox`

Ha de:

1. Cercar només missatges amb l'etiqueta configurada.
2. Retornar identificador de missatge i de fil.
3. Normalitzar remitent, assumpte, text i data en UTC.
4. Descarregar adjunts només quan el processador ho demani o amb un límit segur.
5. No registrar el cos complet ni els adjunts.

Es recomana començar amb sondeig cada cinc minuts. Com que SQLite recorda cada
identificador, tornar a veure el mateix missatge és segur.

### `Mailer`

Ha d'enviar text pla al destinatari indicat i retornar l'identificador creat
pel servei. La crida ha de propagar errors: el processador deixarà l'element en
estat `failed` perquè es pugui reintentar.

### `FileStore`

Ha de desar bytes amb un nom estable i retornar un identificador o localitzador.
Per evitar duplicats després d'un tall entre desar i completar SQLite:

- useu el nom rebut com a clau idempotent, o
- busqueu un fitxer existent amb aquest nom abans de crear-lo.

### `Summarizer`

Ha de retornar:

- un text curt quan el missatge afecta algun dels `targets`;
- `None` quan és irrellevant.

No introduïu dades personals als prompts de test. Si el sistema és remot,
reviseu tractament de dades i retenció abans d'enviar-hi correus o documents.
Un PDF escanejat necessita un pas OCR separat.

### `YouTubeSource`

Ha d'enumerar les subscripcions de l'usuari autenticat, obtenir els vídeos
recents dels canals i retornar-los ordenats del més nou al més antic. Limiteu
peticions i cachegeu l'identificador de la playlist de pujades de cada canal
per reduir quota.

## 4. Crear tres ordres CLI

Cada ordre ha de:

1. Carregar `config.local.json`.
2. Construir els adaptadors amb credencials obtingudes fora del repositori.
3. Obrir un `StateStore` compartit o un fitxer SQLite per automatització.
4. Instanciar el processador corresponent.
5. Acceptar `--dry-run`.
6. Mostrar només comptadors de `RunResult`, mai contingut sensible.

Ordres recomanades:

```text
python -m email_automations.gmail_invoices.cli
python -m email_automations.gmail_school.cli
python -m email_automations.youtube_to_mail.cli
```

Per a `youtube_to_mail`, afegiu `--initialize-without-sending`. Executeu-lo una
vegada abans d'activar el timer, perquè els vídeos ja existents quedin registrats
sense generar una allau de correus.

## 5. Configurar les regles

### `gmail_invoices`

- Creeu una etiqueta dedicada a Gmail.
- Afegiu una regla per proveïdor amb un `slug` estable.
- Comenceu només amb adjunts PDF.
- Tot proveïdor desconegut o correu sense PDF ha d'anar a revisió.
- Afegiu portals web o descàrregues especials com a connectors independents.

### `gmail_school`

- Creeu una etiqueta dedicada.
- Definiu `targets` genèrics i revisables, com curs, grup o tipus d'avís.
- Eviteu reenviar al mateix compte monitoritzat si això pot crear bucles.
- Mantingueu la idempotència per fil, no només per missatge.

### `youtube_to_mail`

- Definiu una finestra curta, normalment entre 24 i 72 hores.
- Inicialitzeu l'estat sense enviar.
- Mantingueu la idempotència pel `video.id`.

## 6. Temporitzadors

Useu el planificador propi del servidor. Comenceu amb:

- Gmail invoices: cada 5 minuts.
- Gmail school: cada 5 minuts.
- YouTube: cada hora.

Cada execució ha de tenir exclusió mútua o acceptar que la reclamació SQLite
impedeixi el doble processament. Configureu un timeout i no solapeu execucions
llargues.

## 7. Operació

- Feu còpia de seguretat consistent dels fitxers SQLite.
- Superviseu elements `failed`, antiguitat de l'última execució i errors
  d'autorització.
- Alerteu si un token deixa de funcionar o si la quota s'esgota.
- Mantingueu un procés documentat per reintentar errors.
- Reviseu periòdicament etiquetes, destinataris i permisos.

## 8. Posada en marxa segura

1. Tests locals.
2. Demo en memòria.
3. Connexió de lectura amb un únic missatge o canal fictici.
4. `--dry-run`.
5. Escriptura en una carpeta de prova i correu a una bústia de prova.
6. Inicialització de YouTube sense enviar.
7. Activació manual d'una execució real.
8. Aprovació del propietari.
9. Activació dels temporitzadors.

