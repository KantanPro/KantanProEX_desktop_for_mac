#!/bin/zsh
# dmg を開いたときの背景（build/dmg-background.html）を、Chrome で build/background.png（660×460）と @2x に書き出す。
# 文言やアイコンの位置を変えたら、これを流してからビルドする。
set -e
cd "$(dirname "$0")/.."
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
TMP=$(mktemp -d)
for s in 1 2; do
  out=build/background.png; [ $s = 2 ] && out=build/background@2x.png
  "$CHROME" --headless=new --disable-gpu --hide-scrollbars --user-data-dir="$TMP/p$s" \
    --window-size=660,460 --force-device-scale-factor=$s --screenshot="$PWD/$out" "file://$PWD/build/dmg-background.html" 2>/dev/null
  sips -g pixelWidth -g pixelHeight "$out" | tail -2 | tr '\n' ' '; echo " $out"
done
rm -rf "$TMP"
