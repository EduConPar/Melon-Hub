dnf -q -y install xorg-x11-server-Xvfb dbus-daemon dbus-tools python3-dbus python3-gobject python3-xlib xdotool xprop ImageMagick >/dev/null 2>&1; echo "deps: $?"
useradd -m tester; mkdir -p /home/tester/.local/share
cp -a /root/.local/share/flatpak /home/tester/.local/share/flatpak
cp /app/dist/MelonHub.flatpak /home/tester/; cp -a /harness /home/tester/harness; chown -R tester: /home/tester
mkdir -p /tmp/rt && chown tester: /tmp/rt && chmod 700 /tmp/rt
Xvfb :99 -screen 0 1400x900x24 >/dev/null 2>&1 &
runuser -u tester -- env HOME=/home/tester XDG_RUNTIME_DIR=/tmp/rt DISPLAY=:99 \
    flatpak install --user -y --noninteractive --bundle /home/tester/MelonHub.flatpak >/dev/null 2>&1
runuser -u tester -- env HOME=/home/tester XDG_RUNTIME_DIR=/tmp/rt DISPLAY=:99 \
    dbus-run-session -- /home/tester/harness/session.sh 2>&1 | grep -v -E "^dbus-daemon\[|portal|RealtimeKit"
cp /home/tester/cerrar.png /out/cerrar.png 2>/dev/null
