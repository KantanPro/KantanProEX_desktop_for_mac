// アプリが使う場所をまとめる。
// 同梱物（PHP・WordPress 一式）は app.asar の外（Resources/）に置く。PHP から読むため。
const path = require('node:path');
const os = require('node:os');
const { app } = require('electron');

function resourcesRoot() {
  return app.isPackaged ? process.resourcesPath : path.join(__dirname, '..');
}

/** 書類フォルダ。Linux は言語によって名前が変わる（日本語なら「ドキュメント」）ので XDG の設定に従う。
 *  Mac・Windows は従来どおり（既存の利用者のバックアップの場所を変えない） */
function documentsDir() {
  if (process.platform === 'linux') {
    try {
      return app.getPath('documents');
    } catch {}
  }
  return path.join(os.homedir(), 'Documents');
}

function getPaths() {
  const res = resourcesRoot();
  const userData = app.getPath('userData');
  return {
    // Windows の PHP は php.exe（開発時はリポジトリの bin-win/、配布版は Resources の bin/）
    // Linux は開発時はリポジトリの bin-linux/、配布版は Resources の bin/
    php:
      process.platform === 'win32'
        ? path.join(res, app.isPackaged ? 'bin' : 'bin-win', 'php.exe')
        : path.join(res, process.platform === 'linux' && !app.isPackaged ? 'bin-linux' : 'bin', 'php'),
    router: path.join(res, 'php', 'router.php'),
    template: path.join(res, 'site-template'),
    userData,
    site: path.join(userData, 'site'),
    data: path.join(userData, 'data'),
    db: path.join(userData, 'data', 'kantanpro.sqlite'),
    logs: path.join(userData, 'logs'),
    tokenFile: path.join(userData, 'login-token'),
    // テスト用データ指定時は、バックアップもその中に作る（本人のバックアップフォルダに触らない）
    backups: process.env.KTP_TEST_USER_DATA
      ? path.join(userData, 'backups')
      : path.join(documentsDir(), app.isPackaged ? 'KantanProEX バックアップ' : 'KantanProEX バックアップ（開発用）'),
  };
}

module.exports = { getPaths };
