<?php
/**
 * Module: Company Knowledge Base & SOPs (AI RAG Q&A + SOP Drafting).
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_KB {

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_cpt_and_taxonomy' );
		$loader->add_action( 'save_post_reatrium_kb', $this, 'index_kb_post', 10, 2 );
		$loader->add_action( 'set_object_terms', $this, 'reindex_on_department_change', 10, 4 );
		$loader->add_action( 'wp_trash_post', $this, 'delete_kb_index' );
		$loader->add_action( 'before_delete_post', $this, 'delete_kb_index' );
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
		$loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view' );
		$loader->add_action( 'reatrium_portal_before_render', $this, 'handle_portal_post' );
	}

	public function register_cpt_and_taxonomy(): void {
		register_post_type(
			'reatrium_kb',
			[
				'labels'              => [
					'name'          => __( 'Handbook & SOPs', 'reatrium' ),
					'singular_name' => __( 'Handbook Article', 'reatrium' ),
					'add_new_item'  => __( 'Add Handbook Article', 'reatrium' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'reatrium-settings',
				'show_in_rest'        => true,
				'supports'            => [ 'title', 'editor', 'author', 'revisions' ],
				'capability_type'     => 'post',
			]
		);

		register_taxonomy(
			'reatrium_department',
			[ 'reatrium_kb' ],
			[
				'labels'            => [
					'name'          => __( 'Departments', 'reatrium' ),
					'singular_name' => __( 'Department', 'reatrium' ),
				],
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
			]
		);
	}

	/**
	 * Chunk and store a KB article when saved (supports external vector DBs via filter).
	 */
	public function index_kb_post( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$store = ReAtrium_AI_Gateway::vector_store();
		$store->delete_by_post( $post_id );

		if ( $post->post_status !== 'publish' ) {
			return;
		}

		$text = trim( wp_strip_all_tags( $post->post_title . "\n\n" . $post->post_content ) );
		if ( $text === '' ) {
			return;
		}

		$chunks = function_exists( 'mb_str_split' )
			? mb_str_split( $text, 1200, 'UTF-8' )
			: str_split( $text, 1200 );
		$terms  = wp_get_post_terms( $post_id, 'reatrium_department', [ 'fields' => 'names' ] );
		$dept   = is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : 'General';

		foreach ( $chunks as $idx => $chunk ) {
			// Store chunk text cleanly; vector embedding can be populated when an embedding model is configured.
			$store->upsert( $post_id, $idx, $chunk, [], [ 'department' => $dept ] );
		}
	}

	/**
	 * Re-index a KB post when its department taxonomy terms are updated.
	 */
	public function reindex_on_department_change( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
		if ( $taxonomy !== 'reatrium_department' || get_post_type( $object_id ) !== 'reatrium_kb' ) {
			return;
		}

		$post = get_post( $object_id );
		if ( $post instanceof WP_Post && $post->post_status === 'publish' ) {
			$this->index_kb_post( $object_id, $post );
		}
	}

	public function delete_kb_index( int $post_id ): void {
		if ( get_post_type( $post_id ) === 'reatrium_kb' ) {
			ReAtrium_AI_Gateway::vector_store()->delete_by_post( $post_id );
		}
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'reatrium/ask-handbook',
			[
				'label'               => __( 'Ask Company Handbook', 'reatrium' ),
				'description'         => __( 'Searches internal SOPs and handbook articles to answer an employee question with citations.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'question' => [ 'type' => 'string' ],
					],
					'required'   => [ 'question' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'answer'  => [ 'type' => 'string' ],
						'sources' => [ 'type' => 'array' ],
					],
				],
				'permission_callback' => static fn() => ReAtrium_Isolation::is_intranet_user(),
				'execute_callback'    => [ $this, 'execute_ask_handbook' ],
			]
		);

		wp_register_ability(
			'reatrium/draft-sop',
			[
				'label'               => __( 'Draft SOP from Notes', 'reatrium' ),
				'description'         => __( 'Turns rough bullet points into a structured company Standard Operating Procedure.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title' => [ 'type' => 'string' ],
						'notes' => [ 'type' => 'string' ],
					],
					'required'   => [ 'title', 'notes' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'draft' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'execute_callback'    => static function ( array $input ) {
					$title = sanitize_text_field( $input['title'] ?? '' );
					$notes = sanitize_textarea_field( $input['notes'] ?? '' );

					$ai = ReAtrium_AI_Gateway::generate(
						"Convert these rough notes into a clear, structured SOP titled '{$title}' with Purpose, Scope, Step-by-Step Procedure, and Owner sections:\n\n{$notes}",
						[
							'agent_slug' => 'reatrium-handbook',
							'module'     => 'knowledge_base',
							'system'     => 'You write clear, concise Standard Operating Procedures for an SMB team.',
						]
					);

					return [
						'draft' => $ai['ok'] ? $ai['text'] : $ai['error'],
					];
				},
			]
		);
	}

	/**
	 * Retrieve matching KB articles and synthesize a cited answer via AI Gateway.
	 */
	public function execute_ask_handbook( array $input ): array {
		$question = sanitize_text_field( $input['question'] ?? '' );
		if ( $question === '' ) {
			return [ 'answer' => '', 'sources' => [] ];
		}

		$matches = get_posts( [
			'post_type'               => 'reatrium_kb',
			'post_status'             => 'publish',
			's'                       => $question,
			'posts_per_page'          => 5,
			'reatrium_internal_query' => true,
		] );

		if ( empty( $matches ) ) {
			// Broaden to recent KB articles for small SMB handbooks (<50 docs).
			$matches = get_posts( [
				'post_type'               => 'reatrium_kb',
				'post_status'             => 'publish',
				'posts_per_page'          => 8,
				'reatrium_internal_query' => true,
			] );
		}

		if ( empty( $matches ) ) {
			return [
				'answer'  => __( 'No handbook articles have been published yet.', 'reatrium' ),
				'sources' => [],
			];
		}

		$sources = [];
		$context = '';
		foreach ( $matches as $doc ) {
			$sources[] = [
				'id'    => $doc->ID,
				'title' => $doc->post_title,
			];
			$context  .= sprintf(
				"### [Doc #%d] %s\n%s\n\n",
				$doc->ID,
				$doc->post_title,
				mb_substr( wp_strip_all_tags( $doc->post_content ), 0, 1500 )
			);
		}

		$ai = ReAtrium_AI_Gateway::generate(
			"Answer the employee question using ONLY the company handbook excerpts below. Cite the [Doc #ID] in your answer. If the answer isn't in the excerpts, say so.\n\nQuestion: {$question}\n\nHandbook:\n{$context}",
			[
				'agent_slug' => 'reatrium-handbook',
				'module'     => 'knowledge_base',
				'system'     => 'You are a helpful, accurate company handbook assistant.',
			]
		);

		return [
			'answer'  => $ai['ok'] ? $ai['text'] : sprintf( __( 'AI unavailable (%s). Matching handbook articles are listed below.', 'reatrium' ), $ai['error'] ),
			'sources' => $sources,
		];
	}

	public function register_portal_view( array $views ): array {
		$views['handbook'] = [
			'label'    => __( 'Handbook & AI', 'reatrium' ),
			'icon'     => '📚',
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	/**
	 * Handle handbook article creation before HTML output (POST-Redirect-GET).
	 */
	public function handle_portal_post( string $current_view ): void {
		if ( $current_view !== 'handbook' ) {
			return;
		}

		if ( isset( $_POST['reatrium_add_kb'] ) && check_admin_referer( 'reatrium_add_kb_nonce' ) && current_user_can( 'publish_posts' ) ) {
			$title   = sanitize_text_field( wp_unslash( $_POST['kb_title'] ?? '' ) );
			$content = wp_kses_post( wp_unslash( $_POST['kb_content'] ?? '' ) );
			$dept    = sanitize_text_field( wp_unslash( $_POST['kb_department'] ?? 'General' ) );

			if ( $title !== '' && $content !== '' ) {
				$post_id = wp_insert_post( [
					'post_type'    => 'reatrium_kb',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
					'post_author'  => get_current_user_id(),
				] );
				if ( $post_id && ! is_wp_error( $post_id ) ) {
					wp_set_object_terms( $post_id, $dept, 'reatrium_department' );
					wp_safe_redirect( add_query_arg( 'reatrium_notice', 'kb_published', ReAtrium_Portal::portal_url( 'handbook' ) ) );
					exit;
				}
			}
		}
	}

	public function render_portal_view(): void {
		$qa_result   = null;
		$question    = '';
		$notice_key  = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$created_msg = $notice_key === 'kb_published' ? __( 'Handbook article published.', 'reatrium' ) : '';

		if ( isset( $_POST['reatrium_ask_kb'] ) && check_admin_referer( 'reatrium_ask_kb_nonce' ) ) {
			$question  = sanitize_text_field( wp_unslash( $_POST['kb_question'] ?? '' ) );
			$qa_result = $this->execute_ask_handbook( [ 'question' => $question ] );
		}

		$articles    = get_posts( [
			'post_type'               => 'reatrium_kb',
			'post_status'             => 'publish',
			'posts_per_page'          => 30,
			'orderby'                 => 'title',
			'order'                   => 'ASC',
			'reatrium_internal_query' => true,
		] );
		$departments = (array) get_option( 'reatrium_departments', [ 'General' ] );
		?>
		<?php if ( $created_msg !== '' ) : ?>
			<div class="reatrium-alert is-ok"><?php echo esc_html( $created_msg ); ?></div>
		<?php endif; ?>

		<div class="reatrium-card">
			<h2><?php esc_html_e( 'Ask the Company Handbook', 'reatrium' ); ?></h2>
			<form method="post" class="reatrium-inline-form">
				<?php wp_nonce_field( 'reatrium_ask_kb_nonce' ); ?>
				<input type="text" name="kb_question" value="<?php echo esc_attr( $question ); ?>" placeholder="<?php esc_attr_e( 'e.g. What is our PTO policy or VPN setup?', 'reatrium' ); ?>" required>
				<button type="submit" name="reatrium_ask_kb" value="1" class="reatrium-btn reatrium-btn-primary">
					✨ <?php esc_html_e( 'Ask AI', 'reatrium' ); ?>
				</button>
			</form>

			<?php if ( is_array( $qa_result ) ) : ?>
				<div class="reatrium-ai-box" style="margin-top:14px;">
					<?php echo wp_kses_post( wpautop( esc_html( $qa_result['answer'] ) ) ); ?>
					<?php if ( ! empty( $qa_result['sources'] ) ) : ?>
						<p class="reatrium-muted">
							<strong><?php esc_html_e( 'Sources:', 'reatrium' ); ?></strong>
							<?php
							echo esc_html(
								implode(
									', ',
									array_map( static fn( $s ) => '#' . $s['id'] . ' ' . $s['title'], $qa_result['sources'] )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( current_user_can( 'publish_posts' ) ) : ?>
			<details class="reatrium-card">
				<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( '+ Add Handbook Article / SOP', 'reatrium' ); ?></summary>
				<form method="post" style="margin-top:14px;">
					<?php wp_nonce_field( 'reatrium_add_kb_nonce' ); ?>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Title', 'reatrium' ); ?></label>
						<input type="text" name="kb_title" required>
					</div>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Department', 'reatrium' ); ?></label>
						<select name="kb_department">
							<?php foreach ( $departments as $dept ) : ?>
								<option value="<?php echo esc_attr( $dept ); ?>"><?php echo esc_html( $dept ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Content', 'reatrium' ); ?></label>
						<textarea name="kb_content" rows="5" required></textarea>
					</div>
					<button type="submit" name="reatrium_add_kb" value="1" class="reatrium-btn reatrium-btn-primary">
						<?php esc_html_e( 'Publish Article', 'reatrium' ); ?>
					</button>
				</form>
			</details>
		<?php endif; ?>

		<div class="reatrium-card">
			<h2><?php esc_html_e( 'All Handbook Articles & SOPs', 'reatrium' ); ?></h2>
			<?php if ( empty( $articles ) ) : ?>
				<p class="reatrium-muted"><?php esc_html_e( 'No handbook articles yet.', 'reatrium' ); ?></p>
			<?php else : ?>
				<?php foreach ( $articles as $doc ) : ?>
					<?php $terms = wp_get_post_terms( $doc->ID, 'reatrium_department', [ 'fields' => 'names' ] ); ?>
					<div class="reatrium-list-item">
						<div class="reatrium-card-header">
							<strong><?php echo esc_html( $doc->post_title ); ?></strong>
							<span class="reatrium-badge is-ok"><?php echo esc_html( ! empty( $terms ) && is_array( $terms ) ? $terms[0] : 'General' ); ?></span>
						</div>
						<p class="reatrium-muted"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $doc->post_content ), 35 ) ); ?></p>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
