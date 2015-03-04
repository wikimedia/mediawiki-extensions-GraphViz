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
 * @ingroup Extensions
 */

 /**
  *  This is the principal class of the GraphViz extension, responsible
  *  for graph file management and rendering graph images and maps as HTML.
  *  Graph source, image and map files are saved in the file system in order to avoid
  *  regenerating them every time a page containing a graph is rendered.
  *  The ImageMap extension is used for the rendering of graph images and maps as HTML.
  *
  *  @ingroup Extensions
  */
class GraphViz {
	/**
	 * A regular expression for matching the following ID form in the DOT language:
	 * - "Any string of alphabetic ([a-zA-Z\200-\377]) characters, underscores ('_') or digits ([0-9]), not beginning with a digit"
	 *
	 * @see http://www.graphviz.org/content/dot-language
	 * @var string DOT_ID_STRING
	 */
	const DOT_ID_STRING = '[a-zA-Z\200-\300_]+[a-zA-Z\200-\300_0-9]*';

	/**
	 * A regular expression for matching the following ID form in the DOT language:
	 * - "a numeral [-]?(.[0-9]+ | [0-9]+(.[0-9]*)? )"
	 *
	 * @see http://www.graphviz.org/content/dot-language
	 * @var string DOT_NUMERAL
	 */
	const DOT_NUMERAL = '[-]?(.[0-9]+|[0-9]+(.[0-9]*)?)';

	/**
	 * A regular expression for matching the following ID form in the DOT language:
	 * - "any double-quoted string ("...") possibly containing escaped quotes ('")"
	 *
	 * @see http://www.graphviz.org/content/dot-language
	 * @var string DOT_QUOTED_STRING
	 */
	const DOT_QUOTED_STRING	= '"(.|\")*"';

	/**
	 * A regular expression for matching the following ID form in the DOT language:
	 * - "an HTML string (<...>)"
	 *
	 * @see http://www.graphviz.org/content/dot-language
	 * @var string DOT_HTML_STRING
	 */
	const DOT_HTML_STRING = '<(.*|<.*>)*>';

	/**
	 * A regular expression for matching an IMG SRC attribute in HTML-like labels in the DOT language.
	 *
	 * @see http://www.graphviz.org/content/node-shapes#html (IMG attribute)
	 * @see http://www.graphviz.org/content/dot-language
	 *
	 * @var string DOT_IMG_PATTERN
	 */
	const DOT_IMG_PATTERN = '~(?i)(<img.*)(src)(\s*=\s*)"(.*)"~';

	/**
	 * A subdirectory of $wgUploadDirectory.
	 * It contains graph source and map files and is created with the same
	 * permissions as the $wgUploadDirectory if it does not exist.
	 *
	 * @var string SOURCE_AND_MAP_SUBDIR
	 */
	const SOURCE_AND_MAP_SUBDIR = "/graphviz/";

	/**
	 * A subdirectory of SOURCE_AND_MAP_SUBDIR.
	 * It contains graph image files and is created with the same
	 * permissions as the $wgUploadDirectory if it does not exist.
	 * Files in this directory are removed after they are uploaded.
	 *
	 * @var string IMAGE_SUBDIR
	 */
	const IMAGE_SUBDIR = "images/";

	/**
	 * The basename to use for dummy graph image files.
	 * @see GraphViz::createDummyImageFilePage
	 *
	 * @var string IMAGE_DUMMY
	 */
	const IMAGE_DUMMY = "File_graph_GraphVizExtensionDummy_dot";

	/**
	 * Used as an array key in GraphViz::$graphTypes and other arrays.
	 * It must be a unique value in GraphViz::$graphTypes.
	 *
	 * @var integer GRAPHVIZ
	 */
	const GRAPHVIZ = 0;

	/**
	 * Used as an array key in GraphViz::$graphTypes and other arrays.
	 * It must be a unique value in GraphViz::$graphTypes.
	 *
	 * @var integer MSCGEN
	 */
	const MSCGEN = 1;

	/**
	 * The name of the root category containing pages created by this extension.
	 *
	 * @var integer ROOT_CATEGORY
	 */
	const ROOT_CATEGORY = "GraphViz";

	/**
	 * A list of dot attributes that are forbidden.
	 * @see http://www.graphviz.org/content/attrs#dimagepath
	 * @see http://www.graphviz.org/content/attrs#dshapefile
	 * @see http://www.graphviz.org/content/attrs#dfontpath
	 * @var array $forbiddenDotAttributes
	 */
	private static $forbiddenDotAttributes = array(
		'imagepath',
		'shapefile',
		'fontpath'
	);

	/**
	 * A list of the graph types that this extension supports.
	 * @var array $graphTypes
	 */
	private static $graphTypes = array(
		self::GRAPHVIZ,
		self::MSCGEN
	);

	/**
	 * A list of the tags that this extension supports.
	 * @var array $tags
	 */
	private static $tags = array(
		self::GRAPHVIZ => 'graphviz',
		self::MSCGEN   => 'mscgen',
	);

	/**
	 * A mapping from graph types to graph languages.
	 * @var array $graphLanguages
	 */
	private static $graphLanguages = array(
		self::GRAPHVIZ => 'dot',
		self::MSCGEN   => 'mscgen',
	);

	/**
	 * A mapping from graph types to parser hook functions.
	 * @var array $parserHookFunctions
	 */
	private static $parserHookFunctions = array(
		self::GRAPHVIZ => 'graphvizParserHook',
		self::MSCGEN   => 'mscgenParserHook',
	);

	/**
	 * A two dimensional array of graph related state.
	 * The array keys of the first dimension are the list of article titles being saved.
	 * The array keys of the second dimension are the list of active graph files for a given title being saved.
	 * @var array $titlesBeingSaved
	 */
	private static $titlesBeingSaved = array();

	/**
	 * A variable for temporarily holding a copy of GLOBALS['wgHooks'].
	 * @var $disabledHooks
	 */
	private static $disabledHooks = null;

	/**
	 * Disable all hook functions (GLOBALS['wgHooks']).
	 * @author Keith Welter
	 * @return true upon success, false upon failure.
	 */
	protected static function disableHooks() {
		if ( isset( $GLOBALS['wgHooks'] ) ) {
			if ( isset( self::$disabledHooks ) ) {
				wfDebug( __METHOD__ . ": hooks already disabled\n" );
			} else {
				self::$disabledHooks = $GLOBALS['wgHooks'];
				$GLOBALS['wgHooks'] = null;
				wfDebug( __METHOD__ . ": hooks disabled\n" );
				return true;
			}
		} else {
			wfDebug( __METHOD__ . ": hooks not set\n" );
		}
		return false;
	}

	/**
	 * Re-enable all hook functions (GLOBALS['wgHooks']).
	 * Must be called after GraphViz::disableHooks.
	 * @author Keith Welter
	 * @return true upon success, false upon failure.
	 */
	protected static function enableHooks() {
		if ( isset( self::$disabledHooks ) ) {
			if ( isset( $GLOBALS['wgHooks'] ) ) {
				wfDebug( __METHOD__ . ": hooks are already set - aborting\n" );
			} else {
				$GLOBALS['wgHooks'] = self::$disabledHooks;
				self::$disabledHooks = null;
				wfDebug( __METHOD__ . ": hooks enabled\n" );
				return true;
			}
		} else {
			wfDebug( __METHOD__ . ": hooks not disabled\n" );
		}
		return false;
	}

	/**
	 * @return string regular expression for matching an image attribute in the DOT language.
	 *
	 * @see http://www.graphviz.org/content/attrs#dimage
	 * @see http://www.graphviz.org/content/dot-language
	 */
	protected static function getDotImagePattern() {
		return "~(?i)image\s*=\s*(" . self::DOT_ID_STRING . "|" . self::DOT_NUMERAL . "|" . self::DOT_QUOTED_STRING . "|" .  self::DOT_HTML_STRING . ")~";
	}

	/**
	 * Unit test hook.
	 * @author Keith Welter
	 * @return true
	 */
	public static function onUnitTestsList( &$files ) {
		$files = array_merge( $files, glob( __DIR__ . '/tests/phpunit/*Test.php' ) );
		return true;
	}

