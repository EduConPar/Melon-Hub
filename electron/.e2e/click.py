# Pulsa una opción del menú del icono, como haría KDE (dbusmenu Event "clicked").
import dbus, sys, time
bus = dbus.SessionBus()
name, path = open('/tmp/tray.txt').read().split('\n')[-2].split()
props = dbus.Interface(bus.get_object(name, path), 'org.freedesktop.DBus.Properties')
menu = dbus.Interface(bus.get_object(name, props.Get('org.kde.StatusNotifierItem', 'Menu')), 'com.canonical.dbusmenu')
rev, layout = menu.GetLayout(dbus.Int32(0), dbus.Int32(-1), dbus.Array([], signature='s'))
for child in layout[2]:
    if str(child[1].get('label', '')).replace('_', '') == sys.argv[1]:
        menu.Event(child[0], 'clicked', dbus.Int32(0, variant_level=1), dbus.UInt32(int(time.time())))
        print('clic en «%s»' % sys.argv[1]); break
else:
    print('no encuentro «%s»' % sys.argv[1])
