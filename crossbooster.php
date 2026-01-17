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
			'default'           => 'mastodon.social',
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
	$disabled = '';

	if ( \defined( 'CROSSBOOSTER_DOMAIN' ) && \defined( 'CROSSBOOSTER_ACCESS_KEY' ) ) {
		$disabled = \esc_attr( 'disabled' );
	}
	?>
	<div id="crossbooster-settings">
		<p class="description">
			<?php _e( 'CrossBooster is a plugin that allows you to cross-boost your ActivityPub posts on Mastodon.', 'crossbooster' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="crossbooster-domain"><?php \esc_html_e( 'Mastodon Domain', 'crossbooster' ); ?></label>
					</th>
					<td>
						<input type="text" class="large-text" id="crossbooster-domain" name="crossbooster_domain" value="<?php echo \esc_attr( \get_option( 'crossbooster_domain' ) ); ?>" <?php echo $disabled; ?> />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="crossbooster-access-key"><?php \esc_html_e( 'Access Key', 'crossbooster' ); ?></label>
					</th>
					<td>
						<input type="text" class="large-text" id="crossbooster-access-key" name="crossbooster_access_key" value="<?php echo \esc_attr( \get_option( 'crossbooster_access_key' ) ); ?>" <?php echo $disabled; ?> />
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
	if ( 'crossbooster_domain' === $option && \defined( 'CROSSBOOSTER_DOMAIN' ) ) {
		return CROSSBOOSTER_DOMAIN;
	}

	if ( 'crossbooster_access_key' === $option && \defined( 'CROSSBOOSTER_ACCESS_KEY' ) ) {
		return CROSSBOOSTER_ACCESS_KEY;
	}

	return $pre;
}
\add_filter( 'pre_option', __NAMESPACE__ . '\pre_option', 10, 2 );

/**
 * Schedule a boost
 *
 * @param array  $inboxes        The inboxes.
 * @param string $json           The ActivityPub Activity JSON
 *
 * @return void
 */
function schedule_boost( $inboxes, $json ) {
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

	$retries = 5;

	\wp_schedule_single_event( \time() + 10, 'crossbooster_boost', array( $id, $retries ) );
}
\add_action( 'activitypub_outbox_processing_complete', __NAMESPACE__ . '\schedule_boost', 10, 2 );

/**
 * Boost a post on Mastodon
 *
 * @param string $id The ID-URI of the post.
 *
 * @return void
 */
function boost( $id, $retries = 0 ) {
	if ( $retries <= 0 ) {
		\error_log( '[CrossBooster] Retries exhausted for: ' . $id );
		return;
	}

	$access_key = \get_option( 'crossbooster_access_key' );
	$domain     = \get_option( 'crossbooster_domain' );

	if ( empty( $access_key ) || empty( $domain ) ) {
		\error_log( '[CrossBooster] Missing access key or domain configuration' );
		return;
	}

	$args = array(
		'timeout'     => 45,
		'redirection' => 5,
		'headers'     => array(
			'Content-Type'  => 'application/json; charset=utf-8',
			'Authorization' => 'Bearer ' . $access_key,
		),
		'cookies'     => array(),
	);

	$search_url = sprintf(
		'https://%s/api/v2/search?resolve=true&type=statuses&limit=1&q=%s',
		$domain,
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
		! is_array( $data->statuses ) ||
		! isset( $data->statuses[0] )
	) {
		\wp_schedule_single_event( \time() + 10, 'crossbooster_boost', array( $id, $retries - 1 ) );
		return;
	}

	$status = $data->statuses[0];

	if ( $id !== $status->uri ) {
		\wp_schedule_single_event( \time() + 10, 'crossbooster_boost', array( $id, $retries - 1 ) );
		return;
	}

	$boost_url = sprintf(
		'https://%s/api/v1/statuses/%s/reblog',
		$domain,
		$status->id
	);

	$response = wp_safe_remote_post( $boost_url, $args );

	if ( \is_wp_error( $response ) ) {
		\error_log( '[CrossBooster] Failed to boost status: ' . $response->get_error_message() );
		return;
	}

	$response_code = \wp_remote_retrieve_response_code( $response );

	if ( $response_code < 200 || $response_code >= 300 ) {
		\error_log( '[CrossBooster] Boost request failed with status ' . $response_code . ': ' . \wp_remote_retrieve_body( $response ) );
	}
}
\add_action( 'crossbooster_boost', __NAMESPACE__ . '\boost', 10, 2 );
