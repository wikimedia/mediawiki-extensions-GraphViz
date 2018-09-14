<?php

namespace MediaWiki\Extension\GraphViz\Test;

use MediaWiki\Extension\GraphViz\GraphViz;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWikiTestCase;
use ParserOptions;
use ReflectionClass;
use WikiPage;

/**
 *  @group GraphViz
 *  @group Database
 */
class GraphVizTest extends MediaWikiTestCase {

	protected function setUp() {
		parent::setUp();
		$this->setMwGlobals( 'wgEnableUploads', true );
	}

	public function skipIfDotNotAvailable() {
		if ( Shell::command( 'which', 'dot' )->execute()->getExitCode() ) {
			$this->markTestSkipped( 'Graphviz is not installed. Can not find "dot"' );
		}
	}

	protected static function getGraphVizMethod( $name ) {
		$class = new ReflectionClass( GraphViz::class );
		$method = $class->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * @covers \MediaWiki\Extension\GraphViz\GraphViz::sanitizeDotInput
	 */
	public function testForbiddenDotAttributes() {
		$sanitizeDotInput = self::getGraphVizMethod( 'sanitizeDotInput' );
		$graphviz = new GraphViz();

		$errorText = "";
		$input = 'digraph graphName { node [imagepath="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, [ &$input, &$errorText ] );
		$this->assertFalse( $result, "imagepath should be rejected" );

		$input = 'digraph graphName { node [fontpath="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, [ &$input, &$errorText ] );
		$this->assertFalse( $result, "fontpath should be rejected" );

		$input = 'digraph graphName { node [shapefile="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, [ &$input, &$errorText ] );
		$this->assertFalse( $result, "shapefile should be rejected" );
	}

	/**
	 * @covers \MediaWiki\Extension\GraphViz\GraphViz::render()
	 */
	public function testCreateGraph() {
		$this->skipIfDotNotAvailable();

		$uploadDir = MediaWikiServices::getInstance()->getMainConfig()->get( 'UploadDirectory' );
		$dotSource = '<graphviz>digraph testGraph { A -> B }</graphviz>';

		// First try as the test user.
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();
		$parserOptions1 = ParserOptions::newCanonical( $user );
		$this->setMwGlobals( 'wgUser', $user );
		$testTitle = $this->insertPage( 'GraphViz test 1', $dotSource );
		$testPage = new WikiPage( $testTitle['title'] );
		$this->assertRegExp(
			'|src=".*/6/6c/GraphViz_test_1_digraph_testGraph_dot.png"|',
			$testPage->getParserOutput( $parserOptions1 )->getText()
		);
		$this->assertFileExists( $uploadDir . "/6/6c/GraphViz_test_1_digraph_testGraph_dot.png" );

		// Then as anon.
		$parserOptions2 = ParserOptions::newFromAnon();
		$this->setMwGlobals( 'wgUser', $parserOptions2->getUser() );
		$testTitle2 = $this->insertPage( 'GraphViz test 2', $dotSource );
		$testPage2 = new WikiPage( $testTitle2['title'] );
		$this->assertRegExp(
			'|src=".*/3/3b/GraphViz_test_2_digraph_testGraph_dot.png"|',
			$testPage2->getParserOutput( $parserOptions2, null, true )->getText()
		);
		$this->assertFileExists( $uploadDir . "/3/3b/GraphViz_test_2_digraph_testGraph_dot.png" );
	}
}
