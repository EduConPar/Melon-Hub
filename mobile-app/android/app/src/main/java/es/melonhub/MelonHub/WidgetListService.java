package es.melonhub.MelonHub;

import android.appwidget.AppWidgetManager;
import android.content.Context;
import android.content.Intent;
import android.widget.RemoteViews;
import android.widget.RemoteViewsService;
import org.json.JSONArray;
import org.json.JSONObject;

/* Filas de las listas del widget: "Mis playlists" o las canciones de una
   playlist. onDataSetChanged corre en un hilo aparte, así que aquí se
   pueden pedir las playlists a la web sin bloquear nada. */
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
        private final boolean kawaii;
        private JSONArray rows = new JSONArray();

        Factory(Context ctx, Intent intent) {
            this.ctx = ctx;
            this.widgetId = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
            this.songs = WidgetStore.SCREEN_SONGS.equals(intent.getStringExtra(MusicWidget.EXTRA_SCREEN));
            String pl = intent.getStringExtra(MusicWidget.EXTRA_PLAYLIST);
            this.playlistId = pl != null ? pl : "";
            this.kawaii = intent.getBooleanExtra("kawaii", false);
        }

        @Override
        public void onCreate() {}

        @Override
        public void onDataSetChanged() {
            if (songs) {
                /* Las canciones vienen en la lista de playlists que se
                   acaba de cargar al entrar en "Mis playlists". */
                JSONObject pl = WidgetStore.findPlaylist(ctx, playlistId);
                rows = pl != null && pl.optJSONArray("tracks") != null ? pl.optJSONArray("tracks") : new JSONArray();
            } else {
                WidgetStore.fetchPlaylists(ctx);
                rows = WidgetStore.playlists(ctx);
                MusicWidget.updateEmptyMessage(ctx, widgetId, kawaii);
            }
        }

        @Override
        public void onDestroy() {}

        @Override
        public int getCount() {
            return rows.length();
        }

        @Override
        public RemoteViews getViewAt(int position) {
            JSONObject row = rows.optJSONObject(position);
            if (row == null) row = new JSONObject();
            RemoteViews rv;
            Intent fill = new Intent();
            if (songs) {
                rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_item_song : R.layout.widget_w98_item_song);
                String title = row.optString("title");
                rv.setTextViewText(R.id.w_item_title, title.isEmpty() ? row.optString("videoId") : title);
                rv.setTextViewText(R.id.w_item_sub, row.optString("artist"));
                fill.putExtra(MusicWidget.EXTRA_PLAYLIST, playlistId).putExtra(MusicWidget.EXTRA_INDEX, position);
            } else {
                rv = new RemoteViews(ctx.getPackageName(), kawaii ? R.layout.widget_kw_item_playlist : R.layout.widget_w98_item_playlist);
                rv.setTextViewText(R.id.w_item_title, row.optString("name"));
                fill.putExtra(MusicWidget.EXTRA_SCREEN, WidgetStore.SCREEN_SONGS)
                    .putExtra(MusicWidget.EXTRA_PLAYLIST, row.optString("id"));
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
