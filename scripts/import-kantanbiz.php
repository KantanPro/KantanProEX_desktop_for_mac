<?php
/**
 * KantanBiz（SaaS）の書き出し ZIP を、DT の業務データとして取り込む。
 *
 * 使い方（wp-cli）: wp eval-file import-kantanbiz.php <ZIPのパス> --user=<管理者>
 * 最後に「imported: key=N ...」の 1 行を出力する。失敗したときは例外（終了コード 1）。
 *
 * - DT の業務データ（顧客・受注・契約など）を入れ替える。呼び出し側が事前にバックアップを取る
 * - 1 つのトランザクションで行い、失敗したら元に戻す（ファイルはコミット後に配置）
 * - パスワード類は書き出し側で除かれているので、SMTP のパスワードは空のまま
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KTP_DT_KantanBiz_Import {

	const PROGRESS = array(
		'受付中' => 1,
		'見積中' => 2,
		'受注'   => 3,
		'完了'   => 4,
		'請求済' => 5,
		'入金済' => 6,
		'ボツ'   => 7,
	);

	/** @var wpdb */
	private $db;
	private $p;
	private $tmp;
	/** @var array<string, array<int,int>> 旧ID => 新ID */
	private $map = array();
	/** @var array<int, array{src:string,dest:string}> コミット後にコピーするファイル */
	private $copies = array();
	private $counts = array();
	private $data;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p  = $wpdb->prefix;
	}

	public function run( $zip_path ) {
		if ( ! is_readable( $zip_path ) ) {
			throw new RuntimeException( 'ファイルを読めません: ' . $zip_path );
		}
		$this->tmp = sys_get_temp_dir() . '/ktp-kb-import-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0700, true );
		try {
			$this->extract( $zip_path );
			$this->load_json();
			$this->ensure_extra_tables();

			$this->db->query( 'START TRANSACTION' );
			try {
				$this->clear_existing();
				$this->import_members();
				$this->import_clients();
				$this->import_departments();
				$this->import_suppliers();
				$this->import_services();
				$this->import_contracts();
				$this->import_orders();
				$this->import_settings();
				$this->db->query( 'COMMIT' );
			} catch ( Throwable $e ) {
				$this->db->query( 'ROLLBACK' );
				throw $e;
			}
			$this->place_files();
			if ( class_exists( 'KTPWP_List_Warning_Counts' ) && method_exists( 'KTPWP_List_Warning_Counts', 'invalidate' ) ) {
				KTPWP_List_Warning_Counts::invalidate();
			}
		} finally {
			$this->rrmdir( $this->tmp );
		}
		$parts = array();
		foreach ( $this->counts as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}
		return 'imported: ' . implode( ' ', $parts );
	}

	private function extract( $zip_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new RuntimeException( 'ZIP を開けませんでした。' );
		}
		// パストラバーサル対策: 想定する名前だけ展開する
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== strpos( $name, '..' ) || '/' === substr( $name, -1 ) ) {
				continue;
			}
			if ( 'export.json' === $name || 0 === strpos( $name, 'files/' ) ) {
				$dest = $this->tmp . '/' . $name;
				wp_mkdir_p( dirname( $dest ) );
				file_put_contents( $dest, $zip->getFromIndex( $i ) );
			}
		}
		$zip->close();
	}

	private function load_json() {
		$path = $this->tmp . '/export.json';
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'KantanBiz の書き出しファイルではありません（export.json がありません）。' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== 'kantanbiz-desktop-export' ) {
			throw new RuntimeException( 'KantanBiz の書き出しファイルではありません。' );
		}
		if ( (int) ( $data['version'] ?? 0 ) !== 1 ) {
			throw new RuntimeException( 'このファイルの版には対応していません。アプリを最新にしてください。' );
		}
		$this->data = $data;
	}

	private function rows( $table ) {
		return isset( $this->data['tables'][ $table ] ) && is_array( $this->data['tables'][ $table ] ) ? $this->data['tables'][ $table ] : array();
	}

	private function t( $name ) {
		return $this->p . $name;
	}

	/** @var array<string, array<string, bool>> 表ごとの実在する列 */
	private $columns = array();

	/** 実在する列だけに絞る（DB の構造が土台と違っても、無い列に書き込んで止まらないように） */
	private function only_existing_columns( $table, array $row ) {
		$name = $this->t( $table );
		if ( ! isset( $this->columns[ $name ] ) ) {
			$cols = (array) $this->db->get_col( "SHOW COLUMNS FROM `{$name}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->columns[ $name ] = array_fill_keys( array_map( 'strtolower', $cols ), true );
		}
		if ( ! $this->columns[ $name ] ) {
			return $row; // 列を調べられなかったときはそのまま
		}
		$out = array();
		foreach ( $row as $k => $v ) {
			if ( isset( $this->columns[ $name ][ strtolower( $k ) ] ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	private function insert( $table, array $row ) {
		$row = $this->only_existing_columns( $table, $row );
		$ok  = $this->db->insert( $this->t( $table ), $row );
		if ( false === $ok ) {
			throw new RuntimeException( $table . ' に書き込めませんでした: ' . $this->db->last_error );
		}
		return (int) $this->db->insert_id;
	}

	private function str( $v, $default = '' ) {
		return ( null === $v ) ? $default : (string) $v;
	}

	private function date( $v ) {
		$v = $this->str( $v );
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $v, $m ) ? $m[1] : null;
	}

	/** 書き出し側（KantanBiz）の時刻の解釈に使うタイムゾーン */
	private function src_tz() {
		$name = isset( $this->data['timezone'] ) ? (string) $this->data['timezone'] : 'Asia/Tokyo';
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'Asia/Tokyo' );
		}
	}

	/** KantanBiz の日時（書き出し側のタイムゾーン）を、DT（サイトの時刻）の文字列にする */
	private function dt( $v, $default = null ) {
		$v = $this->str( $v );
		if ( '' === $v ) {
			return $default;
		}
		try {
			$d = new DateTimeImmutable( $v, $this->src_tz() );
		} catch ( Exception $e ) {
			return $default;
		}
		return $d->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	/** DT の日時文字列（サイトの時刻）→ UNIX 時刻 */
	private function ts( $local ) {
		try {
			return ( new DateTimeImmutable( (string) $local, wp_timezone() ) )->getTimestamp();
		} catch ( Exception $e ) {
			return time();
		}
	}

	private function bump( $key, $n = 1 ) {
		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + $n;
	}

	/** 値を新IDに置き換える（無ければ null） */
	private function mapped( $kind, $old ) {
		return isset( $this->map[ $kind ][ (int) $old ] ) ? $this->map[ $kind ][ (int) $old ] : null;
	}

	/** DT 専用テーブル（プラグイン本体には無い、KantanBiz 由来の項目の置き場） */
	private function ensure_extra_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $this->db->get_charset_collate();
		$table   = $this->t( 'ktp_dt_cost_payment' );
		dbDelta(
			"CREATE TABLE {$table} (
				cost_item_id bigint(20) unsigned NOT NULL,
				paid tinyint(1) NOT NULL DEFAULT 0,
				paid_at datetime DEFAULT NULL,
				due_date_override date DEFAULT NULL,
				closing_reference_date date DEFAULT NULL,
				PRIMARY KEY  (cost_item_id)
			) {$charset};"
		);
	}

	private function clear_existing() {
		$tables = array(
			'ktp_dt_cost_payment', 'ktp_order_task', 'ktp_order_customer_mail_log', 'ktp_order_file',
			'ktp_order_staff_chat', 'ktp_order_cost_items', 'ktp_order_invoice_items',
			'ktp_contract_billing_log', 'ktp_contract_initial_fee', 'ktp_contract_recurring_item', 'ktp_contract',
			'ktp_order', 'ktp_service_initial_fee', 'ktp_service_recurring_item', 'ktp_service',
			'ktp_supplier_skills', 'ktp_supplier', 'ktp_department', 'ktp_client',
		);
		foreach ( $tables as $table ) {
			$name = $this->t( $table );
			$this->db->query( "DELETE FROM `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// 案件ファイルの実体
		$upload = wp_upload_dir();
		$this->rrmdir( trailingslashit( $upload['basedir'] ) . 'ktp-order-files', false );
	}

	/** KantanBiz のメンバーを DT のスタッフ（WP ユーザー）にする。オーナーは既存の管理者に対応づける */
	private function import_members() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
		$admin  = $admins ? $admins[0] : null;
		foreach ( (array) ( $this->data['members'] ?? array() ) as $m ) {
			$old = (int) ( $m['id'] ?? 0 );
			if ( ! $old ) {
				continue;
			}
			if ( 'owner' === ( $m['role'] ?? '' ) && $admin && ! isset( $this->map['user_owner'] ) ) {
				$this->map['user_owner'] = true;
				wp_update_user( array( 'ID' => $admin->ID, 'display_name' => $this->str( $m['name'], $admin->display_name ) ) );
				$this->map['user'][ $old ] = (int) $admin->ID;
				continue;
			}
			$email = sanitize_email( $this->str( $m['email'] ) );
			$user  = $email ? get_user_by( 'email', $email ) : false;
			if ( ! $user ) {
				$login = 'kb-staff-' . $old;
				$id    = wp_insert_user(
					array(
						'user_login'   => $login,
						'user_pass'    => wp_generate_password( 32 ),
						'user_email'   => $email ? $email : $login . '@invalid.example',
						'display_name' => $this->str( $m['name'], $login ),
						'role'         => 'editor',
					)
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				$this->bump( 'members' );
				$this->map['user'][ $old ] = (int) $id;
			} else {
				$this->map['user'][ $old ] = (int) $user->ID;
			}
		}
		if ( isset( $this->map['user_owner'] ) ) {
			unset( $this->map['user_owner'] );
			$this->bump( 'members' );
		}
	}

	private function search_field( array $parts ) {
		return implode( ' ', array_filter( array_map( 'strval', $parts ), 'strlen' ) );
	}

	private function import_clients() {
		foreach ( $this->rows( 'clients' ) as $r ) {
			$old     = (int) $r['id'];
			$mail_ng = ( '対象外' === ( $r['mail_target'] ?? '' ) );
			$created = $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) );
			$id      = $this->insert(
				'ktp_client',
				array(
					'time'                => $this->ts( $created ),
					'name'                => $this->str( $r['representative_name'] ),
					'company_name'        => $this->str( $r['company_name'], '（名称未設定）' ),
					'representative_name' => $this->str( $r['representative_name'] ),
					'email'               => $this->str( $r['email'] ),
					'phone'               => $this->str( $r['phone'] ),
					'postal_code'         => $this->str( $r['postal_code'] ),
					'prefecture'          => $this->str( $r['prefecture'] ),
					'city'                => $this->str( $r['city'] ),
					'address'             => $this->str( $r['address'] ),
					'building'            => $this->str( $r['building'] ),
					'closing_day'         => $this->str( $r['closing_day'] ),
					'payment_month'       => $this->str( $r['payment_month'] ),
					'payment_day'         => $this->str( $r['payment_day'] ),
					'payment_method'      => $this->str( $r['payment_method'] ?? '' ),
					'tax_category'        => $this->str( $r['tax_category'], '内税' ),
					'payment_timing'      => in_array( $r['payment_timing'] ?? '', array( 'postpay', 'prepay' ), true ) ? $r['payment_timing'] : 'postpay',
					'memo'                => $this->str( $r['memo'] ),
					'search_field'        => $this->search_field( array( $r['company_name'] ?? '', $r['representative_name'] ?? '', $r['email'] ?? '', $r['phone'] ?? '', $r['address'] ?? '' ) ),
					'frequency'           => (int) ( $r['frequency'] ?? 0 ),
					'client_status'       => $mail_ng ? '対象外' : '対象',
					'inquiry_blocked'     => ! empty( $r['inquiry_blocked'] ) ? 1 : 0,
					'category'            => $this->str( $r['category'], null ),
					'created_at'          => $created,
					'updated_at'          => $this->dt( $r['updated_at'] ?? null, $created ),
				)
			);
			$this->map['client'][ $old ] = $id;
			$this->bump( 'clients' );
		}
	}

	private function import_departments() {
		foreach ( $this->rows( 'client_departments' ) as $r ) {
			$cid = $this->mapped( 'client', $r['client_id'] ?? 0 );
			if ( ! $cid ) {
				continue;
			}
			$id = $this->insert(
				'ktp_department',
				array(
					'client_id'       => $cid,
					'department_name' => $this->str( $r['department_name'] ),
					'contact_person'  => $this->str( $r['contact_person'] ),
					'email'           => $this->str( $r['email'] ),
					'is_selected'     => 0,
					'created_at'      => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->map['department'][ (int) $r['id'] ] = $id;
			$this->bump( 'departments' );
		}
		// 顧客の「選択中の部署」
		foreach ( $this->rows( 'clients' ) as $r ) {
			$dep = $this->mapped( 'department', $r['selected_department_id'] ?? 0 );
			$cid = $this->mapped( 'client', $r['id'] );
			if ( $dep && $cid ) {
				$this->db->update( $this->t( 'ktp_client' ), array( 'selected_department_id' => $dep ), array( 'id' => $cid ) );
				$this->db->update( $this->t( 'ktp_department' ), array( 'is_selected' => 1 ), array( 'id' => $dep ) );
			}
		}
	}

	private function import_suppliers() {
		foreach ( $this->rows( 'suppliers' ) as $r ) {
			$created = $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) );
			$id      = $this->insert(
				'ktp_supplier',
				array(
					'time'                     => $this->ts( $created ),
					'name'                     => $this->str( $r['contact_name'] ),
					'company_name'             => $this->str( $r['company_name'], '（名称未設定）' ),
					'email'                    => $this->str( $r['email'] ),
					'representative_name'      => $this->str( $r['representative_name'] ),
					'phone'                    => $this->str( $r['phone'] ),
					'postal_code'              => $this->str( $r['postal_code'] ),
					'prefecture'               => $this->str( $r['prefecture'] ),
					'city'                     => $this->str( $r['city'] ),
					'address'                  => $this->str( $r['address'] ),
					'building'                 => $this->str( $r['building'] ),
					'closing_day'              => $this->str( $r['closing_day'] ),
					'payment_month'            => $this->str( $r['payment_month'] ),
					'payment_day'              => $this->str( $r['payment_day'] ),
					'payment_method'           => $this->str( $r['payment_method'] ),
					'tax_category'             => $this->str( $r['tax_category'], '内税' ),
					'memo'                     => $this->str( $r['memo'] ),
					'qualified_invoice_number' => $this->str( $r['qualified_invoice_number'] ),
					'search_field'             => $this->search_field( array( $r['company_name'] ?? '', $r['contact_name'] ?? '', $r['email'] ?? '', $r['phone'] ?? '' ) ),
					'frequency'                => (int) ( $r['frequency'] ?? 0 ),
					'category'                 => $this->str( $r['category'], '一般' ),
					'created_at'               => $created,
					'updated_at'               => $this->dt( $r['updated_at'] ?? null, $created ),
					'url'                      => $this->str( $r['website'] ?? '' ),
				)
			);
			$this->map['supplier'][ (int) $r['id'] ] = $id;
			$this->bump( 'suppliers' );
		}
		foreach ( $this->rows( 'supplier_skills' ) as $r ) {
			$sid = $this->mapped( 'supplier', $r['supplier_id'] ?? 0 );
			if ( ! $sid ) {
				continue;
			}
			$this->insert(
				'ktp_supplier_skills',
				array(
					'supplier_id'  => $sid,
					'product_name' => $this->str( $r['product_name'] ),
					'unit_price'   => (string) ( $r['unit_price'] ?? '0' ),
					'quantity'     => (int) ( $r['quantity'] ?? 1 ),
					'unit'         => $this->str( $r['unit'], '式' ),
					'tax_rate'     => isset( $r['tax_rate'] ) ? $r['tax_rate'] : null,
					'frequency'    => (int) ( $r['frequency'] ?? 0 ),
				)
			);
			$this->bump( 'supplier_skills' );
		}
	}

	private function import_services() {
		$upload = wp_upload_dir();
		foreach ( $this->rows( 'services' ) as $r ) {
			$image_url = null;
			if ( ! empty( $r['export_file'] ) && is_file( $this->tmp . '/' . $r['export_file'] ) ) {
				$ext  = pathinfo( (string) $r['export_file'], PATHINFO_EXTENSION );
				$name = 'svc-' . wp_generate_password( 10, false ) . ( $ext ? '.' . $ext : '' );
				$this->copies[] = array(
					'src'  => $this->tmp . '/' . $r['export_file'],
					'dest' => trailingslashit( $upload['basedir'] ) . 'ktpwp-service-images/' . $name,
				);
				$image_url = trailingslashit( $upload['baseurl'] ) . 'ktpwp-service-images/' . $name;
			}
			$id = $this->insert(
				'ktp_service',
				array(
					'time'                   => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
					'service_name'           => $this->str( $r['name'] ),
					'price'                  => (string) ( $r['price'] ?? '0' ),
					'tax_rate'               => isset( $r['tax_rate'] ) ? $r['tax_rate'] : null,
					'unit'                   => $this->str( $r['unit'] ),
					'image_url'              => $image_url,
					'memo'                   => $this->str( $r['memo'] ),
					'search_field'           => $this->search_field( array( $r['name'] ?? '', $r['memo'] ?? '' ) ),
					'frequency'              => (int) ( $r['frequency'] ?? 0 ),
					'category'               => $this->str( $r['category'], '一般' ),
					'is_public'              => 0,
					'contract_billing_cycle' => $this->str( $r['contract_billing_cycle'], 'none' ),
					'stock'                  => max( 0, (int) ( $r['stock'] ?? 1 ) ),
				)
			);
			$this->map['service'][ (int) $r['id'] ] = $id;
			$this->bump( 'services' );
		}
		foreach ( $this->rows( 'service_initial_fees' ) as $r ) {
			$sid = $this->mapped( 'service', $r['service_id'] ?? 0 );
			if ( $sid ) {
				$this->insert( 'ktp_service_initial_fee', array( 'service_id' => $sid, 'fee_name' => $this->str( $r['fee_name'] ), 'amount' => (string) ( $r['amount'] ?? '0' ), 'tax_rate' => $r['tax_rate'] ?? null, 'sort_order' => (int) ( $r['sort_order'] ?? 0 ) ) );
			}
		}
		foreach ( $this->rows( 'service_recurring_items' ) as $r ) {
			$sid = $this->mapped( 'service', $r['service_id'] ?? 0 );
			if ( $sid ) {
				$this->insert( 'ktp_service_recurring_item', array( 'service_id' => $sid, 'item_name' => $this->str( $r['item_name'] ), 'amount' => (string) ( $r['amount'] ?? '0' ), 'tax_rate' => $r['tax_rate'] ?? null, 'sort_order' => (int) ( $r['sort_order'] ?? 0 ) ) );
			}
		}
	}

	private function import_contracts() {
		foreach ( $this->rows( 'contracts' ) as $r ) {
			$cid = $this->mapped( 'client', $r['client_id'] ?? 0 );
			$sid = $this->mapped( 'service', $r['service_id'] ?? 0 );
			if ( ! $cid || ! $sid ) {
				continue;
			}
			$id = $this->insert(
				'ktp_contract',
				array(
					'client_id'         => $cid,
					'service_id'        => $sid,
					'contract_name'     => $this->str( $r['contract_name'] ),
					'amount'            => (string) ( $r['amount'] ?? '0' ),
					'billing_cycle'     => $this->str( $r['billing_cycle'], 'monthly' ),
					'billing_day'       => (int) ( $r['billing_day'] ?? 1 ),
					'payment_due_mode'  => $this->str( $r['payment_due_mode'], 'client' ),
					'start_date'        => $this->date( $r['start_date'] ?? null ),
					'end_date'          => $this->date( $r['end_date'] ?? null ),
					'status'            => $this->str( $r['status'], 'active' ),
					'next_billing_date' => $this->date( $r['next_billing_date'] ?? null ),
					'first_billed'      => ! empty( $r['first_billed'] ) ? 1 : 0,
					'send_reminder_mail' => ! empty( $r['send_reminder_mail'] ) ? 1 : 0,
					'memo'              => $this->str( $r['memo'] ),
					'created_at'        => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
					'updated_at'        => $this->dt( $r['updated_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->map['contract'][ (int) $r['id'] ] = $id;
			$this->bump( 'contracts' );
		}
		foreach ( $this->rows( 'contract_initial_fees' ) as $r ) {
			$k = $this->mapped( 'contract', $r['contract_id'] ?? 0 );
			if ( $k ) {
				$this->insert( 'ktp_contract_initial_fee', array( 'contract_id' => $k, 'fee_name' => $this->str( $r['fee_name'] ), 'amount' => (string) ( $r['amount'] ?? '0' ), 'tax_rate' => $r['tax_rate'] ?? null, 'sort_order' => (int) ( $r['sort_order'] ?? 0 ) ) );
			}
		}
		foreach ( $this->rows( 'contract_recurring_items' ) as $r ) {
			$k = $this->mapped( 'contract', $r['contract_id'] ?? 0 );
			if ( $k ) {
				$this->insert( 'ktp_contract_recurring_item', array( 'contract_id' => $k, 'item_name' => $this->str( $r['item_name'] ), 'amount' => (string) ( $r['amount'] ?? '0' ), 'tax_rate' => $r['tax_rate'] ?? null, 'sort_order' => (int) ( $r['sort_order'] ?? 0 ) ) );
			}
		}
	}

	private function import_orders() {
		$clients = array();
		foreach ( $this->rows( 'clients' ) as $c ) {
			$clients[ (int) $c['id'] ] = $c;
		}
		foreach ( $this->rows( 'orders' ) as $r ) {
			$cid = $this->mapped( 'client', $r['client_id'] ?? 0 );
			if ( ! $cid ) {
				continue;
			}
			$client   = $clients[ (int) $r['client_id'] ] ?? array();
			$company  = $this->str( $client['company_name'] ?? '', '（名称未設定）' );
			$created  = $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) );
			$order_dt = $this->date( $r['ordered_at'] ?? null ) ?: substr( $created, 0, 10 );
			$no       = 'KB-' . ( ! empty( $r['display_no'] ) ? (int) $r['display_no'] : (int) $r['id'] );
			$id       = $this->insert(
				'ktp_order',
				array(
					'client_id'              => $cid,
					'contract_id'            => (int) ( $this->mapped( 'contract', $r['contract_id'] ?? 0 ) ?: 0 ),
					'billing_period'         => $this->str( $r['billing_period'] ),
					'client_department_id'   => $this->mapped( 'department', $r['client_department_id'] ?? 0 ),
					'project_name'           => $this->str( $r['title'] ),
					'order_date'             => $order_dt,
					'desired_delivery_date'  => $this->date( $r['desired_delivery_date'] ?? null ),
					'expected_delivery_date' => $this->date( $r['expected_delivery_date'] ?? null ),
					'promised_delivery_date' => $this->date( $r['due_date'] ?? null ),
					'completion_date'        => $this->date( $r['completion_date'] ?? null ),
					'total_amount'           => (string) ( $r['amount'] ?? '0' ),
					'created_at'             => $created,
					'updated_at'             => $this->dt( $r['updated_at'] ?? null, $created ),
					'time'                   => $this->ts( $created ),
					'customer_name'          => $company,
					'client_company'         => $company,
					'company_name'           => $company,
					'progress'               => self::PROGRESS[ $r['status'] ?? '' ] ?? 1,
					'memo'                   => $this->str( $r['memo'] ),
					'search_field'           => $this->search_field( array( $r['title'] ?? '', $company, $r['memo'] ?? '' ) ),
					'payment_timing'         => in_array( $r['payment_timing'] ?? '', array( 'postpay', 'prepay' ), true ) ? $r['payment_timing'] : null,
					'order_number'           => $no,
					'stripe_invoice_id'      => $r['stripe_invoice_id'] ?? null,
					'stripe_invoice_url'     => $r['stripe_invoice_url'] ?? null,
					'stripe_paid_at'         => $this->dt( $r['stripe_paid_at'] ?? null ),
					'stripe_subscription_id' => $r['stripe_subscription_id'] ?? null,
				)
			);
			$this->map['order'][ (int) $r['id'] ] = $id;
			$this->bump( 'orders' );
		}
		foreach ( $this->rows( 'order_invoice_items' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid ) {
				continue;
			}
			$this->insert(
				'ktp_order_invoice_items',
				array(
					'order_id'       => $oid,
					'product_name'   => $this->str( $r['product_name'] ),
					'price'          => (string) ( $r['price'] ?? '0' ),
					'unit'           => $this->str( $r['unit'] ),
					'quantity'       => (string) ( $r['quantity'] ?? '0' ),
					'amount'         => (int) round( (float) ( $r['amount'] ?? 0 ) ),
					'tax_rate'       => isset( $r['tax_rate'] ) ? $r['tax_rate'] : null,
					'remarks'        => $this->str( $r['remarks'] ),
					'is_provisional' => ! empty( $r['is_provisional'] ) ? 1 : 0,
					'sort_order'     => (int) ( $r['sort_order'] ?? 0 ),
				)
			);
			$this->bump( 'invoice_items' );
		}
		foreach ( $this->rows( 'order_cost_items' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid ) {
				continue;
			}
			$cost_id = $this->insert(
				'ktp_order_cost_items',
				array(
					'order_id'                 => $oid,
					'supplier_id'              => $this->mapped( 'supplier', $r['supplier_id'] ?? 0 ),
					'product_name'             => $this->str( $r['product_name'] ),
					'price'                    => (string) ( $r['price'] ?? '0' ),
					'unit'                     => $this->str( $r['unit'] ),
					'quantity'                 => (string) ( $r['quantity'] ?? '0' ),
					'amount'                   => (int) round( (float) ( $r['amount'] ?? 0 ) ),
					'tax_rate'                 => isset( $r['tax_rate'] ) ? $r['tax_rate'] : null,
					'remarks'                  => $this->str( $r['remarks'] ),
					'purchase'                 => $r['purchase'] ?? null,
					'qualified_invoice_number' => $r['qualified_invoice_number'] ?? null,
					'ordered'                  => ! empty( $r['ordered'] ) ? 1 : 0,
					'sort_order'               => (int) ( $r['sort_order'] ?? 0 ),
					'purchase_status'          => $this->str( $r['purchase_status'], 'pending' ),
					'purchase_status_date'     => $this->date( $r['status_date'] ?? null ),
					'purchase_status_dates'    => isset( $r['purchase_status_dates'] ) ? ( is_array( $r['purchase_status_dates'] ) ? wp_json_encode( $r['purchase_status_dates'] ) : (string) $r['purchase_status_dates'] ) : null,
				)
			);
			$this->bump( 'cost_items' );
			$paid_at = $this->dt( $r['payment_paid_at'] ?? null );
			if ( ! empty( $r['payment_paid'] ) || $paid_at || ! empty( $r['payment_due_date_override'] ) ) {
				$this->db->insert(
					$this->t( 'ktp_dt_cost_payment' ),
					array(
						'cost_item_id'           => $cost_id,
						'paid'                   => ! empty( $r['payment_paid'] ) ? 1 : 0,
						'paid_at'                => $paid_at,
						'due_date_override'      => $this->date( $r['payment_due_date_override'] ?? null ),
						'closing_reference_date' => $this->date( $r['payment_closing_reference_date'] ?? null ),
					)
				);
			}
		}
		$owner_fallback = (int) get_current_user_id();
		foreach ( $this->rows( 'order_staff_messages' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid ) {
				continue;
			}
			$this->insert(
				'ktp_order_staff_chat',
				array(
					'order_id'          => $oid,
					'user_id'           => (int) ( $this->mapped( 'user', $r['user_id'] ?? 0 ) ?: $owner_fallback ),
					'user_display_name' => $this->str( $r['user_display_name'], '移行データ' ),
					'message'           => $this->str( $r['body'] ),
					'is_initial'        => ! empty( $r['is_initial'] ) ? 1 : 0,
					'created_at'        => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->bump( 'chat' );
		}
		foreach ( $this->rows( 'order_tasks' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid ) {
				continue;
			}
			$this->insert(
				'ktp_order_task',
				array(
					'order_id'             => $oid,
					'created_by_user_id'   => (int) ( $this->mapped( 'user', $r['created_by_user_id'] ?? 0 ) ?: 0 ),
					'assignee_user_id'     => (int) ( $this->mapped( 'user', $r['assignee_user_id'] ?? 0 ) ?: 0 ),
					'completed_by_user_id' => (int) ( $this->mapped( 'user', $r['completed_by_user_id'] ?? 0 ) ?: 0 ),
					'title'                => $this->str( $r['title'] ),
					'start_date'           => $this->date( $r['start_date'] ?? null ) ?: $this->date( $r['due_date'] ?? null ),
					'due_date'             => $this->date( $r['due_date'] ?? null ),
					'completed_at'         => $this->dt( $r['completed_at'] ?? null ),
					'sort_order'           => (int) ( $r['sort_order'] ?? 0 ),
					'created_at'           => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->bump( 'tasks' );
		}
		foreach ( $this->rows( 'order_customer_mail_logs' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid ) {
				continue;
			}
			$cc = $r['cc_emails'] ?? null;
			if ( is_string( $cc ) && '' !== $cc ) {
				$decoded = json_decode( $cc, true );
				$cc      = is_array( $decoded ) ? implode( ', ', $decoded ) : $cc;
			}
			$this->insert(
				'ktp_order_customer_mail_log',
				array(
					'order_id'      => $oid,
					'user_id'       => (int) ( $this->mapped( 'user', $r['user_id'] ?? 0 ) ?: 0 ),
					'to_email'      => $this->str( $r['to_email'] ),
					'cc_emails'     => is_string( $cc ) ? $cc : null,
					'subject'       => $this->str( $r['subject'] ),
					'send_status'   => $this->str( $r['send_status'], 'sent' ),
					'error_message' => $r['error_message'] ?? null,
					'sent_at'       => $this->dt( $r['sent_at'] ?? null ),
					'created_at'    => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->bump( 'mail_logs' );
		}
		$upload = wp_upload_dir();
		foreach ( $this->rows( 'order_files' ) as $r ) {
			$oid = $this->mapped( 'order', $r['order_id'] ?? 0 );
			if ( ! $oid || empty( $r['export_file'] ) || ! is_file( $this->tmp . '/' . $r['export_file'] ) ) {
				continue;
			}
			$orig = sanitize_file_name( $this->str( $r['original_filename'], 'file' ) );
			$ext  = pathinfo( $orig, PATHINFO_EXTENSION );
			$rel  = 'ktp-order-files/' . $oid . '/' . wp_generate_password( 12, false ) . ( $ext ? '.' . strtolower( $ext ) : '' );
			$this->copies[] = array( 'src' => $this->tmp . '/' . $r['export_file'], 'dest' => trailingslashit( $upload['basedir'] ) . $rel );
			$this->insert(
				'ktp_order_file',
				array(
					'order_id'          => $oid,
					'user_id'           => (int) ( $this->mapped( 'user', $r['user_id'] ?? 0 ) ?: 0 ),
					'original_filename' => $this->str( $r['original_filename'], 'file' ),
					'storage_path'      => $rel,
					'mime_type'         => $r['mime_type'] ?? null,
					'size'              => (int) ( $r['size'] ?? 0 ),
					'created_at'        => $this->dt( $r['created_at'] ?? null, current_time( 'mysql' ) ),
				)
			);
			$this->bump( 'files' );
		}
	}

	private function import_settings() {
		$t = (array) ( $this->data['tenant'] ?? array() );
		if ( ! $t ) {
			return;
		}
		$upload = wp_upload_dir();

		// 会社情報（帳票の発行元）。銀行の振込先は構造が違うので会社情報の末尾に入れる
		$lines = array_filter( array( $this->str( $t['pdf_issuer_name'] ?? '' ), $this->str( $t['pdf_issuer_address'] ?? '' ), $this->str( $t['pdf_issuer_note'] ?? '' ) ), 'strlen' );
		$info  = implode( "\n", $lines );
		if ( ! empty( $t['pdf_bank_transfer_text'] ) ) {
			$info .= ( '' === $info ? '' : "\n\n" ) . "【お振込先】\n" . $t['pdf_bank_transfer_text'];
		}
		$general = get_option( 'ktp_general_settings', array() );
		$general = is_array( $general ) ? $general : array();
		if ( '' !== $info ) {
			$general['company_info'] = nl2br( esc_html( $info ) );
		}
		if ( in_array( $t['tax_mode'] ?? '', array( 'multiple', 'unified', 'abolished' ), true ) ) {
			$general['tax_mode'] = $t['tax_mode'];
		}
		if ( isset( $t['unified_tax_rate'] ) ) {
			$general['unified_tax_rate'] = (float) $t['unified_tax_rate'];
		}
		update_option( 'ktp_general_settings', $general );

		update_option( 'ktp_pdf_issuer_name', $this->str( $t['pdf_issuer_name'] ?? '' ) );
		update_option( 'ktp_pdf_issuer_address', $this->str( $t['pdf_issuer_address'] ?? '' ) );
		if ( in_array( $t['pdf_layout'] ?? '', array( 'default', 'compact' ), true ) ) {
			update_option( 'ktp_pdf_layout', $t['pdf_layout'] );
		}

		foreach ( array( 'logo' => 'ktp_pdf_logo_path', 'seal' => 'ktp_pdf_seal_path' ) as $kind => $option ) {
			$entry = $t[ $kind . '_file' ] ?? '';
			if ( $entry && is_file( $this->tmp . '/' . $entry ) ) {
				$ext  = pathinfo( $entry, PATHINFO_EXTENSION );
				$name = $kind . '-' . wp_generate_password( 8, false ) . ( $ext ? '.' . $ext : '' );
				$this->copies[] = array( 'src' => $this->tmp . '/' . $entry, 'dest' => trailingslashit( $upload['basedir'] ) . 'ktp-pdf-branding/' . $name );
				update_option( $option, 'ktp-pdf-branding/' . $name );
			}
		}

		// SMTP（パスワードは書き出されていないので空。画面で入れ直してもらう）
		$smtp = (array) ( $t['mail_smtp_settings'] ?? array() );
		if ( ! empty( $smtp['host'] ) ) {
			$old = get_option( 'ktp_smtp_settings', array() );
			$old = is_array( $old ) ? $old : array();
			$enc = strtolower( $this->str( $smtp['encryption'] ?? '' ) );
			update_option(
				'ktp_smtp_settings',
				array_merge(
					$old,
					array(
						'email_address'  => $this->str( $smtp['from_address'] ?? '' ),
						'smtp_host'      => $this->str( $smtp['host'] ),
						'smtp_port'      => (int) ( $smtp['port'] ?? 587 ),
						'smtp_user'      => $this->str( $smtp['username'] ?? '' ),
						'smtp_pass'      => '',
						'smtp_secure'    => in_array( $enc, array( 'ssl', 'tls' ), true ) ? $enc : '',
						'smtp_from_name' => $this->str( $smtp['from_name'] ?? '' ),
					)
				)
			);
			$this->counts['smtp_password_needed'] = 1;
		}
		$this->counts['settings'] = 1;
	}

	private function place_files() {
		foreach ( $this->copies as $c ) {
			wp_mkdir_p( dirname( $c['dest'] ) );
			if ( ! copy( $c['src'], $c['dest'] ) ) {
				WP_CLI::warning( 'ファイルを置けませんでした: ' . $c['dest'] );
			}
		}
	}

	private function rrmdir( $dir, $remove_self = true ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		if ( $remove_self ) {
			rmdir( $dir );
		}
	}
}

$ktp_dt_zip = isset( $args[0] ) ? $args[0] : '';
try {
	WP_CLI::line( ( new KTP_DT_KantanBiz_Import() )->run( $ktp_dt_zip ) );
} catch ( Throwable $e ) {
	WP_CLI::error( $e->getMessage() );
}
