<?php
/* ──────────────────────────────────────────────────────────────────────
   APK de la app de Android (mobile-app/)
   ──────────────────────────────────────────────────────────────────────
   Vive en GitHub Releases del repo con nombre fijo (MelonHub.apk), así
   que el enlace no cambia entre versiones: se sube a mano a la release.

   Como en el escritorio (assets/desktop/downloads.php), cada entorno
   descarga la build que apunta a su propia web: la de dev abre
   melonhubdev.melonhub.es (release `movil-dev`). Mientras producción no
   tenga release, androidApkUrl() devuelve '' y la bienvenida del móvil
   sigue ofreciendo la PWA.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/dev-build.php';

function androidApkUrl(): string {
    $tag = MELON_DEV_BUILD ? 'movil-dev' : '';
    if ($tag === '') return '';
    return 'https://github.com/EduConPar/Scrapbook-Online/releases/download/' . $tag . '/MelonHub.apk';
}
