<?php

/**
 *  @group GraphViz
 */
class GraphVizTest extends MediaWikiTestCase {
	protected function setUp() {
		parent::setUp();
		//...GraphVizTest set-up
	}

	protected function tearDown() {
		//GraphVizTest tear-down...
		parent::tearDown();
	}

	protected static function getGraphVizMethod( $name ) {
		$class = new ReflectionClass('GraphViz');
		$method = $class->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	public function testForbiddenDotAttributes() {
		$sanitizeDotInput = self::getGraphVizMethod( 'sanitizeDotInput' );
		$graphviz = new GraphViz();

		$errorText = "";
		$input = 'digraph graphName { node [imagepath="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, array( &$input, &$errorText) );
		$this->assertFalse( $result, "imagepath should be rejected" );

		$input = 'digraph graphName { node [fontpath="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, array( &$input, &$errorText) );
		$this->assertFalse( $result, "fontpath should be rejected" );

		$input = 'digraph graphName { node [shapefile="../"]; }';
		$result = $sanitizeDotInput->invokeArgs( $graphviz, array( &$input, &$errorText) );
		$this->assertFalse( $result, "shapefile should be rejected" );
	}
}
