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
 * @section Configuration
 * These settings can be overwritten in LocalSettings.php.
 * Configuration must be done AFTER including this extension using
 * require("extensions/Graphviz.php");
 * - $wgGraphVizSettings->execPath
 * - $wgGraphVizSettings->mscgenPath
 * - $wgGraphVizSettings->defaultImageType
 *
 * @file
 * @ingroup Extensions
 */

if ( !defined( 'MEDIAWIKI' ) ) die();

define( 'GraphViz_VERSION', '1.6.0' );

/**
 * The GraphViz settings class.
 */
class GraphVizSettings {
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
};

$GLOBALS['wgGraphVizSettings'] = new GraphVizSettings();

//self executing anonymous function to prevent global scope assumptions
call_user_func( function() {
	$dir = __DIR__ . '/';

	$GLOBALS['wgMessagesDirs']['GraphViz'] = $dir . 'i18n';
	$GLOBALS['wgExtensionMessagesFiles']['GraphViz'] = $dir . 'GraphViz.i18n.php';
	$GLOBALS['wgAutoloadClasses']['GraphViz'] = $dir . "GraphViz_body.php";
	$GLOBALS['wgAutoloadClasses']['GraphRenderParms'] = $dir . "GraphRenderParms.php";
	$GLOBALS['wgAutoloadClasses']['UploadLocalFile'] = $dir . "UploadLocalFile.php";
	$GLOBALS['wgAutoloadClasses']['UploadFromLocalFile'] = $dir . "UploadLocalFile.php";
	$GLOBALS['wgHooks']['ParserFirstCallInit'][] = 'GraphViz::onParserInit';
	$GLOBALS['wgHooks']['OutputPageParserOutput'][] = 'GraphViz::onOutputPageParserOutput';
	$GLOBALS['wgHooks']['ArticleDeleteComplete'][] = 'GraphViz::onArticleDeleteComplete';
	$GLOBALS['wgHooks']['UnitTestsList'][] = 'GraphViz::onUnitTestsList';

	$oldVersion = version_compare( $GLOBALS['wgVersion'], '1.21', '<' );
	if ( $oldVersion ) {
		# Do stuff for MediaWiki 1.20 and older
		$GLOBALS['wgHooks']['ArticleSave'][] = 'GraphViz::onArticleSave';
		$GLOBALS['wgHooks']['ArticleSaveComplete'][] = 'GraphViz::onArticleSaveComplete';
		$GLOBALS['wgHooks']['EditPageGetPreviewText'][] = 'GraphViz::onEditPageGetPreviewText';
	} else {
		# Do stuff for MediaWiki 1.21 and newer
		$GLOBALS['wgHooks']['PageContentSave'][] = 'GraphViz::onPageContentSave';
		$GLOBALS['wgHooks']['PageContentSaveComplete'][] = 'GraphViz::onPageContentSaveComplete';
		$GLOBALS['wgHooks']['EditPageGetPreviewContent'][] = 'GraphViz::onEditPageGetPreviewContent';
	}

	$GLOBALS['wgExtensionCredits']['parserhook'][] = array(
		'name' => 'Graphviz',
		'path' => __FILE__,
		'version' => GraphViz_VERSION,
		'author' => array(
			'[http://wickle.com CoffMan]',
			'[mailto://arno.venner@gmail.com MasterOfDesaster]',
			'[http://hummel-universe.net Thomas Hummel]',
			'[mailto://welterk@gmail.com Keith Welter]'
			),
		'url' => 'https://www.mediawiki.org/wiki/Extension:GraphViz',
		'descriptionmsg' => 'graphviz-desc'
		);
} );
