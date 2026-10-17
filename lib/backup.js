// バックアップと復元。
// バックアップ = 書類フォルダの「KantanProEX バックアップ/日時/」に DB（kantanpro.sqlite）と添付（uploads/）を置く。
// DB は使用中でも一貫した状態で写せるよう、SQLite の VACUUM INTO で作る。
const fs = require('node:fs');
const path = require('node:path');
const { execFile } = require('node:child_process');

const KEEP_GENERATIONS = 30;

function stamp(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}_${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
}

function vacuumInto(paths, dest) {
  const code = 'try { $d = new PDO("sqlite:" . $argv[1]); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->exec("VACUUM INTO " . $d->quote($argv[2])); echo "ok"; } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }';
  return new Promise((resolve, reject) => {
    execFile(paths.php, ['-r', code, paths.db, dest], { timeout: 120000 }, (err, stdout, stderr) => {
      if (err || stdout.trim() !== 'ok') reject(new Error(`データベースを書き出せませんでした: ${stderr || err?.message}`));
      else resolve();
    });
  });
}

function listBackups(paths) {
  if (!fs.existsSync(paths.backups)) return [];
  return fs
    .readdirSync(paths.backups)
    .filter((n) => /^\d{4}-\d{2}-\d{2}_\d{6}$/.test(n) && fs.existsSync(path.join(paths.backups, n, 'kantanpro.sqlite')))
    .sort()
    .reverse();
}

async function createBackup(paths, { reason = 'manual' } = {}) {
  if (!fs.existsSync(paths.db)) throw new Error('まだデータがありません');
  const dir = path.join(paths.backups, stamp());
  fs.mkdirSync(dir, { recursive: true });
  try {
    await vacuumInto(paths, path.join(dir, 'kantanpro.sqlite'));
    const uploads = path.join(paths.site, 'wp-content', 'uploads');
    if (fs.existsSync(uploads)) fs.cpSync(uploads, path.join(dir, 'uploads'), { recursive: true });
    fs.writeFileSync(path.join(dir, 'backup.json'), JSON.stringify({ createdAt: new Date().toISOString(), reason }, null, 2));
  } catch (e) {
    fs.rmSync(dir, { recursive: true, force: true });
    throw e;
  }
  for (const old of listBackups(paths).slice(KEEP_GENERATIONS)) {
    fs.rmSync(path.join(paths.backups, old), { recursive: true, force: true });
  }
  return dir;
}

/** 最新のバックアップが24時間より古ければ作る（起動時の自動バックアップ） */
async function autoBackupIfDue(paths) {
  const latest = listBackups(paths)[0];
  if (latest) {
    const t = fs.statSync(path.join(paths.backups, latest)).mtimeMs;
    if (Date.now() - t < 24 * 3600 * 1000) return null;
  }
  if (!fs.existsSync(paths.db)) return null;
  return createBackup(paths, { reason: 'auto' });
}

/** 復元（PHP サーバーを止めてから呼ぶこと）。復元前の状態も念のためバックアップしておく */
function restoreBackup(paths, dir) {
  const src = path.join(dir, 'kantanpro.sqlite');
  if (!fs.existsSync(src)) throw new Error('選んだフォルダにバックアップ（kantanpro.sqlite）がありません');
  for (const f of fs.readdirSync(paths.data)) {
    // WAL / ジャーナルが残っていると古い内容が混ざるので消す
    if (/^kantanpro\.sqlite-(wal|shm|journal)$/.test(f)) fs.rmSync(path.join(paths.data, f), { force: true });
  }
  fs.copyFileSync(src, paths.db);
  const uploadsSrc = path.join(dir, 'uploads');
  const uploads = path.join(paths.site, 'wp-content', 'uploads');
  if (fs.existsSync(uploadsSrc)) {
    fs.rmSync(uploads, { recursive: true, force: true });
    fs.cpSync(uploadsSrc, uploads, { recursive: true });
  }
}

module.exports = { createBackup, autoBackupIfDue, restoreBackup, listBackups };
