<?php
/* ──────────────────────────────────────────────────────────────────────
   API de la app Juegos (apps/juegos.php). De momento solo Wordelon
   (assets/juegos/wordelon.php):
     GET  ?action=wordelon            → partida de hoy
     POST ?action=wordelon-intento    {intento, dia} → partida + ganados
     GET  ?action=wordelon-discord    → reintenta el mensaje de Discord
     GET  ?action=wordelon-hoy        → resumen de hoy (Estadísticas)
     GET  ?action=wordelon-hoy-img&cols=N&escala=1|2 → PNG de las tarjetas
   `dia` es el día que tenía cargado la ventana: si ya ha cambiado (se
   dejó abierta pasada la medianoche) no se apunta el intento al día
   nuevo sin que lo vea, se devuelve la partida nueva con diaNuevo.
   ────────────────────────────────────────────────────────────────────── */
@ini_set('display_errors', '0');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/wordelon.php';

$me = requireAuth();
$st = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
$st->execute([$me['key']]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) jsonError('Usuario no encontrado', 403);

wordelon_ensure($pdo);

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'wordelon':
            jsonResponse(['ok' => true, 'estado' => wordelon_estado($pdo, $uid)]);

        case 'wordelon-intento': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
            $b = jsonBody();
            if (isset($b['dia']) && $b['dia'] !== wordelon_hoy()) {
                jsonResponse(['ok' => false, 'diaNuevo' => true, 'estado' => wordelon_estado($pdo, $uid)]);
            }
            try {
                [$estado, $ganados] = wordelon_intentar($pdo, $uid, (string)($b['intento'] ?? ''));
            } catch (InvalidArgumentException $e) {
                jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
            }
            $st = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ?');
            $st->execute([$uid]);
            jsonResponse(['ok' => true, 'estado' => $estado, 'ganados' => $ganados, 'autismo' => (int)$st->fetchColumn()]);
        }

        /* Reintenta el mensaje del día en Discord y dice qué contestó.
           Se abre a mano en el navegador (con sesión) cuando el bot no
           publica: si se arregló el permiso, lo publica en ese momento. */
        case 'wordelon-discord':
            jsonResponse(wordelon_discordResumen($pdo, wordelon_hoy()));

        /* Estadísticas de la app: cómo ha ido el Wordelon de hoy. La
           imagen es la misma que la del bot (wordelon-img.php), a 1x y con
           las tarjetas por fila que quepan en la ventana. */
        case 'wordelon-hoy': {
            require_once __DIR__ . '/wordelon-img.php';
            $dia = wordelon_hoy();
            [$jug, $aciertos] = wordelon_jugadores($pdo, $dia);
            jsonResponse([
                'ok'        => true,
                'dia'       => $dia,
                'numero'    => wordelon_numero($dia),
                'jugadores' => count($jug),
                'aciertos'  => $aciertos,
                'texto'     => wordelon_resumenTexto(count($jug), $aciertos),
                'imagen'    => wimg_disponible() && count($jug) > 0,
            ]);
        }

        case 'wordelon-hoy-img': {
            require_once __DIR__ . '/wordelon-img.php';
            [$jug] = wordelon_jugadores($pdo, wordelon_hoy());
            $png = wimg_render($pdo, $jug, WORDELON_INTENTOS, (int)($_GET['cols'] ?? 4), (int)($_GET['escala'] ?? 1));
            if ($png === null) { http_response_code(404); exit; }
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($png));
            echo $png;
            exit;
        }

        default:
            jsonError('Acción desconocida');
    }
} catch (Throwable $e) {
    error_log('[juegos] ' . $e->getMessage());
    jsonError('Error del servidor', 500);
}
