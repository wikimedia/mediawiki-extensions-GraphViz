<?php

namespace MediaWiki\Extension\GraphViz;

use File;
use RepoGroup;
use Status;
use UploadBase;
use User;

/**
 * Supports local file uploads in the absence of a WebRequest.
 * Simplified from UploadFromFile.
 *
 * @ingroup Upload
 * @author Keith Welter
 */
class UploadFromLocalFile extends UploadBase {

	/** @var User */
	protected $user;

	/**
	 * Set the user to use for the upload.
	 * @param User $user
	 */
	public function setUser( User $user ) {
		$this->user = $user;
	}

	/**
	 * This function is a no-op because a WebRequest is not used.
	 * It exists here because it is abstract in UploadBase.
	 * @param WebRequest &$request
	 */
	function initializeFromRequest( &$request ) {
	}

	/**
	 * @return string 'file'
	 */
	public function getSourceType() {
		return 'file';
	}

	/**
	 * Return the local file and initializes if necessary.
	 *
	 * @return UploadLocalFile|null
	 */
	public function getLocalFile() {
		if ( is_null( $this->mLocalFile ) ) {
			$nt = $this->getTitle();
			$repo = RepoGroup::singleton()->getLocalRepo();
			$this->mLocalFile = is_null( $nt ) ? null : UploadLocalFile::newFromTitle( $nt, $repo );
		}

		return $this->mLocalFile;
	}

	/**
	 * Really perform the upload.
	 *
	 * @param string $comment
	 * @return Status Indicating the whether the upload succeeded.
	 */
	public function performUpload2( $comment ) {
		$this->getLocalFile()->load( File::READ_LATEST );
		$props = $this->mFileProps;

		$pageText = '';

		$status = $this->getLocalFile()->upload2(
			$this->mTempPath,
			$comment,
			$props,
			File::DELETE_SOURCE,
			$this->user
		);

		if ( $status->isGood() ) {
			$this->postProcessUpload();
		}

		return $status;
	}
}
