<?php
/**
 * Module: People & Org Directory (with AI Expert Finder).
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReAtrium_Module_Directory extends ReAtrium_Abstract_Module {

	public function id(): string {
		return 'directory';
	}

	public function name(): string {
		return __( 'People & Org Directory', 'reatrium' );
	}

	public function description(): string {
		return __( 'Team directory with departments, job titles, skills, and an AI Expert Finder.', 'reatrium' );
	}

	public function icon(): string {
		return '👥';
	}

	public function is_core(): bool {
		return true;
	}

	public function abilities(): array {
		return [ 'reatrium/find-expert' ];
	}

	public function agents(): array {
		return [
			[
				'slug'              => 'reatrium-directory',
				'name'              => 'ReAtrium People Finder',
				'description'       => 'Matches employee questions to colleagues and departments.',
				'allowed_abilities' => [ 'reatrium/find-expert', 'aigis/check-guardrail' ],
				'pii_policy'        => 'warn',
			],
		];
	}

	public function register( ReAtrium_Loader $loader ): void {
		$loader->add_action( 'show_user_profile', $this, 'render_profile_fields' );
		$loader->add_action( 'edit_user_profile', $this, 'render_profile_fields' );
		$loader->add_action( 'personal_options_update', $this, 'save_profile_fields' );
		$loader->add_action( 'edit_user_profile_update', $this, 'save_profile_fields' );
		$loader->add_action( 'wp_abilities_api_init', $this, 'register_abilities' );
		$loader->add_filter( 'reatrium_portal_views', $this, 'register_portal_view' );
	}

	public function render_profile_fields( WP_User $user ): void {
		$departments = (array) get_option( 'reatrium_departments', [ 'General' ] );
		$dept        = (string) get_user_meta( $user->ID, 'reatrium_department', true );
		$title       = (string) get_user_meta( $user->ID, 'reatrium_job_title', true );
		$skills      = (string) get_user_meta( $user->ID, 'reatrium_skills', true );
		?>
		<h3><?php esc_html_e( 'ReAtrium Directory Profile', 'reatrium' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="reatrium_job_title"><?php esc_html_e( 'Job Title', 'reatrium' ); ?></label></th>
				<td><input type="text" name="reatrium_job_title" id="reatrium_job_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="reatrium_department"><?php esc_html_e( 'Department', 'reatrium' ); ?></label></th>
				<td>
					<select name="reatrium_department" id="reatrium_department">
						<option value=""><?php esc_html_e( '— Select —', 'reatrium' ); ?></option>
						<?php foreach ( $departments as $d ) : ?>
							<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $dept, $d ); ?>><?php echo esc_html( $d ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="reatrium_skills"><?php esc_html_e( 'Skills / Responsibilities', 'reatrium' ); ?></label></th>
				<td><input type="text" name="reatrium_skills" id="reatrium_skills" value="<?php echo esc_attr( $skills ); ?>" class="large-text" placeholder="e.g. AWS, Payroll, Onboarding, WordPress"></td>
			</tr>
		</table>
		<?php
	}

	public function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( isset( $_POST['reatrium_job_title'] ) ) {
			update_user_meta( $user_id, 'reatrium_job_title', sanitize_text_field( wp_unslash( $_POST['reatrium_job_title'] ) ) );
		}
		if ( isset( $_POST['reatrium_department'] ) ) {
			update_user_meta( $user_id, 'reatrium_department', sanitize_text_field( wp_unslash( $_POST['reatrium_department'] ) ) );
		}
		if ( isset( $_POST['reatrium_skills'] ) ) {
			update_user_meta( $user_id, 'reatrium_skills', sanitize_text_field( wp_unslash( $_POST['reatrium_skills'] ) ) );
		}
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'reatrium/find-expert',
			[
				'label'               => __( 'Find Internal Expert', 'reatrium' ),
				'description'         => __( 'Finds the right colleague or department for a topic based on the company directory.', 'reatrium' ),
				'category'            => 'reatrium-intranet',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'query' => [ 'type' => 'string' ],
					],
					'required'   => [ 'query' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'recommendation' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static fn() => ReAtrium_Isolation::is_intranet_user(),
				'execute_callback'    => [ $this, 'execute_find_expert' ],
			]
		);
	}

	public function execute_find_expert( array $input ): array {
		$query = sanitize_text_field( $input['query'] ?? '' );
		$users = get_users( [
			'role__in' => ReAtrium_Isolation::allowed_roles(),
			'number'   => 100,
			'orderby'  => 'display_name',
		] );

		$roster = '';
		foreach ( $users as $u ) {
			$dept   = (string) get_user_meta( $u->ID, 'reatrium_department', true ) ?: 'General';
			$title  = (string) get_user_meta( $u->ID, 'reatrium_job_title', true ) ?: 'Team Member';
			$skills = (string) get_user_meta( $u->ID, 'reatrium_skills', true );
			$roster .= sprintf( "- %s (%s, %s) | Skills: %s | Email: %s\n", $u->display_name, $title, $dept, $skills ?: 'n/a', $u->user_email );
		}

		$ai = ReAtrium_AI_Gateway::generate(
			"Based on the employee directory below, who should the user contact for: '{$query}'? Give 1-3 best matches with why.\n\nDirectory:\n{$roster}",
			[
				'agent_slug'  => 'reatrium-directory',
				'module'      => 'directory',
				'zone'        => 'intranet',
				'sensitivity' => 'internal',
				'system'      => 'You are a helpful internal directory assistant. Only recommend people listed in the directory.',
			]
		);

		return [
			'recommendation' => $ai['ok']
				? $ai['text']
				: sprintf( __( 'AI unavailable (%s). Browse the directory cards below.', 'reatrium' ), $ai['error'] ),
		];
	}

	public function register_portal_view( array $views ): array {
		$views['directory'] = [
			'label'    => __( 'People Directory', 'reatrium' ),
			'icon'     => '👥',
			'callback' => [ $this, 'render_portal_view' ],
		];
		return $views;
	}

	public function render_portal_view(): void {
		$expert_result = null;
		$query_text    = '';

		if ( isset( $_POST['reatrium_find_expert'] ) && check_admin_referer( 'reatrium_directory_nonce' ) ) {
			$query_text    = sanitize_text_field( wp_unslash( $_POST['expert_query'] ?? '' ) );
			$expert_result = $this->execute_find_expert( [ 'query' => $query_text ] );
		}

		$users = get_users( [
			'role__in' => ReAtrium_Isolation::allowed_roles(),
			'number'   => 100,
			'orderby'  => 'display_name',
		] );
		?>
		<div class="reatrium-card">
			<h2><?php esc_html_e( 'Find the Right Person', 'reatrium' ); ?></h2>
			<form method="post" class="reatrium-inline-form">
				<?php wp_nonce_field( 'reatrium_directory_nonce' ); ?>
				<input type="text" name="expert_query" value="<?php echo esc_attr( $query_text ); ?>" placeholder="<?php esc_attr_e( 'e.g. Who handles AWS billing or vendor onboarding?', 'reatrium' ); ?>" required>
				<button type="submit" name="reatrium_find_expert" value="1" class="reatrium-btn reatrium-btn-primary">
					✨ <?php esc_html_e( 'Ask AI', 'reatrium' ); ?>
				</button>
			</form>
			<?php if ( is_array( $expert_result ) ) : ?>
				<div class="reatrium-ai-box" style="margin-top:14px;">
					<?php echo wp_kses_post( wpautop( esc_html( $expert_result['recommendation'] ) ) ); ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="reatrium-grid">
			<?php foreach ( $users as $u ) : ?>
				<?php
				$dept   = (string) get_user_meta( $u->ID, 'reatrium_department', true ) ?: 'General';
				$title  = (string) get_user_meta( $u->ID, 'reatrium_job_title', true ) ?: 'Team Member';
				$skills = (string) get_user_meta( $u->ID, 'reatrium_skills', true );
				?>
				<div class="reatrium-card">
					<strong><?php echo esc_html( $u->display_name ); ?></strong>
					<div class="reatrium-muted"><?php echo esc_html( $title ); ?> · <?php echo esc_html( $dept ); ?></div>
					<?php if ( $skills !== '' ) : ?>
						<p style="font-size:13px;margin:8px 0;"><?php echo esc_html( $skills ); ?></p>
					<?php endif; ?>
					<a href="mailto:<?php echo esc_attr( $u->user_email ); ?>" style="font-size:13px;"><?php echo esc_html( $u->user_email ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
