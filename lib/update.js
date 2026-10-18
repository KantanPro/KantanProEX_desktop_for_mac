// アップデートのお知らせ。
//
// 公開リポジトリの update.json（最新版の番号・内容・ダウンロード先だけ）を読み、新しい版があれば知らせる。
// dmg 本体は kantanpro.com の WooCommerce で購入者だけがダウンロードする（GitHub には置かない）。
// 自動での入れ替えはしない（署名がないと Mac・Windows とも自動更新できないため）。
// Mac と Windows は版が別々に進められる: update.json の version / url は Mac 用、version_win / url_win は Windows 用
// （Windows 用が無ければ共通の version / url を使う）。
// 通信はこの1件だけ。何も送らない。ネットにつながっていなければ黙って何もしない。
const fs = require('node:fs');
const path = require('node:path');
const { app, dialog, shell } = require('electron');

const UPDATE_URL = 'https://raw.githubusercontent.com/KantanPro/KantanProEX_desktop_for_mac/main/update.json';
const DEFAULT_DOWNLOAD_URL = 'https://www.kantanpro.com/wc-account/downloads/';
const CHECK_INTERVAL_MS = 24 * 3600 * 1000;

function stateFile(paths) {
  return path.join(paths.userData, 'update-state.json');
}

function readState(paths) {
  try {
    return { enabled: true, ...JSON.parse(fs.readFileSync(stateFile(paths), 'utf8')) };
  } catch {
    return { enabled: true };
  }
}

function writeState(paths, patch) {
  const next = { ...readState(paths), ...patch };
  fs.mkdirSync(paths.userData, { recursive: true });
  fs.writeFileSync(stateFile(paths), JSON.stringify(next, null, 2));
  return next;
}

/** "1.2.10" と "1.2.9" のような版番号を比べる（a が新しければ正） */
function compareVersions(a, b) {
  const pa = String(a).split('.').map((n) => parseInt(n, 10) || 0);
  const pb = String(b).split('.').map((n) => parseInt(n, 10) || 0);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (pa[i] || 0) - (pb[i] || 0);
    if (d) return d;
  }
  return 0;
}

/** この OS 向けの最新版の番号・ダウンロード先を取り出す */
function forThisPlatform(info) {
  if (process.platform === 'win32') {
    return { ...info, version: info.version_win || info.version, url: info.url_win || info.url };
  }
  return info;
}

async function fetchLatest() {
  const res = await fetch(UPDATE_URL, { signal: AbortSignal.timeout(8000), cache: 'no-store' });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const info = await res.json();
  if (!info || typeof info.version !== 'string') throw new Error('update.json の形式が違います');
  return forThisPlatform(info);
}

/**
 * 更新を確認する。
 * manual=true（メニューから）なら、最新のときや確認できなかったときも知らせる。
 * manual=false（起動時）なら、1日1回まで・オフにしていれば何もしない・知らせない設定の版は飛ばす。
 */
async function checkForUpdates(paths, win, { manual = false, log = () => {} } = {}) {
  const state = readState(paths);
  if (!manual) {
    if (!state.enabled) return;
    if (state.lastCheckedAt && Date.now() - Date.parse(state.lastCheckedAt) < CHECK_INTERVAL_MS) return;
  }
  let info;
  try {
    info = await fetchLatest();
    writeState(paths, { lastCheckedAt: new Date().toISOString() });
  } catch (e) {
    log(`アップデート確認できず ${e.message}`);
    if (manual) {
      dialog.showMessageBox(win, { type: 'info', message: 'アップデートを確認できませんでした', detail: 'インターネットにつながっているか確かめてください。' });
    }
    return;
  }
  const current = app.getVersion();
  const newer = compareVersions(info.version, current) > 0;
  log(`アップデート確認 最新=${info.version} 今=${current}`);
  if (!newer) {
    if (manual) dialog.showMessageBox(win, { type: 'info', message: 'お使いの KantanProEX は最新です', detail: `バージョン ${current}` });
    return;
  }
  if (!manual && state.skippedVersion === info.version) return;

  const r = await dialog.showMessageBox(win, {
    type: 'info',
    message: `新しい KantanProEX（${info.version}）があります`,
    detail: `今のバージョン: ${current}\n\n${info.notes || ''}\n\nダウンロードページから新しい版を入れると更新できます。データはそのまま引き継がれます。`.trim(),
    buttons: ['ダウンロードページを開く', 'あとで', 'この版は知らせない'],
    defaultId: 0,
    cancelId: 1,
  });
  if (r.response === 0) shell.openExternal(info.url || DEFAULT_DOWNLOAD_URL);
  if (r.response === 2) writeState(paths, { skippedVersion: info.version });
}

function isAutoCheckEnabled(paths) {
  return readState(paths).enabled !== false;
}

function setAutoCheckEnabled(paths, enabled) {
  writeState(paths, { enabled: !!enabled });
}

module.exports = { checkForUpdates, isAutoCheckEnabled, setAutoCheckEnabled, compareVersions, UPDATE_URL };
