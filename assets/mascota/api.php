<?php
/**
 * assets/mascota/api.php
 * Endpoint AJAX para el sistema de mascotas virtuales.
 * Acciones: get, feed, set-skin, save-memoria, get-memoria, heartbeat, reset-death
 */

header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');

require_once __DIR__ . '/../../assets/config.php';
require_once __DIR__ . '/../../db.php';
/* `userIdByKey()` vive en theme-helpers — la incluimos porque la
   usamos abajo para resolver $_SESSION['user'] (slug) → user_id (int).
   El api.php es standalone (no se carga desde un wrapper que ya tenga
   este helper en scope). */
require_once __DIR__ . '/../../assets/themes/theme-helpers.php';
require_once __DIR__ . '/foods.php';

/* ── Sesión y autenticación ─────────────────────────────────────── */
session_start();
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$userKey = $_SESSION['user'];
if (!isset($loginUsers[$userKey])) {
    http_response_code(403);
    echo json_encode(['error' => 'Usuario inválido']);
    exit;
}

$userId = userIdByKey($userKey);
if (!$userId) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo obtener user_id']);
    exit;
}

/* ── Auto-migración del esquema ─────────────────────────────────
   Crea las tablas y columnas si no existen. Idempotente — seguro de
   correr en cada request. Útil para clones nuevos del repo o
   instalaciones que aún no han ejecutado el SQL inicial. */
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mascotas` (
            `user_id`      INT NOT NULL,
            `nombre`       VARCHAR(60)  NOT NULL DEFAULT 'Gabriel',
            `skin`         VARCHAR(40)  NOT NULL DEFAULT 'gabriel',
            `hambre`       TINYINT UNSIGNED NOT NULL DEFAULT 80,
            `felicidad`    TINYINT UNSIGNED NOT NULL DEFAULT 80,
            `temperatura`  TINYINT UNSIGNED NOT NULL DEFAULT 80,
            `edad`         INT          NOT NULL DEFAULT 0,
            `viva`         TINYINT(1)   NOT NULL DEFAULT 1,
            `eclosionado`  TINYINT(1)   NOT NULL DEFAULT 0,
            `ultima_vez`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `eclosion_at`  DATETIME     NULL,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mascota_memoria` (
            `user_id`      INT NOT NULL,
            `clave`        VARCHAR(40) NOT NULL,
            `valor`        VARCHAR(255) NOT NULL,
            `guardado_en`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `clave`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Tabla de gustos: cada mascota tiene un valor 0-100 por cada
       alimento del catálogo. Se generan aleatoriamente al crear la
       mascota y NO cambian durante su vida. */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mascota_gustos` (
            `user_id`   INT NOT NULL,
            `alimento`  VARCHAR(40) NOT NULL,
            `valor`     TINYINT UNSIGNED NOT NULL DEFAULT 50,
            `revelado`  TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`, `alimento`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    /* Migración: columna `revelado` para BD que no la tenían. */
    $col = $pdo->query("SHOW COLUMNS FROM `mascota_gustos` LIKE 'revelado'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `mascota_gustos` ADD COLUMN `revelado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `valor`");
    }
    /* Migraciones: añadir columnas nuevas a instalaciones viejas. */
    $col = $pdo->query("SHOW COLUMNS FROM `mascotas` LIKE 'eclosionado'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `mascotas` ADD COLUMN `eclosionado` TINYINT(1) NOT NULL DEFAULT 1 AFTER `viva`");
    }
    $col = $pdo->query("SHOW COLUMNS FROM `mascotas` LIKE 'temperatura'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `mascotas` ADD COLUMN `temperatura` TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER `felicidad`");
    }
    $col = $pdo->query("SHOW COLUMNS FROM `mascotas` LIKE 'eclosion_at'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `mascotas` ADD COLUMN `eclosion_at` DATETIME NULL AFTER `ultima_vez`");
    }
} catch (Throwable $e) {
    /* Devolver el error con traza compacta para diagnosticar rápido en
       el navegador (DevTools → Network → Response). */
    http_response_code(500);
    echo json_encode([
        'error'  => 'Esquema BD: ' . $e->getMessage(),
        'file'   => basename($e->getFile()),
        'line'   => $e->getLine(),
    ]);
    exit;
}

/* ── Router ─────────────────────────────────────────────────────── */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Parsear JSON body si aplica
$body = [];
$raw = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}

