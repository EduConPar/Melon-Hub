<?php
/**
 * Helpers para el sistema de temas custom.
 *
 * Arquitectura:
 *  - Todos los CSS de la app (base, perfil, reproductor, melonarchive,
 *    calendario, themes) usan var(--token) en lugar de literales hex.
 *  - tokens.css define los valores por defecto (Win98).
 *  - themes.css redefine los tokens para body.capi / body.angie.
 *  - Cada tema custom del usuario produce un archivo en
 *    assets/themes/<themeName>-<userLabel>.css con un único bloque
 *    body.<themeName>-<userLabel> { --token: valor; ... }
 *  - El body recibe la clase <themeName>-<userLabel> y los tokens
 *    cascadean a TODA la UI sin reglas selector-específicas.
 */

/* Lista de tokens editables desde la app de Temas. Debe coincidir con
   COLOR_DEFS en apps/temas.php y con tokens.css. */
$THEME_COLOR_KEYS = [
    /* Superficies */
    'winBg', 'winBodyBg', 'surfaceDeep', 'insetBg',
    /* Inputs */
    'inputBg', 'inputText',
    /* Botones */
    'btnBg', 'btnText',
    /* Botón de inicio (taskbar) */
    'startBtnBg', 'startBtnText',
    /* Texto */
    'text', 'textMuted', 'textFaint', 'textInset',
    /* Acento / barra de título */
    'titlebarStart', 'titlebarEnd', 'titlebarText',
    'accent', 'accentText', 'accentDeep',
    /* Iconos de la title-bar (close / minimize / maximize) */
    'titlebarIconColor', 'titlebarIconBg',
    'titlebarIconBezelLight', 'titlebarIconBezelDark',
    /* Bordes */
    'border',
    /* Bezels */
    'bezelLight1', 'bezelLight2', 'bezelDark1', 'bezelDark2',
    /* Escritorio */
    'desktopBg',
    /* Estados */
    'linkText', 'errorText', 'warningBg', 'warningText',
    /* Badges */
    'badgeBg', 'badgeText',
    /* Decorativos */
    'starColor',
    /* Kawaii-only — solo las vars semánticas clave. Las variantes
       internas (rose-50, accent-coral, button-blue, etc.) viven en
       :root de kawaii como detalles de implementación, no se exponen. */
    'hoverBg', 'activeRose', 'inactiveBg', 'linkHover', 'paleRose', 'shadowColor',
];

/* Mapeo key → variable CSS */
$THEME_TOKEN_MAP = [
    'winBg'         => '--win-bg',
    'winBodyBg'     => '--win-body-bg',
    'surfaceDeep'   => '--surface-deep',
    'insetBg'       => '--inset-bg',
    'inputBg'       => '--input-bg',
    'inputText'     => '--input-text',
    'btnBg'         => '--btn-bg',
    'btnText'       => '--btn-text',
    'startBtnBg'    => '--start-btn-bg',
    'startBtnText'  => '--start-btn-text',
    'text'          => '--text',
    'textMuted'     => '--text-muted',
    'textFaint'     => '--text-faint',
    'textInset'     => '--text-inset',
    'titlebarStart' => '--titlebar-start',
    'titlebarEnd'   => '--titlebar-end',
    'titlebarText'  => '--titlebar-text',
    'titlebarIconColor'      => '--titlebar-icon-color',
    'titlebarIconBg'         => '--titlebar-icon-bg',
    'titlebarIconBezelLight' => '--titlebar-icon-bezel-light',
    'titlebarIconBezelDark'  => '--titlebar-icon-bezel-dark',
    'accent'        => '--accent',
    'accentText'    => '--accent-text',
    'accentDeep'    => '--accent-deep',
    'border'        => '--border',
    'bezelLight1'   => '--bezel-light-1',
    'bezelLight2'   => '--bezel-light-2',
    'bezelDark1'    => '--bezel-dark-1',
    'bezelDark2'    => '--bezel-dark-2',
    'desktopBg'     => '--desktop-bg',
    'linkText'      => '--link-text',
    'errorText'     => '--error-text',
    'warningBg'     => '--warning-bg',
    'warningText'   => '--warning-text',
    'badgeBg'       => '--badge-bg',
    'badgeText'     => '--badge-text',
    'starColor'     => '--star-color',
    /* Kawaii-only */
    'hoverBg'       => '--hover-bg',
    'activeRose'    => '--active-rose',
    'inactiveBg'    => '--inactive-bg',
    'linkHover'     => '--link-hover',
    'paleRose'      => '--pale-rose',
    'shadowColor'   => '--shadow-color',
];

