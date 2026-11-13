<?php
/* ─────────────────────────────────────────────────────────
   CONFIG — cargador de .env + claves de APIs + usuarios + helpers
   ─────────────────────────────────────────────────────────
   - .env (project root) contiene DB_*, SPOTIFY_*, GOOGLE_*
   - env(KEY, DEFAULT) lo expone al resto de la app
   - Las constantes y $loginUsers se definen aquí
   ───────────────────────────────────────────────────────── */
if (!function_exists('env')) {
    function _envLoad(): void {
        if (isset($GLOBALS['_env'])) return;
        $GLOBALS['_env'] = [];
        $file = dirname(__DIR__) . '/.env';
        if (!is_readable($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) continue;
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));
            /* Quitar comentario inline (#) si NO está dentro de comillas */
            if ($val !== '' && $val[0] !== '"' && $val[0] !== "'") {
                $hash = strpos($val, '#');
                if ($hash !== false) $val = rtrim(substr($val, 0, $hash));
            }
            /* Quitar comillas envolventes */
            $len = strlen($val);
            if ($len >= 2 && (($val[0] === '"' && $val[$len-1] === '"') || ($val[0] === "'" && $val[$len-1] === "'"))) {
                $val = substr($val, 1, -1);
            }
            /* Tolerar comillas/espacios sueltos: hostnames y credenciales no
               contienen comillas legítimas — si quedaron por copy-paste,
               las saneamos para evitar errores de DNS y de conexión a BD. */
            $val = trim($val, " \t\"'");
            $GLOBALS['_env'][$key] = $val;
        }
    }
    function env(string $key, $default = null) {
        _envLoad();
        return array_key_exists($key, $GLOBALS['_env']) ? $GLOBALS['_env'][$key] : $default;
    }
}

/* ─── Claves de APIs externas ─── */
define('SPOTIFY_CLIENT_ID',     env('SPOTIFY_CLIENT_ID',     ''));
define('SPOTIFY_CLIENT_SECRET', env('SPOTIFY_CLIENT_SECRET', ''));
define('GOOGLE_CLIENT_ID',      env('GOOGLE_CLIENT_ID',      ''));
/* Tidal (API oficial, credenciales de developer.tidal.com). Client
   credentials → solo catálogo público y playlists públicas. */
define('TIDAL_CLIENT_ID',     env('TIDAL_CLIENT_ID',     ''));
define('TIDAL_CLIENT_SECRET', env('TIDAL_CLIENT_SECRET', ''));
define('TIDAL_COUNTRY',       env('TIDAL_COUNTRY',       'US'));
/* Last.fm — necesario para extraer géneros A NIVEL DE CANCIÓN (tags
   crowdsourced). Si no se configura, el wrapped cae al fallback de
   géneros por artista vía Spotify. Crear key gratis en
   https://www.last.fm/api/account/create */
define('LASTFM_API_KEY',      env('LASTFM_API_KEY',      ''));
/* TMDB — pósters de pelis y series cuando la foto de un item de lista se
   cae (assets/profile/cover-helpers.php). Vale la "API Key" o el "API
   Read Access Token". Gratis en https://www.themoviedb.org/settings/api */
define('TMDB_API_KEY',        env('TMDB_API_KEY',        ''));

/* ─── Conexión a BD (la usa db.php) ─── */
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'scrapbook_melon'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

/* ─── Una sola conexión a la BD por petición ───
   La abre quien la pida primero (ensureLoginUsers, aquí abajo, o db.php)
   y se queda en $GLOBALS['pdo'] para todos. Antes ensureLoginUsers abría
   la suya, la cerraba, y db.php abría otra: dos conexiones por petición,
   el doble de lo necesario contra el tope de la cuenta en Hostinger
   (conexiones a la vez y por hora; ver .claude/notas/rendimiento.md).

   Reintento corto con backoff: en hosting compartido (LiteSpeed +
   CloudLinux/LVE) el connect puede fallar con
   SQLSTATE[HY000] [2002] Operation not permitted cuando la cuenta roza
   su tope de procesos/conexiones (un EPERM en el connect(), no una
   credencial mala). Es transitorio y unos cientos de ms lo salvan.
   Esperas: ~1.85 s en el peor caso. Con 450 ms no bastaba (la ráfaga de
   un cambio de interfaz recarga el shell y todas las apps a la vez).

   Las peticiones que se repiten solas cada pocos segundos (sondeos)
   definen MELON_DB_RAPIDO antes de incluir esto: un solo reintento
   corto. Si la BD está saturada, esperar dormido ocupa un proceso PHP
   más y empeora el atasco; el sondeo ya volverá a preguntar.
   Si falla, no se reintenta otra vez en la misma petición (db.php lo
   pediría de nuevo tras ensureLoginUsers y esperaría el doble). */
