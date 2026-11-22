<?php
/**
 * Example Community Module: Employee Onboarding Checklists.
 *
 * Demonstrates:
 * - Extending ReAtrium_Abstract_Module (automatically shown as a Community Add-on)
 * - Automatic Isolation Wall protection via post_types()
 * - Registering a WP 7+ Ability auto-discovered by Ask ReAtrium
 * - Declaring an AIGIS v2 governed agent via agents()
 * - Adding an Employee Portal tab with POST-Redirect-GET
 *
 * @package ReAtrium_Starter_Onboarding
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Onboarding extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'onboarding';
	}

	public function name(): string {
		return __( 'Employee Onboarding Checklists', 'reatrium-onboarding' );
	}

	public function description(): string {
		return __( 'Track new-hire onboarding checklists and generate role-specific 30-day onboarding plans with AI.', 'reatrium-onboarding' );
	}

	public function icon(): string {
		return '🚀';
	}

	public function version(): string {
		return '1.0.0';
	}

	public function post_types(): array {
		return [ 'reatrium_onboard' ];
	}

	public function abilities(): array {
		return [ 'reatrium/generate-onboarding-plan' ];
	}

	public function agents(): array {
		return [
			[
				'slug'              => 'reatrium-onboarding',
				'name'              => 'ReAtrium Onboarding Planner',
				'description'       => 'Generates structured 30-day onboarding checklists for new hires.',
				'allowed_abilities' => [ 'reatrium/generate-onboarding-plan', 'aigis/check-guardrail' ],
				'pii_policy'        => 'redact',
				'zone'              => 'intranet',
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
			'reatrium_onboard',
			[
				'labels'              => [
					'name'          => __( 'Onboarding Plans', 'reatrium-onboarding' ),
					'singular_name' => __( 'Onboarding Plan', 'reatrium-onboarding' ),
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
			'reatrium/generate-onboarding-plan',
			[
				'label'               => __( 'Generate New-Hire Onboarding Plan', 'reatrium-onboarding' ),
				'description'         => __( 'Drafts a structured Day 1, Week 1, and Day 30 onboarding checklist for a given role and department.', 'reatrium-onboarding' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'role'       => [ 'type' => 'string' ],
						'department' => [ 'type' => 'string' ],
					],
					'required'   => [ 'role' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'plan' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn() => ReAtrium_Isolation::is_intranet_user(),
				'execute_callback'    => [ $this, 'execute_generate_plan' ],
			]
		);
	}

	public function execute_generate_plan( array $input ): array {
		$role = sanitize_text_field( $input['role'] ?? '' );
		$dept = sanitize_text_field( $input['department'] ?? 'General' );

		$ai = ReAtrium_AI_Gateway::generate(
			"Create a concise, actionable onboarding checklist for a new {$role} joining the {$dept} department. Organize into Day 1, Week 1, and First 30 Days.",
			[
				'agent_slug'  => 'reatrium-onboarding',
				'module'      => $this->id(),
				'department'  => $dept,
				'zone'        => 'intranet',
				'sensitivity' => 'internal',
				'system'      => 'You are an SMB People Operations onboarding specialist.',
			]
		);

		return [
			'ok'   => (bool) $ai['ok'],
			'plan' => $ai['ok'] ? $ai['text'] : $ai['error'],
		];
	}

	public function register_portal_view( array $views ): array {
		$views['onboarding'] = [
			'label'    => __( 'Onboarding', 'reatrium-onboarding' ),
			'icon'     => $this->icon(),
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	public function handle_portal_post( string $current_view ): void {
		if ( $current_view !== 'onboarding' ) {
			return;
		}

		if ( isset( $_POST['reatrium_create_onboard'] ) && check_admin_referer( 'reatrium_onboard_nonce' ) && current_user_can( 'edit_posts' ) ) {
			$role = sanitize_text_field( wp_unslash( $_POST['onboard_role'] ?? '' ) );
			$dept = sanitize_text_field( wp_unslash( $_POST['onboard_dept'] ?? 'General' ) );

			if ( $role !== '' ) {
				$generated = $this->execute_generate_plan( [
					'role'       => $role,
					'department' => $dept,
				] );

				$content = ! empty( $generated['ok'] )
					? $generated['plan']
					: __( 'Day 1: Account setup & team intro. Week 1: Review handbook SOPs. Day 30: Role check-in.', 'reatrium-onboarding' );

				$post_id = wp_insert_post( [
					'post_type'    => 'reatrium_onboard',
					'post_status'  => 'publish',
					'post_title'   => sprintf( '%s — %s', $role, $dept ),
					'post_content' => wp_kses_post( $content ),
					'post_author'  => get_current_user_id(),
				] );

				if ( $post_id && ! is_wp_error( $post_id ) ) {
					wp_safe_redirect( add_query_arg( 'reatrium_notice', 'onboard_created', ReAtrium_Portal::portal_url( 'onboarding' ) ) );
					exit;
				}
			}
		}
	}

	public function render_portal_view(): void {
		$notice_key  = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$notice      = $notice_key === 'onboard_created' ? __( 'Onboarding plan created.', 'reatrium-onboarding' ) : '';
		$departments = (array) get_option( 'reatrium_departments', [ 'General', 'Operations', 'IT', 'HR', 'Sales' ] );
		$plans       = get_posts( [
			'post_type'               => 'reatrium_onboard',
			'post_status'             => 'publish',
			'posts_per_page'          => 20,
			'orderby'                 => 'date',
			'order'                   => 'DESC',
			'reatrium_internal_query' => true,
		] );
		?>
		<?php if ( $notice !== '' ) : ?>
			<div class="reatrium-alert is-ok"><?php echo esc_html( $notice ); ?></div>
		<?php endif; ?>

		<?php if ( current_user_can( 'edit_posts' ) ) : ?>
			<div class="reatrium-card">
				<h2><?php esc_html_e( 'Generate a New-Hire Onboarding Plan', 'reatrium-onboarding' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'reatrium_onboard_nonce' ); ?>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Role Title', 'reatrium-onboarding' ); ?></label>
						<input type="text" name="onboard_role" required placeholder="<?php esc_attr_e( 'e.g. Account Executive or Systems Administrator', 'reatrium-onboarding' ); ?>">
					</div>
					<div class="reatrium-field">
						<label><?php esc_html_e( 'Department', 'reatrium-onboarding' ); ?></label>
						<select name="onboard_dept">
							<?php foreach ( $departments as $d ) : ?>
								<option value="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<button type="submit" name="reatrium_create_onboard" value="1" class="reatrium-btn reatrium-btn-primary">
						✨ <?php esc_html_e( 'Generate & Save Plan', 'reatrium-onboarding' ); ?>
					</button>
				</form>
			</div>
		<?php endif; ?>

		<div class="reatrium-card">
			<h2><?php esc_html_e( 'Active Onboarding Plans', 'reatrium-onboarding' ); ?></h2>
			<?php if ( empty( $plans ) ) : ?>
				<p class="reatrium-muted"><?php esc_html_e( 'No onboarding plans created yet.', 'reatrium-onboarding' ); ?></p>
			<?php else : ?>
				<?php foreach ( $plans as $plan ) : ?>
					<div class="reatrium-list-item">
						<strong><?php echo esc_html( $plan->post_title ); ?></strong>
						<div><?php echo wp_kses_post( wpautop( $plan->post_content ) ); ?></div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