try {
    switch ($action) {
        case 'get':          actionGet();        break;
        case 'feed':         actionFeed();       break;
        case 'play':         actionPlay();       break;
        case 'set-skin':     actionSetSkin();    break;
        case 'rename':       actionRename();     break;
        case 'hatch':        actionHatch();      break;
        case 'warm':         actionWarm();       break;
        case 'break-egg':    actionBreakEgg();   break;
        case 'get-foods':    actionGetFoods();   break;
        case 'save-memoria': actionSaveMemoria();break;
        case 'get-memoria':  actionGetMemoria(); break;
        case 'heartbeat':    actionHeartbeat();  break;
        case 'reset-death':  actionResetDeath(); break;
    case 'delete':       actionDelete();     break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Acción desconocida: ' . htmlspecialchars($action)]);
    }
} catch (Throwable $e) {
    /* Cualquier excepción no capturada dentro de una action — devuelve
       JSON en vez del HTML feo de PHP, así el frontend puede mostrar el
       motivo y nosotros podemos depurar viendo Response en DevTools. */
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ]);
}

/* ════════════════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════════════ */

/**
 * Resuelve la skin que se usará para una mascota NUEVA, a partir de la
 * preferencia activa del usuario (gestionada desde la app de Temas en
 * la pestaña "Personalización → Mascotas").
 *
 *   user_settings.active_mascot = id (tienda_items.id)
 *     → tienda_items.slug         = slug
 *       → assets/mascota/skins/{slug}/ debe existir físicamente.
 *
 * Si algo falla (no hay preferencia, slug vacío, carpeta no existe,
 * etc.) caemos a 'gabriel' como skin por defecto — es la que viene
 * con el repo y siempre debería estar disponible.
 */