function melon_pdo(): ?PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    if (isset($GLOBALS['_melonPdoError'])) return null;
    $esperas = defined('MELON_DB_RAPIDO') ? [100000] : [100000, 250000, 500000, 1000000];
    $ultimo  = '';
    for ($intento = 0; $intento <= count($esperas); $intento++) {
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                           DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $GLOBALS['pdo'] = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            $ultimo = $e->getMessage();
            if ($intento < count($esperas)) usleep($esperas[$intento]);
        }
    }
    /* El detalle va al log del servidor, NUNCA al navegador: lleva host,
       usuario y ruta del socket. */
    error_log('[db] conexión fallida tras ' . (count($esperas) + 1) . ' intentos: ' . $ultimo);
    $GLOBALS['_melonPdoError'] = $ultimo;
    return null;
}

/* ─── $loginUsers viene de la tabla `usuarios` (incluye hash bcrypt) ───
   La contraseña no se valida con ===; usar password_verify($plain, $hash).
   Se rellena al incluir este archivo, con la conexión compartida de
   melon_pdo() (la misma que usará luego db.php). */
$loginUsers = [];
$GLOBALS['_loginUsersError'] = null;
function ensureLoginUsers(): void {
    global $loginUsers;
    if (!empty($loginUsers)) return;
    try {
        /* Sin $loginUsers la app degrada a la pantalla de "No hay
           usuarios para mostrar": melon_pdo() ya reintenta. */
        $pdo = melon_pdo();
        if (!$pdo) throw new RuntimeException('sin conexión: ' . ($GLOBALS['_melonPdoError'] ?? ''));
        $stmt = $pdo->query("SELECT id, user_key, label, password FROM usuarios ORDER BY id ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /* Auto-backfill: si hay filas sin user_key (legacy o import parcial),
           generamos uno con 'user{id}' y lo persistimos. Una sola vez. */
        $needsBackfill = false;
        foreach ($rows as $r) {
            if (empty($r['user_key'])) { $needsBackfill = true; break; }
        }
        if ($needsBackfill) {
            try {
                $upd = $pdo->prepare("UPDATE usuarios SET user_key = ? WHERE id = ?");
                foreach ($rows as &$r) {
                    if (empty($r['user_key'])) {
                        $r['user_key'] = 'user' . (int)$r['id'];
                        $upd->execute([$r['user_key'], (int)$r['id']]);
                    }
                }
                unset($r);
            } catch (Throwable $e) { /* sin permiso UPDATE → seguimos con fallback en memoria */ }
        }

        foreach ($rows as $r) {
            $key = $r['user_key'] ?: ('user' . (int)$r['id']);
            $loginUsers[$key] = [
                'label'    => $r['label'] ?: $key,
                'password' => $r['password'],
            ];
        }
        if (empty($loginUsers)) {
            $GLOBALS['_loginUsersError'] = 'La tabla `usuarios` está vacía. Importa el SQL.';
        }
    } catch (Throwable $e) {
        /* El detalle va al log, no a la pantalla de login: exponía
           SQLSTATE, host y usuario de BD a cualquier visitante capaz de
           provocar el fallo. */
        error_log('[config] ensureLoginUsers falló: ' . $e->getMessage());
        $GLOBALS['_loginUsersError'] = 'No se puede conectar con la base de datos. Recarga en unos segundos.';
    }
}
ensureLoginUsers();

/* Melon Hub de dev: solo para quien tiene la tag dev (usuarios.es_dev).
   Sin sesión no hace nada; fuera de la rama dev, tampoco. */
require_once __DIR__ . '/php/dev-acceso.php';
melon_puertaDev();

function getUserImage($label)
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    $root = dirname(__DIR__);
    /* 1) Carpeta de subidas de usuario — caché en filesystem. Si existe,
       sirve directamente. */
    /* ?v=<fecha del archivo>: la foto nueva se guarda con el MISMO nombre
       (<Nombre>.<ext>), así que sin esto el navegador y sobre todo la app
       de escritorio seguían con la de antes en caché (muchas veces la del
       registro, que se sube sin recortar: "sale entera, no como la
       recorté"). Al cambiar la foto cambia la URL. Quien necesite la ruta
       del archivo, que quite lo de detrás del "?" (strtok($r, '?')). */
    foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $ext) {
        $f = $root . "/uploads/profile-photos/{$safe}.{$ext}";
        if (file_exists($f)) {
            return "uploads/profile-photos/{$safe}.{$ext}?v=" . @filemtime($f);
        }
    }
    /* 2) Si el filesystem se borró (deploy de Hostinger limpiando uploads/),
       restauramos desde la BD que SÍ persiste. usuarios.photo_data tiene
       el binario y photo_ext la extensión original. */
    $restored = _restorePhotoFromDb($label, $safe);
    if ($restored !== '') return $restored . '?v=' . @filemtime($root . '/' . $restored);
    /* 3) Fallback al seed del repo (Capi.jpg / Angie.jpg shipped). */
    foreach (['jpg', 'jpeg', 'png', 'gif'] as $ext) {
        if (file_exists(__DIR__ . "/img/{$safe}.{$ext}")) {
            return "assets/img/{$safe}.{$ext}";
        }
    }
    return '';
}

