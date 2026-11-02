#!/bin/bash
# Se ejecuta como el usuario de prueba dentro de una sesión de D-Bus.
cd ~/harness
python3 watcher.py > /tmp/watcher.log 2>&1 &
sleep 2
flatpak run --user --env=MELONHUB_URL=file:///home/tester/harness/site/index.html \
    --filesystem=/home/tester/harness:ro es.melonhub.MelonHub > /tmp/app.log 2>&1 &
sleep 15

visibles() {
    for w in $(xdotool search --onlyvisible --name . 2>/dev/null); do
        geo=$(xdotool getwindowgeometry "$w" | tr '\n' ' ' | sed 's/Window [0-9]* *//')
        echo "   visible: «$(xdotool getwindowname "$w")» $geo"
    done
}
procesos() {
    flatpak ps --columns=instance,pid,application 2>/dev/null | grep melonhub | awk '{print $1 "/" $2}' | tr '\n' ' '
}

echo "── 1. icono en la bandeja desde el sandbox:"
grep -v lista /tmp/watcher.log
visibles

echo "── 2. cerrar la ventana principal (con música sonando):"
main_id=$(xdotool search --onlyvisible --name "prueba" | head -1)
if [ -n "$main_id" ]; then
    python3 close.py "$main_id"
else
    echo "   no encuentro la ventana principal"
fi
sleep 4
visibles
import -window root ~/cerrar.png
mini_id=$(xdotool search --onlyvisible --name "^Melon Hub$" | head -1)
if [ -n "$mini_id" ]; then
    xprop -id "$mini_id" _NET_WM_STATE | sed "s/^/   estado del flotante: /"
else
    echo "   no hay reproductor flotante visible"
fi

echo "── 3. «Reiniciar» desde el menú del icono:"
antes=$(procesos)
python3 click.py Reiniciar
sleep 25
despues=$(procesos)
echo "   instancias/PID antes:   $antes"
echo "   instancias/PID después: $despues"
echo "   el icono se ha registrado $(grep -c REGISTRADO /tmp/watcher.log) veces (la 2ª es la app reiniciada)"
visibles

echo "── 4. «Cerrar» desde el menú del icono:"
python3 click.py Cerrar
sleep 6
if flatpak ps --columns=application 2>/dev/null | grep -q melonhub; then
    echo "   la app SIGUE en marcha: $(procesos)"
else
    echo "   la app ya no está en marcha"
fi
