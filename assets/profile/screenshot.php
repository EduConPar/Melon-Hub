<?php
/* ──────────────────────────────────────────────────────────────────────
   screenshot.php?id=N — sirve una captura del perfil (perfil2.php)
   ──────────────────────────────────────────────────────────────────────
   Del archivo en uploads/screenshots/ si está; si un despliegue lo ha
   borrado, de la copia de la BD, y de paso vuelve a escribir el archivo.
   Sin sesión: se ven en el perfil de cualquiera, como las fotos de perfil.
   ────────────────────────────────────────────────────────────────────── */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

$id = (int)($_GET['id'] ?? 0);
try {
    $st = $pdo->prepare("SELECT file, mime FROM profile_screenshots WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $row = null; }
if (!$row) { http_response_code(404); exit; }

$path = dirname(__DIR__, 2) . '/uploads/screenshots/' . basename($row['file']);
if (!is_file($path)) {
    $st = $pdo->prepare("SELECT data FROM profile_screenshots WHERE id = ?");
    $st->execute([$id]);
    $bin = $st->fetchColumn();
    if (!$bin) { http_response_code(404); exit; }
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
    @file_put_contents($path, $bin);
} else {
    $bin = null;
}
header('Content-Type: ' . $row['mime']);
header('Cache-Control: public, max-age=2592000, immutable');
if ($bin !== null) { echo $bin; exit; }
readfile($path);
