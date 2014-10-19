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
 * @ingroup Upload
 * @author Keith Welter
 */

/**
 * Implements local file uploads in the absence of a WebRequest in conjunction with UploadFromLocalFile.
 *
 * @ingroup Extensions
 * @ingroup Upload
 * @author Keith Welter
 */
class UploadLocalFile {
	/**
	 * Check if uploading is allowed for the given user.
	 * Based on SpecialUpload::execute.
	 *
	 * @param[in] User $user is the user to check.
	 * @param[out] string $errorText is populated with an error message if the user is not allowed to upload.
	 * @return boolean true if the user is allowed to upload, false if not.
	 */
	static function isUploadAllowedForUser( $user, &$errorText ) {
		// Check uploading enabled
		if ( !UploadBase::isEnabled() ) {
			wfDebug( __METHOD__ . ": upload not enabled.\n" );
			$errorText = self::i18nMessage( 'graphviz-uploaddisabledtext' );
			return false;
		}

		// Check permissions
		$permissionRequired = UploadBase::isAllowed( $user );
		if ( $permissionRequired !== true ) {
			wfDebug( __METHOD__ . ": " . $user->getName() . " not allowed to upload.\n" );
			$errorText = self::i18nMessage( 'graphviz-upload-not-permitted' );
			return false;
		}

		// Check blocks
		if ( $user->isBlocked() ) {
			wfDebug( __METHOD__ . ": " . $user->getName() . " is blocked.\n" );
			$errorText = self::i18nMessage( 'graphviz-user-blocked' );
			return false;
		}

		// Check if the wiki is in read-only mode
		if ( wfReadOnly() !== false ) {
			wfDebug( __METHOD__ . ": wiki is in read-only mode.\n" );
			$errorText = self::i18nMessage( 'graphviz-read-only' );
			return false;
		}

		return true;
	}

	/**
	 * Check if the upload is allowed for the given user and destination name.
	 * Based on SpecialUpload::processUpload.
	 *
	 * @param[in] User $user is the user to check.
	 * @param[in] string $desiredDestName the desired destination name of the file to be uploaded.
	 * @param[in] string $localPath the local path of the file to be uploaded.
	 * @param[in] bool $removeLocalFile remove the local file?
	 * @param[in] Language $langauge to use for
	 * @param[out] string $errorText is populated with an error message if the user is not allowed to upload.
	 * @return boolean true if the user is allowed to upload, false if not.
	 */
	static function isUploadAllowedForTitle( $user, $desiredDestName, $localPath, $removeLocalFile, $language, &$errorText ) {
		// Initialize path info
		$fileSize = filesize( $localPath );
		$upload = new UploadFromLocalFile;
		$upload->initializePathInfo( $desiredDestName, $localPath, $fileSize, $removeLocalFile );

		// Upload verification
		$details = $upload->verifyUpload();
		if ( $details['status'] != UploadBase::OK ) {
			wfDebug( __METHOD__ . ": upload->verifyUpload() failed.\n" );
			$errorText = self::processVerificationError( $details, $language, $desiredDestName );
			return false;
		}

		// Verify permissions for this title
		$permErrors = $upload->verifyTitlePermissions( $user );
		if ( $permErrors !== true ) {
			wfDebug( __METHOD__ . ": upload->verifyTitlePermissions() failed.\n" );
			$code = array_shift( $permErrors[0] );
			$errorText = self::getUploadErrorMessage( wfMessage( $code, $permErrors[0] )->parse() );
			return false;
		}

		return true;
	}

