package es.melonhub.MelonHub;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.BitmapShader;
import android.graphics.Canvas;
import android.graphics.Paint;
import android.graphics.Shader;
import android.os.Build;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.Person;
import androidx.core.app.RemoteInput;
import androidx.core.content.pm.ShortcutInfoCompat;
import androidx.core.content.pm.ShortcutManagerCompat;
import androidx.core.graphics.drawable.IconCompat;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/* Avisos push de la app, con la tarjeta de Android (decidido así: se
   probó también una ventana Win98 dentro de la tarjeta y se descartó).
   Los manda el servidor como mensajes de datos de FCM (send-push.php,
   tokens tipo 'fcm2') y los monta AvisosService con esta clase:

     · Chat: estilo conversación (MessagingStyle): foto y nombre de quien
       escribe, los mensajes del mismo chat se apilan y Android 11+ lo
       pone en "Conversaciones" (atajo de conversación). Botones Responder
       (campo de texto en el aviso) y Marcar como leído.
     · Invitaciones (escuchar juntos, calendario en pareja, playlist,
       elemento de lista): foto de quien invita. Playlist y elemento:
       botón Aceptar (sin abrir la app) y Ver.
     · Juegos (ajedrez.php ajz_push): reto (foto de quien reta;
       Rechazar sin abrir la app y Aceptar, que la abre en la partida),
       partida lista (Jugar) y torneos (cancelado, cambio de hora).
     · Diarios (assets/push/cron.php): Wordelon nuevo y racha en peligro.
     · Recordatorios, respuesta a la invitación de pareja, wiki, juegos sin
       foto y el resto: el icono pixel de su app (pack Melon) en grande
       (racha: el fueguito del perfil).

   Todos llevan el melón pequeño y el color de acento del tema del
   usuario (WidgetTheme, el del widget); en los que no son chat, el
   título va sin el emoji del principio (sinEmoji). Los botones van a
   NotifAccionReceiver; tocar el aviso abre la app en su URL
   (MainActivity.EXTRA_AVISO_URL → "open-push" en mobile.php). */
final class MelonNotifs {

    private MelonNotifs() {}

    static final String CHANNEL = "avisos";
    static final String ACCION_TEXTO = "texto";

    /* Un aviso tal como llega del servidor (los datos del mensaje). */
    static final class Aviso {
        String type = "", title = "", body = "", url = "", tag = "";
        String fromKey = "", fromName = "", icon = "", source = "", inviteId = "";
        Bitmap avatar;
        long when = System.currentTimeMillis();

        static Aviso deDatos(Map<String, String> d) {
            Aviso a = new Aviso();
            a.type = s(d.get("type"));
            a.title = s(d.get("title"));
            a.body = s(d.get("body"));
            a.url = s(d.get("url"));
            a.tag = s(d.get("tag"));
            a.fromKey = s(d.get("from"));
            a.fromName = s(d.get("fromName"));
            a.icon = s(d.get("icon"));
            a.source = s(d.get("source"));
            a.inviteId = s(d.get("inviteId"));
            return a;
        }

        private static String s(String v) {
            return v == null ? "" : v;
        }

        boolean esChat() {
            return ("chat".equals(type) || "message".equals(type)) && !fromKey.isEmpty();
        }

        /* Clave del aviso (se sustituye el anterior con la misma). */
        String clave() {
            if (esChat()) return "chat:" + fromKey;
            return tag.isEmpty() ? type + ":" + title : tag;
        }
    }

    /* Icono pixel de cada tipo (pack Melon de la web). */
    private static int iconoTipo(Aviso a) {
        if (a.esChat()) return R.drawable.aviso_chat;
        switch (a.type) {
            case "invite":
                switch (a.source) {
                    case "listen": return R.drawable.aviso_cancion;
                    case "partner": return R.drawable.aviso_calendario;
                    case "playlist": return R.drawable.aviso_musica;
                    case "item": return R.drawable.aviso_lista;
                    default: return R.drawable.aviso_aviso;
                }
            case "reminder": return R.drawable.aviso_bell;
            case "partner": return R.drawable.aviso_calendario;
            case "wiki-accepted":
            case "wiki-rejected": return R.drawable.aviso_wiki;
            case "ajz-reto":
            case "ajz-partida":
            case "ajz-torneo":
            case "wordelon": return R.drawable.aviso_juegos;
            case "racha": return R.drawable.aviso_racha;
            default: return R.drawable.aviso_aviso;
        }
    }

    /* Mensajes ya enseñados por chat, para apilarlos. Se vacía al tocar
       el aviso, responder o marcarlo como leído. */
    private static final Map<String, List<Aviso>> CHATS = new HashMap<>();

