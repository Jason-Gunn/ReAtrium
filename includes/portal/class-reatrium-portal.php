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

	private const REWRITE_PATTERN = '^intranet(?:/([a-z0-9_-]+))?/?$';

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'on_init' );
		$loader->add_filter( 'query_vars', $this, 'register_query_vars' );
		$loader->add_action( 'template_redirect', $this, 'handle_portal_request', 1 );
	}

	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			self::REWRITE_PATTERN,
			'index.php?reatrium_portal=1&reatrium_view=$matches[1]',
			'top'
		);
	}

	/**
	 * Build a portal URL that works with both pretty permalinks (/intranet/)
	 * and Plain permalinks (?reatrium_portal=1).
	 */
	public static function portal_url( string $view = '' ): string {
		$view = sanitize_key( $view );
		if ( get_option( 'permalink_structure' ) !== '' ) {
			return ( $view === '' || $view === 'home' )
				? home_url( '/intranet/' )
				: home_url( '/intranet/' . $view . '/' );
		}

		$args = [ 'reatrium_portal' => '1' ];
		if ( $view !== '' && $view !== 'home' ) {
			$args['reatrium_view'] = $view;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	public function on_init(): void {
		self::register_rewrite_rules();

		// Auto-flush once if pretty permalinks are active but our rewrite rule is missing.
		if ( get_option( 'permalink_structure' ) !== '' ) {
			$rules = get_option( 'rewrite_rules' );
			if ( is_array( $rules ) && ! isset( $rules[ self::REWRITE_PATTERN ] ) ) {
				flush_rewrite_rules( false );
			}
		}
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
		$is_portal      = (bool) get_query_var( 'reatrium_portal' );
		$requested_view = sanitize_key( (string) get_query_var( 'reatrium_view', '' ) );

		// Fallback path matching in case rewrite rules haven't matched or were bypassed.
		if ( ! $is_portal && isset( $_SERVER['REQUEST_URI'] ) ) {
			$req_path  = (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$base      = rtrim( $home_path, '/' );
			$relative  = $base !== '' && str_starts_with( $req_path, $base )
				? ltrim( substr( $req_path, strlen( $base ) ), '/' )
				: ltrim( $req_path, '/' );

			if ( preg_match( '#^intranet(?:/([a-z0-9_-]+))?/?$#i', $relative, $matches ) ) {
				$is_portal      = true;
				$requested_view = isset( $matches[1] ) ? sanitize_key( $matches[1] ) : '';
			}
		}

		if ( ! $is_portal ) {
			return;
		}

		$redirect_to = self::portal_url( $requested_view );

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

		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}

		status_header( 200 );
		nocache_headers();

		include REATRIUM_PLUGIN_DIR . 'templates/portal-shell.php';
		exit;
	}
}

