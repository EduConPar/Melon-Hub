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
import android.view.View;
import android.widget.RemoteViews;
import org.json.JSONObject;

/* Widget de música de la pantalla de inicio. Dos pantallas: el
   reproductor (carátula, canción y ⏮⏯⏭; el botón ≡ lleva a las
   playlists) y las listas (Mis playlists → canciones de una playlist,
   con "Reproducir" arriba). Se pinta con la interfaz activa de la web
   (Win98 o Kawaii).

   La música la toca la web (el iframe de YouTube en el WebView), así que
   los botones le pasan órdenes por MelonMusicPlugin. Si la app no está
   en marcha no hay a quién mandarlas: el reproductor sale vacío y, al
   tocar una canción, WidgetPlayActivity abre la app, que se va al fondo
   en cuanto empieza a sonar. */
public class MusicWidget extends AppWidgetProvider {

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

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        render(ctx, mgr, ids);
    }

    @Override
    public void onReceive(Context ctx, Intent intent) {
        super.onReceive(ctx, intent);
        String action = intent.getAction();
        if (ACTION_SHOW.equals(action)) {
            WidgetStore.setScreen(ctx, intent.getStringExtra(EXTRA_SCREEN), intent.getStringExtra(EXTRA_PLAYLIST));
            refresh(ctx);
        } else if (ACTION_COMMAND.equals(action)) {
            try {
                JSONObject cmd = new JSONObject().put("action", intent.getStringExtra(EXTRA_COMMAND));
                /* Sin app en marcha no hay música que mover: se repinta
                   (sale "Nada sonando" si se había quedado una canción). */
                if (!MelonMusicPlugin.sendWidgetCommand(cmd)) refresh(ctx);
            } catch (Exception ignored) {}
        }
    }

    static void refresh(Context ctx) {
        AppWidgetManager mgr = AppWidgetManager.getInstance(ctx);
        int[] ids = mgr.getAppWidgetIds(new ComponentName(ctx, MusicWidget.class));
        if (ids.length > 0) render(ctx, mgr, ids);
    }

    private static void render(Context ctx, AppWidgetManager mgr, int[] ids) {
        boolean kawaii = WidgetStore.isKawaii(ctx);
        String screen = WidgetStore.screen(ctx);
        for (int id : ids) {
            RemoteViews rv = WidgetStore.SCREEN_PLAYER.equals(screen)
                ? playerViews(ctx, kawaii)
                : listViews(ctx, kawaii, screen, id);
            mgr.updateAppWidget(id, rv);
        }
    }

    private static RemoteViews playerViews(Context ctx, boolean kawaii) {
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_player : R.layout.widget_w98_player);

        /* La canción guardada solo vale si la app sigue en marcha: si
           Android la cerró, ya no suena nada. */
        String title = MelonMusicPlugin.isAlive() ? WidgetStore.title(ctx) : "";
        if (title.isEmpty()) {
            rv.setTextViewText(R.id.w_text, ctx.getString(R.string.widget_idle));
            rv.setImageViewResource(R.id.w_cover, kawaii ? R.drawable.widget_cover_kw : R.drawable.widget_cover_w98);
            rv.setImageViewResource(R.id.w_play, R.drawable.widget_ic_play);
        } else {
            String artist = WidgetStore.artist(ctx);
            rv.setTextViewText(R.id.w_text, artist.isEmpty() ? title : title + " · " + artist);
            Bitmap cover = WidgetStore.cover(ctx);
            if (cover != null) rv.setImageViewBitmap(R.id.w_cover, cover);
            else rv.setImageViewResource(R.id.w_cover, kawaii ? R.drawable.widget_cover_kw : R.drawable.widget_cover_w98);
            rv.setImageViewResource(R.id.w_play, WidgetStore.playing(ctx) ? R.drawable.widget_ic_pause : R.drawable.widget_ic_play);
        }

        rv.setOnClickPendingIntent(R.id.w_btn_menu, show(ctx, REQ_TO_PLAYLISTS, WidgetStore.SCREEN_PLAYLISTS, null));
        rv.setOnClickPendingIntent(R.id.w_prev, command(ctx, REQ_PREV, "prev"));
        rv.setOnClickPendingIntent(R.id.w_play, command(ctx, REQ_TOGGLE, "toggle"));
        rv.setOnClickPendingIntent(R.id.w_next, command(ctx, REQ_NEXT, "next"));
        return rv;
    }

    private static RemoteViews listViews(Context ctx, boolean kawaii, String screen, int widgetId) {
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_list : R.layout.widget_w98_list);
        boolean songs = WidgetStore.SCREEN_SONGS.equals(screen);
        String playlistId = WidgetStore.playlistId(ctx);

        PendingIntent back = songs
            ? show(ctx, REQ_TO_PLAYLISTS, WidgetStore.SCREEN_PLAYLISTS, null)
            : show(ctx, REQ_TO_PLAYER, WidgetStore.SCREEN_PLAYER, null);
        rv.setOnClickPendingIntent(R.id.w_btn_back, back);
        rv.setOnClickPendingIntent(R.id.w_title, back);

        if (songs) {
            JSONObject pl = WidgetStore.findPlaylist(ctx, playlistId);
            rv.setTextViewText(R.id.w_title, pl != null ? pl.optString("name") : "");
            rv.setViewVisibility(R.id.w_btn_playall, View.VISIBLE);
            rv.setOnClickPendingIntent(R.id.w_btn_playall,
                PendingIntent.getActivity(ctx, REQ_PLAY_ALL, playIntent(ctx).putExtra(EXTRA_PLAYLIST, playlistId).putExtra(EXTRA_INDEX, 0),
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
            /* Cada fila añade su índice (setOnClickFillInIntent). */
            rv.setPendingIntentTemplate(R.id.w_list,
                PendingIntent.getActivity(ctx, REQ_SONG_ROW, playIntent(ctx), PendingIntent.FLAG_UPDATE_CURRENT | mutable()));
            rv.setTextViewText(R.id.w_empty, ctx.getString(R.string.widget_empty_playlist));
        } else {
            rv.setTextViewText(R.id.w_title, ctx.getString(R.string.widget_my_playlists));
            rv.setViewVisibility(R.id.w_btn_playall, View.GONE);
            Intent row = new Intent(ctx, MusicWidget.class).setAction(ACTION_SHOW);
            rv.setPendingIntentTemplate(R.id.w_list,
                PendingIntent.getBroadcast(ctx, REQ_PLAYLIST_ROW, row, PendingIntent.FLAG_UPDATE_CURRENT | mutable()));
            rv.setTextViewText(R.id.w_empty, playlistsMessage(ctx));
        }

        /* La URI distinta por pantalla, playlist y entrada hace que el
           lanzador cree un adaptador nuevo (y pida datos) al cambiar. */
        Intent adapter = new Intent(ctx, WidgetListService.class)
            .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId)
            .putExtra(EXTRA_SCREEN, screen)
            .putExtra(EXTRA_PLAYLIST, playlistId)
            .putExtra("kawaii", kawaii);
        adapter.setData(Uri.parse("melonwidget://" + widgetId + "/" + screen + "/" + Uri.encode(playlistId)
            + "/" + (kawaii ? "kw" : "w98") + "/" + WidgetStore.listNonce(ctx)));
        rv.setRemoteAdapter(R.id.w_list, adapter);
        rv.setEmptyView(R.id.w_list, R.id.w_empty);
        return rv;
    }

    static String playlistsMessage(Context ctx) {
        switch (WidgetStore.listStatus(ctx)) {
            case WidgetStore.LIST_LOADING: return ctx.getString(R.string.widget_loading);
            case WidgetStore.LIST_LOGIN: return ctx.getString(R.string.widget_login);
            case WidgetStore.LIST_OFFLINE: return ctx.getString(R.string.widget_offline);
            default: return ctx.getString(R.string.widget_no_playlists);
        }
    }

    /* Cambia solo el texto de la lista vacía, sin tocar el adaptador (un
       repintado completo lo volvería a cargar). Lo usa el adaptador de
       las playlists al terminar de pedirlas. */
    static void updateEmptyMessage(Context ctx, int widgetId, boolean kawaii) {
        /* Si mientras cargaba se cambió de pantalla, el layout ya es otro. */
        if (!WidgetStore.SCREEN_PLAYLISTS.equals(WidgetStore.screen(ctx))) return;
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_list : R.layout.widget_w98_list);
        rv.setTextViewText(R.id.w_empty, playlistsMessage(ctx));
        AppWidgetManager.getInstance(ctx).partiallyUpdateAppWidget(widgetId, rv);
    }

    private static Intent playIntent(Context ctx) {
        return new Intent(ctx, WidgetPlayActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
    }

    private static PendingIntent show(Context ctx, int req, String screen, String playlistId) {
        Intent i = new Intent(ctx, MusicWidget.class).setAction(ACTION_SHOW)
            .putExtra(EXTRA_SCREEN, screen)
            .putExtra(EXTRA_PLAYLIST, playlistId);
        return PendingIntent.getBroadcast(ctx, req, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    private static PendingIntent command(Context ctx, int req, String command) {
        Intent i = new Intent(ctx, MusicWidget.class).setAction(ACTION_COMMAND).putExtra(EXTRA_COMMAND, command);
        return PendingIntent.getBroadcast(ctx, req, i, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /* Las plantillas de las filas tienen que poder recibir los extras de
       cada fila. */
    private static int mutable() {
        return Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ? PendingIntent.FLAG_MUTABLE : 0;
    }
}
