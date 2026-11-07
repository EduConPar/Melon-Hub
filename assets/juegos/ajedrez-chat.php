<?php
/* ──────────────────────────────────────────────────────────────────────
   AJEDREZ: CHAT (decidido así)
   ──────────────────────────────────────────────────────────────────────
   Dos chats, en la misma tabla (`tipo`):
     't' = torneo (sección "Chat" al final de la ventana del torneo): lo
           lee cualquiera que abra el torneo; escriben solo los apuntados
           y quien lo organiza, en cualquier momento (inscripción, en juego
           y al acabar).
     'p' = partida (debajo de Jugadas): solo los dos jugadores, para leer
           y para escribir, también cuando ya ha acabado.
   Timbre (assets/php/timbre.php): cada mensaje reescribe rt/c/<t|p><id>.json
   ({"v"}, sin el texto: rt/ no pide sesión y una partida es privada); la
   ventana lo mira cada 2 s y solo entonces pide los mensajes nuevos
   (ajz-chat con `desde` = el último que tiene).
   ────────────────────────────────────────────────────────────────────── */

const AJZ_CHAT_LARGO = 300;    /* letras por mensaje */
const AJZ_CHAT_ABRIR = 60;     /* mensajes que se cargan al abrir */
const AJZ_CHAT_PAUSA = 800;    /* ms entre dos mensajes de la misma persona */

function ajz_chatEnsure(PDO $pdo): void {
    melon_esquema($pdo, 'ajedrez-chat', __FILE__, function (PDO $pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ajz_chat (
            id      INT AUTO_INCREMENT PRIMARY KEY,
            tipo    CHAR(1) NOT NULL,
            ref_id  INT NOT NULL,
            user_id INT NOT NULL,
            texto   VARCHAR(300) NOT NULL,
            creado  BIGINT NOT NULL,
            INDEX idx_ref (tipo, ref_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    });
}

/* ¿Puede escribir? (y, si no puede ni leerlo, error). */
function ajz_chatPuede(PDO $pdo, int $uid, string $tipo, int $id): bool {
    if ($tipo === 'p') {
        $st = $pdo->prepare("SELECT blanco_id, negro_id FROM ajz_partidas WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new InvalidArgumentException('No existe esa partida');
        if ((int)$p['blanco_id'] !== $uid && (int)$p['negro_id'] !== $uid) throw new InvalidArgumentException('No juegas esta partida');
        return true;
    }
    if ($tipo !== 't') throw new InvalidArgumentException('Chat no válido');
    $t = ajz_torCargar($pdo, $id);
    if ($t['host_id'] === $uid) return true;
    $st = $pdo->prepare("SELECT 1 FROM ajz_inscritos WHERE torneo_id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return (bool)$st->fetchColumn();
}

/* Mensajes después de `desde` (0 = los últimos AJZ_CHAT_ABRIR). */
function ajz_chatVer(PDO $pdo, int $uid, string $tipo, int $id, int $desde): array {
    $puede = ajz_chatPuede($pdo, $uid, $tipo, $id);
    /* Chat sin mensajes todavía: se le crea el archivo para que la ventana
       lo mire a él y no a la API. */
    if (!rt_existe(rt_urlChat($tipo, $id))) rt_chat($tipo, $id);
    if ($desde > 0) {
        $st = $pdo->prepare("SELECT id, user_id, texto, creado FROM ajz_chat WHERE tipo = ? AND ref_id = ? AND id > ? ORDER BY id LIMIT 200");
        $st->execute([$tipo, $id, $desde]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT id, user_id, texto, creado FROM ajz_chat WHERE tipo = ? AND ref_id = ? ORDER BY id DESC LIMIT " . AJZ_CHAT_ABRIR);
        $st->execute([$tipo, $id]);
        $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    }
    $us = bm_usuarios($pdo, array_column($rows, 'user_id'));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'    => (int)$r['id'],
            'de'    => $us[(int)$r['user_id']] ?? null,
            'texto' => (string)$r['texto'],
            'hora'  => (int)$r['creado'],
            'mio'   => (int)$r['user_id'] === $uid,
        ];
    }
    return ['mensajes' => $out, 'puede' => $puede];
}

function ajz_chatEnviar(PDO $pdo, int $uid, string $tipo, int $id, string $texto, int $desde): array {
    $texto = trim(preg_replace('/\s+/u', ' ', $texto));
    if ($texto === '') throw new InvalidArgumentException('Escribe algo');
    if (mb_strlen($texto) > AJZ_CHAT_LARGO) throw new InvalidArgumentException('Como mucho ' . AJZ_CHAT_LARGO . ' letras');
    if (!ajz_chatPuede($pdo, $uid, $tipo, $id)) throw new InvalidArgumentException('Solo pueden escribir los apuntados y quien lo organiza');
    $ahora = bm_ms();
    $st = $pdo->prepare("SELECT MAX(creado) FROM ajz_chat WHERE tipo = ? AND ref_id = ? AND user_id = ?");
    $st->execute([$tipo, $id, $uid]);
    if ($ahora - (int)$st->fetchColumn() < AJZ_CHAT_PAUSA) throw new InvalidArgumentException('Espera un momento');
    $pdo->prepare("INSERT INTO ajz_chat (tipo, ref_id, user_id, texto, creado) VALUES (?,?,?,?,?)")
        ->execute([$tipo, $id, $uid, $texto, $ahora]);
    rt_chat($tipo, $id);
    rt_vaciar();
    return ajz_chatVer($pdo, $uid, $tipo, $id, $desde);
}
