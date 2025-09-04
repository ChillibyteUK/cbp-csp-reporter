<?php
/**
 * Plugin Name: CB CSP Reporter (MU)
 * Description: Minimal CSP report collector for report-uri and Reporting API. Writes NDJSON to uploads/csp-reports/.
 * Version: 1.0.0
 * Author: Chillibyte - DS
 *
 * @package cbp-csp-reporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Minimal CSP report collector for report-uri and Reporting API.
 * Handles REST API ingestion, storage, and rotation of CSP reports.
 */
class CB_CSP_Reporter {

    /**
     * Constructor. Sets up hooks for REST API, storage directory, and rotation jobs.
     */
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_action( 'init', array( $this, 'ensure_storage_dir' ) );
        add_action( 'admin_post_cb_csp_rotate', array( $this, 'manual_rotate' ) );
        add_action( 'cb_csp_daily_rotate', array( $this, 'rotate_job' ) );
        if ( ! wp_next_scheduled( 'cb_csp_daily_rotate' ) ) {
            wp_schedule_event( time() + 3600, 'daily', 'cb_csp_daily_rotate' );
        }
    	add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
    }

    /**
     * Registers REST API routes for CSP report ingestion.
     */
    public function register_routes() {
        register_rest_route(
            'csp/v1',
            '/report',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'ingest' ),
                'permission_callback' => '__return_true',
                'args'                => array(),
            )
        );

        register_rest_route(
            'csp/v1',
            '/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_summary' ),
                'permission_callback' => '__return_true',
            )
        );
    }
    /**
     * Adds a simple admin page to show top offenders from the latest NDJSON file.
     */
    public function add_admin_page() {
        add_menu_page(
            'CSP Top Offenders',
            'CSP Offenders',
            'manage_options',
            'cb-csp-offenders',
            array( $this, 'render_admin_page' ),
            'dashicons-shield',
            80
        );
    }

    /**
     * Renders the admin page with top offenders.
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }
        $file   = $this->storage_path();
        $counts = array();
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( $wp_filesystem->exists( $file ) ) {
            $contents = $wp_filesystem->get_contents( $file );
            if ( false !== $contents ) {
                $lines = explode( "\n", $contents );
                foreach ( $lines as $line ) {
                    if ( '' === trim( $line ) ) {
                        continue;
                    }
                    $data = json_decode( $line, true );
                    if ( ! empty( $data['blocked_uri'] ) ) {
                        $key = $data['blocked_uri'];
                        if ( ! isset( $counts[ $key ] ) ) {
                            $counts[ $key ] = 0;
                        }
                        ++$counts[ $key ];
                    }
                }
            }
        }
        arsort( $counts );
        echo '<div class="wrap"><h1>CSP Top Offenders</h1>';
        if ( empty( $counts ) ) {
            echo '<p>No data found for today.</p>';
        } else {
            echo '<table class="widefat"><thead><tr><th>Blocked URI</th><th>Count</th></tr></thead><tbody>';
            foreach ( array_slice( $counts, 0, 20 ) as $uri => $count ) {
                echo '<tr><td>' . esc_html( $uri ) . '</td><td>' . esc_html( $count ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /**
     * REST callback: Aggregates counts by effective_directive and blocked_uri from the latest NDJSON file.
     */
    public function get_summary() {
        $file    = $this->storage_path();
        $summary = array();
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( $wp_filesystem->exists( $file ) ) {
            $contents = $wp_filesystem->get_contents( $file );
            if ( false !== $contents ) {
                $lines = explode( "\n", $contents );
                foreach ( $lines as $line ) {
                    if ( '' === trim( $line ) ) {
                        continue;
                    }
                    $data    = json_decode( $line, true );
                    $dir     = isset( $data['effective_directive'] ) ? $data['effective_directive'] : 'unknown';
                    $blocked = isset( $data['blocked_uri'] ) ? $data['blocked_uri'] : 'unknown';
                    if ( ! isset( $summary[ $dir ] ) ) {
                        $summary[ $dir ] = array();
                    }
                    if ( ! isset( $summary[ $dir ][ $blocked ] ) ) {
                        $summary[ $dir ][ $blocked ] = 0;
                    }
                    ++$summary[ $dir ][ $blocked ];
                }
            }
        }
        return rest_ensure_response( $summary );
    }

    /**
     * Ensures the CSP report storage directory exists in the uploads folder.
     */
    public function ensure_storage_dir() {
        $upload = wp_get_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'csp-reports';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
    }

    /**
     * Returns the file path for storing CSP reports for the current date.
     *
     * @return string
     */
    private function storage_path() {
        $upload = wp_get_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'csp-reports';
        $date   = gmdate( 'Y-m-d' );
        return $dir . '/csp-' . $date . '.ndjson';
    }

    /**
     * Checks if the given URL is from a browser extension and should be considered noise.
     *
     * @param string|null $url The URL to check.
     * @return bool True if the URL is from a browser extension, false otherwise.
     */
    private function is_extension_noise( $url ) {
        if ( null === $url ) {
            return false;
        }
        $url     = (string) $url;
        $schemes = array(
            'chrome-extension:',
            'moz-extension:',
            'safari-extension:',
            'edge-extension:',
        );
        foreach ( $schemes as $s ) {
            if ( stripos( $url, $s ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if the given URL should be ignored (e.g., about:, data:).
     *
     * @param string|null $url The URL to check.
     * @return bool True if the URL should be ignored, false otherwise.
     */
    private function is_ignorable_url( $url ) {
        if ( null === $url || '' === $url ) {
            return false;
        }
        $u = (string) $url;
        if ( stripos( $u, 'about:' ) === 0 ) {
            return true;
        }
        if ( stripos( $u, 'data:' ) === 0 ) {
            return true;
        }
        if ( stripos( $u, 'blob:' ) === 0 ) {
            return false; // keep blob for worker-src etc.
        }
        return false;
    }

    /**
     * Normalises a legacy CSP report array to a standard format.
     *
     * @param array $r The CSP report array.
     * @return array The normalised CSP report.
     */
    private function normalise_csp_report( $r ) {
        // Legacy "csp-report" shape fields.
        $map = array(
            'document-uri'        => 'document_uri',
            'referrer'            => 'referrer',
            'violated-directive'  => 'violated_directive',
            'effective-directive' => 'effective_directive',
            'blocked-uri'         => 'blocked_uri',
            'original-policy'     => 'original_policy',
            'source-file'         => 'source_file',
            'line-number'         => 'line_number',
            'disposition'         => 'disposition',
            'script-sample'       => 'script_sample',
            'status-code'         => 'status_code',
        );

        $out = array();
        foreach ( $map as $k => $v ) {
            $out[ $v ] = isset( $r[ $k ] ) ? $r[ $k ] : null;
        }
        return $out;
    }

    /**
     * Normalises a Reporting API CSP violation item to a standard format.
     *
     * @param array $item The Reporting API item.
     * @return array The normalised CSP report.
     */
    private function normalise_reporting_api_item( $item ) {
    	// Example structure for Reporting API item: type=csp-violation, age=123, url=..., body={...} // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar.
        $body   = isset( $item['body'] ) ? $item['body'] : array();
        $base   = array(
            'document_uri'          => isset( $item['url'] ) ? $item['url'] : null,
            'user_agent_hint_brand' => isset( $item['user_agent'] ) ? $item['user_agent'] : null,
        );
        $legacy = $this->normalise_csp_report( $body );
        return array_merge( $legacy, $base );
    }

    /**
     * Ingests CSP reports from REST API requests and writes them to NDJSON files.
     *
     * @return WP_REST_Response
     */
    public function ingest() {
        $this->ensure_storage_dir();
        $content_type = isset( $_SERVER['CONTENT_TYPE'] )
            ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) )
            : '';
        $raw          = file_get_contents( 'php://input' );
        $now          = gmdate( 'c' );
        $ua           = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

        $records = array();

        if ( strpos( $content_type, 'application/reports+json' ) !== false || $this->looks_like_reporting_api( $raw ) ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                foreach ( $json as $item ) {
                    if ( isset( $item['type'] ) && 'csp-violation' === $item['type'] ) {
                        $norm      = $this->normalise_reporting_api_item( $item );
                        $records[] = $norm;
                    }
                }
            }
        } else {
            // Legacy one-off report.
            $json = json_decode( $raw, true );
            if ( isset( $json['csp-report'] ) ) {
                $records[] = $this->normalise_csp_report( $json['csp-report'] );
            } elseif ( is_array( $json ) ) {
                // Some browsers omit the wrapper; try directly.
                $records[] = $this->normalise_csp_report( $json );
            }
        }

        if ( empty( $records ) ) {
            return new WP_REST_Response(
				array(
					'ok'     => false,
					'reason' => 'bad-payload',
				),
				400
			);
        }

        $written = 0;
        $path    = $this->storage_path();
        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $existing = '';
        if ( $wp_filesystem->exists( $path ) ) {
            $existing = $wp_filesystem->get_contents( $path );
            if ( false === $existing ) {
                $existing = '';
            }
        }
        $new_lines = '';
        foreach ( $records as $r ) {
            $blocked = isset( $r['blocked_uri'] ) ? $r['blocked_uri'] : null;
            if ( $this->is_extension_noise( $blocked ) ) {
                continue;
            }
            if ( $this->is_ignorable_url( $blocked ) ) {
                continue;
            }
            $line       = array(
                'received_at'         => $now,
                'user_agent'          => $ua,
                'document_uri'        => isset( $r['document_uri'] ) ? $r['document_uri'] : null,
                'referrer'            => isset( $r['referrer'] ) ? $r['referrer'] : null,
                'violated_directive'  => isset( $r['violated_directive'] ) ? $r['violated_directive'] : null,
                'effective_directive' => isset( $r['effective_directive'] ) ? $r['effective_directive'] : null,
                'blocked_uri'         => isset( $r['blocked_uri'] ) ? $r['blocked_uri'] : null,
                'source_file'         => isset( $r['source_file'] ) ? $r['source_file'] : null,
                'line_number'         => isset( $r['line_number'] ) ? (int) $r['line_number'] : null,
                'disposition'         => isset( $r['disposition'] ) ? $r['disposition'] : null,
                'original_policy'     => isset( $r['original_policy'] ) ? $r['original_policy'] : null,
                'script_sample'       => isset( $r['script_sample'] ) ? $r['script_sample'] : null,
                'status_code'         => isset( $r['status_code'] ) ? (int) $r['status_code'] : null,
                'ip'                  => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
            );
            $json_line  = wp_json_encode( $line ) . "\n";
            $new_lines .= $json_line;
            ++$written;
        }
        $wp_filesystem->put_contents( $path, $existing . $new_lines, FS_CHMOD_FILE );

        return new WP_REST_Response(
			array(
				'ok'      => true,
				'written' => $written,
			),
			201
		);
    }

    /**
     * Checks if the raw input looks like a Reporting API payload.
     *
     * @param string|null $raw The raw input string.
     * @return bool True if the input matches Reporting API format, false otherwise.
     */
    private function looks_like_reporting_api( $raw ) {
        if ( '' === $raw || null === $raw ) {
            return false;
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return false;
        }
        // Reporting API is an array of objects with "type".
        return isset( $decoded[0] ) && isset( $decoded[0]['type'] );
    }

    /**
     * Stub for daily rotation job; currently does nothing as files auto-roll by date.
     *
     * @return bool Always returns true.
     */
    public function rotate_job() {
        // nothing heavy: daily files auto-roll by date; this is a stub for future pruning.
        return true;
    }

    /**
     * Handles manual rotation via admin hook; currently redirects to admin dashboard.
     *
     * @return void
     */
    public function manual_rotate() {
        // reserved for a manual admin hook if you want to close the current file.
        wp_safe_redirect( admin_url() );
        exit;
    }
}

new CB_CSP_Reporter();
