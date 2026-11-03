package es.melonhub.MelonHub;

import android.appwidget.AppWidgetManager;
import android.content.Context;
import android.content.Intent;
import android.widget.RemoteViews;
import android.widget.RemoteViewsService;
import org.json.JSONArray;
import org.json.JSONObject;

/* Filas de las listas del widget: "Mis playlists" o las canciones de una
   playlist. onDataSetChanged corre en un hilo aparte. "Mis playlists" sale
   al momento con las guardadas y se piden a la web por detrás; si han
   cambiado, la lista se recarga. Solo la primera vez (sin nada guardado)
   se espera a la web. */
public class WidgetListService extends RemoteViewsService {

    @Override
    public RemoteViewsFactory onGetViewFactory(Intent intent) {
        return new Factory(getApplicationContext(), intent);
    }

    private static class Factory implements RemoteViewsFactory {

        private final Context ctx;
        private final int widgetId;
        private final boolean songs;
        private final String playlistId;
        private final float rowRoomDp;
        private WidgetTheme theme;
        private JSONArray rows = new JSONArray();
        /* Con la lista vacía, una fila con el aviso (sin sesión, sin
           playlists, playlist vacía…). */
        private String message;

        Factory(Context ctx, Intent intent) {
            this.ctx = ctx;
            this.widgetId = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
            this.songs = WidgetStore.SCREEN_SONGS.equals(intent.getStringExtra(PlayerWidget.EXTRA_SCREEN));
            String pl = intent.getStringExtra(PlayerWidget.EXTRA_PLAYLIST);
            this.playlistId = pl != null ? pl : "";
            this.rowRoomDp = intent.getFloatExtra("rowRoomDp", 120);
        }

        @Override
        public void onCreate() {}

        @Override
        public void onDataSetChanged() {
            theme = WidgetTheme.load(ctx);
            if (songs) {
                /* Las canciones vienen en la lista de playlists que se
                   acaba de cargar al entrar en "Mis playlists". */
                JSONObject pl = WidgetStore.findPlaylist(ctx, playlistId);
                rows = pl != null && pl.optJSONArray("tracks") != null ? pl.optJSONArray("tracks") : new JSONArray();
                message = rows.length() == 0 ? ctx.getString(R.string.widget_empty_playlist) : null;
            } else {
                rows = WidgetStore.playlists(ctx);
                if (rows.length() == 0) {
                    WidgetStore.fetchPlaylists(ctx);
                    rows = WidgetStore.playlists(ctx);
                } else if (!WidgetStore.playlistsFresh(ctx)) {
                    refreshInBackground();
                }
                message = rows.length() == 0 ? PlayerWidget.playlistsMessage(ctx) : null;
            }
        }

        /* Al recargar (notifyAppWidgetViewDataChanged) vuelve a pasar por
           onDataSetChanged, pero ya están frescas y no se piden otra vez. */
        private void refreshInBackground() {
            new Thread(() -> {
                if (WidgetStore.fetchPlaylists(ctx)) {
                    AppWidgetManager.getInstance(ctx).notifyAppWidgetViewDataChanged(widgetId, R.id.w_list);
                }
            }).start();
        }

        @Override
        public void onDestroy() {}

        @Override
        public int getCount() {
            return message != null ? 1 : rows.length();
        }

        @Override
        public RemoteViews getViewAt(int position) {
            JSONObject row = rows.optJSONObject(position);
            if (row == null) row = new JSONObject();
            WidgetTheme t = theme != null ? theme : WidgetTheme.load(ctx);
            boolean kawaii = t.kawaii;
            RemoteViews rv;
            Intent fill = new Intent();
            if (message != null) {
                /* Sin extras: tocarla no hace nada. */
                rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_item_song : R.layout.widget_w98_item_song);
                rv.setImageViewBitmap(R.id.w_item_title, WidgetText.draw(ctx, t, WidgetText.BODY, message, 12, t.muted, rowRoomDp, 3, true));
                rv.setInt(R.id.w_item, "setBackgroundColor", 0x00000000);
                return rv;
            }
            if (songs) {
                rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_item_song : R.layout.widget_w98_item_song);
                String title = row.optString("title");
                rv.setImageViewBitmap(R.id.w_item_title, WidgetText.draw(ctx, t, WidgetText.BODY,
                    title.isEmpty() ? row.optString("videoId") : title, 12, t.text, rowRoomDp, 1, false));
                rv.setImageViewBitmap(R.id.w_item_sub, WidgetText.draw(ctx, t, WidgetText.BODY,
                    row.optString("artist"), 10, t.muted, rowRoomDp, 1, false));
                PlayerWidget.paintRow(rv, t, 0);
                fill.putExtra(PlayerWidget.EXTRA_PLAYLIST, playlistId).putExtra(PlayerWidget.EXTRA_INDEX, position);
            } else {
                rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_item_playlist : R.layout.widget_w98_item_playlist);
                rv.setImageViewBitmap(R.id.w_item_title, WidgetText.draw(ctx, t, WidgetText.BODY,
                    row.optString("name"), 12, t.text, rowRoomDp, 1, false));
                PlayerWidget.paintRow(rv, t, R.id.w_item_ic);
                fill.putExtra(PlayerWidget.EXTRA_SCREEN, WidgetStore.SCREEN_SONGS)
                    .putExtra(PlayerWidget.EXTRA_PLAYLIST, row.optString("id"));
            }
            rv.setOnClickFillInIntent(R.id.w_item, fill);
            return rv;
        }

        @Override
        public RemoteViews getLoadingView() {
            return null;
        }

        @Override
        public int getViewTypeCount() {
            return 1;
        }

        @Override
        public long getItemId(int position) {
            return position;
        }

        @Override
        public boolean hasStableIds() {
            return false;
        }
    }
}
