<?php
/**
 * Admin View: ReAtrium Overview & Settings.
 *
 * @package ReAtrium
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'ReAtrium Intranet', 'reatrium' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Manage your SMB intranet modules, public/private isolation, and AIGIS governance integration.', 'reatrium' ); ?>
	</p>

	<p>
		<a class="button button-primary" href="<?php echo esc_url( ReAtrium_Portal::portal_url() ); ?>" target="_blank">
			<?php esc_html_e( 'Open Employee Portal', 'reatrium' ); ?>
		</a>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=reatrium-setup' ) ); ?>">
				<?php esc_html_e( 'Run Setup Wizard', 'reatrium' ); ?>
			</a>
			<?php if ( ReAtrium_AI_Gateway::is_aigis_active() ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=aigis-agents' ) ); ?>">
					<?php esc_html_e( 'Manage Agents in AIGIS', 'reatrium' ); ?>
				</a>
			<?php endif; ?>
		<?php endif; ?>
	</p>
</div>

