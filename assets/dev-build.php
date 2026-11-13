<?php
/* ════════════════════════════════════════════════════════════════════
   FLAG DE BUILD "SOLO DEV"
   --------------------------------------------------------------------
   En la rama `dev` esta constante vale TRUE → se activan las funciones
   exclusivas de desarrollo (banner de Melon Wrapped, icono de mascotas
   en el escritorio, marca de agua "Melon Hub Dev Build", y cualquier
   cosa que se añada a assets/dev/).

   En la rama `main` (producción) debe valer FALSE para que NADA de eso
   aparezca. El merge dev→main conserva el valor de main gracias a la
   regla `merge=ours` de .gitattributes (driver `ours`), así que estos
   cambios NUNCA se "pasan" a main aunque el código viaje en el merge.

   Para añadir más cosas exclusivas de dev: ponlas en assets/dev/ y
   protégelas con  if (defined('MELON_DEV_BUILD') && MELON_DEV_BUILD).
   NO borres este archivo.
   ════════════════════════════════════════════════════════════════════ */
if (!defined('MELON_DEV_BUILD')) {
    define('MELON_DEV_BUILD', false);
}
