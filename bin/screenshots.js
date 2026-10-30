#!/usr/bin/env node
/**
 * Takes the screenshots for the WordPress.org listing, into .wordpress-org/.
 *
 * Usage: WP_BASE_URL=https://customer-lens-demo.test WP_CLI="wp --path=/path/to/demo" npm run screenshots
 *
 * Run it against a demo store filled by bin/demo-data.php, never a real one: the screenshots show
 * customers. It logs in as the demo shop manager with a one-time password, which it replaces with a
 * random one at the end. The captions are in readme.txt, in the same order.
 */
const crypto = require( 'node:crypto' );
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const { chromium } = require( '@playwright/test' );

const base = ( process.env.WP_BASE_URL || 'https://customer-lens-demo.test' ).replace( /\/$/, '' );
const out = path.join( __dirname, '..', '.wordpress-org' );
const demo = { username: 'ana', password: crypto.randomBytes( 16 ).toString( 'hex' ) };

function wpEval( php ) {
	const [ bin, ...args ] = ( process.env.WP_CLI || 'wp' ).split( ' ' );

	return execFileSync( bin, [ ...args, 'eval', php ], {
		encoding: 'utf8',
		env: { ...process.env, XDEBUG_MODE: 'off' },
	} ).trim();
}

function setUp() {
	return JSON.parse(
		wpEval( `
			$user = get_user_by( 'login', '${ demo.username }' );
			if ( ! $user || ! get_option( 'customer_lens_demo_order' ) ) {
				WP_CLI::error( 'Fill the demo store first: CUSTOMER_LENS_DEMO=1 wp eval-file bin/demo-data.php' );
			}
			wp_set_password( '${ demo.password }', $user->ID );
			update_option( CustomerLens\\Settings::OPTION, CustomerLens\\Settings::defaults() );
			echo wp_json_encode( [ 'order' => (int) get_option( 'customer_lens_demo_order' ) ] );
		` )
	);
}

function tearDown() {
	wpEval( `wp_set_password( wp_generate_password( 32 ), get_user_by( 'login', '${ demo.username }' )->ID );` );
}

const width = 1440;

// With crop, the picture is the element and its margin only; otherwise the full width from the element down.
async function shoot( page, file, { selector, height = 800, margin = 24, crop = false } = {} ) {
	let box = { x: 0, y: 0, width, height };
	if ( selector ) {
		const rect = await page
			.locator( selector )
			.first()
			.evaluate( ( element ) => {
				const r = element.getBoundingClientRect();
				return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
			} );
		box = crop
			? { x: Math.max( 0, rect.x - margin ), y: Math.max( 0, rect.y - margin ), width: rect.width + 2 * margin, height: Math.min( height, rect.height + 2 * margin ) }
			: { x: 0, y: Math.max( 0, rect.y - margin ), width, height };
	}
	const pageHeight = await page.evaluate( () => document.documentElement.scrollHeight );
	box.height = Math.min( box.height, Math.max( 1, pageHeight - box.y ) );

	// A viewport tall enough for the whole picture: a full-page capture stitches, and leaves seams.
	await page.setViewportSize( { width, height: Math.ceil( box.y + box.height ) } );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );
	await page.screenshot( { path: path.join( out, file ), clip: box } );
	await page.setViewportSize( { width, height: 900 } );
}

( async () => {
	const data = setUp();
	const browser = await chromium.launch();

	try {
		const context = await browser.newContext( {
			viewport: { width, height: 900 },
			deviceScaleFactor: 2,
			// Local test sites often have a certificate that only the system trusts.
			ignoreHTTPSErrors: true,
		} );
		// Local tools such as Laravel Herd add a toolbar, and WooCommerce adds setup notices on a new store.
		await context.addInitScript( () => {
			document.addEventListener( 'DOMContentLoaded', () => {
				const style = document.createElement( 'style' );
				style.textContent =
					'#herd-ai-root, #herd-ai-host, .notice:not(.mwtcl-keep), .update-nag, .woocommerce-store-alerts, .woocommerce-layout__notice-list { display: none !important; }';
				document.head.appendChild( style );
			} );
		} );
		const page = await context.newPage();

		await page.goto( `${ base }/wp-login.php` );
		await page.locator( '#user_login' ).fill( demo.username );
		await page.locator( '#user_pass' ).fill( demo.password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );

		// 1: the panel on the order screen.
		await page.goto( `${ base }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ data.order }` );
		await page.locator( '#customer-lens-panel .mwtcl-strip' ).waitFor();
		await shoot( page, 'screenshot-1.png', { selector: '#customer-lens-panel', height: 1100, margin: 16, crop: true } );

		// 2: the flag dialog.
		await page.locator( '[data-mwtcl-open="flag"]' ).click();
		await page.locator( '.mwtcl-dialog[open]' ).waitFor();
		await page.screenshot( { path: path.join( out, 'screenshot-2.png' ) } );
		await page.keyboard.press( 'Escape' );

		// 3: the orders list with the Customer Lens column.
		await page.goto( `${ base }/wp-admin/admin.php?page=wc-orders` );
		await page.locator( '.column-customer_lens' ).first().waitFor();
		await shoot( page, 'screenshot-3.png', { selector: '.wp-header-end', height: 760, margin: 60 } );

		// 4: the list filtered to customers with refused deliveries.
		await page.goto( `${ base }/wp-admin/admin.php?page=wc-orders&mwtcl_filter=refused` );
		await shoot( page, 'screenshot-4.png', { selector: '.wp-header-end', height: 620, margin: 60 } );

		// 5: the settings.
		await page.goto( `${ base }/wp-admin/admin.php?page=customer-lens` );
		await shoot( page, 'screenshot-5.png', { height: 1000 } );

		// 6: the privacy tab.
		await page.goto( `${ base }/wp-admin/admin.php?page=customer-lens&tab=privacy` );
		await shoot( page, 'screenshot-6.png', { height: 860 } );
	} finally {
		await browser.close();
		tearDown();
	}
} )();
