<?php
/* ──────────────────────────────────────────────
   MARCOS DE PERFIL (fase 4 del perfil nuevo)
   ──────────────────────────────────────────────
   Los marcos son objetos de la pestaña Perfil de la tienda
   (assets/tienda/catalogo.json, categoria "perfil", con el campo "marco"):
     - "marco": "css"  → el dibujo es [data-marco="<slug>"]::before en
       assets/css/marcos.css.
     - "marco": "assets/img/marcos/x.png" → imagen transparente que se
       estira encima de la foto (assets/js/melon-marcos.js crea la regla).

   El que lleva puesto cada uno se guarda en user_settings
   (key_name 'active_marco', value = slug en JSON: la columna exige JSON
   válido). Se elige en Personalizar del perfil y se ve en la cabecera del
   perfil, el chat, Social y los comentarios del muro: melon-marcos.js pide
   la lista (acción "frames") y lo pone en cada foto con
   data-marco-user="<user_key>".

   Un marco que se quita del catálogo sigue valiendo para quien lo compró
   mientras su dibujo exista (lo mismo que el resto de la tienda). */

require_once dirname(__DIR__) . '/tienda/catalogo.php';

/* slug → { slug, nombre, tipo: css|imagen, src } de los marcos del catálogo. */
function marcos_defs(): array {
    $c = tiendaCatalogo();
    if (!$c) return [];
    $out = [];
    foreach ($c['objetos'] as $o) {
        if ($o['categoria'] !== 'perfil' || empty($o['marco'])) continue;
        $out[$o['slug']] = [
            'slug'   => $o['slug'],
            'nombre' => $o['nombre'],
            'tipo'   => $o['marco'] === 'css' ? 'css' : 'imagen',
            'src'    => $o['marco'] === 'css' ? null : $o['marco'],
        ];
    }
    return $out;
}

/* Slugs de marco que tiene comprados el usuario. */
function marcos_comprados(PDO $pdo, int $uid): array {
    $defs = marcos_defs();
    if (!$defs) return [];
    tiendaSyncCatalogo($pdo);
    $st = $pdo->prepare("SELECT DISTINCT i.slug FROM tienda_compras c
                           JOIN tienda_items i ON i.id = c.item_id
                          WHERE c.user_id = ? AND i.categoria = 'perfil'");
    $st->execute([$uid]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), function ($s) use ($defs) {
        return isset($defs[$s]);
    }));
}

/* Marco puesto de un usuario (null = ninguno o ya no lo tiene). */
function marcos_activo(PDO $pdo, int $uid): ?string {
    $st = $pdo->prepare("SELECT value FROM user_settings WHERE user_id = ? AND key_name = 'active_marco'");
    $st->execute([$uid]);
    $slug = json_decode((string)$st->fetchColumn(), true);
    if (!is_string($slug) || $slug === '') return null;
    return in_array($slug, marcos_comprados($pdo, $uid), true) ? $slug : null;
}

/* Lo que necesita Personalizar: los comprados (con su dibujo) y el puesto. */
function marcos_propios(PDO $pdo, int $uid): array {
    $defs = marcos_defs();
    $owned = [];
    foreach (marcos_comprados($pdo, $uid) as $s) $owned[] = $defs[$s];
    return ['owned' => $owned, 'active' => marcos_activo($pdo, $uid)];
}

/* Poner (slug) o quitar ('') el marco. Solo uno comprado. */
function marcos_poner(PDO $pdo, int $uid, string $slug): bool {
    if ($slug === '') {
        $pdo->prepare("DELETE FROM user_settings WHERE user_id = ? AND key_name = 'active_marco'")->execute([$uid]);
        return true;
    }
    if (!in_array($slug, marcos_comprados($pdo, $uid), true)) return false;
    $pdo->prepare("INSERT INTO user_settings (user_id, key_name, value) VALUES (?, 'active_marco', ?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP")
        ->execute([$uid, json_encode($slug)]);
    return true;
}

/* Para melon-marcos.js: los dibujos y el marco de cada usuario que lleva
   uno (user_key → slug). Son pocos usuarios: va todo en una respuesta. */
function marcos_todos(PDO $pdo): array {
    $defs = marcos_defs();
    $users = [];
    if ($defs) {
        tiendaSyncCatalogo($pdo);
        $st = $pdo->query("SELECT u.user_key, s.value
                             FROM user_settings s
                             JOIN usuarios u ON u.id = s.user_id
                            WHERE s.key_name = 'active_marco'
                              AND EXISTS (SELECT 1 FROM tienda_compras c
                                            JOIN tienda_items i ON i.id = c.item_id
                                           WHERE c.user_id = s.user_id AND i.categoria = 'perfil'
                                             AND i.slug = JSON_UNQUOTE(s.value))");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $slug = json_decode((string)$r['value'], true);
            if (is_string($slug) && isset($defs[$slug])) $users[$r['user_key']] = $slug;
        }
    }
    return ['defs' => (object)$defs, 'users' => (object)$users];
}
