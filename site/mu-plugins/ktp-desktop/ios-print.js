/*
 * iPad・iPhone（LAN 共有で使うとき）の印刷。
 * iOS の Safari は、隠し iframe の print() だと白紙や画面全体の印刷になる。そこで iframe の print() を横取りして、
 * 書類を PDF にし、「共有・印刷」ボタン（共有シート → プリント / ファイルに保存）を出す。PC・親機の Mac では何もしない。
 * 一括請求書は EX 側がすでに PDF の別経路を持っているので、ここは通らない。
 */
(function () {
	'use strict';
	var ua = navigator.userAgent || '';
	var isIos = /iPad|iPhone|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	if (!isIos || !window.ktpIosPrint) { return; }
	var cfg = window.ktpIosPrint;

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src; s.onload = resolve; s.onerror = function () { reject(new Error('読み込めません: ' + src)); };
			document.head.appendChild(s);
		});
	}
	var libs = null;
	function ensureLibs() {
		if (libs) { return libs; }
		libs = Promise.resolve()
			.then(function () { return window.html2canvas ? null : loadScript(cfg.html2canvas); })
			.then(function () { return window.jspdf || window.jsPDF ? null : loadScript(cfg.jspdf); });
		libs.catch(function () { libs = null; });
		return libs;
	}

	function waitImages(doc) {
		var imgs = [].slice.call(doc.images || []);
		return Promise.all(imgs.map(function (im) {
			return im.complete ? null : new Promise(function (r) { im.onload = im.onerror = r; setTimeout(r, 4000); });
		}));
	}

	// 印刷用の iframe の中身を、A4 の幅（794px）の見えない iframe に写して描画する（元の iframe は 1px などで崩れていることがある）
	function renderToPdf(srcDoc) {
		var title = (srcDoc.title || '書類').replace(/[\\/:*?"<>|]/g, '_').slice(0, 60) || '書類';
		var html = '<!doctype html>' + srcDoc.documentElement.outerHTML;
		var f = document.createElement('iframe');
		f.setAttribute('aria-hidden', 'true');
		f.style.cssText = 'position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0;visibility:hidden;';
		document.body.appendChild(f);
		var d = f.contentDocument;
		d.open(); d.write(html); d.close();
		var st = d.createElement('style');
		st.textContent = 'html,body{width:794px!important;margin:0!important;background:#fff!important}.no-print,.pdf-instructions{display:none!important}';
		d.head.appendChild(st);
		var clean = function () { try { document.body.removeChild(f); } catch (e) {} };
		return ensureLibs()
			.then(function () { return new Promise(function (r) { setTimeout(r, 400); }); })
			.then(function () { return waitImages(d); })
			.then(function () { return d.fonts && d.fonts.ready ? d.fonts.ready : null; })
			.then(function () {
				var body = d.body;
				return window.html2canvas(body, { scale: 1.5, useCORS: true, backgroundColor: '#fff', width: 794, windowWidth: 794, height: Math.max(body.scrollHeight, 1123), windowHeight: Math.max(body.scrollHeight, 1123) });
			})
			.then(function (canvas) {
				var JsPDF = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;
				var pdf = new JsPDF({ unit: 'mm', format: 'a4', orientation: 'p' });
				var pageH = Math.floor(canvas.width * 297 / 210);
				var pages = Math.max(1, Math.ceil(canvas.height / pageH));
				for (var i = 0; i < pages; i++) {
					var h = Math.min(pageH, canvas.height - i * pageH);
					var c = document.createElement('canvas');
					c.width = canvas.width; c.height = pageH;
					var ctx = c.getContext('2d');
					ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
					ctx.drawImage(canvas, 0, i * pageH, canvas.width, h, 0, 0, canvas.width, h);
					if (i > 0) { pdf.addPage('a4', 'p'); }
					pdf.addImage(c.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 210, 297);
				}
				clean();
				return { blob: pdf.output('blob'), name: title + '.pdf', pages: pages };
			})
			.catch(function (e) { clean(); throw e; });
	}

	// 共有シートは「タップの直後」でないと開けないので、できあがったらボタンを出して押してもらう
	function showDialog(result, error) {
		var old = document.getElementById('ktp-ios-print'); if (old) { old.remove(); }
		var wrap = document.createElement('div');
		wrap.id = 'ktp-ios-print';
		wrap.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;font-size:16px;line-height:1.6;';
		var box = document.createElement('div');
		box.style.cssText = 'background:#fff;color:#222;border-radius:12px;padding:20px 22px;max-width:420px;width:100%;box-shadow:0 8px 30px rgba(0,0,0,.3);';
		var btn = 'display:block;width:100%;min-height:48px;margin:10px 0 0;border:0;border-radius:8px;font-size:17px;font-weight:700;';
		if (error) {
			box.innerHTML = '<p style="margin:0 0 8px;font-weight:700;">PDF を作れませんでした</p><p style="margin:0;color:#6b7280;font-size:14px;">もう一度試しても同じときは、親機の画面で印刷してください。</p><button type="button" data-x style="' + btn + 'background:#e5e7eb;color:#222;">閉じる</button>';
		} else {
			box.innerHTML = '<p style="margin:0 0 4px;font-weight:700;">PDF ができました（' + result.pages + ' ページ）</p><p style="margin:0;color:#6b7280;font-size:14px;">「共有・印刷」から、プリント（AirPrint）やファイルへの保存ができます。</p>' +
				'<button type="button" data-share style="' + btn + 'background:#2563eb;color:#fff;">共有・印刷</button>' +
				'<button type="button" data-open style="' + btn + 'background:#e5e7eb;color:#222;">画面で開く</button>' +
				'<button type="button" data-x style="' + btn + 'background:transparent;color:#6b7280;font-weight:400;">閉じる</button>';
		}
		wrap.appendChild(box); document.body.appendChild(wrap);
		var url = null;
		var close = function () { wrap.remove(); if (url) { setTimeout(function () { URL.revokeObjectURL(url); }, 60000); } };
		box.querySelector('[data-x]').onclick = close;
		if (error) { return; }
		url = URL.createObjectURL(result.blob);
		box.querySelector('[data-share]').onclick = function () {
			var file = new File([result.blob], result.name, { type: 'application/pdf' });
			if (navigator.share && (!navigator.canShare || navigator.canShare({ files: [file] }))) {
				navigator.share({ files: [file] }).catch(function () {});
			} else {
				window.open(url, '_blank');
			}
		};
		box.querySelector('[data-open]').onclick = function () { window.open(url, '_blank'); };
	}

	function showBusy() {
		var el = document.createElement('div');
		el.id = 'ktp-ios-print-busy';
		el.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;color:#374151;';
		el.textContent = 'PDF を作っています…';
		document.body.appendChild(el);
		return function () { el.remove(); };
	}

	var busy = false;
	function printFrame(frame) {
		if (busy) { return; }
		var doc = null;
		try { doc = frame.contentDocument; } catch (e) {}
		if (!doc || !doc.documentElement) { return; }
		busy = true;
		var done = showBusy();
		renderToPdf(doc)
			.then(function (r) { done(); busy = false; showDialog(r, false); })
			.catch(function (e) { done(); busy = false; if (window.console) { console.error('[ktp-ios-print]', e); } showDialog(null, true); });
	}

	// 隠し iframe の contentWindow を取り出したときに、その print() を PDF 作成に差し替える
	var desc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'contentWindow');
	if (desc && desc.get) {
		Object.defineProperty(HTMLIFrameElement.prototype, 'contentWindow', {
			configurable: true,
			get: function () {
				var w = desc.get.call(this);
				try {
					if (w && !w.__ktpIosPatched && this.id !== 'ktp-ios-print-render') {
						var self = this;
						w.print = function () { printFrame(self); };
						w.__ktpIosPatched = true;
					}
				} catch (e) {}
				return w;
			}
		});
	}
})();
