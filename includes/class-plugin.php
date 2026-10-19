<?php
/**
 * Plugin bootstrap.
 *
 * @package Jcore\Silta
 */

namespace Jcore\Silta;

use Jcore\Silta\Crypto\Identity;
use Jcore\Silta\Remote\Verifier;
use Jcore\Update\Config\UpdateConfig;
use Jcore\Update\Hooks\PluginUpdateHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component to its hooks.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook. Called once on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		Database::maybe_upgrade();
		$this->register_updater();

		Capabilities::register();
		Verifier::register();
		Cron::register();
		Integrations\Maailma::register();
		Integrations\Gravity_Forms::register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Site::class, 'check' ), 1 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			Admin\Menu::register();
			Admin\List_Actions::register();
			Admin\Editor::register();
			Admin\Notices::register();
			add_action( 'admin_init', array( Identity::class, 'sync_with_constant' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( JCORE_SILTA_FILE ), array( $this, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
		}
	}

	/**
	 * Runs on activation: creates the tables.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Database::maybe_upgrade();
	}

	/**
	 * Runs on deactivation: stops the daily cleanup.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Cron::unschedule();
	}

	/**
	 * Hooks the plugin into the J&Co Digital update service.
	 *
	 * The library is vendored into the release; a source checkout without a
	 * `composer install` simply runs without update checks.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		if ( ! class_exists( UpdateConfig::class ) ) {
			return;
		}

		$config = new UpdateConfig(
			pluginFile: JCORE_SILTA_FILE,
			slug: 'jcore-silta',
			version: JCORE_SILTA_VERSION,
			apiBaseUrl: 'https://update.jcore.fi/v1',
		);

		( new PluginUpdateHooks( $config ) )->register();
	}

	/**
	 * Loads the PHP translations shipped in languages/.
	 *
	 * Just-in-time loading only looks in wp-content/languages, which these
	 * plugins never ship to, so the bundled files have to be loaded explicitly.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'jcore-silta', false, dirname( plugin_basename( JCORE_SILTA_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Rest\Settings_Controller() )->register_routes();
		( new Rest\Connections_Controller() )->register_routes();
		( new Rest\Source_Controller() )->register_routes();
		( new Rest\Jobs_Controller() )->register_routes();
		( new Rest\Status_Controller() )->register_routes();
		( new Rest\Remote_Controller() )->register_routes();
	}

	/**
	 * Adds a link to the Silta screen on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin\Menu::url() ),
				esc_html__( 'Open', 'jcore-silta' )
			)
		);

		return $links;
	}
}