	/**
	 * Create dummy file pages for each image type supported by this extension.
	 * @see GraphViz::createDummyImageFilePage
	 * @author Keith Welter
	 */
	public static function initDummyFilePages() {
		global $wgParser;

		if ( $wgParser instanceof StubObject ) {
			$wgParser->_unstub();
		}

		foreach ( GraphRenderParms::$supportedDotImageTypes as $imageType ) {
			if ( !self::imageTypeAllowed( $imageType ) ) {
				wfDebug( __METHOD__ . ": skipping $imageType\n" );
				continue;
			}

			$imageFileName = self::IMAGE_DUMMY . '.' . $imageType;
			$imageTitle = Title::newFromText( $imageFileName, NS_FILE );

			if ( !$imageTitle->exists() ) {
				wfDebug( __METHOD__ . ": file page for $imageFileName does not exist\n" );
				self::createDummyImageFilePage( $wgParser, $imageType );
			} else if ( self::titleHasMultipleRevisions( $imageTitle ) ) {
				wfDebug( __METHOD__ . ": file page for $imageFileName has multiple revisions\n" );
				self::deleteFilePage( $imageTitle );

				self::createDummyImageFilePage( $wgParser, $imageType );
			}
		}
	}

	/**
	 * Check if a given image type is probably allowed to be uploaded
	 * (does not consult any file extension blacklists).
	 * @param[in] string $imageType is the type of image (e.g. png) to check.
	 * @author Keith Welter
	 */
	public static function imageTypeAllowed( $imageType ) {
		global $wgFileExtensions;

		if ( !in_array( strtolower( $imageType ), $wgFileExtensions ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Delete a file page with the given title.
	 * This is used to delete dummy file pages with multiple revisions
	 * so that they don't bloat the database.
	 * @see GraphViz::createDummyImageFilePage
	 * @param[in] Title $title
	 * @author Keith Welter
	 */
	public static function deleteFilePage( $title ) {
		$file = wfLocalFile( $title );
		$oldimage = null;
		$reason = '';
		$suppress = false;
		FileDeleteForm::doDelete( $title, $file, $oldimage, $reason, $suppress );
	}

	/**
	 * Optionally create a category page for GraphViz::ROOT_CATEGORY and the given subcategory.
	 * @param[in] string $subCategory is the same as the graph renderer.
	 * @author Keith Welter
	 */
	public static function optionallyCreateCategoryPage( $subCategory ) {
		global $wgGraphVizSettings;

		if ( strcasecmp( $wgGraphVizSettings->createCategoryPages, 'yes' ) == 0 ) {
			$rootCategoryName = self::ROOT_CATEGORY;
			$rootCategoryDesc = wfMessage( 'graphviz-category-desc', "[[:Category:$rootCategoryName]]" )->text();
			self::createCategoryPage( $rootCategoryName, $rootCategoryDesc, "" );

			$subCategoryName = $rootCategoryName . ' ' . $subCategory;
			$subCategoryDesc = wfMessage( 'graphviz-subcategory-desc', "[[:Category:$subCategoryName]]", $subCategory )->text();
			$subCategoryDesc .= "[[Category:$rootCategoryName]]";
			self::createCategoryPage( $subCategoryName, $subCategoryDesc, "" );
		}
	}

	public static function getCategoryTags( $renderer ) {
		$rootCategoryName = self::ROOT_CATEGORY;
		return "[[Category:$rootCategoryName]][[Category:$rootCategoryName $renderer]]";
	}

	/**
	 * Create a category page with the given name if it does not already exist.
	 * @param[in] string $name is the name to use for the new category page.
	 * @param[in] string $pageText is the page text to supply.
	 * @param[in] string $comment is the comment to supply for the edit.
	 * @author Keith Welter
	 */
	public static function createCategoryPage( $name, $pageText, $comment ) {
		$title = Title::newFromText( "Category:" . $name );

		if ( !$title->exists() ) {
			$wikiPage = new WikiPage( $title );
			$flags = EDIT_NEW;
			$status = self::doEditContent( $wikiPage, $title, $pageText, $comment, $flags, null );
			if ( $status->isOK() ) {
				wfDebug( __METHOD__ . ": created category '$name'\n" );
			} else {
				wfDebug( __METHOD__ . ": create failed for category '$name'\n" );
			}
		}
	}

	/**
	 * Create a dummy file page with a trivial graph image of the given image type.
	 * New graph images of the same type may be uploaded on top of this dummy without
	 * triggering any parser code.  This makes it possible to do graph image file
	 * uploads in the context of a tag function like GraphViz::render.
	 * @param[in] Parser $parser
	 * @param[in] string $imageType is the type of image (e.g. png) to create a dummy file page for.
	 * @author Keith Welter
	 */
	public static function createDummyImageFilePage( Parser &$parser, $imageType ) {
		$input = "graph GraphVizExtensionDummy { GraphViz }";
		$args['format'] = $imageType;
		$frame = false;
		$isDummy = true;
		self::render( $input, $args, $parser, $frame, $isDummy );
	}

	/**
	 * Determine if the given title has multiple revisions.
	 * @param[in] Title $title is the title of the wiki page to check for multiple revisions.
	 * @author Keith Welter
	 * @return true if the given title has multiple revisions, otherwise false.
	 */
	public static function titleHasMultipleRevisions( $title ) {
		return $title->getLatestRevID() != $title->getFirstRevision()->getId();
	}

	/**
	 * Set parser hook functions for supported graph types.
	 * @author Keith Welter
	 * @return true
	 */
	public static function onParserInit( Parser &$parser ) {
		foreach ( self::$graphTypes as $graphType ) {
			$parser->setHook( self::$tags[$graphType] , array( __CLASS__, self::$parserHookFunctions[$graphType] ) );
		}
		return true;
	}

	/**
	 * Hook function front-end to GraphViz::initDummyFilePages for edit preview.
	 * initDummyFilePages() must be called before parsing for edit previews.
	 * @author Keith Welter
	 */
	public static function onEditPageGetPreviewContent( $editPage, &$content ) {
		self::initDummyFilePages();
	}

	/**
	 * Backwards-compatible (< MW 1.21) hook function front-end to GraphViz::initDummyFilePages for edit preview.
	 * initDummyFilePages() must be called before parsing for edit previews.
	 * @author Keith Welter
	 */
	public static function onEditPageGetPreviewText( $editPage, &$toParse ) {
		self::initDummyFilePages();
		return true;
	}

	/**
	 * When parsing is complete for a page, check for any graph image files associated with the page and
	 * upload them.  The uploading must be deferred until this point because the upload code path
	 * invokes parsing logic which throws an exception if called from a parser hook function.
	 *
	 * @return true
	 * @author Keith Welter
	 */
	public static function onOutputPageParserOutput( OutputPage &$out, ParserOutput $parserOutput ) {
		global $wgUser;

		$title = $out->getTitle();

		self::uploadImagesForTitle( $title, $wgUser );

		return true;
	}

	/**
	 * Check for any graph image files associated with the title and upload them.
	 *
	 * @return int number of uploaded images
	 * @author Keith Welter
	 */
	public static function uploadImagesForTitle( $title, $user ) {
		// find any stored images for the page
		$titleText = $title->getFulltext();
		$imageDir = self::getImageDir();
		$globPattern = $imageDir . self::makeFriendlyGraphName( $titleText ) . "*.*";
		$imageFilePaths = glob( $globPattern );
		$uploaded = 0;

		// if any were found, upload them now
		if ( !empty( $imageFilePaths ) ) {
			$errorText = "";

			$uploaded = self::uploadImages( $titleText, $imageFilePaths, $user, $errorText );

			if ( $errorText != "" ) {
				$errorHTML = self::multilineErrorHTML( $errorText );
				$out->addHTML( $errorHTML );
			}

			// purge the page if any uploads were successful so that active images may be displayed
			if ( $uploaded > 0 ) {
				$wikiPage = WikiPage::factory( $title );
				$wikiPage->doPurge();
			}
		}

		return $uploaded;
	}

	/**
	 * Convenience function for creating or editing page text.
	 *
	 * @param[in] WikiPage $wikiPage is the wiki page to create or edit.
	 * @param[in] string $title is the title of the wiki page to create or edit.
	 * @param[in] string $pageText is the page text to supply.
	 * @param[in] string $comment is the comment to supply for the edit.
	 * @param[in] integer $flags see the WikiPage::doEditContent flags documentation
	 * @param[in] User $user is the user on behalf of whom the edit is recorded.
	 *
	 * @return Status.
	 *
	 * @author Keith Welter
	 */
	public static function doEditContent( $wikiPage, $title, $pageText, $comment, $flags, $user ) {
		$status = Status::newGood();

		$oldVersion = version_compare( $GLOBALS['wgVersion'], '1.21', '<' );
		if ( $oldVersion ) {
			// Do stuff for MediaWiki 1.20 and older
			$status = $wikiPage->doEdit( $pageText, $comment, $flags, false, $user );
		} else {
			// Do stuff for MediaWiki 1.21 and newer
			$content = ContentHandler::makeContent( $pageText, $title );
			$status = $wikiPage->doEditContent( $content, $comment, $flags, false, $user );
		}

		return $status;
	}

	public static function getRendererFromImageFileName( $imageFileName ) {
		$renderer = strrev( $imageFileName );
		$renderer = substr( $renderer, 0, strpos( $renderer, '_' ) );
		$renderer = strrev( $renderer );
		wfDebug( __METHOD__ . ": imageFileName: $imageFileName renderer: $renderer\n" );
		return $renderer;
	}

	/**
	 * Upload a list of graph images for a wiki page with the given title text.
	 *
	 * @param[in] string $titleText is the title text of the wiki page.
	 * @param[in] string $imageFilePaths is an array of graph image file paths.
	 * @param[in] User $user is the user on behalf of whom the upload is to be done.
	 * @param[out] string $errorText is used for returning an HTML error message if any errors occur.
	 *
	 * @return integer number of uploaded files.
	 *
	 * @author Keith Welter
	 */
	public static function uploadImages( $titleText, $imageFilePaths, $user, &$errorText ) {
		$comment = wfMessage( 'graphviz-upload-comment', $titleText )->text();
		$watch = false;
		$removeTempFile = true;
		$uploaded = 0;

		foreach ( $imageFilePaths as $imageFilePath ) {
			wfDebug( __METHOD__ . ": uploading $imageFilePath\n" );
			$imageFileName = basename( $imageFilePath );
			$renderer = self::getRendererFromImageFileName( pathinfo ( $imageFilePath, PATHINFO_FILENAME ) );
			$pageText = self::getMapHowToText( $imageFileName ) . self::getCategoryTags( $renderer );

			$imageTitle = Title::newFromText( $imageFileName, NS_FILE );
			if ( !$imageTitle->exists() ) {
				if ( !UploadLocalFile::upload( $imageFileName, $imageFilePath, $user, $comment, $pageText, $watch, $removeTempFile ) ) {
					wfDebug( __METHOD__ . ": upload failed for $imageFileName\n" );
					if ( file_exists( $imageFilePath ) ) {
						wfDebug( __METHOD__ . ": unlinking $imageFilePath\n" );
						unlink( $imageFilePath );
					}
					$graphName = pathinfo( $imageFilePath, PATHINFO_FILENAME );
					self::deleteGraphFiles( $graphName, self::getSourceAndMapDir() );
					$errorText .= wfMessage( 'graphviz-uploaderror', $imageFileName )->text() . "\n";
				} else {
					wfDebug( __METHOD__ . ": uploaded $imageFilePath\n" );
					$uploaded++;

					self::optionallyCreateCategoryPage( $renderer );
				}
			} else {
				// The upload for this title has already occured in GraphViz::render
				// but the page text and comment have not been updated yet so do it now.
				unlink( $imageFilePath );

				$wikiPage = new WikiFilePage( $imageTitle );
				$flags = EDIT_UPDATE | EDIT_SUPPRESS_RC;
				self::doEditContent( $wikiPage, $imageTitle, $pageText, $comment, $flags, $user );
				wfDebug( __METHOD__ . ": updated file page for $imageFilePath\n" );

				// Go ahead and count this as an upload since it has been done.
				$uploaded++;

				self::optionallyCreateCategoryPage( $renderer );
			}
		}
		wfDebug( __METHOD__ . ": uploaded $uploaded files for article: $titleText\n" );

		return $uploaded;
	}

	/**
	 * When an article is deleted, delete all the associated graph files
	 * (except the uploaded ones).
	 * @return true
	 * @author Keith Welter
	 */
	public static function onArticleDeleteComplete( &$article, User &$user, $reason, $id ) {
		self::deleteArticleFiles( $article, self::getSourceAndMapDir() );
		self::deleteArticleFiles( $article, self::getImageDir() );
		return true;
	}

	/**
	 * Delete all the graph files associated with the given article and path.
	 * @author Keith Welter
	 */
	public static function deleteArticleFiles( $article, $path ) {
		$globPattern = $article->getTitle()->getFulltext();
		$globPattern = self::makeFriendlyGraphName( $globPattern );
		$globPattern = $path . $globPattern . "*.*";
		wfDebug( __METHOD__ . ": deleting: $globPattern\n" );
		array_map( 'unlink', glob( $globPattern ) );
	}

	/**
	 * Delete all the graph files associated with the graph name and path.
	 * @author Keith Welter
	 */
	public static function deleteGraphFiles( $graphName, $path ) {
		$globPattern = $path . $graphName . "*.*";
		wfDebug( __METHOD__ . ": deleting: $globPattern\n" );
		array_map( 'unlink', glob( $globPattern ) );
	}

	/**
	 * Hook function front-end to GraphViz::onTitleSave.
	 * @author Keith Welter
	 */
	public static function onPageContentSave( $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $status ) {
		$titleText = $wikiPage->getTitle()->getFulltext();
		return self::onTitleSave( $titleText );
	}

	/**
	 * Function to record when a page with the given title is being saved.
	 * Also, ensure initDummyFilePages() is called before parsing the page.
	 * @author Keith Welter
	 */
	public static function onTitleSave( $titleText ) {
		self::initDummyFilePages();
		self::$titlesBeingSaved[$titleText] = '';
		wfDebug( __METHOD__ . ": saving: $titleText\n" );
		return true;
	}

	/**
	 * Backwards-compatible (< MW 1.21) hook function front-end to GraphViz::onTitleSave.
	 * @author Keith Welter
	 */
	public static function onArticleSave( &$article, &$user, &$text, &$summary, $minor, $watchthis, $sectionanchor, &$flags, &$status ) {
		$titleText = $article->getTitle()->getFulltext();
		return self::onTitleSave( $titleText );
	}

	/**
	 * Check if the given title text corresponds to a page that is being saved.
	 * @author Keith Welter
	 */
	protected static function saving( $titleText ) {
		return array_key_exists( $titleText, self::$titlesBeingSaved );
	}

	/**
	 * Hook function front-end to GraphViz::onTitleSaveComplete.
	 * @author Keith Welter
	 */
	public static function onPageContentSaveComplete( $wikiPage, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId ) {
		$title = $wikiPage->getTitle();
		return self::onTitleSaveComplete( $title );
	}

	/**
	 * Function to clean-up when a page with the given title is done being saved.
	 * Firstly, this function invokes uploadImagesForTitle() to upload any graph
	 * images newly created for the given title.
	 * Secondly, this function invokes deleteInactiveFiles() to delete inactive
	 * graph files associated with a page when it is done being saved.  Lastly,
	 * this function removes the record that the page is being saved as well as
	 * the list of active files for the page.
	 * @author Keith Welter
	 */
	public static function onTitleSaveComplete( $title ) {
		global $wgUser;
		$titleText = $title->getFulltext();
		self::uploadImagesForTitle( $title, $wgUser );
		self::deleteInactiveFiles( $titleText );
		wfDebug( __METHOD__ . ": done saving: $titleText\n" );
		unset( self::$titlesBeingSaved[$titleText] );
		return true;
	}

	/**
	 * Backwards-compatible (< MW 1.21) hook function front-end to GraphViz::onTitleSaveComplete.
	 * @author Keith Welter
	 */
	public static function onArticleSaveComplete( &$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId ) {
		$title = $article->getTitle();
		return self::onTitleSaveComplete( $title );
	}

	/**
	 * Record an active graph file for the given title text.
	 * An active graph file is one that is referenced by wiki text that is being saved.
	 * @param[in] string $titleText is the title of the page being saved.
	 * @param[in] string $file is the path of the active file to record.
	 * @author Keith Welter
	 */
	protected static function recordActiveFile( $titleText, $file ) {
		self::$titlesBeingSaved[$titleText][$file] = '';
	}

	/**
	 * For a given title, check if the given file is active.
	 * An active graph file is one that is referenced by wiki text that is being saved.
	 * @param[in] string $titleText is the title of the page to be checked.
	 * @param[in] string $file is the path of the file to be checked.
	 * @author Keith Welter
	 */
	protected static function isActiveFile( $titleText, $file ) {
		return array_key_exists( $file, self::$titlesBeingSaved[$titleText] );
	}

	/**
	 * Delete inactive graph files associated with the given title text.
	 * @see GraphViz::isActiveFile
	 * @param[in] string $titleText is the title of the page for which inactive files should be deleted.
	 * @author Keith Welter
	 */
	public static function deleteInactiveFiles( $titleText ) {
		$globPattern = self::makeFriendlyGraphName( $titleText );
		$globPattern = self::getSourceAndMapDir() . $globPattern . "*.*";

		foreach ( glob( $globPattern ) as $file ) {
			if ( !self::isActiveFile( $titleText, $file ) ) {
				wfDebug( __METHOD__ . ": deleting inactive file: $file\n" );
				unlink( $file );
			}
		}
	}

	/**
	 * @param[in] string $graphName is the name of the graph to make "friendly".
	 * @return string $graphName with non-alphanumerics replaced with underscores.
	 * @author Keith Welter
	 */
	protected static function makeFriendlyGraphName( $graphName ) {
		return preg_replace ( '~[^\w\d]~', '_' , $graphName );
	}

	/**
	 * The parser hook function for the mscgen tag.
	 * Tag content must conform to the mscgen language.
	 * This is a front-end to self::render which does the heavy lifting.
	 * @see http://www.mcternan.me.uk/mscgen/
	 * @author Matthew Pearson
	 */
	public static function mscgenParserHook( $input, $args, $parser, $frame )
	{
		$args['renderer'] = self::$graphLanguages[self::MSCGEN];
		return self::render( $input, $args, $parser, $frame );
	}

	/**
	 * The parser hook function for the graphviz tag.
	 * Tag content must conform to the dot language.
	 * This is a front-end to self::render which does the heavy lifting.
	 * @see http://www.graphviz.org/content/dot-language
	 * @author Thomas Hummel
	 */
	public static function graphvizParserHook( $input, $args, $parser, $frame )
	{
		if ( isset( $args['renderer'] ) ) {
			switch( $args['renderer'] ) {
				case 'circo':
				case 'dot':
				case 'fdp':
				case 'sfdp':
				case 'neato':
				case 'twopi':
					break;
				default:
					$args['renderer'] = self::$graphLanguages[self::GRAPHVIZ];
			}
		} else {
			$args['renderer'] = self::$graphLanguages[self::GRAPHVIZ];
		}

		return self::render( $input, $args, $parser, $frame );
	}

	/**
	 * @section Overview
	 * This is the main function of the extension which handles rendering graph descriptions as HTML.
	 * A graph description is the content of a graph tag supported by this extension.
	 *
	 * Before a graph description can be rendered as HTML, a graph renderer must be invoked to render
	 * the graph description as an image which is stored on the file system and later uploaded to the wiki.
	 *
	 * Normal upload restrictions apply. See:
	 * - UploadLocalFile::isUploadAllowedForUser
	 * - UploadLocalFile::isUploadAllowedForTitle
	 *
	 * @section Maps
	 * If the graph description contains links then a map file is populated with the coordinates of
	 * the shape corresponding to each link.  The map file is also stored on the file system but it
	 * is not uploaded.  Rather, the map data is included on the description of the uploaded graph
	 * image in the format used by the ImageMap extension.  This allows the graph image and map data
	 * to be included in wiki pages without the graph description itself by copying the ImageMap text
	 * from the graph image description page and pasting it into the desired wiki page.
	 *
	 * @section Regeneration
	 * The graph description is stored in a source file on the file system (independently of the
	 * wiki page that contains it) so that this extension can detect when it is
	 * necessary to regenerate the image and map files.
	 * If the graph description from the wiki page matches the graph description stored in
	 * the graph source file then the image and map files are not regenerated.
	 *
	 * @section Files
	 * As described above, three kinds of files are stored by this extension:
	 * -# graph source files (stored in GraphViz::SOURCE_AND_MAP_SUBDIR)
	 * -# graph image files (stored in GraphViz::IMAGE_SUBDIR, deleted after upload)
	 * -# graph map files (stored in GraphViz::SOURCE_AND_MAP_SUBDIR)
	 *
	 * For a given graph, the basename is the same for each kind of file.
	 * The file base name includes:
	 * -# title of the wiki page containing the graph
	 * -# graph type from the graph description (e.g. graph, digraph, msc)
	 * -# graph name (if any) from the graph description
	 * -# an optional "uniquifier" (see $args)
	 * -# the user name (for edit previews only)
	 *
	 * Basenames are sanitized by makeFriendlyGraphName().  The user name is included for
	 * edit previews so that files generated for edits (which might be cancelled) don't
	 * collide with files corresponding to the saved version of the wiki page.
	 *
	 * The source and map file types are determined by GraphRenderParms.  See:
	 * - GraphRenderParms::getSourceFileName
	 * - GraphRenderParms::getMapFileName
	 *
	 * The image file type may be selected by the user (see $args) and
	 * constrained by GraphRenderParms.  See:
	 * - GraphRenderParms::supportedDotImageTypes
	 * - GraphRenderParms::supportedMscgenImageTypes
	 * - GraphRenderParms::getImageFileName
	 *
	 * Additional hooks are used to delete graph files when they are no longer needed:
	 * - onArticleDeleteComplete()
	 * - onPageContentSaveComplete()
	 *
	 * @section ImageMap
	 * This function depends on the ImageMap extension to do the final rendering of the graph image
	 * and map as HTML as well as validation of the image attributes and links.  The existence of
	 * the graph image as an uploaded file is a requirement of the ImageMap extension.
	 *
	 * @section Security
	 * Upload restrictions (described above) and Cross-site scripting (XSS) are the main security
	 * concerns for this extension.
	 * @see http://www.mediawiki.org/wiki/Cross-site_scripting.
	 *
	 * To prevent XSS we should validate input and must escape output.
	 * The input to validate includes the tag attributes (the $args parameter here) and
	 * the tag content (the $input parameter here).
	 *
	 * The values of the tag attributes accepted by generateImageMapInput() are passed
	 * to ImageMap::render which handles the validation (using Parser::makeImage).
	 *
	 * Sanitation of the graphviz tag content is done in sanitizeDotInput() before attempting
	 * to generate an image or map file from it.  The nature of this sanitization is to
	 * disallow or constrain the use of dot language attributes that relate to the filesystem.
	 * The mscgen language does not have such attributes so it does not require such sanitization.
	 *
	 * If an error occurs generating an image or
	 * map file (for example a syntax error in the graph description) then the error output
	 * will be escaped and rendered as HTML.  The escaping is especially necessary for syntax
	 * error messages because such messages contain context from the graph description that
	 * is user supplied.  Graph file path information is stripped from syntax error messages.
	 *
	 * Links contained in the graph description (and saved in the map file) are passed as-is to
	 * the ImageMap extension for rendering as HTML.  Any sanitization of these links is the
	 * responsibility of the ImageMap extension (as is the case when the ImageMap extension
	 * parses links directly from ImageMap tag content).
	 *
	 * @param[in] string $input contains the graph description.  URL attribute values in the graph
	 * description should be specified as described at http://en.wikipedia.org/wiki/Help:Link.
	 * Examples:
	 * - URL="[[wikilink]]"
	 * - URL="[[interwiki link]]"
	 * - URL="[external link]"
	 *
	 * @param[in] array $args contains the graph tag attributes.  Applicable attribute names
	 * are those listed for generateImageMapInput() as well as "uniquifier" and "format":
	 * - The optional "uniquifier" attribute value is used to disambiguate
	 * graphs of the same name or those with no graph name at all.  The mscgen language
	 * does not include graph names so the "uniquifier" is necessary to show more than
	 * one message sequence chart on the same page.
	 * - The optional "format" attribute allows the user to specify the image type from
	 * among those supported for the graph language.  @ref Files.
	 *
	 * @param[in] Parser $parser
	 * @param[in] boolean $isDummy indicates whether the input represents the source for
	 * a dummy graph image (see GraphViz::createDummyImageFilePage).
	 *
	 * @return string HTML of a graph image and optional map or an HTML error message.
	 *
	 * @author Keith Welter et al.
	 */
	protected static function render( $input, $args, $parser, $frame, $isDummy = false )
	{
		global
		$wgUser,
		$wgGraphVizSettings;

		// sanity check the input
		$input = trim( $input );
		if ( empty( $input ) ) {
			return self::i18nErrorMessageHTML( 'graphviz-no-input' );
		}

		// make sure the ImageMap extension is installed
		if ( !class_exists( 'ImageMap' ) ) {
			return self::i18nErrorMessageHTML( 'graphviz-no-imagemap-class' );
		}

		// get title text
		$title = $parser->getTitle();
		if ( $isDummy ) {
			$titleText = "File";
		} else if ( $title ) {
			$titleText = $title->getFulltext();
		} else {
			$titleText = "";
		}

		// begin the graphName with the article title text...
		$graphName = $titleText;

		// then add the graph title from the graph source...
		$graphSourceTitle = trim( substr( $input, 0, strpos( $input, '{' ) ) );
		$graphName .= '_' . $graphSourceTitle;

		// and finally, add the "uniquifier" if one was supplied.
		if ( isset( $args['uniquifier'] ) ) {
			$graphName .= '_' . $args['uniquifier'];
		}

		// sanitize the graph name
		$graphName = self::makeFriendlyGraphName( $graphName );

		// set renderer
		if ( isset( $args['renderer'] ) ) {
			$renderer = $args['renderer'];
		} else {
			$renderer = self::$graphLanguages[self::GRAPHVIZ];
		}

		// get source and map file directory path
		$sourceAndMapDir = self::getSourceAndMapDir();
		if ( $sourceAndMapDir == false ) {
			return self::i18nErrorMessageHTML( 'graphviz-mkdir-failed' );
		}

		// get source and map file directory path
		$imageDir = self::getImageDir();
		if ( $imageDir == false ) {
			return self::i18nErrorMessageHTML( 'graphviz-mkdir-failed' );
		}

		// set imageType
		if ( isset( $args['format'] ) ) {
			$imageType = $args['format'];
		} else {
			$imageType = $wgGraphVizSettings->defaultImageType;
		}

		// determine user...
		// In testing I found that $parser->getUser() did not give the logged-in user when doing an edit preview.
		// So, I've gone against the recommended practice and used the global which gave the desired results.
		$user = $wgUser;
		$userName = $user->getName();

		// instantiate an object to hold the graph rendering parameters
		$graphParms = new GraphRenderParms( $renderer, $graphName, $userName, $imageType, $sourceAndMapDir, $imageDir );

		// initialize context variables
		$saving = false;
		$isPreview = false;
		$parserOptions = $parser->getOptions();

		if ( $parserOptions ) {
			$isPreview = $parserOptions->getIsPreview();
		}

		if ( !$isPreview ) {
			$saving = self::saving( $titleText );
			if ( $saving ) {
				// delete the preview version of the files (if any)
				self::deleteFiles( $graphParms, true, true );
			}
		}
		wfDebug( __METHOD__ . ": isPreview: $isPreview saving: $saving isDummy: $isDummy\n" );

		// determine whether or not to call recursiveTagParse
		$doRecursiveTagParse = false;
		$preParseType = "none";

		if ( !$isDummy ) {
			if ( isset( $args['preparse'] ) ) {
				$preParseType = $args['preparse'];
				if ( $preParseType == "dynamic" ) {
					$doRecursiveTagParse = true;
					$parser->disableCache();
				} else if ( $preParseType == "static" ) {
					if ( $saving || $isPreview ) {
						$doRecursiveTagParse = true;
					}
				} else {
					return self::i18nErrorMessageHTML( 'graphviz-unrecognized-preparse-value', $preParseType );
				}
			}
		}
		wfDebug( __METHOD__ . ": preParseType: $preParseType doRecursiveTagParse: $doRecursiveTagParse\n" );

		// call recursiveTagParse if appropriate
		if ( $doRecursiveTagParse ) {
			$input = $parser->recursiveTagParse( $input, $frame );
		}

		$errorText = "";
		// if the input is in the dot language, sanitize it
		if ( $graphParms->getRenderer() != self::$graphLanguages[self::MSCGEN] ) {
			if ( !self::sanitizeDotInput( $input, $errorText ) ) {
				return self::errorHTML( $errorText );
			}
		}

		// determine if the image to render exists
		$imageExists = UploadLocalFile::getUploadedFile( $graphParms->getImageFileName( $isPreview ) ) ? true : false;

		// get the path of the map to render
		$mapPath = $graphParms->getMapPath( $isPreview );

		// determine if the map to render exists
		$mapExists = false;
		if ( file_exists( $mapPath ) ) {
			$mapExists = true;
		}
		wfDebug( __METHOD__ . ": imageExists: $imageExists mapExists: $mapExists\n" );

		// check if the graph source changed only if:
		// - the wiki text is being saved or
		// - an edit of the wiki text is being previewed or
		// - the graph image or map file does not exist
		// - doing recursiveTagParse
		$sourceChanged = false;
		if ( $saving || $isPreview || !$imageExists || !$mapExists || $doRecursiveTagParse ) {
			if ( !self::isSourceChanged( $graphParms->getSourcePath( $isPreview ), $input, $sourceChanged, $errorText ) ) {
				return self::errorHTML( $errorText );
			}
		}
		wfDebug( __METHOD__ . ": sourceChanged: $sourceChanged\n" );

		$imageFileName = $graphParms->getImageFileName( $isPreview );
		$imageFilePath = $graphParms->getImagePath( $isPreview );
		$uploaded = false;
		$usedDummy = false;

		// generate image and map files only if the graph source changed or the image or map files do not exist
		if ( $isDummy || $sourceChanged || !$imageExists || !$mapExists ) {
			// first, check if the user is allowed to upload the image
			if ( !UploadLocalFile::isUploadAllowedForUser( $user, $errorText ) ) {
				wfDebug( __METHOD__ . ": $errorText\n" );
				return self::errorHTML( $errorText );
			}

			// if the source changed, update it on disk
			if ( $sourceChanged ) {
				if ( !self::updateSource( $graphParms->getSourcePath( $isPreview ), $input, $errorText ) ) {
					wfDebug( __METHOD__ . ": $errorText\n" );
					self::deleteFiles( $graphParms, $isPreview, false );
					return self::errorHTML( $errorText );
				}
			}

			// execute the image creation command
			if ( !self::executeCommand( $graphParms->getImageCommand( $isPreview ), $errorText ) )
			{
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $isPreview, false );

				// remove path info from the errorText
				$errorText = str_replace( $imageDir, "", $errorText );
				$errorText = str_replace( $sourceAndMapDir, "", $errorText );
				return self::multilineErrorHTML( $errorText );
			}

			// check if the upload is allowed for the intended title (the image file must exist prior to this check)
			if ( !UploadLocalFile::isUploadAllowedForTitle(
				$user,
				$imageFileName,
				$imageFilePath,
				false,
				wfGetLangObj(),
				$errorText ) )
			{
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $isPreview, false );
				return self::errorHTML( $errorText );
			}

			// execute the map creation command
			if ( !self::executeCommand( $graphParms->getMapCommand( $isPreview ), $errorText ) )
			{
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $isPreview, false );

				// remove path info from the errorText (file base names are allowed to pass)
				$errorText = str_replace( $imageDir, "", $errorText );
				$errorText = str_replace( $sourceAndMapDir, "", $errorText );
				return self::multilineErrorHTML( $errorText );
			}

			// normalize the map file contents
			if ( !self::normalizeMapFileContents( $graphParms->getMapPath( $isPreview ), $graphParms->getRenderer(), 
				$titleText, $errorText ) ) {
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $isPreview, false );
				return self::errorHTML( $errorText );
			}

			if ( $saving ) {
				self::recordActiveFile( $titleText, $graphParms->getSourcePath( $isPreview ) );
				self::recordActiveFile( $titleText, $graphParms->getMapPath( $isPreview ) );
			}

			$imageTitle = Title::newFromText( $imageFileName, NS_FILE );
			$imageTitleText = $imageTitle->getText();
			$dummyFilePath = self::getImageDir() . self::IMAGE_DUMMY . '.' . $imageType;

			// decide whether to upload the graph image on top of a dummy or
			// on top of an existing file page for the graph (or neither)
			if ( !$imageTitle->exists() ) {
				// there is no image page for the graph yet so try to use a dummy
				wfDebug( __METHOD__ . ": $imageTitleText does not exist\n" );

				if ( $dummyFilePath != $imageFilePath) {
					$dummyFileName = basename( $dummyFilePath );
					$dummyTitle = Title::newFromText( $dummyFileName, NS_FILE );

					if ( $dummyTitle->exists() ) {
						if ( self::titleHasMultipleRevisions( $dummyTitle ) ) {
							// the dummy file page exists but has already been used so we must bail out
							$dummyTitleText = $dummyTitle->getText();
							wfDebug( __METHOD__ . ": $dummyTitleText has multiple revisions\n" );
							return wfMessage( 'graphviz-reload' )->escaped();
						} else {
							// the dummy file page exists and has not been copied over yet... use it now!
							wfDebug( __METHOD__ . ": copying $imageFilePath to $dummyFilePath\n" );
							copy( $imageFilePath, $dummyFilePath );
							$imageFilePath = $dummyFilePath;
							$imageFileName = basename( $imageFilePath );
							$usedDummy = true;
						}
					} else {
						// the dummy file page does not exist (unexpected) so we must bail out
						$dummyTitleText = $dummyTitle->getText();
						wfDebug( __METHOD__ . ": $dummyTitleText does not exist\n" );
						return wfMessage( 'graphviz-reload' )->escaped();
					}
				}
			} else {
				wfDebug( __METHOD__ . ": $imageTitleText exists\n" );
				if ( $dummyFilePath == $imageFilePath) { //SNO
					return;
				}
			}

			// prepare to upload
			$pageText = "";

			// don't bother tagging dummies
			if ( !$isDummy ) {
				$pageText = self::getCategoryTags( $renderer );
			}
			$comment = wfMessage( 'graphviz-upload-comment', $titleText )->text();
			$watch = false;
			$removeTempFile = true;

			// prevent recusive call to Parser::parse (see bug 73073).
			if ( !self::disableHooks() ) {
				return wfMessage( 'graphviz-reload' )->escaped();
			}

			// Upload the graph image.
			// We can only do this here when a file page already exists for the given image.
			// Otherwise, file page creation triggers parsing of the page text and comment
			// resulting in an illegal recursive parse and an exception (StripState invalid marker).
			// The exception is not even thrown on a path that this extension can wrap try/catch logic around.
			// So we must really jump through hoops to accomplish file uploads in this context.
			if ( !UploadLocalFile::upload( $imageFileName, $imageFilePath, $user, $comment, $pageText, $watch, $removeTempFile ) ) {
				wfDebug( __METHOD__ . ": upload failed for $imageFileName\n" );
				if ( file_exists( $imageFilePath ) ) {
					wfDebug( __METHOD__ . ": unlinking $imageFilePath\n" );
					unlink( $imageFilePath );
				}
				$graphName = pathinfo( $imageFilePath, PATHINFO_FILENAME );
				self::deleteGraphFiles( $graphName, self::getSourceAndMapDir() );
				return wfMessage( 'graphviz-reload' )->escaped();
			} else {
				wfDebug( __METHOD__ . ": uploaded $imageFilePath\n" );
				$uploaded = true;
				touch( $imageFilePath );
			}

			if ( !self::enableHooks() ) {
				throw new MWException( "failed to re-enable hooks" );
			}
		}

