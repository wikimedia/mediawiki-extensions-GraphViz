<?php

namespace MediaWiki\Extension\GraphViz\Test;

use MediaWiki\Extension\GraphViz\GraphViz;
use MediaWikiTestCase;
use ReflectionClass;

/**
 *  @group GraphViz
 */
class GraphVizTest extends MediaWikiTestCase {
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
}
