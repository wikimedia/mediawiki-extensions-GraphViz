<?php

namespace MediaWiki\Extension\GraphViz\Test;

use MediaWiki\Extension\GraphViz\GraphRenderParms;
use MediaWiki\Extension\GraphViz\GraphViz;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use MediaWikiTestCase;
use ParserOptions;
use ReflectionClass;
use WikiPage;

/**
 * @group GraphViz
 * @group Database
 */
class GraphVizTest extends MediaWikiTestCase {

	protected function setUp() : void {
		parent::setUp();
		$this->setMwGlobals( 'wgEnableUploads', true );
	}

	public function skipIfDotNotAvailable() {
		// Cannot use "which" command, as it doesn't exist in windows.
		// -V is for version info.
		if ( Shell::command( 'dot', '-V' )->execute()->getExitCode() ) {
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

		// Test image node with a label. See bug T207248.
		// Using previous graph image as input for this one.
		$this->setMwGlobals( 'wgUser', $user );
		$imageNode = 'A[image="GraphViz_test_2_digraph_testGraph_dot.png", label="test"];';
		$dotSource3 = "<graphviz>digraph testGraph{ {$imageNode} }</graphviz>";
		$testTitle3 = $this->insertPage( 'GraphViz test 3', $dotSource3 );
		$testPage3 = new WikiPage( $testTitle3['title'] );
		$this->assertRegExp(
			'|src=".*/0/0d/GraphViz_test_3_digraph_testGraph_dot.png"|',
			$testPage3->getParserOutput( $parserOptions1 )->getText()
		);
		$this->assertFileExists( $uploadDir . "/0/0d/GraphViz_test_3_digraph_testGraph_dot.png" );
	}

	/**
	 * Tests that render parameters reflect the extension settings.
	 * @covers \MediaWiki\Extension\GraphViz\GraphRenderParms::__construct
	 */
	public function testRenderParams() {
		$this->setMwGlobals( 'wgGraphVizExecPath', '/usr/test/path/' );

		$class = new ReflectionClass( GraphRenderParms::class );

		// The only parameter affecting the render command is the renderer.
		// The rest of the parameters only affect the command arguments.
		$renderer = 'dot';
		$instance = new GraphRenderParms( $renderer, '', '', '', '', '' );

		$property = $class->getProperty( 'renderCommand' );
		$property->setAccessible( true );
		$result = $property->getValue( $instance );

		if ( wfIsWindows() ) {
			$this->assertEquals( '/usr/test/path/dot.exe', $result );
		} else {
			$this->assertEquals( '/usr/test/path/dot', $result );
		}
	}
}
