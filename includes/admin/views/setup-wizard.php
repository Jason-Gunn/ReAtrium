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
$modules      = (array) get_option( 'reatrium_modules', [] );
$ai_ready     = ReAtrium_AI_Gateway::is_ai_available();
$aigis_active = ReAtrium_AI_Gateway::is_aigis_active();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'ReAtrium Setup Wizard', 'reatrium' ); ?></h1>
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

	<div class="card" style="max-width:720px;margin-top:16px;padding:16px 20px;">
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

	<form method="post" style="max-width:720px;margin-top:16px;">
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
					<label><input type="checkbox" name="mod_knowledge_base" value="1" <?php checked( ! empty( $modules['knowledge_base'] ) ); ?>> <?php esc_html_e( 'Knowledge Base & SOPs (AI RAG Q&A)', 'reatrium' ); ?></label><br>
					<label><input type="checkbox" name="mod_announcements" value="1" <?php checked( ! empty( $modules['announcements'] ) ); ?>> <?php esc_html_e( 'Company Announcements & AI Digest', 'reatrium' ); ?></label><br>
					<label><input type="checkbox" name="mod_directory" value="1" <?php checked( ! empty( $modules['directory'] ) ); ?>> <?php esc_html_e( 'People & Org Directory (AI Expert Finder)', 'reatrium' ); ?></label><br>
					<label><input type="checkbox" name="mod_requests" value="1" <?php checked( ! empty( $modules['requests'] ) ); ?>> <?php esc_html_e( 'Internal Requests & Helpdesk Triage', 'reatrium' ); ?></label><br>
					<label><input type="checkbox" name="mod_public_ai" value="1" <?php checked( ! empty( $modules['public_ai'] ) ); ?>> <?php esc_html_e( 'Public Website & Blog AI Drafting Helpers', 'reatrium' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Starter Content', 'reatrium' ); ?></th>
				<td>
					<label><input type="checkbox" name="seed_sample_data" value="1" <?php checked( ! get_option( 'reatrium_setup_completed' ) ); ?>> <?php esc_html_e( 'Seed a sample welcome announcement and PTO handbook article', 'reatrium' ); ?></label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save & Launch Intranet Portal', 'reatrium' ) ); ?>
	</form>
</div>

