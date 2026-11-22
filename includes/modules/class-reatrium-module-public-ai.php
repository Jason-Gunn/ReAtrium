<?php
/**
 * Module: Public Website & Blog AI Drafting Helpers.
 *
 * Assists marketing/content editors with drafting public blog posts and FAQs
 * while maintaining strict isolation from private intranet CPTs and RAG data.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Public_AI extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'public_ai';
	}

	public function name(): string {
		return __( 'Public Website & Blog AI Drafter', 'reatrium' );
	}

	public function description(): string {
		return __( 'Public-zone AI drafting tool for marketing posts and customer FAQs, isolated from private intranet data.', 'reatrium' );
	}

	public function icon(): string {
		return '🌐';
	}

	public function zone(): string {
		return 'public';
	}

	public function is_core(): bool {
		return true;
	}

	public function abilities(): array {
		return [ 'reatrium/draft-public-post' ];
	}

	public function agents(): array {
		return [
			[
				'slug'              => 'reatrium-public-writer',
				'name'              => 'ReAtrium Public Blog & FAQ Drafter',
				'description'       => 'Drafts public website content isolated from private intranet data.',
				'allowed_abilities' => [ 'reatrium/draft-public-post', 'aigis/check-guardrail' ],
				'pii_policy'        => 'block',
				'zone'              => 'public',
			],
		];
	}

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
		if ( is_admin() ) {
			$loader->add_action( 'admin_menu', $this, 'register_submenu' );
		}
	}

	public function register_submenu(): void {
		add_submenu_page(
			'reatrium-settings',
			__( 'Public AI Drafter', 'reatrium' ),
			__( 'Public AI Drafter', 'reatrium' ),
			'edit_posts',
			'reatrium-public-ai',
			[ $this, 'render_admin_page' ]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'reatrium/draft-public-post',
			[
				'label'               => __( 'Draft Public Blog Post or FAQ', 'reatrium' ),
				'description'         => __( 'Generates a public-facing blog post or FAQ draft from a topic and key points without accessing private intranet data.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'topic'  => [ 'type' => 'string' ],
						'format' => [
							'type'    => 'string',
							'enum'    => [ 'blog_post', 'faq', 'announcement' ],
							'default' => 'blog_post',
						],
						'notes'  => [ 'type' => 'string' ],
					],
					'required'   => [ 'topic' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'ok'      => [ 'type' => 'boolean' ],
						'title'   => [ 'type' => 'string' ],
						'content' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => [ $this, 'execute_draft_public_post' ],
			]
		);
	}

	public function execute_draft_public_post( array $input ): array {
		$topic  = sanitize_text_field( $input['topic'] ?? '' );
		$format = sanitize_key( $input['format'] ?? 'blog_post' );
		$notes  = sanitize_textarea_field( $input['notes'] ?? '' );

		$prompt = sprintf(
			"Write a polished public-facing %s about: %s.\n\nKey points/notes:\n%s\n\nReturn a clear headline on the first line followed by the body.",
			str_replace( '_', ' ', $format ),
			$topic,
			$notes ?: 'None provided.'
		);

		$ai = ReAtrium_AI_Gateway::generate(
			$prompt,
			[
				'agent_slug'  => 'reatrium-public-writer',
				'module'      => 'public_ai',
				'department'  => 'marketing',
				'zone'        => 'public',
				'sensitivity' => 'public',
				'system'      => 'You are a public website content writer for an SMB. Write clear, engaging copy suitable for external customers.',
			]
		);

		return [
			'ok'      => (bool) $ai['ok'],
			'title'   => $topic,
			'content' => $ai['ok'] ? $ai['text'] : $ai['error'],
		];
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'reatrium' ) );
		}

		$draft_output = null;
		$saved_post   = 0;

		if ( isset( $_POST['reatrium_generate_public_draft'] ) && check_admin_referer( 'reatrium_public_ai_nonce' ) ) {
			$topic  = sanitize_text_field( wp_unslash( $_POST['public_topic'] ?? '' ) );
			$format = sanitize_key( wp_unslash( $_POST['public_format'] ?? 'blog_post' ) );
			$notes  = sanitize_textarea_field( wp_unslash( $_POST['public_notes'] ?? '' ) );

			$draft_output = $this->execute_draft_public_post( [
				'topic'  => $topic,
				'format' => $format,
				'notes'  => $notes,
			] );

			if ( ! empty( $_POST['save_as_wp_draft'] ) && ! empty( $draft_output['ok'] ) && ! empty( $draft_output['content'] ) ) {
				$post_type = ( $format === 'faq' && current_user_can( 'edit_pages' ) ) ? 'page' : 'post';
				$post_id   = wp_insert_post( [
					'post_type'    => $post_type,
					'post_status'  => 'draft',
					'post_title'   => $topic,
					'post_content' => wp_kses_post( wpautop( $draft_output['content'] ) ),
					'post_author'  => get_current_user_id(),
				] );
				if ( $post_id && ! is_wp_error( $post_id ) ) {
					$saved_post = (int) $post_id;
				}
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Public Website & Blog AI Drafter', 'reatrium' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Draft public blog posts and customer FAQs. This agent is strictly isolated from private intranet handbooks, directory, and requests.', 'reatrium' ); ?>
			</p>

			<?php if ( $saved_post > 0 ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php esc_html_e( 'Saved as WordPress draft!', 'reatrium' ); ?>
						<a href="<?php echo esc_url( get_edit_post_link( $saved_post ) ); ?>"><?php esc_html_e( 'Edit in WordPress →', 'reatrium' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" style="max-width:720px;margin-top:16px;">
				<?php wp_nonce_field( 'reatrium_public_ai_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="public_topic"><?php esc_html_e( 'Topic / Headline', 'reatrium' ); ?></label></th>
						<td><input type="text" id="public_topic" name="public_topic" class="large-text" required placeholder="e.g. How Our Team Uses AI Responsibly"></td>
					</tr>
					<tr>
						<th><label for="public_format"><?php esc_html_e( 'Format', 'reatrium' ); ?></label></th>
						<td>
							<select id="public_format" name="public_format">
								<option value="blog_post"><?php esc_html_e( 'Blog Post', 'reatrium' ); ?></option>
								<option value="faq"><?php esc_html_e( 'Customer FAQ Page', 'reatrium' ); ?></option>
								<option value="announcement"><?php esc_html_e( 'Public Press / Release Note', 'reatrium' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="public_notes"><?php esc_html_e( 'Key Points', 'reatrium' ); ?></label></th>
						<td><textarea id="public_notes" name="public_notes" rows="4" class="large-text"></textarea></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'WordPress Draft', 'reatrium' ); ?></th>
						<td>
							<label><input type="checkbox" name="save_as_wp_draft" value="1" checked> <?php esc_html_e( 'Automatically save generated output as a WordPress Draft', 'reatrium' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Generate Public Draft', 'reatrium' ), 'primary', 'reatrium_generate_public_draft' ); ?>
			</form>

			<?php if ( is_array( $draft_output ) ) : ?>
				<?php if ( empty( $draft_output['ok'] ) ) : ?>
					<div class="notice notice-error">
						<p><?php echo esc_html( $draft_output['content'] ); ?></p>
					</div>
				<?php else : ?>
					<div class="card" style="max-width:720px;padding:16px 20px;margin-top:16px;">
						<h2><?php esc_html_e( 'Generated Draft', 'reatrium' ); ?></h2>
						<div><?php echo wp_kses_post( wpautop( esc_html( $draft_output['content'] ) ) ); ?></div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
