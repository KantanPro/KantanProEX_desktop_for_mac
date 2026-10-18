/**
 * 受注書のタスクリスト（追加・完了・担当者・削除・並べ替え・工程表のドラッグ）。
 * 操作のたびにサーバーがセクション全体のHTMLを返すので、それで差し替える。
 */
(function () {
	'use strict';

	var MS_PER_DAY = 86400000;
	var drag = null;
	var tooltip = null;

	function section() {
		return document.getElementById('ktp-order-tasks');
	}

	function parseYmd(s) {
		var p = String(s).split('-').map(Number);
		return new Date(p[0], p[1] - 1, p[2]);
	}
	function fmtYmd(d) {
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function daysBetween(a, b) {
		return Math.round((parseYmd(b) - parseYmd(a)) / MS_PER_DAY);
	}
	function addDays(s, n) {
		var d = parseYmd(s);
		d.setDate(d.getDate() + n);
		return fmtYmd(d);
	}
	function clamp(v, lo, hi) {
		return Math.max(lo, Math.min(hi, v));
	}

	function showError(sec, msg) {
		var el = sec.querySelector('.ktp-tasks-error');
		if (!el) {
			window.alert(msg);
			return;
		}
		el.textContent = msg;
		el.hidden = !msg;
	}

	function post(sec, action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', sec.dataset.nonce);
		body.set('order_id', sec.dataset.ktpOrderId);
		Object.keys(data || {}).forEach(function (k) {
			var v = data[k];
			if (Array.isArray(v)) {
				v.forEach(function (x) { body.append(k + '[]', x); });
			} else {
				body.set(k, v);
			}
		});
		return fetch(sec.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () { return { success: false, data: { message: '通信に失敗しました。' } }; });
		}).then(function (json) {
			if (json && json.success && json.data && json.data.html) {
				replaceSection(sec, json.data.html);
				return true;
			}
			var msg = (json && json.data && json.data.message) || '保存できませんでした。';
			showError(sec, msg);
			return false;
		}).catch(function () {
			showError(sec, '通信に失敗しました。');
			return false;
		});
	}

	function replaceSection(sec, html) {
		var wasOpen = sec.open;
		var tmp = document.createElement('div');
		tmp.innerHTML = html;
		var next = tmp.firstElementChild;
		if (!next) {
			return;
		}
		next.open = wasOpen;
		// 開閉の記憶（ktp-js.js）は <details> ごとに付くので、差し替え後も同じ Cookie を覚えさせる
		next.addEventListener('toggle', function () {
			try {
				var key = 'ktp_order_section_order_tasks_' + next.dataset.ktpOrderId;
				document.cookie = key + '=' + (next.open ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
			} catch (e) { /* ignore */ }
		});
		sec.replaceWith(next);
	}

	// 開閉の記憶（ktp-js.js と同じ Cookie 名）
	function restoreOpenState() {
		var sec = section();
		if (!sec || sec.dataset.ktpTasksInit === '1') {
			return;
		}
		sec.dataset.ktpTasksInit = '1';
		var m = document.cookie.match(new RegExp('(?:^|; )ktp_order_section_order_tasks_' + sec.dataset.ktpOrderId + '=([^;]*)'));
		if (m) {
			sec.open = m[1] === '1';
		}
		sec.addEventListener('toggle', function () {
			document.cookie = 'ktp_order_section_order_tasks_' + sec.dataset.ktpOrderId + '=' + (sec.open ? '1' : '0') + ';path=/;max-age=31536000;SameSite=Lax';
		});
	}

	// --- クリック・変更 ---
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('#ktp-order-tasks [data-act]') : null;
		if (!btn) {
			return;
		}
		var sec = section();
		var row = btn.closest('.ktp-tasks-row');
		var act = btn.dataset.act;
		if (act === 'add') {
			addTask(sec);
		} else if (act === 'delete' && row) {
			if (window.confirm('このタスクを削除しますか？')) {
				post(sec, 'ktp_dt_task_delete', { task_id: row.dataset.taskId });
			}
		}
	});

	document.addEventListener('change', function (e) {
		var el = e.target;
		if (!el.closest || !el.closest('#ktp-order-tasks')) {
			return;
		}
		var sec = section();
		var row = el.closest('.ktp-tasks-row');
		if (el.dataset.act === 'toggle' && row) {
			post(sec, 'ktp_dt_task_toggle', { task_id: row.dataset.taskId, completed: el.checked ? '1' : '0' });
		} else if (el.dataset.act === 'assignee' && row) {
			post(sec, 'ktp_dt_task_assignee', { task_id: row.dataset.taskId, assignee_user_id: el.value });
		}
	});

	// 追加フォームで Enter を押したら追加
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.closest && e.target.closest('#ktp-order-tasks .ktp-tasks-add')) {
			e.preventDefault();
			addTask(section());
		}
	});

	function addTask(sec) {
		var data = {};
		sec.querySelectorAll('.ktp-tasks-add [data-field]').forEach(function (el) {
			data[el.dataset.field] = el.value;
		});
		post(sec, 'ktp_dt_task_add', data);
	}

	// --- 工程表のドラッグ ---
	function ensureTooltip() {
		if (!tooltip) {
			tooltip = document.createElement('div');
			tooltip.className = 'ktp-tasks-tooltip';
			tooltip.hidden = true;
			document.body.appendChild(tooltip);
		}
		return tooltip;
	}
	function showTip(text, x, y) {
		var t = ensureTooltip();
		t.textContent = text;
		t.hidden = false;
		t.style.left = x + 'px';
		t.style.top = y + 'px';
	}
	function hideTip() {
		if (tooltip) {
			tooltip.hidden = true;
		}
	}

	function applyGeometry(d) {
		var left = clamp(daysBetween(d.rangeStart, d.start) / d.total * 100, 0, 100);
		var right = clamp(daysBetween(d.rangeStart, d.due) / d.total * 100, 0, 100);
		d.bar.style.left = left + '%';
		d.bar.style.width = Math.max(0.8, right - left) + '%';
		var av = d.chart.querySelector('[data-avatar-for="' + d.bar.dataset.taskId + '"]');
		if (av) {
			av.style.left = left + '%';
		}
	}

	function dateFromX(d, clientX) {
		var rect = d.track.getBoundingClientRect();
		var pct = rect.width > 0 ? clamp(clientX - rect.left, 0, rect.width) / rect.width * 100 : 0;
		return addDays(d.rangeStart, Math.round(pct / 100 * d.total));
	}

	document.addEventListener('pointerdown', function (e) {
		var sec = section();
		if (!sec || sec.dataset.editable !== '1' || !e.target.closest) {
			return;
		}
		var bar = e.target.closest('#ktp-order-tasks .ktp-tasks-bar.is-editable');
		if (!bar) {
			return;
		}
		var handleEl = e.target.closest('.ktp-tasks-handle');
		var chart = bar.closest('.ktp-tasks-chart');
		e.preventDefault();
		drag = {
			sec: sec,
			bar: bar,
			chart: chart,
			track: bar.closest('.work-list-schedule-track'),
			type: handleEl ? handleEl.dataset.handle : 'move',
			el: handleEl || bar,
			rangeStart: chart.dataset.rangeStart,
			total: Number(chart.dataset.totalDays) || 1,
			start: bar.dataset.start,
			due: bar.dataset.due,
			initStart: bar.dataset.start,
			initDue: bar.dataset.due,
			grab: e.clientX - bar.getBoundingClientRect().left,
			duration: daysBetween(bar.dataset.start, bar.dataset.due)
		};
		bar.classList.add('is-dragging');
		try { drag.el.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
		showTip(drag.start + ' 〜 ' + drag.due, e.clientX, e.clientY);
	});

	document.addEventListener('pointermove', function (e) {
		if (!drag) {
			return;
		}
		if (drag.type === 'move') {
			var rect = drag.track.getBoundingClientRect();
			var x = clamp(e.clientX - drag.grab - rect.left, 0, rect.width);
			var pct = rect.width > 0 ? x / rect.width * 100 : 0;
			drag.start = addDays(drag.rangeStart, Math.round(pct / 100 * drag.total));
			drag.due = addDays(drag.start, drag.duration);
		} else if (drag.type === 'start') {
			var c = dateFromX(drag, e.clientX);
			drag.start = c <= drag.due ? c : drag.due;
		} else {
			var c2 = dateFromX(drag, e.clientX);
			drag.due = c2 >= drag.start ? c2 : drag.start;
		}
		applyGeometry(drag);
		showTip(drag.start + ' 〜 ' + drag.due, e.clientX, e.clientY);
	});

	function endDrag() {
		if (!drag) {
			return;
		}
		var d = drag;
		drag = null;
		d.bar.classList.remove('is-dragging');
		hideTip();
		if (d.start === d.initStart && d.due === d.initDue) {
			return;
		}
		post(d.sec, 'ktp_dt_task_dates', { task_id: d.bar.dataset.taskId, start_date: d.start, due_date: d.due }).then(function (ok) {
			if (!ok) {
				d.start = d.initStart;
				d.due = d.initDue;
				applyGeometry(d);
			}
		});
	}
	document.addEventListener('pointerup', endDrag);
	document.addEventListener('pointercancel', endDrag);

	// --- 一覧の並べ替え（≡ をドラッグ） ---
	var draggedRow = null;
	document.addEventListener('dragstart', function (e) {
		var h = e.target.closest ? e.target.closest('#ktp-order-tasks .ktp-tasks-drag') : null;
		if (!h) {
			return;
		}
		draggedRow = h.closest('.ktp-tasks-row');
		draggedRow.classList.add('is-dragging');
		if (e.dataTransfer) {
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData('text/plain', 'drag'); } catch (err) { /* ignore */ }
		}
	});
	document.addEventListener('dragover', function (e) {
		if (!draggedRow) {
			return;
		}
		var tbody = draggedRow.parentElement;
		if (!tbody || !tbody.contains(e.target)) {
			return;
		}
		e.preventDefault();
		var rows = Array.prototype.filter.call(tbody.querySelectorAll('.ktp-tasks-row'), function (r) { return r !== draggedRow; });
		var before = null;
		for (var i = 0; i < rows.length; i++) {
			var rect = rows[i].getBoundingClientRect();
			if (e.clientY < rect.top + rect.height / 2) {
				before = rows[i];
				break;
			}
		}
		tbody.insertBefore(draggedRow, before);
	});
	document.addEventListener('dragend', function () {
		if (!draggedRow) {
			return;
		}
		var row = draggedRow;
		draggedRow = null;
		row.classList.remove('is-dragging');
		var sec = section();
		if (!sec) {
			return;
		}
		var ids = Array.prototype.map.call(sec.querySelectorAll('#ktp-tasks-tbody .ktp-tasks-row'), function (r) { return r.dataset.taskId; });
		post(sec, 'ktp_dt_task_reorder', { task_ids: ids });
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', restoreOpenState);
	} else {
		restoreOpenState();
	}
})();
