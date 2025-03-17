<?php
/**
 * Plugin Name: CMS Health Checks for WordPress
 * Description: Implements the CMS Health Checks standard for WordPress
 * Version: 0.0.1
 * Author: Oliver Bartsch
 * License: MIT
 */

// If this file is called directly, abort.
use CmsHealthProject\SerializableReferenceImplementation\Check;
use CmsHealthProject\SerializableReferenceImplementation\CheckCollection;
use CmsHealthProject\SerializableReferenceImplementation\CheckResult;
use CmsHealthProject\SerializableReferenceImplementation\HealthCheck;

if (!defined('WPINC')) {
	die;
}

// Check if Composer autoloader exists
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	// Display admin notice if dependencies are missing
	add_action('admin_notices', function() {
		echo '<div class="error"><p>CMS Health plugin requires Composer dependencies. Please run "composer install" in the plugin directory.</p></div>';
	});
	return;
}

class CMS_Health_WP {
	/**
	 * The single instance of the class.
	 */
	protected static $_instance = null;

	/**
	 * Main CMS_Health_WP Instance.
	 */
	public static function instance() {
		if (is_null(self::$_instance)) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action('rest_api_init', array($this, 'register_rest_routes'));
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		register_rest_route('cms-health/v1', '/health', array(
			'methods' => 'GET',
			'callback' => array($this, 'get_health_check'),
			'permission_callback' => '__return_true' // No authentication required as per request
		));
	}

	/**
	 * Generate health check response.
	 */
	public function get_health_check($request) {
		$healthCheck = $this->generate_health_check_data();

		$response = new WP_REST_Response($healthCheck, 200);
		$response->header('Content-Type', 'application/json');
		return $response;
	}

	/**
	 * Generate health check data following the CMS Health Project standard.
	 */
	private function generate_health_check_data() {
		$checks = new CheckCollection();

		// Add core WordPress health checks
		$this->add_core_health_checks($checks);
		$this->add_database_health_checks($checks);
		$this->add_plugin_health_checks($checks);

		return new HealthCheck(
			'1',
			get_bloginfo('siteurl'),
			get_bloginfo('description'),
			new \DateTimeImmutable('now'),
			$checks
		);
	}

	/**
	 * Add core WordPress health checks.
	 */
	private function add_core_health_checks(CheckCollection $checks) {

		// WordPress version check
		$wp_version = get_bloginfo('version');
		$is_latest = $this->is_latest_wordpress_version($wp_version);

		$status = $is_latest ? \CmsHealth\Definition\CheckResultStatus::Pass : \CmsHealth\Definition\CheckResultStatus::Warn;
		$output = $is_latest
			? "WordPress is at the latest version ($wp_version)"
			: "WordPress is not at the latest version (Current: $wp_version)";

		$wpVersionCheck = new CheckResult(
			$status,
			md5('wordpress:core:version'),
			'system',
			new \DateTimeImmutable('now'),
			$wp_version,
			null,
			$output,
		);

		// PHP version check
		$php_version = phpversion();
		$min_recommended_php = '7.4';

		$php_status = version_compare($php_version, $min_recommended_php, '>=') ? \CmsHealth\Definition\CheckResultStatus::Pass : \CmsHealth\Definition\CheckResultStatus::Warn;
		$php_output = version_compare($php_version, $min_recommended_php, '>=')
			? "PHP version ($php_version) meets recommendations"
			: "PHP version ($php_version) is below recommended version $min_recommended_php";

		$phpVersionCheck = new CheckResult(
			$php_status,
			md5('wordpress:php:version'),
			'system',
			new \DateTimeImmutable('now'),
			$php_version,
			null,
			$php_output,
		);

		$checks->addCheck(new Check(
			'wordpress:version',
			[$wpVersionCheck, $phpVersionCheck]
		));
	}

	private function add_database_health_checks($checks) {
		global $wpdb;

		// Database connection check
		try {
			// Try a simple query
			$result = $wpdb->get_var("SELECT 1");

			if ($result === '1') {
				$db_status = \CmsHealth\Definition\CheckResultStatus::Pass;
				$db_output = "Database connection is working properly";
			} else {
				$db_status = \CmsHealth\Definition\CheckResultStatus::Fail;
				$db_output = "Database connection test failed";
			}
		} catch (Exception $e) {
			$db_status = \CmsHealth\Definition\CheckResultStatus::Fail;
			$db_output = "Database connection error: " . $e->getMessage();
		}

		$dbConnectionCheck = new CheckResult(
			$db_status,
			md5('wordpress:php:version'),
			'system',
			new \DateTimeImmutable('now'),
			null,
			null,
			$db_output
		);

		// Database tables check
		try {
			$tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}%'", ARRAY_N);
			$table_count = count($tables);

			if ($table_count > 0) {
				$tables_status = \CmsHealth\Definition\CheckResultStatus::Pass;
				$tables_output = "Database contains $table_count WordPress tables";
			} else {
				$tables_status = \CmsHealth\Definition\CheckResultStatus::Fail;
				$tables_output = "No WordPress database tables found";
			}
		} catch (Exception $e) {
			$tables_status = \CmsHealth\Definition\CheckResultStatus::Fail;
			$tables_output = "Database tables check error: " . $e->getMessage();
			$table_count = null;
		}

		$dbTablesCheck = new CheckResult(
			$tables_status,
			md5('wordpress:php:version'),
			'datastore',
			new \DateTimeImmutable('now'),
			$table_count,
			null,
			$tables_output
		);

		$checks->addCheck(new Check(
			'wordpress:database',
			[$dbConnectionCheck, $dbTablesCheck]
		));
	}

	/**
	 * Add plugin health checks.
	 */
	private function add_plugin_health_checks($checks) {
		// Active plugins check
		$plugins = get_option('active_plugins');
		$plugin_count = count($plugins);

		$plugin_count = new CheckResult(
			\CmsHealth\Definition\CheckResultStatus::Info,
			md5('wordpress:plugins:active'),
			'component',
			new \DateTimeImmutable('now'),
			$plugin_count,
		);

		$checks->addCheck(new Check(
			'wordpress:plugins:active',
			[$plugin_count]
		));
	}

	/**
	 * Check if the current WordPress version is the latest.
	 */
	private function is_latest_wordpress_version($current_version) {
		$response = wp_remote_get('https://api.wordpress.org/core/version-check/1.7/');

		if (is_wp_error($response)) {
			return true; // Assume it's up to date if we can't check
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (!isset($data['offers'][0]['version'])) {
			return true; // Assume it's up to date if we can't determine
		}

		$latest_version = $data['offers'][0]['version'];

		return version_compare($current_version, $latest_version, '>=');
	}
}

// Initialize the plugin
function cms_health_wp() {
	return CMS_Health_WP::instance();
}

// Start the plugin
cms_health_wp();