/**
 * 受注書の Web-FAX 送信。書類（プレビュー HTML）を画面で PDF にして、サーバーに渡してゲートウェイへメール送信する。
 */
(function () {
	'use strict';
	var cfg = window.ktpDtFax;
	if (!cfg) {
		return;
	}

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = function () { reject(new Error('読み込めませんでした: ' + src)); };
			document.head.appendChild(s);
		});
	}

	function ensureLibs() {
		var p = Promise.resolve();
		if (typeof window.html2canvas === 'undefined') {
			p = p.then(function () { return loadScript(cfg.html2canvas); });
		}
		if (typeof window.jspdf === 'undefined' && typeof window.jsPDF === 'undefined') {
			p = p.then(function () { return loadScript(cfg.jspdf); });
		}
		return p.then(function () {
			return window.jsPDF || (window.jspdf && window.jspdf.jsPDF);
		});
	}

	function post(data) {
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams(data).toString()
		}).then(function (r) { return r.json(); });
	}

	function previewNonce() {
		if (typeof window.ktp_ajax_nonce !== 'undefined') {
			return window.ktp_ajax_nonce;
		}
		return (window.ktp_ajax_object && window.ktp_ajax_object.nonce) || (window.ktp_ajax && window.ktp_ajax.nonce) || '';
	}

	function fetchPreview(orderId) {
		return post({ action: 'ktp_get_order_preview', order_id: orderId, nonce: previewNonce() }).then(function (res) {
			if (res && res.success && res.data && res.data.preview_html) {
				return res.data;
			}
			throw new Error('書類を作れませんでした。');
		});
	}

	/** プレビュー HTML → PDF（base64）。A4 縦、複数ページに分ける */
	function buildPdf(html, JsPDF) {
		var el = document.createElement('div');
		el.innerHTML = html;
		el.style.cssText = 'position:fixed;left:-9999px;top:0;width:210mm;background:#fff;font-family:"Hiragino Kaku Gothic ProN",Meiryo,sans-serif;font-size:12px;line-height:1.4;color:#333;';
		document.body.appendChild(el);
		return window.html2canvas(el, { scale: 2, useCORS: true, allowTaint: true, backgroundColor: '#ffffff', width: el.scrollWidth, height: el.scrollHeight }).then(function (canvas) {
			document.body.removeChild(el);
			var img = canvas.toDataURL('image/jpeg', 0.85);
			var pdf = new JsPDF('p', 'mm', 'a4');
			var imgW = 210, pageH = 297;
			var imgH = (canvas.height * imgW) / canvas.width;
			var left = imgH, pos = 0;
			pdf.addImage(img, 'JPEG', 0, pos, imgW, imgH);
			left -= pageH;
			while (left > 1) {
				pos = left - imgH;
				pdf.addPage();
				pdf.addImage(img, 'JPEG', 0, pos, imgW, imgH);
				left -= pageH;
			}
			var uri = pdf.output('datauristring');
			return uri.substring(uri.indexOf('base64,') + 7);
		}).catch(function (e) {
			if (el.parentNode) {
				el.parentNode.removeChild(el);
			}
			throw e;
		});
	}

	function modal(orderId) {
		var old = document.getElementById('ktp-dt-fax-modal');
		if (old) {
			old.remove();
		}
		var wrap = document.createElement('div');
		wrap.id = 'ktp-dt-fax-modal';
		wrap.style.cssText = 'position:fixed;inset:0;z-index:100003;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;';
		wrap.innerHTML = '<div role="dialog" aria-modal="true" style="background:#fff;border-radius:10px;padding:18px 22px;width:min(440px,92vw);font-size:14px;line-height:1.7;box-shadow:0 8px 32px rgba(0,0,0,.3);">'
			+ '<h3 style="margin:0 0 8px;font-size:16px;">Web-FAX で送る（受注書 #' + orderId + '）</h3>'
			+ '<p style="margin:0 0 8px;color:#6b7280;font-size:12px;">いま画面に出ている受注書の進捗に合わせた書類（見積書・受注書・請求書など）を、FAX で送ります。</p>'
			+ '<label style="display:block;margin:6px 0;">FAX番号<br><input type="tel" id="ktp-dt-fax-no" style="width:100%;padding:6px;font-size:14px;" placeholder="03-1234-5678"></label>'
			+ '<label style="display:block;margin:6px 0;">宛先名（MOVFAX のみ使用・任意）<br><input type="text" id="ktp-dt-fax-name" style="width:100%;padding:6px;font-size:14px;"></label>'
			+ '<p id="ktp-dt-fax-msg" role="status" style="margin:8px 0;font-weight:600;min-height:1.5em;"></p>'
			+ '<div style="display:flex;gap:8px;justify-content:flex-end;"><button type="button" id="ktp-dt-fax-cancel">閉じる</button><button type="button" id="ktp-dt-fax-go" style="background:#2563eb;color:#fff;border:0;padding:6px 18px;border-radius:4px;">送信する</button></div></div>';
		document.body.appendChild(wrap);
		var msg = wrap.querySelector('#ktp-dt-fax-msg');
		var go = wrap.querySelector('#ktp-dt-fax-go');
		wrap.querySelector('#ktp-dt-fax-cancel').onclick = function () { wrap.remove(); };
		go.onclick = function () {
			var no = wrap.querySelector('#ktp-dt-fax-no').value.trim();
			if (!no) {
				msg.style.color = '#b91c1c';
				msg.textContent = 'FAX番号を入力してください。';
				return;
			}
			if (!window.confirm('FAX番号 ' + no + ' へ送信します。よろしいですか？')) {
				return;
			}
			go.disabled = true;
			msg.style.color = '';
			msg.textContent = '書類を作っています…';
			var title = '';
			Promise.all([ensureLibs(), fetchPreview(orderId)]).then(function (r) {
				title = r[1].document_title || '受注書';
				return buildPdf(r[1].preview_html, r[0]);
			}).then(function (pdf) {
				msg.textContent = '送信しています…';
				return post({ action: 'ktp_dt_fax_send', nonce: cfg.nonce, fax_number: no, recipient_name: wrap.querySelector('#ktp-dt-fax-name').value, filename: title + '_' + orderId + '.pdf', pdf: pdf });
			}).then(function (res) {
				if (res && res.success) {
					msg.style.color = '#166534';
					msg.textContent = res.data.message;
				} else {
					msg.style.color = '#b91c1c';
					msg.textContent = (res && res.data && res.data.message) || '送信できませんでした。';
					go.disabled = false;
				}
			}).catch(function (e) {
				msg.style.color = '#b91c1c';
				msg.textContent = String(e && e.message ? e.message : e);
				go.disabled = false;
			});
		};
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('#ktpDtFaxButton') : null;
		if (!btn) {
			return;
		}
		e.preventDefault();
		if (!cfg.configured) {
			if (window.confirm('Web-FAX の設定がまだ完了していません。設定画面を開きますか？\n（先にメール（SMTP）の設定が要ります）')) {
				window.location.href = cfg.settingsUrl;
			}
			return;
		}
		modal(btn.dataset.orderId);
	});
})();
