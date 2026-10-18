// LAN 共有（事務所の中の他の PC から、スタッフがブラウザで使えるようにする）。
//
// 既定はオフ。PHP の内蔵サーバーは今までどおり 127.0.0.1 だけで、LAN へ出すのはここで立てる「窓口」だけ。
//   他の PC ──https──▶ 窓口（0.0.0.0:47322）──http──▶ PHP（127.0.0.1）
// 窓口がやること:
//   - 私設アドレス（10/8・172.16/12・192.168/16・169.254/16・fc00::/7・fe80::/10）以外からの接続は切る
//     （ルーターのポート転送でインターネットから届いても通さない）
//   - 自動ログイン用 URL・WordPress の管理画面の大半・xmlrpc・wp-cron は通さない
//   - 起動ごとの合言葉（X-KTP-LAN）を付けて PHP に渡す。PHP は合言葉が合う要求だけを「LAN から」と扱う
//     （wp-config.php が受け取る。site.js の writeWpConfig）
// 暗号化は自己署名の証明書（同梱 PHP の openssl で作る）。各 PC で最初の 1 回だけブラウザの警告を通す。
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const http = require('node:http');
const https = require('node:https');
const crypto = require('node:crypto');
const { execFile } = require('node:child_process');
const { phpArgs, PHP_ENV } = require('./php');

const DEFAULT_PORT = 47322;

/** 通してよい /wp-admin/ 配下（自分のパスワード変更と、画面の部品の読み込みだけ） */
const ADMIN_ALLOWED = new Set(['admin-ajax.php', 'admin-post.php', 'profile.php', 'load-styles.php', 'load-scripts.php']);
const STATIC_EXT = /\.(css|js|png|jpe?g|gif|svg|ico|woff2?|ttf|eot|map)$/i;

function configFile(paths) {
  return path.join(paths.userData, 'lan.json');
}