function resolvePreferredMascotSlug(PDO $pdo, int $userId): string {
    $fallback = 'gabriel';
    try {
        /* Sin mirar `activo`: una skin comprada y quitada luego del
           catálogo de la tienda se conserva. */
        $stmt = $pdo->prepare("
            SELECT t.slug
            FROM user_settings s
            JOIN tienda_items t
              ON t.id = CAST(s.value AS UNSIGNED)
             AND t.categoria = 'mascotas'
            WHERE s.user_id = ? AND s.key_name = 'active_mascot'
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $slug = (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable) {
        /* Cualquier error de BD (tabla user_settings no existe en
           instalaciones viejas, JOIN sin match, etc.) → fallback. */
        $slug = '';
    }
    if ($slug === '') return $fallback;
    /* Sanitización defensiva: solo letras, números, guion y subrayado.
       Evita path traversal si algún slug viniese contaminado. */
    if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) return $fallback;
    $skinDir = dirname(__DIR__, 2) . '/assets/mascota/skins/' . $slug;
    if (!is_dir($skinDir)) return $fallback;
    return $slug;
}

/**
 * Genera valores aleatorios 0-100 para cada alimento del catálogo
 * y los inserta en mascota_gustos. Reemplaza cualquier valor previo
 * (REPLACE INTO) para no duplicar al regenerar.
 */
function generarGustosAleatorios(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("REPLACE INTO mascota_gustos (user_id, alimento, valor) VALUES (?, ?, ?)");
    foreach (MASCOTA_FOODS as $slug => $_meta) {
        /* Uniforme 0-100 — distribuye toda la escala. */
        $stmt->execute([$userId, $slug, random_int(0, 100)]);
    }
}

/**
 * Si la mascota no tiene gustos en BD (compatibilidad con datos
 * antiguos), los genera. Idempotente. */
function asegurarGustos(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mascota_gustos WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ((int)$stmt->fetchColumn() === 0) {
        generarGustosAleatorios($pdo, $userId);
    }
}

/**
 * Crea la mascota con valores por defecto si no existe.
 * Devuelve el array con todos sus campos, con hambre/felicidad
 * recalculados según el tiempo transcurrido desde ultima_vez.
 */
function getMascota(): array {
    global $pdo, $userId;

    $stmt = $pdo->prepare("SELECT * FROM mascotas WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        /* Primera vez (o después de eliminar): insertar como HUEVO.
           - `eclosion_at` se fija a NOW + 3 días → tiempo mínimo de
             incubación. El huevo solo nace cuando ese tiempo pasa Y
             la temperatura > 0.
           - `temperatura` arranca en 80; decae 1/hora. Si llega a 0
             el huevo muere. El usuario lo mantiene caliente con la
             acción `warm`. */
        $preferredSlug = resolvePreferredMascotSlug($pdo, $userId);
        /* nombre = '' — el huevo NO tiene nombre. Se elige cuando
           eclosiona (vía prompt del cliente que llama a `hatch` con
           el nombre). Hasta entonces, los UIs muestran "Huevo" o "🥚". */
        $pdo->prepare("
            INSERT INTO mascotas
              (user_id, nombre, skin, hambre, felicidad, temperatura, edad, viva, eclosionado, eclosion_at)
            VALUES (?, '', ?, 80, 80, 80, 0, 1, 0, DATE_ADD(NOW(), INTERVAL 3 DAY))
        ")->execute([$userId, $preferredSlug]);
        /* Generar gustos aleatorios uniformes 0-100 para cada alimento.
           Quedarán FIJADOS para toda la vida de esta mascota; al
           eliminar+crear nueva mascota se regeneran de cero. */
        generarGustosAleatorios($pdo, $userId);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    /* Backfill defensivo: si por algún motivo (mascota vieja anterior a
       este sistema, fila huérfana) no hay gustos, los generamos ahora. */
    asegurarGustos($pdo, $userId);

    /* ── Calcular decay por tiempo offline ──────────────────────── */
    $now        = time();
    $ultimaVez  = strtotime($row['ultima_vez']);
    $diffSecs   = max(0, $now - $ultimaVez);
    $diffMins   = $diffSecs / 60;
    $isEgg      = !((int)($row['eclosionado'] ?? 1));

    if ($isEgg && $row['viva']) {
        /* ═══ ESTADO HUEVO ═══════════════════════════════════════════
           - Temperatura decae 1 punto/hora. Es la mecánica única.
           - Si llega a 0 → muere de frío.
           - Si pasa eclosion_at Y temperatura > 0 → eclosiona y se
             resetean los stats de mascota (hambre/felicidad 80). */
        $tempDecay = (int)floor($diffMins / 60);
        $newTemp   = max(0, (int)($row['temperatura'] ?? 80) - $tempDecay);

        $newViva        = (int)$row['viva'];
        $newEclosionado = (int)$row['eclosionado'];
        $newHambre      = (int)$row['hambre'];
        $newFelicidad   = (int)$row['felicidad'];

        if ($newTemp <= 0) {
            /* Huevo muerto de frío. */
            $newViva = 0;
        }
        /* No se auto-eclosiona — cuando `segundos_para_eclosion` llegue
           a 0 el cliente mostrará un prompt al usuario para elegir
           nombre, y entonces llamará a `actionHatch`. Esto permite que
           el usuario elija el nombre en el momento del nacimiento. */

        if ($newTemp        !== (int)($row['temperatura'] ?? 80) ||
            $newViva        !== (int)$row['viva']                ||
            $newEclosionado !== (int)$row['eclosionado']         ||
            $newHambre      !== (int)$row['hambre']              ||
            $newFelicidad   !== (int)$row['felicidad']) {

            $pdo->prepare("
                UPDATE mascotas
                SET temperatura=?, viva=?, eclosionado=?, hambre=?, felicidad=?, ultima_vez=NOW()
                WHERE user_id=?
            ")->execute([$newTemp, $newViva, $newEclosionado, $newHambre, $newFelicidad, $userId]);

            $row['temperatura'] = $newTemp;
            $row['viva']        = $newViva;
            $row['eclosionado'] = $newEclosionado;
            $row['hambre']      = $newHambre;
            $row['felicidad']   = $newFelicidad;
        }
    } elseif ($row['viva'] && $diffMins > 0) {
        /* ═══ ESTADO MASCOTA (post-eclosión) ════════════════════════ */
        /* Hambre: -2 por hora offline (=1/30 por minuto). */
        $hungerDecay    = (int)floor($diffMins / 30);
        /* Felicidad: -1 por hora offline si no hubo interacción. */
        $happyDecay     = (int)floor($diffMins / 60);

        $newHambre    = max(0, (int)$row['hambre']    - $hungerDecay);
        $newFelicidad = max(0, (int)$row['felicidad'] - $happyDecay);

        /* Edad: +1 día por cada 24 h reales. */
        $daysPassed = (int)floor($diffSecs / 86400);
        $newEdad    = (int)$row['edad'] + $daysPassed;

        /* Muerte: hambre=0 + 24h sin atención. */
        $newViva = (int)$row['viva'];
        if ($newHambre === 0 && $diffSecs >= 86400) {
            $newViva = 0;
        }

        if ($newHambre    !== (int)$row['hambre']    ||
            $newFelicidad !== (int)$row['felicidad']  ||
            $newEdad      !== (int)$row['edad']       ||
            $newViva      !== (int)$row['viva']) {

            $pdo->prepare("
                UPDATE mascotas
                SET hambre=?, felicidad=?, edad=?, viva=?, ultima_vez=NOW()
                WHERE user_id=?
            ")->execute([$newHambre, $newFelicidad, $newEdad, $newViva, $userId]);

            $row['hambre']    = $newHambre;
            $row['felicidad'] = $newFelicidad;
            $row['edad']      = $newEdad;
            $row['viva']      = $newViva;
        }
    }

    /* Sanitizar tipos */
    $row['user_id']     = (int)$row['user_id'];
    $row['hambre']      = (int)$row['hambre'];
    $row['felicidad']   = (int)$row['felicidad'];
    $row['temperatura'] = (int)($row['temperatura'] ?? 80);
    $row['edad']        = (int)$row['edad'];
    $row['viva']        = (bool)$row['viva'];
    /* `eclosionado` puede no existir en instalaciones que no se han
       migrado todavía — el `??` evita warnings. Por defecto asumimos 1
       (mascota ya eclosionada) para no regresar a usuarios viejos. */
    $row['eclosionado'] = (bool)($row['eclosionado'] ?? 1);
    /* eclosion_at + segundos restantes — útil para el frontend mostrar
       cuenta atrás. Si ya eclosionada, ambos null. */
    if (!$row['eclosionado'] && !empty($row['eclosion_at'])) {
        $row['segundos_para_eclosion'] = max(0, strtotime($row['eclosion_at']) - $now);
    } else {
        $row['segundos_para_eclosion'] = null;
    }

    return $row;
}

/* ════════════════════════════════════════════════════════════════════
   ACCIONES
═══════════════════════════════════════════════════════════════════ */

/** GET — estado completo de la mascota + memoria pendiente */
function actionGet(): void {
    global $pdo, $userId;

    $mascota = getMascota();

    /* ── Preguntas sin responder ─────────────────────────────────── */
    $allKeys = ['comida_favorita','color_favorito','cancion_favorita','juego_favorito','mascota_favorita'];

    $stmt = $pdo->prepare("SELECT clave FROM mascota_memoria WHERE user_id = ?");
    $stmt->execute([$userId]);
    $answered = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'clave');

    $pending = array_values(array_diff($allKeys, $answered));

    /* ── Última app abierta (para comentarios contextuales) ──────── */
    // Se puede ampliar con más contexto en el futuro

    echo json_encode([
        'ok'              => true,
        'mascota'         => $mascota,
        'pending_preguntas' => $pending,
    ]);
}

/** FEED — alimentar a la mascota (+20 hambre, +5 felicidad) */
function actionFeed(): void {
    global $pdo, $userId, $body;

    $mascota = getMascota();
    if (!$mascota['viva']) {
        echo json_encode(['ok' => false, 'error' => 'Tu mascota ha muerto.']);
        return;
    }
    if (!$mascota['eclosionado']) {
        echo json_encode(['ok' => false, 'error' => 'Es un huevo — todavía no come.']);
        return;
    }

    /* Slug del alimento (validar contra catálogo). */
    $alimento = (string)($body['alimento'] ?? '');
    if (!isset(MASCOTA_FOODS[$alimento])) {
        echo json_encode(['ok' => false, 'error' => 'Alimento no reconocido.']);
        return;
    }

    /* Recoger valor de gusto (0-100). */
    $stmt = $pdo->prepare("SELECT valor FROM mascota_gustos WHERE user_id=? AND alimento=?");
    $stmt->execute([$userId, $alimento]);
    $gusto = (int)($stmt->fetchColumn() ?: 50);

    /* Decidir cambios según el gusto. */
    if      ($gusto >= 80) { $deltaHappy =  20; $reaccion = 'love';    }
    else if ($gusto >= 60) { $deltaHappy =  10; $reaccion = 'like';    }
    else if ($gusto >= 40) { $deltaHappy =   3; $reaccion = 'neutral'; }
    else if ($gusto >= 20) { $deltaHappy =  -3; $reaccion = 'dislike'; }
    else                   { $deltaHappy = -10; $reaccion = 'hate';    }

    $newHambre    = min(100, $mascota['hambre']    + 25);
    $newFelicidad = max(0, min(100, $mascota['felicidad'] + $deltaHappy));

    $pdo->prepare("
        UPDATE mascotas SET hambre=?, felicidad=?, ultima_vez=NOW() WHERE user_id=?
    ")->execute([$newHambre, $newFelicidad, $userId]);

    /* Marca el gusto como REVELADO — el usuario ya sabe la reacción
       de esta comida, así que se descubre en la pestaña Gustos. */
    $pdo->prepare("UPDATE mascota_gustos SET revelado=1 WHERE user_id=? AND alimento=?")
        ->execute([$userId, $alimento]);

    echo json_encode([
        'ok'        => true,
        'hambre'    => $newHambre,
        'felicidad' => $newFelicidad,
        'alimento'  => $alimento,
        'gusto'     => $gusto,
        'reaccion'  => $reaccion,
        'nombre'    => MASCOTA_FOODS[$alimento]['nombre'],
        'emoji'     => MASCOTA_FOODS[$alimento]['emoji'],
    ]);
}

/** PLAY — jugar con la mascota (+10 felicidad, -5 hambre por el esfuerzo) */
function actionPlay(): void {
    global $pdo, $userId;

    $mascota = getMascota();
    if (!$mascota['viva']) {
        echo json_encode(['ok' => false, 'error' => 'Tu mascota ha muerto.']);
        return;
    }
    if ($mascota['hambre'] < 15) {
        echo json_encode(['ok' => false, 'error' => 'La mascota tiene demasiada hambre para jugar.']);
        return;
    }

    $newFelicidad = min(100, $mascota['felicidad'] + 10);
    $newHambre    = max(0,   $mascota['hambre']    - 5);

    $pdo->prepare("
        UPDATE mascotas SET felicidad=?, hambre=?, ultima_vez=NOW() WHERE user_id=?
    ")->execute([$newFelicidad, $newHambre, $userId]);

    echo json_encode([
        'ok'        => true,
        'hambre'    => $newHambre,
        'felicidad' => $newFelicidad,
    ]);
}

/** SET-SKIN — DEPRECADO.
 *  La selección de skin se hace ahora desde la app de Temas →
 *  Personalización → Mascotas (assets/personalize/api.php?action=
 *  set-active-mascot). La skin de una mascota viva NO se puede cambiar
 *  — queda fijada al crearla. Para "cambiar" hay que eliminar la
 *  mascota (`?action=delete`) y la próxima se creará con la skin
 *  preferida actual. Mantenemos esta función para no romper clientes
 *  que aún la llamen, pero devuelve error explicativo. */
function actionSetSkin(): void {
    http_response_code(410); /* Gone */
    echo json_encode([
        'ok' => false,
        'error' => 'La skin queda fijada al crear la mascota. Cámbiala desde Temas → Personalización → Mascotas; surtirá efecto en la próxima mascota que crees.',
    ]);
}

/** SAVE-MEMORIA — guardar una respuesta personal de la mascota */
function actionSaveMemoria(): void {
    global $pdo, $userId, $body;

    $clave = trim($body['clave'] ?? '');
    $valor = trim($body['valor'] ?? '');

    $allowedClaves = ['comida_favorita','color_favorito','cancion_favorita','juego_favorito','mascota_favorita'];
    if (!in_array($clave, $allowedClaves, true) || $valor === '') {
        echo json_encode(['ok' => false, 'error' => 'Datos inválidos']);
        return;
    }

    $valor = mb_substr($valor, 0, 200);

    $pdo->prepare("
        INSERT INTO mascota_memoria (user_id, clave, valor)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE valor=VALUES(valor), guardado_en=NOW()
    ")->execute([$userId, $clave, $valor]);

    echo json_encode(['ok' => true, 'clave' => $clave, 'valor' => $valor]);
}

/** GET-MEMORIA — obtener toda la memoria del usuario */
function actionGetMemoria(): void {
    global $pdo, $userId;

    $stmt = $pdo->prepare("SELECT clave, valor FROM mascota_memoria WHERE user_id=?");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // clave => valor

    echo json_encode(['ok' => true, 'memoria' => $rows]);
}

/**
 * HEARTBEAT — ping cada ~5 min desde el engine para:
 *  1. Bajar hambre suavemente durante la sesión activa.
 *  2. Actualizar `ultima_vez` para que el cálculo offline tenga buena referencia.
 *  3. Devolver estado fresco para sincronizar el HUD.
 *
 * Nota: NO matamos a la mascota aquí. La muerte se decide únicamente en
 * `getMascota()` cuando el usuario lleva ≥ 24h sin interactuar con
 * `ultima_vez` (y hambre=0). Durante sesión activa los heartbeats
 * resetean `ultima_vez`, así que la mascota se mantiene viva mientras
 * el usuario tenga el escritorio abierto, aunque tenga hambre 0
 * (la mascota "aguanta" mientras puedes verla; muere si la abandonas).
 */
function actionHeartbeat(): void {
    global $pdo, $userId;

    $mascota = getMascota();
    if (!$mascota['viva']) {
        echo json_encode(['ok' => true, 'mascota' => $mascota]);
        return;
    }

    /* Tasa: -1 hambre cada 30 min de sesión activa.
       Con HEARTBEAT_MS = 5 min, descontamos solo 1 cada 6 heartbeats.
       Para mantener entero, usamos un contador determinístico basado
       en (minuto del día * id usuario) — sucio pero funciona y no
       requiere columna nueva. Alternativa más limpia: añadir
       `heartbeat_counter` a la tabla. */
    $shouldDecay = ((int)floor(time() / 300) % 6 === 0);
    $newHambre   = $shouldDecay ? max(0, $mascota['hambre'] - 1) : $mascota['hambre'];

    $pdo->prepare("
        UPDATE mascotas SET hambre=?, ultima_vez=NOW() WHERE user_id=?
    ")->execute([$newHambre, $userId]);

    $mascota['hambre'] = $newHambre;

    echo json_encode(['ok' => true, 'mascota' => $mascota]);
}

/** RESET-DEATH — revivir la mascota (solo si viva=0, con coste) */
function actionResetDeath(): void {
    global $pdo, $userId;

    $mascota = getMascota();
    if ($mascota['viva']) {
        echo json_encode(['ok' => false, 'error' => 'La mascota no está muerta.']);
        return;
    }

    $pdo->prepare("
        UPDATE mascotas
        SET viva=1, hambre=30, felicidad=20, ultima_vez=NOW()
        WHERE user_id=?
    ")->execute([$userId]);

    echo json_encode(['ok' => true, 'mensaje' => 'Mascota revivida con hambre baja.']);
}

/** DELETE — borra TODA la data de la mascota del usuario (mascota +
 *  memoria). Solo para testeo: en la próxima request a `get` se creará
 *  una nueva mascota en estado huevo. */
function actionDelete(): void {
    global $pdo, $userId;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM mascota_memoria WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM mascota_gustos  WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM mascotas        WHERE user_id = ?")->execute([$userId]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'mensaje' => 'Mascota eliminada.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/** HATCH — eclosiona el huevo y le pone nombre.
 *  Dos usos:
 *    1. Final del ciclo natural (3 días + temperatura > 0): el cliente
 *       detecta `segundos_para_eclosion = 0`, pide nombre al usuario y
 *       llama aquí con `nombre`.
 *    2. Botón DEV de testeo: el cliente la llama directamente, ignorando
 *       el contador. Acepta el flag `dev=true` para saltar el check de
 *       3 días.
 *  En ambos casos: marca eclosionado=1, fija nombre, resetea stats. */
function actionHatch(): void {
    global $pdo, $userId, $body;

    $mascota = getMascota();
    if (!empty($mascota['eclosionado'])) {
        echo json_encode(['ok' => false, 'error' => 'La mascota ya nació.']);
        return;
    }
    if (!$mascota['viva']) {
        echo json_encode(['ok' => false, 'error' => 'El huevo está muerto — no puede eclosionar.']);
        return;
    }

    /* Sin flag dev, requerimos que el tiempo de incubación haya pasado. */
    $isDev = !empty($body['dev']);
    $ready = ($mascota['segundos_para_eclosion'] ?? null) === 0;
    if (!$isDev && !$ready) {
        echo json_encode([
            'ok' => false,
            'error' => 'El huevo aún no está listo para eclosionar.',
            'segundos_para_eclosion' => $mascota['segundos_para_eclosion'] ?? null,
        ]);
        return;
    }

    /* Nombre obligatorio (el momento de la eclosión es cuando se elige). */
    $nombre = trim((string)($body['nombre'] ?? ''));
    if ($nombre === '') {
        echo json_encode(['ok' => false, 'error' => 'Nombre requerido para eclosionar.']);
        return;
    }
    if (mb_strlen($nombre) > 40) $nombre = mb_substr($nombre, 0, 40);

    $pdo->prepare("
        UPDATE mascotas
        SET eclosionado=1, nombre=?, hambre=80, felicidad=80, ultima_vez=NOW()
        WHERE user_id=?
    ")->execute([$nombre, $userId]);

    $mascota['eclosionado'] = true;
    $mascota['nombre']      = $nombre;
    $mascota['hambre']      = 80;
    $mascota['felicidad']   = 80;
    $mascota['segundos_para_eclosion'] = null;

    echo json_encode(['ok' => true, 'mascota' => $mascota]);
}

/** GET-FOODS — catálogo de alimentos + gustos del usuario.
 *  Devuelve `foods` como array ordenado por slug con cada item
 *  `{slug, nombre, emoji, valor}`. Útil para renderizar el picker
 *  y la pestaña de Gustos. */
function actionGetFoods(): void {
    global $pdo, $userId;
    getMascota(); /* asegura que existan gustos (asegurarGustos dentro). */

    $stmt = $pdo->prepare("SELECT alimento, valor, revelado FROM mascota_gustos WHERE user_id=?");
    $stmt->execute([$userId]);
    $byAlim = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byAlim[$r['alimento']] = [
            'valor'    => (int)$r['valor'],
            'revelado' => (bool)$r['revelado'],
        ];
    }
    $list = [];
    foreach (MASCOTA_FOODS as $slug => $meta) {
        $g = $byAlim[$slug] ?? ['valor' => 50, 'revelado' => false];
        $list[] = [
            'slug'     => $slug,
            'nombre'   => $meta['nombre'],
            'emoji'    => $meta['emoji'],
            'valor'    => $g['valor'],
            'revelado' => $g['revelado'],
        ];
    }
    echo json_encode(['ok' => true, 'foods' => $list]);
}

/** BREAK-EGG — el huevo se rompió por caída desde mucha altura.
 *  Marca `viva=0` para el huevo. Solo aplicable mientras NO esté
 *  eclosionado (un mascota viva no se "rompe", muere de hambre). */
function actionBreakEgg(): void {
    global $pdo, $userId;

    $mascota = getMascota();
    if (!empty($mascota['eclosionado'])) {
        echo json_encode(['ok' => false, 'error' => 'La mascota ya nació — no es un huevo.']);
        return;
    }
    if (!$mascota['viva']) {
        echo json_encode(['ok' => true, 'mascota' => $mascota]);
        return;
    }

    $pdo->prepare("UPDATE mascotas SET viva=0, ultima_vez=NOW() WHERE user_id=?")
        ->execute([$userId]);

    $mascota['viva'] = false;
    echo json_encode(['ok' => true, 'mascota' => $mascota]);
}

/** WARM — dar calor al huevo. Sube la temperatura +30 (capada a 100).
 *  Solo aplicable mientras el huevo NO esté eclosionado. */
function actionWarm(): void {
    global $pdo, $userId;

    $mascota = getMascota();
    if (!empty($mascota['eclosionado'])) {
        echo json_encode(['ok' => false, 'error' => 'La mascota ya nació — no necesita calor.']);
        return;
    }
    if (!$mascota['viva']) {
        echo json_encode(['ok' => false, 'error' => 'El huevo se enfrió y murió.']);
        return;
    }

    $newTemp = min(100, (int)$mascota['temperatura'] + 30);
    $pdo->prepare("UPDATE mascotas SET temperatura=?, ultima_vez=NOW() WHERE user_id=?")
        ->execute([$newTemp, $userId]);

    echo json_encode([
        'ok'          => true,
        'temperatura' => $newTemp,
    ]);
}

/** RENAME — cambiar el nombre de la mascota. */
function actionRename(): void {
    global $pdo, $userId, $body;

    $nombre = trim($body['nombre'] ?? '');
    if ($nombre === '') {
        echo json_encode(['ok' => false, 'error' => 'Nombre vacío']);
        return;
    }
    $nombre = mb_substr($nombre, 0, 60);

    $pdo->prepare("UPDATE mascotas SET nombre=? WHERE user_id=?")
        ->execute([$nombre, $userId]);

    echo json_encode(['ok' => true, 'nombre' => $nombre]);
}