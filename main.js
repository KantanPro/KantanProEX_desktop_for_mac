// KantanProEX デスクトップ版。
// 同梱の PHP で WordPress + KantanProEX を 127.0.0.1 だけで動かし、このウィンドウで開く。
const path = require('node:path');
const fs = require('node:fs');
const crypto = require('node:crypto');
const { app, BrowserWindow, Menu, dialog, shell, session, nativeTheme } = require('electron');

// 開発中はインストール版とデータを分ける
if (!app.isPackaged) {
  app.setPath('userData', path.join(app.getPath('appData'), 'KantanProEX-dev'));
  // 自動テスト用（開発時のみ）: KTP_DEBUG_PORT=9555 npm start で Playwright から接続できる
  if (process.env.KTP_DEBUG_PORT) app.commandLine.appendSwitch('remote-debugging-port', process.env.KTP_DEBUG_PORT);
}
app.setName('KantanProEX');

const { getPaths } = require('./lib/paths');
const { findPort, startServer } = require('./lib/php');
const { syncSite, writeWpConfig, setupIfNeeded } = require('./lib/site');
const { createBackup, autoBackupIfDue, restoreBackup } = require('./lib/backup');

const PREFERRED_PORT = 47321;
const CRON_INTERVAL_MS = 5 * 60 * 1000;

let paths;
let server = null; // { baseUrl, stop }
let mainWindow = null;
let splashWindow = null; // 起動中の小さな画面
let cronTimer = null;
let quitting = false;

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
// 起動中の画面に出すアイコン（data: のページなので画像は埋め込む）
const ICON_DATA_URL = (() => {
  try {
    return `data:image/png;base64,${fs.readFileSync(path.join(__dirname, 'assets', 'icon', 'icon-256.png')).toString('base64')}`;
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
  server = await startServer(paths, port);
  log(`PHP server ${server.baseUrl}`);
  clearInterval(cronTimer);
  // WordPress の定期処理（定期請求のメール・リマインダー等）。DISABLE_WP_CRON にしているのでここで回す
  const runCron = () => fetch(`${server.baseUrl}/wp-cron.php?doing_wp_cron`).catch(() => {});
  cronTimer = setInterval(runCron, CRON_INTERVAL_MS);
  setTimeout(runCron, 30000);
}

async function stopPhp() {
  clearInterval(cronTimer);
  if (server) {
    const s = server;
    server = null;
    await s.stop();
    log('PHP server stopped');
  }
}

/** 使い捨てトークンを書き出し、自動ログイン用の URL を返す */
function loginUrl() {
  const token = crypto.randomBytes(32).toString('hex');
  fs.writeFileSync(paths.tokenFile, token, { mode: 0o600 });
  return `${server.baseUrl}/?ktp_desktop_login=${token}`;
}

function isAppUrl(url) {
  try {
    const u = new URL(url);
    return server && u.origin === new URL(server.baseUrl).origin;
  } catch {
    return false;
  }
}

// ---- ウィンドウ ----
function webPreferences() {
  return { contextIsolation: true, nodeIntegration: false, sandbox: true, spellcheck: false };
}

function guardNavigation(contents) {
  // アプリ以外のページはウィンドウ内で開かず、普段のブラウザに渡す
  contents.on('will-navigate', (e, url) => {
    if (!isAppUrl(url)) {
      e.preventDefault();
      if (/^(https?|mailto|tel):/.test(url)) shell.openExternal(url);
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

function buildMenu() {
  const isDev = !app.isPackaged;
  const template = [
    {
      label: 'KantanProEX',
      submenu: [
        { role: 'about', label: 'KantanProEX について' },
        { type: 'separator' },
        { role: 'hide', label: 'KantanProEX を隠す' },
        { role: 'hideOthers', label: 'ほかを隠す' },
        { role: 'unhide', label: 'すべてを表示' },
        { type: 'separator' },
        { role: 'quit', label: 'KantanProEX を終了' },
      ],
    },
    {
      label: 'ファイル',
      submenu: [
        { label: 'バックアップを作成', accelerator: 'CmdOrCtrl+Shift+B', click: doBackup },
        { label: 'バックアップから復元…', click: doRestore },
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
