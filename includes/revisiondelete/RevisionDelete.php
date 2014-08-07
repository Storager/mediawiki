<?php
/**
 * Base implementations for deletable items.
 *
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
 * @ingroup RevisionDelete
 */

/**
 * List for revision table items
 *
 * This will check both the 'revision' table for live revisions and the
 * 'archive' table for traditionally-deleted revisions that have an
 * ar_rev_id saved.
 *
 * See RevDelRevisionItem and RevDelArchivedRevisionItem for items.
 */
class RevDelRevisionList extends RevDelList {
	/** @var int */
	var $currentRevId;

	public function getType() {
		return 'revision';
	}

	public static function getRelationType() {
		return 'rev_id';
	}

	public static function getRestriction() {
		return 'deleterevision';
	}

	public static function getRevdelConstant() {
		return Revision::DELETED_TEXT;
	}

	public static function suggestTarget( $target, array $ids ) {
		$rev = Revision::newFromId( $ids[0] );
		return $rev ? $rev->getTitle() : $target;
	}

	/**
	 * @param DatabaseBase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$ids = array_map( 'intval', $this->ids );
		$live = $db->select(
			array( 'revision', 'page', 'user' ),
			array_merge( Revision::selectFields(), Revision::selectUserFields() ),
			array(
				'rev_page' => $this->title->getArticleID(),
				'rev_id' => $ids,
			),
			__METHOD__,
			array( 'ORDER BY' => 'rev_id DESC' ),
			array(
				'page' => Revision::pageJoinCond(),
				'user' => Revision::userJoinCond() )
		);

		if ( $live->numRows() >= count( $ids ) ) {
			// All requested revisions are live, keeps things simple!
			return $live;
		}

		// Check if any requested revisions are available fully deleted.
		$archived = $db->select( array( 'archive' ), Revision::selectArchiveFields(),
			array(
				'ar_rev_id' => $ids
			),
			__METHOD__,
			array( 'ORDER BY' => 'ar_rev_id DESC' )
		);

		if ( $archived->numRows() == 0 ) {
			return $live;
		} elseif ( $live->numRows() == 0 ) {
			return $archived;
		} else {
			// Combine the two! Whee
			$rows = array();
			foreach ( $live as $row ) {
				$rows[$row->rev_id] = $row;
			}
			foreach ( $archived as $row ) {
				$rows[$row->ar_rev_id] = $row;
			}
			krsort( $rows );
			return new FakeResultWrapper( array_values( $rows ) );
		}
	}

	public function newItem( $row ) {
		if ( isset( $row->rev_id ) ) {
			return new RevDelRevisionItem( $this, $row );
		} elseif ( isset( $row->ar_rev_id ) ) {
			return new RevDelArchivedRevisionItem( $this, $row );
		} else {
			// This shouldn't happen. :)
			throw new MWException( 'Invalid row type in RevDelRevisionList' );
		}
	}

	public function getCurrent() {
		if ( is_null( $this->currentRevId ) ) {
			$dbw = wfGetDB( DB_MASTER );
			$this->currentRevId = $dbw->selectField(
				'page', 'page_latest', $this->title->pageCond(), __METHOD__ );
		}
		return $this->currentRevId;
	}

	public function getSuppressBit() {
		return Revision::DELETED_RESTRICTED;
	}

	public function doPreCommitUpdates() {
		$this->title->invalidateCache();
		return Status::newGood();
	}

	public function doPostCommitUpdates() {
		$this->title->purgeSquid();
		// Extensions that require referencing previous revisions may need this
		wfRunHooks( 'ArticleRevisionVisibilitySet', array( &$this->title ) );
		return Status::newGood();
	}
}

/**
 * Item class for a live revision table row
 */
class RevDelRevisionItem extends RevDelItem {
	/** @var Revision */
	var $revision;

	public function __construct( $list, $row ) {
		parent::__construct( $list, $row );
		$this->revision = new Revision( $row );
	}

	public function getIdField() {
		return 'rev_id';
	}

