// 使い方: AUTH_COOKIE=名前=値 node scripts/flow3.mjs <baseUrl> <出力フォルダ>
// サービス・協力会社の作成、CSVエクスポート、各タブの印刷（window.print 呼び出し）、受注書のPDF保存を確認する
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const [base, out] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 }, acceptDownloads: true });
const [n, ...v] = process.env.AUTH_COOKIE.split('=');
await ctx.addCookies([{ name: n, value: v.join('='), url: base }]);
// 印刷ダイアログは開かずに回数だけ数える（iframe 内の print も含める）
await ctx.addInitScript(() => { window.__printCount = 0; window.print = () => { (window.top || window).__printCount = ((window.top || window).__printCount || 0) + 1; }; });
const page = await ctx.newPage();
const problems = [];
page.on('pageerror', e => problems.push(`pageerror: ${String(e).slice(0, 160)}`));
page.on('dialog', d => { problems.push(`dialog: ${d.message().slice(0, 100)}`); d.dismiss(); });
const result = (k, v) => console.log(`${k}: ${v}`);

async function createRecord(tab, fields) {
  await page.goto(`${base}/?tab_name=${tab}`, { waitUntil: 'networkidle' });
  await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=追加する]').first().click()]);
  await page.waitForTimeout(800);
  for (const [sel, val] of Object.entries(fields)) await page.locator(sel).fill(val);
  await Promise.all([page.waitForLoadState('load'), page.locator('button[name=send_post][title=追加実行]').first().click()]);
  await page.waitForTimeout(1200);
  return (await page.locator('body').innerText()).includes(Object.values(fields)[0]);
}
result('サービス作成', await createRecord('service', { 'input[name=service_name]': 'ホームページ保守', 'input[name=price]': '30000', 'input[name=unit]': '月' }));
result('協力会社作成', await createRecord('supplier', { '#ktp-supplier-company_name': '協力デザイン合同会社', '#ktp-supplier-user_name': '佐藤 花子', '#ktp-supplier-email': 'sato@example.com' }));

// CSV エクスポート（サービス）
await page.goto(`${base}/?tab_name=service`, { waitUntil: 'networkidle' });
try {
  await page.getByRole('button', { name: 'エクスポート' }).click();   // 形式選択のモーダルが開く
  await page.waitForTimeout(800);
  console.log('エクスポートの形式:', await page.locator('#ktp-service-export-form input[name=format]').evaluateAll(els => els.map(e => e.value + (e.checked ? '(選択中)' : '')).join(' ')));
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.locator('#ktp-service-export-form [type=submit]').first().click()]);
  const p = `${out}/${dl.suggestedFilename()}`; await dl.saveAs(p);
  result('サービスCSV', `${dl.suggestedFilename()} ${fs.statSync(p).size}bytes 先頭=${fs.readFileSync(p, 'utf8').slice(0, 60).replace(/\n/g, '⏎')}`);
} catch (e) { result('サービスCSV', '失敗 ' + String(e).slice(0, 120)); }

// 各タブの印刷ボタン
for (const [tab, label] of [['client', '顧客リスト印刷'], ['service', 'サービスリスト印刷'], ['supplier', '協力会社リスト印刷'], ['report', 'レポート印刷'], ['list', null]]) {
  await page.goto(`${base}/?tab_name=${tab}`, { waitUntil: 'networkidle' });
  await page.evaluate(() => { window.__printCount = 0; });
  const btn = label ? page.getByRole('button', { name: label }) : page.locator('.ktp-tab-print-btn, button[title*=印刷]').first();
  if (!(await btn.count())) { result(`印刷 ${tab}`, 'ボタンなし'); continue; }
  await btn.first().click();
  await page.waitForTimeout(4000);
  result(`印刷 ${tab}`, `window.print 呼び出し ${await page.evaluate(() => window.__printCount)} 回`);
}

// 受注書: 印刷プレビュー → 印刷 / PDF保存
await page.goto(`${base}/?tab_name=order&order_id=1`, { waitUntil: 'networkidle' });
await page.locator('#orderPreviewButton').click();
await page.waitForTimeout(2500);
await page.screenshot({ path: `${out}/order-preview.png` });
const previewButtons = await page.locator('button:visible').evaluateAll(els => els.map(e => e.innerText.trim()).filter(t => /印刷|PDF|保存/.test(t)));
result('受注書プレビューのボタン', JSON.stringify(previewButtons));
const pdfBtn = page.locator('button:visible', { hasText: 'PDF' }).first();
if (await pdfBtn.count()) {
  try {
    const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), pdfBtn.click()]);
    const p = `${out}/${dl.suggestedFilename()}`; await dl.saveAs(p);
    result('受注書PDF', `${dl.suggestedFilename()} ${fs.statSync(p).size}bytes 先頭=${fs.readFileSync(p).slice(0, 5).toString()}`);
  } catch (e) { result('受注書PDF', '失敗 ' + String(e).slice(0, 120)); }
}
const printBtn = page.locator('button:visible', { hasText: '印刷' }).last();
if (await printBtn.count()) {
  await page.evaluate(() => { window.__printCount = 0; });
  await printBtn.click(); await page.waitForTimeout(4000);
  result('受注書印刷', `window.print 呼び出し ${await page.evaluate(() => window.__printCount)} 回`);
}
console.log('--- 問題 ---'); [...new Set(problems)].forEach(p => console.log(p));
await browser.close();
