/**
 * The Customer Lens panel on the order screen. The server renders the panel; this file asks it for a new
 * one after every filter, page change or action, and swaps it in. No build step.
 */
( function () {
	'use strict';

	const settings = window.mwtclPanel || { i18n: {} };
	const apiFetch = window.wp && window.wp.apiFetch;
	const base = '/customer-lens/v1';

	function init( root ) {
		const orderId = parseInt( root.dataset.mwtclOrder, 10 );
		let status = '';

		function customerId() {
			const flag = root.querySelector( '[data-mwtcl-customer]' );
			return flag ? flag.dataset.mwtclCustomer : '';
		}

		function swap( response ) {
			root.innerHTML = response.html;
		}

		function request( path, method, data ) {
			root.classList.add( 'is-busy' );
			return apiFetch( { path: base + path, method: method || 'GET', data } )
				.then( swap )
				.catch( ( error ) => {
					window.alert( ( error && error.message ) || settings.i18n.error );
				} )
				.finally( () => root.classList.remove( 'is-busy' ) );
		}

		function panel( page ) {
			const query = new URLSearchParams( { status, page: String( page || 1 ) } );
			return request( '/order/' + orderId + '/panel?' + query.toString() );
		}

		function confirmed( key ) {
			return ! settings.i18n[ key ] || window.confirm( settings.i18n[ key ] );
		}

		const actions = {
			refused: () => confirmed( 'refused' ) && request( '/order/' + orderId + '/refused', 'POST' ),
			unrefused: () => confirmed( 'unrefused' ) && request( '/order/' + orderId + '/refused', 'DELETE' ),
			merge: ( button ) =>
				confirmed( 'merge' ) &&
				request( '/customer/' + customerId() + '/merge', 'POST', { order: orderId, other: parseInt( button.dataset.other, 10 ) } ),
			dismiss: ( button ) =>
				request( '/customer/' + customerId() + '/dismiss', 'POST', { order: orderId, other: parseInt( button.dataset.other, 10 ) } ),
			unmerge: ( button ) =>
				confirmed( 'unmerge' ) && request( '/merges/' + button.dataset.event + '/undo', 'POST', { order: orderId } ),
			'delete-note': ( button ) =>
				confirmed( 'deleteNote' ) && request( '/notes/' + button.dataset.event + '?order=' + orderId, 'DELETE' ),
			unflag: () => {
				const dialog = root.querySelector( '[data-mwtcl-dialog="flag"]' );
				if ( ! confirmed( 'unflag' ) ) {
					return;
				}
				dialog.close();
				request( '/customer/' + customerId() + '/flag?order=' + orderId, 'DELETE' );
			},
		};

		root.addEventListener( 'click', ( event ) => {
			const target = event.target.closest( 'a, button' );
			if ( ! target || ! root.contains( target ) ) {
				return;
			}

			if ( 'mwtclStatus' in target.dataset ) {
				event.preventDefault();
				status = target.dataset.mwtclStatus;
				panel( 1 );
			} else if ( 'mwtclPage' in target.dataset ) {
				panel( parseInt( target.dataset.mwtclPage, 10 ) );
			} else if ( 'mwtclAction' in target.dataset ) {
				event.preventDefault();
				actions[ target.dataset.mwtclAction ]( target );
			} else if ( 'mwtclOpen' in target.dataset ) {
				root.querySelector( '[data-mwtcl-dialog="' + target.dataset.mwtclOpen + '"]' ).showModal();
			} else if ( 'mwtclClose' in target.dataset ) {
				target.closest( 'dialog' ).close();
			} else if ( 'mwtclFocus' in target.dataset ) {
				root.querySelector( '[data-mwtcl-form="' + target.dataset.mwtclFocus + '"] textarea' ).focus();
			}
		} );

		root.addEventListener( 'submit', ( event ) => {
			const form = event.target;
			const fields = new FormData( form );
			event.preventDefault();

			if ( 'note' === form.dataset.mwtclForm ) {
				const text = String( fields.get( 'text' ) || '' ).trim();
				if ( text ) {
					request( '/customer/' + customerId() + '/notes', 'POST', { order: orderId, text } );
				}
			} else if ( 'flag' === form.dataset.mwtclForm ) {
				form.closest( 'dialog' ).close();
				request( '/customer/' + customerId() + '/flag', 'POST', {
					order: orderId,
					status: parseInt( fields.get( 'status' ), 10 ),
					reason: String( fields.get( 'reason' ) || '' ),
					expires: parseInt( fields.get( 'expires' ), 10 ),
				} );
			}
		} );
	}

	function ready() {
		const box = document.getElementById( 'customer-lens-panel' );
		const details = document.getElementById( 'woocommerce-order-data' );
		if ( box && details && settings.moveUp ) {
			details.after( box );
		}

		document.querySelectorAll( '.mwtcl[data-mwtcl-order]' ).forEach( init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
