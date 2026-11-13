<?php
/* ──────────────────────────────────────────────────────────────────────
   POST: tiempo en cada app desde el último envío (assets/js/uso-apps.js).
   Cuerpo: {"p":"web|electron|movil|app-movil",
            "a":{"<app>":{"s":segundos,"n":aperturas}},
            "h":{"<hora 0-23>":segundos}, "d":"AAAA-MM-DD"}
   Llega como mucho cada 5 min por escritorio abierto (y una vez al
   cerrar, por sendBeacon). Se recorta a lo que cabe en ese rato para que
   un cliente roto o tramposo no infle las cifras.
   ────────────────────────────────────────────────────────────────────── */
define('MELON_DB_RAPIDO', true);
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/comun.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Solo POST', 405);
$u = requireAuth();
/* El cuerpo puede ser lo único que manda sendBeacon: no hay más que
   leer y la sesión no se toca, así que se suelta ya (no bloquea las
   demás peticiones del mismo usuario). */
session_write_close();

$d = jsonBody();
$plat = (string)($d['p'] ?? 'web');
if (!in_array($plat, ['web', 'electron', 'movil', 'app-movil'], true)) $plat = 'web';
$apps  = is_array($d['a'] ?? null) ? $d['a'] : [];
$horas = is_array($d['h'] ?? null) ? $d['h'] : [];

/* Tope por envío: 15 min de tiempo y 60 aperturas (el cliente manda cada
   5; el margen cubre una pestaña dormida que despierta). */
const USO_MAX_S = 900;
$total = 0;
$filasApps = [];
foreach ($apps as $k => $v) {
    $k = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$k));
    if ($k === '' || strlen($k) > 32 || !is_array($v)) continue;
    $s = max(0, min(USO_MAX_S, (int)($v['s'] ?? 0)));
    $n = max(0, min(60, (int)($v['n'] ?? 0)));
    if ($s === 0 && $n === 0) continue;
    if ($total + $s > USO_MAX_S) $s = max(0, USO_MAX_S - $total);
    $total += $s;
    if ($s === 0 && $n === 0) continue;   /* se ha quedado sin tiempo por el tope */
    $filasApps[] = [$k, $s, $n];
    if (count($filasApps) >= 30) break;
}
$filasHoras = [];
$totalH = 0;
foreach ($horas as $h => $s) {
    $h = (int)$h; $s = max(0, min(USO_MAX_S, (int)$s));
    if ($h < 0 || $h > 23 || $s === 0) continue;
    if ($totalH + $s > USO_MAX_S) $s = max(0, USO_MAX_S - $totalH);
    $totalH += $s;
    if ($s) $filasHoras[] = [$h, $s];
}
if (!$filasApps && !$filasHoras) jsonResponse(['ok' => true]);

try {
    melon_usoEsquema($pdo);
    $id = (int)userIdByKeyUso($pdo, $u['key']);
    if (!$id) jsonError('Usuario no encontrado', 404);
    /* El día va en la hora local del navegador (como las horas de
       uso_horas): PHP aquí no fija zona horaria y a medianoche se
       partiría el día por otro sitio. Solo se acepta a ±1 día del
       servidor. */
    $dia = date('Y-m-d');
    $diaCli = (string)($d['d'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $diaCli) && abs(strtotime($diaCli) - strtotime($dia)) <= 86400) $dia = $diaCli;
    if ($filasApps) {
        $st = $pdo->prepare("INSERT INTO uso_apps (user_id, dia, app, plataforma, segundos, aperturas)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE segundos = segundos + VALUES(segundos),
                                                     aperturas = aperturas + VALUES(aperturas)");
        foreach ($filasApps as [$k, $s, $n]) $st->execute([$id, $dia, $k, $plat, $s, $n]);
    }
    if ($filasHoras) {
        $st = $pdo->prepare("INSERT INTO uso_horas (user_id, dia, hora, segundos) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE segundos = segundos + VALUES(segundos)");
        foreach ($filasHoras as [$h, $s]) $st->execute([$id, $dia, $h, $s]);
    }
    jsonResponse(['ok' => true]);
} catch (Throwable $e) {
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquemaRevisar($e);
    error_log('[uso] ' . $e->getMessage());
    jsonError('Error al guardar', 500);
}

function userIdByKeyUso(PDO $pdo, string $key): int {
    $st = $pdo->prepare("SELECT id FROM usuarios WHERE user_key = ? OR CONCAT('user', id) = ? LIMIT 1");
    $st->execute([$key, $key]);
    return (int)$st->fetchColumn();
}
