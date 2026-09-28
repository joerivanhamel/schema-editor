<?php
/**
 * Wires the plugin together.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor;

use JoeriVanhamel\SchemaEditor\Admin\MetaBoxes;
use JoeriVanhamel\SchemaEditor\Admin\PostType;
use JoeriVanhamel\SchemaEditor\Admin\Preview;
use JoeriVanhamel\SchemaEditor\Admin\SettingsPage;
use JoeriVanhamel\SchemaEditor\Cli\Command;
use JoeriVanhamel\SchemaEditor\Output\Engine;
use JoeriVanhamel\SchemaEditor\Output\GraphPreview;
use JoeriVanhamel\SchemaEditor\Output\RankMathBridge;
use JoeriVanhamel\SchemaEditor\Output\StandaloneOutput;
use JoeriVanhamel\SchemaEditor\Render\Renderer;
use JoeriVanhamel\SchemaEditor\Render\WordPressResolver;
use JoeriVanhamel\SchemaEditor\Template\Repository;

/**
 * Composition root. Everything else takes its collaborators through the constructor.
 */
final class Plugin {

	private readonly Settings $settings;
	private readonly Repository $repository;
	private readonly Engine $engine;

	public function __construct() {
		$this->settings   = new Settings();
		$this->repository = new Repository();
		$this->engine     = new Engine( $this->repository, new Renderer( new WordPressResolver() ) );
	}

	public function boot(): void {
		( new PostType() )->register();
		if ( is_admin() ) {
			( new MetaBoxes( $this->repository ) )->register();
			( new Preview( $this->engine, $this->repository ) )->register();
			( new SettingsPage( $this->settings, $this->repository ) )->register();
		}
		add_action( 'init', [ $this, 'registerOutput' ], 20 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'schema-editor', new Command( $this->engine, $this->repository ) );
		}
	}

	/**
	 * Decide at init (plugins are all loaded) whether Rank Math prints the graph or the plugin does.
	 *
	 * "auto" edits Rank Math's graph while Rank Math's schema module is on, and prints the plugin's own graph
	 * when Rank Math is absent or its schema module is off (Rank Math then prints no JSON-LD at all).
	 */
	public function registerOutput(): void {
		( new GraphPreview( $this->engine ) )->register();
		$mode = $this->outputMode();
		if ( null === $mode ) {
			return;
		}
		if ( $this->settings->get( 'debug_comment' ) ) {
			add_action( 'wp_footer', [ $this, 'debugComment' ], 99 );
		}
		if ( Engine::MODE_RANKMATH === $mode ) {
			( new RankMathBridge( $this->engine, $this->settings ) )->register();
			return;
		}
		( new StandaloneOutput( $this->engine ) )->register();
	}

	/**
	 * The mode in force: "rankmath", "standalone", or null when output is off (or forced to Rank Math while Rank
	 * Math prints nothing, which would otherwise print two graphs or none).
	 */
	public function outputMode(): ?string {
		return self::resolveMode( $this->settings );
	}

	/**
	 * The output mode a settings object puts in force (see outputMode()).
	 */
	public static function resolveMode( Settings $settings ): ?string {
		$setting = (string) $settings->get( 'output_mode' );
		if ( 'off' === $setting ) {
			return null;
		}
		if ( 'standalone' === $setting ) {
			return Engine::MODE_STANDALONE;
		}
		if ( 'rankmath' === $setting ) {
			return RankMathBridge::rankMathActive() ? Engine::MODE_RANKMATH : null;
		}
		return RankMathBridge::rankMathSchemaActive() ? Engine::MODE_RANKMATH : Engine::MODE_STANDALONE;
	}

	/**
	 * An HTML comment naming the templates that applied to this request (setting "debug_comment").
	 */
	public function debugComment(): void {
		$names = array_map( static fn( $t ): string => $t->name, $this->engine->matching( \JoeriVanhamel\SchemaEditor\Template\RequestContext::fromWordPress(), $this->outputMode() ) );
		echo "\n<!-- Schema Editor: " . esc_html( $names ? implode( ' | ', $names ) : 'no template applied' ) . " -->\n";
	}

	public function engine(): Engine {
		return $this->engine;
	}

	public function repository(): Repository {
		return $this->repository;
	}
}