function readConfig(paths) {
  try {
    return { enabled: false, port: DEFAULT_PORT, ...JSON.parse(fs.readFileSync(configFile(paths), 'utf8')) };
  } catch {
    return { enabled: false, port: DEFAULT_PORT };
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
  return path.join(paths.userData, 'lan-status.json');
}

function writeStatus(paths, status) {
  try {
    fs.writeFileSync(statusFile(paths), JSON.stringify(status, null, 2));
  } catch {}
}

/** 接続元が私設アドレス（または同じ PC 自身）か */
function isPrivateAddress(addr) {
  let a = String(addr || '').toLowerCase();
  if (a.startsWith('::ffff:')) a = a.slice(7);
  if (net.isIPv4(a)) {
    const [p, q] = a.split('.').map(Number);
    return p === 10 || p === 127 || (p === 172 && q >= 16 && q <= 31) || (p === 192 && q === 168) || (p === 169 && q === 254);
  }
  if (net.isIPv6(a)) {
    return a === '::1' || a.startsWith('fe8') || a.startsWith('fe9') || a.startsWith('fea') || a.startsWith('feb') || a.startsWith('fc') || a.startsWith('fd');
  }
  return false;
}

/** 窓口を通してよい要求か（通さないなら理由を返す） */
function rejectReason(method, rawUrl) {
  let u;
  try {
    u = new URL(rawUrl, 'http://x');
  } catch {
    return 'bad-url';
  }
  const p = decodeURIComponent(u.pathname).toLowerCase();
  if (u.searchParams.has('ktp_desktop_login') || u.searchParams.has('ktp_desktop_quit') || u.searchParams.has('ktp_desktop_lan')) return 'desktop-only';
  if (/(^|\/)(xmlrpc\.php|wp-cron\.php|wp-config\.php)$/.test(p) || p.includes('/ktp-desktop/')) return 'blocked-file';
  if (p.startsWith('/wp-admin/')) {
    const base = p.split('/').pop();
    if (!ADMIN_ALLOWED.has(base) && !STATIC_EXT.test(base)) return 'admin';
  }
  return null;
}

/** 親機の LAN 側の IPv4 アドレス一覧 */
function lanAddresses() {
  const out = [];
  for (const list of Object.values(os.networkInterfaces())) {
    for (const i of list || []) {
      if (i.family === 'IPv4' && !i.internal && isPrivateAddress(i.address)) out.push(i.address);
    }
  }
  return out;
}

function sha256Fingerprint(certPem) {
  const x = new crypto.X509Certificate(certPem);
  return x.fingerprint256;
}

/** 自己署名の証明書を（なければ、または IP が変わっていたら）作る。戻り値 { key, cert, fingerprint } */
function ensureCertificate(paths, ips) {
  const dir = path.join(paths.userData, 'lan');
  fs.mkdirSync(dir, { recursive: true });
  const keyFile = path.join(dir, 'key.pem');
  const certFile = path.join(dir, 'cert.pem');
  const metaFile = path.join(dir, 'meta.json');
  const names = [...ips, '127.0.0.1', os.hostname()].filter(Boolean);

  let meta = null;
  try {
    meta = JSON.parse(fs.readFileSync(metaFile, 'utf8'));
  } catch {}
  const fresh = meta && fs.existsSync(keyFile) && fs.existsSync(certFile) && names.every((n) => (meta.names || []).includes(n)) && Date.now() < (meta.expires || 0) - 30 * 86400000;
  if (fresh) {
    const cert = fs.readFileSync(certFile, 'utf8');
    return Promise.resolve({ key: fs.readFileSync(keyFile, 'utf8'), cert, fingerprint: sha256Fingerprint(cert) });
  }
  const allNames = [...new Set([...(meta?.names || []), ...names])];
  const script = `
$names = json_decode($argv[1], true); $dir = $argv[2];
$alt = "DNS.1 = localhost\\n"; $d = 2; $i = 1;
foreach ($names as $n) { if (filter_var($n, FILTER_VALIDATE_IP)) { $alt .= "IP." . $i++ . " = $n\\n"; } else { $alt .= "DNS." . $d++ . " = $n\\n"; } }
$conf = "[req]\\ndistinguished_name = dn\\nprompt = no\\nx509_extensions = v3\\n[dn]\\nCN = KantanProEX\\n[v3]\\nbasicConstraints = CA:FALSE\\nkeyUsage = digitalSignature,keyEncipherment\\nextendedKeyUsage = serverAuth\\nsubjectAltName = @alt\\n[alt]\\n" . $alt;
$cnf = $dir . '/openssl.cnf'; file_put_contents($cnf, $conf);
$cfg = array('config' => $cnf, 'x509_extensions' => 'v3', 'req_extensions' => 'v3', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
$key = openssl_pkey_new($cfg);
$csr = openssl_csr_new(array('commonName' => 'KantanProEX'), $key, $cfg);
$x = openssl_csr_sign($csr, null, $key, 825, $cfg);
openssl_x509_export($x, $cert); openssl_pkey_export($key, $pem, null, $cfg);
@unlink($cnf);
if (!$cert || !$pem) { fwrite(STDERR, "openssl failed\\n"); exit(1); }
file_put_contents($dir . '/cert.pem', $cert); file_put_contents($dir . '/key.pem', $pem); chmod($dir . '/key.pem', 0600);
echo 'ok';`;
  return new Promise((resolve, reject) => {
    execFile(paths.php, [...phpArgs(paths), '-r', script, '--', JSON.stringify(allNames), dir], { env: { ...process.env, ...PHP_ENV }, windowsHide: true, timeout: 60000 }, (err, stdout, stderr) => {
      if (err || !/ok$/.test(stdout.trim())) return reject(new Error(`証明書を作れませんでした: ${err ? err.message : ''} ${stderr}`));
      fs.writeFileSync(metaFile, JSON.stringify({ names: allNames, expires: Date.now() + 825 * 86400000 }));
      const cert = fs.readFileSync(certFile, 'utf8');
      resolve({ key: fs.readFileSync(keyFile, 'utf8'), cert, fingerprint: sha256Fingerprint(cert) });
    });
  });
}

/**
 * 窓口を起動する。戻り値 { port, urls, fingerprint, stop }
 * phpBaseUrl: PHP の内蔵サーバー（http://127.0.0.1:PORT）、secret: PHP と共有する起動ごとの合言葉
 */
async function startGateway(paths, { phpBaseUrl, secret, port = DEFAULT_PORT, log = () => {} }) {
  const ips = lanAddresses();
  const { key, cert, fingerprint } = await ensureCertificate(paths, ips);
  const target = new URL(phpBaseUrl);

  const server = https.createServer({ key, cert, minVersion: 'TLSv1.2' }, (req, res) => {
    const remote = req.socket.remoteAddress;
    const deny = (code, text) => {
      res.writeHead(code, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(text);
    };
    if (!isPrivateAddress(remote)) {
      log(`LAN 窓口: 私設アドレス以外からの接続を拒否 ${remote}`);
      return req.socket.destroy();
    }
    const why = rejectReason(req.method, req.url);
    if (why) {
      log(`LAN 窓口: 拒否 ${why} ${String(req.url).split('?')[0].slice(0, 120)} (${remote})`);
      return deny(403, 'この画面は、親機でだけ開けます。');
    }
    const headers = { ...req.headers };
    for (const h of Object.keys(headers)) {
      if (/^x-ktp-/i.test(h) || /^x-forwarded-/i.test(h)) delete headers[h];
    }
    headers['x-ktp-lan'] = secret;
    headers['x-ktp-remote'] = String(remote).replace(/^::ffff:/, '');
    const upstream = http.request({ host: target.hostname, port: target.port, method: req.method, path: req.url, headers }, (ures) => {
      res.writeHead(ures.statusCode, ures.headers);
      ures.pipe(res);
    });
    upstream.on('error', (e) => {
      log(`LAN 窓口: PHP につながりません ${e.message}`);
      if (!res.headersSent) deny(502, 'アプリの内部につながりません。親機の KantanProEX を確認してください。');
      else res.destroy();
    });
    req.pipe(upstream);
  });
  server.on('tlsClientError', () => {});
  server.keepAliveTimeout = 5000;

  const listen = (p) =>
    new Promise((resolve, reject) => {
      const onErr = (e) => reject(e);
      server.once('error', onErr);
      server.listen(p, '0.0.0.0', () => {
        server.off('error', onErr);
        resolve(server.address().port);
      });
    });
  let actual = null;
  for (let p = port; p < port + 10 && actual === null; p++) {
    try {
      actual = await listen(p);
    } catch (e) {
      if (e.code !== 'EADDRINUSE') throw e;
    }
  }
  if (actual === null) throw new Error(`ポート ${port}〜${port + 9} がすべて使用中です`);

  const urls = ips.map((ip) => `https://${ip}:${actual}`);
  log(`LAN 窓口を開始 ${urls.join(' ') || '(LAN のアドレスが見つかりません)'}`);
  writeStatus(paths, { enabled: true, port: actual, urls, fingerprint, hostname: os.hostname() });
  const stop = () =>
    new Promise((resolve) => {
      writeStatus(paths, { enabled: false });
      server.closeAllConnections?.();
      server.close(() => resolve());
      setTimeout(resolve, 1500);
    });
  return { port: actual, urls, fingerprint, stop };
}

module.exports = { startGateway, readConfig, writeConfig, writeStatus, isPrivateAddress, rejectReason, lanAddresses, DEFAULT_PORT };
