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
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'GraphViz' );
	$wgMessageDirs['GraphViz'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for GraphViz extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
} else {
	die( 'This version of the GraphViz extension requires MediaWiki 1.29+' );
}
