package es.melonhub.MelonHub;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.widget.RemoteViews;

/* Widget de calendario 2×2: "Próximos", los recordatorios que vienen (los
   de los eventos a los que te unes también son recordatorios, "Evento: …").
   Tocar uno abre el calendario de la app en ese día; ≡ lo abre en hoy.
   Los datos y el refresco los lleva CalendarWidget / CalendarStore; las
   filas, CalendarAgendaService. */
public class CalendarAgendaWidget extends AppWidgetProvider {

    @Override
    public void onUpdate(Context ctx, AppWidgetManager mgr, int[] ids) {
        for (int id : ids) mgr.updateAppWidget(id, views(ctx, mgr, id));
        CalendarWidget.refreshData(ctx, goAsync());
    }

    static RemoteViews views(Context ctx, AppWidgetManager mgr, int widgetId) {
        WidgetTheme t = WidgetTheme.load(ctx);
        RemoteViews rv = new RemoteViews(ctx.getPackageName(), t.kawaii ? R.layout.widget_kw_cal_agenda : R.layout.widget_w98_cal_agenda);
        WidgetPainter p = new WidgetPainter(ctx, rv, t);
        p.frame();
        p.titleBar();
        p.button(R.id.w_menu_1, R.id.w_menu, R.id.w_menu_ic, false);
        p.field(R.id.w_lst_1, R.id.w_lst);
        float w = PlayerWidget.widthDp(mgr, widgetId);

        rv.setImageViewBitmap(R.id.w_title, WidgetText.draw(ctx, t, WidgetText.TITLE, ctx.getString(R.string.cal_title_agenda),
            13, t.tbText, w - (t.kawaii ? 45 : 34), 1, false));
        rv.setImageViewBitmap(R.id.w_empty, WidgetText.draw(ctx, t, WidgetText.BODY, ctx.getString(R.string.widget_loading),
            12, t.muted, w - (t.kawaii ? 42 : 36), 1, true));
        rv.setOnClickPendingIntent(R.id.w_menu_1, CalendarWidget.openDay(ctx, 2000 + (widgetId % 1000), ""));

        /* El tema y el ancho van en la URI: si cambian, filas nuevas. */
        Intent adapter = new Intent(ctx, CalendarAgendaService.class)
            .putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, widgetId)
            .putExtra("rowRoomDp", w - (t.kawaii ? 74 : 66));
        String theme = WidgetStore.themeRaw(ctx);
        adapter.setData(Uri.parse("melonagenda://" + widgetId + "/" + (theme != null ? theme.hashCode() : 0) + "/" + Math.round(w)));
        rv.setRemoteAdapter(R.id.w_list, adapter);
        rv.setEmptyView(R.id.w_list, R.id.w_empty);
        /* Cada fila añade su día (setOnClickFillInIntent). */
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ? PendingIntent.FLAG_MUTABLE : 0);
        rv.setPendingIntentTemplate(R.id.w_list,
            PendingIntent.getActivity(ctx, 3000 + (widgetId % 1000), CalendarWidget.openDayIntent(ctx), flags));
        return rv;
    }
}
