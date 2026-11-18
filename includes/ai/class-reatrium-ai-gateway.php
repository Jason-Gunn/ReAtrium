<?php
/**
 * ReAtrium AI Gateway.
 *
 * Wraps WordPress 7+ native AI Client (wp_ai_client_prompt) and fires
 * in-process governance hooks (reatrium_before_ai_call, reatrium_after_ai_call,
 * reatrium_can_agent_execute_ability) so AIGIS v2 governs calls when active
 * while degrading gracefully when AIGIS is not installed.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_AI_Gateway {

	/**
	 * Check if WordPress 7+ AI Client is available and enabled.
	 */
	public static function is_ai_available(): bool {
		return function_exists( 'wp_supports_ai' )
			&& function_exists( 'wp_ai_client_prompt' )
			&& wp_supports_ai();
	}

	/**
	 * Check whether AIGIS v2 is active on this site.
	 */
	public static function is_aigis_active(): bool {
		return class_exists( 'AIGIS_Abilities_Bridge' );
	}

	/**
	 * Execute a text generation prompt through WP 7+ AI Client with optional AIGIS governance.
	 *
	 * @param string $prompt  User/task prompt.
	 * @param array  $options {
	 *     @type string $agent_slug  Registered agent slug (e.g. 'reatrium-handbook').
	 *     @type string $system      System instruction.
	 *     @type float  $temperature Sampling temperature.
	 *     @type int    $max_tokens  Max output tokens.
	 *     @type array  $abilities   WP Ability names to attach to the prompt builder.
	 *     @type string $module      Module identifier for logging.
	 *     @type string $department  Department context for logging.
	 * }
	 * @return array {
	 *     @type bool   $ok         True on success.
	 *     @type string $text       Generated output text.
	 *     @type string $error      Error message if !$ok.
	 *     @type float  $latency_ms Wall-clock latency in ms.
	 *     @type bool   $redacted   Whether PII was redacted by AIGIS.
	 * }
	 */
	public static function generate( string $prompt, array $options = [] ): array {
		$agent_slug = sanitize_key( $options['agent_slug'] ?? 'reatrium-assistant' );
		$context    = [
			'agent_slug' => $agent_slug,
			'module'     => sanitize_key( $options['module'] ?? 'core' ),
			'department' => sanitize_text_field( $options['department'] ?? 'intranet' ),
			'user_id'    => get_current_user_id(),
		];

		// 1. Pre-flight governance hook (AIGIS checks agent status, budget, and PII).
		$preflight = apply_filters(
			'reatrium_before_ai_call',
			[
				'agent_slug'   => $agent_slug,
				'prompt'       => $prompt,
				'system'       => (string) ( $options['system'] ?? '' ),
				'allowed'      => true,
				'block_reason' => '',
				'pii_redacted' => false,
			],
			$context
		);

		if ( empty( $preflight['allowed'] ) ) {
			return [
				'ok'         => false,
				'text'       => '',
				'error'      => (string) ( $preflight['block_reason'] ?: __( 'Blocked by AI governance policy.', 'reatrium' ) ),
				'latency_ms' => 0.0,
				'redacted'   => false,
			];
		}

		$clean_prompt = (string) ( $preflight['prompt'] ?? $prompt );

		if ( ! self::is_ai_available() ) {
			return [
				'ok'         => false,
				'text'       => '',
				'error'      => __( 'WordPress 7+ AI Client is unavailable or no connector is configured under Settings > Connectors.', 'reatrium' ),
				'latency_ms' => 0.0,
				'redacted'   => ! empty( $preflight['pii_redacted'] ),
			];
		}

		$start   = microtime( true );
		$builder = wp_ai_client_prompt( $clean_prompt );

		if ( ! empty( $preflight['system'] ) ) {
			$builder->using_system_instruction( (string) $preflight['system'] );
		}
		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			$builder->using_temperature( (float) $options['temperature'] );
		}
		if ( isset( $options['max_tokens'] ) && is_numeric( $options['max_tokens'] ) ) {
			$builder->using_max_tokens( (int) $options['max_tokens'] );
		}
		if ( ! empty( $options['abilities'] ) && is_array( $options['abilities'] ) && method_exists( $builder, 'using_abilities' ) ) {
			$allowed_abilities = array_filter(
				$options['abilities'],
				static fn( string $ability ) => self::can_agent_execute_ability( $agent_slug, $ability )
			);
			if ( ! empty( $allowed_abilities ) ) {
				$builder->using_abilities( ...array_values( $allowed_abilities ) );
			}
		}

		if ( method_exists( $builder, 'is_supported_for_text_generation' ) && ! $builder->is_supported_for_text_generation() ) {
			return [
				'ok'         => false,
				'text'       => '',
				'error'      => __( 'No AI connector is configured for text generation under Settings > Connectors.', 'reatrium' ),
				'latency_ms' => 0.0,
				'redacted'   => ! empty( $preflight['pii_redacted'] ),
			];
		}

		$generated  = $builder->generate_text();
		$latency_ms = round( ( microtime( true ) - $start ) * 1000, 2 );
		$error_msg  = is_wp_error( $generated ) ? $generated->get_error_message() : '';
		$text       = is_string( $generated ) ? $generated : '';

		// 2. Post-flight logging hook (AIGIS records usage, latency, and audit trail).
		do_action(
			'reatrium_after_ai_call',
			[
				'agent_slug' => $agent_slug,
				'prompt'     => $clean_prompt,
				'output'     => $text,
				'latency_ms' => $latency_ms,
				'error'      => $error_msg,
			],
			$context
		);

		return [
			'ok'         => $error_msg === '',
			'text'       => $text,
			'error'      => $error_msg,
			'latency_ms' => $latency_ms,
			'redacted'   => ! empty( $preflight['pii_redacted'] ),
		];
	}

	/**
	 * Check whether an agent identity is allowed to execute a given WP Ability.
	 */
	public static function can_agent_execute_ability( string $agent_slug, string $ability_name ): bool {
		return (bool) apply_filters( 'reatrium_can_agent_execute_ability', true, $agent_slug, $ability_name );
	}

	/**
	 * Return the active vector store instance (MySQL by default, filterable).
	 */
	public static function vector_store(): ReAtrium_Vector_Store_Interface {
		$store = new ReAtrium_Vector_Store_MySQL();
		return apply_filters( 'reatrium_vector_store', $store );
	}
}

