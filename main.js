// KantanProEX デスクトップ版。
// 同梱の PHP で WordPress + KantanProEX を 127.0.0.1 だけで動かし、このウィンドウで開く。
const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { app, BrowserWindow, Menu, dialog, shell, session, nativeTheme, powerSaveBlocker, clipboard } = require('electron');

// 開発中はインストール版とデータを分ける
// 自動テスト用: KTP_TEST_USER_DATA を指定すると、配布版でも本人のデータとは別の場所で動く。
// （配布版の確認で本人のデータ・バックアップを消してしまった 2026-10-17 の事故の再発防止）
if (process.env.KTP_TEST_USER_DATA) {
  app.setPath('userData', process.env.KTP_TEST_USER_DATA);
} else if (!app.isPackaged) {
  app.setPath('userData', path.join(app.getPath('appData'), 'KantanProEX-dev'));
}
// 自動テスト用: KTP_DEBUG_PORT（開発時、またはテスト用データ指定時のみ）で Playwright から接続できる
if (process.env.KTP_DEBUG_PORT && (!app.isPackaged || process.env.KTP_TEST_USER_DATA)) {
  app.commandLine.appendSwitch('remote-debugging-port', process.env.KTP_DEBUG_PORT);
}
app.setName('KantanProEX');
// 画面の言語は常に日本語にする（OS が英語などでも同じ表示にする）。
// Windows で OS の言語に合わせると、数値・日付の入力欄の書式づくりで画面が落ちることがあった（言語ファイルが ja しか無かったため）
app.commandLine.appendSwitch('lang', 'ja');

const { getPaths } = require('./lib/paths');
const { findPort, startServer, wpCli } = require('./lib/php');
const { syncSite, writeWpConfig, setupIfNeeded } = require('./lib/site');
const { createBackup, autoBackupIfDue, restoreBackup } = require('./lib/backup');
const { checkForUpdates, isAutoCheckEnabled, setAutoCheckEnabled } = require('./lib/update');
const lan = require('./lib/lan');

const PREFERRED_PORT = 47321;
const CRON_INTERVAL_MS = 5 * 60 * 1000;

let paths;
let server = null; // { baseUrl, stop }
let mainWindow = null;
let splashWindow = null; // 起動中の小さな画面
let cronTimer = null;
let quitting = false;
// LAN 共有（既定はオフ）。合言葉は起動ごとに作り、PHP と窓口だけが知る
const lanSecret = crypto.randomBytes(32).toString('hex');
let lanGateway = null; // { port, urls, fingerprint, stop }
let lanBlockerId = null;

if (!app.requestSingleInstanceLock()) {
  app.quit();
}
app.on('second-instance', () => {
  if (mainWindow) {
    if (mainWindow.isMinimized()) mainWindow.restore();
    mainWindow.focus();
  }
});

function log(msg) {
  try {
    fs.mkdirSync(paths.logs, { recursive: true });
    fs.appendFileSync(path.join(paths.logs, 'app.log'), `[${new Date().toISOString()}] ${msg}\n`);
  } catch {}
}

