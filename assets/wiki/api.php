<?php
/* ════════════════════════════════════════════════════════════════════
   WIKI API — wiki colaborativa con moderación.

   Modelo:
   - wiki_pages      : páginas publicadas y navegables (slug único).
   - wiki_revisions  : cada creación/edición entra como REVISIÓN
                       'pending'. Un admin la acepta (se aplica a
                       wiki_pages + puntos al autor) o la deniega (con
                       motivo). En ambos casos el autor recibe una
                       notificación con el motivo.
   - wiki_admins     : user_id de los admins de la wiki. Se siembra con
                       el usuario de menor id (dueño) si está vacía.

   Endpoints (?action=…). Todos requieren sesión PHP:
     am-admin     GET   {ok, isAdmin}
     list-pages   GET   {ok, pages:[{id,slug,title,updatedAt}]}
     get-page     GET   ?slug=…  → {ok, page:{…}|null}
     submit       POST  {pageId?|slug?, title, body, summary?} → crea
                        revisión pendiente. {ok, revisionId}
     my-requests  GET   {ok, requests:[…]}  — solicitudes del usuario
                        con su estado y motivo.
     pending      GET   (admin) {ok, requests:[…]}  — cola por revisar.
     review       POST  (admin) {revisionId, decision:'accept'|'decline',
                        reason?} → aplica/deniega, puntúa y notifica.

   Puntos de autismo: página nueva aceptada = WIKI_POINTS_NEW, edición
   aceptada = WIKI_POINTS_EDIT (columna usuarios.autismo).
═════════════════════════════════════════════════════════════════════ */
require_once dirname(__DIR__, 2) . '/db.php';                       /* $pdo */
require_once dirname(__DIR__, 2) . '/assets/auth.php';              /* requireAuth, json* */
require_once dirname(__DIR__, 2) . '/assets/themes/theme-helpers.php'; /* userIdByKey */
require_once dirname(__DIR__) . '/php/esquema.php';                 /* melon_esquema: tablas una vez por versión */
$pushLib = dirname(__DIR__, 2) . '/assets/push/send-push.php';
if (file_exists($pushLib)) require_once $pushLib;                   /* sendPushToUser (best-effort) */

const WIKI_POINTS_NEW  = 25;
const WIKI_POINTS_EDIT = 10;

