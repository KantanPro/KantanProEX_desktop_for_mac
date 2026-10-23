// iPad 表示の確認。LAN 窓口に iPad 相当の端末（縦・横）でログインして、各タブの横はみ出しと入力欄の大きさを調べ、画面を撮る。
// 前提: LAN 共有オンのテスト用データ（app-lan-check.mjs と同じ）。使い方: node scripts/ipad-check.mjs <LANポート 47330> [出力フォルダ]
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const pw = require('playwright');
const [lanPort = '47330', out = 'work/ipad'] = process.argv.slice(2);
const BASE = `https://127.0.0.1:${lanPort}`;
fs.mkdirSync(out, { recursive: true });
let engine = pw.webkit;
try { const b = await pw.webkit.launch(); await b.close(); } catch { engine = pw.chromium; console.log('WebKit が使えないので Chromium で代用'); }
const browser = await engine.launch();
const sizes = { iphone: { width: 390, height: 844 }, 'iphone-landscape': { width: 844, height: 390 } };
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
let bad = 0;
for (const [name, viewport] of Object.entries(sizes)) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport, userAgent: UA, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 15000 });
  await page.waitForTimeout(1500);
  await page.fill('#user_login', 'staff1');
  await page.fill('#user_pass', 'TestPass12345');
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
  await page.waitForTimeout(2500);
  await page.evaluate(`(function(){var c=document.querySelector('#ktpwp-terms-dialog input[type=checkbox]');if(!c)return;c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));var b=[].slice.call(document.querySelectorAll('#ktpwp-terms-dialog button')).find(function(x){return x.textContent.indexOf('利用開始する')>=0});if(b)b.click();})()`);
  await page.waitForTimeout(2500);
  for (const tab of ['list', 'client', 'service', 'supplier', 'report', 'setting']) {
    await page.goto(`${BASE}/?tab_name=${tab}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    const r = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      const wide = [...document.querySelectorAll('body *')].filter((e) => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > vw + 2 && getComputedStyle(e).position !== 'fixed'; }).slice(0, 5).map((e) => `${e.tagName.toLowerCase()}${e.id ? '#' + e.id : ''}${e.className && typeof e.className === 'string' ? '.' + e.className.split(' ')[0] : ''}:${Math.round(e.getBoundingClientRect().right)}`);
      const small = [...document.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]),select,textarea')].filter((e) => e.offsetParent && parseFloat(getComputedStyle(e).fontSize) < 16).length;
      return { vw, sw: document.documentElement.scrollWidth, wide, small };
    });
    const overflow = r.sw > r.vw + 2;
    console.log(`${overflow ? '✗' : '✓'} ${name} ${tab}: 幅 ${r.vw} / 中身 ${r.sw}${overflow ? ' はみ出し ' + r.wide.join(' ') : ''}${r.small ? ` 16px未満の入力欄 ${r.small}` : ''}`);
    if (overflow || r.small) bad++;
    await page.screenshot({ path: `${out}/${name}-${tab}.png` });
  }
  await ctx.close();
}
await browser.close();
console.log(bad ? `\n${bad} 件 要確認` : '\nすべて OK');