/* Valores por defecto Win98 (usados si el usuario no fija el token). */
$THEME_DEFAULTS = [
    'winBg'         => '#c0c0c0',
    'winBodyBg'     => '#c0c0c0',
    'surfaceDeep'   => '#c0c0c0',
    'insetBg'       => '#808080',
    'inputBg'       => '#ffffff',
    'inputText'     => '#000000',
    'btnBg'         => '#c0c0c0',
    'btnText'       => '#000000',
    'startBtnBg'    => '#c0c0c0',
    'startBtnText'  => '#000000',
    'text'          => '#000000',
    'textMuted'     => '#666666',
    'textFaint'     => '#808080',
    'textInset'     => '#808080',
    'titlebarStart' => '#000080',
    'titlebarEnd'   => '#1084d0',
    'titlebarText'  => '#ffffff',
    'titlebarIconColor'      => '#000000',
    'titlebarIconBg'         => '#c0c0c0',
    'titlebarIconBezelLight' => '#ffffff',
    'titlebarIconBezelDark'  => '#0a0a0a',
    'accent'        => '#000080',
    'accentText'    => '#ffffff',
    'accentDeep'    => '#00004a',
    'border'        => '#808080',
    'bezelLight1'   => '#ffffff',
    'bezelLight2'   => '#dfdfdf',
    'bezelDark1'    => '#0a0a0a',
    'bezelDark2'    => '#808080',
    'desktopBg'     => '#008080',
    'linkText'      => '#0000ff',
    'errorText'     => '#c00000',
    'warningBg'     => '#fffbe6',
    'warningText'   => '#444444',
    'badgeBg'       => '#d72638',
    'badgeText'     => '#ffffff',
    'starColor'     => '#ffd700',
    /* Kawaii-only — defaults coinciden con :root de kawaii/style.css */
    'hoverBg'       => '#CCE7F7',
    'activeRose'    => '#FCE7F5',
    'inactiveBg'    => '#E8DEEB',
    'linkHover'     => '#6B3A6B',
    'paleRose'      => '#FFF4F9',
    'shadowColor'   => '#2D2052',
];

/* ── Compat: claves antiguas (themes guardados antes del refactor) ── */
$THEME_LEGACY_MAP = [
    'bg'            => 'winBg',
    'taskbarBg'     => 'surfaceDeep',
    'windowBg'      => 'winBg',
    'windowText'    => 'text',
    'titleBarStart' => 'titlebarStart',
    'titleBarEnd'   => 'titlebarEnd',
    'titleBarText'  => 'titlebarText',
    'windowShadow'  => 'bezelDark1',
];

/* ── Temas por defecto para los perfiles fijos (Capi / Angie) ──
   Coinciden EXACTAMENTE con los valores que themes.css aplica a
   body.capi / body.angie. Sirven como punto de partida editable
   en la app de Temas. */
$CAPI_THEME_COLORS = [
    'winBg'         => '#2d2d2d',
    'winBodyBg'     => '#222222',
    'surfaceDeep'   => '#1e1e1e',
    'insetBg'       => '#111111',
    'inputBg'       => '#1a1a1a',
    'inputText'     => '#d0d0d0',
    'btnBg'         => '#3a3a3a',
    'btnText'       => '#d0d0d0',
    'startBtnBg'    => '#3a3a3a',
    'startBtnText'  => '#d0d0d0',
    'text'          => '#d0d0d0',
    'textMuted'     => '#888888',
    'textFaint'     => '#666666',
    'textInset'     => '#444444',
    'titlebarStart' => '#6b5500',
    'titlebarEnd'   => '#EDC001',
    'titlebarText'  => '#000000',
    'titlebarIconColor'      => '#000000',
    'titlebarIconBg'         => '#3a3a3a',
    'titlebarIconBezelLight' => '#555555',
    'titlebarIconBezelDark'  => '#111111',
    'accent'        => '#EDC001',
    'accentText'    => '#000000',
    'accentDeep'    => '#c8a000',
    'border'        => '#3a3a3a',
    'bezelLight1'   => '#555555',
    'bezelLight2'   => '#444444',
    'bezelDark1'    => '#111111',
    'bezelDark2'    => '#1a1a1a',
    'desktopBg'     => '#1a1a1a',
    'linkText'      => '#EDC001',
    'errorText'     => '#ff4f6e',
    'warningBg'     => '#2a2200',
    'warningText'   => '#e8cc80',
    'badgeBg'       => '#ff4f6e',
    'badgeText'     => '#ffffff',
    'starColor'     => '#EDC001',
];

