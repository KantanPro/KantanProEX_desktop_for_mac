<?php
/**
 * KantanProEX（WP）の「デスクトップ版へ移行」で書き出した ZIP を、DT の業務データとして取り込む。
 *
 * 使い方（wp-cli）: wp eval-file import-wordpress.php <ZIPのパス> --user=<管理者>
 * 最後に「imported: key=N ...」の 1 行を出力する。失敗したときは例外（終了コード 1）。
 *
 * - WP 版と DT は同じプラグインなので、表は ID ごとそのまま入れる（表どうしの ID の参照を保つ）
 * - DT の業務データを入れ替える。呼び出し側が事前にバックアップを取る
 * - 1 つのトランザクションで行い、失敗したら元に戻す（ファイルはコミット後に配置）
 * - パスワード・API キー類は書き出し側で除かれている。SMTP のパスワードは空のまま
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KTP_DT_WordPress_Import {

	const FORMAT = 'kantanproex-wp-desktop-export';

	/** 入れ替える表（子から順に消す） */
	const TABLES = array(
		'ktp_dt_cost_payment', 'ktp_order_task', 'ktp_order_customer_mail_log', 'ktp_order_file',
		'ktp_order_staff_chat', 'ktp_order_cost_items', 'ktp_order_invoice_items', 'ktp_invoice_items',
		'ktp_contract_billing_log', 'ktp_contract_initial_fee', 'ktp_contract_recurring_item', 'ktp_contract',
		'ktp_order', 'ktp_service_initial_fee', 'ktp_service_recurring_item', 'ktp_service',
		'ktp_supplier_skills', 'ktp_supplier', 'ktp_department', 'ktp_client',
	);

	/** ユーザー ID を持つ列（WP 版のユーザー ID → DT のユーザー ID に付け替える） */
	const USER_COLUMNS = array(
		'ktp_order_staff_chat'        => 'user_id',
		'ktp_order_file'              => 'user_id',
		'ktp_order_customer_mail_log' => 'user_id',
	);

	/** 取り込む設定 */
	const OPTIONS = array(
		'ktp_general_settings', 'ktp_design_settings', 'ktp_smtp_settings', 'ktp_japanpost_api_settings',
		'ktp_pdf_document_settings', 'ktp_pdf_issuer_name', 'ktp_pdf_issuer_address', 'ktp_pdf_layout',
		'ktp_pdf_logo_path', 'ktp_pdf_seal_path', 'ktp_atena_label_position',
		'ktpwp_qualified_invoice_enabled', 'ktpwp_qualified_invoice_description',
	);

	/** @var wpdb */
	private $db;
	private $p;
	private $tmp;
	private $data;
	/** @var array<int,int> WP 版のユーザー ID => DT のユーザー ID */
	private $users = array();
	private $admin_id = 0;
	/** @var array<int, array{src:string,dest:string}> コミット後にコピーするファイル */
	private $copies = array();
	private $counts = array();
	/** @var array<string, array<string, bool>> 表ごとの実在する列 */
	private $columns = array();

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->p  = $wpdb->prefix;
	}

	public function run( $zip_path ) {
		if ( ! is_readable( $zip_path ) ) {
			throw new RuntimeException( 'ファイルを読めません: ' . $zip_path );
		}
		$this->tmp = sys_get_temp_dir() . '/ktp-wp-import-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp, 0700, true );
		try {
			$this->extract( $zip_path );
			$this->load_json();
			$this->ensure_extra_tables();

			$this->db->query( 'START TRANSACTION' );
			try {
				$this->clear_existing();
				$this->import_staff();
				$this->import_tables();
				$this->import_settings();
				$this->db->query( 'COMMIT' );
			} catch ( Throwable $e ) {
				$this->db->query( 'ROLLBACK' );
				throw $e;
			}
			$this->clear_file_dirs();
			$this->place_files();
			if ( class_exists( 'KTPWP_List_Warning_Counts' ) && method_exists( 'KTPWP_List_Warning_Counts', 'invalidate' ) ) {
				KTPWP_List_Warning_Counts::invalidate();
			}
			wp_cache_flush();
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
			if ( false !== strpos( $name, '..' ) || '/' === substr( $name, -1 ) || 0 === strpos( $name, '/' ) ) {
				continue;
			}
			if ( 'export.json' === $name || 0 === strpos( $name, 'files/uploads/' ) || 0 === strpos( $name, 'files/plugin-upload/' ) ) {
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
			throw new RuntimeException( 'WordPress 版の移行用ファイルではありません（export.json がありません）。' );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) {
			throw new RuntimeException( 'WordPress 版の移行用ファイルではありません。KantanProEX（WP）の「バックアップ」→「デスクトップ版へ移行」で書き出した ZIP を選んでください。' );
		}
		if ( (int) ( $data['version'] ?? 0 ) !== 1 ) {
			throw new RuntimeException( 'このファイルの版には対応していません。アプリを最新にしてください。' );
		}
		$this->data = $data;
	}

	private function t( $name ) {
		return $this->p . $name;
	}

	private function table_exists( $table ) {
		$name = $this->t( $table );
		return $name === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}

	/** 実在する列だけに絞る（WP 版の方が新しく、DT に無い列があっても止まらないように） */
	private function only_existing_columns( $table, array $row ) {
		$name = $this->t( $table );
		if ( ! isset( $this->columns[ $name ] ) ) {
			$cols = (array) $this->db->get_col( "SHOW COLUMNS FROM `{$name}`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->columns[ $name ] = array_fill_keys( $cols, true );
		}
		return array_intersect_key( $row, $this->columns[ $name ] );
	}

	private function bump( $key, $n = 1 ) {
		$this->counts[ $key ] = ( $this->counts[ $key ] ?? 0 ) + $n;
	}

	/** DT にだけある表（DT 版の機能）。古いデータ場所でも消せるように用意しておく */
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
		foreach ( self::TABLES as $table ) {
			if ( $this->table_exists( $table ) ) {
				$name = $this->t( $table );
				$this->db->query( "DELETE FROM `{$name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	/** オーナーは DT の管理者に、ほかの管理者・スタッフは DT のスタッフ（LAN 共有で使う人）にする */
	private function import_staff() {
		$admins         = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
		$this->admin_id = $admins ? (int) $admins[0]->ID : (int) get_current_user_id();
		foreach ( (array) ( $this->data['staff'] ?? array() ) as $s ) {
			$old = (int) ( $s['id'] ?? 0 );
			if ( ! $old ) {
				continue;
			}
			if ( ! empty( $s['owner'] ) ) {
				$this->users[ $old ] = $this->admin_id;
				continue;
			}
			$email = sanitize_email( (string) ( $s['email'] ?? '' ) );
			$login = (string) ( $s['login'] ?? '' );
			$user  = ( $email ? get_user_by( 'email', $email ) : false ) ?: ( $login ? get_user_by( 'login', $login ) : false );
			if ( $user ) {
				$this->users[ $old ] = (int) $user->ID;
				continue;
			}
			// LAN 共有のログイン名と同じ決まり（半角英数と _ . -）に合わないときは作り直す
			if ( ! preg_match( '/^[A-Za-z0-9_.-]{3,40}$/', $login ) || username_exists( $login ) ) {
				$login = 'wp-staff-' . $old;
			}
			$id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32 ),
					'user_email'   => $email ? $email : $login . '@invalid.example',
					'display_name' => '' !== (string) ( $s['display_name'] ?? '' ) ? (string) $s['display_name'] : $login,
					'role'         => 'editor',
				)
			);
			if ( is_wp_error( $id ) ) {
				continue;
			}
			$this->users[ $old ] = (int) $id;
			$this->bump( 'staff' );
		}
	}

	private function import_tables() {
		$upload = wp_upload_dir();
		$meta   = (array) ( $this->data['metadata'] ?? array() );
		foreach ( (array) ( $this->data['tables'] ?? array() ) as $table => $rows ) {
			$table = (string) $table;
			if ( ! in_array( $table, self::TABLES, true ) || ! $this->table_exists( $table ) ) {
				$this->bump( 'skipped_tables' );
				continue;
			}
			foreach ( (array) $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( isset( self::USER_COLUMNS[ $table ] ) ) {
					$col = self::USER_COLUMNS[ $table ];
					if ( isset( $row[ $col ] ) && (int) $row[ $col ] > 0 ) {
						$row[ $col ] = $this->users[ (int) $row[ $col ] ] ?? $this->admin_id;
					}
				}
				if ( 'ktp_service' === $table && ! empty( $row['image_url'] ) ) {
					$row['image_url'] = $this->service_image_url( (string) $row['image_url'], $meta, $upload );
				}
				$row = $this->only_existing_columns( $table, $row );
				if ( ! $row ) {
					continue;
				}
				if ( false === $this->db->insert( $this->t( $table ), $row ) ) {
					throw new RuntimeException( sprintf( '%s の取り込みに失敗しました（ID %s）: %s', $table, (string) ( $row['id'] ?? '?' ), $this->db->last_error ) );
				}
			}
			$this->bump( preg_replace( '/^ktp_/', '', $table ), count( (array) $rows ) );
		}
	}

	/** サービス画像の URL を DT の置き場所に付け替える（WP 版のサイトのアドレスのままだと表示できない） */
	private function service_image_url( $url, array $meta, array $upload ) {
		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' !== $name && ( is_file( $this->tmp . '/files/uploads/ktpwp-service-images/' . $name ) || is_file( $this->tmp . '/files/plugin-upload/' . $name ) ) ) {
			return trailingslashit( $upload['baseurl'] ) . 'ktpwp-service-images/' . $name;
		}
		$plugin_url = (string) ( $meta['plugin_url'] ?? '' );
		if ( '' !== $plugin_url && 0 === strpos( $url, $plugin_url ) && defined( 'KANTANPRO_PLUGIN_URL' ) ) {
			return trailingslashit( KANTANPRO_PLUGIN_URL ) . substr( $url, strlen( $plugin_url ) );
		}
		return $url;
	}

	private function import_settings() {
		$options = (array) ( $this->data['options'] ?? array() );
		foreach ( self::OPTIONS as $name ) {
			if ( ! array_key_exists( $name, $options ) ) {
				continue;
			}
			$value = $options[ $name ];
			switch ( $name ) {
				case 'ktp_general_settings':
				case 'ktp_design_settings':
					if ( ! is_array( $value ) ) {
						continue 2;
					}
					foreach ( array_keys( $value ) as $key ) {
						if ( 0 === strpos( (string) $key, 'stripe_' ) || 'ktp_page_id' === $key ) {
							unset( $value[ $key ] );
						}
					}
					$old   = get_option( $name, array() );
					$value = array_merge( is_array( $old ) ? $old : array(), $value );
					break;
				case 'ktp_smtp_settings':
					if ( ! is_array( $value ) ) {
						continue 2;
					}
					$old   = get_option( $name, array() );
					$value = array_merge( is_array( $old ) ? $old : array(), $value, array( 'smtp_pass' => '' ) );
					if ( ! empty( $value['smtp_host'] ) ) {
						$this->counts['smtp_password_needed'] = 1;
					}
					break;
				case 'ktp_japanpost_api_settings':
					if ( ! is_array( $value ) ) {
						continue 2;
					}
					// シークレットキーは運ばないので、入れ直すまでは止めておく
					if ( ! empty( $value['enabled'] ) ) {
						$this->counts['japanpost_key_needed'] = 1;
					}
					$old   = get_option( $name, array() );
					$value = array_merge( is_array( $old ) ? $old : array(), $value, array( 'enabled' => false, 'secret_key' => '' ) );
					break;
				case 'ktp_pdf_logo_path':
				case 'ktp_pdf_seal_path':
					$value = (string) $value;
					if ( '' !== $value && ! is_file( $this->tmp . '/files/uploads/' . ltrim( $value, '/' ) ) ) {
						$value = '';
					}
					break;
			}
			update_option( $name, $value );
		}
		$this->counts['settings'] = 1;
	}

	/** 前のデータのファイル（ダミーデータのサービス画像など）が、取り込んだ同じ ID のデータに出ないよう空にする */
	private function clear_file_dirs() {
		$upload = wp_upload_dir();
		foreach ( array( 'ktp-order-files', 'ktpwp-service-images' ) as $dir ) {
			$this->rrmdir( trailingslashit( $upload['basedir'] ) . $dir, false );
		}
	}

	private function place_files() {
		$upload = trailingslashit( wp_upload_dir()['basedir'] );
		$n      = 0;
		$src    = $this->tmp . '/files/uploads';
		if ( is_dir( $src ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$this->copies[] = array( 'src' => $f->getPathname(), 'dest' => $upload . ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $src ) ) ), '/' ) ); // Windows の \ もそろえる
				}
			}
		}
		// 古い版でプラグインの中に置いていたサービス画像は、DT のサービス画像の置き場所へ
		$legacy = $this->tmp . '/files/plugin-upload';
		if ( is_dir( $legacy ) ) {
			foreach ( (array) scandir( $legacy ) as $name ) {
				if ( is_file( $legacy . '/' . $name ) && ! is_file( $src . '/ktpwp-service-images/' . $name ) ) {
					$this->copies[] = array( 'src' => $legacy . '/' . $name, 'dest' => $upload . 'ktpwp-service-images/' . $name );
				}
			}
		}
		foreach ( $this->copies as $c ) {
			wp_mkdir_p( dirname( $c['dest'] ) );
			if ( copy( $c['src'], $c['dest'] ) ) {
				$n++;
			} else {
				WP_CLI::warning( 'ファイルを置けませんでした: ' . $c['dest'] );
			}
		}
		$this->counts['files'] = $n;
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
	WP_CLI::line( ( new KTP_DT_WordPress_Import() )->run( $ktp_dt_zip ) );
} catch ( Throwable $e ) {
	WP_CLI::error( $e->getMessage() );
}
