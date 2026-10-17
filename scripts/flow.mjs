// 使い方: AUTH_COOKIE=名前=値 node scripts/flow.mjs <baseUrl> <出力フォルダ>
// 顧客作成 → 顧客画面から受注書を作成 → 画面を保存。途中の失敗通信・JSエラーを集計する
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const [base, out] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome' });
const page = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
const [n, ...v] = process.env.AUTH_COOKIE.split('=');
await page.context().addCookies([{ name: n, value: v.join('='), url: base }]);
const problems = [];
page.on('pageerror', e => problems.push(`pageerror: ${String(e).slice(0, 200)}`));
page.on('response', r => { if (r.status() >= 400 && !/\.(css|js)\?/.test(r.url())) problems.push(`http${r.status()}: ${r.request().method()} ${r.url().slice(0, 150)}`); });
const shot = name => page.screenshot({ path: `${out}/${name}.png` });

await page.goto(`${base}/?tab_name=client`, { waitUntil: 'networkidle' });
// 「＋」(title=Add) で新規作成モードに入ってから入力する
await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=Add]').first().click()]);
await page.waitForTimeout(1000);
console.log('保存ボタン候補:', await page.locator('button[type=submit]:visible').evaluateAll(els => els.map(e => `${e.name}/${e.title}/${e.innerText.trim()}`)));
await page.locator('#ktp-client-company_name').fill('テスト商事株式会社');
await page.locator('#ktp-client-user_name').fill('山田 太郎');
await page.locator('#ktp-client-email').fill('yamada@example.com');
await page.locator('#ktp-client-postal_code').fill('1000001');
await page.locator('#ktp-client-prefecture').fill('東京都');
await page.locator('#ktp-client-city').fill('千代田区');
await page.locator('#ktp-client-address').fill('千代田1-1');
await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=Add]').first().click()]);
await page.waitForTimeout(1500);
await shot('1-client-created');
console.log('顧客一覧の本文:', (await page.locator('body').innerText()).includes('テスト商事') ? 'テスト商事 あり' : 'テスト商事 なし');

await Promise.all([page.waitForLoadState('load'), page.locator('#createOrderButton').click()]);
await page.waitForTimeout(2500);
await shot('2-order-created');
console.log('URL:', page.url());
console.log('--- 問題 ---');
[...new Set(problems)].forEach(p => console.log(p));
await browser.close();