// ---- 準備中の画面 ----
// 起動中の画面に出すマーク（台なし。data: のページなので画像は埋め込む）
const ICON_DATA_URL = (() => {
  try {
    return `data:image/png;base64,${fs.readFileSync(path.join(__dirname, 'assets', 'icon', 'mark-256.png')).toString('base64')}`;
  } catch {
    return '';
  }
})();
const LOADING_HTML = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><title>KantanProEX</title>
<style>
:root{--bg:#ffffff;--fg:#1f2937;--sub:#6b7280;color-scheme:light}
html,body{height:100%;margin:0;background:var(--bg);color:var(--fg);font-family:-apple-system,"Hiragino Sans",sans-serif}
.w{height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px}
h1{font-size:22px;margin:0} #m{color:var(--sub);font-size:14px}
.s{width:20px;height:20px;border:2px solid #c7d2fe;border-top-color:#4f46e5;border-radius:50%;animation:r 1s linear infinite}
@keyframes r{to{transform:rotate(360deg)}}
img{width:88px;height:88px}
</style></head><body><div class="w"><img src="${ICON_DATA_URL}" alt=""><h1>KantanProEX</h1><div class="s"></div><div id="m">起動しています…</div></div></body></html>`;

function setLoadingMessage(msg) {
  log(msg);
  if (splashWindow && !splashWindow.isDestroyed()) {
    splashWindow.webContents.executeJavaScript(`document.getElementById('m')&&(document.getElementById('m').textContent=${JSON.stringify(msg)})`).catch(() => {});
  }
}

// ---- PHP サーバー ----
async function startPhp() {
  const port = await findPort(PREFERRED_PORT);
  server = await startServer(paths, port, lanSecret);
  log(`PHP server ${server.baseUrl}`);
  await startLan();
  clearInterval(cronTimer);
  // WordPress の定期処理（定期請求のメール・リマインダー等）。DISABLE_WP_CRON にしているのでここで回す
  const runCron = () => fetch(`${server.baseUrl}/wp-cron.php?doing_wp_cron`).catch(() => {});
  cronTimer = setInterval(runCron, CRON_INTERVAL_MS);
  setTimeout(runCron, 30000);
}

/** LAN 共有の窓口を、設定がオンなら立てる（PHP を起動し直すたびに呼ばれる） */
async function startLan() {
  await stopLan();
  if (!lan.readConfig(paths).enabled || !server) {
    lan.writeStatus(paths, { enabled: false });
    return;
  }
  try {
    lanGateway = await lan.startGateway(paths, { phpBaseUrl: server.baseUrl, secret: lanSecret, port: lan.readConfig(paths).port, log });
    // 親機が眠ると他の PC から使えなくなるので、オンの間は眠らせない（画面は暗くなってよい）
    lanBlockerId = powerSaveBlocker.start('prevent-app-suspension');
  } catch (e) {
    log(`LAN 共有を開始できませんでした ${e.message}`);
    lan.writeStatus(paths, { enabled: false, error: String(e.message || e) });
  }
}

async function stopLan() {
  if (lanBlockerId !== null) {
    try {
      powerSaveBlocker.stop(lanBlockerId);
    } catch {}
    lanBlockerId = null;
  }
  if (lanGateway) {
    const g = lanGateway;
    lanGateway = null;
    await g.stop();
    log('LAN 窓口を停止');
  }
}

/** 設定画面（?ktp_dt_page=lan）のオン/オフ */
async function setLanEnabled(on) {
  log(`LAN 共有を${on ? 'オン' : 'オフ'}にする`);
  lan.writeConfig(paths, { enabled: on });
  await startLan();
  if (mainWindow && server) mainWindow.loadURL(`${server.baseUrl}/wp-admin/admin.php?page=ktp-dt-lan`);
}

async function stopPhp() {
  clearInterval(cronTimer);
  await stopLan();
  if (server) {
    const s = server;
    server = null;
    await s.stop();
    log('PHP server stopped');
  }
}

/** 使い捨てトークンを書き出し、自動ログイン用の URL を返す */
function loginUrl(redirectTo = '') {
  const token = crypto.randomBytes(32).toString('hex');
  fs.writeFileSync(paths.tokenFile, token, { mode: 0o600 });
  const back = redirectTo && isAppUrl(redirectTo) ? `&redirect_to=${encodeURIComponent(redirectTo)}` : '';
  return `${server.baseUrl}/?ktp_desktop_login=${token}${back}`;
}

/** ログインが切れたとき（ktp-desktop.php が「入り直し」の合図へ転送する）: 自動ログインし直して元の画面へ */
function relogin(contents, url) {
  let back = '';
  try {
    back = new URL(url).searchParams.get('redirect_to') || '';
  } catch {}
  log('ログインが切れていたので自動ログインし直す');
  contents.loadURL(loginUrl(back));
}

function isAppUrl(url) {
  try {
    const u = new URL(url);
    return server && u.origin === new URL(server.baseUrl).origin;
  } catch {
    return false;
  }
}

async function confirmQuit() {
  log('ヘッダーの「アプリを終了」が押された');
  const r = await dialog.showMessageBox(mainWindow, {
    type: 'question',
    message: 'KantanProEX を終了しますか？',
    detail: '入力した内容はその都度保存されています。',
    buttons: ['終了', 'キャンセル'],
    defaultId: 0,
    cancelId: 1,
  });
  if (r.response === 0) app.quit();
}

// ---- ウィンドウ ----
function webPreferences() {
  return { contextIsolation: true, nodeIntegration: false, sandbox: true, spellcheck: false };
}

function guardNavigation(contents) {
  // アプリ以外のページはウィンドウ内で開かず、普段のブラウザに渡す
  contents.on('will-navigate', (e, url) => {
    // ヘッダーの「アプリを終了」（WordPress のログアウトの代わり。ktp-desktop.php が URL を差し替えている）
    if (isAppUrl(url) && url.includes('ktp_desktop_quit=')) {
      e.preventDefault();
      confirmQuit();
      return;
    }
    if (isAppUrl(url) && url.includes('ktp_desktop_relogin=')) {
      e.preventDefault();
      relogin(contents, url);
      return;
    }
    // LAN 共有のオン/オフ（設定画面のボタン。PHP からは窓口を操作しないので、ここで受ける）
    if (isAppUrl(url) && /[?&]ktp_desktop_lan=(on|off)\b/.test(url)) {
      e.preventDefault();
      setLanEnabled(/ktp_desktop_lan=on/.test(url)).catch((err) => log(`LAN 共有の切り替えに失敗 ${err.message}`));
      return;
    }
    if (!isAppUrl(url)) {
      e.preventDefault();
      if (/^(https?|mailto|tel):/.test(url)) shell.openExternal(url);
    }
  });
  // サーバーからの転送（302）で来た場合はこちら
  contents.on('will-redirect', (e, url) => {
    if (isAppUrl(url) && url.includes('ktp_desktop_relogin=')) {
      e.preventDefault();
      relogin(contents, url);
    }
  });
  contents.setWindowOpenHandler(({ url }) => {
    // 印刷用の空ウィンドウ（about:blank）とアプリ内のページは子ウィンドウで開く
    if (url === 'about:blank' || url === '' || isAppUrl(url)) {
      return { action: 'allow', overrideBrowserWindowOptions: { width: 1100, height: 800, webPreferences: webPreferences() } };
    }
    if (/^(https?|mailto|tel):/.test(url)) shell.openExternal(url);
    return { action: 'deny' };
  });
  // 右クリックのメニュー（Electron は何も出さないので自分で出す）。選択した文字のコピー、入力欄の切り取り・貼り付けなど
  contents.on('context-menu', (_e, params) => {
    const items = [];
    const can = params.editFlags || {};
    if (params.isEditable) {
      items.push(
        { role: 'undo', label: '元に戻す', enabled: !!can.canUndo },
        { role: 'redo', label: 'やり直す', enabled: !!can.canRedo },
        { type: 'separator' },
        { role: 'cut', label: '切り取り', enabled: !!can.canCut },
        { role: 'copy', label: 'コピー', enabled: !!can.canCopy },
        { role: 'paste', label: '貼り付け', enabled: !!can.canPaste },
        { role: 'selectAll', label: 'すべて選択', enabled: !!can.canSelectAll },
      );
    } else {
      if (params.selectionText) items.push({ role: 'copy', label: 'コピー' });
      if (params.linkURL && /^(https?|mailto|tel):/.test(params.linkURL)) {
        items.push({ label: 'リンクのアドレスをコピー', click: () => clipboard.writeText(params.linkURL.replace(/^mailto:/, '')) });
      }
      if (params.mediaType === 'image' && params.srcURL) items.push({ role: 'copyImage', label: '画像をコピー' });
      if (items.length) items.push({ type: 'separator' });
      items.push({ role: 'selectAll', label: 'すべて選択' });
    }
    Menu.buildFromTemplate(items).popup({ window: BrowserWindow.fromWebContents(contents) || undefined });
  });
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 1400,
    height: 900,
    minWidth: 900,
    minHeight: 600,
    title: 'KantanProEX',
    backgroundColor: '#ffffff',
    show: false,
    webPreferences: webPreferences(),
  });
  mainWindow.on('closed', () => {
    mainWindow = null;
  });
  guardNavigation(mainWindow.webContents);
  // 画面（描画プロセス）が落ちたときの理由を記録する（不具合の調査用）
  let lastCrashReload = 0;
  mainWindow.webContents.on('render-process-gone', (_e, d) => {
    log(`画面が落ちました reason=${d.reason} exitCode=${d.exitCode}`);
    // 正常終了以外で落ちたら、1 回だけ自動で読み直す（30 秒以内に続けて落ちたら、何度も繰り返さない）
    if (d.reason !== 'clean-exit' && Date.now() - lastCrashReload > 30000 && mainWindow && !mainWindow.isDestroyed()) {
      lastCrashReload = Date.now();
      setTimeout(() => mainWindow && !mainWindow.isDestroyed() && mainWindow.webContents.reload(), 500);
    }
  });
  mainWindow.webContents.on('unresponsive', () => log('画面が応答しません'));
  mainWindow.webContents.on('did-fail-load', (_e, code, desc, url, isMain) => isMain && log(`画面の読み込み失敗 ${code} ${desc} ${String(url).slice(0, 120)}`));
}

/** 起動中の小さな画面（スプラッシュ）。アプリの画面が描き終わるまで出しておく */
function createSplash() {
  splashWindow = new BrowserWindow({
    width: 420,
    height: 280,
    frame: false,
    resizable: false,
    movable: true,
    center: true,
    backgroundColor: '#ffffff',
    show: false,
    webPreferences: webPreferences(),
  });
  splashWindow.once('ready-to-show', () => splashWindow && splashWindow.show());
  splashWindow.on('closed', () => {
    splashWindow = null;
  });
  splashWindow.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(LOADING_HTML)}`);
}

