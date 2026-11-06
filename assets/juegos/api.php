<?php
/* ──────────────────────────────────────────────────────────────────────
   API de la app Juegos (apps/juegos.php). De momento solo Wordelon
   (assets/juegos/wordelon.php):
     GET  ?action=wordelon            → partida de hoy
     POST ?action=wordelon-intento    {intento, dia} → partida + ganados
     GET  ?action=wordelon-discord    → reintenta el mensaje de Discord
     GET  ?action=wordelon-hoy        → resumen de hoy (Estadísticas)
     GET  ?action=wordelon-hoy-img&cols=N&escala=1|2 → PNG de las tarjetas
   Buscaminas (assets/juegos/buscaminas.php):
     GET  ?action=bm-dia               → los 3 tableros de hoy (y reparte
                                          los premios de días pasados)
     POST ?action=bm-dia-abrir         {nivel} → abre/retoma ese tablero
     POST ?action=bm-jugar             {id, tipo: abrir|acorde|bandera, celda}
     GET  ?action=bm-inf               → estado del modo infinito
     GET  ?action=bm-rank-inf          → récords del infinito de todos
     GET  ?action=bm-rank-dia&nivel=N  → 3 mejores tiempos de hoy
     POST ?action=bm-inf-empezar | bm-inf-continuar | bm-inf-retirarse
   Ajedrez (assets/juegos/ajedrez.php y ajedrez-torneos.php):
     GET  ?action=ajz-estado           → saldo, retos, mis partidas, torneos
     GET  ?action=ajz-amigos           → amigos a los que se puede retar
     GET  ?action=ajz-avisos           → para el haro (desktop-base.php)
     POST ?action=ajz-retar            {amigo, min, inc, apuesta}
     POST ?action=ajz-reto-responder   {id, aceptar} → partida
     POST ?action=ajz-reto-cancelar    {id}
     GET  ?action=ajz-partida&id=N     → partida (y señal de vida)
     POST ?action=ajz-mover            {id, uci, n}
     POST ?action=ajz-rendirse         {id}
     POST ?action=ajz-tablas           {id, tipo: ofrecer|aceptar|rechazar}
     GET  ?action=ajz-torneo&id=N      → torneo con su cuadro
     POST ?action=ajz-torneo-crear     {nombre, plazas, min, inc, empieza, entrada, premio1..3}
     POST ?action=ajz-torneo-apuntarse | ajz-torneo-salir | ajz-torneo-cancelar {id}
     GET  ?action=ajz-torneo-discord&id=N → reintenta el anuncio y dice qué contestó
   `dia` es el día que tenía cargado la ventana: si ya ha cambiado (se
   dejó abierta pasada la medianoche) no se apunta el intento al día
   nuevo sin que lo vea, se devuelve la partida nueva con diaNuevo.
   ────────────────────────────────────────────────────────────────────── */
@ini_set('display_errors', '0');
header('Cache-Control: no-cache, no-store, must-revalidate');

/* Lo que las ventanas piden solas (señal de vida de la partida, menú,
   torneo, aviso del haro): si la BD está saturada, un solo reintento
   corto (melon_pdo en assets/config.php). Esperar dormido ocupa un
   proceso PHP más y empeora el atasco; la ventana ya volverá a pedir. */
if (in_array($_GET['action'] ?? '', ['ajz-estado', 'ajz-avisos', 'ajz-partida', 'ajz-torneo'], true)) {
    define('MELON_DB_RAPIDO', true);
}

require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/wordelon.php';
require_once __DIR__ . '/buscaminas.php';
require_once __DIR__ . '/ajedrez.php';

$me = requireAuth();
$st = $pdo->prepare('SELECT id FROM usuarios WHERE user_key = ?');
$st->execute([$me['key']]);
$uid = (int)$st->fetchColumn();
if ($uid <= 0) jsonError('Usuario no encontrado', 403);

$action = $_GET['action'] ?? '';
/* El ajedrez llama cada segundo (partida) o cada pocos (menú, haro): sin
   soltar la sesión, cada petición esperaría a la anterior. */
if (strncmp($action, 'ajz-', 4) === 0) session_write_close();

