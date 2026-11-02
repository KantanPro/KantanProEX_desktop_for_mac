// Mac のアプリに Apple 独自の簡易署名（ad-hoc 署名）を付ける（electron-builder の afterPack）。
//
// Apple の開発者登録をしていないので、正式な署名・公証はできない（初回の警告は出る）。
// ただし Apple シリコンの Mac は、署名がまったく無いアプリを起動できない。簡易署名なら
// 「システム設定 → このまま開く」で開ける。Intel の Mac でも同じ手順で開ける。
// ユニバーサル版は x64 と arm64 を別々に作ってから1つにまとめる。まとめる前に署名すると
// 両者のファイルが食い違ってまとめられないので、まとめた後（-temp でない出力）だけ署名する。
const { execFileSync } = require('node:child_process');
const path = require('node:path');

exports.default = async function afterPack(context) {
  if (context.electronPlatformName !== 'darwin' || context.appOutDir.endsWith('-temp')) return;
  const app = path.join(context.appOutDir, `${context.packager.appInfo.productFilename}.app`);
  // Resources の中の実行ファイルは --deep でも署名されないので、PHP は先に個別に署名する
  execFileSync('codesign', ['--force', '--sign', '-', path.join(app, 'Contents/Resources/bin/php')], { stdio: 'inherit' });
  execFileSync('codesign', ['--force', '--deep', '--sign', '-', app], { stdio: 'inherit' });
  execFileSync('codesign', ['--verify', '--deep', '--strict', app], { stdio: 'inherit' });
  console.log(`  • 簡易署名を付けました ${app}`);
};