function closeSplash() {
  if (splashWindow && !splashWindow.isDestroyed()) splashWindow.close();
}

/**
 * アプリの画面を開く。裏で読み込みを終えてから（規約画面などの描画まで済ませてから）表示する。
 * 先に表示すると「白 → 規約なしの画面 → 規約画面」と切り替わって、2回表示されたように見える
 */
function openApp() {
  const wc = mainWindow.webContents;
  let shown = false;
  const show = () => {
    if (shown || !mainWindow) return;
    shown = true;
    mainWindow.show();
    closeSplash();
    // 表示した瞬間の画面の状態を記録する（規約画面などが描き終わってから出せているかの確認用）
    mainWindow.webContents
      .executeJavaScript("JSON.stringify({app: !!document.querySelector('.ktp_plugin_container'), terms: document.querySelectorAll('.ktpwp-terms-modal').length})")
      .then((st) => log(`アプリの画面を表示 ${st}`))
      .catch(() => {});
    // 新しい版のお知らせ（1日1回まで。オフにできる）
    setTimeout(() => checkForUpdates(paths, mainWindow, { log }).catch((e) => log(`アップデート確認失敗 ${e.message}`)), 3000);
  };
  wc.once('did-finish-load', () => setTimeout(show, 400));
  setTimeout(show, 15000); // 読み込みが終わらなくても出す
  mainWindow.loadURL(loginUrl());
}

