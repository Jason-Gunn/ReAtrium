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

		// Modular Intranet Features.
		$modules = (array) get_option( 'reatrium_modules', [] );
		if ( ! empty( $modules['announcements'] ) || ! isset( $modules['announcements'] ) ) {
			( new ReAtrium_Module_Announcements() )->register( $this->loader );
		}
		if ( ! empty( $modules['knowledge_base'] ) ) {
			( new ReAtrium_Module_KB() )->register( $this->loader );
		}
		if ( ! empty( $modules['directory'] ) ) {
			( new ReAtrium_Module_Directory() )->register( $this->loader );
		}
		if ( ! empty( $modules['requests'] ) ) {
			( new ReAtrium_Module_Requests() )->register( $this->loader );
		}
		if ( ! empty( $modules['public_ai'] ) ) {
			( new ReAtrium_Module_Public_AI() )->register( $this->loader );
		}

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

