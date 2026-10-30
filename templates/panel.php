<?php
/**
 * The customer panel on the order screen. Included by Panel::html() with $view.
 *
 * @package CustomerLens
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside Panel::html(), not in the global scope.

use CustomerLens\Admin\Panel;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Identities;
use CustomerLens\Events\Events;

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $view */
$panel_order = $view['order'];

if ( ! $view['customer_id'] ) :
	?>
	<p class="mwtcl-empty"><?php esc_html_e( 'The customer\'s history appears once the order has an email address, phone number or delivery address.', 'customer-lens' ); ?></p>
	<?php
	return;
endif;

$customer = $view['customer'];
$flagged  = $customer && Customers::FLAG_NONE !== (int) $customer->flag_status;
?>
<div class="mwtcl-head" data-mwtcl-customer="<?php echo esc_attr( (string) $view['customer_id'] ); ?>">
	<span class="mwtcl-who">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: customer name, 2: customer number. */
				__( '%1$s · customer #%2$s', 'customer-lens' ),
				$panel_order->get_formatted_billing_full_name() ? $panel_order->get_formatted_billing_full_name() : __( 'Guest', 'customer-lens' ),
				number_format_i18n( $view['customer_id'] )
			)
		);
		?>
	</span>
	<span class="mwtcl-actions">
		<button type="button" class="button" data-mwtcl-open="flag"><?php echo $flagged ? esc_html__( 'Edit flag', 'customer-lens' ) : esc_html__( 'Flag', 'customer-lens' ); ?></button>
		<?php if ( $view['refused'] ) : ?>
			<button type="button" class="button" data-mwtcl-action="unrefused"><?php esc_html_e( 'Undo refused delivery', 'customer-lens' ); ?></button>
		<?php else : ?>
			<button type="button" class="button" data-mwtcl-action="refused"><?php esc_html_e( 'Mark refused delivery', 'customer-lens' ); ?></button>
		<?php endif; ?>
		<button type="button" class="button" data-mwtcl-focus="note"><?php esc_html_e( 'Add note', 'customer-lens' ); ?></button>
	</span>
</div>

<?php if ( $view['importing'] ) : ?>
	<p class="mwtcl-line mwtcl-line--info">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: orders indexed so far, 2: all orders. */
				__( 'Indexing past orders: %1$s of %2$s. This history may still be incomplete.', 'customer-lens' ),
				number_format_i18n( (int) $view['importing']['done'] ),
				number_format_i18n( (int) $view['importing']['total'] )
			)
		);
		?>
	</p>
<?php endif; ?>

<div class="mwtcl-strip" role="list" aria-label="<?php esc_attr_e( 'Signals', 'customer-lens' ); ?>">
	<?php foreach ( $view['badges'] as $badge ) : ?>
		<span role="listitem" class="mwtcl-badge mwtcl-badge--<?php echo esc_attr( $badge['level'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span>
	<?php endforeach; ?>
</div>

<?php if ( $flagged ) : ?>
	<p class="mwtcl-line">
		<?php
		$flag_user = $view['users'][ (int) $customer->flagged_by ] ?? '';
		$flag_text = Customers::FLAG_TRUSTED === (int) $customer->flag_status
			/* translators: 1: user name, 2: date. */
			? sprintf( __( 'Marked as trusted by %1$s on %2$s.', 'customer-lens' ), $flag_user, Panel::date( $customer->flagged_at ) )
			/* translators: 1: user name, 2: date. */
			: sprintf( __( 'Flagged (watch) by %1$s on %2$s.', 'customer-lens' ), $flag_user, Panel::date( $customer->flagged_at ) );
		if ( $customer->flag_reason ) {
			$flag_text .= ' ' . $customer->flag_reason;
		}
		if ( $customer->flag_expires_at ) {
			/* translators: %s: date. */
			$flag_text .= ' ' . sprintf( __( 'Expires %s.', 'customer-lens' ), Panel::date( $customer->flag_expires_at ) );
		}
		echo esc_html( $flag_text );
		?>
	</p>
<?php endif; ?>

