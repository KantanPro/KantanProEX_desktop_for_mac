// 無料版と有料版（ライセンス）の状態を読む。判定そのものは PHP（site/mu-plugins/ktp-desktop/license.php）が行い、
// PHP が userData/license.json に書いた結果を、LAN 共有・音声操作の窓口を立てるかどうかの判断にだけ使う
// （窓口を立てなくても、PHP 側でも無料版の LAN・音声の要求は断る。二重の備え）。
const fs = require('node:fs');
const path = require('node:path');

function readLicense(paths) {
  try {
    return JSON.parse(fs.readFileSync(path.join(paths.userData, 'license.json'), 'utf8'));
  } catch {
    return null;
  }
}

/**
 * 有料版の機能（LAN 共有・音声操作）を始めてよいか。
 * 記録がまだ無いとき（初回・更新直後）は許可する（PHP が最初の画面で判定して記録する）
 */
function paidFeaturesAllowed(paths) {
  const l = readLicense(paths);
  if (!l) return true;
  if (l.state === 'paid' || l.state === 'legacy') return true;
  if (l.state === 'trial') return typeof l.until === 'number' && l.until * 1000 > Date.now();
  return false;
}

module.exports = { readLicense, paidFeaturesAllowed };
