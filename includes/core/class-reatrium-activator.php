<?php
/**
 * Fired during ReAtrium plugin activation.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Activator {

	public static function activate(): void {
		self::create_tables();
		self::seed_defaults();
		ReAtrium_Portal::register_rewrite_rules();
		flush_rewrite_rules();

		update_option( 'reatrium_version', REATRIUM_VERSION );
		update_option( 'reatrium_db_version', '1.0.0' );
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		// Self-contained MySQL vector/chunk storage for SMB Knowledge Base RAG.
		$sql = "CREATE TABLE {$prefix}reatrium_embeddings (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id      BIGINT(20) UNSIGNED NOT NULL,
			chunk_index  INT UNSIGNED        NOT NULL DEFAULT 0,
			department   VARCHAR(100)        NOT NULL DEFAULT '',
			chunk_text   LONGTEXT            NOT NULL,
			embedding    LONGTEXT            NOT NULL,
			model_name   VARCHAR(150)        NOT NULL DEFAULT '',
			updated_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_department (department)
		) $charset_collate;";
		dbDelta( $sql );
	}

	private static function seed_defaults(): void {
		if ( false === get_option( 'reatrium_company_name' ) ) {
			add_option( 'reatrium_company_name', get_bloginfo( 'name' ) ?: 'Our Company' );
		}
		if ( false === get_option( 'reatrium_departments' ) ) {
			add_option( 'reatrium_departments', [ 'General', 'Operations', 'IT', 'HR', 'Sales' ] );
		}
		if ( false === get_option( 'reatrium_modules' ) ) {
			add_option( 'reatrium_modules', [
				'assistant'      => true,
				'knowledge_base' => true,
				'announcements'  => true,
				'directory'      => true,
				'requests'       => true,
				'public_ai'      => true,
			] );
		}
		if ( false === get_option( 'reatrium_setup_completed' ) ) {
			add_option( 'reatrium_setup_completed', false );
		}
	}
}