		// If rendering a dummy graph image just return without producing HTML.
		if ( $isDummy ) {
			return;
		}

		// get the map file contents
		$mapContents = self::getMapContents( $graphParms->getMapPath( $isPreview ) );

		// If an upload was done using a dummy then set the ImageMap desc parameter to none.
		// Otherwise, the image would contain a link to the dummy graph image file page.
		if ( $uploaded && $usedDummy ) {
			$args['desc'] = "none";
		}

		// generate the input for the ImageMap renderer
		$imageMapInput = self::generateImageMapInput( $args, $imageFileName, $mapContents );

		// render the image map (image must be uploaded first)
		$imageMapOutput = ImageMap::render( $imageMapInput, null, $parser );

		if ( $saving ) {
			self::recordActiveFile( $titleText, $graphParms->getSourcePath( $isPreview ) );
			self::recordActiveFile( $titleText, $graphParms->getMapPath( $isPreview ) );
		}

		// purge the page if an upload was successful so that active images may be displayed
		if ( $title && $uploaded ) {
			$wikiPage = WikiPage::factory( $title );
			$wikiPage->doPurge();
		}

		// return the rendered HTML
		return $imageMapOutput;
	}

	/**
	 * Sanitize the dot language input:
	 * - Image attribute values are required to be the names of uploaded files.
	 * - IMG SRC attribute values in HTML-like labels are required to be the names of uploaded files.
	 * - The imagepath attribute is not allowed as user input.
	 * - The deprecated shapefile attribute is not allowed as user input.
	 * - The fontpath attribute is not allowed as user input.
	 *
	 * @see http://www.graphviz.org/content/dot-language (ID syntax)
	 * @see http://www.graphviz.org/content/attrs#dimage
	 * @see http://www.graphviz.org/content/node-shapes#html (IMG attribute)
	 * @see http://www.graphviz.org/content/attrs#dimagepath
	 * @see http://www.graphviz.org/content/attrs#dshapefile
	 * @see http://www.graphviz.org/content/attrs#afontpath
	 */
	protected static function sanitizeDotInput( &$input, &$errorText ) {

		// reject forbidden attributes from the input
		foreach ( self::$forbiddenDotAttributes as $forbiddenAttribute ) {
			if ( stripos( $input, $forbiddenAttribute ) !== false ) {
				$errorText = wfMessage( 'graphviz-dot-attr-forbidden', $forbiddenAttribute )->text();
				return false;
			}
		}

		// convert any image attributes in the input to specify the full file system path

		$limit = -1; // no limit on the number of replacements
		$count = 0; // count of replacements done (output)
		$pattern = self::getDotImagePattern(); //pattern to match
		$input = preg_replace_callback( $pattern, "self::fixImageName", $input, $limit, $count );

		if ( $count > 0 && stripos( $input, 'image=""' ) !== false ) {
			$errorText = wfMessage( 'graphviz-dot-invalid-image', 'image' )->text();
			return false;
		}

		// convert any img src attributes (in HTML-like labels) in the input to specify the full file system path

		$count = 0;
		$input = preg_replace_callback( self::DOT_IMG_PATTERN, "self::fixImgSrc", $input, $limit, $count );

		if ( $count > 0 && stripos( $input, 'src=""' ) !== false ) {
			$errorText = wfMessage( 'graphviz-dot-invalid-image', 'IMG SRC' )->text();
			return false;
		}

		return true;
	}

	/**
	 *  Ensure a dot image attribute value corresponds to the name of an uploaded file.
	 *  @return string image attribute name-value pair with the value set to a validated uploaded file name or
	 *  'image=""' to indicate an invalid image attribute value.
	 *  @param[in] array $matches corresponds to the pattern returned by GraphViz::getDotImagePattern().
	 *  @author Keith Welter
	 *  @see GraphViz::sanitizeDotInput
	 */
	protected static function fixImageName( array $matches ) {
		$imageName = $matches[1];

		// handle quoted strings
		if ( substr( $imageName, 0, 1 ) == '"' ) {
			if ( substr( $imageName, strlen( $imageName ) - 1, 1 ) == '"' ) {
				// remove beginning and ending quotes
				$imageName = substr( $imageName, 1, strlen( $imageName ) - 2 );
			} else {
				// missing ending quote
				wfDebug( __METHOD__ . ": removing invalid imageName: $imageName\n" );
				return 'image=""';
			}

			// remove concatenation and escaped newlines
			$imageName = preg_replace( '~("\s*[+]\s*"|\\\n)~', '', $imageName );
		}

		$imageFile = UploadLocalFile::getUploadedFile( $imageName );
		if ( $imageFile ) {
			$result = 'image="' . $imageFile->getLocalRefPath() . '"';
			wfDebug( __METHOD__ . ": replacing: $imageName with: $result\n" );
			return $result;
		} else {
			wfDebug( __METHOD__ . ": removing invalid imageName: $imageName\n" );
			return 'image=""';
		}
	}

	/**
	 *  Ensure a dot IMG SRC attribute value corresponds to the name of an uploaded file.
	 *  @return string IMG SRC attribute name-value pair with the value set to a validated uploaded file name or
	 *  'src=""' to indicate an invalid image attribute value.
	 *  @param[in] array $matches corresponds to GraphViz::DOT_IMG_PATTERN.
	 *  @author Keith Welter
	 *  @see GraphViz::sanitizeDotInput
	 */
	protected static function fixImgSrc( array $matches ) {
		$imageName = $matches[4];

		$imageFile = UploadLocalFile::getUploadedFile( $imageName );
		if ( $imageFile ) {
			$imagePath = $imageFile->getLocalRefPath();
			$result = $matches[1] . 'src="' . $imagePath . '"';
			wfDebug( __METHOD__ . ": replacing: $imageName with: $imagePath\n" );
			return $result;
		} else {
			wfDebug( __METHOD__ . ": removing invalid imageName: $imageName\n" );
			return $matches[1] . 'src=""';
		}
	}

	/**
	 * @param[in] string $mapPath is the file system path of the graph map file.
	 * @return string contents of the given graph map file.
	 * @author Keith Welter
	 */
	protected static function getMapContents( $mapPath ) {
		$mapContents = "";
		if ( file_exists( $mapPath ) ) {
			if ( false == ( $mapContents = file_get_contents( $mapPath ) ) ) {
				wfDebug( __METHOD__ . ": map file: $mapPath is empty.\n" );
			}
		} else {
			wfDebug( __METHOD__ . ": map file: $mapPath is missing.\n" );
		}
		return $mapContents;
	}

	/**
	 * @param[in] string $imagePath is the file system path of the graph map file.
	 * @return string description with example of how to include ImageMap tags for the given image file in a wiki page.
	 * @author Keith Welter
	 */
	protected static function getMapHowToText( $imagePath ) {
		$imageExtension = pathinfo( $imagePath, PATHINFO_EXTENSION );
		$graphName = substr( $imagePath, 0, strlen( $imagePath ) - strlen( $imageExtension ) );
		$mapPath = self::getSourceAndMapDir() . $graphName . 'map';
		$mapContents = self::getMapContents( $mapPath );
		$mapHowToText = "";
		if ( $mapContents != "" ) {
			$mapHowToText = wfMessage( 'graphviz-map-desc', $imagePath, $mapContents )->text();
		}
		return $mapHowToText;
	}

	/**
	 * Delete the given graph source, image and map files.
	 *
	 * @param[in] GraphRenderParms $graphParms contains the names of the graph source, image and map files to delete.
	 * @param[in] boolean $isPreview indicates whether or not the files to be deleted were for an edit preview.
	 * @param[in] boolean $deleteUploads indicates whether or not to delete the uploaded image file.
	 *
	 * @author Keith Welter
	 */
	protected static function deleteFiles( $graphParms, $isPreview, $deleteUploads ) {
		$graphParms->deleteFiles( $isPreview );

		if ( $deleteUploads ) {
			$imageFileName = $graphParms->getImageFileName( $isPreview );
			$imageFile = UploadLocalFile::getUploadedFile( $imageFileName );
			if ( $imageFile ) {
				$imageFile->delete( wfMessage( 'graphviz-delete-reason' )->text() );

				$imageTitle = Title::newFromText( $imageFileName, NS_FILE );
				if ( $imageTitle->exists() ) {
					self::deleteFilePage( $imageTitle );
				}
			}
		}
	}

	/**
	 * @param[in] string $command is the command line to execute.
	 * @param[out] string $output is the output of the command.
	 * @return boolean true upon success, false upon failure.
	 * @author Keith Welter et al.
	 */
	protected static function executeCommand( $command, &$output ) {
		if ( !wfIsWindows() ) {
			// redirect stderr to stdout so that it will be included in outputArray
			$command .= " 2>&1";
		}
		$output = wfShellExec( $command, $ret );

		if ( $ret != 0 ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize the map output of different renderers.
	 * The normalized map pattern will adhere to the syntax accepted by the ImageMap extension.
	 * Specifically, each map line has the following order:
	 * -# shape name
	 * -# coordinates
	 * -# link in one of the following forms:
	 *   - [[Page title]]
	 *   - [[Page title|description]]
	 *   - [URL]
	 *   - [URL description]
	 * @see http://www.mediawiki.org/wiki/Extension:ImageMap#Syntax_description
	 *
	 * @param[in] string $mapPath is the map file (including path).
	 * @param[in] string $renderer is the name of the renderer used to produce the map.
	 * @param[in] string $pageTitle is the page title to supply for DOT tooltips that do not have URLs.
	 * @param[out] string $errorText is populated with an error message in the event of an error.
	 *
	 * @return boolean true upon success, false upon failure.
	 *
	 * @author Keith Welter
	 */
	protected static function normalizeMapFileContents( $mapPath, $renderer, $pageTitle, &$errorText ) {
		// read the map file contents
		$map = file_get_contents( $mapPath );
		if ( !empty( $map ) ) {
			// replaces commas with spaces
			$map = str_replace( ',', ' ', $map );

			if ( $renderer == 'mscgen' ) {
				$newMap = "";

				// iterate over the map lines (platform independent)
				foreach ( preg_split( "/((\r?\n)|(\r\n?))/", $map ) as $line ) {
					// the order of $line is shape name, URL, coordinates
					$tokens = explode( " ", $line );

					// skip map lines with too few tokens
					if ( count( $tokens ) < 4 ) {
						continue;
					}

					// get the URL and enclose it in square brackets if they are absent
					$URL = $tokens[1];
					if ( $URL[0] != '[' ) {
						$URL = '[' . $URL . ']';
					}

					// get the coordinates
					$coordinates = implode( ' ', array_slice( $tokens, 2, count( $tokens ) - 1 ) );

					// reorder map lines to the pattern shape name, coordinates, URL
					$mapLine = $tokens[0] . ' ' . $coordinates . ' ' . $URL;

					// add the reordered map line to the new map
					$newMap = $newMap . $mapLine . PHP_EOL;
				}

				// replace the input map with the reordered one
				$map = $newMap;
			} else {
				// remove <map> beginning tag from map file contents
				$map  = preg_replace( '#<map(.*)>#', '', $map );

				// remove <map> ending tag from map file contents
				$map  = str_replace( '</map>', '', $map );

				// DOT and HTML allow tooltips without URLs but ImageMap does not.
				// We want to allow tooltips without URLs (hrefs) so supply the page title if it is missing.

				// detect missing hrefs and add them as needed
				$missingHrefReplacement = 'id="$1" href="[[' . $pageTitle . ']]" title="$2"';
				$map = preg_replace( '~id="([^"]+)"[\s\t]+title="([^"]+)"~',
					$missingHrefReplacement,
					$map );

				// add enclosing square brackets to URLs that don't have them and add the title
				$map = preg_replace( '~href="([^[][^"]+).+title="([^"]+)~',
					'href="[$1 $2]"',
					$map );

				// reorder map lines to the pattern shape name, coordinates, URL
				$map = preg_replace( '~.+shape="([^"]+).+href="([^"]+).+coords="([^"]+).+~',
					'$1 $3 $2',
					$map );
			}

			// eliminate blank lines (platform independent)
			$map = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", '', $map);

			// write the normalized map contents back to the file
			if ( file_put_contents( $mapPath, $map ) === false ) {
				wfDebug( __METHOD__ . ": file_put_contents( $mapPath, map ) failed.\n" );
				wfDebug( __METHOD__ . ": map($map)\n" );
				$errorText = wfMessage( 'graphviz-write-map-failed' )->text();
				return false;
			}
		}
		return true;
	}

	/**
	 * Convert the input into a syntax acceptable by the ImageMap extension.
	 * @see http://www.mediawiki.org/wiki/Extension:ImageMap#Syntax_description
	 *
	 * @param[in] array $args is an optional list of image display attributes
	 * to be applied to the rendered image.  Attribute usage is documented here:
	 * http://en.wikipedia.org/wiki/Wikipedia:Extended_image_syntax
	 * Applicable attributes are:
	 * - type
	 * - border
	 * - location
	 * - alignment
	 * - size
	 * - link
	 * - alt
	 * - caption
	 *
	 * @param[in] string $imageFileName is the filename (without path) of the graph image to render.
	 * @param[in] string $map is map data which is one or more lines each with the following order:
	 * -# shape name
	 * -# coordinates
	 * -# link (see http://en.wikipedia.org/wiki/Help:Link)
	 *
	 * @return string suitable for input to ImageMap::render.
	 *
	 * @author Keith Welter
	 */
	protected static function generateImageMapInput( $args = null, $imageFileName, $map ) {
		$imageMapInput = "";
		$imageLine = "Image:" . $imageFileName;

		$modifiers = array( "type", "border", "location", "alignment", "size", "link", "alt", "caption" );
		foreach ( $modifiers as $modifier ) {
			if ( isset( $args[$modifier] ) ) {
				if ( $modifier == "link" || $modifier == "alt" ) {
					$imageLine .= "|" . $modifier . "=" . $args[$modifier];
				}
				else {
					$imageLine .= "|" . $args[$modifier];
				}
			}
		}

		// ImageMap::render requires at least one modifier so supply alt if not done by the user
		if ( !isset( $modifiers['alt'] ) ) {
			if ( isset( $args['caption'] ) ) {
				$alt = $args['caption'];
			} else {
				$alt = wfMessage( 'graphviz-alt' )->text();
			}
			$imageLine .= "|alt=" . $alt;
		}

		$imageMapInput .= $imageLine . "\n" . $map;

		if ( isset( $args['desc'] ) ) {
			$imageMapInput .= "\ndesc " . $args['desc'];
		}

		if ( isset( $args['default'] ) ) {
			$imageMapInput .= "\ndefault " . $args['desc'];
		}

		return $imageMapInput;
	}

	/**
	 * Update the graph source on disk.
	 *
	 * @param[in] string $sourceFilePath is the path of the graph source file to update.
	 * @param[in] string $source is the text to save in $sourceFilePath.
	 * @param[out] string $errorText is populated with an error message in case of error.
	 *
	 * @return boolean true upon success, false upon failure.
	 *
	 * @author Keith Welter
	 */
	protected static function updateSource( $sourceFilePath, $source, &$errorText ) {
		if ( file_put_contents( $sourceFilePath, $source ) == false ) {
			wfDebug( __METHOD__ . ": file_put_contents($sourceFilePath,source) failed\n" );
			$errorText = wfMessage( 'graphviz-write-src-failed' )->text();
			return false;
		} else {
			wfDebug( __METHOD__ . ": file_put_contents($sourceFilePath,source) succeeded\n" );
		}

		return true;
	}

	/**
	 * Check if the source text matches the contents of the source file.
	 *
	 * @param[in] string $sourceFilePath is the path of existing source in the file system.
	 * @param[in] string $source is the wikitext to be compared with the contents of $sourceFilePath.
	 * @param[out] boolean $sourceChanged is set to true if $source does not match the contents of $sourceFilePath
	 * (otherwise it is set to false).
	 * @param[out] string $errorText is populated with an error message in case of error.
	 *
	 * @return boolean true upon success, false upon failure.
	 *
	 * @author Keith Welter
	 */
	protected static function isSourceChanged( $sourceFilePath, $source, &$sourceChanged, &$errorText ) {
		if ( file_exists( $sourceFilePath ) ) {
			$contents = file_get_contents( $sourceFilePath );
			if ( $contents === false ) {
				wfDebug( __METHOD__ . ": file_get_contents($sourceFilePath) failed\n" );
				$errorText = wfMessage( 'graphviz-read-src-failed' )->text();
				return false;
			}
			if ( strcmp ( $source , $contents ) == 0 ) {
				wfDebug( __METHOD__ . ": $sourceFilePath matches wiki text\n" );
				$sourceChanged = false;
				return true;
			} else {
				$sourceChanged = true;
			}
		} else {
			$sourceChanged = true;
		}

		return true;
	}

	/**
	 * Given a message name, return an HTML error message.
	 * @param[in] string $messageName is the name of a message in the i18n file.
	 * A variable number of message arguments is supported.
	 * @return string escaped HTML error message for $messageName.
	 * @author Keith Welter
	 */
	static function i18nErrorMessageHTML( $messageName ) {
		if ( func_num_args() < 2 ) {
			return self::errorHTML( wfMessage( $messageName )->text() );
		} else {
			$messageArgs = array_slice( func_get_args(), 1 );
			return self::errorHTML( wfMessage( $messageName, $messageArgs )->text() );
		}
	}

	/**
	 * @param[in] string $text is text to be escaped and rendered as an HTML error.
	 * @return string HTML escaped and rendered as an error.
	 * @author Keith Welter
	 */
	static function errorHTML( $text ) {
		return Html::element( 'p', array( 'class' => 'error' ), $text );
	}

	/**
	 * @param[in] string $multilineText is one or more PHP_EOL delimited lines to be escaped and rendered as an HTML error.
	 * @see escapeHTML()
	 * @return string HTML escaped and rendered as an error.
	 * @author Keith Welter
	 */
	static function multilineErrorHTML( $multilineText ) {
		$escapedRows = "";
		foreach ( explode( PHP_EOL, $multilineText ) as $row ) {
			$escapedRows .= self::escapeHTML( $row ) . "<br>";
		}
		return '<p class="error">' . $escapedRows . '</p>';
	}

	/**
	 * Escape the input text for HTML rendering (wrapper for htmlspecialchars).
	 * @see http://www.mediawiki.org/wiki/Cross-site_scripting#Stopping_Cross-site_scripting
	 * @return string escaped HTML.
	 * @param[in] string $text is the text to be escaped.
	 * @author Keith Welter
	 */
	static function escapeHTML( $text ) {
	 	return htmlspecialchars( $text, ENT_QUOTES );
	}

	/**
	 * @return string path of the directory containing graph source and map files.
	 * @author Keith Welter
	 */
	static function getSourceAndMapDir() {
		return self::getUploadSubdir( self::SOURCE_AND_MAP_SUBDIR );
	}

	/**
	 * @return string path of the directory containing graph image files (prior to upload).
	 * @author Keith Welter
	 */
	static function getImageDir() {
		return self::getUploadSubdir( self::SOURCE_AND_MAP_SUBDIR . self::IMAGE_SUBDIR );
	}

	/**
	 * @param[in] $subdir is the path of a subdirectory relative to $wgUploadDirectory.
	 * If the subdirectory does not exist, it is created with the same permissions as $wgUploadDirectory.
	 * @return string path of a subdirectory of the wiki upload directory ($wgUploadDirectory) or false upon failure.
	 * @author Keith Welter
	 */
	protected static function getUploadSubdir( $subdir ) {
		global $wgUploadDirectory;

		// prevent directory traversal
		if ( strpos( $subdir, "../" ) !== false ) {
			throw new MWException( "directory traversal detected in $subdir" );
		}

		$uploadSubdir = $wgUploadDirectory . $subdir;

		// switch the slashes for windows
		if ( wfIsWindows() ) {
			$uploadSubdir = str_replace( "/", '\\', $uploadSubdir );
		}

		// create the output directory if it does not exist
		if ( !is_dir( $uploadSubdir ) ) {
			$mode = fileperms ( $wgUploadDirectory );
			if ( !mkdir( $uploadSubdir, $mode ) ) {
				wfDebug( __METHOD__ . ": mkdir($uploadSubdir, $mode) failed\n" );
				return false;
			}
		}

		return $uploadSubdir;
	}
}
