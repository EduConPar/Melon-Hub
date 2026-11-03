<?php
/* ──────────────────────────────────────────────────────────────────────
   link-ack.php — "la app ha abierto este enlace" (ver enlace.php)
   ──────────────────────────────────────────────────────────────────────
   enlace.php pone un código de un solo uso en melonhub://…?n=<código> y
   pregunta aquí si la app de escritorio lo ha recibido; la app
   (electron/links.js) lo avisa con un POST nada más recibirlo. Así la
   página sabe que la app se ha abierto sin fiarse del foco de la
   ventana (con KDE y el Flatpak el navegador no lo pierde y la página
   seguía también a la web).

   POST ?n=<código>  → la app lo ha recibido
   GET  ?n=<código>  → { ok, opened }
   Sin sesión: el código (24 hex al azar) es lo único que hace falta.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$n = (string)($_GET['n'] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/', $n)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS link_acks (
        nonce      CHAR(24) PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->prepare("INSERT IGNORE INTO link_acks (nonce) VALUES (?)")->execute([$n]);
        /* Limpieza: los códigos solo sirven unos segundos. */
        $pdo->exec("DELETE FROM link_acks WHERE created_at < NOW() - INTERVAL 1 HOUR");
        echo json_encode(['ok' => true]);
        exit;
    }
    $st = $pdo->prepare("SELECT 1 FROM link_acks WHERE nonce = ?");
    $st->execute([$n]);
    echo json_encode(['ok' => true, 'opened' => (bool)$st->fetchColumn()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
