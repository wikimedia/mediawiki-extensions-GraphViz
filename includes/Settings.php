<?php

namespace MediaWiki\Extension\GraphViz;

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
		// Set execution path
		if ( stristr( PHP_OS, 'WIN' ) && !stristr( PHP_OS, 'Darwin' ) ) {
			$this->execPath = 'C:/Program Files/Graphviz/bin/';
		} else {
			$this->execPath = '/usr/bin/';
		}

		$this->mscgenPath = '';
		$this->defaultImageType = 'png';
		$this->createCategoryPages = 'no';
	}
}
