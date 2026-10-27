<?php
/**
 * カテゴリー欄のサジェスト（顧客・協力会社・サービス）。
 *
 * 本体のカテゴリー欄は自由入力のテキスト欄で、既存のカテゴリーは出ない。
 * 欄に触れたとき、いま開いている画面（tab_name）の表に入っているカテゴリーを候補（datalist）として付ける。
 * 入力欄は画面の切り替えで作り直されるので、欄ごとではなく document でフォーカスを受けて付ける。
 * 候補はそのまま入力もできる（新しいカテゴリーも自由に付けられる）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 画面ごとの既存カテゴリー（空は除く） */
function ktp_dt_category_suggest_lists() {
	global $wpdb;
	$out = array();
	foreach ( array( 'client', 'supplier', 'service' ) as $tab ) {
		$table = $wpdb->prefix . 'ktp_' . $tab;
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows        = $wpdb->get_col( "SELECT DISTINCT category FROM `{$table}` WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC LIMIT 300" );
		$out[ $tab ] = array_values( array_filter( array_map( 'strval', (array) $rows ) ) );
	}
	return $out;
}

add_action(
	'wp_footer',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
<script id="ktp-dt-category-suggest">
(function(){
	var lists=<?php echo wp_json_encode( ktp_dt_category_suggest_lists(), JSON_UNESCAPED_UNICODE ); ?>;
	var sel='input[name="category"],input[name="search_category"]';
	document.addEventListener('focusin',function(e){
		var t=e.target;
		if(!t||!t.matches||!t.matches(sel)){return;}
		var tab=new URLSearchParams(location.search).get('tab_name')||'';
		var items=lists[tab];
		if(!items||!items.length){return;}
		var id='ktp-dt-cat-'+tab,dl=document.getElementById(id);
		if(!dl){
			dl=document.createElement('datalist');dl.id=id;
			items.forEach(function(v){var o=document.createElement('option');o.value=v;dl.appendChild(o);});
			document.body.appendChild(dl);
		}
		t.setAttribute('list',id);
		t.setAttribute('autocomplete','off');
	});
})();
</script>
		<?php
	},
	30
);
