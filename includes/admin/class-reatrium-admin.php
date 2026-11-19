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
		$loader->add_action( 'admin_init', $this, 'maybe_sync_aigis_agents' );
	}

	/**
	 * Register ReAtrium's built-in agents in AIGIS v2 if active (cached daily).
	 */
	public function maybe_sync_aigis_agents( bool $force = false ): void {
		if ( ! ReAtrium_AI_Gateway::is_aigis_active() ) {
			return;
		}
		if ( ! $force && get_transient( 'reatrium_aigis_agents_synced' ) ) {
			return;
		}

		$agents = [
			[
				'slug'              => 'reatrium-digest',
				'name'              => 'ReAtrium Announcements Digest',
				'description'       => 'Summarizes internal company announcements for employees.',
				'allowed_abilities' => [ 'reatrium/summarize-updates', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
			],
			[
				'slug'              => 'reatrium-handbook',
				'name'              => 'ReAtrium Handbook Assistant',
				'description'       => 'Answers employee questions from internal SOPs and handbook articles.',
				'allowed_abilities' => [ 'reatrium/ask-handbook', 'reatrium/draft-sop', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
			],
			[
				'slug'              => 'reatrium-directory',
				'name'              => 'ReAtrium People Finder',
				'description'       => 'Matches employee questions to colleagues and departments.',
				'allowed_abilities' => [ 'reatrium/find-expert', 'aigis/check-guardrail' ],
				'pii_policy'        => 'warn',
			],
			[
				'slug'              => 'reatrium-triage',
				'name'              => 'ReAtrium Helpdesk Triage',
				'description'       => 'Categorizes internal IT/HR/Ops requests and drafts initial responses.',
				'allowed_abilities' => [ 'reatrium/triage-request', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
			],
			[
				'slug'              => 'reatrium-public-writer',
				'name'              => 'ReAtrium Public Blog & FAQ Drafter',
				'description'       => 'Drafts public website content isolated from private intranet data.',
				'allowed_abilities' => [ 'reatrium/draft-public-post', 'aigis/check-guardrail' ],
				'pii_policy'        => 'block',
			],
		];

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
			'dashicons-building',
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

	public function render_setup_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reatrium' ) );
		}

		$saved = false;
		if ( isset( $_POST['reatrium_wizard_save'] ) && check_admin_referer( 'reatrium_wizard_nonce' ) ) {
			$company = sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) );
			$depts   = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['departments'] ?? '' ) ) ) ) ) );

			$modules = [
				'knowledge_base' => ! empty( $_POST['mod_knowledge_base'] ),
				'announcements'  => ! empty( $_POST['mod_announcements'] ),
				'directory'      => ! empty( $_POST['mod_directory'] ),
				'requests'       => ! empty( $_POST['mod_requests'] ),
				'public_ai'      => ! empty( $_POST['mod_public_ai'] ),
			];

			update_option( 'reatrium_company_name', $company ?: get_bloginfo( 'name' ) );
			update_option( 'reatrium_departments', $depts ?: [ 'General' ] );
			update_option( 'reatrium_modules', $modules );
			update_option( 'reatrium_setup_completed', true );
			ReAtrium_Portal::register_rewrite_rules();
			flush_rewrite_rules();
			$this->maybe_sync_aigis_agents( true );

			if ( ! empty( $_POST['seed_sample_data'] ) ) {
				$this->seed_sample_data( $modules );
			}

			$saved = true;
		}

		include REATRIUM_PLUGIN_DIR . 'includes/admin/views/setup-wizard.php';
	}

	private function seed_sample_data( array $modules = [] ): void {
		if ( ! empty( $modules['announcements'] ) && ! get_posts( [ 'post_type' => 'reatrium_announce', 'posts_per_page' => 1, 'reatrium_internal_query' => true ] ) ) {
			wp_insert_post( [
				'post_type'    => 'reatrium_announce',
				'post_status'  => 'publish',
				'post_title'   => __( 'Welcome to ReAtrium!', 'reatrium' ),
				'post_content' => __( 'Our new company intranet is live. Use the Handbook tab to search SOPs, or submit IT/HR requests anytime.', 'reatrium' ),
				'post_author'  => get_current_user_id(),
			] );
		}

		if ( ! empty( $modules['knowledge_base'] ) && ! get_posts( [ 'post_type' => 'reatrium_kb', 'posts_per_page' => 1, 'reatrium_internal_query' => true ] ) ) {
			if ( ! taxonomy_exists( 'reatrium_department' ) ) {
				( new ReAtrium_Module_KB() )->register_cpt_and_taxonomy();
			}

			$kb_id = wp_insert_post( [
				'post_type'    => 'reatrium_kb',
				'post_status'  => 'publish',
				'post_title'   => __( 'PTO & Holiday Policy', 'reatrium' ),
				'post_content' => __( 'Full-time employees receive 15 days of paid time off per year plus company holidays. Submit PTO requests at least 2 weeks in advance.', 'reatrium' ),
				'post_author'  => get_current_user_id(),
			] );
			if ( $kb_id && ! is_wp_error( $kb_id ) ) {
				wp_set_object_terms( $kb_id, 'HR', 'reatrium_department' );
				$kb_post = get_post( $kb_id );
				if ( $kb_post instanceof WP_Post ) {
					( new ReAtrium_Module_KB() )->index_kb_post( $kb_id, $kb_post );
				}
			}
		}
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reatrium' ) );
		}

		include REATRIUM_PLUGIN_DIR . 'includes/admin/views/settings.php';
	}
}

