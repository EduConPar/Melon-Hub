package es.melonhub.MelonHub;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.graphics.Bitmap;
import android.graphics.drawable.Icon;
import android.media.MediaMetadata;
import android.media.session.MediaSession;
import android.media.session.PlaybackState;
import android.net.wifi.WifiManager;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;
import android.os.SystemClock;
import android.util.Log;
import androidx.core.app.ServiceCompat;
import org.json.JSONObject;

/* Servicio en primer plano de tipo mediaPlayback mientras hay música.
   Sin él, Android congela o mata el proceso al rato de estar en segundo
   plano (y con la pantalla apagada corta la red), aunque el WebView siga
   reproduciendo. Mientras suena mantiene la CPU y el wifi despiertos para
   que YouTube pueda seguir bajando la canción. Lo arranca
   MelonMusicPlugin (o WidgetPlayActivity) al sonar.

   Su notificación es la de los controles de música (MediaSession +
   MediaStyle): carátula, canción, ⏮⏯⏭ y la barra de progreso, que se
   puede arrastrar. Al pausar se queda (en pausa, con ▶): el servicio
   sigue en primer plano pero suelta los bloqueos, y así ▶ desde la
   notificación no tiene que volver a arrancarlo desde segundo plano (lo
   que Android, y más aún Xiaomi, no siempre deja). Se va al cerrar la
   app (quitarla de recientes o cerrarse la actividad).

   Lo que pinta sale de WidgetStore (lo mismo que el widget, lo manda la
   web con setPlaying / setWidgetState) y se repinta con
   PlayerWidget.refresh. Los botones llegan a la web como las órdenes
   del widget (evento "widgetCommand": toggle, prev, next y seek). */
public class MusicService extends Service {

    private static final String CHANNEL_ID = "music";
    private static final int NOTIFICATION_ID = 1;

    /* Botones de la notificación en Android < 13 (desde el 13 el sistema
       los saca de la MediaSession y llegan a sessionCallback). */
    private static final String ACTION_COMMAND = "es.melonhub.MelonHub.music.COMMAND";
    private static final String EXTRA_COMMAND = "command";
    private static final int REQ_PREV = 1;
    private static final int REQ_TOGGLE = 2;
    private static final int REQ_NEXT = 3;

    /* El servicio en marcha (null si no hay): para repintar la notificación. */
    private static MusicService instance;

    private PowerManager.WakeLock wakeLock;
    private WifiManager.WifiLock wifiLock;
    private MediaSession session;

    /* Repinta la notificación con lo que diga WidgetStore (si el servicio
       está en marcha). Lo llama PlayerWidget.refresh, que junta los avisos
       de la web en uno. */
    static void refresh(Context ctx) {
        MusicService s = instance;
        if (s != null) s.update();
    }

