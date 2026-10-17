// 使い方: node scripts/app-shot.mjs <出力png> [URLのパス]
// 起動中のアプリ（KTP_DEBUG_PORT=9555 npm start）に接続して、メインウィンドウを撮影する
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const [out, goto] = process.argv.slice(2);
const browser = await chromium.connectOverCDP('http://127.0.0.1:9555');
const pages = browser.contexts().flatMap(c => c.pages());
const page = pages.find(p => p.url().startsWith('http://127.0.0.1:')) || pages[0];
if (goto) { await page.goto(new URL(goto, page.url()).href, { waitUntil: 'networkidle' }); }
await page.waitForTimeout(1200);
console.log('URL:', page.url());
await page.screenshot({ path: out });
await browser.close();