	public function getTimestampField() {
		return 'rev_timestamp';
	}

	public function getAuthorIdField() {
		return 'rev_user';
	}

	public function getAuthorNameField() {
		return 'rev_user_text';
	}

	public function canView() {
		return $this->revision->userCan( Revision::DELETED_RESTRICTED, $this->list->getUser() );
	}

	public function canViewContent() {
		return $this->revision->userCan( Revision::DELETED_TEXT, $this->list->getUser() );
	}

	public function getBits() {
		return $this->revision->getVisibility();
	}

	public function setBits( $bits ) {
		$dbw = wfGetDB( DB_MASTER );
		// Update revision table
		$dbw->update( 'revision',
			array( 'rev_deleted' => $bits ),
			array(
				'rev_id' => $this->revision->getId(),
				'rev_page' => $this->revision->getPage(),
				'rev_deleted' => $this->getBits()
			),
			__METHOD__
		);
		if ( !$dbw->affectedRows() ) {
			// Concurrent fail!
			return false;
		}
		// Update recentchanges table
		$dbw->update( 'recentchanges',
			array(
				'rc_deleted' => $bits,
				'rc_patrolled' => 1
			),
			array(
				'rc_this_oldid' => $this->revision->getId(), // condition
				// non-unique timestamp index
				'rc_timestamp' => $dbw->timestamp( $this->revision->getTimestamp() ),
			),
			__METHOD__
		);
		return true;
	}

	public function isDeleted() {
		return $this->revision->isDeleted( Revision::DELETED_TEXT );
	}

	public function isHideCurrentOp( $newBits ) {
		return ( $newBits & Revision::DELETED_TEXT )
			&& $this->list->getCurrent() == $this->getId();
	}

	/**
	 * Get the HTML link to the revision text.
	 * Overridden by RevDelArchiveItem.
	 * @return string
	 */
	protected function getRevisionLink() {
		$date = htmlspecialchars( $this->list->getLanguage()->userTimeAndDate(
			$this->revision->getTimestamp(), $this->list->getUser() ) );

		if ( $this->isDeleted() && !$this->canViewContent() ) {
			return $date;
		}
		return Linker::linkKnown(
			$this->list->title,
			$date,
			array(),
			array(
				'oldid' => $this->revision->getId(),
				'unhide' => 1
			)
		);
	}

	/**
	 * Get the HTML link to the diff.
	 * Overridden by RevDelArchiveItem
	 * @return string
	 */
	protected function getDiffLink() {
		if ( $this->isDeleted() && !$this->canViewContent() ) {
			return $this->list->msg( 'diff' )->escaped();
		} else {
			return Linker::linkKnown(
					$this->list->title,
					$this->list->msg( 'diff' )->escaped(),
					array(),
					array(
						'diff' => $this->revision->getId(),
						'oldid' => 'prev',
						'unhide' => 1
					)
				);
		}
	}

	public function getHTML() {
		$difflink = $this->list->msg( 'parentheses' )
			->rawParams( $this->getDiffLink() )->escaped();
		$revlink = $this->getRevisionLink();
		$userlink = Linker::revUserLink( $this->revision );
		$comment = Linker::revComment( $this->revision );
		if ( $this->isDeleted() ) {
			$revlink = "<span class=\"history-deleted\">$revlink</span>";
		}
		return "<li>$difflink $revlink $userlink $comment</li>";
	}

