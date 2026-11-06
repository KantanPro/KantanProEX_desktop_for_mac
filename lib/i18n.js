// アプリ（Electron 側）の表示言語。日本語 / English。
//
// - 設定は データ置き場/language.json（{"lang":"ja"|"en"}）。メニュー「表示」→「言語 / Language」で切り替える
// - 未設定のとき: すでにデータがあるインストール（英語対応より前から使っている人）は日本語のまま。
//   新しく入れたときは OS の言語で決める（日本語の OS なら日本語、それ以外は英語）
// - 決めた言語は PHP にも環境変数 KTP_DESKTOP_LANG で渡す（lib/php.js → mu-plugin の i18n.php が WordPress のロケールを en_US にする）
// - 切り替えるとアプリを再起動する（Chromium の --lang と PHP の環境変数は起動時にしか変えられない）
//
// 文言は日本語を原文にして、英語のときだけ下の表の訳を返す（表に無ければ日本語のまま）。
// 差し込みは {name} の形で書き、t('…{name}…', { name }) で渡す。
const fs = require('node:fs');
const path = require('node:path');

let current = 'ja';

function settingsFile(userData) {
  return path.join(userData, 'language.json');
}

function normalize(lang) {
  return lang === 'en' ? 'en' : 'ja';
}

/** 起動の最初（app.whenReady の前）に呼ぶ。userData は app.getPath('userData') */
function init(userData, systemLanguages = []) {
  let saved = null;
  try {
    saved = JSON.parse(fs.readFileSync(settingsFile(userData), 'utf8')).lang;
  } catch {}
  if (saved === 'ja' || saved === 'en') {
    current = saved;
  } else if (fs.existsSync(path.join(userData, 'data', 'kantanpro.sqlite'))) {
    current = 'ja';
  } else {
    const first = String((systemLanguages && systemLanguages[0]) || '').toLowerCase();
    current = !first || first.startsWith('ja') ? 'ja' : 'en';
  }
  return current;
}

function save(userData, lang) {
  current = normalize(lang);
  fs.mkdirSync(userData, { recursive: true });
  fs.writeFileSync(settingsFile(userData), JSON.stringify({ lang: current }));
}

function lang() {
  return current;
}

function isEn() {
  return current === 'en';
}

/** Chromium の --lang に渡す値（数値・日付の入力欄の書式に使われる。同梱の言語ファイルは ja / en / en-US） */
function chromiumLang() {
  return current === 'en' ? 'en-US' : 'ja';
}

