<?php
/**
 * Module: Company Knowledge Base & SOPs (AI RAG Q&A + SOP Drafting).
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_KB extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'knowledge_base';
	}

	public function name(): string {
		return __( 'Knowledge Base & SOPs', 'reatrium' );
	}

	public function description(): string {
		return __( 'Internal company handbook, department SOPs, AI Q&A with citations, and SOP drafting.', 'reatrium' );
	}

	public function icon(): string {
		return '📚';
	}

	public function is_core(): bool {
		return true;
	}

	public function post_types(): array {
		return [ 'reatrium_kb' ];
	}

	public function taxonomies(): array {
		return [ 'reatrium_department' ];
	}

	public function abilities(): array {
		return [ 'reatrium/ask-handbook', 'reatrium/draft-sop' ];
	}

	public function agents(): array {
		return [
			[
				'slug'              => 'reatrium-handbook',
				'name'              => 'ReAtrium Handbook Assistant',
				'description'       => 'Answers employee questions from internal SOPs and handbook articles.',
				'allowed_abilities' => [ 'reatrium/ask-handbook', 'reatrium/draft-sop', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
			],
		];
	}

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
			$embed = ReAtrium_AI_Gateway::embed( $chunk, [ 'module' => 'knowledge_base', 'department' => $dept ] );
			$store->upsert(
				$post_id,
				$idx,
				$chunk,
				$embed['vector'],
				[
					'department' => $dept,
					'model_name' => $embed['model'],
				]
			);
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
						'question'   => [ 'type' => 'string' ],
						'department' => [ 'type' => 'string' ],
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
				'execute_callback'    => [ $this, 'execute_draft_sop' ],
			]
		);
	}

	/**
	 * Turn rough notes into a structured SOP via AI Gateway.
	 */
	public function execute_draft_sop( array $input ): array {
		$title = sanitize_text_field( $input['title'] ?? '' );
		$notes = sanitize_textarea_field( $input['notes'] ?? '' );

		$ai = ReAtrium_AI_Gateway::generate(
			"Convert these rough notes into a clear, structured SOP titled '{$title}' with Purpose, Scope, Step-by-Step Procedure, and Owner sections:\n\n{$notes}",
			[
				'agent_slug'  => 'reatrium-handbook',
				'module'      => 'knowledge_base',
				'zone'        => 'intranet',
				'sensitivity' => 'internal',
				'system'      => 'You write clear, concise Standard Operating Procedures for an SMB team.',
			]
		);

		return [
			'ok'    => (bool) $ai['ok'],
			'draft' => $ai['ok'] ? $ai['text'] : $ai['error'],
		];
	}

	/**
	 * Retrieve matching KB chunks via hybrid vector/lexical store (with WP_Query fallback)
	 * and synthesize a cited answer via AI Gateway.
	 */
	public function execute_ask_handbook( array $input ): array {
		$question   = sanitize_text_field( $input['question'] ?? '' );
		$department = sanitize_text_field( $input['department'] ?? '' );
		if ( $question === '' ) {
			return [ 'answer' => '', 'sources' => [] ];
		}

		$filters   = $department !== '' ? [ 'department' => $department ] : [];
		$store     = ReAtrium_AI_Gateway::vector_store();
		$query_vec = ReAtrium_AI_Gateway::embed( $question, [ 'module' => 'knowledge_base' ] )['vector'];
		$chunks    = [];

		if ( method_exists( $store, 'search_hybrid' ) ) {
			$chunks = $store->search_hybrid( $question, $query_vec, 6, $filters );
		} elseif ( ! empty( $query_vec ) ) {
			$chunks = $store->search( $query_vec, 6, $filters );
		}

		$sources = [];
		$seen    = [];
		$context = '';

		if ( ! empty( $chunks ) ) {
			foreach ( $chunks as $chunk ) {
				$pid  = (int) $chunk['post_id'];
				$post = get_post( $pid );
				if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
					continue;
				}
				if ( ! isset( $seen[ $pid ] ) ) {
					$seen[ $pid ] = true;
					$sources[]    = [
						'id'    => $pid,
						'title' => $post->post_title,
					];
				}
				$context .= sprintf(
					"### [Doc #%d] %s (%s)\n%s\n\n",
					$pid,
					$post->post_title,
					$chunk['department'] ?: 'General',
					$chunk['chunk_text']
				);
			}
		}

		// Fallback to WP_Query if the vector index has no published chunks yet.
		if ( $context === '' ) {
			$query_args = [
				'post_type'               => 'reatrium_kb',
				'post_status'             => 'publish',
				's'                       => $question,
				'posts_per_page'          => 5,
				'reatrium_internal_query' => true,
			];
			if ( $department !== '' ) {
				$query_args['tax_query'] = [
					[
						'taxonomy' => 'reatrium_department',
						'field'    => 'name',
						'terms'    => $department,
					],
				];
			}

			$matches = get_posts( $query_args );
			if ( empty( $matches ) ) {
				unset( $query_args['s'] );
				$query_args['posts_per_page'] = 8;
				$matches = get_posts( $query_args );
			}

			if ( empty( $matches ) ) {
				return [
					'answer'  => __( 'No handbook articles have been published yet.', 'reatrium' ),
					'sources' => [],
				];
			}

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
		}

		$ai = ReAtrium_AI_Gateway::generate(
			"Answer the employee question using ONLY the company handbook excerpts below. Cite the [Doc #ID] in your answer. If the answer isn't in the excerpts, say so.\n\nQuestion: {$question}\n\nHandbook:\n{$context}",
			[
				'agent_slug'  => 'reatrium-handbook',
				'module'      => 'knowledge_base',
				'department'  => $department ?: 'intranet',
				'zone'        => 'intranet',
				'sensitivity' => 'internal',
				'system'      => 'You are a helpful, accurate company handbook assistant.',
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
		$qa_result     = null;
		$sop_result    = null;
		$question      = '';
		$selected_dept = '';
		$notice_key    = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$created_msg   = $notice_key === 'kb_published' ? __( 'Handbook article published.', 'reatrium' ) : '';

		if ( isset( $_POST['reatrium_ask_kb'] ) && check_admin_referer( 'reatrium_ask_kb_nonce' ) ) {
			$question      = sanitize_text_field( wp_unslash( $_POST['kb_question'] ?? '' ) );
			$selected_dept = sanitize_text_field( wp_unslash( $_POST['kb_filter_dept'] ?? '' ) );
			$qa_result     = $this->execute_ask_handbook( [
				'question'   => $question,
				'department' => $selected_dept,
			] );
		}

		if ( isset( $_POST['reatrium_draft_sop'] ) && check_admin_referer( 'reatrium_draft_sop_nonce' ) && current_user_can( 'edit_posts' ) ) {
			$sop_title  = sanitize_text_field( wp_unslash( $_POST['sop_title'] ?? '' ) );
			$sop_notes  = sanitize_textarea_field( wp_unslash( $_POST['sop_notes'] ?? '' ) );
			$sop_result = $this->execute_draft_sop( [
				'title' => $sop_title,
				'notes' => $sop_notes,
			] );
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
				<select name="kb_filter_dept" style="padding:8px 10px;border:1px solid var(--ra-border);border-radius:8px;font-size:14px;">
					<option value=""><?php esc_html_e( 'All Departments', 'reatrium' ); ?></option>
					<?php foreach ( $departments as $dept ) : ?>
						<option value="<?php echo esc_attr( $dept ); ?>" <?php selected( $selected_dept, $dept ); ?>><?php echo esc_html( $dept ); ?></option>
					<?php endforeach; ?>
				</select>
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

		<?php if ( current_user_can( 'edit_posts' ) ) : ?>
			<details class="reatrium-card" <?php echo is_array( $sop_result ) ? 'open' : ''; ?>>
				<summary style="cursor:pointer;font-weight:600;">✨ <?php esc_html_e( 'Draft an SOP from Notes with AI', 'reatrium' ); ?></summary>
				<form method="post" style="margin-top:14px;">
					<?php wp_nonce_field( 'reatrium_draft_sop_nonce' ); ?>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'SOP Title', 'reatrium' ); ?></label>
						<input type="text" name="sop_title" required placeholder="<?php esc_attr_e( 'e.g. New Employee Laptop Onboarding', 'reatrium' ); ?>">
					</div>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Rough Notes / Bullet Points', 'reatrium' ); ?></label>
						<textarea name="sop_notes" rows="4" required placeholder="<?php esc_attr_e( 'Order laptop 2 weeks ahead, enroll in MDM, install VPN, hand off on Day 1...', 'reatrium' ); ?>"></textarea>
					</div>
					<button type="submit" name="reatrium_draft_sop" value="1" class="reatrium-btn reatrium-btn-primary">
						✨ <?php esc_html_e( 'Generate SOP Draft', 'reatrium' ); ?>
					</button>
				</form>
				<?php if ( is_array( $sop_result ) ) : ?>
					<div class="reatrium-ai-box" style="margin-top:14px;">
						<?php echo wp_kses_post( wpautop( esc_html( $sop_result['draft'] ) ) ); ?>
					</div>
				<?php endif; ?>
			</details>
		<?php endif; ?>

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
