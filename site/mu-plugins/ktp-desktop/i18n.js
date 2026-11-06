/*
 * 画面の文言を英語にする（英語のときだけ i18n.php が読み込む。辞書は lang/en.js の window.ktpDtI18nDict）。
 *
 * - 文字のかたまり（テキストノード）ごとに、前後の空白を除いた全体が辞書と完全一致したときだけ訳す。
 *   部分一致の置き換えはしない（顧客名・案件名など入力したデータを書き換えないため）
 * - 一致しないときは、末尾の「：」・前後の括弧・先頭の記号（※ ■ など）を外して探し直す。
 *   %s・%d を含む辞書の項目は、数字や名前が入った文にも当てる
 * - 入力欄の値・textarea・contenteditable には触れない。value 属性の無い <option> は、送る値が変わらないよう
 *   元の文字を value に残してから表示だけ訳す。名前（name）付きの送信ボタン（input）の value も触らない
 * - 読み込みながら（MutationObserver）訳すので、あとから JS が出す文言・ajax で入れ替わる部分も訳される
 * - alert / confirm / prompt の文も訳す（行ごとにも探す）
 */
(function () {
	'use strict';
	var D = window.ktpDtI18nDict || {};
	var has = Object.prototype.hasOwnProperty;
	// 改行や字下げを含む項目は、空白をまとめた形でも引けるようにする（画面では空白の入り方が変わることがある）
	Object.keys(D).forEach(function (k) {
		if (/[\s\u3000]{2,}|[\n\t]/.test(k)) {
			var nk = k.replace(/[\s\u3000]+/g, ' ').trim();
			if (!has.call(D, nk)) D[nk] = D[k].replace(/[\s\u3000]+/g, ' ').trim();
		}
	});
	var JA = /[぀-ヿ㐀-鿿]/;
	var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, NOSCRIPT: 1, TEMPLATE: 1 };
	var ATTRS = ['placeholder', 'title', 'aria-label', 'alt', 'data-tooltip', 'data-title'];

	// %s・%d・%1$s を含む項目は正規表現にしておく（日本語を含むものだけ）
	var patterns = [];
	Object.keys(D).forEach(function (k) {
		if (!/%(\d+\$)?[sd]/.test(k) || !JA.test(k)) return;
		var order = [];
		var auto = 0;
		var src = '';
		var re = /%(?:(\d+)\$)?([sd])/g;
		var last = 0;
		var m;
		// 空白はあってもなくてもよい（「3 件」「3件」）
		var lit = function (x) { return x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s*'); };
		// %s の前後には \s* を付けない（.+? と \s* が続くと、長い文で正規表現の照合が終わらなくなる）
		while ((m = re.exec(k))) {
			var before = k.slice(last, m.index);
			if (m[2] === 'd') {
				src += lit(before) + '\\s*([-+]?[\\d,，.]+)\\s*';
			} else {
				src += lit(before.replace(/\s+$/, ' ')).replace(/\\s\*$/, ' ?') + '(.+?)';
			}
			order.push(m[1] ? Number(m[1]) : ++auto);
			last = re.lastIndex;
		}
		src += lit(k.slice(last));
		// 照合の前の絞り込み用に、項目に必ず含まれる一番長い文字の断片を持っておく
		var anchor = k.split(/%(?:\d+\$)?[sd]/).map(function (x) { return x.trim(); }).sort(function (a, b) { return b.length - a.length; })[0] || '';
		// 「%s件」のように、名前などが入って手がかりが短いものは誤訳のもとなので使わない（数字だけが入るものは使う）
		if (!anchor || (anchor.length < 2 && /%(\d+\$)?s/.test(k))) return;
		try {
			patterns.push({ re: new RegExp('^' + src + '$'), order: order, en: D[k], anchor: anchor.replace(/\s+/g, ' ') });
		} catch (e) {}
	});

	function fillPattern(p, m) {
		var args = {};
		p.order.forEach(function (n, i) { args[n] = m[i + 1]; });
		var auto = 0;
		return p.en.replace(/%(?:(\d+)\$)?([sd])/g, function (_x, n) {
			var key = n ? Number(n) : ++auto;
			var v = args[key];
			if (v === undefined) return '';
			var t = JA.test(v) ? lookup(v) : null; // 差し込まれた部分も訳せるなら訳す（「受付中」など）
			return t !== null ? t : v;
		}).replace(/%%/g, '%');
	}

	var cache = Object.create(null);
	var depth = 0;

	/** 前後の空白を除いた文字列の訳。見つからなければ null */
	function lookup(s) {
		if (has.call(D, s)) return D[s];
		if (s in cache) return cache[s];
		if (depth > 2) return null;
		depth++;
		var r = null;
		try { r = lookupHard(s); } finally { depth--; }
		cache[s] = r; // 見つからなかったことも覚える（同じ文を何度も探し直さない）
		return r;
	}

	function lookupHard(s) {
		var m;
		// 中の改行・続く空白を 1 つにまとめる／全角空白を半角に
		var n = s.replace(/[\s　]+/g, ' ');
		if (n !== s) {
			if (has.call(D, n)) return D[n];
			var nn = s.replace(/[\s　]+/g, '');
			if (has.call(D, nn)) return D[nn];
		}
		// 末尾の「：」「:」
		if ((m = n.match(/^([\s\S]+?)\s*[：:]$/))) {
			var a = lookup(m[1]);
			if (a !== null) return a + ':';
		}
		// 末尾の「…」「...」
		if ((m = n.match(/^([\s\S]+?)(…|\.\.\.)$/))) {
			var b = lookup(m[1]);
			if (b !== null) return b + '…';
		}
		// 全体が括弧で囲まれている
		if ((m = n.match(/^([（(【「『［\[])([\s\S]+)([）)】」』］\]])$/))) {
			var c = lookup(m[2]);
			if (c !== null) return openBracket(m[1]) + c + closeBracket(m[3]);
		}
		// 先頭の記号（※ ■ ● ▼ ▶ ✓ ⚠ ★ ・ など）と空白
		if ((m = n.match(/^([^\w぀-ヿ㐀-鿿（(【「]+)\s*([\s\S]+)$/))) {
			var d = lookup(m[2]);
			if (d !== null) return m[1].replace(/・/g, '•') + (/\s$/.test(m[1]) ? '' : ' ') + d;
		}
		// 画面のタイトル「〇〇 ‹ KantanProEX」
		if ((m = n.match(/^(.+?)\s+([‹|–-])\s+([^\u3040-\u30ff\u3400-\u9fff]+)$/))) {
			var tt = lookup(m[1]);
			if (tt !== null) return tt + ' ' + m[2] + ' ' + m[3];
		}
		// 英語の文の中に「"受付中"」のように引用された日本語だけが残っている（書式の差し込み）→ 引用の中だけ訳す
		if (!JA.test(n.replace(/"[^"]*"|「[^」]*」/g, ''))) {
			var okq = true;
			var q = n.replace(/"([^"]*)"|「([^」]*)」/g, function (all, a1, a2) {
				var inner = a1 !== undefined ? a1 : a2;
				if (!JA.test(inner)) return all;
				var ti = lookup(inner);
				if (ti === null) { okq = false; return all; }
				return '"' + ti + '"';
			});
			if (okq && q !== n) return q;
		}
		// 「ラベル: 値」（ラベルだけ訳す。値は入力したデータや日付なのでそのまま）
		if ((m = n.match(/^([^：:]{1,30}?)\s*[：:]\s*([\s\S]+)$/)) && JA.test(m[1])) {
			var l1 = lookup(m[1]);
			if (l1 !== null) {
				var l2 = JA.test(m[2]) ? lookup(m[2]) : null;
				return l1 + ': ' + (l2 !== null ? l2 : m[2]);
			}
		}
		// 末尾の「（…）」の補足だけ別に訳す（例: 「顧客名（必須）」）
		if ((m = n.match(/^([\s\S]+?)\s*[（(]([^（）()]+)[）)]$/))) {
			var e1 = JA.test(m[1]) ? lookup(m[1]) : m[1];
			var e2 = lookup(m[2]);
			if (e1 !== null && (e2 !== null || !JA.test(m[2]))) return e1 + ' (' + (e2 !== null ? e2 : m[2]) + ')';
		}
		// 先頭・末尾の数字（「5件」「全 12 件」は辞書の %d 側で当てる）
		for (var i = 0; n.length <= 160 && i < patterns.length; i++) {
			if (n.indexOf(patterns[i].anchor) < 0) continue;
			var pm = n.match(patterns[i].re);
			if (pm) return fillPattern(patterns[i], pm);
		}
		// 「。」で区切られた複数の文は 1 文ずつ訳す（全部訳せたときだけ）
		var parts = n.match(/[^。！？]+[。！？]?/g);
		if (parts && parts.length > 1) {
			var outp = [];
			for (var j = 0; j < parts.length; j++) {
				var seg = parts[j].trim();
				if (!seg) continue;
				var ts = JA.test(seg) ? lookup(seg) : seg;
				if (ts === null) return null;
				outp.push(ts);
			}
			return outp.join(' ');
		}
		return null;
	}

	function openBracket(b) { return b === '【' || b === '［' || b === '[' ? '[' : b === '「' || b === '『' ? '"' : '('; }
	function closeBracket(b) { return b === '】' || b === '］' || b === ']' ? ']' : b === '」' || b === '』' ? '"' : ')'; }

	/** 文字列全体の訳（前後の空白はそのまま）。訳せなければ null */
	function tr(text) {
		if (!text || !JA.test(text)) return null;
		var m = text.match(/^(\s*)([\s\S]*?)(\s*)$/);
		var r = lookup(m[2]);
		return r === null ? null : m[1] + r + m[3];
	}

	/** 複数行の文（alert など）。全体 → 行ごとの順に探す */
	function trMessage(text) {
		var s = String(text);
		var whole = tr(s);
		if (whole !== null) return whole;
		if (!JA.test(s) || s.indexOf('\n') < 0) return s;
		return s.split('\n').map(function (line) {
			var t = tr(line);
			return t !== null ? t : line;
		}).join('\n');
	}

	function skipped(el) {
		for (var e = el; e && e.nodeType === 1; e = e.parentNode) {
			if (SKIP_TAGS[e.tagName] || e.isContentEditable || (e.classList && e.classList.contains('ktp-dt-no-i18n'))) return true;
		}
		return false;
	}

	// 金額の「12,345円」→「¥12,345」（数字の直後の「円」だけ。文の中にあっても置き換える）
	var counts = new WeakMap();
	// 自分が書いた訳（日本語の名前などが残っていることがある）。変化の通知で戻ってきても訳し直さない
	var produced = new Set();
	var parentCounts = new WeakMap();
	function bumpParent(el) {
		var c = (parentCounts.get(el) || 0) + 1;
		parentCounts.set(el, c);
		if (c === 101 && window.console) console.warn('ktp-dt-i18n: stopped re-translating inside', el.tagName, el.className);
		return c <= 100;
	}
	function bump(obj) {
		var c = (counts.get(obj) || 0) + 1;
		counts.set(obj, c);
		return c <= 30;
	}
	var YEN = /([-−]?)(\d[\d,]*(?:\.\d+)?)\s*円/g;

	function doText(node) {
		var v = node.nodeValue;
		if (!v || !JA.test(v) || produced.has(v)) return;
		var p = node.parentNode;
		if (!p || skipped(p)) return;
		var t = tr(v);
		if (t === null && YEN.test(v)) {
			YEN.lastIndex = 0;
			var y = v.replace(YEN, '$1¥$2');
			t = tr(y);
			if (t === null) t = y;
		}
		YEN.lastIndex = 0;
		if (t === null) return;
		// 画面のスクリプトが日本語に書き戻し続ける場合に、訳し合いが止まらなくならないようにする
		// （同じ文字のかたまりは 30 回、同じ親要素の中は 100 回まで。入れ直すたびに新しいかたまりを作るスクリプトもあるので親でも数える）
		if (!bump(node) || !bumpParent(p)) return;
		produced.add(t);
		// value の無い option は、表示の文字がそのまま送られるので先に元の値を残す
		if (p.tagName === 'OPTION' && !p.hasAttribute('value')) p.setAttribute('value', p.textContent);
		node.nodeValue = t;
	}

	function doAttrs(el) {
		if (skipped(el)) return;
		for (var i = 0; i < ATTRS.length; i++) {
			var a = ATTRS[i];
			var v = el.getAttribute(a);
			if (v && JA.test(v) && !produced.has(v)) {
				var t = tr(v);
				if (t !== null && t !== v && bump(el)) {
					produced.add(t);
					el.setAttribute(a, t);
				}
			}
		}
		if (el.tagName === 'INPUT') {
			var type = (el.getAttribute('type') || '').toLowerCase();
			if ((type === 'button' || type === 'reset' || (type === 'submit' && !el.name)) && JA.test(el.value) && !produced.has(el.value)) {
				var tv = tr(el.value);
				if (tv !== null && tv !== el.value && bump(el)) {
					produced.add(tv);
					el.value = tv;
				}
			}
		}
	}

	function walk(root) {
		if (!root) return;
		if (root.nodeType === 3) { doText(root); return; }
		if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return;
		if (root.nodeType === 1) {
			if (SKIP_TAGS[root.tagName]) return;
			if (root.tagName === 'IFRAME') patchFrame(root);
			doAttrs(root);
		}
		var tw = document.createTreeWalker(root, 5 /* SHOW_ELEMENT | SHOW_TEXT */, null);
		var n;
		while ((n = tw.nextNode())) {
			if (n.nodeType === 3) doText(n);
			else if (SKIP_TAGS[n.tagName]) continue;
			else {
				if (n.tagName === 'IFRAME') patchFrame(n);
				doAttrs(n);
			}
		}
	}

	function trTitle() {
		var t = tr(document.title);
		if (t !== null) document.title = t;
	}

	var mo = new MutationObserver(function (list) {
		for (var i = 0; i < list.length; i++) {
			var r = list[i];
			if (r.type === 'childList') {
				for (var j = 0; j < r.addedNodes.length; j++) walk(r.addedNodes[j]);
			} else if (r.type === 'characterData') {
				doText(r.target);
			} else if (r.type === 'attributes') {
				doAttrs(r.target);
			}
		}
	});
	mo.observe(document.documentElement, {
		childList: true,
		subtree: true,
		characterData: true,
		attributes: true,
		attributeFilter: ATTRS.concat(['value']),
	});

	// 印刷・PDF 用の HTML（本体の印刷は、隠し iframe やポップアップに HTML 文字列を document.write で書き込む）。
	// 印刷の処理には手を入れず、書き込まれる直前の HTML の文言だけを同じ規則で訳す
	function trHtml(html) {
		try {
			var full = /<html[\s>]|<body[\s>]|<!doctype/i.test(html);
			if (full) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				walk(doc.documentElement);
				var t = doc.querySelector('title');
				if (t) { var tt = tr(t.textContent); if (tt !== null) t.textContent = tt; }
				var dt = /^\s*<!doctype[^>]*>/i.exec(html);
				return (dt ? dt[0] : '<!DOCTYPE html>') + '\n' + doc.documentElement.outerHTML;
			}
			var tpl = document.createElement('template');
			tpl.innerHTML = html;
			walk(tpl.content);
			return tpl.innerHTML;
		} catch (e) {
			return html;
		}
	}
	function patchWindow(w) {
		try {
			if (!w || w === window || !w.Document) return;
			var P = w.Document.prototype;
			if (P.__ktpDtI18n) return;
			var nativeWrite = P.write;
			P.write = function () {
				var args = Array.prototype.slice.call(arguments);
				if (args.length === 1 && typeof args[0] === 'string' && JA.test(args[0])) args[0] = trHtml(args[0]);
				return nativeWrite.apply(this, args);
			};
			P.__ktpDtI18n = true;
		} catch (e) {}
	}
	function patchFrame(el) {
		patchWindow(el.contentWindow);
		if (!el.__ktpDtI18n) {
			el.__ktpDtI18n = true;
			el.addEventListener('load', function () { patchWindow(el.contentWindow); });
		}
	}
	// iframe を追加してすぐ書き込む処理もあるので、contentWindow / contentDocument を取り出した時点で差し替える
	['contentWindow', 'contentDocument'].forEach(function (prop) {
		try {
			var desc = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, prop);
			if (!desc || !desc.get) return;
			Object.defineProperty(HTMLIFrameElement.prototype, prop, {
				configurable: true,
				enumerable: desc.enumerable,
				get: function () {
					var v = desc.get.call(this);
					try { patchWindow(prop === 'contentWindow' ? v : v && v.defaultView); } catch (e) {}
					return v;
				},
			});
		} catch (e) {}
	});
	var nativeOpen = window.open;
	window.open = function () {
		var w = nativeOpen.apply(window, arguments);
		patchWindow(w);
		return w;
	};

	// alert / confirm / prompt
	var nativeAlert = window.alert;
	var nativeConfirm = window.confirm;
	var nativePrompt = window.prompt;
	window.alert = function (m) { return nativeAlert.call(window, trMessage(m)); };
	window.confirm = function (m) { return nativeConfirm.call(window, trMessage(m)); };
	window.prompt = function (m, d) { return nativePrompt.call(window, trMessage(m), d); };

	function wrapBridge() {
		// 本体の古いスクリプト用の訳（window.ktpwpTranslate）も同じ辞書にする
		window.ktpwpTranslate = function (text) { return trMessage(text); };
	}

	document.addEventListener('DOMContentLoaded', function () {
		wrapBridge();
		walk(document.body);
		trTitle();
	});
	window.addEventListener('load', trTitle);

	window.ktpDtTranslate = trMessage;
	window.ktpDtTranslateHtml = trHtml;
})();
