package es.melonhub.MelonHub;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        /* Los plugins propios se registran antes de crear el puente. */
        registerPlugin(MelonMusicPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
