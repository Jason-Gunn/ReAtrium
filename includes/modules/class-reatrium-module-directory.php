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
		$loader->add_action( 'reatrium_portal_before_render', $this, 'handle_portal_post' );
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

	/**
	 * Handle self-service profile updates from the People Directory portal view.
	 */
	public function handle_portal_post( string $current_view ): void {
		if ( $current_view !== 'directory' ) {
			return;
		}

		if ( isset( $_POST['reatrium_update_my_profile'] ) && check_admin_referer( 'reatrium_my_profile_nonce' ) ) {
			$uid = get_current_user_id();
			if ( $uid > 0 ) {
				update_user_meta( $uid, 'reatrium_job_title', sanitize_text_field( wp_unslash( $_POST['my_job_title'] ?? '' ) ) );
				update_user_meta( $uid, 'reatrium_department', sanitize_text_field( wp_unslash( $_POST['my_department'] ?? '' ) ) );
				update_user_meta( $uid, 'reatrium_location', sanitize_text_field( wp_unslash( $_POST['my_location'] ?? '' ) ) );
				update_user_meta( $uid, 'reatrium_skills', sanitize_text_field( wp_unslash( $_POST['my_skills'] ?? '' ) ) );
				wp_safe_redirect( add_query_arg( 'reatrium_notice', 'profile_saved', ReAtrium_Portal::portal_url( 'directory' ) ) );
				exit;
			}
		}
	}

	public function render_portal_view(): void {
		$expert_result = null;
		$query_text    = '';
		$notice_key    = sanitize_key( wp_unslash( $_GET['reatrium_notice'] ?? '' ) );
		$notice        = $notice_key === 'profile_saved' ? __( 'Your directory profile has been updated.', 'reatrium' ) : '';
		$filter_dept   = sanitize_text_field( wp_unslash( $_GET['dept'] ?? '' ) );
		$filter_q      = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$departments   = (array) get_option( 'reatrium_departments', [ 'General', 'Operations', 'IT', 'HR', 'Sales' ] );

		if ( isset( $_POST['reatrium_find_expert'] ) && check_admin_referer( 'reatrium_directory_nonce' ) ) {
			$query_text    = sanitize_text_field( wp_unslash( $_POST['expert_query'] ?? '' ) );
			$expert_result = $this->execute_find_expert( [ 'query' => $query_text ] );
		}

		$all_users = get_users( [
			'role__in' => ReAtrium_Isolation::allowed_roles(),
			'number'   => 100,
			'orderby'  => 'display_name',
		] );

		$users = array_values(
			array_filter(
				$all_users,
				static function ( WP_User $u ) use ( $filter_dept, $filter_q ): bool {
					$dept   = (string) get_user_meta( $u->ID, 'reatrium_department', true ) ?: 'General';
					$title  = (string) get_user_meta( $u->ID, 'reatrium_job_title', true );
					$skills = (string) get_user_meta( $u->ID, 'reatrium_skills', true );
					$loc    = (string) get_user_meta( $u->ID, 'reatrium_location', true );

					if ( $filter_dept !== '' && strcasecmp( $dept, $filter_dept ) !== 0 ) {
						return false;
					}
					if ( $filter_q !== '' ) {
						$haystack = mb_strtolower( $u->display_name . ' ' . $title . ' ' . $dept . ' ' . $skills . ' ' . $loc, 'UTF-8' );
						if ( ! str_contains( $haystack, mb_strtolower( $filter_q, 'UTF-8' ) ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		$me_id       = get_current_user_id();
		$my_title    = (string) get_user_meta( $me_id, 'reatrium_job_title', true );
		$my_dept     = (string) get_user_meta( $me_id, 'reatrium_department', true );
		$my_location = (string) get_user_meta( $me_id, 'reatrium_location', true );
		$my_skills   = (string) get_user_meta( $me_id, 'reatrium_skills', true );
		?>
		<?php if ( $notice !== '' ) : ?>
			<div class="reatrium-alert is-ok"><?php echo esc_html( $notice ); ?></div>
		<?php endif; ?>

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

		<details class="reatrium-card">
			<summary style="cursor:pointer;font-weight:600;">✏️ <?php esc_html_e( 'Edit My Directory Profile', 'reatrium' ); ?></summary>
			<form method="post" style="margin-top:14px;">
				<?php wp_nonce_field( 'reatrium_my_profile_nonce' ); ?>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Job Title', 'reatrium' ); ?></label>
					<input type="text" name="my_job_title" value="<?php echo esc_attr( $my_title ); ?>" placeholder="<?php esc_attr_e( 'e.g. Operations Manager', 'reatrium' ); ?>">
				</div>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Department', 'reatrium' ); ?></label>
					<select name="my_department">
						<option value=""><?php esc_html_e( '— Select —', 'reatrium' ); ?></option>
						<?php foreach ( $departments as $d ) : ?>
							<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $my_dept, $d ); ?>><?php echo esc_html( $d ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Location / Time Zone', 'reatrium' ); ?></label>
					<input type="text" name="my_location" value="<?php echo esc_attr( $my_location ); ?>" placeholder="<?php esc_attr_e( 'e.g. Austin, TX (CT) or Remote', 'reatrium' ); ?>">
				</div>
				<div class="reatrium-field">
					<label><?php esc_html_e( 'Skills & Responsibilities', 'reatrium' ); ?></label>
					<input type="text" name="my_skills" value="<?php echo esc_attr( $my_skills ); ?>" placeholder="<?php esc_attr_e( 'e.g. AWS, Payroll, Onboarding, WordPress', 'reatrium' ); ?>">
				</div>
				<button type="submit" name="reatrium_update_my_profile" value="1" class="reatrium-btn reatrium-btn-primary">
					<?php esc_html_e( 'Save Profile', 'reatrium' ); ?>
				</button>
			</form>
		</details>

		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
			<div class="reatrium-pills" style="margin-bottom:0;">
				<a href="<?php echo esc_url( ReAtrium_Portal::portal_url( 'directory' ) ); ?>" class="reatrium-pill <?php echo $filter_dept === '' ? 'is-active' : ''; ?>">
					<?php echo esc_html( sprintf( __( 'All (%d)', 'reatrium' ), count( $all_users ) ) ); ?>
				</a>
				<?php foreach ( $departments as $dept ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'dept', $dept, ReAtrium_Portal::portal_url( 'directory' ) ) ); ?>" class="reatrium-pill <?php echo $filter_dept === $dept ? 'is-active' : ''; ?>">
						<?php echo esc_html( $dept ); ?>
					</a>
				<?php endforeach; ?>
			</div>
			<form method="get" action="<?php echo esc_url( ReAtrium_Portal::portal_url( 'directory' ) ); ?>" style="display:flex;gap:6px;">
				<?php if ( get_option( 'permalink_structure' ) === '' ) : ?>
					<input type="hidden" name="reatrium_portal" value="1">
					<input type="hidden" name="reatrium_view" value="directory">
				<?php endif; ?>
				<?php if ( $filter_dept !== '' ) : ?>
					<input type="hidden" name="dept" value="<?php echo esc_attr( $filter_dept ); ?>">
				<?php endif; ?>
				<input type="text" name="q" value="<?php echo esc_attr( $filter_q ); ?>" placeholder="<?php esc_attr_e( 'Search name or skill…', 'reatrium' ); ?>" style="padding:6px 10px;border:1px solid var(--ra-border);border-radius:8px;font-size:13px;">
				<button type="submit" class="reatrium-btn"><?php esc_html_e( 'Filter', 'reatrium' ); ?></button>
			</form>
		</div>

		<?php if ( empty( $users ) ) : ?>
			<div class="reatrium-card">
				<p class="reatrium-muted"><?php esc_html_e( 'No team members match this filter.', 'reatrium' ); ?></p>
			</div>
		<?php else : ?>
			<div class="reatrium-grid">
				<?php foreach ( $users as $u ) : ?>
					<?php
					$dept   = (string) get_user_meta( $u->ID, 'reatrium_department', true ) ?: 'General';
					$title  = (string) get_user_meta( $u->ID, 'reatrium_job_title', true ) ?: 'Team Member';
					$skills = (string) get_user_meta( $u->ID, 'reatrium_skills', true );
					$loc    = (string) get_user_meta( $u->ID, 'reatrium_location', true );
					?>
					<div class="reatrium-card" style="margin-bottom:0;">
						<div class="reatrium-person-head">
							<?php echo get_avatar( $u->ID, 42 ); ?>
							<div>
								<strong><?php echo esc_html( $u->display_name ); ?></strong>
								<div class="reatrium-muted"><?php echo esc_html( $title ); ?></div>
							</div>
						</div>
						<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
							<span class="reatrium-badge is-ok"><?php echo esc_html( $dept ); ?></span>
							<?php if ( $loc !== '' ) : ?>
								<span class="reatrium-badge is-neutral">📍 <?php echo esc_html( $loc ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $skills !== '' ) : ?>
							<p style="font-size:13px;margin:8px 0;color:var(--ra-text);"><?php echo esc_html( $skills ); ?></p>
						<?php endif; ?>
						<a href="mailto:<?php echo esc_attr( $u->user_email ); ?>" style="font-size:13px;color:var(--ra-accent);text-decoration:none;"><?php echo esc_html( $u->user_email ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}
}
