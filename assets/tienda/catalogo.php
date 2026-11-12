<?php
/* ──────────────────────────────────────────────
   CATÁLOGO DE LA TIENDA (assets/tienda/catalogo.json)
   ──────────────────────────────────────────────
   Las pestañas y los objetos de la tienda salen del JSON, no del código
   ni de la base de datos: para añadir, cambiar o quitar algo se edita el
   archivo y se sube. tienda_items sigue existiendo porque las compras
   (tienda_compras.item_id) y lo activo (user_settings) apuntan a su id.

   Sincronización (tiendaSyncCatalogo):
     - Cada objeto se busca por categoría + slug y se le ponen los datos
       del archivo (nombre, descripción, precio, icono, rol, orden), o se
       crea si no está. Así se conserva su id y las compras siguen valiendo.
     - Las filas que no están en el archivo se desactivan (activo = 0), no
       se borran: dejan de salir en la tienda, pero quien las compró las
       sigue teniendo. Volver a ponerlas en el archivo las reactiva.
     - El orden es el del archivo dentro de cada categoría.
     - Solo se sincroniza cuando el archivo cambia (huella en app_cache).
     - Si el JSON no se puede leer o está mal, no se toca nada: un error de
       sintaxis no puede desactivar toda la tienda. */

require_once dirname(__DIR__) . '/cache-helpers.php';

/* Si cambia la lógica de la sincronización, subir este número fuerza a
   sincronizar otra vez aunque el JSON no haya cambiado. */
const TIENDA_CATALOGO_VERSION = 1;

/* Lee y valida el JSON. Devuelve null si no sirve (y lo deja en el log). */
function tiendaCatalogo(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;

    $raw = @file_get_contents(__DIR__ . '/catalogo.json');
    if ($raw === false) { error_log('tienda: no se puede leer catalogo.json'); return null; }
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['pestanas'] ?? null) || !is_array($data['objetos'] ?? null)) {
        error_log('tienda: catalogo.json no es válido (' . json_last_error_msg() . ')');
        return null;
    }

    $slugOk = function($s) { return is_string($s) && preg_match('/^[a-z0-9_-]{1,60}$/', $s); };

    $pestanas = [];
    foreach ($data['pestanas'] as $p) {
        if (!is_array($p) || !$slugOk($p['id'] ?? null)) continue;
        $pestanas[] = [
            'id'           => $p['id'],
            'nombre'       => (string)($p['nombre'] ?? $p['id']),
            'icono'        => (string)($p['icono'] ?? ''),
            'proximamente' => !empty($p['proximamente']),
        ];
    }

    /* Objetos: un slug repetido dentro de la misma categoría se ignora
       (gana el primero) y se avisa en el log. */
    $objetos = []; $vistos = []; $orden = [];
    foreach ($data['objetos'] as $o) {
        if (!is_array($o)) continue;
        $cat = $o['categoria'] ?? null; $slug = $o['slug'] ?? null;
        if (!$slugOk($cat) || !$slugOk($slug)) {
            error_log('tienda: objeto sin categoría o slug válidos en catalogo.json: ' . json_encode($o));
            continue;
        }
        if (isset($vistos["$cat/$slug"])) { error_log("tienda: slug repetido $cat/$slug en catalogo.json"); continue; }
        $vistos["$cat/$slug"] = true;
        $orden[$cat] = ($orden[$cat] ?? 0) + 1;
        $objetos[] = [
            'categoria'       => $cat,
            'slug'            => $slug,
            'nombre'          => (string)($o['nombre'] ?? $slug),
            'descripcion'     => (string)($o['descripcion'] ?? ''),
            'precio'          => max(0, (int)($o['precio'] ?? 0)),
            'icono'           => (string)($o['icono'] ?? ''),
            'discord_role_id' => preg_replace('/\D/', '', (string)($o['discord_role_id'] ?? '')),
            'orden'           => $orden[$cat],
            /* Marcos de perfil: "css" (estilo en assets/css/marcos.css) o la
               ruta de la imagen (assets/img/marcos/…). No va a la BD: lo lee
               assets/profile/marcos.php del archivo. */
            'marco'           => tiendaMarcoValido($o['marco'] ?? null),
            /* Fuentes (categoria interfaces, slug fuente-…): familia de
               Google Fonts (o una que ya carga la web, con "local": true),
               pesos a pedir ("400;700") y la interfaz que la incluye
               ("incluida": "kawaii" → quien tiene esa interfaz la tiene).
               Lo lee assets/php/fuentes.php. */
            'fuente'          => (is_string($o['fuente'] ?? null) && preg_match('/^[A-Za-z0-9 ]{1,40}$/', $o['fuente'])) ? $o['fuente'] : null,
            'fuente_local'    => !empty($o['local']),
            'fuente_pesos'    => (is_string($o['pesos'] ?? null) && preg_match('/^\d{3}(;\d{3})*$/', $o['pesos'])) ? $o['pesos'] : '',
            'incluida'        => $slugOk($o['incluida'] ?? null) ? $o['incluida'] : null,
        ];
    }

    $cache = ['pestanas' => $pestanas, 'objetos' => $objetos, 'hash' => sha1($raw)];
    return $cache;
}

