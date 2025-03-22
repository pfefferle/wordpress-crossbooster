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

/**
 * Register options
 */
function register_setting() {
	\register_setting(
		'activitypub',
		'crossbooster_domain',
		array(
			'type'              => 'string',
			'description'       => __( 'The domain of the Mastodon instance', 'crossbooster' ),
			'default'           => '',
			'sanitize_callback' => function ( $value ) {
				return \trim( \str_replace( array( 'http://', 'https://' ), '', \trim( $value, '/' ) ) );
			},
		)
	);

	\register_setting(
		'activitypub',
		'crossbooster_access_key',
		array(
			'type'              => 'string',
			'description'       => __( 'The access key for the Mastodon instance', 'crossbooster' ),
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
}
\add_action( 'admin_init', __NAMESPACE__ . '\register_setting' );

/**
 * Add the settings to ActivityPub settings page
 */
function add_settings_field() {
	\add_settings_field(
		'crossbooster',
		__( 'CrossBooster', 'crossbooster' ),
		__NAMESPACE__ . '\render_settings_field',
		'activitypub_settings',
		'activitypub_general'
	);
}
\add_action( 'load-settings_page_activitypub', __NAMESPACE__ . '\add_settings_field', 99 );

/**
 * Render the settings field
 */
function render_settings_field() {
	?>
	<div id="crossbooster-settings">
		<p class="description">
			<?php _e( 'CrossBooster is a plugin that allows you to cross-boost your ActivityPub posts on Mastodon.', 'crossbooster' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php \esc_html_e( 'Mastodon Domain', 'crossbooster' ); ?></th>
					<td>
						<input type="text" class="large-text code" id="crossbooster-domain" value="<?php echo \esc_attr( \get_option( 'crossbooster_domain' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php \esc_html_e( 'Access Key', 'crossbooster' ); ?></th>
					<td>
						<input type="text" class="large-text code" id="crossbooster-access-key" value="<?php echo \esc_attr( \get_option( 'crossbooster_access_key' ) ); ?>" />
					</td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * `get_option` Hook
 *
 * @param string $pre The option value.
 * @param string $option The option name.
 *
 * @return string The option value.
 */
function pre_option( $pre, $option ) {
	if ( 'crossbooster_domain' === $option && defined( 'CROSSBOOSTER_DOMAIN' ) ) {
		return CROSSBOOSTER_DOMAIN;
	}

	if ( 'crossbooster_access_key' === $option && defined( 'CROSSBOOSTER_ACCESS_KEY' ) ) {
		return CROSSBOOSTER_ACCESS_KEY;
	}

	return $pre;
}
\add_filter( 'pre_option', __NAMESPACE__ . '\pre_option', 10, 2 );

/**
 * Boost a post on Mastodon
 *
 * @param array  $inboxes        The inboxes.
 * @param string $json           The ActivityPub Activity JSON
 *
 * @return void
 */
function boost( $inboxes, $json ) {
	$activity = \json_decode( $json, true );

	if (
		! is_array( $activity ) ||
		! isset( $activity['type'] ) ||
		'Create' !== $activity['type'] ||
		! isset( $activity['object']['id'] )
	) {
		return;
	}

	$id = $activity['object']['id'];

	if ( ! $id ) {
		return;
	}

	$args = array(
		'timeout'     => 45,
		'redirection' => 5,
		'headers'     => array(
			'Content-Type'  => 'application/json; charset=utf-8',
			'Authorization' => 'Basic ' . \get_option( 'crossbooster_access_key' ),
		),
		'cookies'     => array(),
	);

	$search_url = sprintf(
		'https://%s/api/v2/search?resolve=true&type=statuses&limit=1&q=%s',
		\get_option( 'crossbooster_domain' ),
		\rawurlencode( $id )
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

	if ( $id !== $status['url'] ) {
		return;
	}

	$boost_url = sprintf(
		'https://%s/api/v2/statuses/%s/reblog',
		\get_option( 'crossbooster_domain' ),
		$status->id
	);

	$response = wp_safe_remote_post( $boost_url, $args );
}
\add_action( 'activitypub_outbox_processing_complete', __NAMESPACE__ . '\boost', 10, 2 );
