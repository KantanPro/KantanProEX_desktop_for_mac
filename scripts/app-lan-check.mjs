// LAN 共有の確認。LAN 共有をオンにしたテスト用データのアプリ（KTP_TEST_USER_DATA に lan.json {enabled:true,port:47330}）に対して、
// 別のブラウザから窓口の https につなぎ、ログインの制限・拒否する画面・同時アクセスを確かめる。
// 前提: work/lan-wp.sh でスタッフを作ってある（staff1=許可, staff2=未許可, kantanpro=管理者）。
// 使い方: node scripts/app-lan-check.mjs <CDPポート 9557> <LANポート 47330> <PHPのポート 47321>
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const [cdp = '9557', lanPort = '47330', phpPort = '47321'] = process.argv.slice(2);
const BASE = `https://127.0.0.1:${lanPort}`;
const PASS = 'TestPass12345';
let bad = 0;
const ok = (c, m) => { console.log(`${c ? '✓' : '✗ NG'} ${m}`); if (!c) bad++; };

const browser = await chromium.launch({ headless: true });
const newCtx = () => browser.newContext({ ignoreHTTPSErrors: true });

async function tryLogin(ctx, user, pass) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 15000 });
  await page.waitForTimeout(1500); // wp_attempt_focus がパスワード欄を空にするのを待つ
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
  await page.waitForTimeout(1500);
  return page;
}

// 1. 未ログインはログイン画面。ロゴ・「パスワードを忘れた」は隠れている
{
  const ctx = await newCtx();
  const page = await ctx.newPage();
  await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#user_login', { timeout: 15000 });
  ok(/wp-login\.php/.test(page.url()), `未ログインはログイン画面へ (${page.url().slice(0, 60)})`);
  ok(page.url().startsWith('https://'), '転送先も https');
  ok(!(await page.locator('#nav').isVisible()), '「パスワードを忘れた」は隠れている');
  const t = await page.textContent('body');
  ok(/KantanProEX ログイン/.test(t), 'ログイン画面の見出し');
  const api = await ctx.request.get(`${BASE}/?rest_route=/wp/v2/users`, { maxRedirects: 0 });
  ok(api.status() === 401, `未ログインの REST ユーザー一覧は 401 (${api.status()})`);
  const auto = await ctx.request.get(`${BASE}/?ktp_desktop_login=${'a'.repeat(40)}`, { maxRedirects: 0 });
  ok(auto.status() === 403, `自動ログイン URL は 403 (${auto.status()})`);
  const xml = await ctx.request.get(`${BASE}/xmlrpc.php`);
  ok(xml.status() === 403, `xmlrpc は 403 (${xml.status()})`);
  const cron = await ctx.request.get(`${BASE}/wp-cron.php`);
  ok(cron.status() === 403, `wp-cron は 403 (${cron.status()})`);
  const cfg = await ctx.request.get(`${BASE}/wp-config.php`);
  ok(cfg.status() === 403 || cfg.status() === 404, `wp-config は見えない (${cfg.status()})`);
  await ctx.close();
}

// 2. 管理者・未許可は入れない（メッセージは同じ）
{
  const ctx = await newCtx();
  const p1 = await tryLogin(ctx, 'kantanpro', 'AdminPass12345');
  const e1 = (await p1.textContent('#login_error').catch(() => '')) || '';
  ok(/wp-login/.test(p1.url()) && /ログイン名またはパスワードが違います/.test(e1), `管理者は LAN から入れない (${e1.trim().slice(0, 40)})`);
  const p2 = await tryLogin(ctx, 'staff2', PASS);
  const e2 = (await p2.textContent('#login_error').catch(() => '')) || '';
  ok(/wp-login/.test(p2.url()) && /ログイン名またはパスワードが違います/.test(e2), '未許可のスタッフは入れない');
  const p3 = await tryLogin(ctx, 'nobody', 'x');
  const e3 = (await p3.textContent('#login_error').catch(() => '')) || '';
  ok(e3.trim() === e1.trim(), '存在しないユーザーも同じメッセージ');
  await ctx.close();
}