app.on('web-contents-created', (_e, contents) => {
  if (contents.getType() === 'window' && mainWindow && contents !== mainWindow.webContents) guardNavigation(contents);
});

// ---- メニュー ----
async function doBackup() {
  try {
    const dir = await createBackup(paths);
    const r = await dialog.showMessageBox(mainWindow, { type: 'info', message: 'バックアップを作成しました', detail: dir, buttons: ['OK', 'フォルダを開く'] });
    if (r.response === 1) shell.showItemInFolder(dir);
  } catch (e) {
    dialog.showErrorBox('バックアップできませんでした', String(e.message || e));
  }
}

async function doRestore() {
  fs.mkdirSync(paths.backups, { recursive: true });
  const pick = await dialog.showOpenDialog(mainWindow, {
    title: '復元するバックアップのフォルダを選んでください',
    defaultPath: paths.backups,
    properties: ['openDirectory'],
  });
  if (pick.canceled || !pick.filePaths[0]) return;
  const dir = pick.filePaths[0];
  const ok = await dialog.showMessageBox(mainWindow, {
    type: 'warning',
    message: 'このバックアップの内容に戻しますか？',
    detail: `${path.basename(dir)}\n\n今のデータは、戻す前に自動でバックアップします。`,
    buttons: ['戻す', 'やめる'],
    defaultId: 1,
    cancelId: 1,
  });
  if (ok.response !== 0) return;
  try {
    await createBackup(paths, { reason: 'before-restore' });
    await stopPhp();
    restoreBackup(paths, dir);
    await startPhp();
    mainWindow.loadURL(loginUrl());
    dialog.showMessageBox(mainWindow, { type: 'info', message: 'バックアップから戻しました' });
  } catch (e) {
    dialog.showErrorBox('復元できませんでした', String(e.message || e));
    if (!server) await startPhp().catch(() => {});
  }
}