    static void ensureChannel(Context ctx) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationManager nm = ctx.getSystemService(NotificationManager.class);
        if (nm.getNotificationChannel(CHANNEL) != null) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL, "Avisos", NotificationManager.IMPORTANCE_HIGH);
        ch.setDescription("Mensajes, invitaciones y avisos de Melon Hub");
        nm.createNotificationChannel(ch);
    }

    static void show(Context ctx, Aviso a) {
        ensureChannel(ctx);
        WidgetTheme t = WidgetTheme.load(ctx);
        String key = a.clave();
        boolean chat = a.esChat();

        List<Aviso> hilo = null;
        if (chat) {
            synchronized (CHATS) {
                hilo = CHATS.get(key);
                if (hilo == null) { hilo = new ArrayList<>(); CHATS.put(key, hilo); }
                hilo.add(a);
                while (hilo.size() > 8) hilo.remove(0);
                hilo = new ArrayList<>(hilo);
            }
        }

        NotificationCompat.Builder b = new NotificationCompat.Builder(ctx, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_melon)
            .setColor(t.accent)
            .setWhen(a.when)
            .setShowWhen(true)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(chat ? NotificationCompat.CATEGORY_MESSAGE
                : "reminder".equals(a.type) || "racha".equals(a.type) || "wordelon".equals(a.type)
                    ? NotificationCompat.CATEGORY_REMINDER
                : NotificationCompat.CATEGORY_SOCIAL)
            .setContentTitle(chat ? a.title : sinEmoji(a.title))
            .setContentText(a.body)
            .setContentIntent(abrir(ctx, a.url, key, "abrir"));

        Bitmap foto = a.avatar != null ? redonda(a.avatar) : null;
        if (chat) {
            Person yo = new Person.Builder().setName("Tú").setKey("yo").build();
            Person otro = persona(ctx, a, foto);
            NotificationCompat.MessagingStyle ms = new NotificationCompat.MessagingStyle(yo);
            for (Aviso m : hilo) ms.addMessage(m.body, m.when, otro);
            b.setStyle(ms);
            atajoConversacion(ctx, a, otro);
            b.setShortcutId("chat_" + a.fromKey);
        } else {
            b.setLargeIcon(foto != null ? foto : BitmapFactory.decodeResource(ctx.getResources(), iconoTipo(a)));
            /* Sin etiqueta del tipo al lado de "Melon Hub" (se probó): Android
               la enseña también plegado y cortaba el título; la foto o el
               icono pixel ya dicen de qué es. */
            b.setStyle(new NotificationCompat.BigTextStyle().bigText(a.body));
        }

        if (chat) {
            RemoteInput ri = new RemoteInput.Builder(ACCION_TEXTO)
                .setLabel("Escribe a " + (a.fromName.isEmpty() ? a.title : a.fromName))
                .build();
            b.addAction(new NotificationCompat.Action.Builder(R.drawable.ic_stat_melon, "Responder",
                    accion(ctx, "reply", a, key, true))
                .addRemoteInput(ri)
                .setAllowGeneratedReplies(false)
                .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_REPLY)
                .setShowsUserInterface(false)
                .build());
            b.addAction(new NotificationCompat.Action.Builder(R.drawable.ic_stat_melon, "Marcar como leído",
                    accion(ctx, "read", a, key, false))
                .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_MARK_AS_READ)
                .setShowsUserInterface(false)
                .build());
        } else if ("invite".equals(a.type) && !a.inviteId.isEmpty()
                && ("playlist".equals(a.source) || "item".equals(a.source))) {
            b.addAction(R.drawable.ic_stat_melon, "Aceptar", accion(ctx, "accept", a, key, false));
            b.addAction(R.drawable.ic_stat_melon, "Ver", abrir(ctx, a.url, key, "ver"));
        } else if ("ajz-reto".equals(a.type) && !a.inviteId.isEmpty()) {
            /* Reto de ajedrez: Rechazar sin abrir la app; Aceptar la abre
               (la partida empieza a contar y hay que entrar): mobile.php
               lo acepta con #abrir=reto-aceptar:N y abre la partida. */
            b.addAction(R.drawable.ic_stat_melon, "Rechazar", accion(ctx, "ajz-rechazar", a, key, false));
            b.addAction(R.drawable.ic_stat_melon, "Aceptar",
                abrir(ctx, conHash(a.url, "abrir=reto-aceptar:" + a.inviteId), key, "aceptar"));
        } else if ("ajz-partida".equals(a.type)) {
            b.addAction(R.drawable.ic_stat_melon, "Jugar", abrir(ctx, a.url, key, "jugar"));
        }

        try {
            NotificationManagerCompat.from(ctx).notify(key, 1, b.build());
        } catch (SecurityException ignored) { /* sin permiso de avisos */ }
    }

    /* Aviso corto cuando un botón no ha podido hacer lo suyo (sin
       conexión, sesión caducada…): tocarlo abre la app en ese sitio. */
    static void fallo(Context ctx, String key, String texto, String url) {
        ensureChannel(ctx);
        NotificationCompat.Builder b = new NotificationCompat.Builder(ctx, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_melon)
            .setColor(WidgetTheme.load(ctx).accent)
            .setContentTitle(texto)
            .setContentText("Toca para abrir Melon Hub")
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(abrir(ctx, url, key, "abrir"));
        try {
            NotificationManagerCompat.from(ctx).notify(key, 1, b.build());
        } catch (SecurityException ignored) {}
    }

    /* Sin el emoji del principio del título (🎧, 📋, 🔔…): la foto o el
       icono pixel del aviso ya dicen de qué es. La PWA lo sigue usando. */
    static String sinEmoji(String t) {
        String r = t.replaceFirst("^[^\\p{L}\\p{N}¡¿\"«(]+", "");
        return r.isEmpty() ? t : r;
    }

    /* La URL del aviso con otro #hash (misma página). */
    private static String conHash(String url, String hash) {
        int i = url.indexOf('#');
        return (i >= 0 ? url.substring(0, i) : url) + "#" + hash;
    }

    static void olvidarChat(String key) {
        synchronized (CHATS) { CHATS.remove(key); }
    }

    static void olvidarTodo() {
        synchronized (CHATS) { CHATS.clear(); }
    }

    private static Person persona(Context ctx, Aviso a, Bitmap foto) {
        Person.Builder p = new Person.Builder()
            .setName(a.fromName.isEmpty() ? a.title : a.fromName)
            .setKey(a.fromKey)
            .setImportant(true);
        p.setIcon(foto != null ? IconCompat.createWithBitmap(foto)
            : IconCompat.createWithBitmap(BitmapFactory.decodeResource(ctx.getResources(), R.drawable.aviso_chat)));
        return p.build();
    }

    /* Atajo de conversación: sin él Android no la pone en la sección
       "Conversaciones" ni deja marcarla como prioritaria. */
    private static void atajoConversacion(Context ctx, Aviso a, Person p) {
        try {
            Intent i = new Intent(ctx, MainActivity.class)
                .setAction(Intent.ACTION_MAIN)
                .putExtra(MainActivity.EXTRA_AVISO_URL, a.url);
            ShortcutInfoCompat.Builder s = new ShortcutInfoCompat.Builder(ctx, "chat_" + a.fromKey)
                .setShortLabel(p.getName() != null ? p.getName() : "Chat")
                .setLongLived(true)
                .setPerson(p)
                .setIntent(i);
            if (p.getIcon() != null) s.setIcon(p.getIcon());
            ShortcutManagerCompat.pushDynamicShortcut(ctx, s.build());
        } catch (Exception ignored) {}
    }

    private static PendingIntent abrir(Context ctx, String url, String key, String que) {
        Intent i = new Intent(ctx, MainActivity.class)
            .setAction("es.melonhub.AVISO_" + que)
            .putExtra(MainActivity.EXTRA_AVISO_URL, url)
            .putExtra(MainActivity.EXTRA_AVISO_KEY, key)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        return PendingIntent.getActivity(ctx, (key + que).hashCode(), i,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /* El de Responder tiene que ser MUTABLE: Android le mete el texto. */
    private static PendingIntent accion(Context ctx, String que, Aviso a, String key, boolean mutable) {
        Intent i = new Intent(ctx, NotifAccionReceiver.class)
            .setAction("es.melonhub.NOTIF_" + que)
            .putExtra("key", key)
            .putExtra("from", a.fromKey)
            .putExtra("fromName", a.fromName)
            .putExtra("source", a.source)
            .putExtra("inviteId", a.inviteId)
            .putExtra("url", a.url);
        int f = PendingIntent.FLAG_UPDATE_CURRENT
            | (mutable && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ? PendingIntent.FLAG_MUTABLE : PendingIntent.FLAG_IMMUTABLE);
        return PendingIntent.getBroadcast(ctx, (key + que).hashCode(), i, f);
    }

    /* Foto del remitente (la URL la manda el servidor). Con las cookies
       del WebView por si la carpeta de fotos pide sesión. Se reduce a
       192 px: la notificación no necesita más. null si falla. */
    static Bitmap bajarFoto(Context ctx, String url) {
        if (url == null || url.isEmpty()) return null;
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(url).openConnection();
            c.setConnectTimeout(4000);
            c.setReadTimeout(5000);
            String cookies = WidgetStore.cookies(ctx);
            if (cookies != null) c.setRequestProperty("Cookie", cookies);
            if (c.getResponseCode() != 200) return null;
            Bitmap b;
            try (InputStream in = c.getInputStream()) {
                b = BitmapFactory.decodeStream(in);
            }
            if (b == null) return null;
            int s = Math.min(b.getWidth(), b.getHeight());
            if (s > 192) {
                float k = 192f / s;
                b = Bitmap.createScaledBitmap(b, Math.round(b.getWidth() * k), Math.round(b.getHeight() * k), true);
            }
            return b;
        } catch (Exception e) {
            return null;
        } finally {
            if (c != null) c.disconnect();
        }
    }

    static Bitmap redonda(Bitmap src) {
        int s = Math.min(src.getWidth(), src.getHeight());
        Bitmap out = Bitmap.createBitmap(s, s, Bitmap.Config.ARGB_8888);
        Paint p = new Paint(Paint.ANTI_ALIAS_FLAG);
        p.setShader(new BitmapShader(src, Shader.TileMode.CLAMP, Shader.TileMode.CLAMP));
        Canvas c = new Canvas(out);
        c.translate(-(src.getWidth() - s) / 2f, -(src.getHeight() - s) / 2f);
        c.drawCircle(src.getWidth() / 2f, src.getHeight() / 2f, s / 2f, p);
        return out;
    }
}
