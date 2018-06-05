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

namespace MediaWiki\Extension\GraphViz;

use File;
use Html;
use ImageMap;
use MediaWiki\MediaWikiServices;
use MWException;
use Parser;
use PPFrame;
use RequestContext;
use Sanitizer;
use User;

/**
  *  This is the principal class of the GraphViz extension, responsible
  *  for graph file management and rendering graph images and maps as HTML.
  *  Graph source, image and map files are saved in the file system in order to avoid
  *  regenerating them every time a page containing a graph is rendered.
  *  The ImageMap extension is used for the rendering of graph images and maps as HTML.
  *
  * @ingroup Extensions
  */
class GraphViz {

	/**
	 * A regular expression for matching the following ID form in the DOT language:
	 * any string of alphabetic ([a-zA-Z\200-\377]) characters, underscores ('_'),
	 * or digits ([0-9]), not beginning with a digit.
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
	 * Used as an array key in GraphViz::$graphTypes and other arrays.
	 * It must be a unique value in GraphViz::$graphTypes.
	 *
	 * @var int GRAPHVIZ
	 */
	const GRAPHVIZ = 0;

	/**
	 * Used as an array key in GraphViz::$graphTypes and other arrays.
	 * It must be a unique value in GraphViz::$graphTypes.
	 *
	 * @var int MSCGEN
	 */
	const MSCGEN = 1;

	/**
	 * The name of the system message that defines the username to be used for uploading graphs
	 * when the current user doesn't have upload permission.
	 */
	const UPLOAD_USER_MESSAGE = 'graphviz-upload-user';

	/**
	 * A list of dot attributes that are forbidden.
	 * @see http://www.graphviz.org/content/attrs#dimagepath
	 * @see http://www.graphviz.org/content/attrs#dshapefile
	 * @see http://www.graphviz.org/content/attrs#dfontpath
	 * @var array $forbiddenDotAttributes
	 */
	private static $forbiddenDotAttributes = [
		'imagepath',
		'shapefile',
		'fontpath'
	];

	/**
	 * A list of the graph types that this extension supports.
	 * @var array $graphTypes
	 */
	private static $graphTypes = [
		self::GRAPHVIZ,
		self::MSCGEN
	];

	/**
	 * A list of the tags that this extension supports.
	 * @var array $tags
	 */
	private static $tags = [
		self::GRAPHVIZ => 'graphviz',
		self::MSCGEN   => 'mscgen',
	];

	/**
	 * A mapping from graph types to graph languages.
	 * @var array $graphLanguages
	 */
	private static $graphLanguages = [
		self::GRAPHVIZ => 'dot',
		self::MSCGEN   => 'mscgen',
	];

	/**
	 * A mapping from graph types to parser hook functions.
	 * @var array $parserHookFunctions
	 */
	private static $parserHookFunctions = [
		self::GRAPHVIZ => 'graphvizParserHook',
		self::MSCGEN   => 'mscgenParserHook',
	];

	/**
	 * @return string regular expression for matching an image attribute in the DOT language.
	 *
	 * @see http://www.graphviz.org/content/attrs#dimage
	 * @see http://www.graphviz.org/content/dot-language
	 */
	protected static function getDotImagePattern() {
		return "~(?i)image\s*=\s*("
			. self::DOT_ID_STRING
			. "|" . self::DOT_NUMERAL
			. "|" . self::DOT_QUOTED_STRING
			. "|" . self::DOT_HTML_STRING
			. ")~";
	}

