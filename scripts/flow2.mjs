// 使い方: AUTH_COOKIE=名前=値 node scripts/flow2.mjs <baseUrl> <出力フォルダ>
// 受注書の編集一式（案件名・進捗・明細・原価・添付・チャット）→ 再読み込みで保持を確認 → レポート表示
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
page.on('pageerror', e => problems.push(`pageerror: ${String(e).slice(0, 160)}`));
page.on('response', async r => {
  if (r.url().includes('admin-ajax.php')) {
    let t = ''; try { t = (await r.text()).slice(0, 140).replace(/\s+/g, ' '); } catch {}
    problems.push(`ajax ${r.status()} ${r.request().postData()?.match(/action=([a-z_]+)/)?.[1] || '?'} → ${t}`);
  }
});
const url = `${base}/?tab_name=order&order_id=1`;
await page.goto(url, { waitUntil: 'networkidle' });
await page.locator('input[name=order_project_name_inline]').fill('SQLite動作確認案件');
await page.locator('input[name=order_project_name_inline]').blur();
await page.waitForTimeout(1500);
await page.locator('select[name=update_progress]').selectOption({ label: '受注' });
await page.waitForTimeout(2500);
await page.locator('input[name="invoice_items[0][product_name]"]').fill('Webサイト制作');
await page.locator('input[name="invoice_items[0][price]"]').fill('150000');
await page.locator('input[name="invoice_items[0][quantity]"]').fill('2');
await page.locator('input[name="invoice_items[0][quantity]"]').blur();
await page.waitForTimeout(2500);
await page.locator('summary.ktp-order-details-summary', { hasText: 'コスト項目' }).click();
await page.waitForTimeout(500);
await page.locator('input[name="cost_items[0][product_name]"]').fill('外注デザイン費');
await page.locator('input[name="cost_items[0][price]"]').fill('40000');
await page.locator('input[name="cost_items[0][quantity]"]').fill('1');
await page.locator('input[name="cost_items[0][quantity]"]').blur();
await page.waitForTimeout(2500);
await page.evaluate(() => document.querySelectorAll('details').forEach(d => { d.open = true; }));
await page.waitForTimeout(400);
await page.locator('#staff-chat-input').fill('動作確認のメッセージ');
await page.locator('#staff-chat-submit').click();
await page.waitForTimeout(2000);
await page.locator('input[name=ktpwp_order_file]').setInputFiles('/Users/kantanpro/Desktop/KantanProEX-Desktop/work/ex-client.png');
await page.locator('.ktp-order-file-upload-btn').click();
await page.waitForTimeout(2500);
await page.screenshot({ path: `${out}/3-order-edited.png`, fullPage: true });

await page.goto(url, { waitUntil: 'networkidle' });   // 再読み込みで保持確認
await page.waitForTimeout(800);
await page.locator('summary.ktp-order-details-summary', { hasText: 'コスト項目' }).click().catch(() => {});
await page.waitForTimeout(400);
const kept = await page.evaluate(() => ({
  project: document.querySelector('input[name=order_project_name_inline]')?.value,
  progress: document.querySelector('select[name=update_progress]')?.selectedOptions[0]?.text,
  item: document.querySelector('input[name="invoice_items[0][product_name]"]')?.value,
  price: document.querySelector('input[name="invoice_items[0][price]"]')?.value,
  cost: document.querySelector('input[name="cost_items[0][product_name]"]')?.value,
  chat: document.body.innerText.includes('動作確認のメッセージ'),
  file: document.body.innerText.includes('ex-client.png'),
}));
console.log('再読み込み後:', JSON.stringify(kept));
await page.locator('select[name=update_progress]').selectOption({ label: '完了' });
await page.waitForTimeout(2500);
await page.locator('select[name=update_progress]').selectOption({ label: '請求済' });
await page.waitForTimeout(3000);
await page.goto(`${base}/?tab_name=report`, { waitUntil: 'networkidle' });
await page.waitForTimeout(2500);
await page.screenshot({ path: `${out}/4-report.png`, fullPage: true });
console.log('--- 通信/エラー ---');
[...new Set(problems)].forEach(p => console.log(p));
await browser.close();
