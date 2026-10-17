// 使い方: AUTH_COOKIE=名前=値 node scripts/js-errors.mjs <baseUrl> <パス...>
// 各ページの JS 例外を「URL:行:列」付きで出す（構文エラーの発生元特定用）
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const [base, ...paths] = process.argv.slice(2);
const browser = await chromium.launch({ channel: 'chrome' });
const page = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
const [n, ...v] = process.env.AUTH_COOKIE.split('=');
await page.context().addCookies([{ name: n, value: v.join('='), url: base }]);
const cdp = await page.context().newCDPSession(page);
await cdp.send('Runtime.enable');
let current = '';
cdp.on('Runtime.exceptionThrown', ({ exceptionDetails: d }) => {
  console.log(`[${current}] ${d.exception?.description?.split('\n')[0] || d.text}  @ ${d.url || '(inline)'}:${d.lineNumber + 1}:${d.columnNumber + 1}`);
});
for (const p of paths) {
  current = p;
  await page.goto(base + p, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
}
await browser.close();
