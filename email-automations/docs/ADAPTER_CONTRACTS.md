# Contractes i proves dels adaptadors

Els processadors assumeixen aquests comportaments:

| Port | Entrada | Sortida | Garantia necessària |
|---|---|---|---|
| `Mailbox` | etiqueta i límit | missatges normalitzats | IDs estables i dates UTC |
| `Mailer` | correu de text | ID del correu | error explícit si no s'ha enviat |
| `FileStore` | nom, bytes i MIME | localitzador estable | creació idempotent pel nom |
| `Summarizer` | text i objectius | resum o `None` | cap efecte lateral |
| `PdfTextExtractor` | bytes PDF | text | error explícit en PDF corrupte |
| `YouTubeSource` | finestra i límit | vídeos recents | IDs estables i dates UTC |

Per a cada adaptador real, creeu contract tests que comprovin:

1. Normalització correcta.
2. Paginació i límits.
3. Un error remot no es converteix en un fals èxit.
4. Els logs no contenen contingut ni credencials.
5. La repetició d'una escriptura amb la mateixa clau no duplica recursos.

Els adaptadors de `demo_adapters.py` serveixen com a referència mínima, no com
a implementació de producció.

