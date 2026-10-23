// LAN 共有（事務所の中の他の PC から、スタッフがブラウザで使えるようにする）。
//
// 既定はオフ。PHP の内蔵サーバーは今までどおり 127.0.0.1 だけで、LAN へ出すのはここで立てる「窓口」だけ。
//   他の PC ──https──▶ 窓口（0.0.0.0:47322）──http──▶ PHP（127.0.0.1）
// 窓口がやること:
//   - 私設アドレス（10/8・172.16/12・192.168/16・169.254/16・100.64/10（Tailscale）・fc00::/7・fe80::/10）以外からの接続は切る
//     （ルーターのポート転送でインターネットから届いても通さない）
//   - 自動ログイン用 URL・WordPress の管理画面の大半・xmlrpc・wp-cron は通さない
//   - 起動ごとの合言葉（X-KTP-LAN）を付けて PHP に渡す。PHP は合言葉が合う要求だけを「LAN から」と扱う
//     （wp-config.php が受け取る。site.js の writeWpConfig）
// 暗号化は事務所用の認証局（CA）で署名した証明書（同梱 PHP の openssl で作る）。iPad・iPhone・PC は CA を最初の 1 回だけ信頼すれば警告が出ない
// （配布は /ktp-lan/ca.mobileconfig と /ktp-lan/ca.crt。ログインなしで取れるが、中身は公開しても困らない CA 証明書だけ）。
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

