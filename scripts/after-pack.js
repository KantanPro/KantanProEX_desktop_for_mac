// Mac のアプリに署名する（electron-builder の afterPack）。
//
// Apple の開発者登録をしていないので、正式な署名・公証はできない（初回の警告は出る。「システム設定 → このまま開く」で開ける）。
// それでも署名するのは2つの理由から:
//   - Apple シリコンの Mac は、署名がまったく無いアプリを起動できない
//   - macOS は「書類」フォルダなどへのアクセスの許可を署名に結び付けて覚える。ad-hoc 署名だとビルドのたびに
//     別のアプリと見なされ、更新のたびに許可を聞き直される。自前の証明書（自己署名）で毎回同じ署名にすれば聞き直されない
// 証明書は手元のキーチェーンファイル（既定 ~/.kantanpro/dt-codesign/、リポジトリの外）に置き、署名のあいだだけ
// キーチェーンの一覧に加える。証明書を作り直すと、利用者は一度だけ許可を聞き直される。
// ユニバーサル版は x64 と arm64 を別々に作ってから1つにまとめる。まとめる前に署名すると
// 両者のファイルが食い違ってまとめられないので、まとめた後（-temp でない出力）だけ署名する。
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const DIR = process.env.KTP_CODESIGN_DIR || path.join(os.homedir(), '.kantanpro', 'dt-codesign');
const KEYCHAIN = path.join(DIR, 'dt-codesign.keychain-db');
const IDENTITY = '44788598B75108DF0D61AE62572A6C3DAEC3F96B'; // 「KantanPro Desktop」（2026-11-03 作成・20年）

function security(args) {
  return execFileSync('security', args, { encoding: 'utf8' });
}

// Linux: 実行ファイルを kantanproex.bin に改名し、--no-sandbox を付けて起動する小さなスクリプトを kantanproex に置く。
// Ubuntu 23.10 以降などは非特権のユーザー名前空間が AppArmor で制限され、AppImage では chrome-sandbox に SUID も
// 付けられないので、Chromium のサンドボックスが立ち上がらず起動できない（画面のプロセスが落ちる）。
// main.js の app.commandLine.appendSwitch('no-sandbox') では、先に起動する zygote に効かないので、起動時の引数で渡す。
// 画面が開くのは同梱 PHP の 127.0.0.1 だけで、外部への読み込みは webRequest で止めている。
function wrapLinuxExecutable(context) {
  const name = context.packager.executableName;
  const exe = path.join(context.appOutDir, name);
  fs.renameSync(exe, `${exe}.bin`);
  fs.writeFileSync(exe, `#!/bin/sh\nexec "$(dirname "$(readlink -f "$0")")/${name}.bin" --no-sandbox "$@"\n`, { mode: 0o755 });
  console.log(`  • 起動スクリプトを置きました（--no-sandbox） ${exe}`);
}

exports.default = async function afterPack(context) {
  if (context.electronPlatformName === 'linux') return wrapLinuxExecutable(context);
  if (context.electronPlatformName !== 'darwin' || context.appOutDir.endsWith('-temp')) return;
  if (!fs.existsSync(KEYCHAIN)) {
    throw new Error(`署名用のキーチェーンがありません: ${KEYCHAIN}（ad-hoc で配ると利用者が更新のたびに許可を聞かれるので止めます）`);
  }
  const app = path.join(context.appOutDir, `${context.packager.appInfo.productFilename}.app`);
  const before = security(['list-keychains', '-d', 'user']).split('\n').map((s) => s.trim().replace(/^"|"$/g, '')).filter(Boolean);
  try {
    security(['list-keychains', '-d', 'user', '-s', ...before, KEYCHAIN]);
    security(['unlock-keychain', '-p', fs.readFileSync(path.join(DIR, 'keychain-password.txt'), 'utf8').trim(), KEYCHAIN]);
    const sign = (target, deep) => execFileSync('codesign', ['--force', ...(deep ? ['--deep'] : []), '--sign', IDENTITY, '--keychain', KEYCHAIN, target], { stdio: 'inherit' });
    // Resources の中の実行ファイルは --deep でも署名されないので、PHP は先に個別に署名する
    sign(path.join(app, 'Contents/Resources/bin/php'), false);
    sign(app, true);
  } finally {
    security(['list-keychains', '-d', 'user', '-s', ...before]);
  }
  execFileSync('codesign', ['--verify', '--deep', '--strict', app], { stdio: 'inherit' });
  console.log(`  • 署名しました（KantanPro Desktop） ${app}`);
};