const EN = {
  // 起動
  '起動しています…': 'Starting…',
  '起動しています': 'Starting',
  'ファイルを準備しています': 'Preparing files',
  '初めての準備をしています（10秒ほどかかります）': 'Setting up for the first time (about 10 seconds)',
  'データの保存場所を作っています': 'Creating the data storage',
  'もうすぐ使えます': 'Almost ready',
  'KantanProEX を起動できませんでした': 'KantanProEX could not start',
  '記録: {file}': 'Log: {file}',
  // 終了
  'KantanProEX を終了しますか？': 'Quit KantanProEX?',
  '入力した内容はその都度保存されています。': 'Everything you enter is saved as you go.',
  '終了': 'Quit',
  'キャンセル': 'Cancel',
  // 右クリック
  '元に戻す': 'Undo',
  'やり直す': 'Redo',
  '切り取り': 'Cut',
  'コピー': 'Copy',
  '貼り付け': 'Paste',
  'すべて選択': 'Select All',
  'リンクのアドレスをコピー': 'Copy Link Address',
  '画像をコピー': 'Copy Image',
  // バックアップ・復元
  'バックアップを作成しました': 'Backup created',
  'フォルダを開く': 'Open Folder',
  'バックアップできませんでした': 'Backup failed',
  '復元するバックアップのフォルダを選んでください': 'Choose the backup folder to restore',
  'このバックアップの内容に戻しますか？': 'Restore this backup?',
  '{name}\n\n今のデータは、戻す前に自動でバックアップします。': '{name}\n\nYour current data will be backed up automatically before restoring.',
  '戻す': 'Restore',
  'やめる': 'Cancel',
  'バックアップから戻しました': 'Restored from the backup',
  '復元できませんでした': 'Restore failed',
  'データベースを書き出せませんでした: {detail}': 'Could not export the database: {detail}',
  'まだデータがありません': 'There is no data yet',
  '選んだフォルダにバックアップ（kantanpro.sqlite）がありません': 'The selected folder does not contain a backup (kantanpro.sqlite)',
  // 取り込み
  'KantanBiz から書き出した ZIP ファイルを選んでください': 'Choose the ZIP file exported from KantanBiz',
  'KantanBiz の移行用ファイル': 'KantanBiz migration file',
  'WordPress 版': 'the WordPress edition',
  'KantanProEX（WP）の「デスクトップ版へ移行」で書き出した ZIP ファイルを選んでください': 'Choose the ZIP file exported with "Migrate to Desktop" in KantanProEX (WP)',
  'WordPress 版の移行用ファイル': 'WordPress edition migration file',
  '{name}のデータを取り込みますか？': 'Import data from {name}?',
  '{file}\n\nいまの顧客・案件・契約などのデータは、取り込んだ内容に入れ替わります。\n入れ替える前に、いまのデータを自動でバックアップします。':
    '{file}\n\nYour current customers, orders, contracts and other data will be replaced by the imported data.\nYour current data will be backed up automatically before it is replaced.',
  '取り込む': 'Import',
  '{name}のデータを取り込みました': 'Imported data from {name}',
  'メール送信（SMTP）のパスワードは移行されません。設定画面で入れ直してください。': 'The email (SMTP) password is not migrated. Please enter it again in Settings.',
  '取り込めませんでした': 'Import failed',
  '{msg}\n\nデータは取り込み前のままです。記録: {file}': '{msg}\n\nYour data has not been changed. Log: {file}',
  '顧客': 'Customers',
  '受注': 'Orders',
  'サービス': 'Services',
  '協力会社': 'Suppliers',
  '定期契約': 'Contracts',
  'スタッフ': 'Staff',
  'ファイル': 'Files',
  'スタッフは「KantanProEX 設定」→「LAN 共有」でパスワードを決めると使えるようになります。': 'Staff can sign in after you set their passwords in "KantanProEX Settings" → "LAN Sharing".',
  '日本郵便 API のシークレットキーは移行されません。設定画面で入れ直してから有効にしてください。': 'The Japan Post API secret key is not migrated. Enter it again in Settings before enabling it.',
  // アップデート
  'アップデートを確認できませんでした': 'Could not check for updates',
  'インターネットにつながっているか確かめてください。': 'Please check your internet connection.',
  'お使いの KantanProEX は最新です': 'KantanProEX is up to date',
  'バージョン {v}': 'Version {v}',
  '新しい KantanProEX（{v}）があります': 'A new version of KantanProEX ({v}) is available',
  '今のバージョン: {current}\n\n{notes}\n\nダウンロードページから新しい版を入れると更新できます。データはそのまま引き継がれます。':
    'Current version: {current}\n\n{notes}\n\nInstall the new version from the download page to update. Your data is kept as is.',
  'ダウンロードページを開く': 'Open Download Page',
  'あとで': 'Later',
  'この版は知らせない': 'Skip This Version',
  // メニュー
  'KantanProEX について': 'About KantanProEX',
  'アップデートを確認…': 'Check for Updates…',
  '起動時にアップデートを確認する': 'Check for Updates at Startup',
  'KantanProEX を隠す': 'Hide KantanProEX',
  'ほかを隠す': 'Hide Others',
  'すべてを表示': 'Show All',
  'KantanProEX を終了': 'Quit KantanProEX',
  'バックアップを作成': 'Create Backup',
  'バックアップから復元…': 'Restore from Backup…',
  '各種取り込み': 'Import',
  'WordPress 版から取り込む…': 'Import from the WordPress Edition…',
  'KantanBiz から取り込む…': 'Import from KantanBiz…',
  'FileMaker 版データ取り込み…': 'Import FileMaker Data…',
  'LAN 共有（事務所の他の PC から使う）…': 'LAN Sharing (Use from Other PCs in the Office)…',
  '音声操作…': 'Voice Control…',
  'サイトからの受注（ホームページの問い合わせ・注文）…': 'Orders from Your Website (Inquiries and Orders)…',
  'ライセンス（無料版・有料版）…': 'License (Free / Paid)…',
  'バックアップのフォルダを開く': 'Open Backup Folder',
  'データのフォルダを開く': 'Open Data Folder',
  'ウィンドウを閉じる': 'Close Window',
  '編集': 'Edit',
  '取り消す': 'Undo',
  'カット': 'Cut',
  'ペースト': 'Paste',
  'すべてを選択': 'Select All',
  '表示': 'View',
  '再読み込み': 'Reload',
  '最初の画面に戻る': 'Back to Home',
  '実際のサイズ': 'Actual Size',
  '拡大': 'Zoom In',
  '縮小': 'Zoom Out',
  'フルスクリーン': 'Toggle Full Screen',
  '開発者ツール': 'Developer Tools',
  'ウインドウ': 'Window',
  // 言語の切り替え
  'Language / 言語': 'Language / 言語',
  '表示する言語を切り替えますか？': 'Change the display language?',
  'KantanProEX を再起動して切り替えます。入力した内容はその都度保存されています。': 'KantanProEX will restart to apply the change. Everything you enter is saved as you go.',
  '再起動する': 'Restart',
  // LAN・音声の窓口
  'この画面は、親機でだけ開けます。': 'This screen can only be opened on the host computer.',
  'iPad・スマホ・他の PC からは開けない画面です。': 'This screen cannot be opened from an iPad, phone, or another computer.',
  '前の画面に戻る': 'Back to the previous screen',
  'アプリの内部につながりません。親機の KantanProEX を確認してください。': 'Cannot reach the app. Please check KantanProEX on the host computer.',
  'ポート {from}〜{to} がすべて使用中です': 'Ports {from}–{to} are all in use',
  'この要求は受け付けられません。': 'This request cannot be accepted.',
  '文が長すぎます。': 'The sentence is too long.',
  'アプリの内部につながりません。': 'Cannot reach the app.',
  'ポート {port} が他のアプリで使用中のため、音声操作を開始できません': 'Voice control could not start because port {port} is in use by another app',
  'PHP サーバーが起動しませんでした': 'The PHP server did not start',
};

function fill(text, vars) {
  if (!vars) return text;
  return text.replace(/\{(\w+)\}/g, (m, k) => (Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m));
}

/** 原文（日本語）を今の言語にする */
function t(ja, vars) {
  const text = current === 'en' && Object.prototype.hasOwnProperty.call(EN, ja) ? EN[ja] : ja;
  return fill(text, vars);
}

module.exports = { init, save, lang, isEn, chromiumLang, t, EN };
