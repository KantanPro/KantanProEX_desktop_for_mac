<?php
// 土台の構造（実運用と同じ列構成・データなし）を流し込む
$ddl = json_decode(file_get_contents(__DIR__ . '/baseline-ddl.json'), true);
global $wpdb; $wpdb->suppress_errors(true);
$ok = 0; $ng = [];
foreach ($ddl as $t => $sql) {
  // 取得元は wp_ 接頭辞。実際の接頭辞に置き換える
  $sql = str_replace('`wp_ktp_', '`' . $wpdb->prefix . 'ktp_', $sql);
  $r = $wpdb->query($sql);
  if ($r === false) { $ng[$t] = $wpdb->last_error; } else { $ok++; }
}
echo "created: $ok / " . count($ddl) . "\n";
foreach ($ng as $t => $e) echo "NG $t: " . substr($e, 0, 200) . "\n";