	public function getApiData( ApiResult $result ) {
		$rev = $this->revision;
		$user = $this->list->getUser();
		$ret = array(
			'id' => $rev->getId(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $rev->getTimestamp() ),
		);
		$ret += $rev->isDeleted( Revision::DELETED_USER ) ? array( 'userhidden' => '' ) : array();
		$ret += $rev->isDeleted( Revision::DELETED_COMMENT ) ? array( 'commenthidden' => '' ) : array();
		$ret += $rev->isDeleted( Revision::DELETED_TEXT ) ? array( 'texthidden' => '' ) : array();
		if ( $rev->userCan( Revision::DELETED_USER, $user ) ) {
			$ret += array(
				'userid' => $rev->getUser( Revision::FOR_THIS_USER ),
				'user' => $rev->getUserText( Revision::FOR_THIS_USER ),
			);
		}
		if ( $rev->userCan( Revision::DELETED_COMMENT, $user ) ) {
			$ret += array(
				'comment' => $rev->getComment( Revision::FOR_THIS_USER ),
			);
		}
		return $ret;
	}
}

/**
 * List for archive table items, i.e. revisions deleted via action=delete
 */
class RevDelArchiveList extends RevDelRevisionList {
	public function getType() {
		return 'archive';
	}

	public static function getRelationType() {
		return 'ar_timestamp';
	}

	/**
	 * @param DatabaseBase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$timestamps = array();
		foreach ( $this->ids as $id ) {
			$timestamps[] = $db->timestamp( $id );
		}
		return $db->select( 'archive', Revision::selectArchiveFields(),
				array(
					'ar_namespace' => $this->title->getNamespace(),
					'ar_title' => $this->title->getDBkey(),
					'ar_timestamp' => $timestamps
				),
				__METHOD__,
				array( 'ORDER BY' => 'ar_timestamp DESC' )
			);
	}

	public function newItem( $row ) {
		return new RevDelArchiveItem( $this, $row );
	}

	public function doPreCommitUpdates() {
		return Status::newGood();
	}

	public function doPostCommitUpdates() {
		return Status::newGood();
	}
}

/**
 * Item class for a archive table row
 */
class RevDelArchiveItem extends RevDelRevisionItem {
	public function __construct( $list, $row ) {
		RevDelItem::__construct( $list, $row );
		$this->revision = Revision::newFromArchiveRow( $row,
			array( 'page' => $this->list->title->getArticleID() ) );
	}

	public function getIdField() {
		return 'ar_timestamp';
	}

	public function getTimestampField() {
		return 'ar_timestamp';
	}

	public function getAuthorIdField() {
		return 'ar_user';
	}

	public function getAuthorNameField() {
		return 'ar_user_text';
	}

	public function getId() {
		# Convert DB timestamp to MW timestamp
		return $this->revision->getTimestamp();
	}

	public function setBits( $bits ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'archive',
			array( 'ar_deleted' => $bits ),
			array(
				'ar_namespace' => $this->list->title->getNamespace(),
				'ar_title' => $this->list->title->getDBkey(),
				// use timestamp for index
				'ar_timestamp' => $this->row->ar_timestamp,
				'ar_rev_id' => $this->row->ar_rev_id,
				'ar_deleted' => $this->getBits()
			),
			__METHOD__ );
		return (bool)$dbw->affectedRows();
	}

	protected function getRevisionLink() {
		$date = htmlspecialchars( $this->list->getLanguage()->userTimeAndDate(
			$this->revision->getTimestamp(), $this->list->getUser() ) );

		if ( $this->isDeleted() && !$this->canViewContent() ) {
			return $date;
		}

		return Linker::link(
			SpecialPage::getTitleFor( 'Undelete' ),
			$date,
			array(),
			array(
				'target' => $this->list->title->getPrefixedText(),
				'timestamp' => $this->revision->getTimestamp()
			)
		);
	}

	protected function getDiffLink() {
		if ( $this->isDeleted() && !$this->canViewContent() ) {
			return $this->list->msg( 'diff' )->escaped();
		}

		return Linker::link(
			SpecialPage::getTitleFor( 'Undelete' ),
			$this->list->msg( 'diff' )->escaped(),
			array(),
			array(
				'target' => $this->list->title->getPrefixedText(),
				'diff' => 'prev',
				'timestamp' => $this->revision->getTimestamp()
			)
		);
	}
}

/**
 * Item class for a archive table row by ar_rev_id -- actually
 * used via RevDelRevisionList.
 */
class RevDelArchivedRevisionItem extends RevDelArchiveItem {
	public function __construct( $list, $row ) {
		RevDelItem::__construct( $list, $row );

		$this->revision = Revision::newFromArchiveRow( $row,
			array( 'page' => $this->list->title->getArticleID() ) );
	}