/* Migración idempotente: añade las columnas blob a `usuarios` y
   `themes` si no existen. Sin esto, los UPDATE en los endpoints fallan
   silenciosamente (try/catch) y los assets solo viven en /uploads/ o
   /assets/img/, donde un `git reset --hard` del deploy los borra.
   La fuente de verdad debe ser la BD; el filesystem es caché.

   `usuarios`:
     - photo_data       + photo_ext       — foto de perfil
     - wallpaper_data   + wallpaper_ext   — wallpaper global del usuario
     - start_icon_data  + start_icon_ext  — icono inicio global del usuario
   `themes`:
     - wallpaper_data   + wallpaper_ext   — wallpaper específico del tema
     - start_icon_data  + start_icon_ext  — icono inicio específico del tema
*/
function _ensureUserPhotoColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema): antes eran 10
       SHOW COLUMNS en cada petición que pintara a un usuario sin la foto
       en disco (p. ej. cada vez que se pedía una partida de ajedrez). */
    require_once __DIR__ . '/php/esquema.php';
    melon_esquema($pdo, 'fotos-usuarios', __FILE__, '_ensureUserPhotoColumnsYa');
}
function _ensureUserPhotoColumnsYa(PDO $pdo): void
{
    $cols = [
        ['usuarios', 'photo_data',       'LONGBLOB',    'label'],
        ['usuarios', 'photo_ext',        'VARCHAR(8)',  'photo_data'],
        ['usuarios', 'wallpaper_data',   'LONGBLOB',    'photo_ext'],
        ['usuarios', 'wallpaper_ext',    'VARCHAR(8)',  'wallpaper_data'],
        ['usuarios', 'start_icon_data',  'LONGBLOB',    'wallpaper_ext'],
        ['usuarios', 'start_icon_ext',   'VARCHAR(8)',  'start_icon_data'],
        ['themes',   'wallpaper_data',   'LONGBLOB',    'wallpaper'],
        ['themes',   'wallpaper_ext',    'VARCHAR(8)',  'wallpaper_data'],
        ['themes',   'start_icon_data',  'LONGBLOB',    'start_icon'],
        ['themes',   'start_icon_ext',   'VARCHAR(8)',  'start_icon_data'],
    ];
    foreach ($cols as [$tbl, $col, $type, $after]) {
        try {
            $exists = $pdo->query("SHOW COLUMNS FROM $tbl LIKE " . $pdo->quote($col))->fetch();
            if (!$exists) {
                $pdo->exec("ALTER TABLE $tbl ADD COLUMN $col $type NULL AFTER $after");
            }
        } catch (Throwable $_) {
            /* BD legacy sin permisos de ALTER, columna `after` ausente, o
               cualquier otro fallo: seguimos con las demás. */
        }
    }
}

/* Lee el blob de la foto desde usuarios.photo_data y lo escribe al
   filesystem como caché. Devuelve la ruta relativa o '' si no hay
   blob / la columna no existe / falla la escritura. */
function _restorePhotoFromDb(string $label, string $safe): string
{
    /* Quien no tiene foto ni en disco ni en la BD (no ha subido ninguna)
       pasaba por aquí en CADA petición que lo pintara: una consulta por
       usuario y petición (un torneo de 32, 32 consultas). Se recuerda 10
       min con un archivo vacío; al subir una foto se escribe su archivo,
       que se encuentra antes (getUserImage, paso 1). */
    static $sinFoto = [];
    if (isset($sinFoto[$safe])) return '';
    $marca = dirname(__DIR__) . '/uploads/profile-photos/.sin-foto-' . $safe;
    if (@filemtime($marca) > time() - 600) { $sinFoto[$safe] = true; return ''; }
    try {
        require_once dirname(__DIR__) . '/db.php';
        global $pdo;
        if (!isset($pdo)) return '';
        _ensureUserPhotoColumns($pdo);
        $stmt = $pdo->prepare("SELECT photo_data, photo_ext FROM usuarios WHERE label = ? AND photo_data IS NOT NULL LIMIT 1");
        $stmt->execute([$label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['photo_data']) || empty($row['photo_ext'])) {
            $sinFoto[$safe] = true;
            if (is_dir(dirname($marca))) @touch($marca);
            return '';
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($row['photo_ext']));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) return '';
        $dir = dirname(__DIR__) . '/uploads/profile-photos';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return '';
        $path = $dir . '/' . $safe . '.' . $ext;
        if (@file_put_contents($path, $row['photo_data']) === false) return '';
        return "uploads/profile-photos/{$safe}.{$ext}";
    } catch (Throwable $e) {
        /* Migración pendiente (no existe la columna) o BD no disponible. */
        return '';
    }
}

