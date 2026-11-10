<?php
/* ════════════════════════════════════════════════════════════════
   send-push.php — helper genérico para mandar Web Push notifications
   a un usuario destinatario. Best-effort: errores se silencian.

   sendPushToUser(PDO $pdo, int $toUid, array $payload): void
   - $payload es un array con keys (al menos):
       title  → string visible del notificación
       body   → string subtítulo
       url    → URL de deep-link a abrir en tap (relativa al dominio)
       icon   → URL opcional del icono
       tag    → opcional, agrupa notificaciones del mismo tema
   - Limpia subscripciones muertas (HTTP 404/410).

   Notas:
   - Web Push (PWA): claves VAPID en assets/push/vapid-keys.php y la
     librería en assets/push/webpush.php.
   - App de Android: FCM (melonFcmEnviar, más abajo) con la clave de la
     cuenta de servicio de Firebase en esta carpeta (fuera de git).
═══════════════════════════════════════════════════════════════════ */

/* Devuelve la URL base ABSOLUTA del proyecto a partir de SCRIPT_NAME,
   con barra final. En XAMPP local típico: `/scrapbookOnline/`. En
   Hostinger u otros hosts donde el sitio vive en la raíz: `/`. Evita
   hardcodear `/scrapbookOnline/` en los payloads de push — eso rompía
   las notifs en producción (URL → 404). */
if (!function_exists('pushNotifBaseUrl')) {
    function pushNotifBaseUrl(): string {
        $s = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        /* Los endpoints push siempre cuelgan de /assets/<X>/api.php o
           similar. Cortar antes del primer /assets/ da exactamente la
           base del proyecto. */
        $pos = strpos($s, '/assets/');
        if ($pos !== false) return substr($s, 0, $pos) . '/';
        /* Fallback genérico: dirname de SCRIPT_NAME + '/'. */
        $d = rtrim(str_replace('\\', '/', dirname($s)), '/');
        return ($d === '' ? '/' : $d . '/');
    }
}

if (!function_exists('sendPushToUser')) {
    function sendPushToUser(PDO $pdo, int $toUid, array $payload): void {
        /* Filtro por preferencias del receptor (mismas que el dialog
           de notificaciones del perfil/ajustes móvil): si la categoría
           del payload está silenciada, no enviamos push. Mapeo de tipo
           de notif → key_name de user_settings: */
        $type = (string)($payload['type'] ?? '');
        static $TYPE_TO_KEY = [
            'chat'           => 'mute_messages',
            'message'        => 'mute_messages',
            'invite'         => 'mute_profile',
            'item-accepted'  => 'mute_profile',
            'item-rejected'  => 'mute_profile',
            'follow'         => 'mute_profile',
            'review'         => 'mute_profile',
            'post'           => 'mute_profile',
            'like'           => 'mute_social',
            'comment'        => 'mute_social',
        ];
        if (isset($TYPE_TO_KEY[$type])) {
            try {
                $st = $pdo->prepare("SELECT value FROM user_settings
                                     WHERE user_id = ? AND key_name = ? LIMIT 1");
                $st->execute([$toUid, $TYPE_TO_KEY[$type]]);
                if ($st->fetchColumn() === 'true') return;   /* silenciado */
            } catch (Throwable $_) { /* tabla ausente → no bloquea */ }
        }

        /* SELECT *: la columna `tipo` la crea la migración del perfil;
           si aún no existe, todo cuenta como Web Push. */
        $stmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$toUid]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$subs) return;

        melonPushEnviar($pdo, $subs, $payload);
    }
}

/* ─── Envío a una lista de suscripciones (Web Push y FCM) ─────────────
   push_subscriptions guarda las dos clases (columna `tipo`):
     · 'web' → PWA / navegador: endpoint + p256dh + auth (VAPID).
     · 'fcm' → app de Android: el token de Firebase va en `endpoint`
       (p256dh y auth vacíos). El WebView de la app no tiene PushManager,
       así que la APK se registra con @capacitor/push-notifications.
   Las que el servicio da por muertas se borran. Lo usan sendPushToUser
   y el push del chat (pf_tryWebPush en assets/profile/api.php). */
if (!function_exists('melonPushEnviar')) {
    function melonPushEnviar(PDO $pdo, array $subs, array $payload): void {
        $wp = null;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($subs as $s) {
            $muerta = false;
            try {
                if (($s['tipo'] ?? 'web') === 'fcm') {
                    $muerta = melonFcmEnviar((string)$s['endpoint'], $payload) === 'muerto';
                } else {
                    if ($wp === null) {
                        $wp = false;
                        if (is_file(__DIR__ . '/webpush.php') && is_file(__DIR__ . '/vapid-keys.php')) {
                            require_once __DIR__ . '/webpush.php';
                            try { $wp = new WebPush(); } catch (Throwable $e) { $wp = false; }
                        }
                    }
                    if (!$wp) continue;
                    list($code, $_resp) = $wp->send([
                        'endpoint' => $s['endpoint'],
                        'p256dh'   => $s['p256dh'],
                        'auth'     => $s['auth'],
                    ], $payloadJson, 60);
                    $muerta = ($code === 404 || $code === 410);
                }
            } catch (Throwable $e) { /* silencio */ }
            if ($muerta) {
                try { $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$s['id']]); }
                catch (Throwable $e) {}
            }
        }
    }
}