	public function getIdField() {
		return 'ar_rev_id';
	}

	public function getId() {
		return $this->revision->getId();
	}

	public function setBits( $bits ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'archive',
			array( 'ar_deleted' => $bits ),
			array( 'ar_rev_id' => $this->row->ar_rev_id,
				'ar_deleted' => $this->getBits()
			),
			__METHOD__ );
		return (bool)$dbw->affectedRows();
	}
}

/**
 * List for oldimage table items
 */
class RevDelFileList extends RevDelList {
	/** @var array */
	var $storeBatch;

	/** @var array */
	var $deleteBatch;

	/** @var array */
	var $cleanupBatch;

	public function getType() {
		return 'oldimage';
	}

	public static function getRelationType() {
		return 'oi_archive_name';
	}

	public static function getRestriction() {
		return 'deleterevision';
	}

	public static function getRevdelConstant() {
		return File::DELETED_FILE;
	}

	/**
	 * @param DatabaseBase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$archiveNames = array();
		foreach ( $this->ids as $timestamp ) {
			$archiveNames[] = $timestamp . '!' . $this->title->getDBkey();
		}
		return $db->select(
			'oldimage',
			OldLocalFile::selectFields(),
			array(
				'oi_name' => $this->title->getDBkey(),
				'oi_archive_name' => $archiveNames
			),
			__METHOD__,
			array( 'ORDER BY' => 'oi_timestamp DESC' )
		);
	}

	public function newItem( $row ) {
		return new RevDelFileItem( $this, $row );
	}

	public function clearFileOps() {
		$this->deleteBatch = array();
		$this->storeBatch = array();
		$this->cleanupBatch = array();
	}

	public function doPreCommitUpdates() {
		$status = Status::newGood();
		$repo = RepoGroup::singleton()->getLocalRepo();
		if ( $this->storeBatch ) {
			$status->merge( $repo->storeBatch( $this->storeBatch, FileRepo::OVERWRITE_SAME ) );
		}
		if ( !$status->isOK() ) {
			return $status;
		}
		if ( $this->deleteBatch ) {
			$status->merge( $repo->deleteBatch( $this->deleteBatch ) );
		}
		if ( !$status->isOK() ) {
			// Running cleanupDeletedBatch() after a failed storeBatch() with the DB already
			// modified (but destined for rollback) causes data loss
			return $status;
		}
		if ( $this->cleanupBatch ) {
			$status->merge( $repo->cleanupDeletedBatch( $this->cleanupBatch ) );
		}
		return $status;
	}

	public function doPostCommitUpdates() {
		$file = wfLocalFile( $this->title );
		$file->purgeCache();
		$file->purgeDescription();
		$purgeUrls = array();
		foreach ( $this->ids as $timestamp ) {
			$archiveName = $timestamp . '!' . $this->title->getDBkey();
			$file->purgeOldThumbnails( $archiveName );
			$purgeUrls[] = $file->getArchiveUrl( $archiveName );
		}
		if ( $this->getConfig()->get( 'UseSquid' ) ) {
			// purge full images from cache
			SquidUpdate::purge( $purgeUrls );
		}
		return Status::newGood();
	}

	public function getSuppressBit() {
		return File::DELETED_RESTRICTED;
	}
}

/**
 * Item class for an oldimage table row
 */
class RevDelFileItem extends RevDelItem {
	/** @var File */
	var $file;

	public function __construct( $list, $row ) {
		parent::__construct( $list, $row );
		$this->file = RepoGroup::singleton()->getLocalRepo()->newFileFromRow( $row );
	}

	public function getIdField() {
		return 'oi_archive_name';
	}

	public function getTimestampField() {
		return 'oi_timestamp';
	}

	public function getAuthorIdField() {
		return 'oi_user';
	}