wordelon_ensure($pdo);
bm_ensure($pdo);
if (strncmp($action, 'ajz-', 4) === 0) ajz_ensure($pdo);
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
            [$jug, $aciertos, $terminados] = wordelon_jugadores($pdo, $dia);
            jsonResponse([
                'ok'        => true,
                'dia'       => $dia,
                'numero'    => wordelon_numero($dia),
                'jugadores' => count($jug),
                'aciertos'  => $aciertos,
                'texto'     => wordelon_resumenTexto($terminados, $aciertos, count($jug) - $terminados),
                'imagen'    => wimg_disponible() && count($jug) > 0,
            ]);
        }

        case 'wordelon-hoy-img': {
            require_once __DIR__ . '/wordelon-img.php';
            [$jug] = wordelon_jugadores($pdo, wordelon_hoy());
            $png = wimg_render($pdo, $jug, wimg_cfgWordelon(WORDELON_INTENTOS), (int)($_GET['cols'] ?? 4), (int)($_GET['escala'] ?? 1));
            if ($png === null) { http_response_code(404); exit; }
            header('Content-Type: image/png');
            header('Content-Length: ' . strlen($png));
            echo $png;
            exit;
        }

        /* ── Buscaminas ── */
        case 'bm-dia':
            jsonResponse(['ok' => true] + bm_dia($pdo, $uid));

        case 'bm-dia-abrir':
        case 'bm-jugar':
        case 'bm-inf-empezar':
        case 'bm-inf-continuar':
        case 'bm-inf-retirarse': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
            $b = jsonBody();
            try {
                if ($action === 'bm-dia-abrir')      $r = ['partida' => bm_abrirDia($pdo, $uid, (int)($b['nivel'] ?? 0))];
                elseif ($action === 'bm-jugar')      $r = bm_jugar($pdo, $uid, (int)($b['id'] ?? 0), (string)($b['tipo'] ?? ''), (int)($b['celda'] ?? -1));
                elseif ($action === 'bm-inf-retirarse') $r = bm_infRetirarse($pdo, $uid);
                else                                 $r = ['infinito' => bm_infAvanzar($pdo, $uid, $action === 'bm-inf-continuar')];
            } catch (InvalidArgumentException $e) {
                jsonResponse(['ok' => false, 'error' => $e->getMessage()]);
            }
            $st = $pdo->prepare('SELECT autismo FROM usuarios WHERE id = ?');
            $st->execute([$uid]);
            jsonResponse(['ok' => true, 'autismo' => (int)$st->fetchColumn()] + $r);
        }

        case 'bm-inf':
            jsonResponse(['ok' => true, 'infinito' => bm_infEstado($pdo, $uid)]);

        case 'bm-rank-inf':
            jsonResponse(['ok' => true] + bm_rankingInf($pdo, $uid));

        case 'bm-rank-dia':
            jsonResponse(['ok' => true] + bm_rankingDia($pdo, $uid, (int)($_GET['nivel'] ?? 0)));

        /* ── Ajedrez ── */
        case 'ajz-estado':
            jsonResponse(['ok' => true] + ajz_estado($pdo, $uid));

        case 'ajz-amigos':
            jsonResponse(['ok' => true, 'amigos' => ajz_amigos($pdo, $uid)]);

        case 'ajz-avisos':
            jsonResponse(['ok' => true] + ajz_avisos($pdo, $uid));

        case 'ajz-partida':
            try { $p = ajz_partidaVer($pdo, $uid, (int)($_GET['id'] ?? 0)); }
            catch (InvalidArgumentException $e) { jsonResponse(['ok' => false, 'error' => $e->getMessage()]); }
            jsonResponse(['ok' => true, 'ahora' => bm_ms(), 'partida' => ajz_vista($pdo, $p, $uid, bm_ms())]);

        case 'ajz-torneo':
            try { $t = ajz_torVer($pdo, $uid, (int)($_GET['id'] ?? 0)); }
            catch (InvalidArgumentException $e) { jsonResponse(['ok' => false, 'error' => $e->getMessage()]); }
            jsonResponse(['ok' => true, 'torneo' => $t, 'autismo' => ajz_saldo($pdo, $uid)]);

        /* Se abre a mano (con sesión) si el anuncio no sale en Discord. */
        case 'ajz-torneo-discord':
            jsonResponse(ajz_torDiscord($pdo, (int)($_GET['id'] ?? 0)));

        case 'ajz-retar':
        case 'ajz-reto-responder':
        case 'ajz-reto-cancelar':
        case 'ajz-mover':
        case 'ajz-rendirse':
        case 'ajz-tablas':
        case 'ajz-torneo-crear':
        case 'ajz-torneo-apuntarse':
        case 'ajz-torneo-salir':
        case 'ajz-torneo-cancelar': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
            $b = jsonBody();
            $id = (int)($b['id'] ?? 0);
            $r = [];
            try {
                switch ($action) {
                    case 'ajz-retar':          $r = ajz_retar($pdo, $uid, (string)($b['amigo'] ?? ''), $b['min'] ?? 0, $b['inc'] ?? 0, $b['apuesta'] ?? 0); break;
                    case 'ajz-reto-responder': $r = ajz_responder($pdo, $uid, $id, !empty($b['aceptar'])); break;
                    case 'ajz-reto-cancelar':  ajz_cancelarReto($pdo, $uid, $id); break;
                    case 'ajz-torneo-crear':   $r = ajz_torCrear($pdo, $uid, $b); break;
                    case 'ajz-torneo-apuntarse': ajz_torApuntarse($pdo, $uid, $id); break;
                    case 'ajz-torneo-salir':   ajz_torSalir($pdo, $uid, $id); break;
                    case 'ajz-torneo-cancelar': ajz_torCancelar($pdo, $uid, $id); break;
                    default:
                        if ($action === 'ajz-mover')        $p = ajz_mover($pdo, $uid, $id, (string)($b['uci'] ?? ''), (int)($b['n'] ?? -1));
                        elseif ($action === 'ajz-rendirse') $p = ajz_rendirse($pdo, $uid, $id);
                        else                                $p = ajz_tablas($pdo, $uid, $id, (string)($b['tipo'] ?? ''));
                        $r = ['ahora' => bm_ms(), 'partida' => ajz_vista($pdo, $p, $uid, bm_ms())];
                }
            } catch (InvalidArgumentException $e) {
                jsonResponse(['ok' => false, 'error' => $e->getMessage(), 'autismo' => ajz_saldo($pdo, $uid)]);
            }
            jsonResponse(['ok' => true, 'autismo' => ajz_saldo($pdo, $uid)] + $r);
        }

        default:
            jsonError('Acción desconocida');
    }
} catch (Throwable $e) {
    error_log('[juegos] ' . $e->getMessage());
    melon_esquemaRevisar($e);
    jsonError('Error del servidor', 500);
}
