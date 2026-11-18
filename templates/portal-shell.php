<?php
/**
 * Standalone Hybrid Employee Portal Shell (/intranet/).
 *
 * @package ReAtrium
 *
 * @var array   $views
 * @var string  $current_view
 * @var string  $company_name
 * @var WP_User $current_user
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $company_name ); ?> — <?php esc_html_e( 'Intranet', 'reatrium' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( REATRIUM_PLUGIN_URL . 'assets/css/portal.css?ver=' . REATRIUM_VERSION ); ?>">
</head>
<body class="reatrium-body">
	<div class="reatrium-shell">
		<aside class="reatrium-sidebar">
			<div class="reatrium-brand">
				<span class="reatrium-brand-mark">R</span>
				<div>
					<strong><?php echo esc_html( $company_name ); ?></strong>
					<small><?php esc_html_e( 'ReAtrium Intranet', 'reatrium' ); ?></small>
				</div>
			</div>

			<nav class="reatrium-nav" aria-label="<?php esc_attr_e( 'Intranet Navigation', 'reatrium' ); ?>">
				<?php foreach ( $views as $slug => $item ) : ?>
					<?php
					$url    = $slug === 'home' ? home_url( '/intranet/' ) : home_url( '/intranet/' . $slug . '/' );
					$active = $current_view === $slug ? ' is-active' : '';
					?>
					<a class="reatrium-nav-link<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $url ); ?>">
						<span class="reatrium-nav-icon"><?php echo esc_html( $item['icon'] ); ?></span>
						<span><?php echo esc_html( $item['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="reatrium-sidebar-footer">
				<a class="reatrium-footer-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					← <?php esc_html_e( 'Public Website', 'reatrium' ); ?>
				</a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="reatrium-footer-link" href="<?php echo esc_url( admin_url( 'admin.php?page=reatrium-settings' ) ); ?>">
						⚙ <?php esc_html_e( 'WP Admin & Settings', 'reatrium' ); ?>
					</a>
				<?php endif; ?>
				<div class="reatrium-user-pill">
					<span><?php echo esc_html( $current_user->display_name ); ?></span>
					<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'reatrium' ); ?></a>
				</div>
			</div>
		</aside>

		<main class="reatrium-main">
			<header class="reatrium-topbar">
				<h1><?php echo esc_html( $views[ $current_view ]['label'] ); ?></h1>
				<div class="reatrium-status-badges">
					<span class="reatrium-badge <?php echo ReAtrium_AI_Gateway::is_ai_available() ? 'is-ok' : 'is-warn'; ?>">
						<?php echo ReAtrium_AI_Gateway::is_ai_available() ? esc_html__( 'WP 7+ AI Ready', 'reatrium' ) : esc_html__( 'AI Connector Needed', 'reatrium' ); ?>
					</span>
					<?php if ( ReAtrium_AI_Gateway::is_aigis_active() ) : ?>
						<span class="reatrium-badge is-ok"><?php esc_html_e( 'AIGIS Governed', 'reatrium' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<section class="reatrium-content">
				<?php
				$callback = $views[ $current_view ]['callback'] ?? null;
				if ( is_callable( $callback ) ) {
					call_user_func( $callback, $current_view );
				} else {
					?>
					<div class="reatrium-card">
						<h2><?php echo esc_html( sprintf( __( 'Welcome to %s Intranet', 'reatrium' ), $company_name ) ); ?></h2>
						<p><?php esc_html_e( 'Your hybrid employee portal is active and isolated from the public website. Day 3 modules (Handbook RAG, Announcements Digest, Directory, and Requests) will render here.', 'reatrium' ); ?></p>
					</div>
					<?php
				}
				?>
			</section>
		</main>
	</div>
</body>
</html>