	public function getAuthorNameField() {
		return 'oi_user_text';
	}

	public function getId() {
		$parts = explode( '!', $this->row->oi_archive_name );
		return $parts[0];
	}

	public function canView() {
		return $this->file->userCan( File::DELETED_RESTRICTED, $this->list->getUser() );
	}

	public function canViewContent() {
		return $this->file->userCan( File::DELETED_FILE, $this->list->getUser() );
	}

	public function getBits() {
		return $this->file->getVisibility();
	}

	public function setBits( $bits ) {
		# Queue the file op
		# @todo FIXME: Move to LocalFile.php
		if ( $this->isDeleted() ) {
			if ( $bits & File::DELETED_FILE ) {
				# Still deleted
			} else {
				# Newly undeleted
				$key = $this->file->getStorageKey();
				$srcRel = $this->file->repo->getDeletedHashPath( $key ) . $key;
				$this->list->storeBatch[] = array(
					$this->file->repo->getVirtualUrl( 'deleted' ) . '/' . $srcRel,
					'public',
					$this->file->getRel()
				);
				$this->list->cleanupBatch[] = $key;
			}
		} elseif ( $bits & File::DELETED_FILE ) {
			# Newly deleted
			$key = $this->file->getStorageKey();
			$dstRel = $this->file->repo->getDeletedHashPath( $key ) . $key;
			$this->list->deleteBatch[] = array( $this->file->getRel(), $dstRel );
		}

		# Do the database operations
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'oldimage',
			array( 'oi_deleted' => $bits ),
			array(
				'oi_name' => $this->row->oi_name,
				'oi_timestamp' => $this->row->oi_timestamp,
				'oi_deleted' => $this->getBits()
			),
			__METHOD__
		);
		return (bool)$dbw->affectedRows();
	}

	public function isDeleted() {
		return $this->file->isDeleted( File::DELETED_FILE );
	}

	/**
	 * Get the link to the file.
	 * Overridden by RevDelArchivedFileItem.
	 * @return string
	 */
	protected function getLink() {
		$date = htmlspecialchars( $this->list->getLanguage()->userTimeAndDate(
			$this->file->getTimestamp(), $this->list->getUser() ) );

		if ( !$this->isDeleted() ) {
			# Regular files...
			return Html::rawElement( 'a', array( 'href' => $this->file->getUrl() ), $date );
		}

		# Hidden files...
		if ( !$this->canViewContent() ) {
			$link = $date;
		} else {
			$link = Linker::link(
				SpecialPage::getTitleFor( 'Revisiondelete' ),
				$date,
				array(),
				array(
					'target' => $this->list->title->getPrefixedText(),
					'file' => $this->file->getArchiveName(),
					'token' => $this->list->getUser()->getEditToken(
						$this->file->getArchiveName() )
				)
			);
		}
		return '<span class="history-deleted">' . $link . '</span>';
	}

	/**
	 * Generate a user tool link cluster if the current user is allowed to view it
	 * @return string HTML
	 */
	protected function getUserTools() {
		if ( $this->file->userCan( Revision::DELETED_USER, $this->list->getUser() ) ) {
			$uid = $this->file->getUser( 'id' );
			$name = $this->file->getUser( 'text' );
			$link = Linker::userLink( $uid, $name ) . Linker::userToolLinks( $uid, $name );
		} else {
			$link = $this->list->msg( 'rev-deleted-user' )->escaped();
		}
		if ( $this->file->isDeleted( Revision::DELETED_USER ) ) {
			return '<span class="history-deleted">' . $link . '</span>';
		}
		return $link;
	}

	/**
	 * Wrap and format the file's comment block, if the current
	 * user is allowed to view it.
	 *
	 * @return string HTML
	 */
	protected function getComment() {
		if ( $this->file->userCan( File::DELETED_COMMENT, $this->list->getUser() ) ) {
			$block = Linker::commentBlock( $this->file->getDescription() );
		} else {
			$block = ' ' . $this->list->msg( 'rev-deleted-comment' )->escaped();
		}
		if ( $this->file->isDeleted( File::DELETED_COMMENT ) ) {
			return "<span class=\"history-deleted\">$block</span>";
		}
		return $block;
	}

