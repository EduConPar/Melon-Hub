<?php
/* ──────────────────────────────────────────────────────────────────────
   Enlaces de Melon Hub ("🔗 Copiar enlace")
   ──────────────────────────────────────────────────────────────────────
   Lo que se comparte es un enlace web normal (Discord y el chat no
   convierten melonhub://… en enlace):

       enlace.php?perfil=<user_key>     perfil de alguien
       enlace.php?cancion=<videoId>     pone la canción
       enlace.php?album=<albumKey>      visor del álbum (itunes:… deezer:… spotify:…)
       enlace.php?playlist=<id>         playlist (cualquiera con sesión puede verla)
       enlace.php?escuchar=<user_key>   "Escuchar conmigo" (presencia de Discord)
       enlace.php?torneo=<id>           torneo de ajedrez (el anuncio del bot en
                                        Discord y "Copiar enlace" del torneo)
       enlace.php?reto=<código>         reto de ajedrez por enlace (lo acepta
                                        quien lo abra; assets/juegos/ajedrez.php)

   enlace.php intenta abrir la app (melonhub://<tipo>/<valor> en PC,
   intent:// en Android) y, si no está, sigue en la web:
   index.php?abrir=<tipo>:<valor> lo guarda en la sesión (sobrevive al
   login) y el escritorio (desktop-base.php) o mobile.php lo recogen con
   melonTakePendingLink() y lo abren (melonOpenLink en JS).
   Las apps nativas reciben melonhub://… y lo pasan a la página como la
   misma cadena "<tipo>:<valor>".
   ────────────────────────────────────────────────────────────────────── */

/* "<tipo>:<valor>" saneado, o null si no vale. */
function melonParseLink(string $tipo, string $valor): ?string {
    $valor = trim($valor);
    switch ($tipo) {
        case 'perfil':
        case 'escuchar':
            $ok = preg_match('/^[A-Za-z0-9_-]{1,50}$/', $valor);
            break;
        case 'cancion':
            $ok = preg_match('/^[A-Za-z0-9_-]{11}$/', $valor);
            break;
        case 'album':
            $ok = preg_match('/^[a-z]{2,12}:[A-Za-z0-9_-]{1,80}$/', $valor);
            break;
        case 'playlist':
        case 'torneo':   /* torneo de ajedrez (anuncio de Discord, ajedrez-torneos.php) */
            $ok = preg_match('/^[0-9]{1,10}$/', $valor);
            break;
        case 'reto':     /* reto de ajedrez por enlace (código de ajz_retarEnlace) */
            $ok = preg_match('/^[a-z0-9]{10}$/', $valor);
            break;
        default:
            return null;
    }
    return $ok ? $tipo . ':' . $valor : null;
}

/* La misma comprobación para una cadena "<tipo>:<valor>" ya junta. */
function melonParseLinkString(string $link): ?string {
    $p = strpos($link, ':');
    if ($p === false) return null;
    return melonParseLink(substr($link, 0, $p), substr($link, $p + 1));
}

/* El enlace que espera a que se entre al escritorio / mobile.php (una
   sola vez). */
function melonTakePendingLink(): ?string {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['abrir'])) return null;
    $link = melonParseLinkString((string)$_SESSION['abrir']);
    unset($_SESSION['abrir']);
    return $link;
}
