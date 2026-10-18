/**
 * 一括請求書で「請求済」にした直後に、取り消しの案内を出す。
 * 本体の XMLHttpRequest（ktp_set_invoice_completed）の完了を見て、画面下に帯を出す。
 */
(function () {
	'use strict';
	if (typeof XMLHttpRequest === 'undefined') {
		return;
	}
	var origSend = XMLHttpRequest.prototype.send;
	XMLHttpRequest.prototype.send = function (body) {
		var xhr = this;
		if (typeof body === 'string' && body.indexOf('action=ktp_set_invoice_completed') !== -1) {
			var m = body.match(/client_id=(\d+)/);
			var clientId = m ? m[1] : '';
			xhr.addEventListener('load', function () {
				try {
					var res = JSON.parse(xhr.responseText);
					if (res && res.success && res.data && res.data.updated > 0) {
						showBar(clientId, res.data.updated);
					}
				} catch (e) { /* ignore */ }
			});
		}
		return origSend.apply(this, arguments);
	};

	function showBar(clientId, count) {
		var old = document.getElementById('ktp-dt-invoice-undo');
		if (old) {
			old.remove();
		}
		var bar = document.createElement('div');
		bar.id = 'ktp-dt-invoice-undo';
		bar.setAttribute('role', 'status');
		bar.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:100002;background:#111827;color:#f9fafb;padding:10px 16px;border-radius:8px;font-size:14px;box-shadow:0 4px 16px rgba(0,0,0,.3);display:flex;gap:14px;align-items:center;';
		var text = document.createElement('span');
		text.textContent = count + '件の受注書を「請求済」にしました。';
		var undo = document.createElement('button');
		undo.type = 'button';
		undo.textContent = '取り消す';
		undo.style.cssText = 'background:#fff;color:#111827;border:0;border-radius:4px;padding:4px 12px;cursor:pointer;font-size:13px;';
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute('aria-label', '閉じる');
		close.style.cssText = 'background:transparent;color:#9ca3af;border:0;cursor:pointer;font-size:16px;';
		close.addEventListener('click', function () { bar.remove(); });
		undo.addEventListener('click', function () {
			undo.disabled = true;
			var nonce = (typeof ktpClientInvoice !== 'undefined' && ktpClientInvoice.nonce) ? ktpClientInvoice.nonce : '';
			var body = new URLSearchParams({ action: 'ktp_dt_unmark_invoiced', client_id: clientId, nonce: nonce });
			fetch(window.ktpDtInvoiceUndo.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (json && json.success) {
						text.textContent = json.data.reverted + '件を「完了」に戻しました。';
						undo.remove();
						setTimeout(function () { window.location.reload(); }, 1200);
					} else {
						text.textContent = (json && json.data && json.data.message) || '取り消せませんでした。';
						undo.remove();
					}
				})
				.catch(function () { text.textContent = '通信に失敗しました。'; undo.remove(); });
		});
		bar.appendChild(text);
		bar.appendChild(undo);
		bar.appendChild(close);
		document.body.appendChild(bar);
	}
})();
