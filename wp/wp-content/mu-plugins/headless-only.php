<?php
/**
 * Plugin Name: Headless only
 * Description: Sends public front-end requests to the storefront. This install exists to serve wp-admin and the REST/GraphQL API for the Next.js frontend, so the bundled theme should never be what a visitor sees.
 * Version: 1.0.0
 *
 * Static files never reach PHP at all - vercel.json serves /wp-admin, /wp-content
 * and /wp-includes assets straight off the filesystem - so uploads, admin CSS and
 * plugin scripts are unaffected by anything here.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where visitors are sent. Overridable per environment so a preview deployment
 * can point at its own storefront.
 */
function fss_headless_storefront_url() {
	$url = isset( $_ENV['HEADLESS_FRONTEND_URL'] ) ? $_ENV['HEADLESS_FRONTEND_URL'] : 'https://fishing-store.vercel.app';

	return untrailingslashit( $url );
}

/**
 * Whether this request is one the headless setup still has to answer itself.
 *
 * template_redirect already excludes wp-admin, the REST API and wp-login.php,
 * because each of those exits earlier in the request. The checks below are
 * belt and braces for the paths that do reach it - WPGraphQL resolves on
 * parse_request, WooCommerce's legacy wc-api endpoints (used by some payment
 * gateway callbacks) likewise - plus an escape hatch for logged-in editors so
 * that post previews keep working.
 */
function fss_headless_should_pass_through() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return true;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	if ( function_exists( 'is_graphql_http_request' ) && is_graphql_http_request() ) {
		return true;
	}

	if ( ! empty( $_GET['wc-api'] ) || ! empty( $_GET['rest_route'] ) ) {
		return true;
	}

	if ( is_robots() ) {
		return true;
	}

	// Previewing an unpublished post is the one reason to render the theme, so
	// allow exactly that and nothing else. Being logged in is deliberately not
	// enough: an editor browsing the site normally should land on the
	// storefront like everyone else.
	if ( ( is_preview() || is_customize_preview() ) && current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return false;
}

add_action(
	'template_redirect',
	function () {
		if ( fss_headless_should_pass_through() ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';

		// The storefront mirrors the same paths, so keep the one requested
		// rather than dumping everyone on the home page.
		wp_redirect( fss_headless_storefront_url() . $path, 301 );
		exit;
	},
	0
);

/**
 * Keep the API host out of search results even while the redirects settle.
 */
add_action(
	'send_headers',
	function () {
		if ( ! is_admin() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}
);
