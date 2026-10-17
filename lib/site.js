// WordPress 一式の配置と初回セットアップ。
//
// - site/ は同梱の site-template/ をコピーしたもの。アプリ更新で template が変わったら丸ごと入れ替える
//   （wp-config.php・添付ファイル・プラグインが自分のフォルダに書く画像は引き継ぐ）
// - データ本体（SQLite）は site/ の外の data/kantanpro.sqlite。入れ替えの影響を受けない
// - 初回は「実運用と同じ構造（データなし）」を先に流してから KantanProEX を有効化する。
//   空の DB から有効化すると受注書が作れない（NOTES.md の段階0の発見1）
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { wpCli } = require('./php');

// 入れ替え時に旧 site/ から引き継ぐもの（site/ からの相対パス）
const KEEP = ['wp-config.php', 'wp-content/uploads', 'wp-content/logs', 'wp-content/plugins/KantanProEX/images/upload'];

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

function templateVersion(paths) {
  return readJson(path.join(paths.template, 'ktp-desktop', 'versions.json'));
}

function siteVersion(paths) {
  return readJson(path.join(paths.site, 'ktp-desktop', 'versions.json'));
}

/** site/ を template と同じ版にする。入れ替えたら true */
function syncSite(paths) {
  const tv = templateVersion(paths);
  if (!tv) throw new Error(`同梱の WordPress 一式が見つかりません: ${paths.template}`);
  const sv = siteVersion(paths);
  if (sv && sv.built === tv.built) return false;

  const old = `${paths.site}.old`;
  fs.rmSync(old, { recursive: true, force: true });
  if (fs.existsSync(paths.site)) fs.renameSync(paths.site, old);
  fs.cpSync(paths.template, paths.site, { recursive: true });
  if (fs.existsSync(old)) {
    for (const rel of KEEP) {
      const from = path.join(old, rel);
      if (!fs.existsSync(from)) continue;
      const to = path.join(paths.site, rel);
      fs.rmSync(to, { recursive: true, force: true });
      fs.mkdirSync(path.dirname(to), { recursive: true });
      fs.renameSync(from, to);
    }
    fs.rmSync(old, { recursive: true, force: true });
  }
  return true;
}

const phpString = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

function writeWpConfig(paths) {
  const file = path.join(paths.site, 'wp-config.php');
  if (fs.existsSync(file)) return;
  const salt = () => crypto.randomBytes(48).toString('base64');
  const keys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
  const php = `<?php
// KantanProEX デスクトップ版が自動生成した設定。手で書き換えない。
define( 'DB_NAME', 'kantanpro' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_PATH', ${phpString(paths.db)} );

${keys.map((k) => `define( '${k}', ${phpString(salt())} );`).join('\n')}

$table_prefix = 'wp_';

// ポートは起動ごとに変わることがあるので、URL は要求の Host から決める（wp-cli では環境変数）
$ktp_desktop_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : ( getenv( 'KTP_DESKTOP_HOST' ) ?: '127.0.0.1' );
define( 'WP_HOME', 'http://' . $ktp_desktop_host );
define( 'WP_SITEURL', 'http://' . $ktp_desktop_host );

define( 'KTP_DESKTOP', true );
define( 'KTP_DESKTOP_TOKEN_FILE', ${phpString(paths.tokenFile)} );

// 定期処理は Electron 側が wp-cron.php を叩く。更新・ファイル編集は行わない（アプリごと更新する）
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'FS_METHOD', 'direct' );
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'WP_DEBUG', false );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
`;
  fs.writeFileSync(file, php, { mode: 0o600 });
}

function installedMarker(paths) {
  return path.join(paths.data, 'installed.json');
}

/** 初回セットアップ（DB が未作成のときだけ） */
async function setupIfNeeded(paths, log = () => {}) {
  if (fs.existsSync(installedMarker(paths))) return false;
  fs.mkdirSync(paths.data, { recursive: true });
  // 途中で失敗した DB が残っていたら作り直す
  for (const f of fs.readdirSync(paths.data)) {
    if (f.startsWith('kantanpro.sqlite')) fs.rmSync(path.join(paths.data, f), { force: true });
  }
  const env = { KTP_DESKTOP_HOST: '127.0.0.1' };
  const wp = (...args) => wpCli(paths, args, env);

  log('WordPress を準備しています');
  const password = crypto.randomBytes(24).toString('base64url');
  await wp('core', 'install', '--url=http://127.0.0.1', '--title=KantanProEX', '--admin_user=kantanpro', `--admin_password=${password}`, '--admin_email=desktop@kantanpro.invalid', '--skip-email', '--locale=ja');
  await wp('option', 'update', 'WPLANG', 'ja');
  await wp('option', 'update', 'timezone_string', 'Asia/Tokyo');
  await wp('option', 'update', 'date_format', 'Y年n月j日');
  await wp('option', 'update', 'blog_public', '0');

  log('データベースの土台を作っています');
  const baseline = await wp('eval-file', path.join(paths.site, 'ktp-desktop', 'baseline.php'));
  if (!/created: (\d+) \/ \1\b/.test(baseline)) throw new Error(`土台の構造を作れませんでした:\n${baseline}`);

  log('KantanProEX を有効にしています');
  await wp('plugin', 'activate', 'KantanProEX');

  const pageId = await wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=KantanProEX', '--post_content=[ktpwp_all_tab]', '--porcelain');
  await wp('option', 'update', 'show_on_front', 'page');
  await wp('option', 'update', 'page_on_front', pageId.trim());
  // 初期データの投稿・固定ページ・コメントは不要
  for (const id of (await wp('post', 'list', '--post_type=post,page', '--format=ids')).split(/\s+/).filter(Boolean)) {
    if (id !== pageId.trim()) await wp('post', 'delete', id, '--force');
  }

  fs.writeFileSync(installedMarker(paths), JSON.stringify({ installedAt: new Date().toISOString(), pageId: Number(pageId), versions: siteVersion(paths) }, null, 2));
  return true;
}

module.exports = { syncSite, writeWpConfig, setupIfNeeded, templateVersion };