/* Campo "marco" de un objeto: "css" o una imagen de assets/img/marcos/.
   Cualquier otra cosa → null (no es un marco). */
function tiendaMarcoValido($m): ?string {
    if ($m === 'css') return 'css';
    if (is_string($m) && preg_match('#^assets/img/marcos/[A-Za-z0-9_-]+\.(png|gif|webp)$#', $m)) return $m;
    return null;
}

/* Pestañas de la tienda (para pintarlas en tienda.php / tienda-mobile.php). */
function tiendaPestanas(): array {
    $c = tiendaCatalogo();
    return $c ? $c['pestanas'] : [];
}

/* Pone tienda_items como dice el archivo. Idempotente y barato: si la
   huella del archivo no ha cambiado desde la última vez, no hace nada. */
function tiendaSyncCatalogo(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $c = tiendaCatalogo();
    if (!$c) return;
    $huella = TIENDA_CATALOGO_VERSION . ':' . $c['hash'];
    if (cacheGet('tienda_catalogo_huella') === $huella) return;

    try {
        /* Columnas que usa el catálogo (en instalaciones viejas faltaban). */
        if (!$pdo->query("SHOW COLUMNS FROM tienda_items LIKE 'slug'")->fetch()) {
            $pdo->exec("ALTER TABLE tienda_items ADD COLUMN slug VARCHAR(60) NOT NULL DEFAULT '' AFTER nombre");
        }
        if (!$pdo->query("SHOW COLUMNS FROM tienda_items LIKE 'discord_role_id'")->fetch()) {
            $pdo->exec("ALTER TABLE tienda_items ADD COLUMN discord_role_id VARCHAR(32) NULL");
        }

        $filas = $pdo->query("SELECT id, categoria, slug, nombre, discord_role_id FROM tienda_items")
                     ->fetchAll(PDO::FETCH_ASSOC);

        $pdo->beginTransaction();
        $upd = $pdo->prepare("UPDATE tienda_items
                                 SET slug = ?, nombre = ?, descripcion = ?, precio = ?, icono = ?,
                                     discord_role_id = ?, orden = ?, activo = 1
                               WHERE id = ?");
        $ins = $pdo->prepare("INSERT INTO tienda_items
                                 (nombre, slug, descripcion, precio, icono, activo, orden, categoria, discord_role_id)
                              VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)");

        $usados = [];
        foreach ($c['objetos'] as $o) {
            $id = tiendaBuscarFila($filas, $o, $usados);
            $rol = $o['discord_role_id'] !== '' ? $o['discord_role_id'] : null;
            if ($id) {
                $upd->execute([$o['slug'], $o['nombre'], $o['descripcion'], $o['precio'], $o['icono'],
                               $rol, $o['orden'], $id]);
            } else {
                $ins->execute([$o['nombre'], $o['slug'], $o['descripcion'], $o['precio'], $o['icono'],
                               $o['orden'], $o['categoria'], $rol]);
                $id = (int)$pdo->lastInsertId();
            }
            $usados[$id] = true;
        }

        /* Lo que no está en el archivo deja de estar a la venta. */
        $sobran = array_diff(array_map('intval', array_column($filas, 'id')), array_keys($usados));
        if ($sobran) {
            $pdo->exec("UPDATE tienda_items SET activo = 0 WHERE id IN (" . implode(',', $sobran) . ")");
        }
        $pdo->commit();
        cacheSet('tienda_catalogo_huella', $huella);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('tienda: no se pudo sincronizar el catálogo: ' . $e->getMessage());
    }
}

/* Fila de tienda_items que corresponde a un objeto del archivo: por
   categoría + slug; si no, las filas viejas sin slug (creadas a mano en
   la BD) se reconocen por el rol de Discord o por el nombre. Una fila ya
   emparejada con otro objeto no se vuelve a usar. */
function tiendaBuscarFila(array $filas, array $o, array $usados): ?int {
    $libre = function($f) use ($usados, $o) {
        return !isset($usados[(int)$f['id']]) && $f['categoria'] === $o['categoria'];
    };
    foreach ($filas as $f) {
        if ($libre($f) && $f['slug'] === $o['slug']) return (int)$f['id'];
    }
    foreach ($filas as $f) {
        if (!$libre($f) || $f['slug'] !== '') continue;
        if ($o['discord_role_id'] !== '' && (string)$f['discord_role_id'] === $o['discord_role_id']) return (int)$f['id'];
    }
    foreach ($filas as $f) {
        if (!$libre($f) || $f['slug'] !== '') continue;
        if (mb_strtolower(trim($f['nombre'])) === mb_strtolower(trim($o['nombre']))) return (int)$f['id'];
    }
    /* Haros de antes con slug 'verde' (ahora 'green'). */
    if ($o['categoria'] === 'haros' && $o['slug'] === 'green') {
        foreach ($filas as $f) if ($libre($f) && $f['slug'] === 'verde') return (int)$f['id'];
    }
    return null;
}

/* Compras en cadena de la pestaña Perfil: el objeto que hay que tener
   antes de poder comprar este (null = ninguno).
     - huecos de expositor: el 4 pide el 3, el 5 pide el 4;
     - Media favorita (media-<tipo>-4/5): el 5 pide el 4 del mismo tipo;
     - filas de logros de Steam (steam-rare|ach-fila-2/3): cada fila pide
       la anterior del mismo expositor.
   La usan tienda api `buy` y, con la misma regla, tienda.php y
   tienda-mobile.php (tiendaPrevSlug en JS). */
function tiendaPrevSlug(string $slug): ?string {
    if (preg_match('/^expositor-hueco-(\d)$/', $slug, $m) && (int)$m[1] > 3) return 'expositor-hueco-' . ((int)$m[1] - 1);
    if (preg_match('/^(media-[a-z]+)-(\d)$/', $slug, $m) && (int)$m[2] > 4) return $m[1] . '-' . ((int)$m[2] - 1);
    if (preg_match('/^(steam-(?:rare|ach)-fila)-(\d)$/', $slug, $m) && (int)$m[2] > 2) return $m[1] . '-' . ((int)$m[2] - 1);
    return null;
}

/* Objetos que se gastan (de momento solo el Protector de racha,
   assets/profile/racha.php): la compra se marca con consumido_en al
   usarse y deja de contar como "lo tienes", así que se puede volver a
   comprar o regalar. Lo de comprobar si se tiene algo en la tienda mira
   siempre consumido_en IS NULL. */
function tiendaEnsureConsumibles(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no en
       cada petición: ver .claude/notas/rendimiento.md. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'tienda-consumibles', __FILE__, function (PDO $pdo) {
        try {
            if (!$pdo->query("SHOW COLUMNS FROM tienda_compras LIKE 'consumido_en'")->fetch()) {
                $pdo->exec("ALTER TABLE tienda_compras ADD COLUMN consumido_en TIMESTAMP NULL DEFAULT NULL");
            }
        } catch (Throwable $e) { /* ya estaba o sin permisos de ALTER */ }
    });
}

