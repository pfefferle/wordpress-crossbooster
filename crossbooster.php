<?php
/**
 * Plugin Name: CrossBooster
 * Plugin URI: https://github.com/pfefferle/wordpress-crossbooster
 * Description: An experimental plugin that Cross-Boosts ActivityPub enabled Posts on Mastodon
 * Author: Matthias Pfefferle
 * Author URI: https://notiz.blog/
 * Version: 1.0.0
 * License: GPL-2.0
 * License URI: https://opensource.org/licenses/GPL-2.0
 * Text Domain: crossbooster
 * Domain Path: /languages
 * Update URI: https://github.com/pfefferle/wordpress-crossbooster
 * Requires Plugins: activitypub
 *
 * @package CrossBooster
 * @version 1.0.0
 */

namespace CrossBooster;

\defined( 'ABSPATH' ) || exit;

\defined( 'CROSSBOOSTER_DOMAIN' ) || \define( 'CROSSBOOSTER_DOMAIN', null );
\defined( 'CROSSBOOSTER_ACCESS_KEY' ) || \define( 'CROSSBOOSTER_ACCESS_KEY', null );

/**
 * Boost a post on Mastodon
 *
 * @param int    $id   The post id.
 * @param string $type The ActivityPub type.
 *
 * @return void
 */
function boost( $id, $type = 'Create' ) {
	if ( 'Create' !== $type ) {
		return;
	}

	$permalink = \get_the_permalink( $id );

	if ( ! $permalink ) {
		return;
	}

	$args = array(
		'timeout'     => 45,
		'redirection' => 5,
		'headers'     => array(
			'Content-Type'  => 'application/json; charset=utf-8',
			'Authorization' => 'Basic ' . CROSSBOOSTER_ACCESS_KEY,
		),
		'cookies'     => array(),
	);

	$search_url = sprintf(
		'https://%s/api/v2/search?resolve=true&type=statuses&limit=1&q=%s',
		CROSSBOOSTER_DOMAIN,
		\rawurlencode( $permalink )
	);

	$response = wp_safe_remote_get( $search_url, $args );

	if ( is_wp_error( $response ) ) {
		return;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = \json_decode( $body );

	if (
		! $data ||
		! isset( $data->statuses ) ||
		! isset( $data->statuses[0] )
	) {
		return;
	}

	$status = $data->statuses[0];

	if ( $permalink !== $status['url'] ) {
		return;
	}

	$boost_url = sprintf(
		'https://%s/api/v2/statuses/%s/reblog',
		CROSSBOOSTER_DOMAIN,
		$status->id
	);

	$response = wp_safe_remote_post( $boost_url, $args );
}
\add_action( 'activitypub_send_post', __NAMESPACE__ . '\boost', 20, 2 );
