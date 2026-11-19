<?php
/**
 * Clean uninstall for ReAtrium.
 *
 * Removes all ReAtrium database tables, CPT posts, taxonomy terms,
 * post meta, user meta, options, transients, and rewrite rules.
 *
 * @package ReAtrium
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Drop custom embeddings table.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}reatrium_embeddings`" );

// 2. Delete all ReAtrium CPT posts (including drafts, trash, revisions, and associated meta).
$post_ids = $wpdb->get_col(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('reatrium_kb', 'reatrium_announce', 'reatrium_request')"
);
if ( ! empty( $post_ids ) ) {
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

// Remove any orphaned ReAtrium post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_reatrium\\_%'" );

// 3. Delete all reatrium_department taxonomy terms and relationships (taxonomy is not registered during uninstall).
$term_rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
		'reatrium_department'
	)
);
if ( ! empty( $term_rows ) ) {
	foreach ( $term_rows as $row ) {
		$wpdb->delete( $wpdb->term_relationships, [ 'term_taxonomy_id' => (int) $row->term_taxonomy_id ], [ '%d' ] );
		$wpdb->delete( $wpdb->term_taxonomy, [ 'term_taxonomy_id' => (int) $row->term_taxonomy_id ], [ '%d' ] );

		$still_used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d",
				(int) $row->term_id
			)
		);
		if ( $still_used === 0 ) {
			$wpdb->delete( $wpdb->termmeta, [ 'term_id' => (int) $row->term_id ], [ '%d' ] );
			$wpdb->delete( $wpdb->terms, [ 'term_id' => (int) $row->term_id ], [ '%d' ] );
		}
	}
}

// 4. Delete all ReAtrium user meta across all users.
foreach ( [ 'reatrium_department', 'reatrium_job_title', 'reatrium_skills' ] as $meta_key ) {
	delete_metadata( 'user', 0, $meta_key, '', true );
}

// 5. Delete all ReAtrium options and transients.
$options = [
	'reatrium_version',
	'reatrium_db_version',
	'reatrium_company_name',
	'reatrium_departments',
	'reatrium_modules',
	'reatrium_setup_completed',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'reatrium_aigis_agents_synced' );
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'reatrium\\_%' OR option_name LIKE '_transient_reatrium\\_%' OR option_name LIKE '_transient_timeout_reatrium\\_%'"
);

// 6. Flush rewrite rules to remove /intranet/ rules.
flush_rewrite_rules();
