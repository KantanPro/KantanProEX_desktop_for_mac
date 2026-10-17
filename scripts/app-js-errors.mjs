// 起動中のアプリの各画面を巡回し、JS 例外を発生位置つきで出す
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const browser = await chromium.connectOverCDP('http://127.0.0.1:9555');
const page = browser.contexts().flatMap(c => c.pages()).find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
const cdp = await page.context().newCDPSession(page);
await cdp.send('Runtime.enable');
let cur = '';
cdp.on('Runtime.exceptionThrown', ({ exceptionDetails: d }) => console.log(`[${cur}] ${d.exception?.description?.split('\n').slice(0, 3).join(' / ') || d.text} @ ${d.url || '(inline)'}:${d.lineNumber + 1}`));
const bad = []; page.on('response', async r => { const ct = r.headers()['content-type'] || ''; if (r.url().includes('admin-ajax') && !ct.includes('json')) bad.push(`[${cur}] ajax が JSON 以外: ${ct} ${(r.request().postData() || '').slice(0, 80)}`); });
for (const p of process.argv.slice(2)) { cur = p; await page.goto(base + p, { waitUntil: 'networkidle' }); await page.waitForTimeout(2000); }
bad.forEach(b => console.log(b));
await browser.close();
