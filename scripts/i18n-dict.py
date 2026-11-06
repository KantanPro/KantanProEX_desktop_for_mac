#!/usr/bin/env python3
"""英語の辞書 site/mu-plugins/ktp-desktop/lang/en.js の読み書き。

  python3 scripts/i18n-dict.py merge <追加分.json>   … {"日本語": "English"} を足す（既にある項目は上書きする）
  python3 scripts/i18n-dict.py count                 … 件数

en.js は「window.ktpDtI18nDict=」+ JSON + 「;」。PHP（i18n.php）も画面（i18n.js）もこの 1 ファイルを読む。
"""
import json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
DICT = os.path.join(HERE, '..', 'site', 'mu-plugins', 'ktp-desktop', 'lang', 'en.js')
HEAD = '// 英語の辞書（日本語の原文 → 英語）。i18n.php と i18n.js が読む。編集は scripts/i18n-dict.py で（中身は JSON）\nwindow.ktpDtI18nDict='


def load():
    if not os.path.exists(DICT):
        return {}
    s = open(DICT, encoding='utf-8').read()
    return json.loads(s[s.index('{'):s.rindex('}') + 1])


def save(d):
    d = {k: d[k] for k in sorted(d)}
    body = json.dumps(d, ensure_ascii=False, indent=0, separators=(',', ':'))
    with open(DICT, 'w', encoding='utf-8') as f:
        f.write(HEAD + body + ';\n')


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'count'
    d = load()
    if cmd == 'merge':
        add = json.load(open(sys.argv[2], encoding='utf-8'))
        n = sum(1 for k in add if k not in d)
        d.update({k: v for k, v in add.items() if isinstance(v, str) and v})
        save(d)
        print(f'added {n}, total {len(d)}')
    else:
        print(len(d))
