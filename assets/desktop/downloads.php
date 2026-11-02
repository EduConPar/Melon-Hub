<?php
/* ──────────────────────────────────────────────────────────────────────
   Instalables de la app de escritorio (electron/)
   ──────────────────────────────────────────────────────────────────────
   Viven en GitHub Releases del repo (los sube electron/build.sh --publish)
   con nombres fijos, así que el enlace no cambia entre versiones.

   Cada entorno descarga la build que apunta a su propia web: la de dev
   abre melonhubdev.melonhub.es y la de producción, melonhub.es. Mientras
   un entorno no tenga release ($tag vacío) no hay descargas: el haro no
   avisa y download.php responde 404.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/dev-build.php';

/* [formato => URL]. 'windows' y 'linux' son las descargas por defecto de
   cada sistema; 'linux-appimage', la alternativa para Linux sin Flatpak. */
function desktopDownloads(): array {
    $tag = MELON_DEV_BUILD ? 'escritorio-dev' : '';
    if ($tag === '') return [];
    $base = 'https://github.com/EduConPar/Scrapbook-Online/releases/download/' . $tag . '/';
    return [
        'windows'        => $base . 'MelonHub-Setup.exe',
        'linux'          => $base . 'MelonHub.flatpak',
        'linux-appimage' => $base . 'MelonHub.AppImage',
    ];
}

/* Sistemas con descarga (para el aviso del haro). */
function desktopDownloadSystems(): array {
    return array_values(array_intersect(['windows', 'linux'], array_keys(desktopDownloads())));
}