// 3. 許可したスタッフは入れて、業務画面が使える。設定・管理画面は開けない
const ctx = await newCtx();
{
  const page = await tryLogin(ctx, 'staff1', PASS);
  ok(!/wp-login/.test(page.url()), `許可したスタッフはログインできる (${page.url().slice(0, 60)})`);
  // 利用規約の同意が出ていれば同意
  await page.waitForTimeout(2500);
  const agreed = await page.evaluate(`(function(){var c=document.querySelector('#ktpwp-terms-dialog input[type=checkbox]');if(!c)return false;c.checked=true;c.dispatchEvent(new Event('change',{bubbles:true}));var b=[].slice.call(document.querySelectorAll('#ktpwp-terms-dialog button')).find(function(x){return x.textContent.indexOf('利用開始する')>=0});if(b)b.click();return true;})()`).catch(() => false);
  if (agreed) await page.waitForTimeout(3000);
  ok(await page.locator('.ktp_plugin_container').count() > 0, '業務画面が表示される');
  const logout = await page.locator('a[href*="wp-login.php?action=logout"]').count();
  const quit = await page.locator('a[href*="ktp_desktop_quit"]').count();
  ok(logout > 0 && quit === 0, `ログアウトは普通のログアウト (logout=${logout} quit=${quit})`);
  const opt = await ctx.request.get(`${BASE}/wp-admin/options-general.php`);
  ok(opt.status() === 403, `WordPress の設定は開けない (${opt.status()})`);
  const kset = await ctx.request.get(`${BASE}/wp-admin/admin.php?page=ktp-settings`);
  ok(kset.status() === 403, `KantanProEX の設定は開けない (${kset.status()})`);
  const prof = await ctx.request.get(`${BASE}/wp-admin/profile.php`);
  ok(prof.status() === 200 && /新しいパスワード|アカウント管理/.test(await prof.text()), `自分のプロフィールは開ける (${prof.status()})`);
  const lanpage = await page.goto(`${BASE}/?ktp_dt_page=lan`, { waitUntil: 'domcontentloaded' });
  ok(!(await page.locator('#ktp-dt-lan').count()), 'LAN 共有の設定画面は LAN から出ない');
  // 受注書の画面
  await page.goto(`${BASE}/?tab_name=list`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
  ok(await page.locator('.ktp_plugin_container').count() > 0 && !/Fatal error|WordPress database error/.test(await page.textContent('body')), '受注一覧が開く');
  // 同時アクセス（親機と LAN から 20 本ずつ）で DB ロックが出ない
  const urls = Array.from({ length: 20 }, (_, i) => `/?tab_name=${['list', 'client', 'service', 'report'][i % 4]}`);
  const res = await Promise.all(urls.map((u) => ctx.request.get(`${BASE}${u}`)));
  const texts = await Promise.all(res.map((r) => r.text()));
  ok(res.every((r) => r.status() === 200) && !texts.some((t) => /database is locked|Fatal error|WordPress database error/.test(t)), `LAN から同時 20 本が全部 200・DB エラーなし`);
}

// 4. 親機の画面（CDP）は今までどおり。設定画面が出る
{
  const b2 = await chromium.connectOverCDP(`http://127.0.0.1:${cdp}`);
  const pg = b2.contexts()[0].pages().find((p) => p.url().startsWith('http://127.0.0.1:'));
  const base = new URL(pg.url()).origin;
  ok(!/wp-login|relogin/.test(pg.url()), `親機は自動ログインのまま (${pg.url().slice(0, 50)})`);
  await pg.goto(`${base}/?ktp_dt_page=lan`, { waitUntil: 'domcontentloaded' }).catch(async () => { await pg.waitForTimeout(2000); await pg.goto(`${base}/?ktp_dt_page=lan`, { waitUntil: 'domcontentloaded' }); });
  await pg.waitForTimeout(1500);
  const body = (await pg.textContent('body')) || '';
  ok(/LAN 共有/.test(body) && /オン（他の PC から入れます）/.test(body), '親機の LAN 共有画面: オンと表示');
  ok(/staff1/.test(body) && !/kantanpro<\/code>/.test(body), 'スタッフ一覧に staff1 が出る');
  ok(/証明書の指紋/.test(body), '証明書の指紋が出る');
  const admin = await pg.goto(`${base}/wp-admin/admin.php?page=ktp-settings`, { waitUntil: 'domcontentloaded' });
  ok(admin.status() === 200, '親機は設定を開ける');
  // 親機から 127.0.0.1:PHP に偽の合言葉を付けても LAN 扱いにならない（ログイン画面にならず、親機の入り直しの転送）
  const forged = await fetch(`http://127.0.0.1:${phpPort}/`, { headers: { 'X-KTP-LAN': 'x'.repeat(64), 'X-KTP-Remote': '192.168.1.9' }, redirect: 'manual' });
  const loc = forged.headers.get('location') || '';
  ok(forged.status === 302 && /ktp_desktop_relogin/.test(loc), `偽の合言葉は LAN 扱いにならない (${forged.status} ${loc.slice(0, 60)})`);
  await b2.close().catch(() => {});
}

// 4b. 事務所用の認証局（iPad 用プロファイルと .crt）はログインなしで取れる。オーナー許可（管理者の LAN 利用）
{
  const c0 = await newCtx();
  const prof = await c0.request.get(`${BASE}/ktp-lan/ca.mobileconfig`);
  const profBody = await prof.text();
  ok(prof.status() === 200 && /application\/x-apple-aspen-config/.test(prof.headers()['content-type'] || '') && /com\.apple\.security\.root/.test(profBody) && !/PRIVATE KEY/.test(profBody), '構成プロファイルがログインなしで取れる（秘密鍵は含まない）');
  const crt = await c0.request.get(`${BASE}/ktp-lan/ca.crt`);
  ok(crt.status() === 200 && /x509-ca-cert/.test(crt.headers()['content-type'] || ''), 'CA 証明書 (.crt) が取れる');
  const mu = await c0.request.get(`${BASE}/wp-content/mu-plugins/ktp-desktop/lan.php`);
  ok(mu.status() === 403, `mu-plugins の PHP は見えない (${mu.status()})`);
  const js = await c0.request.get(`${BASE}/wp-content/mu-plugins/ktp-desktop/webfax.js`);
  ok(js.status() === 200, `mu-plugins の JS は読める (${js.status()})`);
  await c0.close();

  const wp = (...a) => execFileSync('bash', ['work/lan-wp.sh', ...a], { encoding: 'utf8' }).trim();
  const adminId = wp('user', 'get', 'kantanpro', '--field=ID');
  // オーナー許可がオフのままなら、メタが付いていても入れない
  wp('user', 'meta', 'update', adminId, 'ktp_dt_lan_allowed', '1');
  wp('user', 'meta', 'update', adminId, 'ktp_dt_lan_pass_set', '1');
  wp('user', 'update', adminId, '--user_pass=AdminPass12345');
  wp('option', 'update', 'ktp_dt_lan_allow_owner', '0');
  const cOff = await newCtx();
  const pOff = await tryLogin(cOff, 'kantanpro', 'AdminPass12345');
  ok(/wp-login/.test(pOff.url()), 'オーナー許可がオフなら、管理者は入れない');
  await cOff.close();
  wp('option', 'update', 'ktp_dt_lan_allow_owner', '1');
  const cOn = await newCtx();
  const pOn = await tryLogin(cOn, 'kantanpro', 'AdminPass12345');
  ok(!/wp-login/.test(pOn.url()), `オーナー許可がオンなら、管理者が入れる (${pOn.url().slice(0, 50)})`);
  await pOn.waitForTimeout(2000);
  const h = await pOn.content();
  ok(/apple-touch-icon/.test(h) && /apple-mobile-web-app-capable/.test(h) && /ktp-desktop-touch/.test(h), 'ホーム画面用のアイコン・タッチ向け調整が入る');
  ok((await cOn.request.get(`${BASE}/wp-admin/options-general.php`)).status() === 403, '管理者でも WordPress の設定は開けない');
  ok((await cOn.request.get(`${BASE}/wp-admin/admin.php?page=ktp-settings`)).status() === 403, '管理者でも KantanProEX の設定は開けない');
  await pOn.goto(`${BASE}/?ktp_dt_page=lan`, { waitUntil: 'domcontentloaded' });
  ok(!(await pOn.locator('#ktp-dt-lan').count()), '管理者でも LAN 共有の設定画面は LAN から出ない');
  for (const act of ['ktpwp_clear_data', 'ktpwp_create_dummy_data', 'ktpwp_export_data', 'ktp_dt_lan_owner_save', 'ktp_dt_fax_settings']) {
    const r = await cOn.request.post(`${BASE}/wp-admin/admin-ajax.php`, { form: { action: act, nonce: 'x' } });
    ok(r.status() === 403, `LAN からの ${act} は 403 (${r.status()})`);
  }
  const ap = await cOn.request.post(`${BASE}/wp-admin/admin-post.php`, { form: { action: 'ktpwp_restore_data' } });
  ok(ap.status() === 403, `LAN からの復元は 403 (${ap.status()})`);
  await cOn.close();
  wp('option', 'update', 'ktp_dt_lan_allow_owner', '0');
}

// 5. 失敗が続くと止まる（最後に実行。止めた後は正しいパスワードでも入れない）
{
  const c = await newCtx();
  for (let i = 0; i < 5; i++) await tryLogin(c, 'staff1', 'wrong-password-' + i).then((p) => p.close());
  const p = await tryLogin(c, 'staff1', PASS);
  const e = (await p.textContent('#login_error').catch(() => '')) || '';
  ok(/wp-login/.test(p.url()) && /しばらく止めています/.test(e), `5 回失敗すると、正しいパスワードでも止まる (${e.trim().slice(0, 30)})`);
  await c.close();
}

await browser.close();
console.log(bad ? `\n${bad} 件 NG` : '\nすべて OK');
process.exit(bad ? 1 : 0);
