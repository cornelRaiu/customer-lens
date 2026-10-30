<?php

namespace CustomerLens\Rest;

use CustomerLens\Actions;
use CustomerLens\Admin\Panel;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Plugin;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The routes behind the order screen panel. Every route needs the plugin's capability and a REST nonce,
 * and answers with the panel's new HTML for the order it was called from.
 */
final class Routes {

	public const NAMESPACE = 'customer-lens/v1';

	public static function add_hooks(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$order = [
			'order' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		];

		self::route(
			'/order/(?P<order>\d+)/panel',
			WP_REST_Server::READABLE,
			'panel',
			[
				'status' => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
				'page'   => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				],
			]
		);
		self::route( '/order/(?P<order>\d+)/refused', WP_REST_Server::CREATABLE, 'mark_refused' );
		self::route( '/order/(?P<order>\d+)/refused', WP_REST_Server::DELETABLE, 'unmark_refused' );

		self::route(
			'/customer/(?P<customer>\d+)/flag',
			WP_REST_Server::CREATABLE,
			'flag',
			$order + [
				'status'  => [
					'type'     => 'integer',
					'required' => true,
					'enum'     => [ Customers::FLAG_WATCH, Customers::FLAG_TRUSTED ],
				],
				'reason'  => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				],
				'expires' => [
					'type'    => 'integer',
					'default' => 0,
					'enum'    => Actions::FLAG_EXPIRY_DAYS,
				],
			]
		);
		self::route( '/customer/(?P<customer>\d+)/flag', WP_REST_Server::DELETABLE, 'unflag', $order );

		self::route(
			'/customer/(?P<customer>\d+)/notes',
			WP_REST_Server::CREATABLE,
			'add_note',
			$order + [
				'text' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				],
			]
		);
		self::route( '/notes/(?P<event>\d+)', WP_REST_Server::DELETABLE, 'delete_note', $order );

		$other = $order + [
			'other' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		];
		self::route( '/customer/(?P<customer>\d+)/merge', WP_REST_Server::CREATABLE, 'merge', $other );
		self::route( '/customer/(?P<customer>\d+)/dismiss', WP_REST_Server::CREATABLE, 'dismiss', $other );
		self::route( '/merges/(?P<event>\d+)/undo', WP_REST_Server::CREATABLE, 'unmerge', $order );
	}

	public static function can_manage(): bool {
		return current_user_can( Plugin::capability() );
	}

	public static function panel( WP_REST_Request $request ) {
		$order = self::order( $request );

		return $order instanceof WC_Order ? self::html( $order, (string) $request['status'], (int) $request['page'] ) : $order;
	}

	public static function mark_refused( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order && ! Actions::mark_refused( $order, get_current_user_id() ) ) {
			return self::error( __( 'This order has no customer to count it for yet.', 'customer-lens' ) );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function unmark_refused( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order ) {
			Actions::unmark_refused( $order );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function flag( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order && ! Actions::flag( (int) $request['customer'], (int) $request['status'], (string) $request['reason'], (int) $request['expires'], get_current_user_id() ) ) {
			return self::error( __( 'That customer does not exist.', 'customer-lens' ) );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function unflag( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order ) {
			Actions::unflag( (int) $request['customer'], get_current_user_id() );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function add_note( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order && ! Actions::add_note( (int) $request['customer'], (string) $request['text'], get_current_user_id(), $order->get_id() ) ) {
			return self::error( __( 'Write the note first.', 'customer-lens' ) );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function delete_note( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order && ! Actions::delete_note( (int) $request['event'], get_current_user_id() ) ) {
			return self::error( __( 'Only the author of a note or an administrator can delete it.', 'customer-lens' ), 403 );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function merge( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order && ! Actions::merge( (int) $request['customer'], (int) $request['other'], get_current_user_id() ) ) {
			return self::error( __( 'Those customers cannot be merged.', 'customer-lens' ) );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function dismiss( WP_REST_Request $request ) {
		$order = self::order( $request );
		if ( $order instanceof WC_Order ) {
			Actions::dismiss_proposal( (int) $request['customer'], (int) $request['other'] );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	public static function unmerge( WP_REST_Request $request ) {
		$order = self::order( $request );
		$event = Events::get( (int) $request['event'] );
		if ( $order instanceof WC_Order && ( ! $event || ! Actions::unmerge( (int) $event->id, get_current_user_id() ) ) ) {
			return self::error( __( 'That merge can no longer be undone.', 'customer-lens' ) );
		}

		return $order instanceof WC_Order ? self::html( $order ) : $order;
	}

	/** @param array<string, array<string, mixed>> $args */
	private static function route( string $path, string $methods, string $callback, array $args = [] ): void {
		register_rest_route(
			self::NAMESPACE,
			$path,
			[
				'methods'             => $methods,
				'callback'            => [ self::class, $callback ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => $args,
			]
		);
	}

	/** @return WC_Order|WP_Error */
	private static function order( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request['order'] );

		return $order instanceof WC_Order && 'shop_order' === $order->get_type() ? $order : self::error( __( 'That order does not exist.', 'customer-lens' ), 404 );
	}

	private static function html( WC_Order $order, string $status = '', int $page = 1 ): WP_REST_Response {
		return new WP_REST_Response( [ 'html' => Panel::html( $order, $status, $page ) ] );
	}

	private static function error( string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'customer_lens_failed', $message, [ 'status' => $status ] );
	}
}
