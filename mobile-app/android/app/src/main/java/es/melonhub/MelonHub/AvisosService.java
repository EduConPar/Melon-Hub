package es.melonhub.MelonHub;

import androidx.annotation.NonNull;
import com.capacitorjs.plugins.pushnotifications.PushNotificationsPlugin;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.Map;

/* Recibe los mensajes de FCM en lugar del servicio del plugin de
   Capacitor (quitado en el AndroidManifest con tools:node="remove"); lo
   que no es nuestro se le pasa igual que hacía él.
   Los avisos de Melon Hub llegan como mensajes de datos ("mh": "1",
   ver melonFcmEnviar en assets/push/send-push.php) para montarlos aquí
   con su estilo (MelonNotifs). Con la app delante no se enseñan: la web
   ya avisa dentro (como antes con los mensajes "notification"). */
public class AvisosService extends FirebaseMessagingService {

    @Override
    public void onMessageReceived(@NonNull RemoteMessage msg) {
        super.onMessageReceived(msg);
        Map<String, String> d = msg.getData();
        if (!"1".equals(d.get("mh")) || MainActivity.enPrimerPlano) {
            PushNotificationsPlugin.sendRemoteMessage(msg);
            return;
        }
        MelonNotifs.Aviso a = MelonNotifs.Aviso.deDatos(d);
        if (msg.getSentTime() > 0) a.when = msg.getSentTime();
        /* Aquí se puede tardar (hilo propio de FCM, unos segundos). */
        a.avatar = MelonNotifs.bajarFoto(this, a.icon);
        MelonNotifs.show(this, a);
    }

    @Override
    public void onNewToken(@NonNull String token) {
        super.onNewToken(token);
        PushNotificationsPlugin.onNewToken(token);
    }
}
