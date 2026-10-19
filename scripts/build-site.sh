#!/usr/bin/env bash
# アプリに同梱する WordPress 一式（site-template/）を組み立てる。
#   WordPress 本体 + 日本語パック + SQLite 統合プラグイン + KantanProEX(pro) + デスクトップ用 mu-plugin
# KantanProEX は EX リポジトリの作業ツリーから create_release_zip.sh pro で作る（未コミットの修正も入る）。
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
WP_VER="6.9.4"
EX_DIR="${KTPEX_SOURCE_DIR:-/Users/kantanpro/Desktop/KantanPro/wordpress/wp-content/plugins/KantanProEX}"
DL="$ROOT/work/dl"; OUT="$ROOT/site-template"
mkdir -p "$DL"

fetch() { [ -s "$2" ] || curl -fsSL -m 300 -o "$2" "$1"; }
fetch "https://wordpress.org/wordpress-${WP_VER}.tar.gz" "$DL/wordpress-${WP_VER}.tar.gz"
fetch "https://downloads.wordpress.org/translation/core/${WP_VER}/ja.zip" "$DL/wp-ja-${WP_VER}.zip"
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip" "$DL/sqlite-database-integration.zip"

echo "[1/5] WordPress 本体"
# 前回の組み立て結果は WordPress の配布物由来で読み取り専用のファイルを含むので、権限を戻してから消す
chmod -R u+rwX "$OUT" 2>/dev/null || true
rm -rf "$OUT" && mkdir -p "$OUT"
tar xzf "$DL/wordpress-${WP_VER}.tar.gz" -C "$OUT" --strip-components=1
rm -rf "$OUT/wp-content/plugins/akismet" "$OUT/wp-content/plugins/hello.php" "$OUT/wp-config-sample.php" "$OUT/readme.html" "$OUT/license.txt"
# テーマは画面に使わない（mu-plugin の専用テンプレートで描画）が、WP が1つ必要とするので最小限だけ残す
find "$OUT/wp-content/themes" -mindepth 1 -maxdepth 1 -type d ! -name twentytwentyfive -exec rm -rf {} +

echo "[2/5] 日本語パック"
mkdir -p "$OUT/wp-content/languages"
unzip -q -o "$DL/wp-ja-${WP_VER}.zip" -d "$OUT/wp-content/languages"

echo "[3/5] SQLite 統合プラグイン"
unzip -q -o "$DL/sqlite-database-integration.zip" -d "$OUT/wp-content/plugins"
sed -e "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__ . '/plugins/sqlite-database-integration'#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    "$OUT/wp-content/plugins/sqlite-database-integration/db.copy" > "$OUT/wp-content/db.php"
grep -q "__DIR__ . '/plugins/sqlite-database-integration'" "$OUT/wp-content/db.php"

echo "[4/5] KantanProEX (pro)"
BUILD_TMP="$ROOT/work/ex-build"
rm -rf "$BUILD_TMP"
( cd "$EX_DIR" && KTPWP_RELEASE_DEST_PARENT="$ROOT/work" KTPWP_RELEASE_DEST_DIR_NAME=ex-build bash create_release_zip.sh pro >/dev/null )
unzip -q -o "$BUILD_TMP"/KantanProEX_*.zip -d "$OUT/wp-content/plugins"
grep -q "define( 'KTPWP_EDITION', 'pro' )" "$OUT/wp-content/plugins/KantanProEX/ktpwp.php"
# ダミーデータ作成はデスクトップ版にだけ入れる（WP 版の配布 ZIP は開発用として外している。お試し用に使う）
cp "$EX_DIR/create_dummy_data.php" "$OUT/wp-content/plugins/KantanProEX/create_dummy_data.php"

echo "[5/5] デスクトップ用の追加分"
cp -R "$ROOT/site/mu-plugins" "$OUT/wp-content/"
# ユーザー登録のニュースレター登録用の秘密（ソースには置かない）をビルド物にだけ差し込む
REG_SECRET_FILE="${KTP_DESKTOP_REG_SECRET_FILE:-$HOME/.kantanpro/kpdg-desktop-secret.txt}"
REG_FILE="$OUT/wp-content/mu-plugins/ktp-desktop/registration.php"
if [ -f "$REG_SECRET_FILE" ]; then
  KTP_DESKTOP_REG_SECRET="$(tr -d '[:space:]' < "$REG_SECRET_FILE")" perl -pi -e 's/__KTP_DESKTOP_REG_SECRET__(?=\x27;)/$ENV{KTP_DESKTOP_REG_SECRET}/' "$REG_FILE"
else
  echo "! $REG_SECRET_FILE が無いので、ニュースレター登録は動かないビルドになります"
fi
mkdir -p "$OUT/ktp-desktop"
cp "$ROOT/assets/baseline-ddl.json" "$ROOT/scripts/baseline.php" "$ROOT/scripts/import-kantanbiz.php" "$ROOT/scripts/import-wordpress.php" "$OUT/ktp-desktop/"
cp "$EX_DIR/wp-cli.phar" "$OUT/ktp-desktop/wp-cli.phar"
# 初回セットアップで使う。Web からは見えないよう php -S の外（router で拒否）に置く
EX_VER=$(grep -m1 -i "Version:" "$OUT/wp-content/plugins/KantanProEX/ktpwp.php" | sed -E 's/.*Version:[[:space:]]*([0-9.]+).*/\1/')
printf '{"wordpress":"%s","kantanproex":"%s","built":"%s"}\n' "$WP_VER" "$EX_VER" "$(date +%Y-%m-%dT%H:%M:%S)" > "$OUT/ktp-desktop/versions.json"
cat "$OUT/ktp-desktop/versions.json"
du -sh "$OUT" | cut -f1
