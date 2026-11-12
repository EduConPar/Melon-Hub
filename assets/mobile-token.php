<?php
/* ──────────────────────────────────────────────────────────────────────
   DEVICE TOKENS — auto-login persistente para la PWA móvil
   ──────────────────────────────────────────────────────────────────────
   La PWA en iOS 16.4+ tiene un cookie jar AISLADO del navegador Safari
   que la instaló — por eso una cookie de sesión "normal" no sobrevive
   el paso a standalone. Workaround: codificamos un token en el
   `start_url` del manifest. Al abrir la PWA, el OS llama a la URL con
   ?t=TOKEN; el servidor lo valida, rellena $_SESSION y redirige a la
   home limpia.

   Características:
   - Sin expiración por defecto (revocable manualmente).
   - Un token por (usuario, dispositivo) — útil para revocar el acceso
     de un móvil concreto sin echar a todos los demás.
   - Se renueva `last_used_at` cada vez que se consume.
   ────────────────────────────────────────────────────────────────────── */

if (!function_exists('mtEnsureTable')) {
    function mtEnsureTable(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        /* Una vez por versión de este archivo (melon_esquema), no en cada
           petición: ver .claude/notas/rendimiento.md. */
        require_once __DIR__ . '/php/esquema.php';
        melon_esquema($pdo, 'tokens-movil', __FILE__, function (PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_tokens (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            user_id      INT NOT NULL,
            token        VARCHAR(64) NOT NULL UNIQUE,
            user_agent   VARCHAR(255) DEFAULT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_user (user_id),
            CONSTRAINT fk_device_user FOREIGN KEY (user_id)
                REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }, true);
        $done = true;
    }
}

if (!function_exists('mtCreateToken')) {
    /* Genera un token de 64 chars hex (256 bits) y lo persiste asociado
       al usuario. Devuelve el token en claro — solo se devuelve UNA vez. */
    function mtCreateToken(PDO $pdo, int $userId, ?string $userAgent = null): string {
        mtEnsureTable($pdo);
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            "INSERT INTO device_tokens (user_id, token, user_agent) VALUES (?, ?, ?)"
        );
        $stmt->execute([$userId, $token, $userAgent ? mb_substr($userAgent, 0, 255) : null]);
        return $token;
    }
}

if (!function_exists('mtConsumeToken')) {
    /* Valida un token y devuelve el user_key (string) o null si no
       existe. Actualiza last_used_at en éxito.  */
    function mtConsumeToken(PDO $pdo, string $token): ?string {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        mtEnsureTable($pdo);
        $stmt = $pdo->prepare(
            "SELECT u.user_key, dt.id
             FROM device_tokens dt
             JOIN usuarios u ON u.id = dt.user_id
             WHERE dt.token = ?
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $pdo->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE id = ?")
            ->execute([(int)$row['id']]);
        return (string)$row['user_key'];
    }
}
