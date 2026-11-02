<?php
/* ──────────────────────────────────────────────────────────────────────
   download.php — redirige al instalable de la app de escritorio
   ──────────────────────────────────────────────────────────────────────
   ?os=windows | linux | linux-appimage. Sin ?os, se deduce del navegador.
   El botón "Descargar" del aviso del haro (desktop-base.php) apunta aquí;
   las URLs reales están en downloads.php.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/downloads.php';

$os = (string)($_GET['os'] ?? '');
if ($os === '') {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stripos($ua, 'Windows') !== false)                                    $os = 'windows';
    elseif (stripos($ua, 'Linux') !== false && stripos($ua, 'Android') === false) $os = 'linux';
}

$urls = desktopDownloads();
if ($os === '' || empty($urls[$os])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Todavía no hay app de escritorio de Melon Hub para tu sistema.';
    exit;
}
header('Cache-Control: no-store');
header('Location: ' . $urls[$os], true, 302);