<?php foreach ( $view['proposals'] as $proposal ) : ?>
	<?php $other = (int) $proposal->payload['other']; ?>
	<p class="mwtcl-line">
		<span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: customer number, 2: what they have in common, such as "same phone". */
					__( 'May be the same person as customer #%1$s (%2$s).', 'customer-lens' ),
					number_format_i18n( $other ),
					Panel::via( (array) ( $proposal->payload['via'] ?? [] ) )
				)
			);
			?>
		</span>
		<button type="button" class="button button-small" data-mwtcl-action="merge" data-other="<?php echo esc_attr( (string) $other ); ?>"><?php esc_html_e( 'Merge', 'customer-lens' ); ?></button>
		<button type="button" class="button-link" data-mwtcl-action="dismiss" data-other="<?php echo esc_attr( (string) $other ); ?>"><?php esc_html_e( 'Dismiss', 'customer-lens' ); ?></button>
	</p>
<?php endforeach; ?>

<?php foreach ( $view['merges'] as $merge ) : ?>
	<p class="mwtcl-line">
		<span>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: customer number, 2: date. */
					__( 'Customer #%1$s was merged into this one on %2$s.', 'customer-lens' ),
					number_format_i18n( (int) $merge->payload['from'] ),
					Panel::date( $merge->created_at )
				)
			);
			?>
		</span>
		<button type="button" class="button button-small" data-mwtcl-action="unmerge" data-event="<?php echo esc_attr( (string) $merge->id ); ?>"><?php esc_html_e( 'Undo merge', 'customer-lens' ); ?></button>
	</p>
<?php endforeach; ?>

