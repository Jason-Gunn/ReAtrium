<?php
/**
 * Clean uninstall for ReAtrium.
 *
 * @package ReAtrium
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}reatrium_embeddings`" );

delete_option( 'reatrium_version' );
delete_option( 'reatrium_db_version' );
delete_option( 'reatrium_company_name' );
delete_option( 'reatrium_departments' );
delete_option( 'reatrium_modules' );
delete_option( 'reatrium_setup_completed' );

