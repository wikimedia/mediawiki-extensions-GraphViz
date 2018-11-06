<?php

namespace MediaWiki\Extension\GraphViz;

use MediaWiki\MediaWikiServices;

/**
 * The GraphViz settings class.
 */
class Settings {
	/**
	 * dot executable path
	 * Windows Default: C:/Programme/ATT/Graphviz/bin/
	 * Other Platform : /usr/local/bin/dot
	 *
	 * '/' will be converted to '\\' later on, so feel free how to write your path C:/ or C:\\
	 *
	 * @var string $execPath
	 */
	public $execPath;

	/**
	 * mscgen executable path
	 * Commonly '/usr/bin/', '/usr/local/bin/' or (if set) '$DOT_PATH/'.
	 *
	 * '/' will be converted to '\\' later on, so feel free how to write your path C:/ or C:\\
	 *
	 * @var string $mscgenPath
	 */
	public $mscgenPath;

	/**
	 * default image type for the output of dot or mscgen
	 * The "default default" is png.
	 *
	 * @var string $defaultImageType
	 */
	public $defaultImageType;

	/**
	 * Whether or not to automatically create category pages for images created by this extension.
	 * yes|no (case insensitive). The default is no.
	 *
	 * @var string $createCategoryPages
	 */
	public $createCategoryPages;

	/**
	 * Constructor for setting configuration variable defaults.
	 */
	public function __construct() {
		$settings = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'GraphViz' );

		// Path to GraphViz executables
		// Since default depends on OS, the default value on extension.json
		// is null. This way, if for any reason someone wants to have
		// the path be '/usr/bin/' on windows, they can just set it as such.
		$execPath = $settings->get( 'GraphVizExecPath' );
		if ( $execPath !== null && $execPath !== '' ) {
			$this->execPath = $execPath;
		} elseif ( wfIsWindows() ) {
			$this->execPath = 'C:\\Program Files\\Graphviz\\bin\\';
		} else {
			$this->execPath = '/usr/bin/';
		}

		$this->mscgenPath = $settings->get( 'GraphVizMscgenPath' );
		$this->defaultImageType = $settings->get( 'GraphVizDefaultImageType' );

		// TODO: currently not used
		$this->createCategoryPages = 'no';
	}
}
