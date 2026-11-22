<?php
/**
 * Plugin Name:       ReAtrium Module Starter — Employee Onboarding
 * Plugin URI:        https://dialogisticindustries.com/solutions/software/reatrium/
 * Description:       Example standalone community module for ReAtrium. Adds private Employee Onboarding Checklists, an AI onboarding plan generator, and a WP 7+ Ability.
 * Version:           1.0.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Dialogistic Industries
 * License:           GPL v2 or later
 * Text Domain:       reatrium-onboarding
 *
 * @package ReAtrium_Starter_Onboarding
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Onboarding module with ReAtrium Core when active.
 */
add_action(
	'reatrium_register_modules',
	static function ( ReAtrium_Module_Registry $registry ): void {
		require_once __DIR__ . '/includes/class-reatrium-module-onboarding.php';
		$registry->register( new ReAtrium_Module_Onboarding() );
	}
);

/**
 * Show an admin notice if ReAtrium Core is not active.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( class_exists( 'ReAtrium_Module_Registry' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'ReAtrium Module Starter (Employee Onboarding) requires the ReAtrium core plugin to be active.', 'reatrium-onboarding' );
		echo '</p></div>';
	}
);
