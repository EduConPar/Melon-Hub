# 09 — Base de datos

MariaDB 10.4 / MySQL 8 con charset `utf8mb4`. ~54 tablas. Storage engine InnoDB para todo (necesitamos FKs y transacciones).

Nombre local: `scrapbook_melon`. Nombre en Hostinger producción: `u546786567_melonhub` (los dumps usan SED para renombrar al exportar a producción).

## Tablas centrales

### `usuarios` — fuente de verdad de identidad

| Columna | Tipo |
|---|---|
| id | INT PK auto |
| user_key | VARCHAR(50) UNIQUE |
| username | VARCHAR(50) UNIQUE |
| label | VARCHAR(50) |
| password | VARCHAR(255) (bcrypt) |
| autismo | INT NOT NULL DEFAULT 10 |
| discord_user_id | VARCHAR(40) NULL |
| created_at | TIMESTAMP DEFAULT current_timestamp |

Mediorrepo referencia esta tabla por FK. Ver [01-autenticacion.md](01-autenticacion.md).

### `user_settings` — preferencias key-value por user

| Columna | Tipo |
|---|---|
| user_id | INT FK |
| key_name | VARCHAR(80) |
| value | TEXT (JSON, con CHECK json_valid) |
| updated_at | TIMESTAMP |

`PRIMARY KEY (user_id, key_name)`.

Keys usadas:

- `active_interface_slug` — `"win98"` o `"kawaii"`.
- `icon_pack:<interface>` — pack de iconos por interfaz.
- `active_haro` — id del haro activo.
- `active_mascota_skin` — slug del skin de mascota.
- ...

Para upsert:

```sql
INSERT INTO user_settings (user_id, key_name, value)
VALUES (?, ?, ?)
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP;
```

### `parejas` — relación entre dos users

| Columna | Tipo |
|---|---|
| id | INT PK |
| usuario1_id | INT FK |
| usuario2_id | INT FK |
| desde | DATE |
| creada_en | TIMESTAMP |

`UNIQUE` compuesto bidireccional NO está implementado (se asume convención `usuario1_id < usuario2_id` al insertar). FKs sin CASCADE (`RESTRICT`) — borrar un user con pareja activa requiere cleanup explícito.

## Tablas por subsistema

### Tienda

- `tienda_items` — catálogo. Ver [03-tienda.md](03-tienda.md).
- `tienda_compras` — historial de compras.
- `donaciones` — donaciones de Ko-fi.

### Temas

- `themes` — temas por user/interfaz.
- `theme_download_rewards` — dedupe de premio +50 por descarga.

### Social

- `posts`, `post_likes`, `post_comments` — publicaciones públicas.
- `follows` — relación de seguimiento.
- `chats`, `messages` — mensajería 1-a-1.
- `chat_mutes`, `chat_nicknames` — preferencias por chat.
- `user_presence` — heartbeat + focused chat.
- `notifications` — bell notifications internas.
- `push_subscriptions` — endpoints VAPID.
- `device_tokens` — multi-device session.

### Perfil + listas

- `profile` — extensiones del user.
- `list_items` — series, pelis, juegos, libros, ...
- `list_item_collaborators` — quién colabora en qué item.
- `item_invites` — invitaciones a colaborar.

### Calendario

- `momentos` — momentos con foto.
- `recordatorios` — calendario.
- `reminder_notifs_sent` — dedupe de notifs de recordatorio (7/2/1 días antes).
- `partner_invites` — invitaciones a emparejarse.

### Mascota

- `mascotas`, `mascota_gustos`, `mascota_memoria`, `mascota_objetos`. Ver [05-mascota.md](05-mascota.md).

### Música

- `playlists`, `playlist_items`, `playlist_collaborators`, `playlist_invites`.
- `music_plays` — historial para Wrapped.
- `music_extras` — preferencias del player (Last.fm key, vol, etc.).
- `music_album_actions` — likes a álbumes.
- `now_playing` — qué está sonando ahora (para mostrar a follows).
- `listening_sessions`, `listening_participants`, `listening_invites` — Listen-Together.

### Galería / Discord

- `webhook_posts` — mapping de post local a `message_id` de Discord. Disponible para integraciones externas (p. ej. un bot de Discord en otro repo) que necesiten resolver qué post corresponde a un mensaje del canal.
- `webhook_reactions` — dedupe de premios de corazón.

### Cache

- `app_cache (key_name, value, expires_at)` — cache genérico de respuestas externas (Spotify tokens, YouTube responses, etc.). TTL configurable por entrada. Helpers en `assets/cache-helpers.php`:

```php
cacheGet('yt_<hash>')          // devuelve null si no existe o expiró
cacheSet('yt_<hash>', $value, 3600)
```

### Escritorio

- `desktop_icons` — posiciones custom de iconos en el escritorio del user.
- `desktop_folders` — carpetas del escritorio.

### TV / Pareada

- `tv_pairs` — emparejar una TV (tv.php): token del QR y código de 6 cifras, 10 min (la vieja `tv_link_codes` ya no se usa).

## Convenciones de FKs

