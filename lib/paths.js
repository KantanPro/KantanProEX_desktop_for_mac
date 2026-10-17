// アプリが使う場所をまとめる。
// 同梱物（PHP・WordPress 一式）は app.asar の外（Resources/）に置く。PHP から読むため。
const path = require('node:path');
const os = require('node:os');
const { app } = require('electron');

function resourcesRoot() {
  return app.isPackaged ? process.resourcesPath : path.join(__dirname, '..');
}

function getPaths() {
  const res = resourcesRoot();
  const userData = app.getPath('userData');
  return {
    php: path.join(res, 'bin', 'php'),
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
      : path.join(os.homedir(), 'Documents', app.isPackaged ? 'KantanProEX バックアップ' : 'KantanProEX バックアップ（開発用）'),
  };
}

module.exports = { getPaths };
