<?php
/* ──────────────────────────────────────────────────────────────────────
   Vista previa de los enlaces compartidos (Discord, WhatsApp, Telegram…)
   ──────────────────────────────────────────────────────────────────────
   enlace.php pinta etiquetas Open Graph con esto: esas apps leen la
   página desde su servidor (sin sesión ni JS) y sacan una tarjeta con
   título, texto y una miniatura a la derecha (twitter:card = summary).
     - canción: título · artista, portada del álbum (o la de YouTube);
     - álbum: nombre, artista y nº de canciones, portada;
     - perfil: nombre, bio, foto de perfil;
     - playlist: nombre, de quién y nº de canciones, su imagen;
     - escuchar: "Escucha con <nombre>", foto de perfil.
   Todo con try/catch: si algo falla, sale la tarjeta genérica.
   ────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/music/song-info.php';

/* $base = URL absoluta de la raíz de la web (para las imágenes propias). */
function melonLinkPreview(PDO $pdo, string $tipo, string $valor, string $base): array {
    $p = ['title' => 'Melon Hub', 'description' => 'Ábrelo en Melon Hub.', 'image' => $base . 'icon.png', 'type' => 'website'];
    $abs = function (string $u) use ($base) {
        if ($u === '') return '';
        return preg_match('#^https?://#i', $u) ? $u : $base . ltrim($u, '/');
    };
    $usuario = function (string $key) use ($pdo) {
        $st = $pdo->prepare("SELECT id, user_key, username, label FROM usuarios WHERE user_key = ?");
        $st->execute([$key]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    try {
        switch ($tipo) {
        case 'cancion': {
            $s = melonSongInfo($pdo, $valor);
            if ($s['title'] === '') break;
            $p['title'] = $s['title'];
            $p['description'] = trim(($s['artist'] !== '' ? $s['artist'] . ' · ' : '') . 'Escúchala en Melon Hub');
            $p['image'] = $s['cover'] ?: 'https://i.ytimg.com/vi/' . $s['videoId'] . '/hqdefault.jpg';
            $p['type'] = 'music.song';
            break;
        }
        case 'album': {
            [$src, $id] = explode(':', $valor, 2);
            $a = json_decode((string)cacheGet('album_tracks_' . $src . '_' . $id), true);
            if (!is_array($a) || empty($a['name'])) {
                /* No abierto aún por nadie: se pide a su fuente (rápido, sin
                   auth) y se deja en la misma caché que album-tracks. */
                if ($src === 'itunes' && ctype_digit($id)) {
                    require_once __DIR__ . '/music/itunes-helpers.php';
                    $a = itunesGetAlbumTracks($id);
                } elseif ($src === 'deezer' && ctype_digit($id)) {
                    require_once __DIR__ . '/music/deezer-helpers.php';
                    $a = deezerGetAlbumTracks($id);
                }
                if (is_array($a) && !empty($a['tracks'])) {
                    cacheSet('album_tracks_' . $src . '_' . $id, json_encode($a, JSON_UNESCAPED_UNICODE), 30 * 24 * 3600);
                }
            }
            if (!is_array($a) || empty($a['name'])) break;
            $n = count($a['tracks'] ?? []);
            $p['title'] = $a['name'];
            $p['description'] = trim((!empty($a['artist']) ? 'Álbum de ' . $a['artist'] : 'Álbum')
                . ($n ? ' · ' . $n . ($n === 1 ? ' canción' : ' canciones') : ''));
            if (!empty($a['image'])) $p['image'] = $a['image'];
            $p['type'] = 'music.album';
            break;
        }
        case 'perfil':
        case 'escuchar': {
            $u = $usuario($valor);
            if (!$u) break;
            $nombre = $u['label'] ?: $u['username'];
            $foto = $abs(getUserImage($nombre));
            if ($foto !== '') $p['image'] = $foto;
            if ($tipo === 'escuchar') {
                $p['title'] = '🎧 Escucha con ' . $nombre;
                $p['description'] = 'Únete a lo que está escuchando ' . $nombre . ' en Melon Hub.';
                break;
            }
            $p['title'] = $nombre;
            $p['type'] = 'profile';
            $bio = '';
            try {
                $st = $pdo->prepare("SELECT bio FROM profile WHERE user_id = ?");
                $st->execute([(int)$u['id']]);
                $bio = trim((string)$st->fetchColumn());
            } catch (Throwable $e) { /* sin perfil */ }
            $p['description'] = $bio !== '' ? '"' . $bio . '"' : 'Perfil de ' . $nombre . ' en Melon Hub';
            break;
        }
        case 'playlist': {
            $st = $pdo->prepare("SELECT p.id, p.name, COALESCE(p.image_url,'') AS image, u.label, u.username
                                 FROM playlists p JOIN usuarios u ON p.owner_id = u.id WHERE p.id = ?");
            $st->execute([(int)$valor]);
            $pl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$pl) break;
            $st = $pdo->prepare("SELECT video_id FROM playlist_tracks WHERE playlist_id = ? ORDER BY position ASC");
            $st->execute([(int)$valor]);
            $vids = $st->fetchAll(PDO::FETCH_COLUMN);
            $n = count($vids);
            $p['title'] = $pl['name'];
            $p['description'] = 'Playlist de ' . ($pl['label'] ?: $pl['username'])
                . ' · ' . $n . ($n === 1 ? ' canción' : ' canciones');
            if ($pl['image'] !== '') {
                $p['image'] = $pl['image'];
            } elseif ($vids) {
                $s = melonSongInfo($pdo, (string)$vids[0], false);
                $p['image'] = $s['cover'] ?: 'https://i.ytimg.com/vi/' . $s['videoId'] . '/hqdefault.jpg';
            }
            $p['type'] = 'music.playlist';
            break;
        }
        }
    } catch (Throwable $e) { /* tarjeta genérica */ }
    return $p;
}
