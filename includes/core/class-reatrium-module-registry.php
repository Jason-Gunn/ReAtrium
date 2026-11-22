<?php
/**
 * ReAtrium Module Registry.
 *
 * Central registry for built-in and community-contributed ReAtrium modules.
 * Third-party plugins register modules via:
 *
 *     add_action( 'reatrium_register_modules', function ( ReAtrium_Module_Registry $registry ) {
 *         $registry->register( new My_Custom_ReAtrium_Module() );
 *     } );
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Registry {

	private static ?self $instance = null;

	/** @var array<string, ReAtrium_Module_Interface> */
	private array $modules = [];

	private bool $discovered = false;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a module instance by its unique ID.
	 */
	public function register( ReAtrium_Module_Interface $module ): void {
		$id = sanitize_key( $module->id() );
		if ( $id === '' ) {
			return;
		}
		$this->modules[ $id ] = $module;
	}

	/**
	 * Allow third-party plugins to register their modules once.
	 */
	public function discover(): void {
		if ( $this->discovered ) {
			return;
		}
		$this->discovered = true;

		/**
		 * Fires when ReAtrium discovers available modules.
		 *
		 * @param ReAtrium_Module_Registry $registry The module registry instance.
		 */
		do_action( 'reatrium_register_modules', $this );
	}

	/**
	 * Return all registered modules keyed by ID.
	 *
	 * @return array<string, ReAtrium_Module_Interface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * Return a single registered module by ID.
	 */
	public function get( string $id ): ?ReAtrium_Module_Interface {
		return $this->modules[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Check whether a registered module is currently enabled.
	 */
	public function is_enabled( string $id ): bool {
		$id     = sanitize_key( $id );
		$module = $this->get( $id );
		if ( $module === null ) {
			return false;
		}

		$saved = get_option( 'reatrium_modules', [] );
		if ( is_array( $saved ) && array_key_exists( $id, $saved ) ) {
			return (bool) $saved[ $id ];
		}

		return $module->default_enabled();
	}

	/**
	 * Return only currently enabled modules.
	 *
	 * @return array<string, ReAtrium_Module_Interface>
	 */
	public function enabled(): array {
		return array_filter(
			$this->modules,
			fn( ReAtrium_Module_Interface $m, string $id ) => $this->is_enabled( $id ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Discover external modules, register isolation rules, and boot enabled modules.
	 */
	public function boot_enabled( ReAtrium_Loader $loader ): void {
		$this->discover();

		foreach ( $this->enabled() as $module ) {
			$module->register( $loader );
		}

		// Automatically feed intranet CPTs and taxonomies from enabled modules into ReAtrium_Isolation.
		$loader->add_filter( 'reatrium_intranet_post_types', $this, 'filter_intranet_post_types' );
		$loader->add_filter( 'reatrium_intranet_taxonomies', $this, 'filter_intranet_taxonomies' );
	}

	/**
	 * Merge intranet-private CPTs declared by enabled modules.
	 *
	 * @param string[] $post_types
	 * @return string[]
	 */
	public function filter_intranet_post_types( array $post_types ): array {
		foreach ( $this->enabled() as $module ) {
			if ( $module->zone() === 'public' ) {
				continue;
			}
			foreach ( $module->post_types() as $cpt ) {
				$cpt = sanitize_key( (string) $cpt );
				if ( $cpt !== '' && ! in_array( $cpt, $post_types, true ) ) {
					$post_types[] = $cpt;
				}
			}
		}
		return $post_types;
	}

	/**
	 * Merge intranet-private taxonomies declared by enabled modules.
	 *
	 * @param string[] $taxonomies
	 * @return string[]
	 */
	public function filter_intranet_taxonomies( array $taxonomies ): array {
		foreach ( $this->enabled() as $module ) {
			if ( $module->zone() === 'public' ) {
				continue;
			}
			foreach ( $module->taxonomies() as $tax ) {
				$tax = sanitize_key( (string) $tax );
				if ( $tax !== '' && ! in_array( $tax, $taxonomies, true ) ) {
					$taxonomies[] = $tax;
				}
			}
		}
		return $taxonomies;
	}

	/**
	 * Collect WP 7+ Ability names from enabled modules, optionally filtered by zone.
	 *
	 * @param string|null $zone Optional zone filter ('intranet', 'public', 'hybrid').
	 * @return string[]
	 */
	public function collect_abilities( ?string $zone = null ): array {
		$abilities = [];
		foreach ( $this->enabled() as $module ) {
			if ( $zone !== null && $module->zone() !== $zone ) {
				continue;
			}
			foreach ( $module->abilities() as $ability ) {
				$ability = trim( (string) $ability );
				if ( $ability !== '' && ! in_array( $ability, $abilities, true ) ) {
					$abilities[] = $ability;
				}
			}
		}
		return $abilities;
	}

	/**
	 * Collect all AIGIS agent definitions from enabled modules.
	 *
	 * @return array[]
	 */
	public function collect_agents(): array {
		$agents = [];
		foreach ( $this->enabled() as $module ) {
			foreach ( $module->agents() as $agent ) {
				if ( is_array( $agent ) && ! empty( $agent['slug'] ) ) {
					$agent['module'] = $module->id();
					$agent['zone']   = $agent['zone'] ?? $module->zone();
					$agents[]        = $agent;
				}
			}
		}
		return $agents;
	}
}
