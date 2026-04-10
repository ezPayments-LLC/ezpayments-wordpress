<?php
/**
 * ezPayments Plugin Auto-Updater
 *
 * Checks GitHub releases for new versions and integrates with
 * the WordPress plugin update system.
 *
 * @package EzPayments_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EzPayments_Updater {

    /**
     * GitHub repository (owner/repo).
     */
    const GITHUB_REPO = 'ezPayments-LLC/ezpayments-wordpress';

    /**
     * GitHub API URL for latest release.
     */
    const GITHUB_API_URL = 'https://api.github.com/repos/ezPayments-LLC/ezpayments-wordpress/releases/latest';

    /**
     * Cache key for the update check.
     */
    const CACHE_KEY = 'ezpayments_update_check';

    /**
     * Cache duration in seconds (6 hours).
     */
    const CACHE_TTL = 21600;

    /**
     * Initialize the updater hooks.
     */
    public static function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
    }

    /**
     * Check GitHub for a newer release and inject it into WordPress update transient.
     *
     * @param object $transient The update_plugins transient.
     * @return object Modified transient.
     */
    public static function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $remote_version = ltrim( $release['tag_name'], 'v' );
        $plugin_file    = EZPAYMENTS_PLUGIN_BASENAME;

        if ( version_compare( EZPAYMENTS_VERSION, $remote_version, '<' ) ) {
            $download_url = self::get_zip_url( $release );

            if ( $download_url ) {
                $transient->response[ $plugin_file ] = (object) array(
                    'slug'        => 'ezpayments-woocommerce',
                    'plugin'      => $plugin_file,
                    'new_version' => $remote_version,
                    'url'         => 'https://github.com/' . self::GITHUB_REPO,
                    'package'     => $download_url,
                    'icons'       => array(
                        'default' => EZPAYMENTS_PLUGIN_URL . 'assets/images/ezpayments-icon.svg',
                    ),
                    'tested'      => '6.7',
                    'requires'    => '5.8',
                );
            }
        } else {
            // No update available — clear from response if it was there.
            unset( $transient->response[ $plugin_file ] );
            $transient->no_update[ $plugin_file ] = (object) array(
                'slug'        => 'ezpayments-woocommerce',
                'plugin'      => $plugin_file,
                'new_version' => EZPAYMENTS_VERSION,
                'url'         => 'https://github.com/' . self::GITHUB_REPO,
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the WordPress "View Details" modal.
     *
     * @param false|object|array $result The result object or array.
     * @param string             $action The API action being performed.
     * @param object             $args   Plugin API arguments.
     * @return false|object
     */
    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || 'ezpayments-woocommerce' !== $args->slug ) {
            return $result;
        }

        $release = self::get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = ltrim( $release['tag_name'], 'v' );

        return (object) array(
            'name'            => 'ezPayments for WooCommerce',
            'slug'            => 'ezpayments-woocommerce',
            'version'         => $remote_version,
            'author'          => '<a href="https://ezpayments.co">ezPayments</a>',
            'author_profile'  => 'https://ezpayments.co',
            'homepage'        => 'https://github.com/' . self::GITHUB_REPO,
            'requires'        => '5.8',
            'tested'          => '6.7',
            'requires_php'    => '7.4',
            'download_link'   => self::get_zip_url( $release ),
            'trunk'           => self::get_zip_url( $release ),
            'last_updated'    => $release['published_at'] ?? '',
            'sections'        => array(
                'description'  => 'Accept payments in your WooCommerce store via ezPayments with test and live mode support.',
                'changelog'    => self::format_changelog( $release ),
            ),
            'banners'         => array(),
        );
    }

    /**
     * Clear the update cache after an upgrade completes.
     *
     * @param WP_Upgrader $upgrader The upgrader instance.
     * @param array       $options  Upgrade options.
     */
    public static function clear_cache( $upgrader, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( self::CACHE_KEY );
        }
    }

    /**
     * Fetch the latest release from GitHub (cached).
     *
     * @return array|null Release data or null on failure.
     */
    private static function get_latest_release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( self::GITHUB_API_URL, array(
            'timeout'    => 10,
            'sslverify'  => true,
            'headers'    => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'ezPayments-WooCommerce/' . EZPAYMENTS_VERSION,
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure for 1 hour to avoid hammering the API.
            set_transient( self::CACHE_KEY, null, HOUR_IN_SECONDS );
            return null;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $release ) || ! isset( $release['tag_name'] ) ) {
            return null;
        }

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * Find the zip download URL from a GitHub release.
     *
     * @param array $release The GitHub release data.
     * @return string|null The download URL or null.
     */
    private static function get_zip_url( $release ) {
        // Check uploaded assets first (the build zip).
        if ( ! empty( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( isset( $asset['name'] ) && strpos( $asset['name'], '.zip' ) !== false ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fall back to the source zip.
        return $release['zipball_url'] ?? null;
    }

    /**
     * Format the release body as a changelog section.
     *
     * @param array $release The GitHub release data.
     * @return string HTML changelog.
     */
    private static function format_changelog( $release ) {
        $body    = $release['body'] ?? '';
        $version = ltrim( $release['tag_name'] ?? '', 'v' );

        if ( empty( $body ) ) {
            return '<h4>' . esc_html( $version ) . '</h4><p>See the <a href="https://github.com/' . self::GITHUB_REPO . '/releases" target="_blank">release notes on GitHub</a>.</p>';
        }

        // Convert markdown-style lists to HTML.
        $lines = explode( "\n", $body );
        $html  = '<h4>' . esc_html( $version ) . '</h4><ul>';
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) || strpos( $line, '#' ) === 0 ) {
                continue;
            }
            $line  = ltrim( $line, '-* ' );
            $html .= '<li>' . esc_html( $line ) . '</li>';
        }
        $html .= '</ul>';

        return $html;
    }
}