// 取り込みの種類ごとの文言と、使う取り込みスクリプト
const IMPORTS = {
  kantanbiz: {
    name: 'KantanBiz',
    script: 'import-kantanbiz.php',
    pickTitle: 'KantanBiz から書き出した ZIP ファイルを選んでください',
    filterName: 'KantanBiz の移行用ファイル',
    reason: 'before-kantanbiz-import',
  },
  wordpress: {
    name: 'WordPress 版',
    script: 'import-wordpress.php',
    pickTitle: 'KantanProEX（WP）の「デスクトップ版へ移行」で書き出した ZIP ファイルを選んでください',
    filterName: 'WordPress 版の移行用ファイル',
    reason: 'before-wordpress-import',
  },
};

// WordPress 版の取り込み結果（imported: client=7 order=22 ...）を、画面に出す文に直す
function importSummary(kind, out) {
  const body = out.replace(/^imported:\s*/, '');
  if (kind !== 'wordpress') return body;
  const n = Object.fromEntries(body.split(/\s+/).map((kv) => kv.split('=')).filter((a) => a.length === 2));
  const parts = [['client', '顧客', '件'], ['order', '受注', '件'], ['service', 'サービス', '件'], ['supplier', '協力会社', '件'], ['contract', '定期契約', '件'], ['staff', 'スタッフ', '人'], ['files', 'ファイル', '件']]
    .filter(([k]) => n[k] !== undefined)
    .map(([k, label, unit]) => `${label} ${n[k]}${unit}`);
  const notes = [];
  if (n.staff && Number(n.staff) > 0) notes.push('スタッフは「KantanProEX 設定」→「LAN 共有」でパスワードを決めると使えるようになります。');
  if (n.japanpost_key_needed) notes.push('日本郵便 API のシークレットキーは移行されません。設定画面で入れ直してから有効にしてください。');
  return parts.join('・') + (notes.length ? `\n\n${notes.join('\n')}` : '');
}

