<?php
/**
 * Fired during ReAtrium plugin deactivation.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Deactivator {

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