	public function getHTML() {
		$data =
			$this->list->msg( 'widthheight' )->numParams(
				$this->file->getWidth(), $this->file->getHeight() )->text() .
			' (' . $this->list->msg( 'nbytes' )->numParams( $this->file->getSize() )->text() . ')';

		return '<li>' . $this->getLink() . ' ' . $this->getUserTools() . ' ' .
			$data . ' ' . $this->getComment() . '</li>';
	}

	public function getApiData( ApiResult $result ) {
		$file = $this->file;
		$user = $this->list->getUser();
		$ret = array(
			'title' => $this->list->title->getPrefixedText(),
			'archivename' => $file->getArchiveName(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $file->getTimestamp() ),
			'width' => $file->getWidth(),
			'height' => $file->getHeight(),
			'size' => $file->getSize(),
		);
		$ret += $file->isDeleted( Revision::DELETED_USER ) ? array( 'userhidden' => '' ) : array();
		$ret += $file->isDeleted( Revision::DELETED_COMMENT ) ? array( 'commenthidden' => '' ) : array();
		$ret += $this->isDeleted() ? array( 'contenthidden' => '' ) : array();
		if ( !$this->isDeleted() ) {
			$ret += array(
				'url' => $file->getUrl(),
			);
		} elseif ( $this->canViewContent() ) {
			$ret += array(
				'url' => SpecialPage::getTitleFor( 'Revisiondelete' )->getLinkURL(
					array(
						'target' => $this->list->title->getPrefixedText(),
						'file' => $file->getArchiveName(),
						'token' => $user->getEditToken( $file->getArchiveName() )
					),
					false, PROTO_RELATIVE
				),
			);
		}
		if ( $file->userCan( Revision::DELETED_USER, $user ) ) {
			$ret += array(
				'userid' => $file->user,
				'user' => $file->user_text,
			);
		}
		if ( $file->userCan( Revision::DELETED_COMMENT, $user ) ) {
			$ret += array(
				'comment' => $file->description,
			);
		}
		return $ret;
	}
}

/**
 * List for filearchive table items
 */
class RevDelArchivedFileList extends RevDelFileList {
	public function getType() {
		return 'filearchive';
	}

	public static function getRelationType() {
		return 'fa_id';
	}

	/**
	 * @param DatabaseBase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$ids = array_map( 'intval', $this->ids );
		return $db->select(
			'filearchive',
			ArchivedFile::selectFields(),
			array(
				'fa_name' => $this->title->getDBkey(),
				'fa_id' => $ids
			),
			__METHOD__,
			array( 'ORDER BY' => 'fa_id DESC' )
		);
	}

	public function newItem( $row ) {
		return new RevDelArchivedFileItem( $this, $row );
	}
}

/**
 * Item class for a filearchive table row
 */
class RevDelArchivedFileItem extends RevDelFileItem {
	public function __construct( $list, $row ) {
		RevDelItem::__construct( $list, $row );
		$this->file = ArchivedFile::newFromRow( $row );
	}

	public function getIdField() {
		return 'fa_id';
	}

	public function getTimestampField() {
		return 'fa_timestamp';
	}

	public function getAuthorIdField() {
		return 'fa_user';
	}

	public function getAuthorNameField() {
		return 'fa_user_text';
	}

	public function getId() {
		return $this->row->fa_id;
	}

	public function setBits( $bits ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'filearchive',
			array( 'fa_deleted' => $bits ),
			array(
				'fa_id' => $this->row->fa_id,
				'fa_deleted' => $this->getBits(),
			),
			__METHOD__
		);
		return (bool)$dbw->affectedRows();
	}

