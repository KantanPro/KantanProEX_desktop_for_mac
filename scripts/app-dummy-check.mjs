// 起動中のアプリ（ポートは引数、既定 9556）でダミーデータの作成・削除・再作成を試す
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const port = process.argv[2] || '9556';
const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
const page = browser.contexts().flatMap(c => c.pages()).find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
page.on('dialog', d => d.accept());
const ajax = (action) => page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes(`action=${action}`), { timeout: 120000 })
  .then(async r => { try { const j = await r.json(); return `${j.success ? '成功' : '失敗'} ${String(j.data?.message ?? JSON.stringify(j.data)).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').slice(0, 140)}`; } catch { return `JSONでない応答 ${r.status()}`; } });
const counts = async () => {
  const r = await page.evaluate(async () => {
    const out = {};
    for (const t of ['client', 'service', 'supplier', 'list']) {
      const html = await (await fetch(`/?tab_name=${t}`)).text();
      const m = html.match(/全\s*(\d+)\s*件/);
      out[t] = m ? Number(m[1]) : '?';
    }
    return out;
  });
  return `顧客${r.client} サービス${r.service} 協力会社${r.supplier} 仕事リスト${r.list}`;
};
const open = async () => { await page.goto(`${base}/wp-admin/admin.php?page=ktpwp-dummy-data`, { waitUntil: 'networkidle' }); };

await open();
console.log('画面:', (await page.locator('h1').first().innerText()).trim());
const t0 = Date.now();
let res = ajax('ktpwp_create_dummy_data'); await page.locator('#create-dummy-data').click(); console.log('作成:', await res, `(${((Date.now() - t0) / 1000).toFixed(1)}秒)`);
console.log('作成後:', await counts());
await open();
res = ajax('ktpwp_clear_data'); await page.locator('#clear-data').click(); console.log('削除:', await res);
console.log('削除後:', await counts());
await open();
res = ajax('ktpwp_create_dummy_data'); await page.locator('#create-dummy-data').click(); console.log('再作成:', await res);
console.log('再作成後:', await counts());
await page.goto(`${base}/?tab_name=list`, { waitUntil: 'networkidle' });
await page.screenshot({ path: 'work/dummy-list.png' });
await page.goto(`${base}/?tab_name=report`, { waitUntil: 'networkidle' }); await page.waitForTimeout(1500);
console.log('レポート総売上:', await page.locator('body').innerText().then(t => t.match(/総売上\s*([\d,]+円)/)?.[1]));
await page.screenshot({ path: 'work/dummy-report.png' });
await browser.close();
