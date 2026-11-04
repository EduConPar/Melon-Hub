<?php
/* ──────────────────────────────────────────────
   ITEMS DE PERSONALIZACIÓN — haros y mascotas de la tienda
   ──────────────────────────────────────────────
   Va aparte de api.php porque la Tienda (assets/tienda/api.php) también
   tiene que sembrarlos: si solo se sembraban al abrir Temas, un haro
   nuevo no salía en la Tienda hasta que alguien abría Temas. */

/* ── Setup idempotente ──
   Añade columna `slug` a tienda_items si no existe — la usamos para
   mapear el item a sus archivos (gif/png/audio). Y siembra el primer
   haro base ("Verde") asociado al greenHaro existente. */
function personalizeEnsureItems(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    /* slug column */
    try {
        $col = $pdo->query("SHOW COLUMNS FROM tienda_items LIKE 'slug'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE tienda_items ADD COLUMN slug VARCHAR(60) NOT NULL DEFAULT '' AFTER nombre");
        }
    } catch (Throwable $e) { /* sin permisos ALTER → seguimos, fallback en nombre */ }

    /* Seed para una categoría arbitraria de tienda_items.
       Convención de nombres por slug:
         haros    → assets/vids/{slug}Haro.gif (+ -last.png + .mp3)
         mascotas → assets/mascota/skins/{slug}/shimeN.png */
    $seed = function(string $categoria, string $slug, string $nombre, string $desc, int $precio, int $orden) use ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM tienda_items WHERE categoria = ? AND slug = ? LIMIT 1");
            $stmt->execute([$categoria, $slug]);
            if ($stmt->fetchColumn()) return;
            $ins = $pdo->prepare("INSERT INTO tienda_items (nombre, slug, descripcion, precio, icono, activo, orden, categoria)
                                  VALUES (?, ?, ?, ?, '', 1, ?, ?)");
            $ins->execute([$nombre, $slug, $desc, $precio, $orden, $categoria]);
        } catch (Throwable $e) { /* slug column quizá no existe — ignoramos */ }
    };

    try {
        /* Migra cualquier seed anterior con slug='verde' a 'green' y
           vacía iconos emoji previos — los haros usan el PNG del slug
           como icono visible (la convención manda). */
        $pdo->exec("UPDATE tienda_items SET slug = 'green' WHERE categoria = 'haros' AND slug = 'verde'");
        $pdo->exec("UPDATE tienda_items SET icono = '' WHERE categoria = 'haros' AND icono <> ''");
    } catch (Throwable $e) {}

    /* Haros */
    $seed('haros', 'green',  'Verde',    'Haro verde clásico. Lo tienes por defecto.', 0,  1);
    $seed('haros', 'yellow', 'Amarillo', 'Haro amarillo',                              200, 2);
    $seed('haros', 'purple', 'Morado', 'Haro Morado', 200, 3);
    /* Mascotas — la skin se aplica a la PRÓXIMA mascota que crees
       (la actual mantiene su skin original). */
    $seed('mascotas', 'gabriel', 'Gabriel', 'Mascota Gabriel — angel shimeji con 46 frames.', 0, 1);

    $done = true;
}
