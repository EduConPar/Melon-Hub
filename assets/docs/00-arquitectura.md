# 00 — Arquitectura general

## Stack

- **Backend**: PHP 8.0+ vainilla, sin frameworks. Apache sirve los `.php` directamente.
- **Base de datos**: MariaDB 10.4 / MySQL 8 (utf8mb4). PDO con prepared statements.
- **Frontend**: HTML/CSS/JS sin build step. Sin React/Vue/etc. JS organizado en IIFEs.
- **Bot**: Node.js 20+, discord.js v14, mysql2.
- **APIs externas**: Discord, Ko-fi (webhook), Spotify, Tidal, YouTube (Innertube reverse-engineered), Last.fm, Google Drive (OAuth).
- **PWA**: Service Worker (`service-worker.js`) + manifest dinámico (`manifest.php`).
- **Estilo Win98**: variantes de [98.css](https://github.com/jdan/98.css) extendidas en `assets/css/base.css`.

## Estructura de directorios

```
scrapbookOnline/
├── index.php                  # selector de usuarios + login
├── login-manual.php           # login con username/password manual
├── register-user.php          # registro de nuevos users
├── delete-user.php            # baja de cuenta
├── logout.php                 # cierra sesión
├── mobile.php                 # shell del feature-phone móvil
├── mobile-landing.php         # landing para instalación PWA en móvil
├── desktop-base.php           # shell del escritorio Win98 (incluye TODAS las apps)
├── manifest.php               # PWA manifest dinámico
├── service-worker.js          # SW para push y PWA
├── setup-https.sh             # script para SSL local (XAMPP)
├── tv.php                     # TV: suena aquí, el móvil de mando (📺)
│
├── desktops/                  # stubs <label>-desktop.php (un PHP de 1 línea por user)
│   └── <user>-desktop.php
│
├── apps/                      # apps cargadas en iframes desde el shell
│   ├── perfil.php             # perfil + chat + listas + posts
│   ├── calendario.php         # calendario de pareja
│   ├── reproductor.php        # música
│   ├── melonarchive.php       # YouTube archive
│   ├── galeria.php            # galería + posts a Discord
│   ├── tienda.php             # tienda con economía
│   ├── temas.php              # editor de temas
│   ├── mascota.php            # mascota virtual
│   ├── dnd.php                # fichas D&D + tirador de dados
│   ├── wrapped.php            # Wrapped anual
│   ├── companion.php          # companion app (extra)
│   ├── dibujo.php             # canvas colaborativo
│   └── mobile/                # versiones móviles standalone
│       ├── perfil-mobile.php
│       ├── chat-mobile.{php,js}
│       ├── temas-mobile.php
│       └── ...
│
├── assets/
│   ├── auth.php               # helpers de auth para APIs
│   ├── config.php             # carga de .env + getters de assets
│   ├── mobile-detect.php      # detección de móvil/tablet por UA
│   ├── cache-helpers.php      # cache de respuestas externas en BD
│   ├── push/                  # generación de subscripciones VAPID
│   ├── interfaces/            # packs visuales (win98, kawaii)
│   ├── themes/                # API de temas, CSS generados
│   ├── tienda/                # API de tienda + Ko-fi webhook
│   ├── couple/                # API de pareja/calendario
│   ├── profile/               # API de perfil/social/chat
│   ├── music/                 # API de player/playlists
│   ├── discord-oauth/         # OAuth flow + role assign
│   ├── personalize/           # API de packs comprados
│   ├── mascota/               # API y assets de mascota
│   ├── desktop/               # API del escritorio (posiciones iconos)
│   ├── img/                   # assets gráficos
│   ├── css/                   # base.css, tokens.css, themes.css...
│   ├── js/                    # helpers JS compartidos
│   ├── sound/                 # SFX (notificacion.wav)
│   └── docs/                  # esta documentación
│
├── uploads/                   # subidas de usuarios (gitignored)
│   └── momentos/
│
├── logs/                      # logs varios
├── .env                       # credenciales (gitignored)
├── .htaccess                  # reglas Apache
└── *.sql                      # dumps de BD (gitignored)
```

## Flujo de una petición típica (escritorio)

1. **Usuario abre `/scrapbookOnline/`** → `index.php` carga `assets/config.php` (que lee `.env` y rellena `$loginUsers` con la tabla `usuarios`). Renderiza el selector con avatares.
2. **Click en un avatar** → guarda `selectedUser` en cookie, muestra ventana de password.
3. **Envío del password** → `password_verify()` contra `usuarios.password` (bcrypt). Si OK, `$_SESSION['user']` se setea y `header('Location: desktops/<label>-desktop.php')`.
4. **`desktops/<label>-desktop.php`** es un archivo de UNA línea: `<?php $desktopLabel = '<Label>'; require __DIR__ . '/../desktop-base.php';`
5. **`desktop-base.php`** valida sesión, calcula interfaz activa (cookie + BD), tema activo, wallpaper, start-icon, y emite todo el shell Win98 + las apps en iframes.
6. **Las apps** dentro de los iframes piden datos a sus respectivas APIs (`assets/<app>/api.php`) vía `fetch()` con `credentials: 'same-origin'` para reusar la sesión.

## Flujo móvil

1. Móvil entra a la URL principal → `index.php` detecta móvil vía `assets/mobile-detect.php` (UA: iPhone, Android Mobile, etc.) → redirige a `mobile-landing.php`.
2. **`mobile-landing.php`** decide entre:
   - "Instala la PWA" (si `display-mode` NO es standalone)
   - Redirige a `mobile.php` (si ya es PWA)
3. **`mobile.php`** es el feature-phone: lista de apps. Cada tap abre `apps/mobile/<app>-mobile.php` dentro de un iframe `#app-frame`.
4. El iframe app envía `shell:back` postMessage para volver al menú.

## Flujo tablet

Las tablets entran al escritorio igual que un PC, pero con `body.is-tablet` y `assets/css/tablet.css` cargados (taskbar 44px, ventanas escalables, touch). Ver `assets/mobile-detect.php` → `isTabletDevice()`.

## Identificadores de usuario

Tres formas, cada una para su contexto:

| Campo | Tipo | Ejemplo | Para qué |
|---|---|---|---|
| `usuarios.id` | INT PK | 1, 2, 7 | FKs internas a `usuarios`, queries SQL. |
| `usuarios.user_key` | VARCHAR | `user1`, `user2` | Identificador URL-safe y serializable. Lo que va en `$_SESSION['user']` y cookies. |
| `usuarios.label` | VARCHAR | `Capi`, `Angie` | Nombre visible. Lo usan paths de archivos (`Capi.jpg`), CSS class (`.Capi-Capi`), etc. |
| `usuarios.username` | VARCHAR | `capi`, `angie` | Lowercase de label, único. Usado para login manual + matching. |

**Convención**: el código nunca asume que `id` es estable entre instalaciones — por eso los assets se nombran por `label` (humano) y la BD se referencia por `user_key` o `id` interno según contexto.

## Convenciones de naming

- `<algo>-api.php` o `assets/<feature>/api.php`: endpoint REST de un sistema. Switch por `?action=...`.
- `<algo>-helpers.php`: utilidades sin estado, ejecutadas via `require_once`.
- `<algo>-stream.php`: SSE (Server-Sent Events).
- `assets/<feature>/save-*.php`: subidas de archivo (multipart POST).
- Cookies de feature: `<feature>For_<scope>` (ej. `themeFor_win98`).
- Settings de usuario en BD: tabla `user_settings` con `key_name` como string (ej. `active_interface_slug`, `icon_pack:win98`).

## Dependencias externas frontend

Cargadas por CDN (no hay build step):

- `html2canvas` 1.4.1 — captura DOM como PNG (wrapped).
- `JSZip` 3.10.1 — exporta capas de dibujo.
- `three.js` (versión específica en `dnd.php`) — dados 3D.
- `excalidraw` iframe — dibujo simple antiguo (sustituido por canvas propio).
- Ko-fi widget — donaciones embebidas.

## Documentos relacionados

- [08-base-datos.md](08-base-datos.md) — esquema completo.
- [01-autenticacion.md](01-autenticacion.md) — auth en detalle.
- [07-pwa-movil.md](07-pwa-movil.md) — PWA y mobile flow.
