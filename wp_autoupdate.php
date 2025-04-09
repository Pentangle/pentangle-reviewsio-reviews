<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include the Parsedown library if not already loaded.
if ( ! class_exists( 'Parsedown' ) ) {
	include_once plugin_dir_path( __FILE__ ) . 'Parsedown.php';
}

/**
 * Pentangle Reviews.io Reviews Plugin Update Handler.
 */

add_filter( 'pre_set_site_transient_update_plugins', 'pentangle_reviewsio_reviews_update_check' );
add_filter( 'plugins_api', 'pentangle_reviewsio_reviews_plugin_details', 10, 3 );
add_action( 'admin_head', 'pentangle_reviewsio_reviews_changelog_styles' );

/**
 * Retrieve the latest release from GitHub.
 *
 * @return array|false Latest release data as an associative array, or false on error.
 */
function pentangle_reviewsio_reviews_get_latest_release(): bool|array {
	$api_url = 'https://api.github.com/repos/Pentangle/pentangle-reviewsio-reviews/releases/latest';
	$headers = [
		'Authorization' => 'token ' . GITHUB_ACCESS_TOKEN,
		'User-Agent'    => 'WordPress',
		'Accept'        => 'application/vnd.github.v3+json',
	];

	$response = wp_remote_get( $api_url, [ 'headers' => $headers ] );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$release_data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( isset( $release_data['status'] ) && $release_data['status'] === '404' ) {
		return false;
	}

	return $release_data;
}

/**
 * Get plugin header data.
 *
 * @return array Plugin data.
 */
function pentangle_reviewsio_reviews_get_plugin_data(): array {
	$plugin_file = 'pentangle-reviewsio-reviews/pentangle-reviewsio-reviews.php';

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
}

/**
 * Check for plugin updates.
 *
 * @param object $transient Data on plugin updates.
 *
 * @return object Updated transient object.
 */
function pentangle_reviewsio_reviews_update_check( object $transient ): object {
	if ( ! is_object( $transient ) ) {
		$transient = new stdClass();
	}

	if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = [];
	}

	$plugin_file  = 'pentangle-reviewsio-reviews/pentangle-reviewsio-reviews.php';
	$plugin_data  = pentangle_reviewsio_reviews_get_plugin_data();
	$release_data = pentangle_reviewsio_reviews_get_latest_release();

	if ( ! $release_data ) {
		return $transient;
	}

	$latest_version = str_replace( 'v', '', $release_data['tag_name'] );
	if ( ! version_compare( $plugin_data['Version'], $latest_version, '<' ) ) {
		return $transient;
	}

	if ( ! isset( $release_data['assets'][0]['browser_download_url'] ) ) {
		return $transient;
	}

	$update_info                         = [
		'id'          => $plugin_file,
		'slug'        => $plugin_data['TextDomain'],
		'plugin'      => $plugin_file,
		'new_version' => $latest_version,
		'package'     => $release_data['assets'][0]['browser_download_url'],
		'author'      => $plugin_data['Author'],
	];
	$transient->response[ $plugin_file ] = (object) $update_info;

	return $transient;
}

/**
 * Provide up-to-date plugin details for the update information modal.
 *
 * @param mixed $def Default API response.
 * @param string $action The requested action.
 * @param object $args Plugin API arguments.
 *
 * @return object Plugin details or default response if conditions are not met.
 */
function pentangle_reviewsio_reviews_plugin_details( mixed $def, string $action, object $args ): object {
	if ( 'plugin_information' !== $action ) {
		return $def;
	}

	$plugin_data = pentangle_reviewsio_reviews_get_plugin_data();

	if ( $args->slug !== $plugin_data['TextDomain'] ) {
		return $def;
	}

	$release_data = pentangle_reviewsio_reviews_get_latest_release();
	if ( ! $release_data ) {
		return $def;
	}

	$parsedown      = new Parsedown();
	$latest_version = str_replace( 'v', '', $release_data['tag_name'] );

	$plugin_info               = new stdClass();
	$plugin_info->name         = $plugin_data['Name'];
	$plugin_info->slug         = $plugin_data['TextDomain'];
	$plugin_info->version      = $latest_version;
	$plugin_info->author       = $plugin_data['Author'];
	$plugin_info->tested       = '6.7.2';
	$plugin_info->requires_php = '7.0';
	$plugin_info->last_updated = $release_data['created_at'];
	$plugin_info->sections     = [
		'description' => $plugin_data['Description'],
		'changelog'   => isset( $release_data['body'] ) ? $parsedown->text( $release_data['body'] ) : 'No changelog available.',
	];

	if ( isset( $release_data['assets'][0]['browser_download_url'] ) ) {
		$plugin_info->download_link = $release_data['assets'][0]['browser_download_url'];
	}

	return $plugin_info;
}

/**
 * Output custom CSS for the changelog modal.
 */
function pentangle_reviewsio_reviews_changelog_styles(): void {
	if ( ! is_admin() ) {
		return;
	}
	echo '<style>
        #section-changelog {
            display: inline-block !important;
        }
    </style>';
}