/* Lo gastado en la tienda, una fila por compra (medalla "Derrochador",
   perfil2.php). tienda_compras no sirve para sumarlo: recomprar un
   consumible pisa la fila de la compra anterior. La primera vez se llena
   con las compras que ya había (solo si está vacía: no se duplica al
   repetirse con una versión nueva de este archivo). */
function tiendaEnsureGastos(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'tienda-gastos', __FILE__, function (PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS tienda_gastos (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            user_id   INT NOT NULL,
            item_id   INT NOT NULL,
            precio    INT NOT NULL,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!(int)$pdo->query("SELECT COUNT(*) FROM tienda_gastos")->fetchColumn()) {
            $pdo->exec("INSERT INTO tienda_gastos (user_id, item_id, precio, creado_en)
                        SELECT user_id, item_id, precio, creado_en FROM tienda_compras WHERE precio > 0");
        }
    });
}

/* Regalos de la tienda (tienda api gift / gifts / gift-answer):
   estado pendiente → aceptado | rechazado | devuelto (ya lo tenía o le
   faltaba la mejora anterior al abrirlo). */
function tiendaEnsureRegalos(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    /* Una vez por versión de este archivo (melon_esquema), no en
       cada petición: ver .claude/notas/rendimiento.md. */
    require_once dirname(__DIR__) . '/php/esquema.php';
    melon_esquema($pdo, 'tienda-regalos', __FILE__, function (PDO $pdo) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tienda_regalos (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                from_user_id INT NOT NULL,
                to_user_id   INT NOT NULL,
                item_id      INT NOT NULL,
                precio       INT NOT NULL,
                mensaje      VARCHAR(255) NOT NULL DEFAULT '',
                estado       VARCHAR(12) NOT NULL DEFAULT 'pendiente',
                creado_en    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resuelto_en  TIMESTAMP NULL,
                INDEX idx_to (to_user_id, estado),
                INDEX idx_item (to_user_id, item_id, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { /* sin permisos de CREATE */ }
    });
}