$me     = requireAuth();                 /* ['key','label']; corta 401 si no hay sesión */
$userId = userIdByKey($me['key']);
if (!$userId) jsonError('Usuario desconocido', 401);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ── Auto-migración de tablas ──────────────────────────────────────── */
melon_esquema($pdo, 'wiki', __FILE__, function (PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wiki_pages (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            slug        VARCHAR(160) NOT NULL UNIQUE,
            title       VARCHAR(200) NOT NULL,
            body        MEDIUMTEXT   NOT NULL,
            created_by  INT NULL,
            updated_by  INT NULL,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wiki_revisions (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            page_id        INT NULL,                 /* NULL = página nueva */
            slug           VARCHAR(160) NULL,        /* slug propuesto (nuevas) */
            title          VARCHAR(200) NOT NULL,
            body           MEDIUMTEXT   NOT NULL,
            summary        VARCHAR(255) NULL,        /* comentario del autor */
            author_id      INT NOT NULL,
            status         ENUM('pending','accepted','declined') DEFAULT 'pending',
            reviewer_id    INT NULL,
            reason         TEXT NULL,
            points_awarded INT NOT NULL DEFAULT 0,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at    TIMESTAMP NULL,
            INDEX idx_status (status, created_at),
            INDEX idx_author (author_id, status),
            INDEX idx_page (page_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wiki_admins (
            user_id INT PRIMARY KEY
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}, true);
/* Siembra: si no hay admins, el usuario de menor id (dueño) lo es. */
try {
    $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM wiki_admins")->fetchColumn();
    if ($hasAdmin === 0) {
        $owner = $pdo->query("SELECT MIN(id) FROM usuarios")->fetchColumn();
        if ($owner) $pdo->prepare("INSERT IGNORE INTO wiki_admins (user_id) VALUES (?)")->execute([(int)$owner]);
    }
} catch (Throwable $e) { /* usuarios puede no existir en clones vacíos */ }

/* ── Helpers ───────────────────────────────────────────────────────── */
function wiki_isAdmin(PDO $pdo, int $uid): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM wiki_admins WHERE user_id = ? LIMIT 1");
        $st->execute([$uid]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/* Slug ASCII a partir del título. */
function wiki_slugify(string $s): string {
    $s = trim($s);
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = 'pagina';
    return substr($s, 0, 150);
}

/* Devuelve un slug libre en wiki_pages a partir de uno base. */
function wiki_freeSlug(PDO $pdo, string $base): string {
    $base = wiki_slugify($base);
    $slug = $base; $n = 1;
    $st = $pdo->prepare("SELECT 1 FROM wiki_pages WHERE slug = ? LIMIT 1");
    while (true) {
        $st->execute([$slug]);
        if (!$st->fetchColumn()) return $slug;
        $n++; $slug = $base . '-' . $n;
    }
}

/* Etiqueta de un usuario por id (para mostrar autor en la cola). */
function wiki_userLabel(PDO $pdo, ?int $uid): string {
    if (!$uid) return 'Anónimo';
    try {
        $st = $pdo->prepare("SELECT label, user_key FROM usuarios WHERE id = ? LIMIT 1");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return (string)($r['label'] ?: $r['user_key'] ?: ('user' . $uid));
    } catch (Throwable $e) {}
    return 'user' . $uid;
}

/* Inserta una notificación in-app (misma tabla que perfil) + push
   best-effort. Tolerante a que la tabla/clave no existan. */
function wiki_notify(PDO $pdo, int $toUid, ?int $fromUid, string $type, array $payload): void {
    if ($fromUid !== null && $fromUid === $toUid) {
        /* Aun así notificamos: el autor quiere ver su propio resultado
           aunque el revisor sea él mismo. No saltamos. */
    }
    try {
        $pdo->prepare("INSERT INTO notifications (user_id, type, from_user_id, payload, is_read)
                       VALUES (?, ?, ?, ?, 0)")
            ->execute([$toUid, $type, $fromUid, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        /* Timbre 'notif' (assets/php/timbre.php): su escritorio lo ve. */
        require_once dirname(__DIR__) . '/php/timbre.php';
        rt_usuario($toUid, 'notif');
    } catch (Throwable $e) { /* tabla ausente → no bloquea */ }
    if (function_exists('sendPushToUser')) {
        try { sendPushToUser($pdo, $toUid, $payload); } catch (Throwable $e) {}
    }
}

$action = $_GET['action'] ?? '';

/* ════════════════════════════════════════════════════════════════════
   LECTURA
═════════════════════════════════════════════════════════════════════ */
if ($action === 'am-admin') {
    jsonResponse(['ok' => true, 'isAdmin' => wiki_isAdmin($pdo, $userId)]);
}

if ($action === 'list-pages') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = $pdo->prepare("SELECT id, slug, title, updated_at FROM wiki_pages
                             WHERE title LIKE ? OR body LIKE ? ORDER BY title ASC LIMIT 500");
        $like = '%' . $q . '%';
        $st->execute([$like, $like]);
    } else {
        $st = $pdo->query("SELECT id, slug, title, updated_at FROM wiki_pages ORDER BY title ASC LIMIT 500");
    }
    $pages = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pages[] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'title' => $r['title'], 'updatedAt' => $r['updated_at']];
    }
    jsonResponse(['ok' => true, 'pages' => $pages]);
}

if ($action === 'get-page') {
    $slug = trim((string)($_GET['slug'] ?? ''));
    if ($slug === '') jsonError('Falta slug');
    $st = $pdo->prepare("SELECT id, slug, title, body, updated_at, updated_by FROM wiki_pages WHERE slug = ? LIMIT 1");
    $st->execute([$slug]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) jsonResponse(['ok' => true, 'page' => null]);
    jsonResponse(['ok' => true, 'page' => [
        'id'        => (int)$r['id'],
        'slug'      => $r['slug'],
        'title'     => $r['title'],
        'body'      => $r['body'],
        'updatedAt' => $r['updated_at'],
        'updatedBy' => wiki_userLabel($pdo, $r['updated_by'] ? (int)$r['updated_by'] : null),
    ]]);
}

if ($action === 'my-requests') {
    $st = $pdo->prepare("SELECT id, page_id, slug, title, status, reason, points_awarded, created_at, reviewed_at
                         FROM wiki_revisions WHERE author_id = ? ORDER BY created_at DESC LIMIT 100");
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'            => (int)$r['id'],
            'title'         => $r['title'],
            'slug'          => $r['slug'],
            'isNew'         => $r['page_id'] === null,
            'status'        => $r['status'],
            'reason'        => $r['reason'],
            'pointsAwarded' => (int)$r['points_awarded'],
            'createdAt'     => $r['created_at'],
            'reviewedAt'    => $r['reviewed_at'],
        ];
    }
    jsonResponse(['ok' => true, 'requests' => $out]);
}

/* ════════════════════════════════════════════════════════════════════
   ESCRITURA — el usuario manda una revisión a moderación
═════════════════════════════════════════════════════════════════════ */
if ($action === 'submit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b      = jsonBody();
    $title  = trim((string)($b['title'] ?? ''));
    $body   = trim((string)($b['body'] ?? ''));
    $summary= trim((string)($b['summary'] ?? ''));
    $pageId = isset($b['pageId']) && $b['pageId'] !== null && $b['pageId'] !== '' ? (int)$b['pageId'] : null;

    if ($title === '')             jsonError('El título no puede estar vacío');
    if (mb_strlen($title) > 200)   jsonError('El título es demasiado largo');
    if ($body === '')              jsonError('El contenido no puede estar vacío');
    if (mb_strlen($body) > 200000) jsonError('El contenido es demasiado largo');

    $slug = null;
    if ($pageId !== null) {
        /* Edición: la página debe existir; heredamos su slug. */
        $st = $pdo->prepare("SELECT slug FROM wiki_pages WHERE id = ? LIMIT 1");
        $st->execute([$pageId]);
        $slug = $st->fetchColumn();
        if (!$slug) jsonError('La página que intentas editar no existe', 404);
    } else {
        /* Nueva: slug propuesto a partir del título (se resuelve definitivo
           al aceptar, por si colisiona). */
        $slug = wiki_slugify($title);
    }

    /* Evita spam: máximo de pendientes por usuario. */
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM wiki_revisions WHERE author_id = ? AND status = 'pending'");
    $cnt->execute([$userId]);
    if ((int)$cnt->fetchColumn() >= 20) jsonError('Tienes demasiadas solicitudes pendientes. Espera a que las revisen.', 429);

    $ins = $pdo->prepare("INSERT INTO wiki_revisions (page_id, slug, title, body, summary, author_id, status)
                          VALUES (?, ?, ?, ?, ?, ?, 'pending')");
    $ins->execute([$pageId, $slug, $title, $body, ($summary !== '' ? $summary : null), $userId]);
    jsonResponse(['ok' => true, 'revisionId' => (int)$pdo->lastInsertId()]);
}

/* ════════════════════════════════════════════════════════════════════
   MODERACIÓN — solo admins
═════════════════════════════════════════════════════════════════════ */
if ($action === 'pending') {
    if (!wiki_isAdmin($pdo, $userId)) jsonError('No autorizado', 403);
    $st = $pdo->query("SELECT id, page_id, slug, title, body, summary, author_id, created_at
                       FROM wiki_revisions WHERE status = 'pending' ORDER BY created_at ASC LIMIT 200");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $current = null;
        if ($r['page_id'] !== null) {
            $cs = $pdo->prepare("SELECT title, body FROM wiki_pages WHERE id = ? LIMIT 1");
            $cs->execute([(int)$r['page_id']]);
            $cur = $cs->fetch(PDO::FETCH_ASSOC);
            if ($cur) $current = ['title' => $cur['title'], 'body' => $cur['body']];
        }
        $out[] = [
            'id'        => (int)$r['id'],
            'isNew'     => $r['page_id'] === null,
            'slug'      => $r['slug'],
            'title'     => $r['title'],
            'body'      => $r['body'],
            'summary'   => $r['summary'],
            'author'    => wiki_userLabel($pdo, (int)$r['author_id']),
            'createdAt' => $r['created_at'],
            'current'   => $current,
        ];
    }
    jsonResponse(['ok' => true, 'requests' => $out]);
}

if ($action === 'review') {
    if (!wiki_isAdmin($pdo, $userId)) jsonError('No autorizado', 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);
    $b        = jsonBody();
    $revId    = (int)($b['revisionId'] ?? 0);
    $decision = (string)($b['decision'] ?? '');
    $reason   = trim((string)($b['reason'] ?? ''));
    if (!$revId) jsonError('Falta revisionId');
    if (!in_array($decision, ['accept', 'decline'], true)) jsonError('Decisión inválida');

    $st = $pdo->prepare("SELECT * FROM wiki_revisions WHERE id = ? LIMIT 1");
    $st->execute([$revId]);
    $rev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rev) jsonError('Solicitud no encontrada', 404);
    if ($rev['status'] !== 'pending') jsonError('Esta solicitud ya fue revisada', 409);

    $authorId = (int)$rev['author_id'];

    if ($decision === 'decline') {
        $pdo->prepare("UPDATE wiki_revisions SET status='declined', reviewer_id=?, reason=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$userId, ($reason !== '' ? $reason : null), $revId]);
        wiki_notify($pdo, $authorId, $userId, 'wiki-rejected', [
            'type'   => 'wiki-rejected',
            'title'  => 'Edición de wiki denegada',
            'body'   => $rev['title'] . ($reason !== '' ? ' — ' . $reason : ''),
            'wikiTitle' => $rev['title'],
            'reason' => $reason,
            'url'    => (function_exists('pushNotifBaseUrl') ? pushNotifBaseUrl() : '') . 'mobile.php?pwa=1#wiki=requests',
        ]);
        jsonResponse(['ok' => true, 'status' => 'declined']);
    }

    /* accept → aplicar a wiki_pages */
    $points = 0;
    $finalSlug = $rev['slug'];
    try {
        $pdo->beginTransaction();
        if ($rev['page_id'] === null) {
            /* Página nueva: resolver slug definitivo libre. */
            $finalSlug = wiki_freeSlug($pdo, $rev['slug'] ?: $rev['title']);
            $pdo->prepare("INSERT INTO wiki_pages (slug, title, body, created_by, updated_by)
                           VALUES (?, ?, ?, ?, ?)")
                ->execute([$finalSlug, $rev['title'], $rev['body'], $authorId, $authorId]);
            $points = WIKI_POINTS_NEW;
        } else {
            $up = $pdo->prepare("UPDATE wiki_pages SET title=?, body=?, updated_by=? WHERE id=?");
            $up->execute([$rev['title'], $rev['body'], $authorId, (int)$rev['page_id']]);
            $cs = $pdo->prepare("SELECT slug FROM wiki_pages WHERE id = ? LIMIT 1");
            $cs->execute([(int)$rev['page_id']]);
            $finalSlug = $cs->fetchColumn() ?: $finalSlug;
            $points = WIKI_POINTS_EDIT;
        }
        /* Puntos de autismo al autor. */
        try {
            $pdo->prepare("UPDATE usuarios SET autismo = autismo + ? WHERE id = ?")->execute([$points, $authorId]);
            require_once dirname(__DIR__) . '/php/timbre.php';
            rt_saldo((int)$authorId);
        }
        catch (Throwable $e) { $points = 0; /* columna ausente → sin puntos, no rompe */ }

        $pdo->prepare("UPDATE wiki_revisions SET status='accepted', reviewer_id=?, reason=?, points_awarded=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$userId, ($reason !== '' ? $reason : null), $points, $revId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonError('No se pudo aplicar la revisión: ' . $e->getMessage(), 500);
    }

    wiki_notify($pdo, $authorId, $userId, 'wiki-accepted', [
        'type'   => 'wiki-accepted',
        'title'  => 'Edición de wiki aceptada',
        'body'   => $rev['title'] . ($points ? ' (+' . $points . ' autismo)' : '') . ($reason !== '' ? ' — ' . $reason : ''),
        'wikiTitle' => $rev['title'],
        'slug'   => $finalSlug,
        'points' => $points,
        'reason' => $reason,
        'url'    => (function_exists('pushNotifBaseUrl') ? pushNotifBaseUrl() : '') . 'mobile.php?pwa=1#wiki=page=' . rawurlencode($finalSlug),
    ]);
    jsonResponse(['ok' => true, 'status' => 'accepted', 'slug' => $finalSlug, 'points' => $points]);
}

jsonError('Acción no válida: ' . $action, 400);
