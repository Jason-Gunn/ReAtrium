<?php
/**
 * Abstract base class for ReAtrium modules.
 *
 * Third-party / community plugins can extend this class and only implement
 * id(), name(), description(), and register( ReAtrium_Loader $loader ).
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class ReAtrium_Abstract_Module implements ReAtrium_Module_Interface {

	public function icon(): string {
		return '🧩';
	}

	public function version(): string {
		return defined( 'REATRIUM_VERSION' ) ? REATRIUM_VERSION : '1.0.0';
	}

	public function zone(): string {
		return 'intranet';
	}

	public function is_core(): bool {
		return false;
	}

	public function default_enabled(): bool {
		return true;
	}

	public function post_types(): array {
		return [];
	}

	public function taxonomies(): array {
		return [];
	}

	public function abilities(): array {
		return [];
	}

	public function agents(): array {
		return [];
	}
}
