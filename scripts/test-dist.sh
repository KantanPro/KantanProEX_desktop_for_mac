#!/usr/bin/env bash
# 配布版（dist/mac/KantanProEX.app）を、本人のデータとは別の場所で起動して自動テストする。
# 本人が使っている ~/Library/Application Support/KantanProEX と ~/Documents/KantanProEX バックアップ には触らない。
# 使い方: scripts/test-dist.sh   （終了は自分が起動したプロセスだけを止める）
set -uo pipefail
cd "$(dirname "$0")/.."
TEST_DIR="$PWD/work/test-userdata"
rm -rf "$TEST_DIR" && mkdir -p "$TEST_DIR"
env -u ELECTRON_RUN_AS_NODE KTP_TEST_USER_DATA="$TEST_DIR" KTP_DEBUG_PORT=9556 KTP_TEST_DOWNLOAD_DIR="$PWD/work/app-dl" \
  dist/mac/KantanProEX.app/Contents/MacOS/KantanProEX > work/test-dist.log 2>&1 &
APP_PID=$!
for i in $(seq 1 120); do grep -q "アプリの画面を表示" "$TEST_DIR/logs/app.log" 2>/dev/null && break; sleep 1; done
grep -E "PHP server|アプリの画面を表示" "$TEST_DIR/logs/app.log"
node work/app-e2e-dist.mjs work/dist-e2e 2>&1 | cut -c1-110 | grep -E "✓|!|問題|pageerror|http|dialog|Error"
node work/app-js-errors-dist.mjs "/?tab_name=list" "/?tab_name=client" "/?tab_name=service" "/?tab_name=report" "/wp-admin/admin.php?page=ktp-settings" "/wp-admin/profile.php"; echo "(JS例外ここまで)"
node work/ext.mjs 9556
kill "$APP_PID"; sleep 4; kill -0 "$APP_PID" 2>/dev/null && kill -9 "$APP_PID"
echo "テスト用データ: $TEST_DIR"
