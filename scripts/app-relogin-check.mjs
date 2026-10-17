// ログイン画面やログイン切れのときに、WordPress のログイン画面が出ずに自動で入り直して元の画面へ戻るかを確かめる
import { createRequire } from 'node:module';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const port = process.argv[2] || '9556';
const browser = await chromium.connectOverCDP(`http://127.0.0.1:${port}`);
const ctx = browser.contexts()[0];
const page = ctx.pages().find(p => p.url().startsWith('http://127.0.0.1:'));
const base = new URL(page.url()).origin;
const sawLogin = { v: false };
page.on('framenavigated', f => { if (f === page.mainFrame() && /wp-login\.php/.test(f.url())) sawLogin.v = true; });
const settle = async () => { await page.waitForTimeout(3500); await page.waitForLoadState('networkidle').catch(() => {}); };
const state = async () => ({ url: page.url().replace(base, ''), app: await page.locator('.ktp_plugin_container').count() > 0, admin: await page.locator('#adminmenu').count() > 0, wpLogin: await page.locator('#loginform').count() > 0 });
const dropAuth = async () => {
  const cdp = await ctx.newCDPSession(page);
  const { cookies } = await cdp.send('Network.getAllCookies');
  for (const c of cookies.filter(c => /^wordpress_/.test(c.name))) await cdp.send('Network.deleteCookies', { name: c.name, domain: c.domain, path: c.path });
};
for (const [label, prep, url] of [
  ['ログイン画面を直接開く', null, '/wp-login.php'],
  ['Cookie を消して顧客タブ', dropAuth, '/?tab_name=client'],
  ['Cookie を消して設定画面', dropAuth, '/wp-admin/admin.php?page=ktp-settings'],
]) {
  sawLogin.v = false;
  if (prep) await prep();
  await page.goto(base + url, { waitUntil: 'commit' }).catch(() => {});
  await settle();
  const s = await state();
  console.log(`${label}: 着いた先=${s.url.slice(0, 70)} アプリ画面=${s.app} 管理画面=${s.admin} WPログイン画面=${s.wpLogin || sawLogin.v}`);
}
await browser.close();
