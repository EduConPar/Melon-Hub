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

/* El APK si quien visita está en un Android y no dentro de la propia
   app ("MelonHubApp" en el User-Agent); si no, ''. */
function androidApkUrlForVisitor(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stripos($ua, 'Android') === false || strpos($ua, 'MelonHubApp') !== false) return '';
    return androidApkUrl();
}

/* Con APK, Chrome no debe ofrecer instalar la PWA (su aviso de "Instalar
   app" sale solo por tener manifiesto): cancelar beforeinstallprompt
   lo quita. Va en el <head> de las páginas con manifiesto. */
function suppressPwaInstallPrompt(): void {
    if (androidApkUrlForVisitor() === '') return;
    echo "<script>window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); });</script>\n";
}