	protected function getLink() {
		$date = htmlspecialchars( $this->list->getLanguage()->userTimeAndDate(
			$this->file->getTimestamp(), $this->list->getUser() ) );

		# Hidden files...
		if ( !$this->canViewContent() ) {
			$link = $date;
		} else {
			$undelete = SpecialPage::getTitleFor( 'Undelete' );
			$key = $this->file->getKey();
			$link = Linker::link( $undelete, $date, array(),
				array(
					'target' => $this->list->title->getPrefixedText(),
					'file' => $key,
					'token' => $this->list->getUser()->getEditToken( $key )
				)
			);
		}
		if ( $this->isDeleted() ) {
			$link = '<span class="history-deleted">' . $link . '</span>';
		}
		return $link;
	}

	public function getApiData( ApiResult $result ) {
		$file = $this->file;
		$user = $this->list->getUser();
		$ret = array(
			'title' => $this->list->title->getPrefixedText(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $file->getTimestamp() ),
			'width' => $file->getWidth(),
			'height' => $file->getHeight(),
			'size' => $file->getSize(),
		);
		$ret += $file->isDeleted( Revision::DELETED_USER ) ? array( 'userhidden' => '' ) : array();
		$ret += $file->isDeleted( Revision::DELETED_COMMENT ) ? array( 'commenthidden' => '' ) : array();
		$ret += $this->isDeleted() ? array( 'contenthidden' => '' ) : array();
		if ( $this->canViewContent() ) {
			$ret += array(
				'url' => SpecialPage::getTitleFor( 'Revisiondelete' )->getLinkURL(
					array(
						'target' => $this->list->title->getPrefixedText(),
						'file' => $file->getKey(),
						'token' => $user->getEditToken( $file->getKey() )
					),
					false, PROTO_RELATIVE
				),
			);
		}
		if ( $file->userCan( Revision::DELETED_USER, $user ) ) {
			$ret += array(
				'userid' => $file->getUser( 'id' ),
				'user' => $file->getUser( 'text' ),
			);
		}
		if ( $file->userCan( Revision::DELETED_COMMENT, $user ) ) {
			$ret += array(
				'comment' => $file->getRawDescription(),
			);
		}
		return $ret;
	}
}

/**
 * List for logging table items
 */
class RevDelLogList extends RevDelList {
	public function getType() {
		return 'logging';
	}

	public static function getRelationType() {
		return 'log_id';
	}

	public static function getRestriction() {
		return 'deletelogentry';
	}

	public static function getRevdelConstant() {
		return LogPage::DELETED_ACTION;
	}

	public static function suggestTarget( $target, array $ids ) {
		$result = wfGetDB( DB_SLAVE )->select( 'logging',
			'log_type',
			array( 'log_id' => $ids ),
			__METHOD__,
			array( 'DISTINCT' )
		);
		if ( $result->numRows() == 1 ) {
			// If there's only one type, the target can be set to include it.
			return SpecialPage::getTitleFor( 'Log', $result->current()->log_type );
		}
		return SpecialPage::getTitleFor( 'Log' );
	}

	/**
	 * @param DatabaseBase $db
	 * @return mixed
	 */
	public function doQuery( $db ) {
		$ids = array_map( 'intval', $this->ids );
		return $db->select( 'logging', array(
				'log_id',
				'log_type',
				'log_action',
				'log_timestamp',
				'log_user',
				'log_user_text',
				'log_namespace',
				'log_title',
				'log_page',
				'log_comment',
				'log_params',
				'log_deleted'
			),
			array( 'log_id' => $ids ),
			__METHOD__,
			array( 'ORDER BY' => 'log_id DESC' )
		);
	}

	public function newItem( $row ) {
		return new RevDelLogItem( $this, $row );
	}

	public function getSuppressBit() {
		return Revision::DELETED_RESTRICTED;
	}

	public function getLogAction() {
		return 'event';
	}

	public function getLogParams( $params ) {
		return array(
			implode( ',', $params['ids'] ),
			"ofield={$params['oldBits']}",
			"nfield={$params['newBits']}"
		);
	}
}