	/**
	 * Check if a given image type is probably allowed to be uploaded
	 * (does not consult any file extension blacklists).
	 * @param string $imageType is the type of image (e.g. png) to check.
	 * @return bool
	 * @author Keith Welter
	 */
	public static function imageTypeAllowed( $imageType ) {
		$fileExtensions = RequestContext::getMain()->getConfig()->get( 'FileExtensions' );

		if ( !in_array( strtolower( $imageType ), $fileExtensions ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Set parser hook functions for supported graph types.
	 * @author Keith Welter
	 * @param Parser &$parser
	 * @return true
	 */
	public static function onParserInit( Parser &$parser ) {
		foreach ( self::$graphTypes as $graphType ) {
			$parser->setHook(
				self::$tags[$graphType], [ __CLASS__, self::$parserHookFunctions[$graphType] ]
			);
		}
		return true;
	}

	/**
	 * When an article is deleted, delete all the associated graph files.
	 * @author Keith Welter
	 * @param Article &$article
	 * @param User &$user
	 * @param string $reason
	 * @param int $id
	 */
	public static function onArticleDeleteComplete( &$article, User &$user, $reason, $id ) {
		self::deleteArticleUploadedFiles( $article, self::getImageDir() );
		self::deleteArticleFiles( $article, self::getSourceAndMapDir() );
		self::deleteArticleFiles( $article, self::getImageDir() );
	}

	/**
	 * For a given title, get the corresponding graph file base name.
	 * @author Keith Welter
	 * @param Title $title
	 * @return string
	 */
	public static function getGraphFileBaseNameFromTitle( $title ) {
		$baseName = $title->getFulltext();
		$baseName = self::makeFriendlyGraphName( $baseName );
		return $baseName;
	}

	/**
	 * Delete all the graph files associated with the given article and path.
	 * @author Keith Welter
	 * @param Article $article
	 * @param string $path
	 */
	public static function deleteArticleFiles( $article, $path ) {
		$title = $article->getTitle();
		$globPattern = self::getGraphFileBaseNameFromTitle( $title );
		$globPattern = $path . $globPattern . "*.*";
		wfDebug( __METHOD__ . ": deleting: $globPattern\n" );
		array_map( 'unlink', glob( $globPattern ) );
	}

	/**
	 * Detect if the given title has associated graph files at the given path.
	 * @param Title $title
	 * @param string $path
	 * @return True if the title has associated graph files.  Otherwise false.
	 * @author Keith Welter
	 */
	public static function titleHasGraphFiles( $title, $path ) {
		$globPattern = self::getGraphFileBaseNameFromTitle( $title );
		$globPattern = $path . $globPattern . "*.*";
		if ( empty( glob( $globPattern ) ) ) {
			$result = false;
		} else {
			$result = true;
		}
		wfDebug( __METHOD__ . ": result: $result\n" );
		return $result;
	}

	/**
	 * Delete all uploaded files associated with the given article and path.
	 * @author Keith Welter
	 * @param Article $article
	 * @param string $path
	 */
	public static function deleteArticleUploadedFiles( $article, $path ) {
		wfDebug( __METHOD__ . ": entering\n" );
		$title = $article->getTitle();
		$globPattern = self::getGraphFileBaseNameFromTitle( $title );
		$globPattern = $path . $globPattern . "*.*";
		foreach ( glob( $globPattern ) as $file ) {
			$uploadedFile = UploadLocalFile::getUploadedFile( $file );
			if ( $uploadedFile ) {
				wfDebug( __METHOD__ . ": deleting uploaded file: $file\n" );
				$uploadedFile->delete( wfMessage( 'graphviz-delete-reason' )->text() );
			}
		}
	}

	/**
	 * Delete all the graph files associated with the graph name and path.
	 * @author Keith Welter
	 * @param string $graphName
	 * @param string $path
	 */
	public static function deleteGraphFiles( $graphName, $path ) {
		$globPattern = $path . $graphName . "*.*";
		wfDebug( __METHOD__ . ": deleting: $globPattern\n" );
		array_map( 'unlink', glob( $globPattern ) );
	}

	/**
	 * Reject the parser cache value for a page if it has recently uploaded graph images.
	 * This is necessary when a user edits a graph but then cancels the edit.  In this case,
	 * the parser cache reflects the canceled edit rather than the saved graph so we must
	 * reject it.
	 * @author Keith Welter
	 * @param ParserOutput $parserOutput
	 * @param WikiPage $wikiPage
	 * @param ParserOptions $parserOptions
	 * @return bool
	 */
	public static function onRejectParserCacheValue( $parserOutput, $wikiPage, $parserOptions ) {
		$title = $wikiPage->getTitle();

		$result = true;
		if ( self::titleHasGraphFiles( $title, self::getImageDir() ) ) {
			self::deleteArticleFiles( $wikiPage, self::getImageDir() );
			$result = false;
		}

		wfDebug( __METHOD__ . ": result $result\n" );
		return $result;
	}

	/**
	 * Deletes all graph files for the page.
	 * The reason for this is to ensure clean-up of orphaned graph images
	 * (graph images for which the source wiki text has been deleted).
	 * Graph images for extant wiki source will be regenerated when parsed.
	 * @author Keith Welter
	 * @param \WikiPage $wikiPage
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param bool $isWatch
	 * @param string|int $section
	 * @param int $flags
	 * @param Status $status
	 * @return true
	 */
	public static function onPageContentSave(
		\WikiPage $wikiPage, $user, $content,
		$summary, $isMinor, $isWatch, $section, $flags, $status
	) {
		wfDebug( __METHOD__ . ": entering\n" );

		$title = $wikiPage->getTitle();

		self::deleteArticleUploadedFiles( $wikiPage, self::getImageDir() );
		self::deleteArticleFiles( $wikiPage, self::getSourceAndMapDir() );

		return true;
	}

	/**
	 * Add the GraphViz user to the list of reserved usernames.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/UserGetReservedNames
	 * @param string[] &$reservedUsernames Array of usernames or references to system messages.
	 */
	public static function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'msg:' . self::UPLOAD_USER_MESSAGE;
	}

	/**
	 * @param string $graphName is the name of the graph to make "friendly".
	 * @return string $graphName with non-alphanumerics replaced with underscores.
	 * @author Keith Welter
	 */
	protected static function makeFriendlyGraphName( $graphName ) {
		return preg_replace( '~[^\w\d]~', '_', $graphName );
	}

	/**
	 * The parser hook function for the mscgen tag.
	 * Tag content must conform to the mscgen language.
	 * This is a front-end to self::render which does the heavy lifting.
	 * @see http://www.mcternan.me.uk/mscgen/
	 * @author Matthew Pearson
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function mscgenParserHook( $input, $args, $parser, $frame ) {
		$args['renderer'] = self::$graphLanguages[self::MSCGEN];
		return self::render( $input, $args, $parser, $frame );
	}

	/**
	 * The parser hook function for the graphviz tag.
	 * Tag content must conform to the dot language.
	 * This is a front-end to self::render which does the heavy lifting.
	 * @see http://www.graphviz.org/content/dot-language
	 * @author Thomas Hummel
	 * @param string $input
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function graphvizParserHook( $input, $args, $parser, $frame ) {
		if ( isset( $args['renderer'] ) ) {
			switch ( $args['renderer'] ) {
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
	 * Get the user who should perform GraphViz uploads. If the current user can't upload (e.g.
	 * this graph generation is being done by an extension and no user is logged in) then fall back
	 * to the GraphViz system user.
	 * @return User
	 */
	public static function getUser() {
		$user = RequestContext::getMain()->getUser();
		$requiredRights = [ 'upload', 'reupload', 'edit', 'createpage' ];

		// If the user has all the required rights, use them.
		$rights = is_array( $user->mRights ) ? $user->mRights : [];
		if ( array_intersect( $rights, $requiredRights ) == $requiredRights ) {
			return $user;
		}

		// Otherwise, use a system user.
		$userName = wfMessage( self::UPLOAD_USER_MESSAGE )->parse();
		$user = User::newSystemUser( $userName, [ 'steal' => true ] );
		$user->mRights = array_merge( $user->getRights(), $requiredRights );
		// Make sure system user's email address is confirmed if required.
		$emailConfirmToEdit = MediaWikiServices::getInstance()
			->getMainConfig()
			->get( 'EmailConfirmToEdit' );
		if ( $emailConfirmToEdit && !$user->isEmailConfirmed() ) {
			$user->mEmail = 'graphviz@example.org';
			$user->setEmailAuthenticationTimestamp( wfTimestampNow() );
			$user->saveSettings();
		}
		return $user;
	}

	/**
	 * # Overview
	 *
	 * This is the main function of the extension which handles rendering graph descriptions as HTML.
	 * A graph description is the content of a graph tag supported by this extension.
	 *
	 * Before a graph description can be rendered as HTML, a graph renderer must be invoked to render
	 * the graph description as an image which is stored on the file system and later stored in
	 * the wiki file repository.
	 *
	 * ## Maps
	 * If the graph description contains links then a map file is populated with the coordinates of
	 * the shape corresponding to each link.  The map file is also stored on the file system but it
	 * is not uploaded.
	 *
	 * ## Regeneration
	 * The graph description is stored in a source file on the file system (independently of the
	 * wiki page that contains it) so that this extension can detect when it is
	 * necessary to regenerate the image and map files.
	 * If the graph description from the wiki page matches the graph description stored in
	 * the graph source file then the image and map files are not regenerated.
	 *
	 * ## Files
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
	 *
	 * Basenames are sanitized by makeFriendlyGraphName().
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
	 * ## ImageMap
	 * This function depends on the ImageMap extension to do the final rendering of the graph image
	 * and map as HTML as well as validation of the image attributes and links.  The existence of
	 * the graph image as an uploaded file is a requirement of the ImageMap extension.
	 *
	 * ## Security
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
	 * @param string $input contains the graph description.  URL attribute values in the graph
	 * description should be specified as described at http://en.wikipedia.org/wiki/Help:Link.
	 * Examples:
	 * - URL="[[wikilink]]"
	 * - URL="[[interwiki link]]"
	 * - URL="[external link]"
	 *
	 * @param array $args contains the graph tag attributes.  Applicable attribute names
	 * are those listed for generateImageMapInput() as well as "uniquifier" and "format":
	 * - The optional "uniquifier" attribute value is used to disambiguate
	 * graphs of the same name or those with no graph name at all.  The mscgen language
	 * does not include graph names so the "uniquifier" is necessary to show more than
	 * one message sequence chart on the same page.
	 * - The optional "format" attribute allows the user to specify the image type from
	 * among those supported for the graph language.  @ref Files.
	 *
	 * @param Parser $parser The parser.
	 *
	 * @param PPFrame $frame The preprocessor frame.
	 *
	 * @return string HTML of a graph image and optional map or an HTML error message.
	 *
	 * @author Keith Welter et al.
	 */
	protected static function render( $input, $args, Parser $parser, PPFrame $frame ) {
		// sanity check the input
		$input = trim( $input );
		if ( empty( $input ) ) {
			return self::i18nErrorMessageHTML( 'graphviz-no-input' );
		}

		// get title text
		$title = $parser->getTitle();
		$titleText = $title->getFulltext();

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
			$settings = new Settings();
			$imageType = $settings->defaultImageType;
		}

		// Determine user.
		$user = self::getUser();
		$userName = $user->getName();

		// instantiate an object to hold the graph rendering parameters
		$graphParms = new GraphRenderParms(
			$renderer, $graphName, $userName, $imageType, $sourceAndMapDir, $imageDir
		);

		// initialize context variables
		$isPreview = false;
		$userSpecific = false;
		$parserOptions = $parser->getOptions();

		if ( $parserOptions ) {
			$isPreview = $parserOptions->getIsPreview();
		}
		wfDebug( __METHOD__ . ": isPreview: $isPreview\n" );

		// determine whether or not to call recursiveTagParse
		$doRecursiveTagParse = false;
		$preParseType = "none";

		if ( isset( $args['preparse'] ) ) {
			$preParseType = $args['preparse'];
			if ( $preParseType == "dynamic" ) {
				$doRecursiveTagParse = true;
				$parser->disableCache();
			} else {
				return self::i18nErrorMessageHTML(
					'graphviz-unrecognized-preparse-value', $preParseType
				);
			}
		}
		wfDebug(
			__METHOD__ . ": preParseType: $preParseType"
			. " doRecursiveTagParse: $doRecursiveTagParse\n"
		);

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
		$imageFileName = $graphParms->getImageFileName( $userSpecific );
		$imageFile = UploadLocalFile::getUploadedFile( $imageFileName );
		$imageExists = false;
		if ( $imageFile instanceof File && !$imageFile->isMissing() ) {
			$imageExists = true;
		}

		// get the path of the map to render
		$mapPath = $graphParms->getMapPath( $userSpecific );

		// determine if the map to render exists
		$mapExists = false;
		if ( file_exists( $mapPath ) ) {
			$mapExists = true;
		}
		wfDebug( __METHOD__ . ": imageExists: $imageExists mapExists: $mapExists\n" );

		$sourceChanged = false;
		$sourcePath = $graphParms->getSourcePath( $userSpecific );
		if ( !self::isSourceChanged( $sourcePath, $input, $sourceChanged, $errorText ) ) {
			return self::errorHTML( $errorText );
		}
		wfDebug( __METHOD__ . ": sourceChanged: $sourceChanged\n" );

		$imageFilePath = $graphParms->getImagePath( $userSpecific );
		$uploaded = false;

		// Generate image and map files only if the graph source changed
		// or the image or map files do not exist.
		if ( $sourceChanged || !$imageExists || !$mapExists ) {
			// first, check if the user is allowed to upload the image
			if ( !UploadLocalFile::isUploadAllowedForUser( $user, $errorText ) ) {
				wfDebug( __METHOD__ . ": $errorText\n" );
				return self::errorHTML( $errorText );
			}

			// if the source changed, update it on disk
			if ( $sourceChanged ) {
				if ( !self::updateSource( $sourcePath, $input, $errorText ) ) {
					wfDebug( __METHOD__ . ": $errorText\n" );
					self::deleteFiles( $graphParms, $userSpecific, false );
					return self::errorHTML( $errorText );
				}
			}

			// execute the image creation command
			if ( !self::executeCommand( $graphParms->getImageCommand( $userSpecific ), $errorText ) ) {
				self::deleteFiles( $graphParms, $userSpecific, false );

				// remove path info from the errorText
				$errorText = str_replace( $imageDir, "", $errorText );
				$errorText = str_replace( $sourceAndMapDir, "", $errorText );
				return self::multilineErrorHTML( $errorText );
			}

			$upload = new UploadFromLocalFile;
			$upload->setUser( $user );

			// Check if the upload is allowed for the intended title
			// (the image file must exist prior to this check).
			if ( !UploadLocalFile::isUploadAllowedForTitle(
				$upload,
				$user,
				$imageFileName,
				$imageFilePath,
				false,
				$errorText )
			) {
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $userSpecific, false );
				return self::errorHTML( $errorText );
			}

			// execute the map creation command
			$commandOutput = self::executeCommand(
				$graphParms->getMapCommand( $userSpecific ), $errorText
			);
			if ( !$commandOutput ) {
				self::deleteFiles( $graphParms, $userSpecific, false );

				// remove path info from the errorText (file base names are allowed to pass)
				$errorText = str_replace( $imageDir, "", $errorText );
				$errorText = str_replace( $sourceAndMapDir, "", $errorText );
				return self::multilineErrorHTML( $errorText );
			}

			// normalize the map file contents
			$normalizedMapFileContents = self::normalizeMapFileContents(
				$graphParms->getMapPath( $userSpecific ), $graphParms->getRenderer(),
				$titleText, $errorText
			);
			if ( !$normalizedMapFileContents ) {
				wfDebug( __METHOD__ . ": $errorText\n" );
				self::deleteFiles( $graphParms, $userSpecific, false );
				return self::errorHTML( $errorText );
			}

			// prepare to upload
			$removeTempFile = true;

			// Store the graph image in the wiki file repository.
			$uploadSuccessful = UploadLocalFile::uploadWithoutFilePage(
				$upload,
				$imageFileName,
				$imageFilePath,
				$removeTempFile
			);
			if ( !$uploadSuccessful ) {
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

				// create an empty file to serve as a marker of a recently uploaded file
				touch( $imageFilePath );
			}
		}

		// get the map file contents
		$mapContents = self::getMapContents( $graphParms->getMapPath( $userSpecific ) );

		// generate the input for the ImageMap renderer
		$args['desc'] = "none";
		$imageMapInput = self::generateImageMapInput( $args, $imageFileName, $mapContents );

		// render the image map (image must be uploaded first)
		$imageMapOutput = ImageMap::render( $imageMapInput, null, $parser );

		// if no upload occured, clear the recently uploaded file marker (if any)
		if ( !$uploaded ) {
			if ( file_exists( $imageFilePath ) ) {
				wfDebug( __METHOD__ . ": unlinking $imageFilePath\n" );
				unlink( $imageFilePath );
			}
		}

		// Mark the page as using the GraphViz extension.
		$parser->getOutput()->setProperty( 'graphviz', true );

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
	 * @param string &$input
	 * @param array &$errorText
	 * @return bool
	 */
	protected static function sanitizeDotInput( &$input, &$errorText ) {
		// reject forbidden attributes from the input
		foreach ( self::$forbiddenDotAttributes as $forbiddenAttribute ) {
			if ( stripos( $input, $forbiddenAttribute ) !== false ) {
				$errorText = wfMessage( 'graphviz-dot-attr-forbidden', $forbiddenAttribute )
					->text();
				return false;
			}
		}

		// convert any image attributes in the input to specify the full file system path

		$limit = -1; // no limit on the number of replacements
		$count = 0; // count of replacements done (output)
		$pattern = self::getDotImagePattern(); // pattern to match
		$input = preg_replace_callback( $pattern, "self::fixImageName", $input, $limit, $count );

		if ( $count > 0 && stripos( $input, 'image=""' ) !== false ) {
			$errorText = wfMessage( 'graphviz-dot-invalid-image', 'image' )->text();
			return false;
		}

		// convert any img src attributes (in HTML-like labels) in the input to specify the full
		// filesystem path.

		$count = 0;
		$input = preg_replace_callback(
			self::DOT_IMG_PATTERN, "self::fixImgSrc", $input, $limit, $count
		);

		if ( $count > 0 && stripos( $input, 'src=""' ) !== false ) {
			$errorText = wfMessage( 'graphviz-dot-invalid-image', 'IMG SRC' )->text();
			return false;
		}

		return true;
	}

	/**
	 * Ensure a dot image attribute value corresponds to the name of an uploaded file.
	 * @return string image attribute name-value pair with the value set to a validated uploaded
	 *   file name or 'image=""' to indicate an invalid image attribute value.
	 * @param array $matches corresponds to the pattern returned by GraphViz::getDotImagePattern().
	 * @author Keith Welter
	 * @see GraphViz::sanitizeDotInput
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
	 * Ensure a dot IMG SRC attribute value corresponds to the name of an uploaded file.
	 * @return string IMG SRC attribute name-value pair with the value set to a validated uploaded
	 *   file name or 'src=""' to indicate an invalid image attribute value.
	 * @param array $matches corresponds to GraphViz::DOT_IMG_PATTERN.
	 * @author Keith Welter
	 * @see GraphViz::sanitizeDotInput
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
	 * @param string $mapPath is the file system path of the graph map file.
	 * @return string contents of the given graph map file.
	 * @author Keith Welter
	 */
	protected static function getMapContents( $mapPath ) {
		$mapContents = "";
		if ( file_exists( $mapPath ) ) {
			$mapContents = file_get_contents( $mapPath );
			if ( false == $mapContents ) {
				wfDebug( __METHOD__ . ": map file: $mapPath is empty.\n" );
			}
		} else {
			wfDebug( __METHOD__ . ": map file: $mapPath is missing.\n" );
		}
		return $mapContents;
	}

	/**
	 * Delete the given graph source, image and map files.
	 *
	 * @param GraphRenderParms $graphParms contains the names of the graph source, image and map
	 * files to delete.
	 * @param bool $userSpecific indicates whether or not the files to be deleted are user specific.
	 * @param bool $deleteUploads indicates whether or not to delete the uploaded image file.
	 *
	 * @author Keith Welter
	 */
	protected static function deleteFiles( $graphParms, $userSpecific, $deleteUploads ) {
		$graphParms->deleteFiles( $userSpecific );

		if ( $deleteUploads ) {
			$imageFileName = $graphParms->getImageFileName( $userSpecific );
			$imageFile = UploadLocalFile::getUploadedFile( $imageFileName );
			if ( $imageFile ) {
				$imageFile->delete( wfMessage( 'graphviz-delete-reason' )->text() );
			}
		}
	}

	/**
	 * @param string $command is the command line to execute.
	 * @param string &$output is the output of the command.
	 * @return bool true upon success, false upon failure.
	 * @author Keith Welter et al.
	 */
	protected static function executeCommand( $command, &$output ) {
		if ( !wfIsWindows() ) {
			// redirect stderr to stdout so that it will be included in outputArray
			$command .= " 2>&1";
		}
		$output = wfShellExec( $command, $ret );

		if ( $ret != 0 || $output ) {
			wfDebug( __METHOD__ . ": command: $command ret: $ret output: $output\n" );
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
	 * @param string $mapPath is the map file (including path).
	 * @param string $renderer is the name of the renderer used to produce the map.
	 * @param string $pageTitle is the page title to supply for DOT tooltips that do not have URLs.
	 * @param string &$errorText is populated with an error message in the event of an error.
	 *
	 * @return bool true upon success, false upon failure.
	 *
	 * @author Keith Welter
	 */
	protected static function normalizeMapFileContents(
		$mapPath, $renderer, $pageTitle, &$errorText
	) {
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
				$map = preg_replace( '#<map(.*)>#', '', $map );

				// remove <map> ending tag from map file contents
				$map = str_replace( '</map>', '', $map );

				// DOT and HTML allow tooltips without URLs but ImageMap does not.
				// We want to allow tooltips without URLs (hrefs)
				// so supply the page title if it is missing.

				// detect missing hrefs and add them as needed
				$missingHrefReplacement = 'id="$1" href="[[' . $pageTitle . ']]" title="$2"';
				$map = preg_replace( '~id="([^"]+)"[\s\t]+title="([^"]+)"~',
					$missingHrefReplacement,
					$map );

				// add enclosing square brackets to URLs that don't have them and add the title
				$map = preg_replace( '~href="([^[][^"]+).+title="([^"]+)~',
					'href="[$1 $2]"',
					$map );

				// Decode character references in URLs because the map file output by GraphViz
				// has already encoded them and we want to pass them to ImageMap::render
				// unencoded.
				$hrefPattern = '~href="([^"]+)"~';
				$map = preg_replace_callback(
					$hrefPattern,
					function ( $matches ) {
						if ( $matches[1] !== '' ) {
							$decoded = Sanitizer::decodeCharReferences( $matches[1] );
							return 'href="' . $decoded . '"';
						}
						return $matches[0];
					},
					$map );

				// reorder map lines to the pattern shape name, coordinates, URL
				$map = preg_replace( '~.+shape="([^"]+).+href="([^"]+).+coords="([^"]+).+~',
					'$1 $3 $2',
					$map );
			}

			// eliminate blank lines (platform independent)
			$map = preg_replace( "/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", '', $map );

			wfDebug( __METHOD__ . ": map($map)\n" ); // KJW

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
	 * @param array|null $args is an optional list of image display attributes
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
	 * @param string $imageFileName is the filename (without path) of the graph image to render.
	 * @param string $map is map data which is one or more lines each with the following order:
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

		$modifiers = [ "type", "border", "location", "alignment", "size", "link", "alt", "caption" ];
		foreach ( $modifiers as $modifier ) {
			if ( isset( $args[$modifier] ) ) {
				if ( $modifier == "link" || $modifier == "alt" ) {
					$imageLine .= "|" . $modifier . "=" . $args[$modifier];
				} else {
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
	 * @param string $sourceFilePath is the path of the graph source file to update.
	 * @param string $source is the text to save in $sourceFilePath.
	 * @param string &$errorText is populated with an error message in case of error.
	 *
	 * @return bool true upon success, false upon failure.
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
	 * @param string $sourceFilePath is the path of existing source in the file system.
	 * @param string $source is the wikitext to be compared with the contents of $sourceFilePath.
	 * @param bool &$sourceChanged is set to true if $source does not match the contents of
	 * $sourceFilePath (otherwise it is set to false).
	 * @param string &$errorText is populated with an error message in case of error.
	 *
	 * @return bool true upon success, false upon failure.
	 *
	 * @author Keith Welter
	 */
	protected static function isSourceChanged(
		$sourceFilePath, $source, &$sourceChanged, &$errorText
	) {
		if ( file_exists( $sourceFilePath ) ) {
			$contents = file_get_contents( $sourceFilePath );
			if ( $contents === false ) {
				wfDebug( __METHOD__ . ": file_get_contents($sourceFilePath) failed\n" );
				$errorText = wfMessage( 'graphviz-read-src-failed' )->text();
				return false;
			}
			if ( strcmp( $source, $contents ) == 0 ) {
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
	 * @param string $messageName is the name of a message in the i18n file.
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
	 * @param string $text is text to be escaped and rendered as an HTML error.
	 * @return string HTML escaped and rendered as an error.
	 * @author Keith Welter
	 */
	static function errorHTML( $text ) {
		return Html::element( 'p', [ 'class' => 'error' ], $text );
	}

	/**
	 * @param string $multilineText is one or more PHP_EOL delimited lines to be escaped and
	 * rendered as an HTML error.
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
	 * @param string $text is the text to be escaped.
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
	 * @param string $subdir is the path of a subdirectory relative to $wgUploadDirectory. If the
	 * subdirectory does not exist, it is created with the same permissions as $wgUploadDirectory.
	 * @return string path of a subdirectory of the wiki upload directory ($wgUploadDirectory)
	 * or false upon failure.
	 * @author Keith Welter
	 */
	protected static function getUploadSubdir( $subdir ) {
		$uploadDirectory = RequestContext::getMain()->getConfig()->get( 'UploadDirectory' );

		// prevent directory traversal
		if ( strpos( $subdir, "../" ) !== false ) {
			throw new MWException( "directory traversal detected in $subdir" );
		}

		$uploadSubdir = $uploadDirectory . $subdir;

		// switch the slashes for windows
		if ( wfIsWindows() ) {
			$uploadSubdir = str_replace( "/", '\\', $uploadSubdir );
		}

		// create the output directory if it does not exist
		if ( !is_dir( $uploadSubdir ) ) {
			$mode = fileperms( $uploadDirectory );
			if ( !mkdir( $uploadSubdir, $mode, true ) ) {
				wfDebug( __METHOD__ . ": mkdir($uploadSubdir, $mode) failed\n" );
				return false;
			}
		}

		return $uploadSubdir;
	}
}
