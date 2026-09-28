<?php
/**
 * GraphMerger: selectors and operations against a keyed graph.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Tests;

use JoeriVanhamel\SchemaEditor\Output\GraphMerger;
use PHPUnit\Framework\TestCase;

final class GraphMergerTest extends TestCase {

	private function graph(): array {
		return [
			'publisher' => [ '@type' => [ 'HomeAndConstructionBusiness', 'Organization' ], '@id' => 'https://example.com/#organization', 'name' => 'Refined', 'aggregateRating' => [ 'ratingValue' => '4.9' ], 'review' => [] ],
			'WebPage'   => [ '@type' => 'WebPage', '@id' => 'https://example.com/garden-design/#webpage', 'name' => 'Page' ],
			'Service'   => [ '@type' => 'Service', '@id' => 'https://example.com/garden-design/#service', 'name' => 'Old' ],
		];
	}

	public function testSelectors(): void {
		$graph = $this->graph();
		self::assertSame( [ 'publisher' ], GraphMerger::matching( $graph, '#organization' ) );
		self::assertSame( [ 'publisher' ], GraphMerger::matching( $graph, '@type:Organization' ) );
		self::assertSame( [ 'WebPage' ], GraphMerger::matching( $graph, '@type:WebPage' ) );
		self::assertSame( [ 'Service' ], GraphMerger::matching( $graph, '@key:Service' ) );
		self::assertSame( [ 'WebPage' ], GraphMerger::matching( $graph, 'https://example.com/garden-design/#webpage' ) );
		self::assertSame( [], GraphMerger::matching( $graph, '#nothing' ) );
	}

	public function testAddReplacesByKeyInPlace(): void {
		$graph = GraphMerger::apply( $this->graph(), [ [ 'op' => 'add', 'key' => 'Service', 'node' => [ '@type' => 'Service', 'name' => 'New' ] ] ] );
		self::assertSame( 'New', $graph['Service']['name'] );
		self::assertSame( [ 'publisher', 'WebPage', 'Service' ], array_keys( $graph ) );
	}

	public function testMergeOverwritesProperties(): void {
		$graph = GraphMerger::apply( $this->graph(), [ [ 'op' => 'merge', 'match' => '#webpage', 'properties' => [ 'mainEntity' => [ '@id' => 'x' ], 'name' => 'Renamed' ] ] ] );
		self::assertSame( [ '@id' => 'x' ], $graph['WebPage']['mainEntity'] );
		self::assertSame( 'Renamed', $graph['WebPage']['name'] );
		self::assertSame( 'WebPage', $graph['WebPage']['@type'] );
	}

	public function testRemoveProperties(): void {
		$graph = GraphMerger::apply( $this->graph(), [ [ 'op' => 'remove', 'match' => '#organization', 'properties' => [ 'aggregateRating', 'review', 'priceRange' ] ] ] );
		self::assertArrayNotHasKey( 'aggregateRating', $graph['publisher'] );
		self::assertArrayNotHasKey( 'review', $graph['publisher'] );
		self::assertSame( 'Refined', $graph['publisher']['name'] );
	}

	public function testRemoveNode(): void {
		$graph = GraphMerger::apply( $this->graph(), [ [ 'op' => 'remove', 'match' => '@key:Service', 'properties' => null ] ] );
		self::assertArrayNotHasKey( 'Service', $graph );
	}
}