	/**
	 * Provides output to the user for an error result from UploadBase::verifyUpload
	 * Based on SpecialUpload::processVerificationError.
	 *
	 * @param[in] array $details result of UploadBase::verifyUpload
	 * @param[in] Language $language for adding comma-separated lists to some messages.
	 * @param[in] string $filename is the name of the file for which upload verification failed.
	 * @return string error message.
	 * @throws MWException
	 */
	static function processVerificationError( $details, $language, $filename ) {
		global $wgFileExtensions;

		switch( $details['status'] ) {
			case UploadBase::ILLEGAL_FILENAME:
				return self::getUploadErrorMessage( wfMessage( 'illegalfilename', $details['filtered'] )->parse(), $filename );
			case UploadBase::FILENAME_TOO_LONG:
				return self::getUploadErrorMessage( wfMessage( 'filename-toolong' )->text(), $filename );
			case UploadBase::WINDOWS_NONASCII_FILENAME:
				return self::getUploadErrorMessage( wfMessage( 'windows-nonascii-filename' )->parse(), $filename );
			case UploadBase::FILE_TOO_LARGE:
				return self::getUploadErrorMessage( wfMessage( 'largefileserver' )->text(), $filename );
			case UploadBase::FILETYPE_BADTYPE:
				$msg = wfMessage( 'filetype-banned-type' );
				if ( isset( $details['blacklistedExt'] ) ) {
					$msg->params( $language->commaList( $details['blacklistedExt'] ) );
				} else {
					$msg->params( $details['finalExt'] );
				}
				$msg->params( $language->commaList( $wgFileExtensions ), count( $wgFileExtensions ) );

				// Add PLURAL support for the first parameter. This results
				// in a bit unlogical parameter sequence, but does not break
				// old translations
				if ( isset( $details['blacklistedExt'] ) ) {
					$msg->params( count( $details['blacklistedExt'] ) );
				} else {
					$msg->params( 1 );
				}

				return self::getUploadErrorMessage( $msg->parse(), $filename );
			case UploadBase::VERIFICATION_ERROR:
				unset( $details['status'] );
				$code = array_shift( $details['details'] );
				return self::getUploadErrorMessage( wfMessage( $code, $details['details'] )->parse(), $filename );
			case UploadBase::HOOK_ABORTED:
				if ( is_array( $details['error'] ) ) { # allow hooks to return error details in an array
					$args = $details['error'];
					$error = array_shift( $args );
				} else {
					$error = $details['error'];
					$args = null;
				}

				return self::getUploadErrorMessage( wfMessage( $error, $args )->parse(), $filename );
			default:
				throw new MWException( __METHOD__ . ": Unexpected value `{$details['status']}`" );
		}
	}

	/**
	 * Based on SpecialUpload::showUploadError.
	 *
	 * @param[in] string $message message to be included in the result
	 * @param[in] string $filename is the name of the file for which upload verification failed.
	 * @return string upload error message.
	 */
	static function getUploadErrorMessage( $message, $filename ) {
		return wfMessage( 'graphviz-uploaderror', $filename )->text() . $message;
	}

	/**
	 * Given an i18n message name and arguments, return the message text.
	 * @param[in] string $messageName is the name of a message in the i18n file.
	 * A variable number of message arguments is supported.
	 * @return string error message for $messageName.
	 * @author Keith Welter
	 */
	static function i18nMessage( $messageName ) {
		if ( func_num_args() < 2 ) {
			return wfMessage( $messageName )->text();
		} else {
			$messageArgs = array_slice( func_get_args(), 1 );
			return wfMessage( $messageName, $messageArgs )->text();
		}
	}

	/**
	 * Check if the given file has been uploaded to the wiki.
	 *
	 * @param[in] string $filename is the name of the file to check.
	 * @return File: file, or null on failure
	 */
	static function getUploadedFile( $fileName ) {
		$upload = new UploadFromLocalFile;
		$upload->initializePathInfo( $fileName, "", 0, false );
		$title = $upload->getTitle();
		$file = wfFindFile( $title );
		return $file;
	}

	/**
	 * Upload a file from the given local path to the given destination name.
	 * Based on SpecialUpload::processUpload
	 *
	 * @param[in] string $desiredDestName the desired destination name of the file to be uploaded.
	 * @param[in] string $localPath the local path of the file to be uploaded.
	 * @param[in] User $user is the user performing the upload.
	 * @param[in] string $comment is the upload description.
	 * @param[in] string|null $pageText text to use for the description page or null to keep the text of an existing page.
	 * @param[in] bool $watch indicates whether or not to make the user watch the new page.
	 * @param[in] bool $removeLocalFile remove the local file?
	 *
	 * @return bool true if the upload succeeds, false if it fails.
	 */
	static function upload( $desiredDestName, $localPath, $user, $comment, $pageText, $watch, $removeLocalFile ) {
		// Initialize path info
		$fileSize = filesize( $localPath );
		$upload = new UploadFromLocalFile;
		$upload->initializePathInfo( $desiredDestName, $localPath, $fileSize, $removeLocalFile );

		$title = $upload->getTitle();

		$status = $upload->performUpload( $comment, $pageText, $watch, $user );
		if ( !$status->isGood() ) {
			return false;
		}

		RepoGroup::singleton()->clearCache( $title );

		return true;
	}
}

/**
 * Supports local file uploads in the absence of a WebRequest.
 * Simplified from UploadFromFile.
 *
 * @ingroup Upload
 * @author Keith Welter
 */
class UploadFromLocalFile extends UploadBase {
	/**
	 * This function is a no-op because a WebRequest is not used.
	 * It exists here because it is abstract in UploadBase.
	 */
	function initializeFromRequest( &$request ) {
	}

	/**
	 * @return string 'file'
	 */
	public function getSourceType() {
		return 'file';
	}
}
