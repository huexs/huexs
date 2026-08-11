# Traducciones

Los textos fuente del plugin están en **español** y envueltos en las funciones i18n de WordPress con el text domain `huexs-google-reviews` (ver decisión D4 en `docs/DECISIONS.md`).

Para generar el catálogo `.pot` y añadir otros idiomas:

```
wp i18n make-pot . languages/huexs-google-reviews.pot --slug=huexs-google-reviews
```
