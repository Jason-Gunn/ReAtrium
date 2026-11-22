<?php
/**
 * Module: Unified "Ask ReAtrium" Workspace Assistant.
 *
 * Orchestrates all enabled intranet WordPress 7+ Abilities (core and community)
 * from a single employee workspace command center, governed by AIGIS when active.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Assistant extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'assistant';
	}

	public function name(): string {
		return __( 'Ask ReAtrium (AI Assistant)', 'reatrium' );
	}

	public function description(): string {
		return __( 'Unified intranet assistant that orchestrates enabled WordPress 7+ Abilities across Handbook, Directory, Announcements, and community modules.', 'reatrium' );
	}

	public function icon(): string {
		return '✨';
	}

	public function is_core(): bool {
		return true;
	}

	public function agents(): array {
		$intranet_abilities = ReAtrium_Module_Registry::instance()->collect_abilities( 'intranet' );
		$allowed            = array_values( array_unique( array_merge( $intranet_abilities, [ 'aigis/check-guardrail' ] ) ) );

		return [
			[
				'slug'              => 'reatrium-assistant',
				'name'              => 'Ask ReAtrium Workspace Assistant',
				'description'       => 'Unified intranet copilot that routes employee questions across enabled company abilities.',
				'allowed_abilities' => $allowed,
				'pii_policy'        => 'redact',
				'zone'              => 'intranet',
			],
		];
	}

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view', 5 );
	}

	public function register_portal_view( array $views ): array {
		$views['assistant'] = [
			'label'    => __( 'Ask ReAtrium', 'reatrium' ),
			'icon'     => '✨',
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	/**
	 * Execute a cross-module workspace query using all enabled intranet abilities.
	 */
	public function execute_ask_workspace( string $question ): array {
		$question = sanitize_textarea_field( $question );
		if ( $question === '' ) {
			return [
				'ok'         => false,
				'text'       => '',
				'error'      => __( 'Please enter a question.', 'reatrium' ),
				'latency_ms' => 0.0,
				'redacted'   => false,
				'abilities'  => [],
			];
		}

		$company_name = (string) get_option( 'reatrium_company_name', get_bloginfo( 'name' ) );
		$abilities    = ReAtrium_Module_Registry::instance()->collect_abilities( 'intranet' );

		$ai = ReAtrium_AI_Gateway::generate(
			$question,
			[
				'agent_slug'  => 'reatrium-assistant',
				'module'      => 'assistant',
				'department'  => 'intranet',
				'zone'        => 'intranet',
				'sensitivity' => 'internal',
				'abilities'   => $abilities,
				'system'      => sprintf(
					'You are Ask ReAtrium, the internal workplace assistant for %s. Use the available WordPress Abilities to look up handbook policies, summarize announcements, find the right colleague, or triage requests. Always cite sources when available and never fabricate internal policies.',
					$company_name
				),
			]
		);

		$ai['abilities'] = $abilities;
		return $ai;
	}

	public function render_portal_view(): void {
		$result   = null;
		$question = '';

		if ( isset( $_POST['reatrium_assistant_ask'] ) && check_admin_referer( 'reatrium_assistant_nonce' ) ) {
			$question = sanitize_textarea_field( wp_unslash( $_POST['assistant_prompt'] ?? '' ) );
			$result   = $this->execute_ask_workspace( $question );
		}

		$active_abilities = ReAtrium_Module_Registry::instance()->collect_abilities( 'intranet' );
		$quick_prompts    = [
			__( 'Catch me up on recent company announcements.', 'reatrium' ),
			__( 'What is our PTO and holiday policy?', 'reatrium' ),
			__( 'Who should I contact for IT or HR support?', 'reatrium' ),
		];
		?>
		<div class="reatrium-card">
			<div class="reatrium-card-header">
				<div>
					<h2><?php esc_html_e( 'Ask ReAtrium — Workspace AI Assistant', 'reatrium' ); ?></h2>
					<p class="reatrium-muted">
						<?php esc_html_e( 'Ask anything across your company handbook, announcements, people directory, and enabled intranet modules.', 'reatrium' ); ?>
					</p>
				</div>
				<span class="reatrium-badge is-ok">
					<?php
					/* translators: %d: number of active WP abilities */
					echo esc_html( sprintf( _n( '%d Ability Active', '%d Abilities Active', count( $active_abilities ), 'reatrium' ), count( $active_abilities ) ) );
					?>
				</span>
			</div>

			<form method="post" class="reatrium-inline-form">
				<?php wp_nonce_field( 'reatrium_assistant_nonce' ); ?>
				<input type="text" name="assistant_prompt" value="<?php echo esc_attr( $question ); ?>" placeholder="<?php esc_attr_e( 'Ask about policies, recent updates, colleagues, or helpdesk requests…', 'reatrium' ); ?>" required>
				<button type="submit" name="reatrium_assistant_ask" value="1" class="reatrium-btn reatrium-btn-primary">
					✨ <?php esc_html_e( 'Ask', 'reatrium' ); ?>
				</button>
			</form>

			<form method="post" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">
				<?php wp_nonce_field( 'reatrium_assistant_nonce' ); ?>
				<input type="hidden" name="reatrium_assistant_ask" value="1">
				<?php foreach ( $quick_prompts as $prompt ) : ?>
					<button type="submit" name="assistant_prompt" value="<?php echo esc_attr( $prompt ); ?>" class="reatrium-btn" style="font-weight:500;">
						<?php echo esc_html( $prompt ); ?>
					</button>
				<?php endforeach; ?>
			</form>

			<?php if ( is_array( $result ) ) : ?>
				<div class="reatrium-ai-box" style="margin-top:16px;">
					<?php if ( ! empty( $result['ok'] ) ) : ?>
						<?php echo wp_kses_post( wpautop( esc_html( $result['text'] ) ) ); ?>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Assistant unavailable:', 'reatrium' ); ?></strong> <?php echo esc_html( $result['error'] ); ?></p>
					<?php endif; ?>

					<div class="reatrium-muted" style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;">
						<?php if ( ! empty( $result['latency_ms'] ) ) : ?>
							<span><?php echo esc_html( sprintf( __( 'Latency: %s ms', 'reatrium' ), $result['latency_ms'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $result['redacted'] ) ) : ?>
							<span>🛡 <?php esc_html_e( 'PII redacted by AIGIS', 'reatrium' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<div class="reatrium-card">
			<h3><?php esc_html_e( 'Connected Intranet Abilities (WP 7+)', 'reatrium' ); ?></h3>
			<?php if ( empty( $active_abilities ) ) : ?>
				<p class="reatrium-muted"><?php esc_html_e( 'Enable intranet modules in Settings → Modules to connect abilities to Ask ReAtrium.', 'reatrium' ); ?></p>
			<?php else : ?>
				<div style="display:flex;flex-wrap:wrap;gap:8px;">
					<?php foreach ( $active_abilities as $ability ) : ?>
						<code style="background:var(--ra-bg);padding:4px 10px;border-radius:6px;font-size:12px;"><?php echo esc_html( $ability ); ?></code>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
