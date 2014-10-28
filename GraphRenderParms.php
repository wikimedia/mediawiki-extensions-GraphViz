<?php
/**
 * Extension to allow Graphviz to work inside MediaWiki.
 * See mediawiki.org/wiki/Extension:GraphViz for more information
 *
 * @section LICENSE
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Keith Welter et al.
 * @ingroup Extensions
 */

/**
 * A convenience class for holding the parameters pertaining to graph rendering using dot or mscgen.
 * @author Keith Welter
 * @ingroup Extensions
 */
class GraphRenderParms {
	private $graphName = '';
	private $userName = '';
	private $renderer = '';

	/**
	 * The graph image type to output.
	 * @see http://www.graphviz.org/doc/info/output.html
	 * @var string $imageType
	 */
	private $imageType = '';

	/**
	 * A subset of the dot supported image types that are supported by the GraphViz extension.
	 * @see http://www.graphviz.org/doc/info/output.html
	 * @var array $supportedDotImageTypes
	 */
	public static $supportedDotImageTypes = array(
		'gif',
		'jpg',
		'jpeg',
		'png',
		'svg'
	);

	/**
	 * A subset of the mscgen supported image types that are supported by the GraphViz extension.
	 * @see http://www.mcternan.me.uk/mscgen/
	 * @var array $supportedMscgenImageTypes
	 */
	private static $supportedMscgenImageTypes = array(
		'png',
		'svg'
	);

	private $mapType = '';
	private $execPath = '';
	private $renderCommand = '';
	private $imageCommand = '';
	private $mapCommand = '';
	private $sourceAndMapDir = '';
	private $imageDir = '';

	public function __construct( $renderer, $graphName, $userName, $imageType, $sourceAndMapDir, $imageDir ) {
		global $wgGraphVizSettings;

		$this->graphName = $graphName;
		$this->userName = $userName;
		$this->renderer = $renderer;
		$this->sourceAndMapDir = $sourceAndMapDir;
		$this->imageDir = $imageDir;
		$this->execPath = $wgGraphVizSettings->execPath;

		wfDebug( __METHOD__ . ": userName: $userName graphName: $graphName\n" );
		wfDebug( __METHOD__ . ": sourceAndMapDir: $sourceAndMapDir imageDir: $imageDir\n" );
		wfDebug( __METHOD__ . ": renderer: $renderer imageType: $imageType\n" );

		switch( $renderer ) {
			case 'circo':
			case 'dot':
			case 'fdp':
			case 'sfdp':
			case 'neato':
			case 'twopi':
				$this->mapType = 'cmapx';
				break;
			case 'mscgen':
				$this->mapType = 'ismap';
				$this->execPath = $wgGraphVizSettings->mscgenPath;
				break;
			default:
				$this->renderer = 'dot';
				$this->mapType = 'cmapx';
		}

		// set this->imageType to a type supported for the given renderer
		if ( $this->renderer != 'mscgen' ) {
			if ( in_array( $imageType, self::$supportedDotImageTypes ) ) {
				$this->imageType = $imageType;
			} else {
				wfDebug( __METHOD__ . ": unsupported dot imageType: $imageType; using png\n" );
				$this->imageType = 'png';
			}
		} else {
			if ( in_array( $imageType, self::$supportedMscgenImageTypes ) ) {
				$this->imageType = $imageType;
			} else {
				wfDebug( __METHOD__ . ": unsupported mscgen imageType: $imageType; using png\n" );
				$this->imageType = 'png';
			}
		}

		// create the command for graphviz or mscgen
		$this->renderCommand = $this->execPath . $this->renderer;
		if ( wfIsWindows() ) {
			$this->renderCommand = $this->renderCommand . '.exe';
		}
	}

	public function getRenderer() {
		return $this->renderer;
	}

	public function getImageCommand( $userSpecific ) {
		if ( $this->imageCommand == '' ) {
			$this->imageCommand = wfEscapeShellArg( $this->renderCommand ) . ' -T ' . wfEscapeShellArg( $this->imageType ) .
				' -o ' . wfEscapeShellArg( $this->getImagePath( $userSpecific ) ) . ' ' .
				wfEscapeShellArg( $this->getSourcePath( $userSpecific ) );
		}
		return $this->imageCommand;
	}

	public function getMapCommand( $userSpecific ) {
		if ( $this->mapCommand == '' ) {
			$this->mapCommand = wfEscapeShellArg( $this->renderCommand ) . ' -T ' . wfEscapeShellArg( $this->mapType ) .
				' -o ' . wfEscapeShellArg( $this->getMapPath( $userSpecific ) ) . ' ' .
				wfEscapeShellArg( $this->getSourcePath( $userSpecific ) );
		}
		return $this->mapCommand;
	}

	public function getGraphName( $userSpecific ) {
		if ( $userSpecific ) { return $this->graphName . '_' . $this->userName; }
		return $this->graphName;
	}

	public function getSourceFileName( $userSpecific ) {
		return $this->getGraphName( $userSpecific ) . '.src';
	}

	public function getImageBaseName( $userSpecific ) {
		return $this->getGraphName( $userSpecific ) . '_' . $this->getRenderer();
	}

	public function getImageFileName( $userSpecific ) {
		return $this->getImageBaseName( $userSpecific ) . '.' . $this->imageType;
	}

	public function getMapFileName( $userSpecific ) {
		return $this->getImageBaseName( $userSpecific ) . '.map';
	}

	public function getSourcePath( $userSpecific ) {
		return $this->sourceAndMapDir . $this->getSourceFileName( $userSpecific );
	}

	public function getImagePath( $userSpecific ) {
		return $this->imageDir . $this->getImageFileName( $userSpecific );
	}

	public function getMapPath( $userSpecific ) {
		return $this->sourceAndMapDir . $this->getMapFileName( $userSpecific );
	}

	public function deleteFiles( $userSpecific ) {
		self::unlinkIfFileExists( $this->getSourcePath( $userSpecific ) );
		self::unlinkIfFileExists( $this->getImagePath( $userSpecific ) );
		self::unlinkIfFileExists( $this->getMapPath( $userSpecific ) );
	}

	protected static function unlinkIfFileExists( $path ) {
		// prevent directory traversal
		if ( strpos( $path, "../" ) !== false ) {
			throw new MWException( "directory traversal detected in $path" );
		}

		if ( file_exists( $path ) ) {
			if ( unlink( $path ) ) {
				wfDebug( __METHOD__ . ": unlinked $path\n" );
			} else {
				wfDebug( __METHOD__ . ": unlink($path) failed\n" );
				return false;
			}
		}
		return true;
	}
}

/**#@-*/