/* ─── FCM (Firebase Cloud Messaging, API HTTP v1) ─────────────────────
   Sin composer, como webpush.php. Hace falta la clave de la cuenta de
   servicio de Firebase (Configuración del proyecto → Cuentas de servicio
   → Generar nueva clave privada) en esta carpeta: un .json con
   "firebase-adminsdk" en el nombre. NUNCA en git (el repo es público y
   la clave da acceso a todo el proyecto de Firebase): está en
   .gitignore y assets/push/.htaccess no deja descargarla. Se sube a mano
   al servidor. Sin ella, los avisos a la app no salen (sin error).

   Google pide un access token OAuth2 (dura 1 h): se firma un JWT con la
   clave privada (RS256) y se cambia en oauth2.googleapis.com. Se guarda
   en fcm-token.cache 50 min para no pedirlo en cada aviso. */
if (!function_exists('melonFcmCuenta')) {
    function melonFcmCuenta(): ?array {
        static $sa = false;
        if ($sa !== false) return $sa;
        $sa = null;
        foreach (glob(__DIR__ . '/*firebase-adminsdk*.json') ?: [] as $f) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (!empty($j['client_email']) && !empty($j['private_key']) && !empty($j['project_id'])) {
                $sa = $j;
                break;
            }
        }
        return $sa;
    }
}

if (!function_exists('melonFcmAccessToken')) {
    function melonFcmAccessToken(array $sa): string {
        static $tok = '';
        if ($tok !== '') return $tok;
        $cache = __DIR__ . '/fcm-token.cache';
        if (is_file($cache) && filemtime($cache) > time() - 3000) {
            $tok = trim((string)@file_get_contents($cache));
            if ($tok !== '') return $tok;
        }
        $b64 = function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
        $now = time();
        $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
            'iss'   => $sa['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        if (!openssl_sign($jwt, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
            $GLOBALS['melonFcmUltimo'] = 'openssl_sign falló: ' . openssl_error_string();
            return '';
        }
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt . '.' . $b64($sig),
            ]),
        ]);
        $raw  = curl_exec($ch);
        if ($raw === false) $GLOBALS['melonFcmUltimo'] = 'curl token: ' . curl_error($ch);
        $resp = json_decode((string)$raw, true);
        curl_close($ch);
        $tok = (string)($resp['access_token'] ?? '');
        if ($tok !== '') @file_put_contents($cache, $tok, LOCK_EX);
        else {
            error_log('[fcm] sin access token: ' . json_encode($resp));
            if ($raw !== false) $GLOBALS['melonFcmUltimo'] = 'token OAuth: ' . substr((string)$raw, 0, 300);
        }
        return $tok;
    }
}

/* Manda un aviso a un token de la app. Devuelve 'ok', 'muerto' (token
   que ya no vale: app desinstalada o datos borrados → borrarlo) o
   'error' (otra cosa: se deja el token). */
if (!function_exists('melonFcmEnviar')) {
    function melonFcmEnviar(string $token, array $p): string {
        $sa = melonFcmCuenta();
        if (!$sa) { $GLOBALS['melonFcmUltimo'] = 'no hay clave *firebase-adminsdk*.json en assets/push/'; return 'error'; }
        if ($token === '') return 'error';
        $access = melonFcmAccessToken($sa);
        if ($access === '') return 'error';

        /* La app carga la web remota: la URL del aviso tiene que ser
           absoluta y del mismo sitio que la mandó (dev o producción). */
        $url = (string)($p['url'] ?? '');
        if ($url === '' || $url[0] === '/') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'melonhub.es');
            $url  = 'https://' . $host . ($url === '' ? pushNotifBaseUrl() . 'mobile.php' : $url);
        }
        $notif = ['channel_id' => 'avisos'];
        if (!empty($p['tag'])) $notif['tag'] = (string)$p['tag'];
        $msg = ['message' => [
            'token'        => $token,
            /* Con "notification" Android la pinta él si la app está en
               segundo plano o cerrada (icono y canal del manifest). */
            'notification' => [
                'title' => (string)($p['title'] ?? 'Melon Hub'),
                'body'  => (string)($p['body'] ?? ''),
            ],
            /* En data todo tiene que ser string. */
            'data'    => ['url' => $url, 'type' => (string)($p['type'] ?? '')],
            'android' => ['priority' => 'HIGH', 'notification' => $notif],
        ]];

        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($msg, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        /* Para la acción test-push (assets/profile/api.php). */
        $GLOBALS['melonFcmUltimo'] = $code . ' ' . ($resp !== '' ? substr($resp, 0, 500) : curl_error($ch));
        curl_close($ch);
        if ($code === 200) return 'ok';
        /* 401: el access token guardado ya no vale → se pedirá otro. */
        if ($code === 401) @unlink(__DIR__ . '/fcm-token.cache');
        /* 404 UNREGISTERED o 400 "not a valid FCM registration token". */
        if ($code === 404 || strpos($resp, 'UNREGISTERED') !== false
            || ($code === 400 && strpos($resp, 'registration token') !== false)) return 'muerto';
        error_log('[fcm] ' . $code . ' ' . substr($resp, 0, 300));
        return 'error';
    }
}

/* Helper de conveniencia: construye un payload de tipo 'invite' con los
   campos comunes y la URL de deep-link al shell móvil (?pwa=1#...). */
if (!function_exists('buildInvitePushPayload')) {
    function buildInvitePushPayload(string $source, string $title, string $body, ?string $iconUrl = null): array {
        return [
            'type'  => 'invite',
            'title' => $title,
            'body'  => $body,
            'icon'  => $iconUrl,
            'tag'   => 'invite-' . $source,
            /* Deep-link: el shell móvil parsea #notif=<source> al cargar y
               abre el sheet de invites pendientes. Usamos la base
               calculada dinámicamente — antes era `/scrapbookOnline/`
               hardcodeado y rompía las notifs en producción. */
            'url'   => pushNotifBaseUrl() . 'mobile.php?pwa=1#notif=' . $source,
        ];
    }
}
