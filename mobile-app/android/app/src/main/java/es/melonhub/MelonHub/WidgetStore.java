package es.melonhub.MelonHub;

import android.content.Context;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.SystemClock;
import android.util.Log;
import android.webkit.CookieManager;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONArray;
import org.json.JSONObject;

/* Lo que el widget de música necesita fuera de la web: en qué pantalla
   está, qué suena (lo cuenta MelonMusicPlugin), la lista de playlists y
   la carátula. Las playlists se piden a api.php con las cookies del
   WebView (la misma sesión, y melon_remember si el servidor la borró),
   así que el widget no necesita su propio inicio de sesión. */
final class WidgetStore {

    static final String SCREEN_PLAYER = "player";
    static final String SCREEN_PLAYLISTS = "playlists";
    static final String SCREEN_SONGS = "songs";

    /* Mensaje de la lista de playlists mientras está vacía. */
    static final String LIST_LOADING = "loading";
    static final String LIST_OK = "ok";
    static final String LIST_LOGIN = "login";
    static final String LIST_OFFLINE = "offline";

    private static final String PREFS = "melon_widget";
    private static final String PLAYLISTS_FILE = "widget_playlists.json";
    private static final String COVER_FILE = "widget_cover.png";
    private static final String TAG = "MelonWidget";

    private static final ExecutorService IO = Executors.newSingleThreadExecutor();
    private static String baseUrl;

    private WidgetStore() {}

