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

namespace MediaWiki\Extension\GraphViz;

use CdnCacheUpdate;
use DeferredUpdates;
use File;
use FileRepo;
use FSFile;
use HTMLCacheUpdate;
use LinksUpdate;
use LocalFile;
use MediaHandler;
use MWException;
use RepoGroup;
use RequestContext;
use SiteStatsUpdate;
use Status;
use Title;
use UploadBase;
use User;

/**
 * Implements local file uploads in the absence of a WebRequest in conjunction with
 * UploadFromLocalFile.
 *
 * @ingroup Extensions
 * @ingroup Upload
 * @author Keith Welter
 */
class UploadLocalFile extends LocalFile {
	/**
	 * Check if uploading is allowed for the given user.
	 * Based on SpecialUpload::execute.
	 *
	 * @param User $user is the user to check.
	 * @param string &$errorText is populated with an error message if the user is not allowed to
	 * upload.
	 * @return bool true if the user is allowed to upload, false if not.
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
	 * @param UploadFromLocalFile $upload
	 * @param User $user is the user to check.
	 * @param string $desiredDestName the desired destination name of the file to be uploaded.
	 * @param string $localPath the local path of the file to be uploaded.
	 * @param bool $removeLocalFile remove the local file?
	 * @param string &$errorText is populated with an error message if the user is not allowed to
	 * upload.
	 * @return bool true if the user is allowed to upload, false if not.
	 */
	static function isUploadAllowedForTitle(
		UploadFromLocalFile $upload, User $user, $desiredDestName, $localPath, $removeLocalFile,
		&$errorText
	) {
		// Initialize path info
		$fileSize = file_exists( $localPath ) ? filesize( $localPath ) : null;
		$upload->initializePathInfo( $desiredDestName, $localPath, $fileSize, $removeLocalFile );

		// Upload verification
		$details = $upload->verifyUpload();
		if ( $details['status'] != UploadBase::OK ) {
			wfDebug( __METHOD__ . ": upload->verifyUpload() failed.\n" );
			$errorText = self::processVerificationError( $details, $desiredDestName );
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
	 * @param array $details result of UploadBase::verifyUpload
	 * @param string $filename is the name of the file for which upload verification failed.
	 * @return string error message.
	 * @throws MWException
	 */
	static function processVerificationError( $details, $filename ) {
		switch ( $details['status'] ) {
			case UploadBase::ILLEGAL_FILENAME:
				$message = wfMessage( 'illegalfilename', $details['filtered'] );
				return self::getUploadErrorMessage( $message->parse(), $filename );
			case UploadBase::FILENAME_TOO_LONG:
				$message = wfMessage( 'filename-toolong' );
				return self::getUploadErrorMessage( $message->text(), $filename );
			case UploadBase::WINDOWS_NONASCII_FILENAME:
				$message = wfMessage( 'windows-nonascii-filename' );
				return self::getUploadErrorMessage( $message->parse(), $filename );
			case UploadBase::FILE_TOO_LARGE:
				$message = wfMessage( 'largefileserver' );
				return self::getUploadErrorMessage( $message->text(), $filename );
			case UploadBase::FILETYPE_BADTYPE:
				$lang = RequestContext::getMain()->getLanguage();
				$fileExtensions = RequestContext::getMain()->getConfig()->get( 'FileExtensions' );
				$msg = wfMessage( 'filetype-banned-type' );
				if ( isset( $details['blacklistedExt'] ) ) {
					$msg->params( $lang->commaList( $details['blacklistedExt'] ) );
				} else {
					$msg->params( $details['finalExt'] );
				}
				$msg->params( $lang->commaList( $fileExtensions ), count( $fileExtensions ) );

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
				$message = wfMessage( $code, $details['details'] );
				return self::getUploadErrorMessage( $message->parse(), $filename );
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
	 * @param string $message message to be included in the result
	 * @param string $filename is the name of the file for which upload verification failed.
	 * @return string upload error message.
	 */
	static function getUploadErrorMessage( $message, $filename = '' ) {
		return wfMessage( 'graphviz-uploaderror', $filename )->text() . $message;
	}

	/**
	 * Given an i18n message name and arguments, return the message text.
	 * @param string $messageName is the name of a message in the i18n file.
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
	 * @param string $fileName is the name of the file to check.
	 * @return File file, or null on failure
	 */
	static function getUploadedFile( $fileName ) {
		$upload = new UploadFromLocalFile;
		$upload->initializePathInfo( $fileName, "", 0, false );
		$title = $upload->getTitle();
		$file = wfFindFile( $title );
		return $file;
	}

	/**
	 * Create a LocalFile from a title
	 *
	 * @param Title $title
	 * @param FileRepo $repo
	 * @param null $unused
	 *
	 * @return UploadLocalFile
	 */
	static function newFromTitle( $title, $repo, $unused = null ) {
		return new self( $title, $repo );
	}

	/**
	 * Upload a file from the given local path to the given destination name.
	 * Based on SpecialUpload::processUpload
	 *
	 * @param UploadFromLocalFile $upload
	 * @param string $desiredDestName the desired destination name of the file to be uploaded.
	 * @param string $localPath the local path of the file to be uploaded.
	 * @param bool $removeLocalFile remove the local file?
	 *
	 * @return bool true if the upload succeeds, false if it fails.
	 */
	static function uploadWithoutFilePage(
		UploadFromLocalFile $upload, $desiredDestName, $localPath, $removeLocalFile
	) {
		// Initialize path info
		$fileSize = filesize( $localPath );
		$upload->initializePathInfo( $desiredDestName, $localPath, $fileSize, $removeLocalFile );

		$title = $upload->getTitle();
		$comment = '';

		$status = $upload->performUpload2( $comment );
		if ( !$status->isGood() ) {
			return false;
		}

		RepoGroup::singleton()->clearCache( $title );

		return true;
	}

	/**
	 * Upload a file and record it in the DB
	 * @param string|FSFile $src Source storage path, virtual URL, or filesystem path
	 * @param string $comment Upload description
	 * @param array|bool $props File properties, if known. This can be used to
	 *   reduce the upload time when uploading virtual URLs for which the file
	 *   info is already known
	 * @param int|bool $flags Flags for publish()
	 * @param User $user The user who should upload the file.
	 * @return Status On success, the value member contains the
	 *     archive name, or an empty string if it was a new file.
	 */
	function upload2( $src, $comment, $props, $flags, User $user ) {
		if ( $this->getRepo()->getReadOnlyReason() !== false ) {
			return $this->readOnlyFatalStatus();
		}

		$srcPath = ( $src instanceof FSFile ) ? $src->getPath() : $src;

		$options = [];
		$handler = MediaHandler::getHandler( $props['mime'] );
		if ( $handler ) {
			$options['headers'] = $handler->getContentHeaders( $props['metadata'] );
		} else {
			$options['headers'] = [];
		}

		$this->lock(); // begin
		$status = $this->publish( $src, $flags, $options );

		if ( $status->successCount >= 2 ) {
			// There will be a copy+(one of move,copy,store).
			// The first succeeding does not commit us to updating the DB
			// since it simply copied the current version to a timestamped file name.
			// It is only *preferable* to avoid leaving such files orphaned.
			// Once the second operation goes through, then the current version was
			// updated and we must therefore update the DB too.
			$oldver = $status->value;
			if ( !$this->recordUpload3( $oldver, $comment, $props, $user ) ) {
				$status->fatal( 'filenotfound', $srcPath );
			}
		}

		$this->unlock(); // done

		return $status;
	}

	/**
	 * Record a file upload in the image table only
	 * @param string $oldver
	 * @param string $comment
	 * @param bool|array $props
	 * @param User $user The user (either the current user, or the special GraphViz user).
	 * @return bool
	 */
	function recordUpload3( $oldver, $comment, $props, User $user ) {
		$dbw = $this->repo->getMasterDB();

		$timestamp = $dbw->timestamp();
		$allowTimeKludge = true;

		$props['description'] = $comment;
		$props['user'] = $user->getId();
		$props['user_text'] = $user->getName();
		$props['timestamp'] = wfTimestamp( TS_MW, $timestamp ); // DB -> TS_MW
		$this->setProps( $props );

		# major_mime and minor_mime are private in the parent so use local variables instead
		list( $major_mime, $minor_mime ) = self::splitMime( $this->mime );

		# Fail now if the file isn't there
		if ( !$this->fileExists ) {
			wfDebug( __METHOD__ . ": File " . $this->getRel() . " went missing!\n" );
			$dbw->rollback( __METHOD__ );

			return false;
		}

		$reupload = false;

		$dbw->startAtomic( __METHOD__ );

		# Test to see if the row exists using INSERT IGNORE
		# This avoids race conditions by locking the row until the commit, and also
		# doesn't deadlock. SELECT FOR UPDATE causes a deadlock for every race condition.
		$dbw->insert( 'image',
			[
				'img_name' => $this->getName(),
				'img_size' => $this->size,
				'img_width' => intval( $this->width ),
				'img_height' => intval( $this->height ),
				'img_bits' => $this->bits,
				'img_media_type' => $this->media_type,
				'img_major_mime' => $major_mime,
				'img_minor_mime' => $minor_mime,
				'img_timestamp' => $timestamp,
				'img_description' => $comment,
				'img_user' => $user->getId(),
				'img_user_text' => $user->getName(),
				'img_metadata' => $dbw->encodeBlob( $this->metadata ),
				'img_sha1' => $this->sha1
			],
			__METHOD__,
			'IGNORE'
		);
		if ( $dbw->affectedRows() == 0 ) {
			if ( $allowTimeKludge ) {
				# Use LOCK IN SHARE MODE to ignore any transaction snapshotting
				$ltimestamp = $dbw->selectField( 'image', 'img_timestamp',
					[ 'img_name' => $this->getName() ],
					__METHOD__,
					[ 'LOCK IN SHARE MODE' ] );
				$lUnixtime = $ltimestamp ? wfTimestamp( TS_UNIX, $ltimestamp ) : false;
				# Avoid a timestamp that is not newer than the last version
				# TODO: the image/oldimage tables should be like page/revision with an ID field
				if ( $lUnixtime && wfTimestamp( TS_UNIX, $timestamp ) <= $lUnixtime ) {
					sleep( 1 ); // fast enough re-uploads would go far in the future otherwise
					$timestamp = $dbw->timestamp( $lUnixtime + 1 );
					$props['timestamp'] = wfTimestamp( TS_MW, $timestamp ); // DB -> TS_MW
					# timestamp is private in the parent so use setProps to update it
					$this->setProps( $props );
				}
			}

			# (bug 34993) Note: $oldver can be empty here, if the previous
			# version of the file was broken. Allow registration of the new
			# version to continue anyway, because that's better than having
			# an image that's not fixable by user operations.

			$reupload = true;
			# Collision, this is an update of a file
			# Insert previous contents into oldimage
			$dbw->insertSelect( 'oldimage', 'image',
				[
					'oi_name' => 'img_name',
					'oi_archive_name' => $dbw->addQuotes( $oldver ),
					'oi_size' => 'img_size',
					'oi_width' => 'img_width',
					'oi_height' => 'img_height',
					'oi_bits' => 'img_bits',
					'oi_timestamp' => 'img_timestamp',
					'oi_description' => 'img_description',
					'oi_user' => 'img_user',
					'oi_user_text' => 'img_user_text',
					'oi_metadata' => 'img_metadata',
					'oi_media_type' => 'img_media_type',
					'oi_major_mime' => 'img_major_mime',
					'oi_minor_mime' => 'img_minor_mime',
					'oi_sha1' => 'img_sha1'
				],
				[ 'img_name' => $this->getName() ],
				__METHOD__
			);

			# Update the current image row
			$dbw->update( 'image',
				[ /* SET */
					'img_size' => $this->size,
					'img_width' => intval( $this->width ),
					'img_height' => intval( $this->height ),
					'img_bits' => $this->bits,
					'img_media_type' => $this->media_type,
					'img_major_mime' => $major_mime,
					'img_minor_mime' => $minor_mime,
					'img_timestamp' => $timestamp,
					'img_description' => $comment,
					'img_user' => $user->getId(),
					'img_user_text' => $user->getName(),
					'img_metadata' => $dbw->encodeBlob( $this->metadata ),
					'img_sha1' => $this->sha1
				],
				[ 'img_name' => $this->getName() ],
				__METHOD__
			);
		} else {
			# This is a new file, so update the image count
			DeferredUpdates::addUpdate( SiteStatsUpdate::factory( [ 'images' => 1 ] ) );
		}

		# Defer purges, page creation, and link updates in case they error out.
		# The most important thing is that files and the DB registry stay synced.
		$dbw->endAtomic( __METHOD__ );

		# Update memcache after the commit
		$this->invalidateCache();

		if ( $reupload ) {
			# Delete old thumbnails
			$this->purgeThumbnails();

			# Remove the old file from the squid cache
			CdnCacheUpdate::purge( [ $this->getURL() ] );
		}

		# Invalidate cache for all pages using this file
		$update = new HTMLCacheUpdate( $this->getTitle(), 'imagelinks' );
		$update->doUpdate();
		if ( !$reupload ) {
			LinksUpdate::queueRecursiveJobsForTable( $this->getTitle(), 'imagelinks' );
		}

		return true;
	}
}