function getUserWallpaper($label)
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    foreach ([$safe, strtolower($safe)] as $name) {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
            if (file_exists(__DIR__ . "/img/wallpapers/{$name}-wallpaper.{$ext}")) {
                return "assets/img/wallpapers/{$name}-wallpaper.{$ext}";
            }
        }
    }
    /* Fallback BD: si el filesystem se borró en un deploy, restauramos
       desde usuarios.wallpaper_data al filesystem y devolvemos la ruta. */
    $restored = _restoreUserAssetFromDb(
        $label,
        strtolower($safe),
        __DIR__ . '/img/wallpapers',
        'assets/img/wallpapers',
        '-wallpaper',
        'wallpaper_data',
        'wallpaper_ext'
    );
    if ($restored !== '') return $restored;
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        if (file_exists(__DIR__ . "/img/wallpapers/base-wallpaper.{$ext}")) {
            return "assets/img/wallpapers/base-wallpaper.{$ext}";
        }
    }
    return '';
}

/* Lee un blob (wallpaper/start_icon) desde `usuarios` y lo escribe al
   filesystem como caché. Genérico: el llamador especifica las columnas
   y la carpeta destino. Devuelve la ruta relativa o '' si no se pudo. */
function _restoreUserAssetFromDb(string $label, string $safeLower, string $dirAbs,
                                  string $dirRel, string $suffix,
                                  string $dataCol, string $extCol): string
{
    try {
        require_once dirname(__DIR__) . '/db.php';
        global $pdo;
        if (!isset($pdo)) return '';
        _ensureUserPhotoColumns($pdo);
        $stmt = $pdo->prepare("SELECT $dataCol AS d, $extCol AS e FROM usuarios
                               WHERE label = ? AND $dataCol IS NOT NULL LIMIT 1");
        $stmt->execute([$label]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['d']) || empty($row['e'])) return '';
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($row['e']));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp','svg'], true)) return '';
        if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0775, true)) return '';
        $path = $dirAbs . '/' . $safeLower . $suffix . '.' . $ext;
        if (@file_put_contents($path, $row['d']) === false) return '';
        return $dirRel . '/' . $safeLower . $suffix . '.' . $ext;
    } catch (Throwable $_) {
        return '';
    }
}

/* Fondo por defecto de la app (base-wallpaper). Se usa cuando un tema activo
   no define un fondo propio. Devuelve ruta relativa o ''. */
function defaultWallpaper()
{
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
        if (file_exists(__DIR__ . "/img/wallpapers/base-wallpaper.{$ext}")) {
            return "assets/img/wallpapers/base-wallpaper.{$ext}";
        }
    }
    return '';
}

function getUserStartIcon($label)
{
    $safe = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $label));
    foreach (['png', 'svg', 'webp', 'jpg', 'jpeg', 'gif'] as $ext) {
        if (file_exists(__DIR__ . "/img/start-icons/{$safe}-start-icon.{$ext}")) {
            return "assets/img/start-icons/{$safe}-start-icon.{$ext}";
        }
    }
    /* Fallback BD: restaura desde usuarios.start_icon_data si el archivo
       del filesystem desapareció por el deploy. */
    $restored = _restoreUserAssetFromDb(
        $label, $safe,
        __DIR__ . '/img/start-icons',
        'assets/img/start-icons',
        '-start-icon',
        'start_icon_data',
        'start_icon_ext'
    );
    return $restored;
}

/* Self-heal del stub `desktops/<label>-desktop.php`. Si por cualquier
   motivo (rollback erróneo de register-user.php en versiones antiguas,
   File Manager borrado, etc.) el archivo no existe pero el usuario sí
   está en BD, lo regeneramos al vuelo justo antes del redirect. Sin
   esto el login redirige a un 404 y el usuario queda sin escritorio
   utilizable hasta que un admin lo cree a mano. Idempotente. */
function ensureDesktopStub(string $label): void {
    $safe = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $label));
    if ($safe === '') return;
    $path = dirname(__DIR__) . '/desktops/' . $safe . '-desktop.php';
    if (file_exists($path)) return;
    $stub = "<?php \$desktopLabel = " . var_export($label, true)
          . "; require __DIR__ . '/../desktop-base.php';\n";
    @file_put_contents($path, $stub);
}
