<?php
/**
 * The template language features that let a template set build a whole graph on its own (0.2.0): variables,
 * conditions, comparisons, node-level and query loops, list splicing and the text and list filters.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Tests;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Render\ArrayResolver;
use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Render\Expression;
use JoeriVanhamel\SchemaEditor\Render\Renderer;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use JoeriVanhamel\SchemaEditor\Template\Template;
use PHPUnit\Framework\TestCase;

final class StandaloneFeaturesTest extends TestCase {

	/**
	 * @param array<int,array<string,mixed>> $nodes Node definitions.
	 * @param array<string,mixed>            $data  Resolver data.
	 * @param array<string,mixed>            $extra Extra document keys (vars, output).
	 * @return array<int,array<string,mixed>>
	 */
	private function render( array $nodes, array $data = [], array $extra = [], mixed $request = null ): array {
		$template = Template::fromArray( array_merge( [ 'name' => 't', 'targets' => [ [ 'type' => 'all' ] ], 'nodes' => $nodes ], $extra ) );
		$renderer = new Renderer( new ArrayResolver( $data ) );
		return $renderer->render( $template, new Context( $data['post'] ?? null, null, null, [], 0, $request ) );
	}

	private function evaluate( string $expression, array $data = [] ): mixed {
		return ( new Expression( new ArrayResolver( $data ) ) )->evaluate( $expression, new Context( $data['post'] ?? null ) );
	}

	public function testVarsAreEvaluatedInOrderAndReadAsVar(): void {
		$ops = $this->render(
			[ [ '@type' => 'Thing', '@id' => '{{ var.base }}#thing', 'name' => '{{ var.name }}' ] ],
			[ 'site' => [ 'url' => 'https://example.com/' ], 'option' => [ 'brand' => 'Example Studio' ] ],
			[ 'vars' => [ 'base' => 'site.url', 'name' => "var.missing || option.brand" ] ]
		);
		self::assertSame( 'https://example.com/#thing', $ops[0]['node']['@id'] );
		self::assertSame( 'Example Studio', $ops[0]['node']['name'] );
	}

	public function testComparisonsAndConditionLists(): void {
		$data = [ 'post' => [ 'id' => 31 ], 'rankmath' => [ 'titles' => [ 'about' => '31', 'contact' => '13' ] ] ];
		$ops  = $this->render(
			[
				[ '@key' => 'a', '@if' => 'post.id == rankmath.titles.about', '@type' => 'AboutPage', 'name' => 'x' ],
				[ '@key' => 'c', '@if' => 'post.id == rankmath.titles.contact', '@type' => 'ContactPage', 'name' => 'x' ],
				[ '@key' => 'w', '@if' => [ 'post.id != rankmath.titles.about', 'post.id != rankmath.titles.contact' ], '@type' => 'WebPage', 'name' => 'x' ],
			],
			$data
		);
		self::assertSame( [ 'AboutPage' ], array_map( static fn( $o ) => $o['node']['@type'], $ops ) );
		self::assertTrue( $this->evaluate( "option.name | lower == 'refined'", [ 'option' => [ 'name' => 'Refined' ] ] ) );
		self::assertNull( $this->evaluate( "option.name == 'Other'", [ 'option' => [ 'name' => 'Refined' ] ] ) );
	}

	public function testRequestNamespaceReadsTheRequestContext(): void {
		$request = new RequestContext( 5, 'page', 'default', 'about', true, false, false, false, '', '', true );
		$ops     = $this->render(
			[
				[ '@key' => 's', '@if' => 'request.is_search', '@type' => 'SearchResultsPage', 'name' => 'x' ],
				[ '@key' => 'p', '@if' => [ 'request.is_page', 'request.is_front_page | not' ], '@type' => 'WebPage', 'name' => 'x' ],
			],
			[],
			[],
			$request
		);
		self::assertCount( 2, $ops );
		self::assertTrue( $request->toArray()['is_search'] );
		self::assertArrayNotHasKey( 'is_404', $request->toArray() );
	}

	public function testNestedObjectsHonourIf(): void {
		$ops = $this->render(
			[
				[
					'@type' => 'WebPage',
					'name'  => 'Home',
					'about' => [ '@if' => 'option.front', '@id' => 'https://example.com/#organization' ],
					'image' => [ '@if' => 'option.missing', '@id' => 'https://example.com/img.jpg' ],
				],
			],
			[ 'option' => [ 'front' => true ] ]
		);
		self::assertArrayHasKey( 'about', $ops[0]['node'] );
		self::assertArrayNotHasKey( 'image', $ops[0]['node'] );
	}

	public function testNodeLevelEachGivesEveryItemItsOwnKey(): void {
		$ops = $this->render(
			[
				[
					'@each'     => 'acf.reels',
					'@required' => [ 'name' ],
					'@type'     => 'VideoObject',
					'@id'       => 'https://example.com/films/#reel-{{ item.id }}',
					'name'      => '{{ item.title }}',
				],
			],
			[ 'acf' => [ 'reels' => [ [ 'id' => 'a', 'title' => 'One' ], [ 'id' => 'b', 'title' => '' ], [ 'id' => 'c', 'title' => 'Three' ] ] ] ]
		);
		self::assertCount( 2, $ops );
		self::assertNotSame( $ops[0]['key'], $ops[1]['key'] );
		self::assertSame( 'Three', $ops[1]['node']['name'] );
	}

	public function testEachOverAQueryExposesPostAndLoopIndex(): void {
		$ops = $this->render(
			[
				[
					'@type'           => 'ItemList',
					'itemListElement' => [
						'@each'    => [ 'post_type' => 'post' ],
						'@type'    => 'ListItem',
						'position' => '{{ loop.index }}',
						'name'     => '{{ post.title }}',
					],
				],
			],
			[ 'repeat' => [ [ 'title' => 'First' ], [ 'title' => 'Second' ] ] ]
		);
		$items = $ops[0]['node']['itemListElement'];
		self::assertSame( [ 1, 2 ], array_column( $items, 'position' ) );
		self::assertSame( 'Second', $items[1]['name'] );
	}

	public function testListsAreSplicedNotNested(): void {
		$ops = $this->render(
			[
				[
					'@type'  => 'Organization',
					'name'   => 'x',
					'sameAs' => [ '{{ option.facebook }}', '{{ option.profiles | lines }}' ],
					'author' => [
						[ '@each' => 'option.people', '@type' => 'Person', 'name' => '{{ item }}' ],
						[ '@if' => 'option.none', '@type' => 'Organization', 'name' => 'y' ],
					],
				],
			],
			[ 'option' => [ 'facebook' => 'https://facebook.com/x', 'profiles' => "https://a.example/\r\nhttps://b.example/\n", 'people' => [ 'Alex', 'Isaac' ] ] ]
		);
		self::assertSame( [ 'https://facebook.com/x', 'https://a.example/', 'https://b.example/' ], $ops[0]['node']['sameAs'] );
		self::assertSame( [ 'Alex', 'Isaac' ], array_column( $ops[0]['node']['author'], 'name' ) );
	}

	public function testSingleCollapsesAListOfOne(): void {
		$node = [
			'@type'    => 'Organization',
			'name'     => 'x',
			'memberOf' => [ '@each' => 'option.members', '@single' => true, '@type' => 'Organization', 'name' => '{{ item }}' ],
		];
		$one  = $this->render( [ $node ], [ 'option' => [ 'members' => [ 'BALI' ] ] ] );
		self::assertSame( [ '@type' => 'Organization', 'name' => 'BALI' ], $one[0]['node']['memberOf'] );
		$two = $this->render( [ $node ], [ 'option' => [ 'members' => [ 'BALI', 'RHS' ] ] ] );
		self::assertCount( 2, $two[0]['node']['memberOf'] );
	}

	public function testTypeListsAreDeduplicated(): void {
		$ops = $this->render(
			[ [ '@type' => [ "{{ option.type || 'Organization' }}", 'Organization' ], 'name' => 'x' ] ],
			[ 'option' => [ 'type' => 'Organization' ] ]
		);
		self::assertSame( 'Organization', $ops[0]['node']['@type'] );
		$ops = $this->render(
			[ [ '@type' => [ "{{ option.type || 'Organization' }}", 'Organization' ], 'name' => 'x' ] ],
			[ 'option' => [ 'type' => 'HomeAndConstructionBusiness' ] ]
		);
		self::assertSame( [ 'HomeAndConstructionBusiness', 'Organization' ], $ops[0]['node']['@type'] );
	}

	public function testTextFilters(): void {
		$data = [ 'option' => [ 'geo' => '51.9670911,-0.2854595', 'byline' => 'Nicola and Jacob, Directors', 'solo' => 'Alex' ] ];
		self::assertSame( '51.9670911', $this->evaluate( "option.geo | before:','", $data ) );
		self::assertSame( '-0.2854595', $this->evaluate( "option.geo | after:','", $data ) );
		self::assertSame( 'https://www.google.com/maps/search/?api=1&query=51.9670911,-0.2854595', $this->evaluate( 'option.geo | map_url', $data ) );
		self::assertSame( [ 'Nicola', 'Jacob' ], $this->evaluate( "option.byline | before:',' | split:' and '", $data ) );
		self::assertSame( 'Directors', $this->evaluate( "option.byline | after:','", $data ) );
		self::assertSame( 'Alex', $this->evaluate( "option.solo | before:','", $data ) );
		self::assertNull( $this->evaluate( "option.solo | after:','", $data ) );
		self::assertTrue( $this->evaluate( 'option.missing | not', $data ) );
		self::assertNull( $this->evaluate( 'option.solo | not', $data ) );
		self::assertSame( '08:00', $this->evaluate( 'option.t | time', [ 'option' => [ 't' => '8:00' ] ] ) );
	}

	public function testListFilters(): void {
		$data = [
			'option' => [
				'awards' => [ [ 'award_text' => 'Award one' ], [ 'award_text' => '' ], [ 'award_text' => 'Award two' ] ],
				'list'   => [ 'a', 'b', 'a' ],
				'row'    => [ 'name' => 'Row', 'deep' => [ 'x' => 1 ] ],
			],
		];
		self::assertSame( [ 'Award one', 'Award two' ], $this->evaluate( 'option.awards | pluck:award_text', $data ) );
		self::assertSame( [ 'a', 'b' ], $this->evaluate( 'option.list | unique', $data ) );
		self::assertSame( 'a', $this->evaluate( 'option.list | last', $data ) );
		self::assertSame( 1, $this->evaluate( 'option.row | key:deep.x', $data ) );
	}

	public function testOpeningHoursGroupsDaysBySpan(): void {
		$rows  = [
			[ 'day' => 'Monday', 'time' => '08:00-17:00' ],
			[ 'day' => 'Tuesday', 'time' => '8:00 - 17:00' ],
			[ 'day' => 'Saturday', 'time' => '09:00-12:00' ],
			[ 'day' => 'Funday', 'time' => '09:00-12:00' ],
			[ 'day' => 'Sunday', 'time' => 'closed' ],
		];
		$spans = Expression::openingHours( $rows );
		self::assertSame(
			[
				[ 'days' => [ 'Monday', 'Tuesday' ], 'opens' => '08:00', 'closes' => '17:00' ],
				[ 'days' => [ 'Saturday' ], 'opens' => '09:00', 'closes' => '12:00' ],
			],
			$spans
		);
	}

	public function testOutputAndVarsAreValidatedAndExported(): void {
		$template = Template::fromArray(
			[
				'name'    => 't',
				'output'  => 'standalone',
				'vars'    => [ 'image' => 'post.featured_image_id' ],
				'targets' => [ [ 'type' => 'all' ] ],
				'nodes'   => [ [ '@type' => 'Thing', 'name' => 'x' ] ],
			]
		);
		self::assertTrue( $template->runsIn( 'standalone' ) );
		self::assertFalse( $template->runsIn( 'rankmath' ) );
		self::assertSame( 'standalone', $template->toArray()['output'] );
		self::assertSame( [ 'image' => 'post.featured_image_id' ], $template->toArray()['vars'] );
		$plain = Template::fromArray( [ 'name' => 't', 'targets' => [ [ 'type' => 'all' ] ], 'nodes' => [ [ '@type' => 'Thing', 'name' => 'x' ] ] ] );
		self::assertTrue( $plain->runsIn( 'rankmath' ) );
		self::assertArrayNotHasKey( 'output', $plain->toArray() );
		$this->expectException( InvalidArgumentException::class );
		Template::fromArray( [ 'name' => 't', 'output' => 'sometimes', 'nodes' => [] ] );
	}

	public function testBadVariableNamesAreRejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Template::fromArray( [ 'name' => 't', 'vars' => [ 'bad name' => 'x' ], 'nodes' => [] ] );
	}
}
