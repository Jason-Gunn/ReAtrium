<?php
/**
 * ReAtrium Module Interface.
 *
 * Both built-in modules and third-party community module plugins implement
 * this contract (typically by extending ReAtrium_Abstract_Module) and register
 * themselves on the `reatrium_register_modules` action.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ReAtrium_Module_Interface {

	/**
	 * Unique machine-readable module identifier (e.g. 'knowledge_base', 'acme_pto').
	 */
	public function id(): string;

	/**
	 * Human-readable module name displayed in Settings and Setup Wizard.
	 */
	public function name(): string;

	/**
	 * Short description of what the module provides.
	 */
	public function description(): string;

	/**
	 * Icon character or short badge label for admin cards and navigation.
	 */
	public function icon(): string;

	/**
	 * Semantic version string of the module.
	 */
	public function version(): string;

	/**
	 * Operating zone: 'intranet', 'public', or 'hybrid'.
	 */
	public function zone(): string;

	/**
	 * Whether this module ships with ReAtrium core (false for community add-ons).
	 */
	public function is_core(): bool;

	/**
	 * Whether the module should be enabled by default when no saved preference exists.
	 */
	public function default_enabled(): bool;

	/**
	 * Intranet-private custom post type slugs owned by this module.
	 * Automatically added to ReAtrium_Isolation when the module is enabled.
	 *
	 * @return string[]
	 */
	public function post_types(): array;

	/**
	 * Intranet-private taxonomy slugs owned by this module.
	 * Automatically added to ReAtrium_Isolation when the module is enabled.
	 *
	 * @return string[]
	 */
	public function taxonomies(): array;

	/**
	 * AIGIS v2 agent definitions owned by this module.
	 * Automatically synced to AIGIS when the module is enabled.
	 *
	 * @return array[]
	 */
	public function agents(): array;

	/**
	 * Register WordPress hooks, CPTs, abilities, and portal views via the loader.
	 */
	public function register( ReAtrium_Loader $loader ): void;
}