/** 接続元が私設アドレス（または同じ PC 自身、Tailscale の 100.64/10）か */
function isPrivateAddress(addr) {
  let a = String(addr || '').toLowerCase();
  if (a.startsWith('::ffff:')) a = a.slice(7);
  if (net.isIPv4(a)) {
    const [p, q] = a.split('.').map(Number);
    return p === 10 || p === 127 || (p === 172 && q >= 16 && q <= 31) || (p === 192 && q === 168) || (p === 169 && q === 254) || (p === 100 && q >= 64 && q <= 127);
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
  // サイト直下の /ktp-desktop/（wp-cli・土台づくりの PHP）と、mu-plugins の PHP は通さない。画面の部品（JS・CSS・画像）は通す
  if (/(^|\/)(xmlrpc\.php|wp-cron\.php|wp-config\.php)$/.test(p) || p.startsWith('/ktp-desktop/') || (p.includes('/mu-plugins/') && !STATIC_EXT.test(p))) return 'blocked-file';
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

/** PEM → DER（構成プロファイル・.crt 用） */
function pemToDer(pem) {
  return Buffer.from(pem.replace(/-----[^-]+-----/g, '').replace(/\s+/g, ''), 'base64');
}

/**
 * 事務所用の認証局（CA、10 年）と、それで署名したサーバー証明書（825 日）を（なければ、または IP が変わっていたら）作る。
 * iPad・iPhone・PC は、CA を 1 回だけ信頼すれば警告が出ない。IP が変わったときに作り直すのはサーバー証明書だけ。
 * 戻り値 { key, cert, fingerprint, caPem }
 */
function ensureCertificate(paths, ips) {
  const dir = path.join(paths.userData, 'lan');
  fs.mkdirSync(dir, { recursive: true });
  const keyFile = path.join(dir, 'key.pem');
  const certFile = path.join(dir, 'cert.pem');
  const caFile = path.join(dir, 'ca.pem');
  const caKeyFile = path.join(dir, 'ca-key.pem');
  const metaFile = path.join(dir, 'meta.json');
  const names = [...ips, '127.0.0.1', os.hostname()].filter(Boolean);

  let meta = null;
  try {
    meta = JSON.parse(fs.readFileSync(metaFile, 'utf8'));
  } catch {}
  const hasCa = !!meta && meta.ca === true && fs.existsSync(caFile) && fs.existsSync(caKeyFile) && Date.now() < (meta.caExpires || 0) - 90 * 86400000;
  const load = () => {
    const cert = fs.readFileSync(certFile, 'utf8');
    return { key: fs.readFileSync(keyFile, 'utf8'), cert, fingerprint: sha256Fingerprint(cert), caPem: fs.readFileSync(caFile, 'utf8') };
  };
  const fresh = hasCa && fs.existsSync(keyFile) && fs.existsSync(certFile) && names.every((n) => (meta.names || []).includes(n)) && Date.now() < (meta.expires || 0) - 30 * 86400000;
  if (fresh) return Promise.resolve(load());

  // CA が無い（旧方式の自己署名からの移行を含む）なら CA から作り直す。CA があればサーバー証明書だけ作り直す
  const allNames = [...new Set([...(hasCa ? meta.names || [] : []), ...names])];
  const script = `
$names = json_decode($argv[1], true); $dir = $argv[2]; $needCa = $argv[3] === '1';
$alt = "DNS.1 = localhost\\n"; $d = 2; $i = 1;
foreach ($names as $n) { if (filter_var($n, FILTER_VALIDATE_IP)) { $alt .= "IP." . $i++ . " = $n\\n"; } else { $alt .= "DNS." . $d++ . " = $n\\n"; } }
$conf = "[req]\\ndistinguished_name = dn\\nprompt = no\\nx509_extensions = v3\\n[dn]\\nCN = KantanProEX\\n"
  . "[v3]\\nbasicConstraints = CA:FALSE\\nkeyUsage = digitalSignature,keyEncipherment\\nextendedKeyUsage = serverAuth\\nsubjectAltName = @alt\\n"
  . "[v3_ca]\\nbasicConstraints = critical,CA:TRUE,pathlen:0\\nkeyUsage = critical,keyCertSign,cRLSign\\nsubjectKeyIdentifier = hash\\n"
  . "[alt]\\n" . $alt;
$cnf = $dir . '/openssl.cnf'; file_put_contents($cnf, $conf);
$base = array('config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA);
if ($needCa) {
  $caKey = openssl_pkey_new($base);
  $caCsr = openssl_csr_new(array('commonName' => 'KantanProEX LAN 認証局 ' . substr(bin2hex(random_bytes(4)), 0, 8), 'organizationName' => 'KantanProEX'), $caKey, $base);
  $caCert = openssl_csr_sign($caCsr, null, $caKey, 3650, $base + array('x509_extensions' => 'v3_ca'), random_int(1, 2147483646));
  openssl_x509_export($caCert, $caPem); openssl_pkey_export($caKey, $caKeyPem, null, $base);
  if (!$caPem || !$caKeyPem) { fwrite(STDERR, "ca failed\\n"); exit(1); }
  file_put_contents($dir . '/ca.pem', $caPem); file_put_contents($dir . '/ca-key.pem', $caKeyPem); chmod($dir . '/ca-key.pem', 0600);
}
$caCert = file_get_contents($dir . '/ca.pem'); $caKey = file_get_contents($dir . '/ca-key.pem');
$key = openssl_pkey_new($base);
$csr = openssl_csr_new(array('commonName' => 'KantanProEX'), $key, $base);
$x = openssl_csr_sign($csr, $caCert, $caKey, 825, $base + array('x509_extensions' => 'v3'), random_int(1, 2147483646));
openssl_x509_export($x, $cert); openssl_pkey_export($key, $pem, null, $base);
@unlink($cnf);
if (!$cert || !$pem) { fwrite(STDERR, "openssl failed\\n"); exit(1); }
file_put_contents($dir . '/cert.pem', $cert); file_put_contents($dir . '/key.pem', $pem); chmod($dir . '/key.pem', 0600);
echo 'ok';`;
  return new Promise((resolve, reject) => {
    execFile(paths.php, [...phpArgs(paths), '-r', script, '--', JSON.stringify(allNames), dir, hasCa ? '0' : '1'], { env: { ...process.env, ...PHP_ENV }, windowsHide: true, timeout: 60000 }, (err, stdout, stderr) => {
      if (err || !/ok$/.test(stdout.trim())) return reject(new Error(`証明書を作れませんでした: ${err ? err.message : ''} ${stderr}`));
      fs.writeFileSync(metaFile, JSON.stringify({ ca: true, caExpires: hasCa ? meta.caExpires : Date.now() + 3650 * 86400000, names: allNames, expires: Date.now() + 825 * 86400000 }));
      resolve(load());
    });
  });
}

/** iPad・iPhone に入れる構成プロファイル（CA 証明書だけ。公開しても困らない） */
function buildMobileConfig(caPem) {
  const uuid = () => crypto.randomUUID().toUpperCase();
  const b64 = pemToDer(caPem).toString('base64').replace(/(.{64})/g, '$1\n');
  return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>PayloadCertificateFileName</key><string>kantanproex-lan-ca.cer</string>
      <key>PayloadContent</key><data>
${b64}
</data>
      <key>PayloadDescription</key><string>KantanProEX の LAN 共有に使う、この事務所だけの証明書です。</string>
      <key>PayloadDisplayName</key><string>KantanProEX LAN 認証局</string>
      <key>PayloadIdentifier</key><string>com.kantanpro.kantanproex.lan.ca</string>
      <key>PayloadType</key><string>com.apple.security.root</string>
      <key>PayloadUUID</key><string>${uuid()}</string>
      <key>PayloadVersion</key><integer>1</integer>
    </dict>
  </array>
  <key>PayloadDescription</key><string>KantanProEX の LAN 共有の警告をなくします。</string>
  <key>PayloadDisplayName</key><string>KantanProEX（LAN 共有）</string>
  <key>PayloadIdentifier</key><string>com.kantanpro.kantanproex.lan.profile</string>
  <key>PayloadRemovalDisallowed</key><false/>
  <key>PayloadType</key><string>Configuration</string>
  <key>PayloadUUID</key><string>${uuid()}</string>
  <key>PayloadVersion</key><integer>1</integer>
</dict>
</plist>
`;
}

/**
 * 窓口を起動する。戻り値 { port, urls, fingerprint, stop }
 * phpBaseUrl: PHP の内蔵サーバー（http://127.0.0.1:PORT）、secret: PHP と共有する起動ごとの合言葉
 */
async function startGateway(paths, { phpBaseUrl, secret, port = DEFAULT_PORT, log = () => {} }) {
  const ips = lanAddresses();
  const { key, cert, fingerprint, caPem } = await ensureCertificate(paths, ips);
  const mobileConfig = buildMobileConfig(caPem);
  const caDer = pemToDer(caPem);
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
    // ログインなしで取れる配布ファイル（CA 証明書だけ。秘密鍵は含まない）
    const pub = String(req.url).split('?')[0];
    if (req.method === 'GET' && pub === '/ktp-lan/ca.mobileconfig') {
      res.writeHead(200, { 'Content-Type': 'application/x-apple-aspen-config', 'Content-Disposition': 'attachment; filename="KantanProEX-LAN.mobileconfig"', 'Cache-Control': 'no-store' });
      return res.end(mobileConfig);
    }
    if (req.method === 'GET' && pub === '/ktp-lan/ca.crt') {
      res.writeHead(200, { 'Content-Type': 'application/x-x509-ca-cert', 'Content-Disposition': 'attachment; filename="KantanProEX-LAN.crt"', 'Cache-Control': 'no-store' });
      return res.end(caDer);
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

  let urls = ips.map((ip) => `https://${ip}:${actual}`);
  let currentFingerprint = fingerprint;
  const publish = () => writeStatus(paths, { enabled: true, port: actual, urls, fingerprint: currentFingerprint, hostname: os.hostname(), profileUrls: urls.map((u) => `${u}/ktp-lan/ca.mobileconfig`) });
  log(`LAN 窓口を開始 ${urls.join(' ') || '(LAN のアドレスが見つかりません)'}`);
  publish();

  // 起動後に Wi-Fi・Tailscale がつながる／IP が変わることがある。変わったら証明書を作り直して、画面の QR のアドレスも更新する
  let knownIps = ips.join(',');
  let checking = false;
  const watcher = setInterval(async () => {
    const now = lanAddresses();
    if (checking || now.join(',') === knownIps) return;
    checking = true;
    try {
      const renewed = await ensureCertificate(paths, now);
      server.setSecureContext({ key: renewed.key, cert: renewed.cert });
      currentFingerprint = renewed.fingerprint;
      knownIps = now.join(',');
      urls = now.map((ip) => `https://${ip}:${actual}`);
      log(`LAN のアドレスが変わりました ${urls.join(' ') || '(なし)'}`);
      publish();
    } catch (e) {
      log(`LAN のアドレス更新に失敗 ${e.message}`);
    } finally {
      checking = false;
    }
  }, 5000);
  watcher.unref?.();
  const stop = () =>
    new Promise((resolve) => {
      clearInterval(watcher);
      writeStatus(paths, { enabled: false });
      server.closeAllConnections?.();
      server.close(() => resolve());
      setTimeout(resolve, 1500);
    });
  return { port: actual, get urls() { return urls; }, fingerprint, stop };
}

module.exports = { buildMobileConfig, startGateway, readConfig, writeConfig, writeStatus, isPrivateAddress, rejectReason, lanAddresses, DEFAULT_PORT };
