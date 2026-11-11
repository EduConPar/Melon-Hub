package es.melonhub.MelonHub;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.RemoteInput;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import org.json.JSONObject;

/* Botones de los avisos (MelonNotifs), sin abrir la app. Llaman a la
   misma API que la web con las cookies del WebView (WidgetStore):
     · reply  → assets/profile/api.php?action=send-message {to, text}
                (el servidor marca también el chat como leído).
     · read   → assets/profile/api.php?action=get-messages&with=… (es lo
                que marca el chat como leído en la web al abrirlo).
     · ajz-rechazar → assets/juegos/api.php?action=ajz-reto-responder
                {id, aceptar: false} (reto de ajedrez).
     · accept → playlist: assets/music/api.php?action=respond-invite;
                elemento de lista: assets/profile/api.php?action=
                respond-item-invite. {inviteId, action: 'accept'}.
   Si sale bien, el aviso se quita; si no, se cambia por uno que lo dice
   y abre la app al tocarlo. */
public class NotifAccionReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context ctx, Intent i) {
        String key = i.getStringExtra("key");
        if (key == null) return;
        String accion = i.getAction() == null ? "" : i.getAction();
        Bundle ri = RemoteInput.getResultsFromIntent(i);
        CharSequence texto = ri != null ? ri.getCharSequence(MelonNotifs.ACCION_TEXTO) : null;
        PendingResult pr = goAsync();
        Context app = ctx.getApplicationContext();
        new Thread(() -> {
            try {
                hacer(app, accion, i, key, texto);
            } finally {
                pr.finish();
            }
        }).start();
    }

    private static void hacer(Context ctx, String accion, Intent i, String key, CharSequence texto) {
        String from = nn(i.getStringExtra("from"));
        String nombre = nn(i.getStringExtra("fromName"));
        String url = nn(i.getStringExtra("url"));
        boolean ok;
        String error;
        try {
            if (accion.endsWith("reply")) {
                String t = texto == null ? "" : texto.toString().trim();
                if (t.isEmpty()) { NotificationManagerCompat.from(ctx).cancel(key, 1); return; }
                ok = pedir(ctx, "assets/profile/api.php?action=send-message",
                    new JSONObject().put("to", from).put("text", t));
                error = "No se ha podido enviar tu mensaje a " + (nombre.isEmpty() ? from : nombre);
            } else if (accion.endsWith("read")) {
                ok = pedir(ctx, "assets/profile/api.php?action=get-messages&with="
                    + URLEncoder.encode(from, "UTF-8"), null);
                error = "No se ha podido marcar como leído";
            } else if (accion.endsWith("ajz-rechazar")) {
                ok = pedir(ctx, "assets/juegos/api.php?action=ajz-reto-responder", new JSONObject()
                    .put("id", Integer.parseInt(nn(i.getStringExtra("inviteId"))))
                    .put("aceptar", false));
                error = "No se ha podido rechazar el reto";
            } else if (accion.endsWith("accept")) {
                String src = nn(i.getStringExtra("source"));
                String ruta = "playlist".equals(src) ? "assets/music/api.php?action=respond-invite"
                    : "assets/profile/api.php?action=respond-item-invite";
                ok = pedir(ctx, ruta, new JSONObject()
                    .put("inviteId", Integer.parseInt(nn(i.getStringExtra("inviteId"))))
                    .put("action", "accept"));
                error = "No se ha podido aceptar la invitación";
            } else {
                return;
            }
        } catch (Exception e) {
            ok = false;
            error = "No se ha podido completar";
        }
        if (ok) {
            MelonNotifs.olvidarChat(key);
            NotificationManagerCompat.from(ctx).cancel(key, 1);
        } else {
            MelonNotifs.fallo(ctx, key, error, url);
        }
    }

    /* POST con JSON (o GET si body es null). true si la API dice ok. */
    private static boolean pedir(Context ctx, String ruta, JSONObject body) throws Exception {
        HttpURLConnection c = (HttpURLConnection) new URL(new URL(WidgetStore.baseUrl(ctx)), ruta).openConnection();
        try {
            c.setConnectTimeout(8000);
            c.setReadTimeout(10000);
            String cookies = WidgetStore.cookies(ctx);
            if (cookies != null) c.setRequestProperty("Cookie", cookies);
            if (body != null) {
                c.setRequestMethod("POST");
                c.setDoOutput(true);
                c.setRequestProperty("Content-Type", "application/json");
                try (OutputStream out = c.getOutputStream()) {
                    out.write(body.toString().getBytes(StandardCharsets.UTF_8));
                }
            }
            int code = c.getResponseCode();
            WidgetStore.keepCookies(ctx, c);
            if (code != 200) return false;
            String r = WidgetStore.readAll(c.getInputStream());
            return new JSONObject(r).optBoolean("ok", true) && !new JSONObject(r).has("error");
        } finally {
            c.disconnect();
        }
    }

    private static String nn(String s) {
        return s == null ? "" : s;
    }
}
