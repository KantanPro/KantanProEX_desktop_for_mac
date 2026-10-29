#!/usr/bin/env bash
# ビルドした dist/mac/KantanProEX.app を、本人が使う /Applications/KantanProEX.app に入れ替える。
# 配布（WooCommerce 差し替え・update.json push）とは別物。手元で試すためだけの入れ替え。
#
#   scripts/install-local.sh          … 入れ替える
#   scripts/install-local.sh --dry    … 何をするかだけ表示する
#
# 守ること:
# - 起動中なら終了させない。アプリ名指定で止めると本人の作業中のデータが壊れる（2026-10-17 の事故）。
#   終了を頼む表示を出して止まる（終了コード 2）。
# - データの場所（~/Library/Application Support/KantanProEX、~/Documents/KantanProEX）には触らない。
# - 旧版は消さずに work/old-apps/KantanProEX-<旧版>.app へ移す。
set -euo pipefail
cd "$(dirname "$0")/.."

DRY=0
[ "${1:-}" = "--dry" ] && DRY=1

SRC="dist/mac/KantanProEX.app"
# テスト用に入れ先を差し替えられるようにしておく（本物の /Applications を使わずに試すため）
DEST_DIR="${KTP_INSTALL_DEST:-/Applications}"
DEST="$DEST_DIR/KantanProEX.app"
OLD_DIR="${KTP_INSTALL_OLD_DIR:-work/old-apps}"

ver() { /usr/libexec/PlistBuddy -c 'Print CFBundleShortVersionString' "$1/Contents/Info.plist" 2>/dev/null; }

[ -d "$SRC" ] || { echo "✗ $SRC がありません。先に npm run build:mac"; exit 1; }
NEW_VER=$(ver "$SRC")
echo "ビルド: $NEW_VER（$(stat -f '%Sm' -t '%m/%d %H:%M' "$SRC/Contents/Resources/app.asar")）"

if [ -d "$DEST" ]; then
  OLD_VER=$(ver "$DEST")
  echo "入っている版: $OLD_VER"
  # 同じ版番号でも作り直していることがあるので、版番号ではなくビルドごとに変わる app.asar で比べる。
  # ditto は更新日時を保つので、大きさと更新日時が同じなら同じビルド（中身の比較は site-template が大きく数分かかる）
  stamp() { stat -f '%z %m' "$1/Contents/Resources/app.asar" 2>/dev/null; }
  if [ "$(stamp "$SRC")" = "$(stamp "$DEST")" ]; then
    echo "✓ 同じビルドがすでに入っています。何もしません"
    exit 0
  fi
else
  OLD_VER=""
  echo "入っている版: なし"
fi

# 起動中か。/Applications 以外へ入れるテストのときも、本物が起動中なら同じく止まる
if [ -n "$OLD_VER" ] && pgrep -f "$DEST/Contents/MacOS" >/dev/null; then
  echo ""
  echo "⚠ KantanProEX が起動中です。アプリを終了してから、もう一度実行してください。"
  echo "  （こちらからは終了させません）"
  exit 2
fi

if [ -n "$OLD_VER" ]; then
  BACKUP="$OLD_DIR/KantanProEX-$OLD_VER.app"
  # 同じ版をもう一度入れ直すときは、先に退避したものを上書きしない
  [ -e "$BACKUP" ] && BACKUP="$OLD_DIR/KantanProEX-$OLD_VER-$(date +%m%d%H%M).app"
  echo "旧版の退避先: $BACKUP"
fi

if [ "$DRY" = 1 ]; then
  echo "(--dry のため入れ替えていません)"
  exit 0
fi

mkdir -p "$OLD_DIR"
[ -n "$OLD_VER" ] && mv "$DEST" "$BACKUP"
ditto "$SRC" "$DEST"

GOT=$(ver "$DEST")
[ "$GOT" = "$NEW_VER" ] || { echo "✗ 入れ替え後の版が $GOT です（期待: $NEW_VER）"; exit 1; }
echo "✓ $DEST を $NEW_VER に入れ替えました"

# 旧版は 1 本 400MB 近くある。消すのは本人判断なので、たまってきたら知らせるだけにする
N=$(find "$OLD_DIR" -maxdepth 1 -name 'KantanProEX-*.app' | wc -l | tr -d ' ')
if [ "$N" -gt 5 ]; then
  echo "（参考）$OLD_DIR に旧版が $N 本、$(du -sh "$OLD_DIR" | cut -f1) あります。空きは $(df -h / | awk 'NR==2{print $4}')"
fi
