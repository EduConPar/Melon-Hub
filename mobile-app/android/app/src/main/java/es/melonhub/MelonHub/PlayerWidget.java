package es.melonhub.MelonHub;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.widget.RemoteViews;
import org.json.JSONObject;

/* Widget de música de la pantalla de inicio (2×2). Dos pantallas: el
   reproductor (carátula, canción, barra de progreso y ⏮⏯⏭; en la barra
   de título 🔀 y ≡, que lleva a las playlists) y las listas (Mis
   playlists → canciones de una playlist, con "Reproducir" arriba). Se
   pinta con la interfaz activa de la web (Win98 o Kawaii) y los colores
   del tema del usuario (WidgetTheme).

   La música la toca la web (el iframe de YouTube en el WebView), así que
   los botones le pasan órdenes por MelonMusicPlugin. La última canción
   se queda en el widget aunque la app se cierre; ⏯ (y tocar una canción)
   pasan por WidgetPlayActivity, que si hace falta abre la app y esta se
   va al fondo en cuanto empieza a sonar. */
public class PlayerWidget extends AppWidgetProvider {

    static final String ACTION_SHOW = "es.melonhub.MelonHub.widget.SHOW";
    static final String ACTION_COMMAND = "es.melonhub.MelonHub.widget.COMMAND";
    static final String EXTRA_SCREEN = "screen";
    static final String EXTRA_PLAYLIST = "playlist";
    static final String EXTRA_INDEX = "index";
    static final String EXTRA_COMMAND = "command";

