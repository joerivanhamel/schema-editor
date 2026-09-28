<?php
/**
 * Targets and Template parsing.
 *
 * @package SchemaEditor
 */

declare(strict_types=1);

namespace JoeriVanhamel\SchemaEditor\Tests;

use InvalidArgumentException;
use JoeriVanhamel\SchemaEditor\Template\RequestContext;
use JoeriVanhamel\SchemaEditor\Template\Targets;
use JoeriVanhamel\SchemaEditor\Template\Template;
use PHPUnit\Framework\TestCase;

final class TargetsTest extends TestCase {

	private function template( array $targets, array $exclude = [], bool $enabled = true ): Template {
		return Template::fromArray( [ 'name' => 't', 'enabled' => $enabled, 'targets' => $targets, 'exclude' => $exclude, 'nodes' => [ [ '@type' => 'Thing', 'name' => 'x' ] ] ] );
	}

	public function testPageTemplateAndPostType(): void {
		$request = new RequestContext( postId: 6, postType: 'page', pageTemplate: 'page-garden-design.php', slug: 'garden-design', isSingular: true, terms: [ 'category:design-build' ] );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'page_template', 'value' => 'page-garden-design.php' ] ] ), $request ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'page_template', 'value' => 'templates/page-garden-design.php' ] ] ), $request ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'post_type', 'value' => 'page' ] ] ), $request ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'post', 'value' => '6' ] ] ), $request ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'slug', 'value' => 'garden-design' ] ] ), $request ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'term', 'value' => 'category:design-build' ] ] ), $request ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'post_type', 'value' => 'post' ] ] ), $request ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'front_page' ] ] ), $request ) );
	}

	public function testExcludeWinsAndDisabledNeverApplies(): void {
		$request = new RequestContext( postId: 52, postType: 'page', pageTemplate: 'page-expertise.php', slug: 'services', isSingular: true );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'post_type', 'value' => 'page' ] ], [ [ 'type' => 'slug', 'value' => 'services' ] ] ), $request ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'all' ] ], [], false ), $request ) );
	}

	public function testArchives(): void {
		$archive = new RequestContext( postType: 'portfolio_project', isPostTypeArchive: true );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'post_type_archive', 'value' => 'portfolio_project' ] ] ), $archive ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'post_type', 'value' => 'portfolio_project' ] ] ), $archive ) );
		$category = new RequestContext( taxonomy: 'category', termSlug: 'design-build' );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'taxonomy', 'value' => 'category' ] ] ), $category ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'all' ] ] ), new RequestContext( is404: true ) ) );
	}

	public function testAuthorArchives(): void {
		$archive = new RequestContext( isAuthor: true, authorId: 7, authorLogin: 'alex' );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'author' ] ] ), $archive ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'author', 'value' => 'alex' ] ] ), $archive ) );
		self::assertTrue( Targets::applies( $this->template( [ [ 'type' => 'author', 'value' => 7 ] ] ), $archive ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'author', 'value' => 'jon' ] ] ), $archive ) );
		self::assertFalse( Targets::applies( $this->template( [ [ 'type' => 'author' ] ] ), new RequestContext( postId: 3, postType: 'post', isSingular: true ) ) );
	}

	public function testInvalidDocuments(): void {
		$this->expectException( InvalidArgumentException::class );
		Template::fromJson( '{"nodes": "no"}' );
	}

	public function testUnknownTargetType(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/unknown type "galaxy"/' );
		$this->template( [ [ 'type' => 'galaxy' ] ] );
	}

	public function testMergeNeedsMatch(): void {
		$this->expectException( InvalidArgumentException::class );
		Template::fromArray( [ 'nodes' => [ [ '@op' => 'merge', 'name' => 'x' ] ] ] );
	}

	public function testRoundTrip(): void {
		$document = [ 'name' => 'Service page', 'enabled' => false, 'priority' => 20, 'targets' => [ [ 'type' => 'all', 'value' => null ] ], 'exclude' => [], 'nodes' => [ [ '@type' => 'Service', 'name' => '{{post.title}}' ] ] ];
		self::assertSame( $document, Template::fromArray( $document )->toArray() );
	}
}
