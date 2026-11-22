# ReAtrium Module SDK Guide

ReAtrium is designed as a modular intranet core. Companies and community developers can build standalone WordPress plugins that plug directly into ReAtrium — automatically inheriting **Public/Private Isolation**, **Employee Portal navigation**, **Settings & Setup Wizard toggles**, **Ask ReAtrium ability discovery**, and **AIGIS v2 agent governance**.

---

## Quick Start

1. Create a standard WordPress plugin.
2. Hook `reatrium_register_modules` (which fires on `plugins_loaded`).
3. Extend `ReAtrium_Abstract_Module` and register an instance on the `ReAtrium_Module_Registry`.

```php
<?php
/**
 * Plugin Name: ReAtrium – My Custom Module
 * Requires Plugins: reatrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'reatrium_register_modules', function ( ReAtrium_Module_Registry $registry ) {
	require_once __DIR__ . '/includes/class-my-module.php';
	$registry->register( new My_ReAtrium_Module() );
} );
```

---

## Implementing a Module (`ReAtrium_Abstract_Module`)

Only four methods are required: `id()`, `name()`, `description()`, and `register( ReAtrium_Loader $loader )`. Override the optional methods to unlock automatic core integrations:

| Method | Default | What ReAtrium Does Automatically |
| :--- | :--- | :--- |
| `id(): string` | *(required)* | Unique key used in `reatrium_modules` option and admin toggles. |
| `name(): string` | *(required)* | Displayed in **ReAtrium → Modules** and **Setup Wizard**. |
| `description(): string` | *(required)* | Displayed on the module toggle card. |
| `icon(): string` | `'🧩'` | Displayed in admin cards and portal nav. |
| `version(): string` | `REATRIUM_VERSION` | Displayed in the module card footer. |
| `zone(): string` | `'intranet'` | `'intranet'`, `'public'`, or `'hybrid'`. Controls isolation & ability scoping. |
| `is_core(): bool` | `false` | Shows a **Community Add-on** badge when `false`. |
| `default_enabled(): bool` | `true` | Initial state before an admin toggles it. |
| `post_types(): array` | `[]` | Automatically added to `ReAtrium_Isolation` (hidden from public search, feeds, sitemaps, and unauthenticated REST). |
| `taxonomies(): array` | `[]` | Automatically added to `ReAtrium_Isolation` (hidden from sitemaps and unauthenticated REST). |
| `abilities(): array` | `[]` | WP 7+ Ability names automatically discovered by **Ask ReAtrium** (for `intranet` zone) and AIGIS. |
| `agents(): array` | `[]` | Automatically synced to AIGIS v2 via `aigis_register_agent_if_missing`. |

---

## Portal Integration & POST-Redirect-GET

To add a workspace tab to `/intranet/`:

1. Filter `reatrium_portal_views` in your `register( $loader )` method:
   ```php
   $loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view' );
   $loader->add_action( 'reatrium_portal_before_render', $this, 'handle_portal_post' );
   ```
2. Return your view entry:
   ```php
   public function register_portal_view( array $views ): array {
       $views['my_view'] = [
           'label'    => __( 'My Tool', 'my-module' ),
           'icon'     => '🛠️',
           'callback' => [ $this, 'render_portal_view' ],
       ];
       return $views;
   }
   ```
3. Handle write `POST` forms inside `handle_portal_post( string $current_view )` and redirect using `ReAtrium_Portal::portal_url( 'my_view' )` so your module works seamlessly on both Pretty and Plain permalinks without duplicate submissions on browser refresh.

---

## Calling AI Safely

Always call `ReAtrium_AI_Gateway::generate()` instead of calling `wp_ai_client_prompt()` directly so your module automatically respects AIGIS v2 guardrails, PII policies, budgets, and audit logging:

```php
$ai = ReAtrium_AI_Gateway::generate(
	$prompt,
	[
		'agent_slug'  => 'my-module-agent',
		'module'      => $this->id(),
		'zone'        => $this->zone(),
		'sensitivity' => 'internal', // 'public' | 'internal' | 'confidential'
		'system'      => 'You are a helpful internal assistant.',
	]
);
```

---

## Starter Template

See the working, installable starter plugin in [`examples/reatrium-module-starter/`](../examples/reatrium-module-starter/) for a complete real-world example (**Employee Onboarding Checklists**).
