// 使い方: node scripts/app-e2e.mjs <出力フォルダ>
// 起動中のアプリ（KTP_DEBUG_PORT=9555 KTP_TEST_DOWNLOAD_DIR=... npm start）を実際に操作する。
// 規約同意 → 顧客作成 → 受注書作成・明細 → 完了→請求済 → レポート → 印刷(window.print) → CSV エクスポート
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.connectOverCDP('http://127.0.0.1:9555');
const page = browser.contexts().flatMap(c => c.pages()).find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
// 印刷は隠し iframe から呼ばれるので、iframe を含む全フレームで window.print を差し替える（実機ではダイアログが開いて止まるため）
await page.context().addInitScript(() => { window.print = () => { try { (window.top.__p = (window.top.__p || 0) + 1); } catch {} }; });
const problems = [];
page.on('pageerror', e => problems.push(`pageerror: ${String(e).slice(0, 160)}`));
page.on('response', r => { if (r.status() >= 400) problems.push(`http${r.status()} ${r.url().slice(0, 120)}`); });
page.on('dialog', d => { problems.push(`dialog: ${d.message().slice(0, 80)}`); d.accept(); });
const step = (s) => console.log('✓', s);
const cdp = await page.context().newCDPSession(page);
await cdp.send('Runtime.enable');
cdp.on('Runtime.exceptionThrown', ({ exceptionDetails: d }) => console.log(`  ! JS例外 ${d.exception?.description?.split('\n').slice(0, 2).join(' / ') || d.text} @ ${d.url || '(inline)'}:${d.lineNumber + 1}`));
const go = async (q) => { await page.goto(`${base}/${q}`, { waitUntil: 'networkidle' }); await page.waitForTimeout(600); };

await go('?tab_name=client');
const agreeBtn = page.locator('#ktpwp-terms-dialog button', { hasText: '利用開始する' });
console.log('規約ボタン:', await agreeBtn.count(), await page.locator('#ktpwp-terms-dialog input[type=checkbox]').count());
if (await agreeBtn.count()) {
  await page.locator('#ktpwp-terms-dialog input[type=checkbox]').first().check();
  await agreeBtn.click();
  await page.waitForTimeout(2500);
  step('利用規約に同意');
}
await page.screenshot({ path: `${out}/1-client.png` });

await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=追加する]').first().click()]);
await page.waitForTimeout(800);
await page.locator('#ktp-client-company_name').fill('株式会社テスト工務店');
await page.locator('#ktp-client-user_name').fill('鈴木 一郎');
await page.locator('#ktp-client-email').fill('suzuki@example.com');
await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=追加実行]').first().click()]);
await page.waitForTimeout(1200);
step(`顧客作成: ${(await page.locator('body').innerText()).includes('株式会社テスト工務店')}`);

await Promise.all([page.waitForLoadState('load'), page.locator('#createOrderButton').click()]);
await page.waitForTimeout(2000);
const orderId = new URL(page.url()).searchParams.get('order_id') || (await page.evaluate(() => document.body.innerText.match(/受注書ID[：:]\s*(\d+)/)?.[1]));
step(`受注書作成: URL=${page.url().replace(base, '')}`);
await page.locator('input[name=order_project_name_inline]').fill('事務所リフォーム');
await page.locator('input[name=order_project_name_inline]').blur();
await page.waitForTimeout(1200);
await page.locator('input[name="invoice_items[0][product_name]"]').fill('内装工事一式');
await page.locator('input[name="invoice_items[0][price]"]').fill('480000');
await page.locator('input[name="invoice_items[0][quantity]"]').fill('1');
await page.locator('input[name="invoice_items[0][quantity]"]').blur();
await page.waitForTimeout(2000);
for (const label of ['受注', '完了', '請求済']) {
  await page.locator('select[name=update_progress]').selectOption({ label });
  await page.waitForTimeout(2200);
}
await page.screenshot({ path: `${out}/2-order.png` });
step('受注書の明細・進捗（受注→完了→請求済）');

await go('?tab_name=report');
await page.waitForTimeout(1500);
const total = await page.locator('body').innerText().then(t => t.match(/総売上\s*([\d,]+円)/)?.[1]);
await page.screenshot({ path: `${out}/3-report.png` });
step(`レポート 総売上=${total}`);

// 印刷: 実機ではダイアログが開くので、ここでは呼ばれた回数だけ確認する
await go('?tab_name=client');
await page.evaluate(() => { window.__p = 0; });
await page.getByRole('button', { name: '顧客リスト印刷' }).click();
await page.waitForTimeout(3500);
step(`顧客リスト印刷 window.print=${await page.evaluate(() => window.__p)}回`);

await go('?tab_name=service');
await page.getByRole('button', { name: 'エクスポート' }).click();
await page.waitForTimeout(800);
await page.locator('#ktp-service-export-form [type=submit]').first().click();
await page.waitForTimeout(3000);
step('サービスCSVエクスポートを実行');

await go('');
await page.screenshot({ path: `${out}/4-home.png` });
console.log('--- 問題 ---'); [...new Set(problems)].forEach(p => console.log(p));
await browser.close();