<div class="mwtcl-body">
	<div class="mwtcl-orders">
		<div class="mwtcl-orders-head">
			<strong>
				<?php
				/* translators: %s: number of orders. */
				echo esc_html( sprintf( __( 'Previous orders (%s)', 'customer-lens' ), number_format_i18n( $view['total'] ) ) );
				?>
			</strong>
			<?php if ( $view['total'] ) : ?>
				<span class="mwtcl-filters">
					<a href="#" data-mwtcl-status="" <?php echo '' === $view['status'] ? 'class="current" aria-current="true"' : ''; ?>>
						<?php
						/* translators: %s: number of orders. */
						echo esc_html( sprintf( __( 'All (%s)', 'customer-lens' ), number_format_i18n( $view['total'] ) ) );
						?>
					</a>
					<?php foreach ( $view['counts'] as $status_slug => $count ) : ?>
						· <a href="#" data-mwtcl-status="<?php echo esc_attr( $status_slug ); ?>" <?php echo $view['status'] === $status_slug ? 'class="current" aria-current="true"' : ''; ?>><?php echo esc_html( wc_get_order_status_name( $status_slug ) . ' (' . number_format_i18n( $count ) . ')' ); ?></a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( ! $view['rows'] ) : ?>
			<p class="mwtcl-empty"><?php esc_html_e( 'This is the customer\'s first order with you.', 'customer-lens' ); ?></p>
		<?php else : ?>
			<div class="mwtcl-table-wrap">
				<table class="widefat striped mwtcl-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Order', 'customer-lens' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'customer-lens' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'customer-lens' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total', 'customer-lens' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Delivery', 'customer-lens' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Matched by', 'customer-lens' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $view['rows'] as $row ) : ?>
							<?php
							$row_id    = (int) $row->order_id;
							$row_order = $view['orders'][ $row_id ] ?? null;
							$delivery  = $row_order ? $row_order->get_shipping_method() : '';
							if ( in_array( Events::REFUSED_DELIVERY, $view['row_events'][ $row_id ] ?? [], true ) ) {
								$delivery = trim( $delivery . ' · ' . __( 'refused', 'customer-lens' ), ' ·' );
							}
							?>
							<tr>
								<td>
									<?php if ( $row_order ) : ?>
										<a href="<?php echo esc_url( $row_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $row_order->get_order_number() ); ?></a>
									<?php else : ?>
										#<?php echo esc_html( (string) $row_id ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Panel::date( $row->created_at ) ); ?></td>
								<td><mark class="order-status status-<?php echo esc_attr( $row->status ); ?>"><span><?php echo esc_html( wc_get_order_status_name( $row->status ) ); ?></span></mark></td>
								<td><?php echo wp_kses_post( wc_price( (float) $row->total, [ 'currency' => $row->currency ] ) ); ?></td>
								<td><?php echo esc_html( '' === $delivery ? '—' : $delivery ); ?></td>
								<td><?php echo esc_html( implode( ', ', array_map( [ Panel::class, 'kind_label' ], $view['kinds'][ $row_id ] ?? [] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="mwtcl-pages">
				<span>
					<?php
					$first = ( $view['page'] - 1 ) * Panel::PER_PAGE + 1;
					/* translators: 1: first order shown, 2: last order shown, 3: number of orders. */
					echo esc_html( sprintf( __( 'Showing %1$s–%2$s of %3$s', 'customer-lens' ), number_format_i18n( $first ), number_format_i18n( $first + count( $view['rows'] ) - 1 ), number_format_i18n( $view['filtered'] ) ) );
					?>
				</span>
				<?php if ( $view['pages'] > 1 ) : ?>
					<span>
						<button type="button" class="button button-small" data-mwtcl-page="<?php echo esc_attr( (string) ( $view['page'] - 1 ) ); ?>" <?php disabled( $view['page'] <= 1 ); ?> aria-label="<?php esc_attr_e( 'Previous page', 'customer-lens' ); ?>">‹</button>
						<?php
						/* translators: 1: page number, 2: number of pages. */
						echo esc_html( sprintf( __( 'Page %1$s of %2$s', 'customer-lens' ), number_format_i18n( $view['page'] ), number_format_i18n( $view['pages'] ) ) );
						?>
						<button type="button" class="button button-small" data-mwtcl-page="<?php echo esc_attr( (string) ( $view['page'] + 1 ) ); ?>" <?php disabled( $view['page'] >= $view['pages'] ); ?> aria-label="<?php esc_attr_e( 'Next page', 'customer-lens' ); ?>">›</button>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="mwtcl-side">
		<div>
			<strong><?php esc_html_e( 'Identities', 'customer-lens' ); ?></strong>
			<ul class="mwtcl-list">
				<?php foreach ( $view['identities'] as $identity ) : ?>
					<li><?php echo esc_html( Identities::display( $identity->kind, $identity->value_norm ) ); ?> <span class="mwtcl-muted">· <?php echo esc_html( Panel::kind_label( $identity->kind ) . ' · ' . (int) $identity->confidence ); ?></span></li>
				<?php endforeach; ?>
				<?php foreach ( $view['shared'] as $identity ) : ?>
					<li><?php echo esc_html( Identities::display( $identity->kind, $identity->value_norm ) ); ?> <span class="mwtcl-badge mwtcl-badge--neutral"><?php esc_html_e( 'shared identifier', 'customer-lens' ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<div>
			<strong><?php esc_html_e( 'Notes and activity', 'customer-lens' ); ?></strong>
			<form class="mwtcl-note-form" data-mwtcl-form="note">
				<label class="screen-reader-text" for="mwtcl-note-<?php echo esc_attr( (string) $panel_order->get_id() ); ?>"><?php esc_html_e( 'Note about this customer', 'customer-lens' ); ?></label>
				<textarea id="mwtcl-note-<?php echo esc_attr( (string) $panel_order->get_id() ); ?>" name="text" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'A note for everyone who handles this customer…', 'customer-lens' ); ?>"></textarea>
				<button type="submit" class="button button-small"><?php esc_html_e( 'Add note', 'customer-lens' ); ?></button>
			</form>
			<ul class="mwtcl-list mwtcl-timeline">
				<?php foreach ( $view['timeline'] as $event ) : ?>
					<li>
						<span class="mwtcl-when">
							<?php
							$author = $event->actor_user_id ? ( $view['users'][ (int) $event->actor_user_id ] ?? '' ) : __( 'Customer Lens', 'customer-lens' );
							echo esc_html( $author . ' · ' . Panel::date( $event->created_at ) );
							?>
						</span>
						<?php echo esc_html( Panel::describe( $event ) ); ?>
						<?php if ( Events::NOTE === $event->type && ( get_current_user_id() === (int) $event->actor_user_id || current_user_can( 'manage_options' ) ) ) : ?>
							<button type="button" class="button-link mwtcl-delete" data-mwtcl-action="delete-note" data-event="<?php echo esc_attr( (string) $event->id ); ?>"><?php esc_html_e( 'Delete', 'customer-lens' ); ?></button>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<p class="mwtcl-muted mwtcl-small">
			<?php
			/* translators: %s: number of months. */
			echo esc_html( sprintf( __( 'Events kept %s months.', 'customer-lens' ), number_format_i18n( $view['retention'] ) ) );
			?>
		</p>
	</div>
</div>

<dialog class="mwtcl-dialog" data-mwtcl-dialog="flag" aria-labelledby="mwtcl-flag-title-<?php echo esc_attr( (string) $panel_order->get_id() ); ?>">
	<form method="dialog" data-mwtcl-form="flag">
		<h2 id="mwtcl-flag-title-<?php echo esc_attr( (string) $panel_order->get_id() ); ?>">
			<?php
			/* translators: %s: customer name. */
			echo esc_html( sprintf( __( 'Flag %s', 'customer-lens' ), $panel_order->get_formatted_billing_full_name() ? $panel_order->get_formatted_billing_full_name() : __( 'this customer', 'customer-lens' ) ) );
			?>
		</h2>
		<fieldset>
			<legend><?php esc_html_e( 'What should staff know on the next order?', 'customer-lens' ); ?></legend>
			<label class="mwtcl-option">
				<input type="radio" name="status" value="<?php echo esc_attr( (string) Customers::FLAG_WATCH ); ?>" <?php checked( ! $flagged || Customers::FLAG_WATCH === (int) $customer->flag_status ); ?>>
				<span><strong><?php esc_html_e( 'Watch', 'customer-lens' ); ?></strong><span><?php esc_html_e( 'Staff see a warning on every order from this customer. Checkout does not change.', 'customer-lens' ); ?></span></span>
			</label>
			<label class="mwtcl-option">
				<input type="radio" name="status" value="<?php echo esc_attr( (string) Customers::FLAG_TRUSTED ); ?>" <?php checked( $flagged && Customers::FLAG_TRUSTED === (int) $customer->flag_status ); ?>>
				<span><strong><?php esc_html_e( 'Trusted', 'customer-lens' ); ?></strong><span><?php esc_html_e( 'A known good customer: shown in green, whatever the counts say.', 'customer-lens' ); ?></span></span>
			</label>
		</fieldset>
		<div class="mwtcl-fields">
			<label>
				<span><?php esc_html_e( 'Reason (staff only)', 'customer-lens' ); ?></span>
				<textarea name="reason" rows="2" maxlength="190"><?php echo esc_textarea( $flagged ? (string) $customer->flag_reason : '' ); ?></textarea>
			</label>
			<label>
				<span><?php esc_html_e( 'Expires', 'customer-lens' ); ?></span>
				<select name="expires">
					<?php foreach ( [ 90, 30, 365, 0 ] as $days ) : ?>
						<option value="<?php echo esc_attr( (string) $days ); ?>">
							<?php
							/* translators: %s: number of days. */
							echo esc_html( 0 === $days ? __( 'Never', 'customer-lens' ) : sprintf( _n( 'In %s day', 'In %s days', $days, 'customer-lens' ), number_format_i18n( $days ) ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>
		<p class="mwtcl-muted"><?php esc_html_e( 'The customer is never told about a flag.', 'customer-lens' ); ?></p>
		<div class="mwtcl-dialog-buttons">
			<?php if ( $flagged ) : ?>
				<button type="button" class="button-link mwtcl-delete" data-mwtcl-action="unflag"><?php esc_html_e( 'Remove flag', 'customer-lens' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button" data-mwtcl-close><?php esc_html_e( 'Cancel', 'customer-lens' ); ?></button>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save flag', 'customer-lens' ); ?></button>
		</div>
	</form>
</dialog>