- **CASCADE on user delete**: la mayoría (chats, follows, posts, comments, etc.).
- **SET NULL on user delete**: `notifications.from_user_id`, `list_items.shared_from` (queremos preservar el registro aunque el origen desaparezca).
- **RESTRICT (sin CASCADE)**: `parejas`, `recordatorios`. Hay que limpiarlos manualmente antes de DELETE FROM usuarios.
- **Tablas SIN FK declarada** (orphan rows posibles): `tienda_compras`, `mascotas`, `chat_mutes`, `chat_nicknames`, `listening_invites`, `listening_participants`, `listening_sessions`, `music_album_actions`, `music_plays`, `now_playing`, `ocs`, `oc_categorias`, `push_subscriptions`, `reminder_notifs_sent`, `user_presence`, `webhook_posts`, `momentos`. Limpia explícitamente al borrar users.

Para una limpieza masiva ver el patrón en `delete-user.php`.

## Dumps SQL

Tres archivos en la raíz del repo (todos en `.gitignore`):

| Archivo | Para qué |
|---|---|
| `scrapbook_melon.sql` | Dump completo (schema + datos) de la BD local. ~1.3 MB. Backup. |
| `u546786567_melonhub.sql` | Mismo dump pero con `CREATE DATABASE` y `USE` apuntando a `u546786567_melonhub`. Listo para importar en Hostinger via phpMyAdmin. |
| `u546786567_melonhub_schema.sql` | Solo schema (sin INSERTs), apunta a Hostinger. ~45 KB. Para arrancar de cero en producción. |

### Comando para regenerar los 3

```bash
cd /opt/lampp/htdocs/scrapbookOnline

# Dump completo local
/opt/lampp/bin/mysqldump -u root --databases scrapbook_melon \
    --single-transaction --quick --triggers --default-character-set=utf8mb4 \
    > scrapbook_melon.sql

# Dump completo renombrado a Hostinger
/opt/lampp/bin/mysqldump -u root --databases scrapbook_melon \
    --single-transaction --quick --triggers --default-character-set=utf8mb4 \
    | sed 's/`scrapbook_melon`/`u546786567_melonhub`/g; s/Database: scrapbook_melon/Database: u546786567_melonhub/g; s/Current Database: `scrapbook_melon`/Current Database: `u546786567_melonhub`/g' \
    > u546786567_melonhub.sql

# Schema-only renombrado
/opt/lampp/bin/mysqldump -u root --databases scrapbook_melon \
    --no-data --single-transaction --triggers --default-character-set=utf8mb4 \
    | sed 's/`scrapbook_melon`/`u546786567_melonhub`/g; s/Database: scrapbook_melon/Database: u546786567_melonhub/g; s/Current Database: `scrapbook_melon`/Current Database: `u546786567_melonhub`/g' \
    > u546786567_melonhub_schema.sql
```

### Por qué `--no-routines --no-events`

Por defecto `mysqldump` exporta routines (procedures, functions) y events. En entornos como XAMPP a veces dan error si `mysql.proc` está desactualizada (`Column count is wrong`) o el event scheduler está off. La Melon Hub no usa procedures ni events, así que se pueden omitir.

## Migraciones

NO hay framework de migraciones (al estilo Rails/Laravel). Los cambios de schema se hacen ad-hoc con `ALTER TABLE` directos en phpMyAdmin o vía CLI, y se regenera el dump.

Para tablas creadas en runtime (auto-migración blandas), el patrón es `CREATE TABLE IF NOT EXISTS` al inicio del endpoint que la usa, dentro de un try/catch silencioso:

```php
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reminder_notifs_sent (
            recordatorio_id INT NOT NULL,
            user_id INT NOT NULL,
            threshold INT NOT NULL,
            occurrence_date DATE NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (recordatorio_id, user_id, threshold, occurrence_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) { /* ya existe o no hay permiso */ }
```

Para columnas nuevas en tablas existentes, similar pero con `SHOW COLUMNS` previo (ver `_ensureThemesInterfaceColumn()` en `assets/themes/theme-helpers.php` como ejemplo).

## Backup en producción

Hostinger ofrece backups automáticos semanales. Para backup manual:

1. hPanel → Bases de datos → phpMyAdmin → Exportar → Custom → utf8mb4 → Continuar.
2. O via SSH: `mysqldump -u <user> -p u546786567_melonhub > backup_$(date +%Y%m%d).sql`.

Restore: phpMyAdmin → Importar → archivo SQL.

## Strict mode en producción

Hostinger MySQL viene con `sql_mode=STRICT_TRANS_TABLES` por defecto. Esto rechaza INSERTs que pasan `NULL` a columnas `NOT NULL`. Localmente XAMPP suele tener strict mode desactivado → bugs invisibles que aparecen al deploy.

Caso real: `recordatorios.pareja_id` es `NOT NULL DEFAULT 0`, pero el código pasaba `null` para usuarios sin pareja → en local funcionaba (coerción silenciosa a 0), en Hostinger 500. El fix fue pasar `0` explícitamente.

Para detectar estos casos pronto, fuerza strict mode en local:

```sql
SET GLOBAL sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
```

(Hasta que reinicies MySQL.)
