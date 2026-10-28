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


// URL の決め方（ポートは起動ごとに変わることがあるので、要求の Host から決める。wp-cli では環境変数）。
// LAN 共有の窓口（lib/lan.js）経由の要求は、起動ごとの合言葉（X-KTP-LAN）が合うときだけ「LAN から」と扱い、
// https・接続元の IP を引き継ぐ。合わない X-KTP-* は信用せず捨てる（外から偽装できない）
const VOICE_BLOCK = `// 音声操作の窓口経由か（KTP_VOICE_URL_BLOCK）。LAN 経由とは排他。合言葉が合うときだけ KTP_DESKTOP_VOICE_REQUEST を定義する
$ktp_voice_secret = getenv( 'KTP_VOICE_SECRET' );
$ktp_voice_given  = isset( $_SERVER['HTTP_X_KTP_VOICE'] ) ? (string) $_SERVER['HTTP_X_KTP_VOICE'] : '';
if ( ! defined( 'KTP_DESKTOP_LAN_REQUEST' ) && is_string( $ktp_voice_secret ) && strlen( $ktp_voice_secret ) >= 32 && $ktp_voice_given !== '' && hash_equals( $ktp_voice_secret, $ktp_voice_given ) ) {
	define( 'KTP_DESKTOP_VOICE_REQUEST', true );
}
unset( $_SERVER['HTTP_X_KTP_VOICE'], $ktp_voice_secret, $ktp_voice_given );
`;

const WP_URL_BLOCK = `// URL の決め方（LAN 共有の窓口経由かどうかの判定を含む。KTP_LAN_URL_BLOCK）
$ktp_lan_secret = getenv( 'KTP_LAN_SECRET' );
$ktp_lan_given  = isset( $_SERVER['HTTP_X_KTP_LAN'] ) ? (string) $_SERVER['HTTP_X_KTP_LAN'] : '';
if ( is_string( $ktp_lan_secret ) && strlen( $ktp_lan_secret ) >= 32 && $ktp_lan_given !== '' && hash_equals( $ktp_lan_secret, $ktp_lan_given ) ) {
	define( 'KTP_DESKTOP_LAN_REQUEST', true );
	$_SERVER['HTTPS'] = 'on';
	if ( ! empty( $_SERVER['HTTP_X_KTP_REMOTE'] ) ) {
		$_SERVER['REMOTE_ADDR'] = (string) $_SERVER['HTTP_X_KTP_REMOTE'];
	}
}
unset( $_SERVER['HTTP_X_KTP_LAN'], $_SERVER['HTTP_X_KTP_REMOTE'], $ktp_lan_secret, $ktp_lan_given );
${VOICE_BLOCK}
$ktp_desktop_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : ( getenv( 'KTP_DESKTOP_HOST' ) ?: '127.0.0.1' );
$ktp_desktop_scheme = ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ? 'https' : 'http' );
define( 'WP_HOME', $ktp_desktop_scheme . '://' . $ktp_desktop_host );
define( 'WP_SITEURL', $ktp_desktop_scheme . '://' . $ktp_desktop_host );`;

const phpString = (s) => `'${String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

function writeWpConfig(paths) {
  const file = path.join(paths.site, 'wp-config.php');
  if (fs.existsSync(file)) {
    // 既存の設定に LAN 共有の判定が入っていなければ、URL の 3 行だけを差し替える（鍵などはそのまま）
    const cur = fs.readFileSync(file, 'utf8');
    if (cur.includes('KTP_LAN_URL_BLOCK') && cur.includes('KTP_VOICE_URL_BLOCK')) return;
    // LAN 共有の判定だけが入っている設定には、音声操作の判定を足す（鍵などはそのまま）
    if (cur.includes('KTP_LAN_URL_BLOCK')) {
      const marker = '$ktp_desktop_host = isset(';
      if (!cur.includes(marker)) throw new Error('wp-config.php に音声操作の設定を足せませんでした');
      fs.writeFileSync(file, cur.replace(marker, () => `${VOICE_BLOCK}${marker}`), { mode: 0o600 });
      return;
    }
    const next = cur.replace(/\/\/ ポートは起動ごとに変わることがあるので[\s\S]*?define\( 'WP_SITEURL'[^\n]*\n/, () => `${WP_URL_BLOCK}\n`);
    if (next === cur) throw new Error('wp-config.php の URL の設定を更新できませんでした');
    fs.writeFileSync(file, next, { mode: 0o600 });
    return;
  }
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

${WP_URL_BLOCK}

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

  log('初めての準備をしています（10秒ほどかかります）');
  const password = crypto.randomBytes(24).toString('base64url');
  await wp('core', 'install', '--url=http://127.0.0.1', '--title=KantanProEX', '--admin_user=kantanpro', `--admin_password=${password}`, '--admin_email=desktop@kantanpro.invalid', '--skip-email', '--locale=ja');
  await wp('option', 'update', 'WPLANG', 'ja');
  await wp('option', 'update', 'timezone_string', 'Asia/Tokyo');
  await wp('option', 'update', 'date_format', 'Y年n月j日');
  await wp('option', 'update', 'blog_public', '0');

  log('データの保存場所を作っています');
  const baseline = await wp('eval-file', path.join(paths.site, 'ktp-desktop', 'baseline.php'));
  if (!/created: (\d+) \/ \1\b/.test(baseline)) throw new Error(`土台の構造を作れませんでした:\n${baseline}`);

  log('もうすぐ使えます');
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
