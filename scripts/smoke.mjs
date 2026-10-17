// 使い方: node scripts/smoke.mjs <baseUrl> <出力フォルダ>
// ログイン → 6タブ巡回 → コンソールエラー・失敗通信・画面内のPHPエラーを集計し、スクリーンショットを保存する
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');

const base = process.argv[2];
const out = process.argv[3];
fs.mkdirSync(out, { recursive: true });

const browser = await chromium.launch({ channel: 'chrome' });
const page = await (await browser.newContext({ viewport: { width: 1400, height: 900 } })).newPage();
const problems = [];
page.on('console', m => { if (m.type() === 'error') problems.push(`console: ${m.text().slice(0, 200)}`); });
page.on('pageerror', e => problems.push(`pageerror: ${String(e).slice(0, 200)}`));
page.on('requestfailed', r => problems.push(`requestfailed: ${r.url().slice(0, 150)} ${r.failure()?.errorText}`));
page.on('response', r => { if (r.status() >= 400) problems.push(`http${r.status()}: ${r.url().slice(0, 150)}`); });

// フォームのログインはブラウザ側で不安定だったので、wp-cli で発行した認証Cookieを注入する（環境変数 AUTH_COOKIE=名前=値）
const [ckName, ...ckRest] = process.env.AUTH_COOKIE.split('=');
await page.context().addCookies([{ name: ckName, value: ckRest.join('='), url: base }]);
for (const tab of ['list', 'order', 'client', 'service', 'supplier', 'report']) {
  const t0 = Date.now();
  await page.goto(`${base}/?tab_name=${tab}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const body = await page.locator('body').innerText();
  const php = body.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|WordPress database error)[^\n]{0,150}/g) || [];
  console.log(`${tab}: ${Date.now() - t0}ms  本文${body.length}文字  PHPエラー表示${php.length}件`);
  php.forEach(p => console.log('   ' + p));
  await page.screenshot({ path: `${out}/${tab}.png`, fullPage: false });
}
console.log('--- 問題 ---');
[...new Set(problems)].forEach(p => console.log(p));
await browser.close();
