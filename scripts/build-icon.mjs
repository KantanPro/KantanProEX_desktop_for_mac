// アプリのアイコン（build/icon.icns）を作る。
// 元画像: assets/icon/kantanpro-mark-1080.png（KantanPro のマーク）を、macOS 標準の角丸の白い台に載せる。
// 使い方: node scripts/build-icon.mjs   （Chrome で描画 → 透過PNG → iconutil）
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
const require = createRequire('/Users/kantanpro/Desktop/PromoStudio/package.json');
const { chromium } = require('playwright');
const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const mark = fs.readFileSync(path.join(root, 'assets/icon/kantanpro-mark-1080.png')).toString('base64');
// macOS のアイコン格子: 1024 の中に 824 の角丸（半径 185）、上下左右 100 の余白
const html = `<!doctype html><html><body style="margin:0;background:transparent">
<div style="position:relative;width:1024px;height:1024px">
  <div style="position:absolute;left:100px;top:96px;width:824px;height:824px;border-radius:185px;background:linear-gradient(#ffffff,#f1f5fb);box-shadow:0 10px 24px rgba(0,0,0,.28);overflow:hidden">
    <img src="data:image/png;base64,${mark}" style="position:absolute;left:50%;top:50%;width:1250px;height:1250px;transform:translate(-50%,-50%)">
  </div>
</div></body></html>`;
const browser = await chromium.launch({ channel: 'chrome' });
const page = await browser.newPage({ viewport: { width: 1024, height: 1024 } });
await page.setContent(html);
await page.waitForTimeout(300);
const png1024 = path.join(root, 'assets/icon/icon-1024.png');
await page.screenshot({ path: png1024, omitBackground: true });

// 起動中の画面用: 台なしでマークだけ（透過）
await page.setViewportSize({ width: 256, height: 256 });
await page.setContent(`<!doctype html><html><body style="margin:0;background:transparent">
<div style="position:relative;width:256px;height:256px;overflow:hidden">
  <img src="data:image/png;base64,${mark}" style="position:absolute;left:50%;top:50%;width:460px;height:460px;transform:translate(-50%,-50%)">
</div></body></html>`);
await page.waitForTimeout(300);
await page.screenshot({ path: path.join(root, 'assets/icon/mark-256.png'), omitBackground: true });
await browser.close();

const set = path.join(root, 'build/icon.iconset');
fs.rmSync(set, { recursive: true, force: true });
fs.mkdirSync(set, { recursive: true });
for (const s of [16, 32, 128, 256, 512]) {
  execFileSync('sips', ['-z', String(s), String(s), png1024, '--out', path.join(set, `icon_${s}x${s}.png`)], { stdio: 'ignore' });
  execFileSync('sips', ['-z', String(s * 2), String(s * 2), png1024, '--out', path.join(set, `icon_${s}x${s}@2x.png`)], { stdio: 'ignore' });
}
execFileSync('iconutil', ['-c', 'icns', set, '-o', path.join(root, 'build/icon.icns')]);
fs.rmSync(set, { recursive: true, force: true });
console.log('build/icon.icns を作りました');