    /* Un código por PendingIntent distinto: con el mismo código y la misma
       acción, FLAG_UPDATE_CURRENT pisaría los extras del otro. */
    private static final int REQ_TO_PLAYER = 1;
    private static final int REQ_TO_PLAYLISTS = 2;
    private static final int REQ_PREV = 3;
    private static final int REQ_TOGGLE = 4;
    private static final int REQ_NEXT = 5;
    private static final int REQ_PLAY_ALL = 6;
    private static final int REQ_PLAYLIST_ROW = 7;
    private static final int REQ_SONG_ROW = 8;
    private static final int REQ_SHUFFLE = 9;

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        render(ctx, mgr, ids);
    }

    @Override
    public void onReceive(Context ctx, Intent intent) {
        super.onReceive(ctx, intent);
        String action = intent.getAction();
        if (ACTION_SHOW.equals(action)) {
            /* La fila de aviso de una lista vacía no lleva pantalla. */
            if (intent.getStringExtra(EXTRA_SCREEN) == null) return;
            WidgetStore.setScreen(ctx, intent.getStringExtra(EXTRA_SCREEN), intent.getStringExtra(EXTRA_PLAYLIST));
            refresh(ctx);
        } else if (ACTION_COMMAND.equals(action)) {
            String command = intent.getStringExtra(EXTRA_COMMAND);
            try {
                if (MelonMusicPlugin.sendWidgetCommand(new JSONObject().put("action", command))) {
                    /* ⏯ cambia de icono ya; la web lo confirma al pausar. */
                    if ("toggle".equals(command)) {
                        WidgetStore.setPlayingFlag(ctx, !WidgetStore.playing(ctx));
                        refresh(ctx);
                    }
                    return;
                }
            } catch (Exception ignored) {}
            /* Sin app en marcha: 🔀 se apunta para cuando se abra. */
            if ("shuffle".equals(command)) WidgetStore.toggleShuffleOffline(ctx);
            refresh(ctx);
        }
    }

    /* La web suele avisar varias veces seguidas (setPlaying, setWidgetState,
       la carátula…): se juntan en un solo repintado. */
    private static final Handler MAIN = new Handler(Looper.getMainLooper());
    private static Context refreshCtx;
    private static final Runnable REFRESH = () -> {
        Context c = refreshCtx;
        if (c != null) refreshNow(c);
    };

    static void refresh(Context ctx) {
        refreshCtx = ctx.getApplicationContext();
        MAIN.removeCallbacks(REFRESH);
        MAIN.postDelayed(REFRESH, 30);
    }

    private static void refreshNow(Context ctx) {
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        int[] ids = mgr.getAppWidgetIds(new ComponentName(ctx, PlayerWidget.class));
        if (ids.length > 0) render(ctx, mgr, ids);
    }

    /* Solo la barra de progreso (cada 5 s mientras suena): sin repintar
       todo el widget. */
    static void refreshProgress(Context ctx) {
        if (!WidgetStore.SCREEN_PLAYER.equals(WidgetStore.screen(ctx))) return;
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        int[] ids = mgr.getAppWidgetIds(new ComponentName(ctx, PlayerWidget.class));
        if (ids.length == 0) return;
        WidgetTheme t = WidgetTheme.load(ctx);
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_player : R.layout.widget_w98_player);
        rv.setImageViewBitmap(R.id.w_prog_img, progressBitmap(ctx, t));
        mgr.partiallyUpdateAppWidget(ids, rv);
    }

    private static void render(Context ctx, AppWidgetManager mgr, int[] ids) {
        WidgetTheme t = WidgetTheme.load(ctx);
        String screen = WidgetStore.screen(ctx);
        for (int id : ids) {
            float w = widthDp(mgr, id);
            RemoteViews rv = WidgetStore.SCREEN_PLAYER.equals(screen)
                ? playerViews(ctx, t, w)
                : listViews(ctx, t, screen, id, w);
            mgr.updateAppWidget(id, rv);
        }
    }

    /* Ancho del widget en dp (en vertical, el mínimo que da el lanzador),
       para cortar los textos con "…" a lo que cabe. */
    static float widthDp(AppWidgetManager mgr, int id) {
        Bundle o = mgr.getAppWidgetOptions(id);
        int w = o != null ? o.getInt(AppWidgetManager.OPTION_APPWIDGET_MIN_WIDTH, 0) : 0;
        return w > 0 ? w : 160;
    }

    /* Lo que ocupan bordes, márgenes y botones alrededor de cada texto
       (dp, con un poco de holgura), según los layouts de cada interfaz. */
    private static float room(WidgetTheme t, float w98, float kawaii) {
        return t.kawaii ? kawaii : w98;
    }

    private static RemoteViews playerViews(Context ctx, WidgetTheme t, float w) {
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_player : R.layout.widget_w98_player);
        WidgetPainter p = new WidgetPainter(ctx, rv, t);
        p.frame();
        p.titleBar();
        p.button(R.id.w_shuf_1, R.id.w_shuf, R.id.w_shuf_ic, WidgetStore.shuffle(ctx));
        p.button(R.id.w_menu_1, R.id.w_menu, R.id.w_menu_ic, false);
        p.field(R.id.w_cov_1, R.id.w_cov);
        p.field(R.id.w_prog_1, R.id.w_prog);
        p.button(R.id.w_prev_1, R.id.w_prev, R.id.w_prev_ic, false);
        p.button(R.id.w_play_1, R.id.w_play, R.id.w_play_ic, false);
        p.button(R.id.w_next_1, R.id.w_next, R.id.w_next_ic, false);
        /* La nota de la carátula vacía: en Win98, el gris del texto
           secundario (el relieve oscuro se confundía con el fondo en temas
           oscuros). */
        rv.setInt(R.id.w_cover_ph, "setColorFilter", t.kawaii ? t.accent : t.muted);

        /* La última canción sigue aunque la app esté cerrada (en pausa). */
        String title = WidgetStore.title(ctx);
        boolean playing = WidgetStore.playing(ctx) && MelonMusicPlugin.isAlive();
        Bitmap cover = title.isEmpty() ? null : WidgetStore.cover(ctx);
        String song = ctx.getString(R.string.widget_idle);
        if (!title.isEmpty()) {
            String artist = WidgetStore.artist(ctx);
            song = artist.isEmpty() ? title : title + " · " + artist;
        }
        rv.setImageViewBitmap(R.id.w_text, WidgetText.draw(ctx, t, WidgetText.BODY, song, 12, t.text, w - room(t, 16, 26), 1, true));
        rv.setImageViewBitmap(R.id.w_title, WidgetText.draw(ctx, t, WidgetText.TITLE, ctx.getString(R.string.widget_title), 13, t.tbText, w - room(t, 52, 62), 1, false));
        if (cover != null) {
            rv.setImageViewBitmap(R.id.w_cover, cover);
            rv.setViewVisibility(R.id.w_cover, View.VISIBLE);
            rv.setViewVisibility(R.id.w_cover_ph, View.GONE);
        } else {
            rv.setViewVisibility(R.id.w_cover, View.GONE);
            rv.setViewVisibility(R.id.w_cover_ph, View.VISIBLE);
        }
        rv.setImageViewResource(R.id.w_play_ic, playing ? R.drawable.widget_ic_pause : R.drawable.widget_ic_play);
        rv.setImageViewBitmap(R.id.w_prog_img, progressBitmap(ctx, t));

        rv.setOnClickPendingIntent(R.id.w_menu_1, show(ctx, REQ_TO_PLAYLISTS, WidgetStore.SCREEN_PLAYLISTS, null));
        rv.setOnClickPendingIntent(R.id.w_shuf_1, command(ctx, REQ_SHUFFLE, "shuffle"));
        /* Mientras suena, ⏮⏯⏭ van directos a la web (rápido). Si no, pasan
           por una actividad (WidgetPlayActivity): ⏯ puede tener que abrir
           la app, y los tres ponen música desde una pausa, que necesita
           arrancar el servicio de música desde ahí. Abrir la actividad es
           lo lento (sobre todo en Xiaomi), así que solo cuando hace falta. */
        if (playing) {
            rv.setOnClickPendingIntent(R.id.w_prev_1, command(ctx, REQ_PREV, "prev"));
            rv.setOnClickPendingIntent(R.id.w_play_1, command(ctx, REQ_TOGGLE, "toggle"));
            rv.setOnClickPendingIntent(R.id.w_next_1, command(ctx, REQ_NEXT, "next"));
        } else {
            rv.setOnClickPendingIntent(R.id.w_prev_1, control(ctx, REQ_PREV, "prev"));
            rv.setOnClickPendingIntent(R.id.w_play_1, control(ctx, REQ_TOGGLE, "toggle"));
            rv.setOnClickPendingIntent(R.id.w_next_1, control(ctx, REQ_NEXT, "next"));
        }
        return rv;
    }

    private static PendingIntent control(Context ctx, int req, String command) {
        return PendingIntent.getActivity(ctx, req, playIntent(ctx).putExtra(EXTRA_COMMAND, command),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static RemoteViews listViews(Context ctx, WidgetTheme t, String screen, int widgetId, float w) {
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_list : R.layout.widget_w98_list);
        WidgetPainter p = new WidgetPainter(ctx, rv, t);
        p.frame();
        p.titleBar();
        p.button(R.id.w_back_1, R.id.w_back, R.id.w_back_ic, false);
        p.button(R.id.w_pa_1, R.id.w_pa, R.id.w_pa_ic, false);
        rv.setImageViewBitmap(R.id.w_pa_text, WidgetText.draw(ctx, t, WidgetText.BODY, ctx.getString(R.string.widget_play_all), 12, t.btnText, w, 1, false));
        p.field(R.id.w_lst_1, R.id.w_lst);
        float titleRoom = w - room(t, 34, 45);
        /* Mientras carga; los avisos (sin sesión, sin playlists…) son una
           fila de la lista (WidgetListService). */
        rv.setImageViewBitmap(R.id.w_empty, WidgetText.draw(ctx, t, WidgetText.BODY, ctx.getString(R.string.widget_loading),
            12, t.muted, w - room(t, 36, 42), 1, true));

        boolean songs = WidgetStore.SCREEN_SONGS.equals(screen);
        String playlistId = WidgetStore.playlistId(ctx);

        PendingIntent back = songs
            ? show(ctx, REQ_TO_PLAYLISTS, WidgetStore.SCREEN_PLAYLISTS, null)
            : show(ctx, REQ_TO_PLAYER, WidgetStore.SCREEN_PLAYER, null);
        rv.setOnClickPendingIntent(R.id.w_back_1, back);
        rv.setOnClickPendingIntent(R.id.w_title, back);

        if (songs) {
            JSONObject pl = WidgetStore.findPlaylist(ctx, playlistId);
            rv.setImageViewBitmap(R.id.w_title, WidgetText.draw(ctx, t, WidgetText.TITLE, pl != null ? pl.optString("name") : "", 13, t.tbText, titleRoom, 1, false));
            rv.setViewVisibility(R.id.w_pa_1, View.VISIBLE);
            rv.setOnClickPendingIntent(R.id.w_pa_1,
                PendingIntent.getActivity(ctx, REQ_PLAY_ALL, playIntent(ctx).putExtra(EXTRA_PLAYLIST, playlistId).putExtra(EXTRA_INDEX, 0),
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
            /* Cada fila añade su índice (setOnClickFillInIntent). */
            rv.setPendingIntentTemplate(R.id.w_list,
                PendingIntent.getActivity(ctx, REQ_SONG_ROW, playIntent(ctx), PendingIntent.FLAG_UPDATE_CURRENT | mutable()));
        } else {
            rv.setImageViewBitmap(R.id.w_title, WidgetText.draw(ctx, t, WidgetText.TITLE, ctx.getString(R.string.widget_my_playlists), 13, t.tbText, titleRoom, 1, false));
            rv.setViewVisibility(R.id.w_pa_1, View.GONE);
            Intent row = new Intent(ctx, PlayerWidget.class).setAction(ACTION_SHOW);
            rv.setPendingIntentTemplate(R.id.w_list,
                PendingIntent.getBroadcast(ctx, REQ_PLAYLIST_ROW, row, PendingIntent.FLAG_UPDATE_CURRENT | mutable()));
        }

        /* La URI distinta por pantalla, playlist, interfaz y entrada hace
           que el lanzador cree un adaptador nuevo (y pida datos) al
           cambiar. Si solo cambian los colores del tema, el tema va en la
           URI también para que las filas se repinten. */
        Intent adapter = new Intent(ctx, WidgetListService.class)
            .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId)
            .putExtra("rowRoomDp", w - room(t, songs ? 34 : 49, songs ? 40 : 55))
            .putExtra(EXTRA_SCREEN, screen)
            .putExtra(EXTRA_PLAYLIST, playlistId);
        JSONObject theme = WidgetStore.theme(ctx);
        adapter.setData(Uri.parse("melonwidget://" + widgetId + "/" + screen + "/" + Uri.encode(playlistId)
            + "/" + (t.kawaii ? "kw" : "w98") + "/" + WidgetStore.listNonce(ctx)
            + "/" + (theme != null ? theme.toString().hashCode() : 0) + "/" + Math.round(w)));
        rv.setRemoteAdapter(R.id.w_list, adapter);
        rv.setEmptyView(R.id.w_list, R.id.w_empty);
        return rv;
    }

    /* Barra de progreso: relleno del color de acento hasta donde va, el
       resto del fondo del campo. Se estira a lo ancho (fitXY). */
    private static Bitmap progressBitmap(Context ctx, WidgetTheme t) {
        double dur = WidgetStore.duration(ctx);
        double ratio = dur > 0 ? Math.max(0, Math.min(1, WidgetStore.position(ctx) / dur)) : 0;
        if (WidgetStore.title(ctx).isEmpty()) ratio = 0;
        int w = 200;
        int filled = (int) Math.round(ratio * w);
        Bitmap b = Bitmap.createBitmap(w, 1, Bitmap.Config.ARGB_8888);
        for (int x = 0; x < w; x++) b.setPixel(x, 0, x < filled ? t.accent : t.inputBg);
        return b;
    }

    /* Aviso de "Mis playlists" cuando no hay ninguna que enseñar. */
    static String playlistsMessage(Context ctx) {
        switch (WidgetStore.listStatus(ctx)) {
            case WidgetStore.LIST_LOGIN: return ctx.getString(R.string.widget_login);
            case WidgetStore.LIST_OFFLINE: return ctx.getString(R.string.widget_offline);
            default: return ctx.getString(R.string.widget_no_playlists);
        }
    }

    private static Intent playIntent(Context ctx) {
        return new Intent(ctx, WidgetPlayActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    private static PendingIntent show(Context ctx, int req, String screen, String playlistId) {
        Intent i = new Intent(ctx, PlayerWidget.class).setAction(ACTION_SHOW)
            .putExtra(EXTRA_SCREEN, screen)
            .putExtra(EXTRA_PLAYLIST, playlistId);
        return PendingIntent.getBroadcast(ctx, req, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static PendingIntent command(Context ctx, int req, String command) {
        Intent i = new Intent(ctx, PlayerWidget.class).setAction(ACTION_COMMAND).putExtra(EXTRA_COMMAND, command);
        return PendingIntent.getBroadcast(ctx, req, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /* Las plantillas de las filas tienen que poder recibir los extras de
       cada fila. */
    private static int mutable() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ? PendingIntent.FLAG_MUTABLE : 0;
    }
}
