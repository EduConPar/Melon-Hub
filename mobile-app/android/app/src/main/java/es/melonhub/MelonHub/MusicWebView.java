package es.melonhub.MelonHub;

import android.content.Context;
import android.util.AttributeSet;
import android.view.View;
import com.getcapacitor.CapacitorWebView;

/* WebView de Capacitor que sigue "visible" mientras suena música.
   Al salir de la app o apagar la pantalla, Android avisa al WebView de
   que su ventana ya no se ve: la página pasa a hidden y Chromium pausa
   el <video> del iframe de YouTube. Mientras hay música le decimos que
   sigue visible; al pausar, le pasamos la visibilidad real para que la
   web vuelva a ahorrar batería en segundo plano como siempre.
   Se usa desde res/layout/capacitor_bridge_layout_main.xml, que tapa
   el layout del mismo nombre de Capacitor. */
public class MusicWebView extends CapacitorWebView {

    private boolean keepVisible = false;

    public MusicWebView(Context context, AttributeSet attrs) {
        super(context, attrs);
    }

    public void setKeepVisible(boolean keep) {
        if (keepVisible == keep) return;
        keepVisible = keep;
        /* Al soltar, la página recibe la visibilidad de verdad (si la
           app está en segundo plano, ahora sí pasa a hidden). */
        if (!keep) super.onWindowVisibilityChanged(getWindowVisibility());
    }

    @Override
    protected void onWindowVisibilityChanged(int visibility) {
        super.onWindowVisibilityChanged(keepVisible ? View.VISIBLE : visibility);
    }
}