    /* Sin la app no hay página que toque la música: fuera la notificación. */
    static void stop(Context ctx) {
        ctx.stopService(new Intent(ctx, MusicService.class));
    }

    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;
        session = new MediaSession(this, "MelonHub");
        session.setCallback(sessionCallback);
        /* Antes de Android 8 hay que pedirlo; después ya va así siempre. */
        session.setFlags(MediaSession.FLAG_HANDLES_MEDIA_BUTTONS | MediaSession.FLAG_HANDLES_TRANSPORT_CONTROLS);
        session.setSessionActivity(openAppIntent());
        session.setActive(true);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent != null && ACTION_COMMAND.equals(intent.getAction())) {
            /* Botón de la notificación (Android < 13): el servicio ya está en
               primer plano, solo hay que pasar la orden. */
            if (MelonMusicPlugin.isAlive()) command(intent.getStringExtra(EXTRA_COMMAND));
            else stopSelf();
            return START_NOT_STICKY;
        }

        ServiceCompat.startForeground(
            this,
            NOTIFICATION_ID,
            updateSession(),
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ? ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK : 0
        );
        updateLocks();
        /* Si Android mata el proceso, no lo resucitamos: sin la página
           no habría música y quedaría una notificación vacía. */
        return START_NOT_STICKY;
    }

    /* ── Órdenes hacia la web ── */

    private final MediaSession.Callback sessionCallback = new MediaSession.Callback() {
        @Override
        public void onPlay() {
            if (!WidgetStore.playing(MusicService.this)) command("toggle");
        }

        @Override
        public void onPause() {
            if (WidgetStore.playing(MusicService.this)) command("toggle");
        }

        @Override
        public void onSkipToPrevious() {
            command("prev");
        }

        @Override
        public void onSkipToNext() {
            command("next");
        }

        @Override
        public void onSeekTo(long pos) {
            double seconds = Math.max(0, pos / 1000.0);
            try {
                if (MelonMusicPlugin.sendWidgetCommand(new JSONObject().put("action", "seek").put("position", seconds))) {
                    /* La barra se queda donde se soltó sin esperar a la web. */
                    WidgetStore.setPosition(MusicService.this, seconds);
                    PlayerWidget.refresh(MusicService.this);
                }
            } catch (Exception e) {
                Log.w("MelonMusic", "No se pudo mandar el salto", e);
            }
        }
    };

    private void command(String action) {
        if (action == null) return;
        try {
            if (!MelonMusicPlugin.sendWidgetCommand(new JSONObject().put("action", action))) return;
        } catch (Exception e) {
            Log.w("MelonMusic", "Orden de la notificación no válida", e);
            return;
        }
        /* ⏯ cambia de icono ya (como en el widget); la web lo confirma.
           La posición se apunta antes de cambiar para que la barra no
           salte. */
        if ("toggle".equals(action)) {
            WidgetStore.savePosition(this);
            WidgetStore.setPlayingFlag(this, !WidgetStore.playing(this));
            PlayerWidget.refresh(this);
        }
    }

    /* ── Lo que se ve ── */

    private void update() {
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        nm.notify(NOTIFICATION_ID, updateSession());
        updateLocks();
    }

    /* Pasa a la MediaSession la canción y por dónde va, y devuelve la
       notificación. El progreso lo avanza el sistema solo (posición +
       hora + velocidad). */
    private Notification updateSession() {
        boolean playing = WidgetStore.playing(this);
        String title = WidgetStore.title(this);
        String artist = WidgetStore.artist(this);
        Bitmap cover = WidgetStore.cover(this);
        long durationMs = Math.round(WidgetStore.duration(this) * 1000);

        MediaMetadata.Builder meta = new MediaMetadata.Builder()
            .putString(MediaMetadata.METADATA_KEY_TITLE, title.isEmpty() ? getString(R.string.app_name) : title)
            .putString(MediaMetadata.METADATA_KEY_ARTIST, artist)
            /* Sin duración no hay barra de progreso. */
            .putLong(MediaMetadata.METADATA_KEY_DURATION, durationMs > 0 ? durationMs : -1);
        if (cover != null) meta.putBitmap(MediaMetadata.METADATA_KEY_ALBUM_ART, cover);
        session.setMetadata(meta.build());

        /* Solo ⏮⏯⏭ y saltar en la barra (en Android 13+ el sistema enseña
           los botones de estas acciones). */
        session.setPlaybackState(new PlaybackState.Builder()
            .setActions(PlaybackState.ACTION_PLAY | PlaybackState.ACTION_PAUSE | PlaybackState.ACTION_PLAY_PAUSE
                | PlaybackState.ACTION_SKIP_TO_PREVIOUS | PlaybackState.ACTION_SKIP_TO_NEXT | PlaybackState.ACTION_SEEK_TO)
            .setState(playing ? PlaybackState.STATE_PLAYING : PlaybackState.STATE_PAUSED,
                Math.round(WidgetStore.position(this) * 1000), playing ? 1f : 0f, SystemClock.elapsedRealtime())
            .build());

        return buildNotification(playing, title, artist, cover);
    }

    private Notification buildNotification(boolean playing, String title, String artist, Bitmap cover) {
        NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        Notification.Builder b;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            if (nm.getNotificationChannel(CHANNEL_ID) == null) {
                NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "Música", NotificationManager.IMPORTANCE_LOW);
                ch.setShowBadge(false);
                nm.createNotificationChannel(ch);
            }
            b = new Notification.Builder(this, CHANNEL_ID);
        } else {
            b = new Notification.Builder(this);
        }

        b.setSmallIcon(R.drawable.ic_stat_music)
            .setContentTitle(title.isEmpty() ? getString(R.string.app_name) : title)
            .setContentText(artist)
            .setLargeIcon(cover)
            .setContentIntent(openAppIntent())
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setShowWhen(false)
            .setCategory(Notification.CATEGORY_TRANSPORT)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .addAction(action(R.drawable.widget_ic_prev, R.string.widget_prev, REQ_PREV, "prev"))
            .addAction(action(playing ? R.drawable.widget_ic_pause : R.drawable.widget_ic_play, R.string.widget_toggle, REQ_TOGGLE, "toggle"))
            .addAction(action(R.drawable.widget_ic_next, R.string.widget_next, REQ_NEXT, "next"))
            .setStyle(new Notification.MediaStyle()
                .setMediaSession(session.getSessionToken())
                .setShowActionsInCompactView(0, 1, 2));
        return b.build();
    }

    private Notification.Action action(int icon, int label, int req, String command) {
        Intent i = new Intent(this, MusicService.class).setAction(ACTION_COMMAND).putExtra(EXTRA_COMMAND, command);
        PendingIntent pi = PendingIntent.getService(this, req, i, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        return new Notification.Action.Builder(Icon.createWithResource(this, icon), getString(label), pi).build();
    }

    private PendingIntent openAppIntent() {
        Intent open = new Intent(this, MainActivity.class).setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        return PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    /* ── CPU y wifi: solo mientras suena ── */

    private void updateLocks() {
        if (WidgetStore.playing(this)) acquireLocks();
        else releaseLocks();
    }

    private void acquireLocks() {
        if (wakeLock == null) {
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "MelonHub:music");
            wakeLock.setReferenceCounted(false);
        }
        if (!wakeLock.isHeld()) wakeLock.acquire();

        if (wifiLock == null) {
            WifiManager wm = (WifiManager) getApplicationContext().getSystemService(Context.WIFI_SERVICE);
            if (wm != null) {
                wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, "MelonHub:music");
                wifiLock.setReferenceCounted(false);
            }
        }
        if (wifiLock != null && !wifiLock.isHeld()) wifiLock.acquire();
    }

    private void releaseLocks() {
        if (wakeLock != null && wakeLock.isHeld()) wakeLock.release();
        if (wifiLock != null && wifiLock.isHeld()) wifiLock.release();
    }

    /* Al quitar la app de recientes se acaba la música (y el servicio). */
    @Override
    public void onTaskRemoved(Intent rootIntent) {
        stopSelf();
    }

    @Override
    public void onDestroy() {
        if (instance == this) instance = null;
        releaseLocks();
        session.setActive(false);
        session.release();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
