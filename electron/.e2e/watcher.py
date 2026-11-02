# Hace de bandeja de KDE: registra iconos (StatusNotifierWatcher) y lee su menú (dbusmenu).
import dbus, dbus.service, dbus.mainloop.glib, sys
from gi.repository import GLib
dbus.mainloop.glib.DBusGMainLoop(set_as_default=True)
bus = dbus.SessionBus()
IFACE = 'org.kde.StatusNotifierWatcher'
class Watcher(dbus.service.Object):
    @dbus.service.method(IFACE, in_signature='s', out_signature='', sender_keyword='sender')
    def RegisterStatusNotifierItem(self, service, sender=None):
        # El icono se registra con una ruta (y vive en la conexión de quien
        # llama) o con un nombre de bus (y vive en la ruta estándar).
        if service.startswith('/'):
            name, path = sender, service
        else:
            name, path = service, '/StatusNotifierItem'
        print('ICONO REGISTRADO desde %s' % sender, flush=True)
        open('/tmp/tray.txt', 'a').write('%s %s\n' % (name, path))
        GLib.timeout_add(1500, lambda: dump(name, path) or False)
    @dbus.service.method(IFACE, in_signature='s', out_signature='')
    def RegisterStatusNotifierHost(self, s): pass
    @dbus.service.method('org.freedesktop.DBus.Properties', in_signature='ss', out_signature='v')
    def Get(self, i, p):
        return {'IsStatusNotifierHostRegistered': dbus.Boolean(True), 'ProtocolVersion': dbus.Int32(0),
                'RegisteredStatusNotifierItems': dbus.Array([], signature='s')}[p]
    @dbus.service.method('org.freedesktop.DBus.Properties', in_signature='s', out_signature='a{sv}')
    def GetAll(self, i):
        return {'IsStatusNotifierHostRegistered': dbus.Boolean(True), 'ProtocolVersion': dbus.Int32(0)}
def dump(name, path):
    try:
        item = bus.get_object(name, path)
        props = dbus.Interface(item, 'org.freedesktop.DBus.Properties')
        menu_path = props.Get('org.kde.StatusNotifierItem', 'Menu')
        print('  título:', props.Get('org.kde.StatusNotifierItem', 'Title'), '| tooltip:', props.Get('org.kde.StatusNotifierItem', 'ToolTip')[2], flush=True)
        menu = dbus.Interface(bus.get_object(name, menu_path), 'com.canonical.dbusmenu')
        rev, layout = menu.GetLayout(dbus.Int32(0), dbus.Int32(-1), dbus.Array([], signature='s'))
        for child in layout[2]:
            p = child[1]
            if p.get('type') == 'separator': print('  ──────', flush=True); continue
            tog = p.get('toggle-type')
            mark = (' [✓]' if p.get('toggle-state') == 1 else ' [ ]') if tog else ''
            print('  menú:', str(p.get('label', '')).replace('_', '') + mark, flush=True)
    except Exception as e:
        print('  ERROR leyendo el icono:', e, flush=True)
bus_name = dbus.service.BusName(IFACE, bus)
Watcher(bus, '/StatusNotifierWatcher')
print('bandeja falsa lista', flush=True)
GLib.MainLoop().run()
