// 音声操作（KoeType などの音声入力アプリや Mac のショートカットから「今日の予定」などを送る）の窓口。段階1: 読み上げと画面を開く。
//
// 既定はオフ。オンのときだけ 127.0.0.1:47331（固定）で待ち受ける。LAN には出さない。
//   ショートカット ──POST /voice──▶ 窓口（127.0.0.1:47331）──x-ktp-voice──▶ PHP（/?ktp_dt_voice=1）
// 窓口がやること:
//   - 受けるのは POST /voice だけ。Host が 127.0.0.1:47331（または localhost:47331）以外、Origin 付き、
//     Content-Type が application/json 以外、X-KTP-Voice: 1 なしは拒否する。
//     ブラウザのページからは Origin が付くか、独自ヘッダーのため事前確認（OPTIONS）が要る。CORS の応答は一切返さないので叩けない
//   - 起動ごとの合言葉（x-ktp-voice）を付けて PHP に渡す。PHP は合言葉が合う要求だけを音声扱いにする
//     （wp-config.php が受け取る。site.js の writeWpConfig）
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');

const VOICE_PORT = 47331;
// 動作確認用: テスト用データ場所（KTP_TEST_USER_DATA）で動かすときだけ、KTP_VOICE_PORT でポートを変えられる。
// 本人が使っているアプリが 47331 を使っているため。通常の起動では環境変数があっても無視する（宛先が固定のため）
function voicePort() {
  const p = Number(process.env.KTP_VOICE_PORT);
  return process.env.KTP_TEST_USER_DATA && Number.isInteger(p) && p >= 1024 && p <= 65535 ? p : VOICE_PORT;
}
const MAX_BODY = 8 * 1024; // 話した文はごく短い。これを超える本文は受け取らない
const TIMEOUT_MS = 15000;

function configFile(paths) {
  return path.join(paths.userData, 'voice.json');
}

function readConfig(paths) {
  try {
    return { enabled: false, ...JSON.parse(fs.readFileSync(configFile(paths), 'utf8')) };
  } catch {
    return { enabled: false };
  }
}

function writeConfig(paths, patch) {
  const next = { ...readConfig(paths), ...patch };
  fs.mkdirSync(paths.userData, { recursive: true });
  fs.writeFileSync(configFile(paths), JSON.stringify(next, null, 2));
  return next;
}

/** PHP（mu-plugin の設定画面）が読む状態ファイル */
function statusFile(paths) {
  return path.join(paths.userData, 'voice-status.json');
}

function writeStatus(paths, status) {
  try {
    fs.writeFileSync(statusFile(paths), JSON.stringify(status, null, 2));
  } catch {}
}

/** 窓口を通してよい要求か（通さないなら理由を返す） */
function rejectReason(req, port = voicePort()) {
  const h = req.headers;
  const u = String(req.url || '');
  if (req.method !== 'POST') return 'method';
  if (u !== '/voice') return 'path';
  const host = String(h.host || '').toLowerCase();
  if (host !== `127.0.0.1:${port}` && host !== `localhost:${port}`) return 'host';
  if (h.origin !== undefined) return 'origin';
  const type = String(h['content-type'] || '').split(';')[0].trim().toLowerCase();
  if (type !== 'application/json') return 'content-type';
  if (h['x-ktp-voice'] !== '1') return 'no-header';
  return null;
}

/**
 * PHP の返事に付く open（画面を開く先）として受け付けてよいか。
 * 「/ で始まり、// で始まらず、スキームも含まず、制御文字・バックスラッシュもない」相対パスだけ。戻り値は文字列か null
 */
function safeOpenPath(v) {
  if (typeof v !== 'string' || v.length === 0 || v.length > 500) return null;
  if (!v.startsWith('/') || v.startsWith('//')) return null;
  if (/[\\\u0000-\u001f\u007f]/.test(v)) return null;
  // 先頭が / なのでスキームにはならないが、念のため URL として解釈し直して同じオリジンに留まるかも確かめる
  try {
    if (new URL(v, 'http://127.0.0.1').origin !== 'http://127.0.0.1') return null;
  } catch {
    return null;
  }
  return v;
}

