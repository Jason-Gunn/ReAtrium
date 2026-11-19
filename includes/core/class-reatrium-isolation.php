<?php
/**
 * Public Website vs. Private Intranet Isolation Wall.
 *
 * Guarantees that internal intranet CPTs, REST routes, search results,
 * and feeds never leak onto the front-facing company website or blog.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Isolation {

	/**
	 * Intranet-only custom post types that must never appear publicly.
	 *
	 * @return string[]
	 */
	public static function intranet_post_types(): array {
		return (array) apply_filters(
			'reatrium_intranet_post_types',
			[ 'reatrium_kb', 'reatrium_announce', 'reatrium_request' ]
		);
	}

	/**
	 * Intranet-only taxonomies that must never be exposed publicly.
	 *
	 * @return string[]
	 */
	public static function intranet_taxonomies(): array {
		return (array) apply_filters(
			'reatrium_intranet_taxonomies',
			[ 'reatrium_department' ]
		);
	}

	/**
	 * WordPress roles permitted to access the intranet by default.
	 * Excludes public-facing roles like 'subscriber' and 'customer'.
	 *
	 * @return string[]
	 */
	public static function allowed_roles(): array {
		return (array) apply_filters(
			'reatrium_allowed_roles',
			[ 'administrator', 'editor', 'author', 'contributor' ]
		);
	}

	/**
	 * Check whether the current user is permitted to access intranet resources.
	 */
	public static function is_intranet_user(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user    = wp_get_current_user();
		$allowed = current_user_can( 'reatrium_access_intranet' )
			|| ! empty( array_intersect( (array) $user->roles, self::allowed_roles() ) );

		return (bool) apply_filters( 'reatrium_is_intranet_user', $allowed, $user );
	}

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'pre_get_posts', $this, 'exclude_intranet_from_public_queries' );
		$loader->add_filter( 'rest_pre_dispatch', $this, 'gate_intranet_rest_routes', 10, 3 );
		$loader->add_filter( 'wp_sitemaps_post_types', $this, 'exclude_from_sitemaps' );
		$loader->add_filter( 'wp_sitemaps_taxonomies', $this, 'exclude_taxonomies_from_sitemaps' );
	}

	/**
	 * Ensure public search, archives, and feeds never include intranet CPTs.
	 */
	public function exclude_intranet_from_public_queries( WP_Query $query ): void {
		if ( is_admin() || $query->get( 'reatrium_internal_query' ) ) {
			return;
		}

		$intranet_types = self::intranet_post_types();
		$post_type      = $query->get( 'post_type' );
		$is_public_view = $query->is_search() || $query->is_feed() || ! self::is_intranet_user();

		if ( is_string( $post_type ) && in_array( $post_type, $intranet_types, true ) ) {
			if ( ! self::is_intranet_user() ) {
				$query->set( 'post__in', [ 0 ] );
			}
			return;
		}

		if ( is_array( $post_type ) && $is_public_view ) {
			$filtered = array_values( array_diff( $post_type, $intranet_types ) );
			if ( empty( $filtered ) ) {
				$query->set( 'post__in', [ 0 ] );
			} else {
				$query->set( 'post_type', $filtered );
			}
			return;
		}

		if ( $post_type === 'any' && $is_public_view ) {
			$public_types = get_post_types( [ 'public' => true, 'exclude_from_search' => false ] );
			$filtered     = array_diff( $public_types, $intranet_types );
			$query->set( 'post_type', array_values( $filtered ) );
			return;
		}

		if ( $query->is_search() || $query->is_feed() ) {
			$public_types = get_post_types( [ 'public' => true, 'exclude_from_search' => false ] );
			$filtered     = array_diff( $public_types, $intranet_types );
			if ( empty( $post_type ) ) {
				$query->set( 'post_type', array_values( $filtered ) );
			}
		}
	}

	/**
	 * Block unauthenticated REST API access to ReAtrium routes, intranet CPTs, and taxonomies.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public function gate_intranet_rest_routes( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		$route = $request->get_route();

		$is_reatrium_route = str_starts_with( $route, '/reatrium/' );
		$is_intranet_cpt   = false;
		$is_request_cpt    = str_contains( $route, '/wp/v2/reatrium_request' );

		foreach ( self::intranet_post_types() as $cpt ) {
			if ( str_contains( $route, '/wp/v2/' . $cpt ) ) {
				$is_intranet_cpt = true;
				break;
			}
		}

		$is_intranet_tax = false;
		foreach ( self::intranet_taxonomies() as $tax ) {
			if ( str_contains( $route, '/wp/v2/' . $tax ) ) {
				$is_intranet_tax = true;
				break;
			}
		}

		if ( ( $is_reatrium_route || $is_intranet_cpt || $is_intranet_tax ) && ! self::is_intranet_user() ) {
			return new WP_Error(
				'reatrium_rest_forbidden',
				__( 'Authentication is required to access intranet resources.', 'reatrium' ),
				[ 'status' => 401 ]
			);
		}

		if ( $is_request_cpt && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'reatrium_rest_forbidden',
				__( 'You do not have permission to access internal requests via REST.', 'reatrium' ),
				[ 'status' => 403 ]
			);
		}

		return $result;
	}

	/**
	 * Exclude intranet CPTs from WordPress core XML sitemaps.
	 */
	public function exclude_from_sitemaps( array $post_types ): array {
		foreach ( self::intranet_post_types() as $cpt ) {
			unset( $post_types[ $cpt ] );
		}
		return $post_types;
	}

	/**
	 * Exclude intranet taxonomies from WordPress core XML sitemaps.
	 */
	public function exclude_taxonomies_from_sitemaps( array $taxonomies ): array {
		foreach ( self::intranet_taxonomies() as $tax ) {
			unset( $taxonomies[ $tax ] );
		}
		return $taxonomies;
	}
}