$ANGIE_THEME_COLORS = [
    'winBg'         => '#F8D0C8',
    'winBodyBg'     => '#F8D0C8',
    'surfaceDeep'   => '#F8D0C8',
    'insetBg'       => '#F3BABA',
    'inputBg'       => '#F3BABA',
    'inputText'     => '#1a1a1a',
    'btnBg'         => '#5B744B',
    'btnText'       => '#F9DDD8',
    'startBtnBg'    => '#5B744B',
    'startBtnText'  => '#F9DDD8',
    'text'          => '#35522B',
    'textMuted'     => '#5B744B',
    'textFaint'     => '#799567',
    'textInset'     => '#799567',
    'titlebarStart' => '#5B744B',
    'titlebarEnd'   => '#799567',
    'titlebarText'  => '#F9DDD8',
    'titlebarIconColor'      => '#35522B',
    'titlebarIconBg'         => '#F8D0C8',
    'titlebarIconBezelLight' => '#F9DDD8',
    'titlebarIconBezelDark'  => '#35522B',
    'accent'        => '#799567',
    'accentText'    => '#F9DDD8',
    'accentDeep'    => '#5B744B',
    'border'        => '#F3BABA',
    'bezelLight1'   => '#F9DDD8',
    'bezelLight2'   => '#F8D0C8',
    'bezelDark1'    => '#35522B',
    'bezelDark2'    => '#5B744B',
    'desktopBg'     => '#F9DDD8',
    'linkText'      => '#5B744B',
    'errorText'     => '#c8456e',
    'warningBg'     => '#F3BABA',
    'warningText'   => '#35522B',
    'badgeBg'       => '#c8456e',
    'badgeText'     => '#F9DDD8',
    'starColor'     => '#c8a000',
];

function defaultThemeForUser($userKey) {
    /* Sin tema por defecto: TODOS los perfiles usan los tokens Win98 de
       tokens.css salvo que el usuario active uno custom. Las paletas
       $CAPI_THEME_COLORS / $ANGIE_THEME_COLORS se conservan abajo como
       referencia por si se quieren recrear desde la app de Temas. */
    return null;
}

/**
 * Siembra UNA VEZ el tema personal del usuario en su lista de temas (SQL):
 *  - user1 (Capi)  → tema "Capi"
 *  - user2 (Angie) → tema "Angie"
 *  - resto         → no se siembra nada (cae al Win98 por defecto de tokens.css)
 *
 * Solo se siembra si el usuario NO tiene ya un tema con ese nombre. Si lo
 * borró explícitamente desde la app, no vuelve.
 */
function seedDefaultTheme($userKey, $label) {
    $def = defaultThemeForUser($userKey);
    if (!$def) return;
    $uid = userIdByKey($userKey);
    if (!$uid) return;
    $pdo = themesPdo();
    /* Seed para la interfaz actual. Cada (user, interface) tiene su
       propio espacio: el usuario puede tener "Capi" en win98 y "Capi"
       en win7 con paletas diferentes. */
    $iface = preg_replace('/[^A-Za-z0-9_-]/', '', $_COOKIE['activeInterface'] ?? '') ?: 'win98';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM themes WHERE user_id = ? AND interface_name = ?");
    $stmt->execute([$uid, $iface]);
    if ((int)$stmt->fetchColumn() > 0) return;

    $pdo->prepare("INSERT INTO themes (user_id, interface_name, name, colors, is_active, updated_at)
                   VALUES (?, ?, ?, ?, 0, NOW())")
        ->execute([
            $uid, $iface, $def['name'],
            json_encode($def['colors'], JSON_UNESCAPED_UNICODE),
        ]);
    $cssPath = themeCssFile($def['name'], $label);
    $css     = generateThemeCss(themeCssClassName($def['name'], $label), $def['colors']);
    @file_put_contents($cssPath, $css);
}

/* Carpeta donde viven los CSS de temas regenerados por usuario.
   ANTES era __DIR__ (assets/themes), pero los CSS de Capi/Angie estaban
   tracked en el repo como seeds y el auto-deploy de Hostinger
   (git reset --hard) los restauraba, perdiéndose los cambios que los
   usuarios habían guardado. Ahora viven en uploads/themes/ (gitignored)
   donde sobreviven los deploys. mkdir on-demand cubre instalación
   limpia y entornos donde la carpeta no existía aún. */
function themesDir() {
    $dir = dirname(__DIR__, 2) . '/uploads/themes';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/* Asegura que la conexión PDO esté disponible y devuelve el handle.
   db.php se incluye aquí (dentro de la función) — `require_once` deja
   las variables del archivo en SCOPE LOCAL. Por eso el handle se mueve
   manualmente a $GLOBALS para que el resto del código (y futuras
   llamadas a esta función) lo recuperen vía `global $pdo`. */
function themesPdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        _ensureThemesInterfaceColumn($GLOBALS['pdo']);
        return $GLOBALS['pdo'];
    }
    require_once dirname(__DIR__, 2) . '/db.php';
    $GLOBALS['pdo'] = $pdo;
    _ensureThemesInterfaceColumn($pdo);
    return $pdo;
}

