# Cierra una ventana (por id) como un gestor de ventanas: WM_DELETE_WINDOW, sin matarla.
import sys
from Xlib import display, X, protocol
d = display.Display()
w = d.create_resource_object('window', int(sys.argv[1]))
WM_PROTOCOLS = d.intern_atom('WM_PROTOCOLS'); WM_DELETE = d.intern_atom('WM_DELETE_WINDOW')
w.send_event(protocol.event.ClientMessage(window=w, client_type=WM_PROTOCOLS, data=(32, [WM_DELETE, X.CurrentTime, 0, 0, 0])))
d.flush(); print('cerrando la ventana', sys.argv[1])
