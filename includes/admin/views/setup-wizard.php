<?php
/**
 * Admin View: 5-Minute ReAtrium Setup Wizard.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$company_name = (string) get_option( 'reatrium_company_name', get_bloginfo( 'name' ) );
$departments  = (array) get_option( 'reatrium_departments', [ 'General', 'Operations', 'IT', 'HR', 'Sales' ] );
$registry     = ReAtrium_Module_Registry::instance();
$all_modules  = $registry->all();
$ai_ready     = ReAtrium_AI_Gateway::is_ai_available();
$aigis_active = ReAtrium_AI_Gateway::is_aigis_active();
$logotype_url = REATRIUM_PLUGIN_URL . 'assets/img/reatrium-logotype-lm.svg';
?>
<div class="wrap">
	<div style="display:flex;align-items:center;gap:14px;margin:12px 0 6px;">
		<img src="<?php echo esc_url( $logotype_url ); ?>" alt="ReAtrium" style="height:34px;width:auto;display:block;">
		<span style="color:#64748b;font-size:14px;font-weight:600;"><?php esc_html_e( 'Setup Wizard', 'reatrium' ); ?></span>
	</div>
	<p class="description">
		<?php esc_html_e( 'Configure your out-of-the-box AI-forward SMB intranet in under 5 minutes.', 'reatrium' ); ?>
	</p>

	<?php if ( ! empty( $saved ) ) : ?>
		<?php $portal_url = ReAtrium_Portal::portal_url(); ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php esc_html_e( 'Setup saved! Your employee portal is live:', 'reatrium' ); ?>
				<a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"><?php echo esc_html( $portal_url ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="card" style="max-width:740px;margin-top:16px;padding:16px 20px;">
		<h2><?php esc_html_e( 'Environment & AI Readiness', 'reatrium' ); ?></h2>
		<ul>
			<li>
				<strong><?php esc_html_e( 'Permalinks:', 'reatrium' ); ?></strong>
				<?php if ( get_option( 'permalink_structure' ) !== '' ) : ?>
					✅ <?php esc_html_e( 'Pretty permalinks active (/intranet/)', 'reatrium' ); ?>
				<?php else : ?>
					ℹ️ <?php esc_html_e( 'Plain permalinks active (portal uses ?reatrium_portal=1). Switch to "Post name" under Settings → Permalinks for /intranet/ URLs.', 'reatrium' ); ?>
				<?php endif; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'WordPress 7+ AI Client:', 'reatrium' ); ?></strong>
				<?php echo $ai_ready ? '✅ ' . esc_html__( 'Available', 'reatrium' ) : '⚠️ ' . esc_html__( 'Configure a provider under Settings → Connectors', 'reatrium' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'AIGIS Governance Control Plane:', 'reatrium' ); ?></strong>
				<?php echo $aigis_active ? '✅ ' . esc_html__( 'Active (PII guardrails, budgets & audit trail enabled)', 'reatrium' ) : 'ℹ️ ' . esc_html__( 'Not active (running in standalone mode)', 'reatrium' ); ?>
			</li>
		</ul>
	</div>

	<form method="post" style="max-width:740px;margin-top:16px;">
		<?php wp_nonce_field( 'reatrium_wizard_nonce' ); ?>
		<input type="hidden" name="reatrium_wizard_save" value="1">

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
				<th><?php esc_html_e( 'Enabled Modules', 'reatrium' ); ?></th>
				<td>
					<?php foreach ( $all_modules as $mod_id => $module ) : ?>
						<label style="display:block;margin-bottom:8px;">
							<input type="checkbox" name="reatrium_modules[]" value="<?php echo esc_attr( $mod_id ); ?>" <?php checked( $registry->is_enabled( $mod_id ) ); ?>>
							<strong><?php echo esc_html( $module->icon() . ' ' . $module->name() ); ?></strong>
							<?php if ( ! $module->is_core() ) : ?>
								<span style="background:#eff6ff;color:#1d4ed8;font-size:11px;padding:1px 6px;border-radius:999px;margin-left:4px;"><?php esc_html_e( 'Add-on', 'reatrium' ); ?></span>
							<?php endif; ?>
							<br>
							<span class="description" style="margin-left:24px;"><?php echo esc_html( $module->description() ); ?></span>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Starter Content', 'reatrium' ); ?></th>
				<td>
					<label><input type="checkbox" name="seed_sample_data" value="1" <?php checked( ! get_option( 'reatrium_setup_completed' ) ); ?>> <?php esc_html_e( 'Seed demo team members, announcements, handbook SOPs, and sample requests (can be wiped anytime in Settings → Overview)', 'reatrium' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save & Launch Intranet Portal', 'reatrium' ) ); ?>
	</form>
</div>
