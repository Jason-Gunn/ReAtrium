# ReAtrium ↔ AIGIS v2 Integration Specification

ReAtrium runs standalone out of the box using WordPress 7+ native AI (`wp_ai_client_prompt()` and the Abilities API). When **AIGIS v2** (`Jason-Gunn/aigis`) is active on the same WordPress site, ReAtrium automatically pairs with AIGIS as its **SMB Agent 365 control plane** — providing centralized agent registration, kill-switches, PII/DLP guardrails, zone enforcement, cost budgets, and append-only audit trails.

---

## Detection

ReAtrium detects AIGIS v2 via `ReAtrium_AI_Gateway::is_aigis_active()`:

```php
class_exists( 'AIGIS_Abilities_Bridge' )
```

When active:
- The Employee Portal top bar displays the **AIGIS Governed** badge.
- **ReAtrium → Overview** and **Setup Wizard** display a live connection status and link to `admin.php?page=aigis-agents`.
- Enabled core and community modules automatically sync their declared agents to AIGIS (cached daily via transient `reatrium_aigis_agents_synced`, and force-synced whenever Setup Wizard or Module Settings are saved).

---

## 1. Agent Registry Sync (`aigis_register_agent_if_missing`)

Fired in `ReAtrium_Admin::maybe_sync_aigis_agents()` for every agent declared by enabled modules via `ReAtrium_Module_Interface::agents()`.

```php
do_action( 'aigis_register_agent_if_missing', array $agent );
```

### `$agent` Payload Shape

| Key | Type | Description |
| :--- | :--- | :--- |
| `slug` | `string` | Unique agent identifier (e.g. `reatrium-assistant`, `reatrium-handbook`, `reatrium-public-writer`). |
| `name` | `string` | Human-readable display name. |
| `description` | `string` | Purpose of the agent. |
| `allowed_abilities` | `string[]` | WP 7+ Ability names the agent is permitted to invoke. |
| `pii_policy` | `string` | Recommended default DLP policy: `'redact'`, `'warn'`, or `'block'`. |
| `zone` | `string` | Operating boundary: `'intranet'`, `'public'`, or `'hybrid'`. |
| `module` | `string` | Owning ReAtrium module ID (e.g. `knowledge_base`, `assistant`, or a community module ID). |

---

## 2. Pre-Flight Governance Hook (`reatrium_before_ai_call`)

Fired inside `ReAtrium_AI_Gateway::generate()` **before** `wp_ai_client_prompt()` is called. Use this in AIGIS v2 to enforce kill-switches, monthly budgets, zone policies, and PII redaction/blocking.

```php
$preflight = apply_filters( 'reatrium_before_ai_call', array $preflight, array $context );
```

### `$preflight` Input & Return Shape

| Key | Type | Mutable | Description |
| :--- | :--- | :--- | :--- |
| `agent_slug` | `string` | No | Calling agent slug. |
| `prompt` | `string` | **Yes** | Raw prompt text. Return redacted text if PII was scrubbed. |
| `system` | `string` | **Yes** | System instruction. AIGIS can append compliance instructions. |
| `zone` | `string` | No | `'intranet'`, `'public'`, or `'hybrid'`. |
| `sensitivity` | `string` | No | `'public'`, `'internal'`, or `'confidential'` (e.g. HR/IT requests). |
| `allowed` | `bool` | **Yes** | Set to `false` to block execution (kill-switch, budget exceeded, DLP block). |
| `block_reason` | `string` | **Yes** | Human-readable message shown when `allowed` is `false`. |
| `pii_redacted` | `bool` | **Yes** | Set to `true` if AIGIS redacted PII from `prompt`. |

### `$context` Shape

```php
[
	'agent_slug'  => 'reatrium-handbook',
	'module'      => 'knowledge_base',
	'department'  => 'HR',
	'zone'        => 'intranet',      // 'intranet' | 'public' | 'hybrid'
	'sensitivity' => 'internal',      // 'public' | 'internal' | 'confidential'
	'abilities'   => [ 'reatrium/ask-handbook' ],
	'user_id'     => 42,
]
```

---

## 3. Per-Ability Execution Gate (`reatrium_can_agent_execute_ability`)

Fired for each WP 7+ Ability before attaching it via `$builder->using_abilities(...)`.

```php
$allowed = (bool) apply_filters(
	'reatrium_can_agent_execute_ability',
	true,
	string $agent_slug,
	string $ability_name
);
```

> **Note:** Even without AIGIS, `ReAtrium_AI_Gateway` enforces a built-in zone boundary: any call with `zone === 'public'` strips all non-public abilities automatically.

---

## 4. Post-Flight Audit & Telemetry Hook (`reatrium_after_ai_call`)

Fired immediately after `$builder->generate_text()` completes (on both success and error).

```php
do_action( 'reatrium_after_ai_call', array $result, array $context );
```

### `$result` Shape

```php
[
	'agent_slug' => 'reatrium-assistant',
	'prompt'     => '...',       // Clean/redacted prompt sent to model
	'output'     => '...',       // Generated text (empty on error)
	'latency_ms' => 412.85,      // Wall-clock milliseconds
	'error'      => '',          // Error string if failed
]
```

---

## 5. Pluggable Embeddings & Vector Store Hooks

### Dense Embeddings (`reatrium_generate_embedding`)

Hook this in AIGIS v2 (or any provider adapter like OpenAI, Ollama, or Gemini) to upgrade ReAtrium's Knowledge Base from lexical search to hybrid vector + lexical RAG:

```php
add_filter( 'reatrium_generate_embedding', function ( $result, string $text, array $options ) {
	// Call your configured embedding provider and return:
	return [
		'vector' => [ 0.0123, -0.0456, /* ... */ ],
		'model'  => 'text-embedding-3-small',
	];
}, 10, 3 );
```

### External Vector Store (`reatrium_vector_store`)

Swap the default MySQL vector store (`ReAtrium_Vector_Store_MySQL`) for Qdrant, pgvector, or Pinecone by returning any class implementing `ReAtrium_Vector_Store_Interface`:

```php
add_filter( 'reatrium_vector_store', function ( ReAtrium_Vector_Store_Interface $store ) {
	return new My_Qdrant_Vector_Store();
} );
```

---

## Reference AIGIS v2 Bridge Snippet

```php
class AIGIS_ReAtrium_Integration {

	public static function init(): void {
		add_action( 'aigis_register_agent_if_missing', [ self::class, 'sync_agent' ] );
		add_filter( 'reatrium_before_ai_call', [ self::class, 'preflight' ], 10, 2 );
		add_filter( 'reatrium_can_agent_execute_ability', [ self::class, 'can_execute_ability' ], 10, 3 );
		add_action( 'reatrium_after_ai_call', [ self::class, 'audit_log' ], 10, 2 );
	}

	public static function preflight( array $preflight, array $context ): array {
		// 1. Check agent kill-switch / active status.
		// 2. Check monthly token/cost budget for $context['agent_slug'] or $context['department'].
		// 3. Enforce zone/sensitivity DLP & PII policy (redact or block).
		return $preflight;
	}

	public static function can_execute_ability( bool $allowed, string $agent_slug, string $ability ): bool {
		// Verify $ability is in the agent's approved abilities list in AIGIS.
		return $allowed;
	}

	public static function audit_log( array $result, array $context ): void {
		// Append immutable audit record with user_id, zone, sensitivity, latency_ms, and error.
	}
}
```
