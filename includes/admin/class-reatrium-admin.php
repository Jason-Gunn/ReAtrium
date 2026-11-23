<?php
/**
 * WordPress Admin menus, Setup Wizard, and Settings for ReAtrium.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Admin {

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'admin_menu', $this, 'register_menus' );
		$loader->add_action( 'admin_head', $this, 'render_admin_menu_icon_styles' );
		$loader->add_action( 'admin_init', $this, 'maybe_sync_aigis_agents' );
	}

	/**
	 * Register all agents declared by enabled ReAtrium modules (core + community)
	 * in AIGIS v2 when active (cached daily).
	 */
	public function maybe_sync_aigis_agents( bool $force = false ): void {
		if ( ! ReAtrium_AI_Gateway::is_aigis_active() ) {
			return;
		}
		if ( ! $force && get_transient( 'reatrium_aigis_agents_synced' ) ) {
			return;
		}

		$agents = ReAtrium_Module_Registry::instance()->collect_agents();

		foreach ( $agents as $agent ) {
			do_action( 'aigis_register_agent_if_missing', $agent );
		}

		set_transient( 'reatrium_aigis_agents_synced', 1, DAY_IN_SECONDS );
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'ReAtrium Intranet', 'reatrium' ),
			__( 'ReAtrium', 'reatrium' ),
			'edit_posts',
			'reatrium-settings',
			[ $this, 'render_settings' ],
			REATRIUM_PLUGIN_URL . 'assets/img/reatrium-logo.svg',
			31
		);

		add_submenu_page(
			'reatrium-settings',
			__( 'Settings & Modules', 'reatrium' ),
			__( 'Settings & Modules', 'reatrium' ),
			'edit_posts',
			'reatrium-settings',
			[ $this, 'render_settings' ]
		);

		add_submenu_page(
			'reatrium-settings',
			__( 'Setup Wizard', 'reatrium' ),
			__( 'Setup Wizard', 'reatrium' ),
			'manage_options',
			'reatrium-setup',
			[ $this, 'render_setup_wizard' ]
		);
	}

	/**
	 * Keep the custom SVG icon neatly sized in the WordPress admin sidebar.
	 */
	public function render_admin_menu_icon_styles(): void {
		echo '<style>#toplevel_page_reatrium-settings .wp-menu-image img{width:20px;height:20px;padding-top:7px;opacity:1;}</style>';
	}

	public function render_setup_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reatrium' ) );
		}

		$registry = ReAtrium_Module_Registry::instance();
		$saved    = false;

		if ( isset( $_POST['reatrium_wizard_save'] ) && check_admin_referer( 'reatrium_wizard_nonce' ) ) {
			$company = sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) );
			$depts   = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['departments'] ?? '' ) ) ) ) ) );

			$posted_modules = isset( $_POST['reatrium_modules'] ) && is_array( $_POST['reatrium_modules'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['reatrium_modules'] ) )
				: [];

			$modules = [];
			foreach ( $registry->all() as $mod_id => $module ) {
				$modules[ $mod_id ] = in_array( $mod_id, $posted_modules, true );
			}

			update_option( 'reatrium_company_name', $company ?: get_bloginfo( 'name' ) );
			update_option( 'reatrium_departments', $depts ?: [ 'General' ] );
			update_option( 'reatrium_modules', $modules );
			update_option( 'reatrium_setup_completed', true );
			ReAtrium_Portal::register_rewrite_rules();
			flush_rewrite_rules();
			$this->maybe_sync_aigis_agents( true );

			if ( ! empty( $_POST['seed_sample_data'] ) ) {
				ReAtrium_Sample_Data::seed();
			}

			$saved = true;
		}

		include REATRIUM_PLUGIN_DIR . 'includes/admin/views/setup-wizard.php';
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reatrium' ) );
		}

		$registry   = ReAtrium_Module_Registry::instance();
		$active_tab = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
		$notice     = '';

		if ( current_user_can( 'manage_options' ) ) {
			// Seed or Wipe Demo / Sample Data.
			if ( isset( $_POST['reatrium_sample_action'] ) && check_admin_referer( 'reatrium_sample_data_nonce' ) ) {
				$sample_action = sanitize_key( wp_unslash( $_POST['reatrium_sample_action'] ) );
				if ( $sample_action === 'seed' ) {
					$res    = ReAtrium_Sample_Data::seed();
					$notice = sprintf(
						/* translators: 1: users, 2: announcements, 3: kb, 4: requests */
						__( 'Sample data generated: %1$d team members, %2$d announcements, %3$d handbook SOPs, and %4$d requests.', 'reatrium' ),
						$res['users'],
						$res['announcements'],
						$res['kb'],
						$res['requests']
					);
				} elseif ( $sample_action === 'wipe' ) {
					$res    = ReAtrium_Sample_Data::wipe();
					$notice = sprintf(
						/* translators: 1: users, 2: announcements, 3: kb, 4: requests */
						__( 'Sample data wiped: %1$d team members, %2$d announcements, %3$d handbook SOPs, and %4$d requests removed.', 'reatrium' ),
						$res['users'],
						$res['announcements'],
						$res['kb'],
						$res['requests']
					);
				}
				$active_tab = 'overview';
			}

			// Save Modules tab.
			if ( isset( $_POST['reatrium_save_modules'] ) && check_admin_referer( 'reatrium_settings_modules_nonce' ) ) {
				$posted = isset( $_POST['reatrium_modules'] ) && is_array( $_POST['reatrium_modules'] )
					? array_map( 'sanitize_key', wp_unslash( $_POST['reatrium_modules'] ) )
					: [];

				$modules = [];
				foreach ( $registry->all() as $mod_id => $module ) {
					$modules[ $mod_id ] = in_array( $mod_id, $posted, true );
				}

				update_option( 'reatrium_modules', $modules );
				ReAtrium_Portal::register_rewrite_rules();
				flush_rewrite_rules();
				$this->maybe_sync_aigis_agents( true );
				$active_tab = 'modules';
				$notice     = __( 'Module settings saved.', 'reatrium' );
			}

			// Save Access & Isolation tab.
			if ( isset( $_POST['reatrium_save_access'] ) && check_admin_referer( 'reatrium_settings_access_nonce' ) ) {
				$posted_roles = isset( $_POST['reatrium_allowed_roles'] ) && is_array( $_POST['reatrium_allowed_roles'] )
					? array_values( array_unique( array_map( 'sanitize_key', wp_unslash( $_POST['reatrium_allowed_roles'] ) ) ) )
					: [];

				if ( ! in_array( 'administrator', $posted_roles, true ) ) {
					$posted_roles[] = 'administrator';
				}

				update_option( 'reatrium_allowed_roles', $posted_roles );
				$active_tab = 'access';
				$notice     = __( 'Access & Isolation settings saved.', 'reatrium' );
			}

			// Save Branding tab.
			if ( isset( $_POST['reatrium_save_branding'] ) && check_admin_referer( 'reatrium_settings_branding_nonce' ) ) {
				$company = sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) );
				$depts   = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['departments'] ?? '' ) ) ) ) ) );
				$accent  = sanitize_hex_color( wp_unslash( $_POST['accent_color'] ?? '#e8420a' ) ) ?: '#e8420a';
				$logo    = esc_url_raw( trim( (string) wp_unslash( $_POST['logo_url'] ?? '' ) ) );

				update_option( 'reatrium_company_name', $company ?: get_bloginfo( 'name' ) );
				update_option( 'reatrium_departments', $depts ?: [ 'General' ] );
				update_option( 'reatrium_accent_color', $accent );
				update_option( 'reatrium_logo_url', $logo );
				$active_tab = 'branding';
				$notice     = __( 'Branding settings saved.', 'reatrium' );
			}
		}

		include REATRIUM_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}
}
