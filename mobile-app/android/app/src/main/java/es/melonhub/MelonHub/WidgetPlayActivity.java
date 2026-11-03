package es.melonhub.MelonHub;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import com.getcapacitor.JSObject;
import org.json.JSONArray;
import org.json.JSONObject;

/* Al tocar una canción (o "Reproducir") en el widget. Es una actividad
   invisible que se cierra al momento: si la app está en marcha, le pasa
   la orden y no se ve nada; si no, abre la app, que empieza a sonar y se
   manda sola al fondo (MelonMusicPlugin.setPlaying).
   Tiene que ser una actividad y no un broadcast: Android no deja abrir la
   app desde un broadcast, pero sí desde una actividad que acaba de abrir
   el usuario. */
public class WidgetPlayActivity extends Activity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Intent in = getIntent();
        String playlistId = in.getStringExtra(MusicWidget.EXTRA_PLAYLIST);
        int index = in.getIntExtra(MusicWidget.EXTRA_INDEX, 0);
        JSONObject pl = playlistId != null ? WidgetStore.findPlaylist(this, playlistId) : null;
        JSONArray tracks = pl != null ? pl.optJSONArray("tracks") : null;

        if (tracks != null && tracks.length() > 0) {
            index = Math.max(0, Math.min(index, tracks.length() - 1));
            JSObject cmd = new JSObject();
            cmd.put("action", "play-playlist");
            cmd.put("name", pl.optString("name"));
            cmd.put("tracks", tracks);
            cmd.put("index", index);

            /* El widget vuelve al reproductor con la canción elegida; la
               web lo confirma en cuanto empieza a sonar. */
            JSONObject tr = tracks.optJSONObject(index);
            if (tr != null) {
                WidgetStore.setNowPlaying(this, false, tr.optString("title"), tr.optString("artist"), tr.optString("videoId"));
            }
            WidgetStore.setScreen(this, WidgetStore.SCREEN_PLAYER, null);
            MusicWidget.refresh(this);

            if (!MelonMusicPlugin.sendWidgetCommand(cmd)) {
                MelonMusicPlugin.pendingWidgetCommand = cmd;
                startActivity(new Intent(this, MainActivity.class)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    .putExtra(MainActivity.EXTRA_FROM_WIDGET, true));
            }
        }
        finish();
    }
}