// 移行用 ZIP（KantanBiz・WordPress 版）を取り込む（DT の業務データは入れ替わる）。
// filePath を渡すと、選択・確認のダイアログを出さずに実行する（自動テスト用）
async function doImport(kind, filePath) {
  const src = IMPORTS[kind];
  let file = filePath;
  if (!file) {
    const pick = await dialog.showOpenDialog(mainWindow, {
      title: src.pickTitle,
      defaultPath: app.getPath('downloads'),
      properties: ['openFile'],
      filters: [{ name: src.filterName, extensions: ['zip'] }],
    });
    if (pick.canceled || !pick.filePaths[0]) return;
    file = pick.filePaths[0];
    const ok = await dialog.showMessageBox(mainWindow, {
      type: 'warning',
      message: `${src.name}のデータを取り込みますか？`,
      detail: `${path.basename(file)}\n\nいまの顧客・案件・契約などのデータは、取り込んだ内容に入れ替わります。\n入れ替える前に、いまのデータを自動でバックアップします。`,
      buttons: ['取り込む', 'やめる'],
      defaultId: 1,
      cancelId: 1,
    });
    if (ok.response !== 0) return;
  }
  try {
    const backup = await createBackup(paths, { reason: src.reason });
    log(`${src.name} 取り込み開始 ${file}（バックアップ ${backup}）`);
    // 取り込み中は画面側のアクセスで DB が競合しないよう、PHP の内蔵サーバーを止める
    await stopPhp();
    let out;
    try {
      out = await wpCli(paths, ['eval-file', path.join(paths.site, 'ktp-desktop', src.script), file, '--user=kantanpro'], { KTP_DESKTOP_HOST: '127.0.0.1' });
    } finally {
      await startPhp();
    }
    log(`${src.name} 取り込み完了 ${out}`);
    // 入り直しの画面が開き終わるまで待つ（続けて別の画面を開くと、使い捨てのログイン用トークンが食い違う）
    await mainWindow.loadURL(loginUrl()).catch(() => {});
    if (!filePath) {
      const needSmtp = /smtp_password_needed=1/.test(out);
      dialog.showMessageBox(mainWindow, {
        type: 'info',
        message: `${src.name}のデータを取り込みました`,
        detail: `${importSummary(kind, out)}${needSmtp ? '\n\nメール送信（SMTP）のパスワードは移行されません。設定画面で入れ直してください。' : ''}`,
      });
    }
  } catch (e) {
    log(`${src.name} 取り込み失敗 ${e.message}`);
    // wp-cli のエラー（Error: ...）があればそれを、なければ 1 行目を見せる
    const msg = (String(e.message || e).match(/Error: (.+)/) || [])[1] || String(e.message || e).split('\n')[0];
    if (!filePath) dialog.showErrorBox('取り込めませんでした', `${msg}\n\nデータは取り込み前のままです。記録: ${path.join(paths.logs, 'app.log')}`);
    if (!server) await startPhp().catch(() => {});
  }
}

const sleepMs = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * 自動テスト用（KTP_TEST_VISIT）: Playwright などをつながずに、アプリ自身が画面を順に開き、
 * 表示できたか・落ちたかを app.log に、画面を KTP_TEST_SHOT_DIR（なければ logs/shots）に残す。
 * 利用規約の同意画面が出ていれば先に同意する。
 */
async function runTestVisits(urls) {
  const dir = process.env.KTP_TEST_SHOT_DIR || path.join(paths.logs, 'shots');
  fs.mkdirSync(dir, { recursive: true });
  const wc = () => mainWindow.webContents;
  const js = (code) => wc().executeJavaScript(code, true);
  const agree = async () => {
    const had = await js(`(function(){var c=document.querySelector('#ktpwp-terms-dialog input[type=checkbox]');if(!c)return false;c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));var b=[].slice.call(document.querySelectorAll('#ktpwp-terms-dialog button')).find(function(x){return x.textContent.indexOf('利用開始する')>=0});if(b)b.click();return true;})()`).catch(() => false);
    if (had) { log('テスト表示 利用規約に同意'); await sleepMs(3000); }
  };
  await agree();
  for (const [i, u] of urls.entries()) {
    const name = `${String(i + 1).padStart(2, '0')}`;
    try {
      await mainWindow.loadURL(`${server.baseUrl}${u}`).catch((e) => { if (!/ERR_ABORTED/.test(String(e))) throw e; });
      await sleepMs(3500);
      if (wc().isCrashed()) {
        log(`テスト表示 NG ${u} 画面が落ちた`);
        wc().reload();
        await sleepMs(4000);
        continue;
      }
      await agree();
      const text = await js('document.body ? document.body.innerText.replace(/\\s+/g, " ").slice(0, 120) : ""');
      const phpErr = await js('/Fatal error|Parse error|WordPress database error|Warning:/.test(document.body ? document.body.innerText : "")');
      const img = await wc().capturePage();
      fs.writeFileSync(path.join(dir, `${name}.png`), img.toPNG());
      log(`テスト表示 ${phpErr ? 'NG(PHPエラー)' : 'OK'} ${u} ${text}`);
    } catch (e) {
      log(`テスト表示 NG ${u} ${String(e.message || e).slice(0, 200)}`);
    }
  }
  log('テスト表示 完了');
}