/**
 * Item class for a logging table row
 */
class RevDelLogItem extends RevDelItem {
	public function getIdField() {
		return 'log_id';
	}

	public function getTimestampField() {
		return 'log_timestamp';
	}

	public function getAuthorIdField() {
		return 'log_user';
	}

	public function getAuthorNameField() {
		return 'log_user_text';
	}

	public function canView() {
		return LogEventsList::userCan( $this->row, Revision::DELETED_RESTRICTED, $this->list->getUser() );
	}

	public function canViewContent() {
		return true; // none
	}

	public function getBits() {
		return $this->row->log_deleted;
	}

	public function setBits( $bits ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'recentchanges',
			array(
				'rc_deleted' => $bits,
				'rc_patrolled' => 1
			),
			array(
				'rc_logid' => $this->row->log_id,
				'rc_timestamp' => $this->row->log_timestamp // index
			),
			__METHOD__
		);
		$dbw->update( 'logging',
			array( 'log_deleted' => $bits ),
			array(
				'log_id' => $this->row->log_id,
				'log_deleted' => $this->getBits()
			),
			__METHOD__
		);
		return (bool)$dbw->affectedRows();
	}

	public function getHTML() {
		$date = htmlspecialchars( $this->list->getLanguage()->userTimeAndDate(
			$this->row->log_timestamp, $this->list->getUser() ) );
		$title = Title::makeTitle( $this->row->log_namespace, $this->row->log_title );
		$formatter = LogFormatter::newFromRow( $this->row );
		$formatter->setContext( $this->list->getContext() );
		$formatter->setAudience( LogFormatter::FOR_THIS_USER );

		// Log link for this page
		$loglink = Linker::link(
			SpecialPage::getTitleFor( 'Log' ),
			$this->list->msg( 'log' )->escaped(),
			array(),
			array( 'page' => $title->getPrefixedText() )
		);
		$loglink = $this->list->msg( 'parentheses' )->rawParams( $loglink )->escaped();
		// User links and action text
		$action = $formatter->getActionText();
		// Comment
		$comment = $this->list->getLanguage()->getDirMark()
			. Linker::commentBlock( $this->row->log_comment );

		if ( LogEventsList::isDeleted( $this->row, LogPage::DELETED_COMMENT ) ) {
			$comment = '<span class="history-deleted">' . $comment . '</span>';
		}

		return "<li>$loglink $date $action $comment</li>";
	}

	public function getApiData( ApiResult $result ) {
		$logEntry = DatabaseLogEntry::newFromRow( $this->row );
		$user = $this->list->getUser();
		$ret = array(
			'id' => $logEntry->getId(),
			'type' => $logEntry->getType(),
			'action' => $logEntry->getSubtype(),
		);
		$ret += $logEntry->isDeleted( LogPage::DELETED_USER )
			? array( 'userhidden' => '' )
			: array();
		$ret += $logEntry->isDeleted( LogPage::DELETED_COMMENT )
			? array( 'commenthidden' => '' )
			: array();
		$ret += $logEntry->isDeleted( LogPage::DELETED_ACTION )
			? array( 'actionhidden' => '' )
			: array();

		if ( LogEventsList::userCan( $this->row, LogPage::DELETED_ACTION, $user ) ) {
			ApiQueryLogEvents::addLogParams(
				$result,
				$ret,
				$logEntry->getParameters(),
				$logEntry->getType(),
				$logEntry->getSubtype(),
				$logEntry->getTimestamp(),
				$logEntry->isLegacy()
			);
		}
		if ( LogEventsList::userCan( $this->row, LogPage::DELETED_USER, $user ) ) {
			$ret += array(
				'userid' => $this->row->log_user,
				'user' => $this->row->log_user_text,
			);
		}
		if ( LogEventsList::userCan( $this->row, LogPage::DELETED_COMMENT, $user ) ) {
			$ret += array(
				'comment' => $this->row->log_comment,
			);
		}
		return $ret;
	}
}
