<?php
/**
 * Module: Company Home, Announcements & AI "Catch Me Up" Digest.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Announcements {

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_cpt' );
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
		$loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view' );
		$loader->add_action( 'reatrium_portal_before_render', $this, 'handle_portal_post' );
	}

	public function register_cpt(): void {
		register_post_type(
			'reatrium_announce',
			[
				'labels'              => [
					'name'          => __( 'Announcements', 'reatrium' ),
					'singular_name' => __( 'Announcement', 'reatrium' ),
					'add_new_item'  => __( 'Add Announcement', 'reatrium' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'reatrium-settings',
				'show_in_rest'        => true,
				'supports'            => [ 'title', 'editor', 'author' ],
				'capability_type'     => 'post',
			]
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'reatrium/summarize-updates',
			[
				'label'               => __( 'Summarize Company Announcements', 'reatrium' ),
				'description'         => __( 'Generates an executive "Catch Me Up" digest of recent internal announcements.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'days' => [
							'type'    => 'integer',
							'default' => 14,
						],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'summary' => [ 'type' => 'string' ],
						'count'   => [ 'type' => 'integer' ],
					],
				],
				'permission_callback' => static fn() => ReAtrium_Isolation::is_intranet_user(),
				'execute_callback'    => [ $this, 'execute_summarize_updates' ],
			]
		);
	}

	public function execute_summarize_updates( array $input = [] ): array {
		$days  = min( 90, max( 1, (int) ( $input['days'] ?? 14 ) ) );
		$posts = get_posts( [
			'post_type'      => 'reatrium_announce',
			'post_status'    => 'publish',
			'posts_per_page' => 15,
			'date_query'     => [ [ 'after' => $days . ' days ago' ] ],
			'reatrium_internal_query' => true,
		] );

		if ( empty( $posts ) ) {
			return [
				'summary' => __( 'No announcements posted in this period.', 'reatrium' ),
				'count'   => 0,
			];
		}

		$corpus = '';
		foreach ( $posts as $post ) {
			$corpus .= sprintf(
				"- [%s] %s: %s\n",
				get_the_date( 'Y-m-d', $post ),
				$post->post_title,
				wp_strip_all_tags( $post->post_content )
			);
		}

		$ai = ReAtrium_AI_Gateway::generate(
			"Summarize these company announcements into a concise bulleted 'Catch Me Up' digest for an employee:\n\n" . $corpus,
			[
				'agent_slug' => 'reatrium-digest',
				'module'     => 'announcements',
				'system'     => 'You are an internal communications assistant. Be concise, accurate, and highlight action items.',
			]
		);

		$summary = $ai['ok']
			? $ai['text']
			: sprintf(
				/* translators: 1: error message, 2: announcement count */
				__( 'AI digest unavailable (%1$s). %2$d recent announcement(s) are listed below.', 'reatrium' ),
				$ai['error'],
				count( $posts )
			);

		return [
			'summary' => $summary,
			'count'   => count( $posts ),
		];
	}

	public function register_portal_view( array $views ): array {
		$views['home'] = [
			'label'    => __( 'Home & Feed', 'reatrium' ),
			'icon'     => '🏠',
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	/**
	 * Handle quick announcement creation before HTML output (POST-Redirect-GET).
	 */
	public function handle_portal_post( string $current_view ): void {
		if ( $current_view !== 'home' ) {
			return;
		}

		if ( isset( $_POST['reatrium_post_announce'] ) && check_admin_referer( 'reatrium_announce_nonce' ) && current_user_can( 'publish_posts' ) ) {
			$title   = sanitize_text_field( wp_unslash( $_POST['announce_title'] ?? '' ) );
			$content = wp_kses_post( wp_unslash( $_POST['announce_content'] ?? '' ) );
			$pinned  = ! empty( $_POST['announce_pinned'] ) ? '1' : '0';

			if ( $title !== '' && $content !== '' ) {
				$post_id = wp_insert_post( [
					'post_type'    => 'reatrium_announce',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
					'post_author'  => get_current_user_id(),
				] );
				if ( $post_id && ! is_wp_error( $post_id ) ) {
					update_post_meta( $post_id, '_reatrium_pinned', $pinned );
					wp_safe_redirect( add_query_arg( 'reatrium_notice', 'announce_published', ReAtrium_Portal::portal_url( 'home' ) ) );
					exit;
				}
			}
		}
	}

	public function render_portal_view(): void {
		$digest_result = null;
		$notice_key    = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$posted_notice = $notice_key === 'announce_published' ? __( 'Announcement published.', 'reatrium' ) : '';

		// Handle "Catch Me Up" AI Digest request.
		if ( isset( $_POST['reatrium_catch_me_up'] ) && check_admin_referer( 'reatrium_digest_nonce' ) ) {
			$digest_result = $this->execute_summarize_updates( [ 'days' => 14 ] );
		}

		// Fetch pinned announcements first, then fill up to 20 with recent unpinned posts.
		$pinned_posts = get_posts( [
			'post_type'               => 'reatrium_announce',
			'post_status'             => 'publish',
			'posts_per_page'          => 20,
			'orderby'                 => 'date',
			'order'                   => 'DESC',
			'meta_key'                => '_reatrium_pinned',
			'meta_value'              => '1',
			'reatrium_internal_query' => true,
		] );

		$pinned_ids = wp_list_pluck( $pinned_posts, 'ID' );
		$remaining  = max( 0, 20 - count( $pinned_posts ) );
		$other      = $remaining > 0
			? get_posts( [
				'post_type'               => 'reatrium_announce',
				'post_status'             => 'publish',
				'posts_per_page'          => $remaining,
				'orderby'                 => 'date',
				'order'                   => 'DESC',
				'post__not_in'            => $pinned_ids,
				'reatrium_internal_query' => true,
			] )
			: [];

		$announcements = array_merge( $pinned_posts, $other );
		?>
		<?php if ( $posted_notice !== '' ) : ?>
			<div class="reatrium-alert is-ok"><?php echo esc_html( $posted_notice ); ?></div>
		<?php endif; ?>

		<div class="reatrium-card">
			<div class="reatrium-card-header">
				<div>
					<h2><?php esc_html_e( 'Catch Me Up — AI Digest', 'reatrium' ); ?></h2>
					<p class="reatrium-muted"><?php esc_html_e( 'Get a quick summary of company updates from the last 14 days.', 'reatrium' ); ?></p>
				</div>
				<form method="post">
					<?php wp_nonce_field( 'reatrium_digest_nonce' ); ?>
					<button type="submit" name="reatrium_catch_me_up" value="1" class="reatrium-btn reatrium-btn-primary">
						✨ <?php esc_html_e( 'Generate Digest', 'reatrium' ); ?>
					</button>
				</form>
			</div>

			<?php if ( is_array( $digest_result ) ) : ?>
				<div class="reatrium-ai-box">
					<?php echo wp_kses_post( wpautop( esc_html( $digest_result['summary'] ) ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( current_user_can( 'publish_posts' ) ) : ?>
			<details class="reatrium-card">
				<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( '+ Post Company Announcement', 'reatrium' ); ?></summary>
				<form method="post" style="margin-top:14px;">
					<?php wp_nonce_field( 'reatrium_announce_nonce' ); ?>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Title', 'reatrium' ); ?></label>
						<input type="text" name="announce_title" required>
					</div>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Message', 'reatrium' ); ?></label>
						<textarea name="announce_content" rows="3" required></textarea>
					</div>
					<label style="display:inline-flex;gap:6px;align-items:center;margin-bottom:12px;">
						<input type="checkbox" name="announce_pinned" value="1"> <?php esc_html_e( 'Pin to top', 'reatrium' ); ?>
					</label><br>
					<button type="submit" name="reatrium_post_announce" value="1" class="reatrium-btn reatrium-btn-primary">
						<?php esc_html_e( 'Publish Announcement', 'reatrium' ); ?>
					</button>
				</form>
			</details>
		<?php endif; ?>

		<?php if ( empty( $announcements ) ) : ?>
			<div class="reatrium-card">
				<p class="reatrium-muted"><?php esc_html_e( 'No announcements yet. Post your first company update above.', 'reatrium' ); ?></p>
			</div>
		<?php else : ?>
			<?php foreach ( $announcements as $item ) : ?>
				<?php $is_pinned = get_post_meta( $item->ID, '_reatrium_pinned', true ) === '1'; ?>
				<article class="reatrium-card">
					<div class="reatrium-card-header">
						<h3>
							<?php if ( $is_pinned ) : ?>
								<span class="reatrium-badge is-ok"><?php esc_html_e( 'Pinned', 'reatrium' ); ?></span>
							<?php endif; ?>
							<?php echo esc_html( $item->post_title ); ?>
						</h3>
						<span class="reatrium-muted"><?php echo esc_html( get_the_date( '', $item ) ); ?> · <?php echo esc_html( get_the_author_meta( 'display_name', (int) $item->post_author ) ); ?></span>
					</div>
					<div><?php echo wp_kses_post( wpautop( $item->post_content ) ); ?></div>
				</article>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}
}
