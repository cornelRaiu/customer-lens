<?php

namespace CustomerLens\Admin;

use CustomerLens\Import\Importer;
use CustomerLens\Plugin;
use CustomerLens\Privacy\Privacy;
use CustomerLens\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Customer Lens > Settings: badge thresholds and retention, the order index, and privacy.
 */
final class SettingsPage {

	public const SLUG           = 'customer-lens';
	public const REINDEX_ACTION = 'mwtcl_reindex';

	/** The policy text is offered in these languages, besides the site's own, each named in its own language. */
	public const POLICY_LOCALES = [
		'en_US' => 'English',
		'ro_RO' => 'Română',
	];

	public static function add_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'register' ] );
		add_action( 'admin_post_' . self::REINDEX_ACTION, [ self::class, 'reindex' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( Plugin::file() ), [ self::class, 'action_links' ] );
	}

	public static function menu(): void {
		add_menu_page( __( 'Customer Lens', 'customer-lens' ), __( 'Customer Lens', 'customer-lens' ), Plugin::capability(), self::SLUG, [ self::class, 'render' ], self::menu_icon(), 56 );
		add_submenu_page( self::SLUG, __( 'Customer Lens settings', 'customer-lens' ), __( 'Settings', 'customer-lens' ), Plugin::capability(), self::SLUG, [ self::class, 'render' ] );
	}

	public static function register(): void {
		register_setting(
			Settings::OPTION,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ Settings::class, 'save' ],
				'default'           => Settings::defaults(),
			]
		);

		// The settings page saves through options.php, which checks this capability.
		add_filter( 'option_page_capability_' . Settings::OPTION, [ Plugin::class, 'capability' ] );
	}

	public static function enqueue( $hook ): void {
		if ( 'toplevel_page_' . self::SLUG === $hook ) {
			wp_enqueue_style( OrderPanel::HANDLE, Plugin::url( 'assets/css/admin.css' ), [], Plugin::VERSION );
			wp_enqueue_script( 'customer-lens-settings', Plugin::url( 'assets/js/settings.js' ), [], Plugin::VERSION, true );
		}
	}

	/** @param string[] $links */
	public static function action_links( $links ): array {
		$links = (array) $links;
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Settings', 'customer-lens' ) ) );

		return $links;
	}

	public static function url( string $tab = '' ): string {
		return add_query_arg( array_filter( [ 'tab' => $tab ] ), admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function reindex(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'customer-lens' ), 403 );
		}
		check_admin_referer( self::REINDEX_ACTION );

		Importer::start();

		wp_safe_redirect( add_query_arg( 'reindexing', '1', self::url() ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the tab to show.
		$tab      = isset( $_GET['tab'] ) && 'privacy' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'privacy' : 'general';
		$settings = Settings::all();
		$name     = Settings::OPTION;
		?>
		<div class="wrap mwtcl-settings">
			<h1><?php esc_html_e( 'Customer Lens settings', 'customer-lens' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'customer-lens' ); ?>">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'general' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'customer-lens' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'privacy' ) ); ?>" class="nav-tab <?php echo 'privacy' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Privacy', 'customer-lens' ); ?></a>
			</nav>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::OPTION ); ?>

				<?php if ( 'general' === $tab ) : ?>
					<h2><?php esc_html_e( 'Badges', 'customer-lens' ); ?></h2>
					<p><?php esc_html_e( 'Every signal is a count. A count turns into a yellow warning, or a red critical badge, from the number you set here. 0 keeps that badge grey.', 'customer-lens' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::number_row( __( 'Refunds', 'customer-lens' ), [ 'refunds_warning' => __( 'warning from', 'customer-lens' ) ], $settings );
						self::number_row(
							__( 'Refused deliveries', 'customer-lens' ),
							[
								'refused_warning'  => __( 'warning from', 'customer-lens' ),
								'refused_critical' => __( 'critical from', 'customer-lens' ),
							],
							$settings
						);
						self::number_row( __( 'Cancelled orders', 'customer-lens' ), [ 'cancels_warning' => __( 'warning from', 'customer-lens' ) ], $settings );
						self::number_row( __( 'Failed payments', 'customer-lens' ), [ 'failed_warning' => __( 'warning from', 'customer-lens' ) ], $settings );
						self::number_row( __( 'Chargebacks', 'customer-lens' ), [ 'chargebacks_critical' => __( 'critical from', 'customer-lens' ) ], $settings );
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'First orders', 'customer-lens' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[first_order_warning]" value="0">
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[first_order_warning]" value="1" <?php checked( $settings['first_order_warning'] ); ?>> <?php esc_html_e( 'Show "First order" as a warning', 'customer-lens' ); ?></label>
								<p class="description"><?php esc_html_e( 'Useful when first-time cash-on-delivery orders are the ones that get refused.', 'customer-lens' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'History', 'customer-lens' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mwtcl-retention"><?php esc_html_e( 'Keep events for', 'customer-lens' ); ?></label></th>
							<td>
								<input type="number" id="mwtcl-retention" class="small-text" name="<?php echo esc_attr( $name ); ?>[retention_months]" min="<?php echo esc_attr( (string) Settings::RETENTION_MIN ); ?>" max="<?php echo esc_attr( (string) Settings::RETENTION_MAX ); ?>" value="<?php echo esc_attr( (string) $settings['retention_months'] ); ?>">
								<?php esc_html_e( 'months', 'customer-lens' ); ?>
								<p class="description"><?php esc_html_e( 'Refunds, refused deliveries and other events older than this are deleted every night, and stop counting. Notes stay until the customer asks for erasure.', 'customer-lens' ); ?></p>
							</td>
						</tr>
					</table>
				<?php else : ?>
					<h2><?php esc_html_e( 'Privacy policy', 'customer-lens' ); ?></h2>
					<p><?php esc_html_e( 'Customer Lens processes data your store already holds, for fulfilment and fraud prevention. Add a paragraph like this one to your privacy policy. It is also suggested under Settings > Privacy > Policy Guide.', 'customer-lens' ); ?></p>
					<?php foreach ( self::policy_texts() as $locale => $text ) : ?>
						<div class="mwtcl-policy">
							<label for="mwtcl-policy-<?php echo esc_attr( $locale ); ?>"><strong><?php echo esc_html( self::POLICY_LOCALES[ $locale ] ?? $locale ); ?></strong></label>
							<textarea id="mwtcl-policy-<?php echo esc_attr( $locale ); ?>" class="large-text" rows="8" readonly><?php echo esc_textarea( $text ); ?></textarea>
							<button type="button" class="button" data-mwtcl-copy="mwtcl-policy-<?php echo esc_attr( $locale ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'customer-lens' ); ?>"><?php esc_html_e( 'Copy', 'customer-lens' ); ?></button>
						</div>
					<?php endforeach; ?>

					<h2><?php esc_html_e( 'Uninstall', 'customer-lens' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Data', 'customer-lens' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="0">
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="1" <?php checked( $settings['delete_data'] ); ?>> <?php esc_html_e( 'Remove all Customer Lens data when the plugin is deleted', 'customer-lens' ); ?></label>
								<p class="description"><?php esc_html_e( 'Off by default, because flags, notes and refused deliveries cannot be rebuilt from your orders.', 'customer-lens' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<?php if ( 'general' === $tab ) : ?>
				<?php self::render_index(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_index(): void {
		$state = Importer::state();
		?>
		<h2><?php esc_html_e( 'Order index', 'customer-lens' ); ?></h2>
		<p>
			<?php
			if ( Importer::RUNNING === $state['status'] ) {
				/* translators: 1: orders indexed so far, 2: all orders. */
				echo esc_html( sprintf( __( 'Indexing past orders: %1$s of %2$s.', 'customer-lens' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ) );
			} elseif ( Importer::DONE === $state['status'] && 0 === (int) $state['total'] ) {
				esc_html_e( 'There were no past orders to index. New orders are indexed as they come in.', 'customer-lens' );
			} elseif ( Importer::DONE === $state['status'] ) {
				/* translators: 1: number of orders, 2: date. */
				echo esc_html( sprintf( __( 'All %1$s past orders were indexed on %2$s. New orders are indexed as they come in.', 'customer-lens' ), number_format_i18n( (int) $state['total'] ), wp_date( (string) get_option( 'date_format' ), (int) $state['finished'] ) ) );
			} else {
				esc_html_e( 'Past orders have not been indexed yet.', 'customer-lens' );
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::REINDEX_ACTION ); ?>">
			<?php wp_nonce_field( self::REINDEX_ACTION ); ?>
			<p class="description"><?php esc_html_e( 'Indexing again goes through every order, newest first, in the background. Flags, notes and merges are kept.', 'customer-lens' ); ?></p>
			<?php submit_button( __( 'Index all orders again', 'customer-lens' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * @param array<string, string> $fields Setting => label before its input.
	 * @param array<string, mixed>  $settings
	 */
	private static function number_row( string $title, array $fields, array $settings ): void {
		$first = 'mwtcl-' . str_replace( '_', '-', (string) array_key_first( $fields ) );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $first ); ?>"><?php echo esc_html( $title ); ?></label></th>
			<td>
				<?php foreach ( $fields as $key => $label ) : ?>
					<?php $id = 'mwtcl-' . str_replace( '_', '-', $key ); ?>
					<span class="mwtcl-threshold">
						<?php if ( $id !== $first ) : ?>
							<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
						<?php else : ?>
							<?php echo esc_html( $label ); ?>
						<?php endif; ?>
						<input type="number" id="<?php echo esc_attr( $id ); ?>" class="small-text" min="0" max="999" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>">
					</span>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The policy text in the site's language and in POLICY_LOCALES. Loads the plugin's own translations
	 * directly: switch_to_locale() needs WordPress itself installed in that language.
	 *
	 * @return array<string, string> Locale => policy text in that language.
	 */
	private static function policy_texts(): array {
		$texts = [ determine_locale() => Privacy::policy_text() ];

		foreach ( array_keys( self::POLICY_LOCALES ) as $locale ) {
			if ( isset( $texts[ $locale ] ) ) {
				continue;
			}

			unload_textdomain( 'customer-lens', true );
			$file = dirname( Plugin::file() ) . '/languages/customer-lens-' . $locale . '.mo';
			if ( 'en_US' === $locale || load_textdomain( 'customer-lens', $file, $locale ) ) {
				$texts[ $locale ] = Privacy::policy_text();
			}
		}

		unload_textdomain( 'customer-lens', true );
		Plugin::load_textdomain();

		return array_unique( $texts );
	}

	/** A lens over a person, for the admin menu. WordPress colours it like the other menu icons. */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M8.5 2a6.5 6.5 0 0 1 5.2 10.4l4 4a1 1 0 0 1-1.4 1.4l-4-4A6.5 6.5 0 1 1 8.5 2Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Zm0 1.6a1.6 1.6 0 1 1 0 3.2 1.6 1.6 0 0 1 0-3.2Zm0 3.8c1.5 0 2.7.7 2.9 1.7a4.5 4.5 0 0 1-5.8 0c.2-1 1.4-1.7 2.9-1.7Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- A data URI for the menu icon, as WordPress expects.
	}
}
