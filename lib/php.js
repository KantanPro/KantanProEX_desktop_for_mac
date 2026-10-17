// 同梱 PHP の実行（wp-cli・内蔵サーバー）
const { spawn, execFile } = require('node:child_process');
const net = require('node:net');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const PHP_INI = ['-d', 'memory_limit=512M', '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=64M', '-d', 'max_execution_time=300', '-d', 'display_errors=0', '-d', 'date.timezone=Asia/Tokyo'];

/** wp-cli を実行して標準出力を返す（失敗したら例外） */
function wpCli(paths, args, extraEnv = {}) {
  const phar = path.join(paths.site, 'ktp-desktop', 'wp-cli.phar');
  return new Promise((resolve, reject) => {
    execFile(
      paths.php,
      [...PHP_INI, '-d', 'memory_limit=-1', phar, `--path=${paths.site}`, ...args],
      { env: { ...process.env, ...extraEnv }, maxBuffer: 20 * 1024 * 1024, timeout: 300000 },
      (err, stdout, stderr) => {
        if (err) {
          err.message += `\nwp ${args.join(' ')}\n${stderr}`;
          reject(err);
        } else {
          resolve(stdout.trim());
        }
      },
    );
  });
}

/** 空いているポートを探す（希望ポートが使用中なら OS に任せる） */
function findPort(preferred) {
  const tryPort = (port) =>
    new Promise((resolve) => {
      const srv = net.createServer();
      srv.once('error', () => resolve(null));
      srv.listen(port, '127.0.0.1', () => {
        const p = srv.address().port;
        srv.close(() => resolve(p));
      });
    });
  return tryPort(preferred).then((p) => p || tryPort(0));
}

/** 応答が返るまで待つ */
function waitForServer(url, timeoutMs = 20000) {
  const start = Date.now();
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const req = http.get(url, (res) => {
        res.resume();
        resolve();
      });
      req.on('error', () => {
        if (Date.now() - start > timeoutMs) reject(new Error('PHP サーバーが起動しませんでした'));
        else setTimeout(attempt, 200);
      });
      req.setTimeout(5000, () => req.destroy());
    };
    attempt();
  });
}

/** 内蔵サーバーを起動する。戻り値の stop() で子プロセスごと止める */
async function startServer(paths, port) {
  fs.mkdirSync(paths.logs, { recursive: true });
  const log = fs.openSync(path.join(paths.logs, 'php-server.log'), 'a');
  const child = spawn(
    paths.php,
    [...PHP_INI, '-d', `error_log=${path.join(paths.logs, 'php-error.log')}`, '-d', 'log_errors=1', '-S', `127.0.0.1:${port}`, '-t', paths.site, paths.router],
    {
      // ワーカーは fork された子プロセスなので、プロセスグループごと止められるよう detached にする
      detached: true,
      stdio: ['ignore', log, log],
      env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' },
    },
  );
  let exited = false;
  child.on('exit', () => {
    exited = true;
  });
  const baseUrl = `http://127.0.0.1:${port}`;
  await waitForServer(`${baseUrl}/wp-login.php`);

  const stop = () =>
    new Promise((resolve) => {
      if (exited) return resolve();
      child.once('exit', () => resolve());
      try {
        process.kill(-child.pid, 'SIGTERM');
      } catch {
        resolve();
      }
      setTimeout(() => {
        try {
          process.kill(-child.pid, 'SIGKILL');
        } catch {}
        resolve();
      }, 3000);
    });
  return { baseUrl, stop, child };
}

module.exports = { wpCli, findPort, startServer, PHP_INI };