    private static SharedPreferences prefs(Context ctx) {
        return ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    /* ── Pantalla ── */

    static String screen(Context ctx) {
        return prefs(ctx).getString("screen", SCREEN_PLAYER);
    }

    static String playlistId(Context ctx) {
        return prefs(ctx).getString("playlist", "");
    }

    /* Cada vez que se entra en una lista cambia el "nonce": el lanzador ve
       un adaptador nuevo y vuelve a pedir los datos (playlists frescas). */
    static void setScreen(Context ctx, String screen, String playlistId) {
        SharedPreferences.Editor e = prefs(ctx).edit()
            .putString("screen", screen)
            .putString("playlist", playlistId != null ? playlistId : "")
            .putLong("nonce", System.currentTimeMillis());
        if (SCREEN_PLAYLISTS.equals(screen)) e.putString("listStatus", LIST_LOADING);
        e.apply();
    }

    static long listNonce(Context ctx) {
        return prefs(ctx).getLong("nonce", 0);
    }

    static String listStatus(Context ctx) {
        return prefs(ctx).getString("listStatus", LIST_OK);
    }

    /* ── Lo que suena ── */

    /* La última canción se queda guardada aunque la app se cierre: el
       widget la sigue enseñando (en pausa) y ⏯ la retoma. */
    static void setNowPlaying(Context ctx, boolean playing, String title, String artist, String videoId) {
        SharedPreferences.Editor e = prefs(ctx).edit().putBoolean("playing", playing);
        /* Las webs viejas no mandan la canción al pausar: se queda la de antes. */
        if (title != null && !title.isEmpty()) {
            e.putString("title", title).putString("artist", artist != null ? artist : "");
            e.putString("videoId", videoId != null ? videoId : "");
        }
        e.apply();
        if (videoId != null && !videoId.isEmpty()) loadCover(ctx, videoId);
    }

    /* Estado completo (setWidgetState de la web): además, por dónde va y
       si está en aleatorio. La posición se apunta con la hora para ir
       avanzándola sin que la web tenga que mandarla cada segundo. */
    static void setState(Context ctx, boolean playing, String title, String artist, String videoId,
                         double position, double duration, boolean shuffle) {
        setNowPlaying(ctx, playing, title, artist, videoId);
        prefs(ctx).edit()
            .putFloat("position", (float) position)
            .putLong("positionAt", SystemClock.elapsedRealtime())
            .putFloat("duration", (float) duration)
            .putBoolean("shuffle", shuffle)
            .apply();
    }

    /* Solo el icono de ⏯, sin esperar a la web (PlayerWidget). */
    static void setPlayingFlag(Context ctx, boolean playing) {
        prefs(ctx).edit().putBoolean("playing", playing).apply();
    }

    static boolean playing(Context ctx) {
        return prefs(ctx).getBoolean("playing", false);
    }

    static String title(Context ctx) {
        return prefs(ctx).getString("title", "");
    }

    static String artist(Context ctx) {
        return prefs(ctx).getString("artist", "");
    }

    static String videoId(Context ctx) {
        return prefs(ctx).getString("videoId", "");
    }

    static double duration(Context ctx) {
        return prefs(ctx).getFloat("duration", 0);
    }

    /* Por dónde va: si suena (y la app sigue viva), lo apuntado más el
       tiempo pasado desde entonces. */
    static double position(Context ctx) {
        SharedPreferences p = prefs(ctx);
        double pos = p.getFloat("position", 0);
        if (p.getBoolean("playing", false) && MelonMusicPlugin.isAlive()) {
            pos += (SystemClock.elapsedRealtime() - p.getLong("positionAt", SystemClock.elapsedRealtime())) / 1000.0;
        }
        double dur = duration(ctx);
        return dur > 0 ? Math.min(pos, dur) : pos;
    }

    /* Cada vez que avanza la barra se apunta la posición: si Android
       cierra la app, el widget se queda donde iba. */
    static void savePosition(Context ctx) {
        prefs(ctx).edit()
            .putFloat("position", (float) position(ctx))
            .putLong("positionAt", SystemClock.elapsedRealtime())
            .apply();
    }

    static boolean shuffle(Context ctx) {
        return prefs(ctx).getBoolean("shuffle", false);
    }

    /* 🔀 con la app cerrada: se cambia aquí y se le pasa a la web cuando
       se vuelva a abrir (MelonMusicPlugin.load). */
    static void toggleShuffleOffline(Context ctx) {
        boolean on = !shuffle(ctx);
        prefs(ctx).edit().putBoolean("shuffle", on).putBoolean("pendingShuffle", true).apply();
    }

    /* null si no hay cambio pendiente. */
    static Boolean takePendingShuffle(Context ctx) {
        SharedPreferences p = prefs(ctx);
        if (!p.getBoolean("pendingShuffle", false)) return null;
        p.edit().remove("pendingShuffle").apply();
        return p.getBoolean("shuffle", false);
    }

    /* ── Interfaz y tema ── */

    /* { interface: 'win98'|'kawaii', colors: { 'win-bg': '#…', … } } */
    static void setTheme(Context ctx, JSONObject theme) {
        prefs(ctx).edit().putString("theme", theme.toString()).apply();
    }

    static String themeRaw(Context ctx) {
        return prefs(ctx).getString("theme", null);
    }

    static JSONObject theme(Context ctx) {
        try {
            String t = themeRaw(ctx);
            return t != null ? new JSONObject(t) : null;
        } catch (Exception e) {
            return null;
        }
    }

    /* Antes de que la web mande el tema: la cookie activeInterface (la
       pone mobile.php en cada carga). */
    static boolean cookieKawaii(Context ctx) {
        String cookies = cookies(ctx);
        if (cookies == null) return false;
        for (String c : cookies.split(";")) {
            String[] kv = c.trim().split("=", 2);
            if (kv.length == 2 && kv[0].equals("activeInterface")) return kv[1].equals("kawaii");
        }
        return false;
    }

    private static String cookies(Context ctx) {
        try {
            return CookieManager.getInstance().getCookie(baseUrl(ctx));
        } catch (Exception e) {
            /* Sin WebView instalado o actualizándose. */
            return null;
        }
    }

    /* server.url de capacitor.config.json: el widget habla con la misma web
       que carga la app (melonhubdev en la build de dev). */
    static synchronized String baseUrl(Context ctx) {
        if (baseUrl != null) return baseUrl;
        try (InputStream in = ctx.getAssets().open("capacitor.config.json")) {
            JSONObject cfg = new JSONObject(readAll(in));
            baseUrl = cfg.getJSONObject("server").getString("url");
        } catch (Exception e) {
            baseUrl = "https://melonhub.es/mobile.php";
        }
        return baseUrl;
    }

    /* ── Playlists ── */

    /* Se llama desde el adaptador de la lista (hilo aparte, puede tardar).
       Si falla se quedan las de la última vez. */
    /* true si la lista guardada ha cambiado. */
    static boolean fetchPlaylists(Context ctx) {
        HttpURLConnection c = null;
        String status;
        boolean changed = false;
        try {
            URL url = new URL(new URL(baseUrl(ctx)), "assets/music/api.php?action=get-playlists");
            c = (HttpURLConnection) url.openConnection();
            c.setConnectTimeout(10000);
            c.setReadTimeout(15000);
            String cookies = cookies(ctx);
            if (cookies != null) c.setRequestProperty("Cookie", cookies);
            int code = c.getResponseCode();
            keepCookies(ctx, c);
            if (code == 401) {
                status = LIST_LOGIN;
            } else if (code == 200) {
                String body;
                try (InputStream in = c.getInputStream()) {
                    body = readAll(in);
                }
                new JSONArray(body); /* que sea una lista de verdad antes de guardarla */
                File f = new File(ctx.getFilesDir(), PLAYLISTS_FILE);
                changed = !body.equals(f.exists() ? readFile(f) : null);
                if (changed) {
                    try (OutputStream out = new FileOutputStream(f)) {
                        out.write(body.getBytes(StandardCharsets.UTF_8));
                    }
                }
                status = LIST_OK;
            } else {
                status = LIST_OFFLINE;
            }
        } catch (Exception e) {
            Log.w(TAG, "No se pudieron cargar las playlists", e);
            status = LIST_OFFLINE;
        } finally {
            if (c != null) c.disconnect();
        }
        prefs(ctx).edit().putString("listStatus", status).putLong("playlistsAt", System.currentTimeMillis()).apply();
        return changed;
    }

    private static String readFile(File f) {
        try (InputStream in = new java.io.FileInputStream(f)) {
            return readAll(in);
        } catch (Exception e) {
            return null;
        }
    }

    /* Si el servidor rehízo la sesión (melon_remember), manda un PHPSESSID
       nuevo y borra el viejo: hay que dárselo al WebView o la web
       perdería la sesión. */
    private static void keepCookies(Context ctx, HttpURLConnection c) {
        Map<String, List<String>> headers = c.getHeaderFields();
        if (headers == null) return;
        for (Map.Entry<String, List<String>> h : headers.entrySet()) {
            if (h.getKey() == null || !h.getKey().equalsIgnoreCase("Set-Cookie")) continue;
            try {
                CookieManager cm = CookieManager.getInstance();
                for (String v : h.getValue()) cm.setCookie(baseUrl(ctx), v);
                cm.flush();
            } catch (Exception ignored) {}
        }
    }

    /* La lista ya leída (puede ser grande: lleva todas las canciones):
       se vuelve a leer solo si cambia el archivo. */
    private static JSONArray playlistsCache;
    private static long playlistsCacheTime = -1;

    static synchronized JSONArray playlists(Context ctx) {
        File f = new File(ctx.getFilesDir(), PLAYLISTS_FILE);
        if (!f.exists()) return new JSONArray();
        if (playlistsCache != null && f.lastModified() == playlistsCacheTime) return playlistsCache;
        try (InputStream in = new java.io.FileInputStream(f)) {
            playlistsCache = new JSONArray(readAll(in));
            playlistsCacheTime = f.lastModified();
            return playlistsCache;
        } catch (Exception e) {
            return new JSONArray();
        }
    }

    /* Si hace poco que se pidieron, no se vuelven a pedir: al entrar en
       "Mis playlists" se enseñan las guardadas al momento y se refrescan
       por detrás (WidgetListService). */
    static boolean playlistsFresh(Context ctx) {
        return System.currentTimeMillis() - prefs(ctx).getLong("playlistsAt", 0) < 20000;
    }

    static JSONObject findPlaylist(Context ctx, String id) {
        JSONArray all = playlists(ctx);
        for (int i = 0; i < all.length(); i++) {
            JSONObject pl = all.optJSONObject(i);
            if (pl != null && pl.optString("id").equals(id)) return pl;
        }
        return null;
    }

    /* ── Carátula ── */

    /* La miniatura de YouTube (mqdefault: 16:9 sin franjas negras),
       recortada a cuadrado. Se guarda en un archivo para repintar el
       widget sin volver a bajarla. */
    private static void loadCover(Context ctx, String videoId) {
        final Context app = ctx.getApplicationContext();
        if (videoId.equals(prefs(app).getString("coverId", ""))) return;
        IO.execute(() -> {
            HttpURLConnection c = null;
            try {
                c = (HttpURLConnection) new URL("https://i.ytimg.com/vi/" + videoId + "/mqdefault.jpg").openConnection();
                c.setConnectTimeout(10000);
                c.setReadTimeout(15000);
                Bitmap src;
                try (InputStream in = c.getInputStream()) {
                    src = BitmapFactory.decodeStream(in);
                }
                if (src == null) return;
                int side = Math.min(src.getWidth(), src.getHeight());
                Bitmap sq = Bitmap.createBitmap(src, (src.getWidth() - side) / 2, (src.getHeight() - side) / 2, side, side);
                try (OutputStream out = new FileOutputStream(new File(app.getFilesDir(), COVER_FILE))) {
                    sq.compress(Bitmap.CompressFormat.PNG, 100, out);
                }
                prefs(app).edit().putString("coverId", videoId).apply();
                PlayerWidget.refresh(app);
            } catch (Exception e) {
                Log.w(TAG, "No se pudo bajar la carátula", e);
            } finally {
                if (c != null) c.disconnect();
            }
        });
    }

    /* La carátula de la canción que suena, o null si aún no ha bajado. */
    private static String coverCacheId;
    private static Bitmap coverCache;

    static synchronized Bitmap cover(Context ctx) {
        String id = videoId(ctx);
        if (id.isEmpty() || !id.equals(prefs(ctx).getString("coverId", ""))) return null;
        /* Se decodifica una vez por canción, no en cada repintado. */
        if (!id.equals(coverCacheId) || coverCache == null) {
            coverCache = BitmapFactory.decodeFile(new File(ctx.getFilesDir(), COVER_FILE).getPath());
            coverCacheId = id;
        }
        return coverCache;
    }

    private static String readAll(InputStream in) throws java.io.IOException {
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        byte[] b = new byte[8192];
        int n;
        while ((n = in.read(b)) != -1) buf.write(b, 0, n);
        return buf.toString("UTF-8");
    }
}