/* Migración idempotente: añade la columna interface_name a la tabla
   themes y cambia el UNIQUE de (user_id, name) a (user_id, interface_name,
   name) para que cada usuario pueda tener el MISMO nombre de tema en
   interfaces distintas. Default 'win98' para todos los themes existentes
   (era la única interfaz antes del sistema de packs). */
function _ensureThemesInterfaceColumn(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no un SHOW
       COLUMNS en cada página o API que use los temas. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'temas-interfaz', __FILE__, '_ensureThemesInterfaceColumnYa');
}
function _ensureThemesInterfaceColumnYa(PDO $pdo): void {
    try {
        $col = $pdo->query("SHOW COLUMNS FROM themes LIKE 'interface_name'")->fetch();
        if ($col) return;
        $pdo->exec("ALTER TABLE themes ADD COLUMN interface_name VARCHAR(32) NOT NULL DEFAULT 'win98' AFTER user_id");
        /* Reemplaza el UNIQUE viejo por uno que también considere interface_name. */
        try { $pdo->exec("ALTER TABLE themes DROP INDEX uq_themes_user_name"); } catch (Throwable $_) {}
        $pdo->exec("ALTER TABLE themes ADD UNIQUE KEY uq_themes_user_iface_name (user_id, interface_name, name)");
    } catch (Throwable $_) {
        /* Si la migración falla (permisos, BD legacy), seguimos. El resto
           del código maneja la ausencia de la columna con fallback a 'win98'. */
    }
}

/* Resuelve user_key (string) → usuarios.id (int). Cachea por petición. */
function userIdByKey(string $userKey): ?int {
    static $cache = [];
    if (array_key_exists($userKey, $cache)) return $cache[$userKey];
    $stmt = themesPdo()->prepare("SELECT id FROM usuarios WHERE user_key = ?");
    $stmt->execute([$userKey]);
    $id = $stmt->fetchColumn();
    return $cache[$userKey] = $id ? (int)$id : null;
}

/**
 * Carga los temas del usuario PARA UNA INTERFAZ CONCRETA.
 *
 * Cada (user, interface) tiene su propio espacio de temas: crear "Blue"
 * en win98 NO lo hace visible en win7. La interfaz se pasa explícitamente
 * o se infiere de la cookie 'activeInterface' (default 'win98').
 *
 * Devuelve la misma forma que antes:
 *   ['themes' => {name: {colors, updatedAt}}, 'active' => string]
 *
 * Pasa $interfaceName = '*' para listar de TODAS las interfaces (uso
 * interno de retrocompat — p.ej. helpers que regeneran CSS antiguos).
 */