/**
 * 窓口を起動する。戻り値 { port, stop }
 * phpBaseUrl: PHP の内蔵サーバー（http://127.0.0.1:PORT）、secret: PHP と共有する起動ごとの合言葉
 * onOpen(path): PHP の返事に open（相対パス）があったとき、または focus: true のとき（path は null）に呼ぶ。ウィンドウを前面に出して読み込むのは呼び出し側
 */
async function startGateway(paths, { phpBaseUrl, secret, log = () => {}, onOpen = null }) {
  const target = new URL(phpBaseUrl);
  const PORT = voicePort();

  const server = http.createServer((req, res) => {
    const reply = (code, obj) => {
      const body = JSON.stringify(obj);
      res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(body), 'Cache-Control': 'no-store' });
      res.end(body);
    };
    const why = rejectReason(req, PORT);
    if (why) {
      log(`音声窓口: 拒否 ${why} ${req.method} ${String(req.url).slice(0, 80)}`);
      return reply(403, { speech: 'この要求は受け付けられません。' });
    }
    const chunks = [];
    let size = 0;
    let over = false;
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) {
        over = true;
        return;
      }
      chunks.push(c);
    });
    req.on('error', () => {});
    req.on('end', () => {
      if (over) {
        log('音声窓口: 本文が大きすぎるため拒否');
        return reply(413, { speech: '文が長すぎます。' });
      }
      const body = Buffer.concat(chunks);
      const headers = { 'content-type': 'application/json', 'content-length': body.length, host: target.host, 'x-ktp-voice': secret };
      const upstream = http.request({ host: target.hostname, port: target.port, method: 'POST', path: '/?ktp_dt_voice=1', headers, timeout: TIMEOUT_MS }, (ures) => {
        const out = [];
        ures.on('data', (c) => out.push(c));
        ures.on('end', () => {
          let buf = Buffer.concat(out);
          // open（画面を開く先）は窓口で取り除く。安全な相対パスだけ onOpen に渡す
          try {
            const j = JSON.parse(buf.toString('utf8'));
            if (j && typeof j === 'object' && ('open' in j || j.focus === true)) {
              // focus: true は画面を変えずにウィンドウを前面に出すだけ（open: null で onOpen を呼ぶ）
              const open = 'open' in j ? safeOpenPath(j.open) : null;
              if ((open || j.focus === true) && onOpen) {
                try { onOpen(open); } catch (e) { log(`音声窓口: 画面を開けません ${e.message}`); }
              } else if (j.open) {
                log(`音声窓口: 不正な open を無視 ${String(j.open).slice(0, 80)}`);
              }
              delete j.open;
              delete j.focus;
              buf = Buffer.from(JSON.stringify(j), 'utf8');
            }
          } catch {}
          res.writeHead(ures.statusCode || 502, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': buf.length, 'Cache-Control': 'no-store' });
          res.end(buf);
        });
      });
      upstream.on('timeout', () => upstream.destroy(new Error('timeout')));
      upstream.on('error', (e) => {
        log(`音声窓口: PHP につながりません ${e.message}`);
        if (!res.headersSent) reply(502, { speech: 'アプリの内部につながりません。' });
      });
      upstream.end(body);
    });
  });
  server.requestTimeout = TIMEOUT_MS;
  server.headersTimeout = 10000;
  server.keepAliveTimeout = 2000;

  // 使用中なら別のポートへは逃げない（ショートカットの宛先が固定のため）。例外はそのまま呼び出し側へ
  await new Promise((resolve, reject) => {
    const onErr = (e) => reject(e.code === 'EADDRINUSE' ? new Error(`ポート ${PORT} が他のアプリで使用中のため、音声操作を開始できません`) : e);
    server.once('error', onErr);
    server.listen(PORT, '127.0.0.1', () => {
      server.off('error', onErr);
      resolve();
    });
  });
  log(`音声窓口を開始 127.0.0.1:${PORT}`);
  writeStatus(paths, { enabled: true, port: PORT });

  const stop = () =>
    new Promise((resolve) => {
      writeStatus(paths, { enabled: false });
      server.closeAllConnections?.();
      server.close(() => resolve());
      setTimeout(resolve, 1500);
    });
  return { port: PORT, stop };
}

module.exports = { startGateway, readConfig, writeConfig, writeStatus, rejectReason, safeOpenPath, voicePort, VOICE_PORT };
