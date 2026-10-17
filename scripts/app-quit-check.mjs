// ヘッダーの「アプリを終了」ボタンの見た目と、押したときに画面が移動しないこと（Electron が止めて確認を出す）を確かめる
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const port = process.argv[2] || '9556';
const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
const page = browser.contexts().flatMap(c => c.pages()).find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
await page.goto(`${base}/?tab_name=list`, { waitUntil: 'networkidle' });
const btn = page.locator('a[href*="ktp_desktop_quit"]').first();
console.log('終了ボタン:', await btn.count() ? `title=${await btn.getAttribute('title')} svg=${await btn.locator('svg').count()} 旧ログアウト=${await page.locator('a[title="ログアウト"]').count()}` : 'なし');
await page.screenshot({ path: 'work/quit-button.png', clip: { x: 1100, y: 0, width: 300, height: 120 } });
const before = page.url();
await btn.click({ noWaitAfter: true });
await page.waitForTimeout(1500);
console.log('押した後のURL:', page.url() === before ? '移動していない（OK）' : page.url());
await browser.close();
