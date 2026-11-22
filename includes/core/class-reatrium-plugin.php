<?php
/**
 * Main ReAtrium plugin orchestrator.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Plugin {

	private ReAtrium_Loader $loader;

	public function __construct() {
		$this->loader = new ReAtrium_Loader();
	}

	private function init(): void {
		// Register shared WP 7+ Ability category for ReAtrium.
		$this->loader->add_action( 'wp_abilities_api_categories_init', $this, 'register_ability_category' );

		// Public vs. Intranet Isolation Wall.
		( new ReAtrium_Isolation() )->register( $this->loader );

		// Hybrid Frontend Portal (/intranet/).
		( new ReAtrium_Portal() )->register( $this->loader );

		// Modular Intranet & Public AI Features via extensible Registry.
		$registry = ReAtrium_Module_Registry::instance();
		$registry->register( new ReAtrium_Module_Announcements() );
		$registry->register( new ReAtrium_Module_Assistant() );
		$registry->register( new ReAtrium_Module_KB() );
		$registry->register( new ReAtrium_Module_Directory() );
		$registry->register( new ReAtrium_Module_Requests() );
		$registry->register( new ReAtrium_Module_Public_AI() );
		$registry->boot_enabled( $this->loader );

		// Admin Setup Wizard & Settings.
		if ( is_admin() ) {
			( new ReAtrium_Admin() )->register( $this->loader );
		}
	}

	public function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'reatrium-intranet',
			[
				'label'       => __( 'ReAtrium Intranet', 'reatrium' ),
				'description' => __( 'Internal company handbook, announcements, directory, and request triage abilities.', 'reatrium' ),
			]
		);
	}

	public function run(): void {
		$this->init();
		$this->loader->run();
	}
}

