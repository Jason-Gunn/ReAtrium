<?php
/**
 * Hybrid Frontend Intranet Portal (/intranet/).
 *
 * Provides a clean, distraction-free employee workspace outside wp-admin
 * while keeping wp-admin for administrators and AIGIS governance.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Portal {

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'on_init' );
		$loader->add_filter( 'query_vars', $this, 'register_query_vars' );
		$loader->add_action( 'template_redirect', $this, 'handle_portal_request' );
	}

	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^intranet(?:/([a-z0-9_-]+))?/?$',
			'index.php?reatrium_portal=1&reatrium_view=$matches[1]',
			'top'
		);
	}

	public function on_init(): void {
		self::register_rewrite_rules();
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'reatrium_portal';
		$vars[] = 'reatrium_view';
		return $vars;
	}

	/**
	 * Return registered portal navigation views (filterable by modules).
	 *
	 * @return array<string, array{label:string, icon:string, callback:callable|null}>
	 */
	public static function get_views(): array {
		$modules = (array) get_option( 'reatrium_modules', [] );

		$views = [
			'home' => [
				'label'    => __( 'Home & Feed', 'reatrium' ),
				'icon'     => '🏠',
				'callback' => null,
			],
		];

		if ( ! empty( $modules['knowledge_base'] ) ) {
			$views['handbook'] = [
				'label'    => __( 'Handbook & AI', 'reatrium' ),
				'icon'     => '📚',
				'callback' => null,
			];
		}
		if ( ! empty( $modules['directory'] ) ) {
			$views['directory'] = [
				'label'    => __( 'People Directory', 'reatrium' ),
				'icon'     => '👥',
				'callback' => null,
			];
		}
		if ( ! empty( $modules['requests'] ) ) {
			$views['requests'] = [
				'label'    => __( 'Requests & Helpdesk', 'reatrium' ),
				'icon'     => '📥',
				'callback' => null,
			];
		}

		return (array) apply_filters( 'reatrium_portal_views', $views );
	}

	/**
	 * Intercept /intranet/ requests, enforce login, and render the portal shell.
	 */
	public function handle_portal_request(): void {
		if ( ! get_query_var( 'reatrium_portal' ) ) {
			return;
		}

		$requested_view = sanitize_key( (string) get_query_var( 'reatrium_view', '' ) );
		$redirect_to    = $requested_view !== ''
			? home_url( '/intranet/' . $requested_view . '/' )
			: home_url( '/intranet/' );

		if ( ! ReAtrium_Isolation::is_intranet_user() ) {
			wp_safe_redirect( wp_login_url( $redirect_to ) );
			exit;
		}

		$views        = self::get_views();
		$current_view = $requested_view !== '' && isset( $views[ $requested_view ] ) ? $requested_view : 'home';

		// Allow modules to handle write POST submissions and perform POST-Redirect-GET before HTML output.
		do_action( 'reatrium_portal_before_render', $current_view );

		$company_name = (string) get_option( 'reatrium_company_name', get_bloginfo( 'name' ) );
		$current_user = wp_get_current_user();

		status_header( 200 );
		nocache_headers();

		include REATRIUM_PLUGIN_DIR . 'templates/portal-shell.php';
		exit;
	}
}