function buildMenu() {
  const isDev = !app.isPackaged;
  const template = [
    {
      label: 'KantanProEX',
      submenu: [
        { role: 'about', label: 'KantanProEX について' },
        { label: 'アップデートを確認…', click: () => checkForUpdates(paths, mainWindow, { manual: true, log }) },
        {
          label: '起動時にアップデートを確認する',
          type: 'checkbox',
          checked: isAutoCheckEnabled(paths),
          click: (item) => setAutoCheckEnabled(paths, item.checked),
        },
        { type: 'separator' },
        ...(process.platform === 'darwin'
          ? [
              { role: 'hide', label: 'KantanProEX を隠す' },
              { role: 'hideOthers', label: 'ほかを隠す' },
              { role: 'unhide', label: 'すべてを表示' },
              { type: 'separator' },
            ]
          : []),
        { role: 'quit', label: 'KantanProEX を終了' },
      ],
    },
    {
      label: 'ファイル',
      submenu: [
        { label: 'バックアップを作成', accelerator: 'CmdOrCtrl+Shift+B', click: doBackup },
        { label: 'バックアップから復元…', click: doRestore },
        {
          label: '各種取り込み',
          submenu: [
            { label: 'WordPress 版から取り込む…', click: () => doImport('wordpress') },
            { label: 'KantanBiz から取り込む…', click: () => doImport('kantanbiz') },
            { label: 'FileMaker 版データ取り込み…', click: () => server && mainWindow && mainWindow.loadURL(`${server.baseUrl}/wp-admin/admin.php?page=ktp-fm-import`) },
          ],
        },
        { label: 'LAN 共有（事務所の他の PC から使う）…', click: () => server && mainWindow && mainWindow.loadURL(`${server.baseUrl}/wp-admin/admin.php?page=ktp-dt-lan`) },
        { label: 'サイトからの受注（ホームページの問い合わせ・注文）…', click: () => server && mainWindow && mainWindow.loadURL(`${server.baseUrl}/wp-admin/admin.php?page=ktp-dt-intake`) },
        { type: 'separator' },
        { label: 'バックアップのフォルダを開く', click: () => { fs.mkdirSync(paths.backups, { recursive: true }); shell.openPath(paths.backups); } },
        { label: 'データのフォルダを開く', click: () => shell.openPath(paths.userData) },
        { type: 'separator' },
        { role: 'close', label: 'ウィンドウを閉じる' },
      ],
    },
    {
      label: '編集',
      submenu: [
        { role: 'undo', label: '取り消す' },
        { role: 'redo', label: 'やり直す' },
        { type: 'separator' },
        { role: 'cut', label: 'カット' },
        { role: 'copy', label: 'コピー' },
        { role: 'paste', label: 'ペースト' },
        { role: 'selectAll', label: 'すべてを選択' },
      ],
    },
    {
      label: '表示',
      submenu: [
        { label: '再読み込み', accelerator: 'CmdOrCtrl+R', click: () => mainWindow && mainWindow.webContents.reload() },
        { label: '最初の画面に戻る', accelerator: 'CmdOrCtrl+Shift+H', click: () => server && mainWindow && mainWindow.loadURL(`${server.baseUrl}/`) },
        { type: 'separator' },
        { role: 'resetZoom', label: '実際のサイズ' },
        { role: 'zoomIn', label: '拡大' },
        { role: 'zoomOut', label: '縮小' },
        { type: 'separator' },
        { role: 'togglefullscreen', label: 'フルスクリーン' },
        ...(isDev ? [{ type: 'separator' }, { role: 'toggleDevTools', label: '開発者ツール' }] : []),
      ],
    },
    { role: 'windowMenu', label: 'ウインドウ' },
  ];
  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

// ---- 起動 ----
async function boot() {
  paths = getPaths();
  buildMenu();
  createSplash();
  createWindow();
  try {
    setLoadingMessage('ファイルを準備しています');
    const replaced = syncSite(paths);
    if (replaced) log('site/ を同梱の版に入れ替えました');
    writeWpConfig(paths);
    if (await setupIfNeeded(paths, setLoadingMessage)) log('初回セットアップ完了');

    setLoadingMessage('起動しています');
    await startPhp();
    autoBackupIfDue(paths).then((d) => d && log(`自動バックアップ ${d}`)).catch((e) => log(`自動バックアップ失敗 ${e.message}`));
    openApp();
    // 自動テスト用: 取り込みを起動後に実行する（開発時、またはテスト用データ指定時のみ）
    const testMode = !app.isPackaged || process.env.KTP_TEST_USER_DATA;
    if (testMode && (process.env.KTP_TEST_IMPORT_FILE || process.env.KTP_TEST_IMPORT_WP_FILE || process.env.KTP_TEST_VISIT)) {
      setTimeout(async () => {
        if (process.env.KTP_TEST_IMPORT_FILE) await doImport('kantanbiz', process.env.KTP_TEST_IMPORT_FILE);
        if (process.env.KTP_TEST_IMPORT_WP_FILE) await doImport('wordpress', process.env.KTP_TEST_IMPORT_WP_FILE);
        if (process.env.KTP_TEST_VISIT) await runTestVisits(process.env.KTP_TEST_VISIT.split(',').filter(Boolean));
      }, 3000);
    }
  } catch (e) {
    log(`起動失敗 ${e.stack || e}`);
    closeSplash();
    dialog.showErrorBox('KantanProEX を起動できませんでした', `${e.message || e}\n\n記録: ${path.join(paths.logs, 'app.log')}`);
    app.quit();
  }
}

app.whenReady().then(() => {
  // 背景は常に白。Mac がダークモードでも画面をダーク表示にしない（プラグインのダーク用 CSS も効かせない）
  nativeTheme.themeSource = 'light';
  if (!app.isPackaged && process.platform === 'darwin' && ICON_DATA_URL) {
    app.dock.setIcon(path.join(__dirname, 'assets', 'icon', 'icon-1024.png'));
  }

  // 画面から外部への読み込みは一切させない（127.0.0.1 の自分自身だけ）。
  // プラグインや WordPress が外部の画像・スクリプトを読もうとしても通信が出ないことを、ここで保証する
  session.defaultSession.webRequest.onBeforeRequest({ urls: ['http://*/*', 'https://*/*', 'ws://*/*', 'wss://*/*'] }, (details, cb) => {
    let host = '';
    try {
      host = new URL(details.url).hostname;
    } catch {}
    const local = host === '127.0.0.1' || host === 'localhost' || host === '[::1]';
    if (!local) log(`外部への読み込みを止めました ${details.url.slice(0, 200)}`);
    cb({ cancel: !local });
  });

  // ダウンロード（CSV・PDF など）は保存先を聞く
  session.defaultSession.on('will-download', (_e, item) => {
    log(`download ${item.getFilename()}`);
    if (!app.isPackaged && process.env.KTP_TEST_DOWNLOAD_DIR) {
      // 自動テスト用（開発時のみ）: ダイアログを出さずに保存する
      item.setSavePath(path.join(process.env.KTP_TEST_DOWNLOAD_DIR, item.getFilename()));
      return;
    }
    item.setSaveDialogOptions({ defaultPath: path.join(app.getPath('downloads'), item.getFilename()) });
  });
  boot();
});

app.on('child-process-gone', (_e, d) => log(`子プロセスが終了しました type=${d.type} reason=${d.reason} exitCode=${d.exitCode}`));

app.on('activate', () => {
  if (!mainWindow && server) {
    createWindow();
    openApp();
  }
});

// ウィンドウを閉じたら終了する（PHP を残さない）
app.on('window-all-closed', () => app.quit());

app.on('before-quit', (e) => {
  if (quitting || !server) return;
  e.preventDefault();
  quitting = true;
  stopPhp().finally(() => app.quit());
});
