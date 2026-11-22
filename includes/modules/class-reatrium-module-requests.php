<?php
/**
 * Module: Internal Requests & AI Helpdesk Triage (IT, HR, Ops).
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Requests extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'requests';
	}

	public function name(): string {
		return __( 'Internal Requests & Helpdesk', 'reatrium' );
	}

	public function description(): string {
		return __( 'IT, HR, and Operations intake queue with automated AI triage and priority recommendations.', 'reatrium' );
	}

	public function icon(): string {
		return '📥';
	}

	public function is_core(): bool {
		return true;
	}

	public function post_types(): array {
		return [ 'reatrium_request' ];
	}

	public function abilities(): array {
		return [ 'reatrium/triage-request' ];
	}

	public function agents(): array {
		return [
			[
				'slug'              => 'reatrium-triage',
				'name'              => 'ReAtrium Helpdesk Triage',
				'description'       => 'Categorizes internal IT/HR/Ops requests and drafts initial responses.',
				'allowed_abilities' => [ 'reatrium/triage-request', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
			],
		];
	}

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'init', $this, 'register_cpt' );
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
		$loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view' );
		$loader->add_action( 'reatrium_portal_before_render', $this, 'handle_portal_post' );
	}

	public function register_cpt(): void {
		register_post_type(
			'reatrium_request',
			[
				'labels'              => [
					'name'          => __( 'Internal Requests', 'reatrium' ),
					'singular_name' => __( 'Request', 'reatrium' ),
				],
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'reatrium-settings',
				'show_in_rest'        => false,
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
			'reatrium/triage-request',
			[
				'label'               => __( 'Triage Internal Request', 'reatrium' ),
				'description'         => __( 'Categorizes an IT/HR/Ops request, suggests priority, and drafts an initial response.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'       => [ 'type' => 'string' ],
						'description' => [ 'type' => 'string' ],
					],
					'required'   => [ 'title', 'description' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'triage_notes' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn() => ReAtrium_Isolation::is_intranet_user(),
				'execute_callback'    => [ $this, 'execute_triage_request' ],
			]
		);
	}

	public function execute_triage_request( array $input ): array {
		$title       = sanitize_text_field( $input['title'] ?? '' );
		$description = sanitize_textarea_field( $input['description'] ?? '' );
		$depts       = implode( ', ', (array) get_option( 'reatrium_departments', [ 'IT', 'HR', 'Operations' ] ) );

		$ai = ReAtrium_AI_Gateway::generate(
			"Triage this internal employee request.\nDepartments: {$depts}\nTitle: {$title}\nDetails: {$description}\n\nProvide: 1) Suggested Department, 2) Suggested Priority (Low/Medium/High), 3) Short draft response for the assignee.",
			[
				'agent_slug'  => 'reatrium-triage',
				'module'      => 'requests',
				'zone'        => 'intranet',
				'sensitivity' => 'confidential',
				'system'      => 'You are an efficient internal IT/HR/Ops helpdesk triage assistant.',
			]
		);

		return [
			'triage_notes' => $ai['ok'] ? $ai['text'] : __( 'Pending manual triage.', 'reatrium' ),
		];
	}

	public function register_portal_view( array $views ): array {
		$views['requests'] = [
			'label'    => __( 'Requests & Helpdesk', 'reatrium' ),
			'icon'     => '📥',
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	/**
	 * Handle request submission and status updates before HTML output (POST-Redirect-GET).
	 */
	public function handle_portal_post( string $current_view ): void {
		if ( $current_view !== 'requests' ) {
			return;
		}

		if ( isset( $_POST['reatrium_submit_request'] ) && check_admin_referer( 'reatrium_request_nonce' ) ) {
			$title = sanitize_text_field( wp_unslash( $_POST['req_title'] ?? '' ) );
			$desc  = sanitize_textarea_field( wp_unslash( $_POST['req_desc'] ?? '' ) );
			$dept  = sanitize_text_field( wp_unslash( $_POST['req_dept'] ?? 'IT' ) );

			if ( $title !== '' && $desc !== '' ) {
				$post_id = wp_insert_post( [
					'post_type'    => 'reatrium_request',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $desc,
					'post_author'  => get_current_user_id(),
				] );

				if ( $post_id && ! is_wp_error( $post_id ) ) {
					$triage = $this->execute_triage_request( [ 'title' => $title, 'description' => $desc ] );
					update_post_meta( $post_id, '_reatrium_request_dept', $dept );
					update_post_meta( $post_id, '_reatrium_request_status', 'open' );
					update_post_meta( $post_id, '_reatrium_ai_triage', $triage['triage_notes'] );
					wp_safe_redirect( add_query_arg( 'reatrium_notice', 'req_submitted', ReAtrium_Portal::portal_url( 'requests' ) ) );
					exit;
				}
			}
		}

		if ( isset( $_POST['reatrium_update_req_status'] ) && check_admin_referer( 'reatrium_req_status_nonce' ) && current_user_can( 'edit_others_posts' ) ) {
			$req_id = (int) ( $_POST['req_id'] ?? 0 );
			$status = sanitize_key( wp_unslash( $_POST['new_status'] ?? '' ) );
			if ( $req_id > 0 && get_post_type( $req_id ) === 'reatrium_request' && in_array( $status, [ 'open', 'in_progress', 'resolved' ], true ) ) {
				update_post_meta( $req_id, '_reatrium_request_status', $status );
				wp_safe_redirect( add_query_arg( 'reatrium_notice', 'req_updated', ReAtrium_Portal::portal_url( 'requests' ) ) );
				exit;
			}
		}
	}

	public function render_portal_view(): void {
		$notice_key = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$notice     = '';
		if ( $notice_key === 'req_submitted' ) {
			$notice = __( 'Request submitted and triaged.', 'reatrium' );
		} elseif ( $notice_key === 'req_updated' ) {
			$notice = __( 'Request status updated.', 'reatrium' );
		}

		$args = [
			'post_type'               => 'reatrium_request',
			'post_status'             => 'publish',
			'posts_per_page'          => 25,
			'orderby'                 => 'date',
			'order'                   => 'DESC',
			'reatrium_internal_query' => true,
		];
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}

		$requests    = get_posts( $args );
		$departments = (array) get_option( 'reatrium_departments', [ 'IT', 'HR', 'Operations' ] );
		?>
		<?php if ( $notice !== '' ) : ?>
			<div class="reatrium-alert is-ok"><?php echo esc_html( $notice ); ?></div>
		<?php endif; ?>

		<div class="reatrium-card">
			<h2><?php esc_html_e( 'Submit an Internal Request', 'reatrium' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'reatrium_request_nonce' ); ?>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Subject', 'reatrium' ); ?></label>
					<input type="text" name="req_title" required placeholder="<?php esc_attr_e( 'e.g. Need Figma seat or laptop charger', 'reatrium' ); ?>">
				</div>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Department', 'reatrium' ); ?></label>
					<select name="req_dept">
						<?php foreach ( $departments as $d ) : ?>
							<option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Details', 'reatrium' ); ?></label>
					<textarea name="req_desc" rows="3" required></textarea>
				</div>
				<button type="submit" name="reatrium_submit_request" value="1" class="reatrium-btn reatrium-btn-primary">
					<?php esc_html_e( 'Submit Request', 'reatrium' ); ?>
				</button>
			</form>
		</div>

		<div class="reatrium-card">
			<h2><?php esc_html_e( 'Recent Requests', 'reatrium' ); ?></h2>
			<?php if ( empty( $requests ) ) : ?>
				<p class="reatrium-muted"><?php esc_html_e( 'No requests found.', 'reatrium' ); ?></p>
			<?php else : ?>
				<?php foreach ( $requests as $req ) : ?>
					<?php
					$dept   = (string) get_post_meta( $req->ID, '_reatrium_request_dept', true ) ?: 'General';
					$status = (string) get_post_meta( $req->ID, '_reatrium_request_status', true ) ?: 'open';
					$triage = (string) get_post_meta( $req->ID, '_reatrium_ai_triage', true );
					?>
					<div class="reatrium-list-item">
						<div class="reatrium-card-header">
							<strong><?php echo esc_html( $req->post_title ); ?></strong>
							<span class="reatrium-badge <?php echo $status === 'resolved' ? 'is-ok' : 'is-warn'; ?>">
								<?php echo esc_html( $dept . ' · ' . ucfirst( str_replace( '_', ' ', $status ) ) ); ?>
							</span>
						</div>
						<p><?php echo esc_html( wp_strip_all_tags( $req->post_content ) ); ?></p>
						<?php if ( $triage !== '' ) : ?>
							<div class="reatrium-ai-box" style="margin-top:8px;">
								<strong>✨ <?php esc_html_e( 'AI Triage:', 'reatrium' ); ?></strong>
								<?php echo wp_kses_post( wpautop( esc_html( $triage ) ) ); ?>
							</div>
						<?php endif; ?>
						<?php if ( current_user_can( 'edit_others_posts' ) ) : ?>
							<form method="post" style="margin-top:8px;display:flex;gap:6px;">
								<?php wp_nonce_field( 'reatrium_req_status_nonce' ); ?>
								<input type="hidden" name="req_id" value="<?php echo esc_attr( $req->ID ); ?>">
								<input type="hidden" name="reatrium_update_req_status" value="1">
								<button type="submit" name="new_status" value="in_progress" class="reatrium-btn"><?php esc_html_e( 'In Progress', 'reatrium' ); ?></button>
								<button type="submit" name="new_status" value="resolved" class="reatrium-btn"><?php esc_html_e( 'Resolve', 'reatrium' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
