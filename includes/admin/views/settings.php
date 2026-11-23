<?php
/**
 * Admin View: ReAtrium Overview, Modules, Access & Branding Settings.
 *
 * @package ReAtrium
 *
 * @var ReAtrium_Module_Registry $registry
 * @var string                   $active_tab
 * @var string                   $notice
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$logotype_url = REATRIUM_PLUGIN_URL . 'assets/img/reatrium-logotype-lm.svg';
$portal_url   = ReAtrium_Portal::portal_url();
$is_admin     = current_user_can( 'manage_options' );
$all_modules  = $registry->all();
$enabled_mods = $registry->enabled();
$all_agents   = $registry->collect_agents();

$tabs = [
	'overview' => __( 'Overview', 'reatrium' ),
];
if ( $is_admin ) {
	$tabs['modules']  = __( 'Modules', 'reatrium' );
	$tabs['access']   = __( 'Access & Isolation', 'reatrium' );
	$tabs['branding'] = __( 'Branding', 'reatrium' );
}
if ( ! isset( $tabs[ $active_tab ] ) ) {
	$active_tab = 'overview';
}
?>
<div class="wrap">
	<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:12px 0 8px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<img src="<?php echo esc_url( $logotype_url ); ?>" alt="ReAtrium" style="height:34px;width:auto;display:block;">
			<span style="background:#fff4f0;color:#e8420a;font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;">v<?php echo esc_html( REATRIUM_VERSION ); ?></span>
		</div>
		<div>
			<a class="button button-primary" href="<?php echo esc_url( $portal_url ); ?>" target="_blank">
				<?php esc_html_e( 'Open Employee Portal ↗', 'reatrium' ); ?>
			</a>
			<?php if ( $is_admin ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=reatrium-setup' ) ); ?>">
					<?php esc_html_e( 'Run Setup Wizard', 'reatrium' ); ?>
				</a>
				<?php if ( ReAtrium_AI_Gateway::is_aigis_active() ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aigis-agents' ) ); ?>">
						<?php esc_html_e( 'Manage Agents in AIGIS', 'reatrium' ); ?>
					</a>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $notice ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
	<?php endif; ?>

	<?php if ( count( $tabs ) > 1 ) : ?>
		<nav class="nav-tab-wrapper" style="margin-bottom:18px;">
			<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=reatrium-settings&tab=' . $tab_slug ) ); ?>" class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $tab_label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $active_tab === 'overview' ) : ?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;max-width:960px;">
			<div class="card" style="margin:0;padding:16px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'System Status', 'reatrium' ); ?></h2>
				<ul style="margin:0;">
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Portal URL:', 'reatrium' ); ?></strong>
						<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"><?php echo esc_html( $portal_url ); ?></a>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'WP 7+ AI Client:', 'reatrium' ); ?></strong>
						<?php echo ReAtrium_AI_Gateway::is_ai_available() ? '✅ ' . esc_html__( 'Ready', 'reatrium' ) : '⚠️ ' . esc_html__( 'Needs connector', 'reatrium' ); ?>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'AIGIS Control Plane:', 'reatrium' ); ?></strong>
						<?php echo ReAtrium_AI_Gateway::is_aigis_active() ? '✅ ' . esc_html__( 'Connected', 'reatrium' ) : 'ℹ️ ' . esc_html__( 'Standalone mode', 'reatrium' ); ?>
					</li>
				</ul>
			</div>

			<div class="card" style="margin:0;padding:16px 20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Workspace Summary', 'reatrium' ); ?></h2>
				<ul style="margin:0;">
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Active Modules:', 'reatrium' ); ?></strong>
						<?php echo esc_html( count( $enabled_mods ) . ' / ' . count( $all_modules ) ); ?>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Registered AI Agents:', 'reatrium' ); ?></strong>
						<?php echo esc_html( (string) count( $all_agents ) ); ?>
					</li>
					<li style="margin-bottom:8px;">
						<strong><?php esc_html_e( 'Isolated CPTs:', 'reatrium' ); ?></strong>
						<code><?php echo esc_html( implode( ', ', ReAtrium_Isolation::intranet_post_types() ) ); ?></code>
					</li>
				</ul>
			</div>
		</div>

		<?php if ( $is_admin ) : ?>
			<?php $sample_stats = ReAtrium_Sample_Data::stats(); ?>
			<div class="card" style="max-width:960px;padding:16px 20px;margin-top:16px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Demo & Sample Data (Testing)', 'reatrium' ); ?></h2>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'Populate realistic demo team members, pinned announcements, department SOPs, and helpdesk requests to test portal and AI features. All sample items are tagged so wiping them never affects your real company content or users.', 'reatrium' ); ?>
				</p>
				<p style="margin:10px 0;">
					<strong><?php esc_html_e( 'Currently installed sample data:', 'reatrium' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: users, 2: announcements, 3: kb, 4: requests */
							__( '%1$d demo users · %2$d announcements · %3$d handbook SOPs · %4$d requests', 'reatrium' ),
							$sample_stats['users'],
							$sample_stats['announcements'],
							$sample_stats['kb'],
							$sample_stats['requests']
						)
					);
					?>
				</p>
				<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
					<?php wp_nonce_field( 'reatrium_sample_data_nonce' ); ?>
					<button type="submit" name="reatrium_sample_action" value="seed" class="button button-secondary">
						<?php esc_html_e( 'Generate Sample Data', 'reatrium' ); ?>
					</button>
					<?php if ( $sample_stats['total'] > 0 ) : ?>
						<button type="submit" name="reatrium_sample_action" value="wipe" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete all tagged ReAtrium sample users and demo posts? Real content and users will not be touched.', 'reatrium' ) ); ?>');">
							<?php esc_html_e( 'Wipe All Sample Data', 'reatrium' ); ?>
						</button>
					<?php endif; ?>
				</form>
			</div>
		<?php endif; ?>

	<?php elseif ( $active_tab === 'modules' && $is_admin ) : ?>
		<form method="post" style="max-width:960px;">
			<?php wp_nonce_field( 'reatrium_settings_modules_nonce' ); ?>
			<input type="hidden" name="reatrium_save_modules" value="1">

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;margin-bottom:16px;">
				<?php foreach ( $all_modules as $mod_id => $module ) : ?>
					<?php $is_on = $registry->is_enabled( $mod_id ); ?>
					<div class="card" style="margin:0;padding:16px 18px;display:flex;flex-direction:column;justify-content:space-between;">
						<div>
							<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px;">
								<label style="font-weight:600;font-size:14px;cursor:pointer;">
									<input type="checkbox" name="reatrium_modules[]" value="<?php echo esc_attr( $mod_id ); ?>" <?php checked( $is_on ); ?>>
									<?php echo esc_html( $module->icon() . ' ' . $module->name() ); ?>
								</label>
								<span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:<?php echo $module->zone() === 'public' ? '#fef3c7;color:#92400e' : '#dcfce7;color:#166534'; ?>;">
									<?php echo esc_html( ucfirst( $module->zone() ) ); ?>
								</span>
							</div>
							<p class="description" style="margin:0 0 12px;"><?php echo esc_html( $module->description() ); ?></p>
						</div>
						<div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:8px;">
							<span><?php echo $module->is_core() ? esc_html__( 'Core Module', 'reatrium' ) : esc_html__( 'Community Add-on', 'reatrium' ); ?> · v<?php echo esc_html( $module->version() ); ?></span>
							<span>
								<?php
								/* translators: %d: number of agents */
								echo esc_html( sprintf( _n( '%d agent', '%d agents', count( $module->agents() ), 'reatrium' ), count( $module->agents() ) ) );
								?>
							</span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Save Module Settings', 'reatrium' ) ); ?>

			<div class="card" style="max-width:960px;padding:14px 18px;margin-top:8px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Building Custom or Community Modules', 'reatrium' ); ?></h3>
				<p class="description" style="margin-bottom:6px;">
					<?php esc_html_e( 'Extend ReAtrium_Abstract_Module in any WordPress plugin and register it on the reatrium_register_modules action. Enabled modules automatically inherit intranet isolation, portal navigation, and AIGIS agent sync.', 'reatrium' ); ?>
				</p>
				<code>add_action( 'reatrium_register_modules', fn( $registry ) =&gt; $registry-&gt;register( new My_Module() ) );</code>
			</div>
		</form>

	<?php elseif ( $active_tab === 'access' && $is_admin ) : ?>
		<?php
		$wp_roles      = wp_roles();
		$allowed_roles = ReAtrium_Isolation::allowed_roles();
		?>
		<form method="post" style="max-width:740px;">
			<?php wp_nonce_field( 'reatrium_settings_access_nonce' ); ?>
			<input type="hidden" name="reatrium_save_access" value="1">

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Intranet Access Roles', 'reatrium' ); ?></th>
					<td>
						<p class="description" style="margin-top:0;margin-bottom:10px;">
							<?php esc_html_e( 'Select which WordPress roles can access /intranet/, view internal handbooks, and appear in the People Directory. Leave public roles (such as Subscriber or Customer) unchecked.', 'reatrium' ); ?>
						</p>
						<?php foreach ( $wp_roles->get_names() as $role_slug => $role_name ) : ?>
							<?php $is_admin_role = $role_slug === 'administrator'; ?>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="reatrium_allowed_roles[]" value="<?php echo esc_attr( $role_slug ); ?>" <?php checked( in_array( $role_slug, $allowed_roles, true ) ); ?> <?php disabled( $is_admin_role ); ?>>
								<?php echo esc_html( translate_user_role( $role_name ) ); ?>
								<?php if ( $is_admin_role ) : ?>
									<input type="hidden" name="reatrium_allowed_roles[]" value="administrator">
									<span class="description"><?php esc_html_e( '(always enabled)', 'reatrium' ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Protected Intranet Data', 'reatrium' ); ?></th>
					<td>
						<p style="margin-top:0;">
							<strong><?php esc_html_e( 'Post Types:', 'reatrium' ); ?></strong>
							<code><?php echo esc_html( implode( ', ', ReAtrium_Isolation::intranet_post_types() ) ); ?></code>
						</p>
						<p>
							<strong><?php esc_html_e( 'Taxonomies:', 'reatrium' ); ?></strong>
							<code><?php echo esc_html( implode( ', ', ReAtrium_Isolation::intranet_taxonomies() ) ); ?></code>
						</p>
						<p class="description">
							<?php esc_html_e( 'These post types and taxonomies are automatically excluded from public search, feeds, XML sitemaps, and unauthenticated REST API requests.', 'reatrium' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Access Settings', 'reatrium' ) ); ?>
		</form>

	<?php elseif ( $active_tab === 'branding' && $is_admin ) : ?>
		<?php
		$company_name = (string) get_option( 'reatrium_company_name', get_bloginfo( 'name' ) );
		$departments  = (array) get_option( 'reatrium_departments', [ 'General', 'Operations', 'IT', 'HR', 'Sales' ] );
		$accent_color = sanitize_hex_color( (string) get_option( 'reatrium_accent_color', '#e8420a' ) ) ?: '#e8420a';
		$logo_url     = (string) get_option( 'reatrium_logo_url', '' );
		?>
		<form method="post" style="max-width:740px;">
			<?php wp_nonce_field( 'reatrium_settings_branding_nonce' ); ?>
			<input type="hidden" name="reatrium_save_branding" value="1">

			<table class="form-table">
				<tr>
					<th><label for="company_name"><?php esc_html_e( 'Company Name', 'reatrium' ); ?></label></th>
					<td><input type="text" id="company_name" name="company_name" class="regular-text" value="<?php echo esc_attr( $company_name ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="departments"><?php esc_html_e( 'Departments', 'reatrium' ); ?></label></th>
					<td>
						<input type="text" id="departments" name="departments" class="large-text" value="<?php echo esc_attr( implode( ', ', $departments ) ); ?>">
						<p class="description"><?php esc_html_e( 'Comma-separated list used for Knowledge Base, Directory, and Request routing.', 'reatrium' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="accent_color"><?php esc_html_e( 'Portal Accent Color', 'reatrium' ); ?></label></th>
					<td>
						<input type="color" id="accent_color" name="accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
						<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Default: #e8420a (ReAtrium brand vermilion)', 'reatrium' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="logo_url"><?php esc_html_e( 'Custom Company Logo URL', 'reatrium' ); ?></label></th>
					<td>
						<input type="url" id="logo_url" name="logo_url" class="large-text" value="<?php echo esc_attr( $logo_url ); ?>" placeholder="<?php echo esc_attr( REATRIUM_PLUGIN_URL . 'assets/img/reatrium-logo.svg' ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to use the built-in ReAtrium logo mark in the employee portal sidebar.', 'reatrium' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Branding Settings', 'reatrium' ) ); ?>
		</form>
	<?php endif; ?>
</div>
