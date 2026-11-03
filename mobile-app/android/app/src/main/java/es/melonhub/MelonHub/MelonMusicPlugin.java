package es.melonhub.MelonHub;

import android.content.Intent;
import android.util.Log;
import androidx.core.content.ContextCompat;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/* La web (mobile.php) avisa de cuándo suena y cuándo se pausa:
     Capacitor.nativePromise('MelonMusic', 'setPlaying', { playing, title, artist })
   Mientras suena: servicio en primer plano + WebView "visible", para que
   ni salir de la app ni apagar la pantalla corten la música. */
@CapacitorPlugin(name = "MelonMusic")
public class MelonMusicPlugin extends Plugin {

    @PluginMethod
    public void setPlaying(PluginCall call) {
        boolean playing = call.getBoolean("playing", false);
        Intent svc = new Intent(getContext(), MusicService.class);

        if (playing) {
            svc.putExtra(MusicService.EXTRA_TITLE, call.getString("title", ""));
            svc.putExtra(MusicService.EXTRA_ARTIST, call.getString("artist", ""));
            try {
                ContextCompat.startForegroundService(getContext(), svc);
            } catch (Exception e) {
                /* Android 12+ no deja arrancarlo desde segundo plano (p. ej.
                   si la canción siguiente empieza con la app ya oculta y el
                   servicio se había parado). Si ya estaba en marcha, sigue. */
                Log.w("MelonMusic", "No se pudo arrancar el servicio", e);
            }
        } else {
            getContext().stopService(svc);
        }

        getActivity().runOnUiThread(() -> {
            if (getBridge().getWebView() instanceof MusicWebView) {
                ((MusicWebView) getBridge().getWebView()).setKeepVisible(playing);
            }
        });
        call.resolve();
    }
}
