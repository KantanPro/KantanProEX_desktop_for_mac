// 起動中のアプリ（ポートは引数、既定 9556）の管理画面を確認する: 左メニュー・通知・プロフィールへの移動
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const port = process.argv[2] || '9556';
const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
const page = browser.contexts().flatMap(c => c.pages()).find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
await page.goto(`${base}/wp-admin/admin.php?page=ktp-settings`, { waitUntil: 'networkidle' });
console.log('KantanProEX のサブメニュー:', (await page.locator('#adminmenu .wp-submenu a').allInnerTexts()).join(' / '));
console.log('左メニュー:', (await page.locator('#adminmenu > li.menu-top .wp-menu-name').allInnerTexts()).join(' / '));
const notices = await page.locator('#wpbody-content .notice, #wpbody-content .updated, #wpbody-content .error').evaluateAll(ns => ns.filter(n => n.offsetParent).map(n => n.innerText.trim().replace(/\s+/g, ' ').slice(0, 80)));
console.log('通知:', notices.length ? notices.join(' | ') : 'なし');
await Promise.all([page.waitForURL(/profile\.php/), page.locator('#adminmenu a[href="profile.php"]').first().click()]);
console.log('プロフィールへ:', page.url().replace(base, ''), (await page.locator('h1').first().innerText()).trim());
await page.goto(`${base}/`, { waitUntil: 'networkidle' });
await browser.close();