function loadUserThemes($userKey, string $interfaceName = '') {
    $uid = userIdByKey($userKey);
    if (!$uid) return ['themes' => new stdClass(), 'active' => ''];

    /* Resuelve la interfaz si no se pasó. */
    if ($interfaceName === '') {
        $cookie = $_COOKIE['activeInterface'] ?? '';
        $interfaceName = preg_replace('/[^A-Za-z0-9_-]/', '', $cookie) ?: 'win98';
    }

    if ($interfaceName === '*') {
        $rows = themesPdo()->prepare("SELECT name, colors, is_active, UNIX_TIMESTAMP(updated_at) AS updated_ts
                                      FROM themes WHERE user_id = ?");
        $rows->execute([$uid]);
    } else {
        $rows = themesPdo()->prepare("SELECT name, colors, is_active, UNIX_TIMESTAMP(updated_at) AS updated_ts
                                      FROM themes WHERE user_id = ? AND interface_name = ?");
        $rows->execute([$uid, $interfaceName]);
    }
    $themes = [];
    $active = '';
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $themes[$r['name']] = [
            'colors'    => json_decode($r['colors'], true) ?: [],
            'updatedAt' => (int)$r['updated_ts'],
        ];
        if ($r['is_active']) $active = $r['name'];
    }
    return ['themes' => $themes ?: new stdClass(), 'active' => $active];
}

/**
 * Sustituye los temas del usuario PARA UNA INTERFAZ CONCRETA por los
 * del array $data. La interfaz se infiere de la cookie 'activeInterface'.
 *
 * IMPORTANTE: solo borra/upserta dentro del scope de la interfaz actual.
 * Los temas de OTRAS interfaces se quedan intactos.
 */
function saveUserThemes($userKey, $data) {
    $uid = userIdByKey($userKey);
    if (!$uid) return;
    $pdo = themesPdo();
    $active  = isset($data['active']) ? (string)$data['active'] : '';
    $themes  = (array)($data['themes'] ?? []);
    $iface   = preg_replace('/[^A-Za-z0-9_-]/', '', $_COOKIE['activeInterface'] ?? '') ?: 'win98';
    $pdo->beginTransaction();
    try {
        /* Borra los que ya no están en $themes (SOLO de esta interfaz) */
        $names = array_keys($themes);
        if (empty($names)) {
            $pdo->prepare("DELETE FROM themes WHERE user_id = ? AND interface_name = ?")
                ->execute([$uid, $iface]);
        } else {
            $place = implode(',', array_fill(0, count($names), '?'));
            $params = array_merge([$uid, $iface], $names);
            $pdo->prepare("DELETE FROM themes WHERE user_id = ? AND interface_name = ? AND name NOT IN ($place)")
                ->execute($params);
        }
        /* Inserta o actualiza cada uno con interface_name */
        $upsert = $pdo->prepare("INSERT INTO themes (user_id, interface_name, name, colors, is_active, updated_at)
            VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))
            ON DUPLICATE KEY UPDATE colors=VALUES(colors), is_active=VALUES(is_active), updated_at=VALUES(updated_at)");
        foreach ($themes as $name => $info) {
            $ts = isset($info['updatedAt']) && is_numeric($info['updatedAt']) ? (int)$info['updatedAt'] : time();
            $upsert->execute([
                $uid, $iface, $name,
                json_encode($info['colors'] ?? [], JSON_UNESCAPED_UNICODE),
                ($name === $active) ? 1 : 0,
                $ts,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function sanitizeThemeName($name) {
    return preg_replace('/[^A-Za-z0-9_-]/', '', trim($name));
}

function themeCssFile($themeName, $label) {
    $safeTheme = sanitizeThemeName($themeName);
    $safeLabel = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    return themesDir() . '/' . $safeTheme . '-' . $safeLabel . '.css';
}

function themeCssRelPath($themeName, $label) {
    $safeTheme = sanitizeThemeName($themeName);
    $safeLabel = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    /* URL relativa a la raíz del proyecto. Coherente con themesDir():
       los CSS viven en uploads/themes/ (fuera de assets/) para
       sobrevivir el git reset --hard del auto-deploy. */
    return 'uploads/themes/' . $safeTheme . '-' . $safeLabel . '.css';
}

function themeCssClassName($themeName, $label) {
    $safeTheme = sanitizeThemeName($themeName);
    $safeLabel = preg_replace('/[^A-Za-z0-9_-]/', '', $label);
    return $safeTheme . '-' . $safeLabel;
}

/* Wallpaper PROPIO del usuario por nombre ({label}-wallpaper.ext), SIN el
   fallback genérico a base-wallpaper. Devuelve ruta relativa o ''. Sirve
   para resolver el fondo «efectivo» de un tema sin asset propio. */
function userNamedWallpaper($label) {
    $safe = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $label));
    $dir  = dirname(__DIR__, 2) . '/assets/img/wallpapers';
    foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
        if (is_file("$dir/$safe-wallpaper.$ext")) {
            return "assets/img/wallpapers/$safe-wallpaper.$ext";
        }
    }
    return '';
}

/* Wallpaper EFECTIVO del usuario — el mismo que ve en su escritorio.
   Prioridad:
     1) Tema activo con themes.wallpaper propio Y archivo existente → esa ruta.
     2) Si la ruta del tema no resuelve (vacía o borrada) → getUserWallpaper($label).
     3) Sin tema activo → getUserWallpaper($label) directamente.
   Devuelve ruta relativa al proyecto, o '' si no hay ninguna. */
function getUserEffectiveWallpaper($userKey, $label) {
    $fallback = function_exists('getUserWallpaper') ? getUserWallpaper($label) : '';
    $data   = loadUserThemes($userKey);
    $active = !empty($data['active']) ? sanitizeThemeName($data['active']) : '';
    if ($active === '') return $fallback;
    $uid = userIdByKey($userKey);
    if ($uid) {
        $st = themesPdo()->prepare("SELECT wallpaper FROM themes WHERE user_id = ? AND name = ?");
        $st->execute([$uid, $active]);
        $tWp = (string)$st->fetchColumn();
        if ($tWp !== '') {
            /* Restaura desde BD si el deploy borró el archivo. */
            if (!is_file(dirname(__DIR__, 2) . '/' . $tWp)) {
                restoreThemeAssetFromDb($tWp, 'wallpaper');
            }
            if (is_file(dirname(__DIR__, 2) . '/' . $tWp)) return $tWp;
        }
    }
    /* Tema activo sin wallpaper propio (o el archivo ya no existe) →
       fondo global del usuario (que a su vez cae al base-wallpaper). */
    return $fallback;
}

/* Resuelve los assets EFECTIVOS de un tema (wallpaper + start-icon) dado
   lo guardado en BD para ese tema. Misma prioridad que desktop-base.php
   aplica en la carga inicial: si el tema declara un asset Y el archivo
   existe → ese. Si no → el global del usuario (getUserWallpaper /
   getUserStartIcon, que ya hacen su propio fallback a base/seed). Sin
   esto, el cliente al activar un tema sin assets propios caía a defaults
   y reseteaba el wallpaper/icono que el usuario tenía configurado como
   global. */
function effectiveThemeAssets(string $label, string $themeWallpaper, string $themeStartIcon): array {
    $root = dirname(__DIR__, 2);
    /* Si la ruta del tema apunta a un archivo que no existe en
       filesystem (deploy lo borró) pero tenemos blob en BD, lo
       restauramos antes de la verificación is_file. */
    if ($themeWallpaper !== '' && !is_file($root . '/' . $themeWallpaper)) {
        restoreThemeAssetFromDb($themeWallpaper, 'wallpaper');
    }
    if ($themeStartIcon !== '' && !is_file($root . '/' . $themeStartIcon)) {
        restoreThemeAssetFromDb($themeStartIcon, 'start_icon');
    }
    $wp = '';
    if ($themeWallpaper !== '' && is_file($root . '/' . $themeWallpaper)) {
        $wp = $themeWallpaper;
    } elseif (function_exists('getUserWallpaper')) {
        $wp = (string)getUserWallpaper($label);
    }
    $si = '';
    if ($themeStartIcon !== '' && is_file($root . '/' . $themeStartIcon)) {
        $si = $themeStartIcon;
    } elseif (function_exists('getUserStartIcon')) {
        $si = (string)getUserStartIcon($label);
    }
    return [$wp, $si];
}

/* Dado el path del asset (themes.wallpaper o themes.start_icon), busca
   el row en `themes` cuya columna apunte a él y, si existe blob en
   `<kind>_data`, restaura el archivo al filesystem. Idempotente: no
   hace nada si el archivo ya existe. `$kind` debe ser 'wallpaper' o
   'start_icon'. */
function restoreThemeAssetFromDb(string $relPath, string $kind): void {
    if (!in_array($kind, ['wallpaper', 'start_icon'], true)) return;
    $root = dirname(__DIR__, 2);
    $full = $root . '/' . $relPath;
    if (is_file($full)) return;
    try {
        $pdo = themesPdo();
        $dataCol = $kind . '_data';
        $extCol  = $kind . '_ext';
        $st = $pdo->prepare("SELECT $dataCol AS d, $extCol AS e FROM themes
                             WHERE $kind = ? AND $dataCol IS NOT NULL LIMIT 1");
        $st->execute([$relPath]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['d'])) return;
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return;
        @file_put_contents($full, $row['d']);
    } catch (Throwable $_) {
        /* Migración pendiente o sin permisos → seguimos. */
    }
}

/**
 * Valida una ruta de asset (wallpaper / start-icon) que viene de la
 * biblioteca: debe estar dentro de assets/img/{wallpapers|start-icons}/
 * y apuntar a un archivo existente. Si pasa, devuelve la propia ruta;
 * si no, ''. Se usa al descargar un tema para guardar una REFERENCIA
 * (no una copia) al asset del autor.
 *
 * @param string $srcRel ruta candidata
 * @param string $kind   'wallpaper' | 'start-icon'
 * @return string ruta validada, o '' si no es válida o no existe.
 */
function validateThemeAssetPath($srcRel, $kind) {
    if (!is_string($srcRel) || $srcRel === '') return '';
    $sub = ($kind === 'start-icon') ? 'start-icons' : 'wallpapers';
    if (!preg_match('#^assets/img/' . $sub . '/[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp|svg)$#i', $srcRel)) return '';
    if (!is_file(dirname(__DIR__, 2) . '/' . $srcRel)) return '';
    return $srcRel;
}

/* Normaliza un array de colores: aplica el mapa legacy y rellena defaults. */
function normalizeThemeColors($colors) {
    global $THEME_COLOR_KEYS, $THEME_DEFAULTS, $THEME_LEGACY_MAP;
    if (!is_array($colors)) return $THEME_DEFAULTS;
    /* Mapear claves legacy a las nuevas */
    $out = [];
    foreach ($colors as $k => $v) {
        if (isset($THEME_LEGACY_MAP[$k]) && !isset($colors[$THEME_LEGACY_MAP[$k]])) {
            $out[$THEME_LEGACY_MAP[$k]] = $v;
        } else {
            $out[$k] = $v;
        }
    }
    /* Migración suave para tokens nuevos: si un tema antiguo no tiene
       titlebarIcon{Color,Bg,BezelLight,BezelDark}, en vez de irse al
       default Win98 hereda los colores del botón / bezels generales. */
    $hex = '/^#[0-9a-f]{3,8}$/i';
    if (empty($out['titlebarIconColor']) && !empty($out['btnText']) && preg_match($hex, $out['btnText'])) {
        $out['titlebarIconColor'] = $out['btnText'];
    }
    if (empty($out['titlebarIconBg']) && !empty($out['btnBg']) && preg_match($hex, $out['btnBg'])) {
        $out['titlebarIconBg'] = $out['btnBg'];
    }
    if (empty($out['titlebarIconBezelLight']) && !empty($out['bezelLight1']) && preg_match($hex, $out['bezelLight1'])) {
        $out['titlebarIconBezelLight'] = $out['bezelLight1'];
    }
    if (empty($out['titlebarIconBezelDark']) && !empty($out['bezelDark1']) && preg_match($hex, $out['bezelDark1'])) {
        $out['titlebarIconBezelDark'] = $out['bezelDark1'];
    }
    /* Migración suave: si el tema no tiene startBtn{Bg,Text}, hereda los
       colores del botón normal para no romper el look existente. */
    if (empty($out['startBtnBg']) && !empty($out['btnBg']) && preg_match($hex, $out['btnBg'])) {
        $out['startBtnBg'] = $out['btnBg'];
    }
    if (empty($out['startBtnText']) && !empty($out['btnText']) && preg_match($hex, $out['btnText'])) {
        $out['startBtnText'] = $out['btnText'];
    }
    /* Rellenar con defaults los tokens que falten */
    foreach ($THEME_COLOR_KEYS as $k) {
        if (!isset($out[$k]) || !is_string($out[$k]) || !preg_match('/^#[0-9a-f]{3,8}$/i', $out[$k])) {
            $out[$k] = $THEME_DEFAULTS[$k];
        }
    }
    return $out;
}

function validateThemeColors($colors) {
    global $THEME_COLOR_KEYS, $THEME_LEGACY_MAP;
    if (!is_array($colors)) return false;
    /* Aceptamos temas guardados con claves legacy: si las claves nuevas
       no están pero hay equivalentes legacy, el tema sigue siendo válido. */
    foreach ($THEME_COLOR_KEYS as $k) {
        $val = null;
        if (isset($colors[$k])) {
            $val = $colors[$k];
        } else {
            $legacyKey = array_search($k, $THEME_LEGACY_MAP, true);
            if ($legacyKey !== false && isset($colors[$legacyKey])) $val = $colors[$legacyKey];
        }
        if ($val === null) continue; /* token ausente → se rellena con default en normalizeThemeColors */
        if (!preg_match('/^#[0-9a-f]{3,8}$/i', $val)) return false;
    }
    return true;
}

/**
 * Genera el CSS de un tema personalizado. Produce un único bloque de
 * variables CSS para body.<className>; el resto de la UI hereda los
 * estilos token-driven definidos en base/perfil/reproductor/etc.
 */
function generateThemeCss($className, $colors) {
    global $THEME_COLOR_KEYS, $THEME_TOKEN_MAP;
    $norm = normalizeThemeColors($colors);
    $sel = 'body.' . $className;
    $lines = [
        '/* Generado por la app de Temas. NO editar a mano.',
        '   Establece los tokens CSS para que cualquier app que use',
        '   var(--token) reciba la paleta del tema activo. */',
        '',
        $sel . ' {',
    ];
    foreach ($THEME_COLOR_KEYS as $k) {
        $var = $THEME_TOKEN_MAP[$k];
        $val = $norm[$k];
        $lines[] = '    ' . $var . ': ' . $val . ';';
    }
    /* Alias retro-compat (--t-*) para CSS antiguo */
    $lines[] = '';
    $lines[] = '    /* Alias retro-compat */';
    $lines[] = '    --t-bg:           ' . $norm['winBg']       . ';';
    $lines[] = '    --t-bg-alt:       ' . $norm['winBodyBg']   . ';';
    $lines[] = '    --t-text:         ' . $norm['text']        . ';';
    $lines[] = '    --t-text-muted:   ' . $norm['textMuted']   . ';';
    $lines[] = '    --t-accent:       ' . $norm['accent']      . ';';
    $lines[] = '    --t-accent-text:  ' . $norm['accentText']  . ';';
    $lines[] = '    --t-border:       ' . $norm['border']      . ';';
    $lines[] = '    --t-grad:         linear-gradient(90deg, ' . $norm['titlebarStart'] . ', ' . $norm['titlebarEnd'] . ');';
    /* Delta de tamaño de fuente. Lo lee font-scale.js para escalar
       TODAS las font-size de la app de forma uniforme. */
    $fsDelta = isset($colors['fontDelta']) ? (int)$colors['fontDelta'] : 0;
    if ($fsDelta < -6) $fsDelta = -6;
    if ($fsDelta >  10) $fsDelta = 10;
    $lines[] = '    --fs-delta:       ' . $fsDelta . 'px;';
    $lines[] = '}';
    return implode("\n", $lines) . "\n";
}

/* Regenera el CSS del tema activo (si lo hay) según el generador actual. */
function refreshActiveThemeCss($userKey, $label) {
    $data = loadUserThemes($userKey);
    $active = !empty($data['active']) ? sanitizeThemeName($data['active']) : '';
    if ($active === '') return;
    $themes = (array)$data['themes'];
    if (!isset($themes[$active]) || !isset($themes[$active]['colors'])) return;
    if (!validateThemeColors($themes[$active]['colors'])) return;
    $cssPath = themeCssFile($active, $label);
    $css     = generateThemeCss(themeCssClassName($active, $label), $themes[$active]['colors']);
    @file_put_contents($cssPath, $css);
}

/* Regenera el CSS de TODOS los temas guardados del usuario. Llamado
   desde apps que muestran el picker (temas.php) o cuando se necesita
   garantizar que los CSS existan en disco. La BD (themes.colors) es la
   fuente de verdad — el filesystem es solo caché que el deploy puede
   borrar. Esto reconstruye la caché completa. Idempotente: si el CSS
   ya existe se sobrescribe con la misma versión. */
function refreshAllUserThemesCss($userKey, $label) {
    $data = loadUserThemes($userKey);
    $themes = (array)($data['themes'] ?? []);
    foreach ($themes as $name => $info) {
        if (!isset($info['colors']) || !validateThemeColors($info['colors'])) continue;
        $safeName = sanitizeThemeName((string)$name);
        if ($safeName === '') continue;
        $cssPath = themeCssFile($safeName, $label);
        $css     = generateThemeCss(themeCssClassName($safeName, $label), $info['colors']);
        @file_put_contents($cssPath, $css);
    }
}

/* ──────────────────────────────────────────────────────────────────────
   getActiveThemeForInterface
   ──────────────────────────────────────────────────────────────────────
   Resuelve qué tema debe estar activo PARA UNA INTERFAZ CONCRETA.
   Cada usuario puede tener un tema activo distinto por interfaz; la
   selección se persiste en cookies `themeFor_<interfaceName>`.

   Orden de resolución:
     1) Cookie `themeFor_<interface>` → si apunta a un tema del usuario
        que existe, lo devuelve.
     2) `loadUserThemes()['active']` → el tema global (retrocompat).
     3) '' (sin tema → la interfaz usa sus tokens default de :root).

   Refresca también el CSS del tema activo en disco para que el browser
   tenga la versión vigente.
   ────────────────────────────────────────────────────────────────────── */
function getActiveThemeForInterface(string $userKey, string $label, string $interfaceName = ''): array {
    $iface = preg_replace('/[^A-Za-z0-9_-]/', '', $interfaceName);
    if ($iface === '') $iface = 'win98';
    /* Filtra estrictamente por la interfaz actual. Crear "Blue" en
       win98 NO lo muestra en win7, y vice versa. */
    $data   = loadUserThemes($userKey, $iface);
    $themes = (array)$data['themes'];
    $name   = '';
    /* 1. Cookie por interfaz (la fuente principal de verdad). */
    $cookieKey = 'themeFor_' . $iface;
    if (!empty($_COOKIE[$cookieKey])) {
        $cand = sanitizeThemeName((string)$_COOKIE[$cookieKey]);
        if ($cand !== '' && isset($themes[$cand])) $name = $cand;
    }
    /* 2. is_active del DB para esta interfaz (retrocompat para
       usuarios viejos sin cookie todavía). */
    if ($name === '' && !empty($data['active'])) {
        $cand = sanitizeThemeName($data['active']);
        if ($cand !== '' && isset($themes[$cand])) $name = $cand;
    }
    /* 3. Sin tema → la interfaz usa los tokens default del :root de
       su style.css. No buscamos en otras interfaces. */
    if ($name !== '' && isset($themes[$name]['colors']) && validateThemeColors($themes[$name]['colors'])) {
        $cssPath = themeCssFile($name, $label);
        $css     = generateThemeCss(themeCssClassName($name, $label), $themes[$name]['colors']);
        @file_put_contents($cssPath, $css);
        return [
            'name'      => $name,
            'cssRel'    => themeCssRelPath($name, $label),
            'className' => themeCssClassName($name, $label),
        ];
    }
    return ['name' => '', 'cssRel' => '', 'className' => ''];
}
