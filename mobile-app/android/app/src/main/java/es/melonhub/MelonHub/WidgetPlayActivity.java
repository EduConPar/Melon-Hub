package es.melonhub.MelonHub;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.util.Log;
import androidx.core.content.ContextCompat;
import com.getcapacitor.JSObject;
import org.json.JSONArray;
import org.json.JSONObject;

/* Al tocar ⏮⏯⏭, una canción o "Reproducir" en el widget. Es una actividad
   invisible que se cierra al momento: si la app está en marcha, le pasa
   la orden y no se ve nada; si no, ⏯ y las canciones abren la app, que
   empieza a sonar y se manda sola al fondo (MelonMusicPlugin.setPlaying).
   Con ⏯ y la app cerrada sigue la última canción donde iba (la web la
   tiene guardada).
   Tiene que ser una actividad y no un broadcast: Android no deja abrir la
   app desde un broadcast, pero sí desde una actividad que acaba de abrir
   el usuario. Por lo mismo, si la orden pone música, el servicio de
   música se arranca aquí: con la app en segundo plano y en pausa, Android
   (y más aún Xiaomi) no deja arrancarlo cuando la web avisa de que ya
   suena, y sin él la app se congela y la música se corta al acabar la
   canción. */
public class WidgetPlayActivity extends Activity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        String command = getIntent().getStringExtra(MusicWidget.EXTRA_COMMAND);
        JSObject cmd = command != null ? controlCommand(command) : playlistCommand();
        if (cmd != null) {
            if (MelonMusicPlugin.isAlive()) {
                if (startsMusic(cmd)) startMusicService(cmd);
                MelonMusicPlugin.sendWidgetCommand(cmd);
            } else if (!"prev".equals(command) && !"next".equals(command)) {
                MelonMusicPlugin.pendingWidgetCommand = cmd;
                startActivity(new Intent(this, MainActivity.class)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    .putExtra(MainActivity.EXTRA_FROM_WIDGET, true));
            }
        }
        finish();
    }

    /* ⏮⏯⏭. Con la app cerrada, ⏯ solo tiene sentido si hay una última
       canción, y ⏮⏭ no hacen nada (no hay cola que mover). */
    private JSObject controlCommand(String command) {
        boolean alive = MelonMusicPlugin.isAlive();
        if (!alive && ("prev".equals(command) || "next".equals(command))) return null;
        if (!alive && WidgetStore.title(this).isEmpty()) return null;
        JSObject cmd = new JSObject();
        cmd.put("action", command);
        return cmd;
    }

    /* Todo pone música salvo ⏯ cuando ya suena (eso pausa). Si ya suena,
       el servicio ya está en marcha. */
    private boolean startsMusic(JSObject cmd) {
        return "play-playlist".equals(cmd.optString("action")) || !WidgetStore.playing(this);
    }

    private void startMusicService(JSObject cmd) {
        String title = WidgetStore.title(this);
        String artist = WidgetStore.artist(this);
        JSONArray tracks = cmd.optJSONArray("tracks");
        JSONObject tr = tracks != null ? tracks.optJSONObject(cmd.optInt("index", 0)) : null;
        if (tr != null) {
            title = tr.optString("title");
            artist = tr.optString("artist");
        }
        Intent svc = new Intent(this, MusicService.class)
            .putExtra(MusicService.EXTRA_TITLE, title)
            .putExtra(MusicService.EXTRA_ARTIST, artist);
        try {
            ContextCompat.startForegroundService(this, svc);
        } catch (Exception e) {
            Log.w("MelonWidget", "No se pudo arrancar el servicio de música", e);
        }
    }

    private JSObject playlistCommand() {
        Intent in = getIntent();
        String playlistId = in.getStringExtra(MusicWidget.EXTRA_PLAYLIST);
        int index = in.getIntExtra(MusicWidget.EXTRA_INDEX, 0);
        JSONObject pl = playlistId != null ? WidgetStore.findPlaylist(this, playlistId) : null;
        JSONArray tracks = pl != null ? pl.optJSONArray("tracks") : null;
        if (tracks == null || tracks.length() == 0) return null;

        index = Math.max(0, Math.min(index, tracks.length() - 1));
        JSObject cmd = new JSObject();
        cmd.put("action", "play-playlist");
        cmd.put("name", pl.optString("name"));
        cmd.put("tracks", tracks);
        cmd.put("index", index);

        /* El widget vuelve al reproductor con la canción elegida; la web lo
           confirma en cuanto empieza a sonar. */
        JSONObject tr = tracks.optJSONObject(index);
        if (tr != null) {
            WidgetStore.setState(this, false, tr.optString("title"), tr.optString("artist"), tr.optString("videoId"),
                0, tr.optDouble("duration", 0), WidgetStore.shuffle(this));
        }
        WidgetStore.setScreen(this, WidgetStore.SCREEN_PLAYER, null);
        MusicWidget.refresh(this);
        return cmd;
    }
}
