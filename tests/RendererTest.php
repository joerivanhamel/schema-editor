<?php
/**
 * Renderer and Expression behaviour: the rules that keep the output clean.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Tests;

use JoeriVanhamel\SchemaEditor\Render\ArrayResolver;
use JoeriVanhamel\SchemaEditor\Render\Context;
use JoeriVanhamel\SchemaEditor\Render\Renderer;
use JoeriVanhamel\SchemaEditor\Template\Template;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase {

	/**
	 * @param array<string,mixed> $data Resolver data.
	 */
	private function render( array $nodes, array $data = [], ?Context $context = null ): array {
		$template = Template::fromArray( [ 'name' => 't', 'targets' => [ [ 'type' => 'all' ] ], 'nodes' => $nodes ] );
		$renderer = new Renderer( new ArrayResolver( $data ) );
		return $renderer->render( $template, $context ?? new Context( $data['post'] ?? null ) );
	}

	private function post(): array {
		return [
			'title'     => 'Consulting &amp; Advice',
			'permalink' => 'https://example.com/consulting/',
			'excerpt'   => '',
			'date'      => '2026-06-10T14:11:27+01:00',
		];
	}

	public function testPlaceholdersResolveAndEntitiesDecode(): void {
		$ops = $this->render(
			[ [ '@type' => 'Service', '@id' => '{{post.permalink}}#service', 'name' => '{{post.title}}' ] ],
			[ 'post' => $this->post() ]
		);
		self::assertCount( 1, $ops );
		self::assertSame( 'add', $ops[0]['op'] );
		self::assertSame( 'Service', $ops[0]['key'] );
		self::assertSame( 'https://example.com/consulting/#service', $ops[0]['node']['@id'] );
		self::assertSame( 'Consulting & Advice', $ops[0]['node']['name'] );
	}

	public function testEmptyPropertiesAreOmitted(): void {
		$ops  = $this->render(
			[ [ '@type' => 'Service', 'name' => '{{post.title}}', 'description' => '{{post.excerpt}}', 'image' => '{{acf.missing}}' ] ],
			[ 'post' => $this->post() ]
		);
		$node = $ops[0]['node'];
		self::assertArrayHasKey( 'name', $node );
		self::assertArrayNotHasKey( 'description', $node );
		self::assertArrayNotHasKey( 'image', $node );
	}

	public function testMixedStringWithMissingPlaceholderIsOmitted(): void {
		$ops = $this->render(
			[ [ '@type' => 'Thing', 'name' => 'x', 'url' => '{{post.permalink}}#{{acf.missing}}' ] ],
			[ 'post' => $this->post() ]
		);
		self::assertArrayNotHasKey( 'url', $ops[0]['node'] );
	}

	public function testAlternativesTakeTheFirstNonEmpty(): void {
		$ops = $this->render(
			[ [ '@type' => 'Thing', 'description' => '{{ acf.project_excerpt || rankmath.description || acf.seo_intro }}' ] ],
			[ 'post' => $this->post(), 'acf' => [ 'project_excerpt' => '', 'seo_intro' => 'Intro' ], 'rankmath' => [ 'description' => '  Meta <b>desc</b>  ' ] ]
		);
		self::assertSame( 'Meta desc', $ops[0]['node']['description'] );
	}

	public function testLiteralAlternative(): void {
		$ops = $this->render( [ [ '@type' => 'Thing', 'name' => "{{acf.nothing || 'Fallback name'}}" ] ] );
		self::assertSame( 'Fallback name', $ops[0]['node']['name'] );
	}

	public function testRequiredDropsTheNode(): void {
		$ops = $this->render( [ [ '@type' => 'VideoObject', '@required' => [ 'name', 'thumbnailUrl' ], 'name' => 'Film', 'thumbnailUrl' => '{{acf.still|image}}' ] ] );
		self::assertSame( [], $ops );
	}

	public function testIfSkipsTheNode(): void {
		$data = [ 'acf' => [ 'video_id' => '' ] ];
		self::assertSame( [], $this->render( [ [ '@if' => 'acf.video_id', '@type' => 'VideoObject', 'name' => 'x' ] ], $data ) );
		$data['acf']['video_id'] = 'abc';
		self::assertCount( 1, $this->render( [ [ '@if' => 'acf.video_id', '@type' => 'VideoObject', 'name' => 'x' ] ], $data ) );
	}

	public function testIfAcceptsBracesAroundTheExpression(): void {
		$data = [ 'acf' => [ 'video_url' => 'https://youtu.be/RPX44L1DfQU' ] ];
		self::assertCount( 1, $this->render( [ [ '@if' => '{{ acf.video_url|youtube_id }}', '@type' => 'VideoObject', 'name' => 'x' ] ], $data ) );
		self::assertCount( 1, $this->render( [ [ '@if' => 'acf.video_url|youtube_id', '@type' => 'VideoObject', 'name' => 'x' ] ], $data ) );
		self::assertSame( [], $this->render( [ [ '@if' => '{{ acf.missing|youtube_id }}', '@type' => 'VideoObject', 'name' => 'x' ] ], $data ) );
	}

	public function testFilters(): void {
		$data = [
			'acf'  => [ 'project_year' => 'Completed 2024', 'gd_hero_image' => [ 'url' => 'https://example.com/hero.jpg', 'ID' => 5 ], 'video_id' => 'RPX44L1DfQU', 'len' => '1:24' ],
			'post' => [ 'title' => 'Alex Haerle' ],
		];
		$ops  = $this->render(
			[
				[
					'@type'    => 'Thing',
					'year'     => '{{acf.project_year|year}}',
					'image'    => '{{acf.*_hero_image|image:large}}',
					'first'    => '{{post.title|first_word}}',
					'slug'     => '{{post.title|slug}}',
					'embed'    => '{{acf.video_id|youtube_embed}}',
					'duration' => '{{acf.len|iso_duration}}',
					'count'    => '{{acf.missing|count}}',
				],
			],
			$data
		);
		$node = $ops[0]['node'];
		self::assertSame( '2024', $node['year'] );
		self::assertSame( 'https://example.com/hero.jpg', $node['image'] );
		self::assertSame( 'Alex', $node['first'] );
		self::assertSame( 'alex-haerle', $node['slug'] );
		self::assertSame( 'https://www.youtube-nocookie.com/embed/RPX44L1DfQU', $node['embed'] );
		self::assertSame( 'PT1M24S', $node['duration'] );
		self::assertSame( 0, $node['count'] );
	}

	public function testYoutubeFiltersAcceptIdsAndUrls(): void {
		$data = [ 'acf' => [ 'url' => 'https://www.youtube.com/watch?v=RPX44L1DfQU&t=3s', 'short' => 'https://youtu.be/RPX44L1DfQU', 'bad' => 'https://vimeo.com/123' ] ];
		$ops  = $this->render(
			[ [ '@type' => 'VideoObject', 'a' => '{{acf.url|youtube_id}}', 'b' => '{{acf.short|youtube_embed}}', 'c' => '{{acf.url|youtube_watch}}', 'd' => '{{acf.bad|youtube_embed}}' ] ],
			$data
		);
		$node = $ops[0]['node'];
		self::assertSame( 'RPX44L1DfQU', $node['a'] );
		self::assertSame( 'https://www.youtube-nocookie.com/embed/RPX44L1DfQU', $node['b'] );
		self::assertSame( 'https://www.youtube.com/watch?v=RPX44L1DfQU', $node['c'] );
		self::assertArrayNotHasKey( 'd', $node, 'a non-YouTube URL yields nothing rather than a broken embed URL' );
	}

	public function testSinglePlaceholderKeepsArrayType(): void {
		$ops = $this->render(
			[ [ '@type' => 'Thing', 'keywords' => '{{terms.feature}}', 'joined' => "{{terms.feature|join:', '}}", 'bare' => '{{terms.feature|join}}' ] ],
			[ 'terms' => [ 'feature' => [ 'Pond', 'Water &amp; Stone' ] ] ]
		);
		self::assertSame( [ 'Pond', 'Water & Stone' ], $ops[0]['node']['keywords'] );
		self::assertSame( 'Pond, Water & Stone', $ops[0]['node']['joined'] );
		self::assertSame( 'Pond, Water & Stone', $ops[0]['node']['bare'] );
	}

	public function testEachBuildsAList(): void {
		$ops = $this->render(
			[
				[
					'@type'      => 'Service',
					'name'       => 'x',
					'areaServed' => [ '@each' => 'option.areas_served', '@type' => '{{item.area_type}}', 'name' => '{{item.area_name}}' ],
				],
			],
			[ 'option' => [ 'areas_served' => [ [ 'area_name' => 'Example County', 'area_type' => 'AdministrativeArea' ], [ 'area_name' => '', 'area_type' => 'Place' ] ] ] ]
		);
		self::assertSame( [ [ '@type' => 'AdministrativeArea', 'name' => 'Example County' ] ], $ops[0]['node']['areaServed'] );
	}

	public function testEmptyEachIsOmitted(): void {
		$ops = $this->render( [ [ '@type' => 'Service', 'name' => 'x', 'areaServed' => [ '@each' => 'option.none', 'name' => '{{item.area_name}}' ] ] ] );
		self::assertArrayNotHasKey( 'areaServed', $ops[0]['node'] );
	}

	public function testObjectWithOnlyTypeIsOmitted(): void {
		$ops = $this->render( [ [ '@type' => 'Thing', 'name' => 'x', 'location' => [ '@type' => 'Place', 'name' => '{{acf.missing}}' ] ] ] );
		self::assertArrayNotHasKey( 'location', $ops[0]['node'] );
	}

	public function testRepeatRendersOnePerItemWithUniqueKeys(): void {
		$page = [ 'permalink' => 'https://example.com/about/meet-the-team/' ];
		$ops  = $this->render(
			[
				[
					'@repeat'  => [ 'post_type' => 'team_member' ],
					'@type'    => 'Person',
					'@id'      => '{{parent.permalink}}#{{post.title|first_word|slug}}',
					'name'     => '{{post.title|first_word}}',
					'jobTitle' => '{{acf.team_role}}',
				],
			],
			[ 'post' => $page, 'repeat' => [ [ 'title' => 'Alex Haerle' ], [ 'title' => 'Jon Dundon' ] ] ]
		);
		self::assertCount( 2, $ops );
		self::assertSame( 'https://example.com/about/meet-the-team/#alex', $ops[0]['node']['@id'] );
		self::assertSame( 'https://example.com/about/meet-the-team/#jon', $ops[1]['node']['@id'] );
		self::assertNotSame( $ops[0]['key'], $ops[1]['key'] );
	}

	public function testMergeAndRemoveOperations(): void {
		$ops = $this->render(
			[
				[ '@op' => 'merge', '@match' => '#webpage', 'mainEntity' => [ '@id' => '{{post.permalink}}#service' ] ],
				[ '@op' => 'remove', '@match' => '#organization', 'properties' => [ 'aggregateRating', 'review' ] ],
				[ '@op' => 'merge', '@match' => '#webpage', 'video' => [ '@id' => '{{acf.missing}}' ] ],
			],
			[ 'post' => $this->post() ]
		);
		self::assertCount( 2, $ops, 'a merge with nothing to merge is dropped' );
		self::assertSame( [ 'op' => 'merge', 'match' => '#webpage', 'properties' => [ 'mainEntity' => [ '@id' => 'https://example.com/consulting/#service' ] ] ], $ops[0] );
		self::assertSame( [ 'op' => 'remove', 'match' => '#organization', 'properties' => [ 'aggregateRating', 'review' ] ], $ops[1] );
	}

	public function testExplicitKeyWithPlaceholder(): void {
		$ops = $this->render( [ [ '@key' => 'Video_{{acf.video_id}}', '@type' => 'VideoObject', 'name' => 'x' ] ], [ 'acf' => [ 'video_id' => 'abc' ] ] );
		self::assertSame( 'Video_abc', $ops[0]['key'] );
	}
}
