<?php
/**
 * Classes used to send e-mails
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
 * @author <brion@pobox.com>
 * @author <mail@tgries.de>
 * @author Tim Starling
 * @author Luke Welling lwelling@wikimedia.org
 */

/**
 * This module processes the email notifications when the current page is
 * changed. It looks up the table watchlist to find out which users are watching
 * that page.
 *
 * The current implementation sends independent emails to each watching user for
 * the following reason:
 *
 * - Each watching user will be notified about the page edit time expressed in
 * his/her local time (UTC is shown additionally). To achieve this, we need to
 * find the individual timeoffset of each watching user from the preferences..
 *
 * Suggested improvement to slack down the number of sent emails: We could think
 * of sending out bulk mails (bcc:user1,user2...) for all these users having the
 * same timeoffset in their preferences.
 *
 * Visit the documentation pages under http://meta.wikipedia.com/Enotif
 */
class EmailNotification {

	/**
	 * Notification is due to user's user talk being edited
	 */
	const USER_TALK = 'user_talk';
	/**
	 * Notification is due to a watchlisted page being edited
	 */
	const WATCHLIST = 'watchlist';
	/**
	 * Notification because user is notified for all changes
	 */
	const ALL_CHANGES = 'all_changes';

	protected $subject, $body, $replyto, $from;
	protected $timestamp, $summary, $minorEdit, $oldid, $composed_common, $pageStatus;
	protected $mailTargets = array();

	/**
	 * @var Title
	 */
	protected $title;

	/**
	 * @var User
	 */
	protected $editor;

	/**
	 * @param User $editor The editor that triggered the update.  Their notification
	 *  timestamp will not be updated(they have already seen it)
	 * @param Title $title The title to update timestamps for
	 * @param string $timestamp Set the update timestamp to this value
	 * @return int[]
	 */
	public static function updateWatchlistTimestamp( User $editor, Title $title, $timestamp ) {
		global $wgEnotifWatchlist, $wgShowUpdatedMarker;

		if ( !$wgEnotifWatchlist && !$wgShowUpdatedMarker ) {
			return array();
		}

		$dbw = wfGetDB( DB_MASTER );
		$tbl = $dbw->tableName( 'user_properties' );
		$res = $dbw->select( array( 'watchlist' ),
			array( 'wl_user' ),
			array(
				'wl_user != ' . intval( $editor->getID() ),
				'wl_namespace' => $title->getNamespace(),
				'wl_title' => $title->getDBkey(),
				"(wl_notificationtimestamp IS NULL OR EXISTS (SELECT * FROM $tbl".
					" WHERE up_user=wl_user AND up_property IN ('enotifsendmultiple', 'enotifsenddiff') AND up_value='1'))",
			), __METHOD__
		);

		$watchers = array();
		foreach ( $res as $row ) {
			$watchers[] = intval( $row->wl_user );
		}

		if ( $watchers ) {
			// Update wl_notificationtimestamp for all watching users except the editor
			$fname = __METHOD__;
			$dbw->onTransactionIdle(
				function () use ( $dbw, $timestamp, $watchers, $title, $fname ) {
					$dbw->update( 'watchlist',
						array( /* SET */
							'wl_notificationtimestamp' => $dbw->timestamp( $timestamp )
						), array( /* WHERE */
							'wl_user' => $watchers,
							'wl_namespace' => $title->getNamespace(),
							'wl_title' => $title->getDBkey(),
						), $fname
					);
				}
			);
		}

		return $watchers;
	}

	/**
	 * Send emails corresponding to the user $editor editing the page $title.
	 *
	 * May be deferred via the job queue.
	 *
	 * @param User $editor
	 * @param Title $title
	 * @param string $timestamp
	 * @param string $summary
	 * @param bool $minorEdit
	 * @param bool $oldid (default: false)
	 * @param string $pageStatus (default: 'changed')
	 */
	public function notifyOnPageChange( $editor, $title, $timestamp, $summary,
		$minorEdit, $oldid = false, $pageStatus = 'changed'
	) {
		global $wgEnotifUseJobQ, $wgEnotifMinorEdits, $wgUsersNotifiedOnAllChanges, $wgEnotifUserTalk;

		if ( $title->getNamespace() < 0 ) {
			return;
		}

		// update wl_notificationtimestamp for watchers
		$watchers = self::updateWatchlistTimestamp( $editor, $title, $timestamp );

		$sendEmail = true;
		// If nobody is watching the page, and there are no users notified on all changes
		// don't bother creating a job/trying to send emails, unless it's a
		// talk page with an applicable notification.
		//
		// $watchers deals with $wgEnotifWatchlist
		if ( !count( $watchers ) && !count( $wgUsersNotifiedOnAllChanges ) ) {
			$sendEmail = false;
			// Only send notification for non minor edits, unless $wgEnotifMinorEdits
			if ( !$minorEdit || ( $wgEnotifMinorEdits && !$editor->isAllowed( 'nominornewtalk' ) ) ) {
				$isUserTalkPage = ( $title->getNamespace() == NS_USER_TALK );
				if ( $wgEnotifUserTalk
					&& $isUserTalkPage
					&& $this->canSendUserTalkEmail( $editor, $title, $minorEdit )
				) {
					$sendEmail = true;
				}
			}
		}

		if ( !$sendEmail ) {
			return;
		}

		if ( $wgEnotifUseJobQ ) {
			$params = array(
				'editor' => $editor->getName(),
				'editorID' => $editor->getID(),
				'timestamp' => $timestamp,
				'summary' => $summary,
				'minorEdit' => $minorEdit,
				'oldid' => $oldid,
				'watchers' => $watchers,
				'pageStatus' => $pageStatus
			);
			$job = new EnotifNotifyJob( $title, $params );
			JobQueueGroup::singleton()->lazyPush( $job );
		} else {
			$this->actuallyNotifyOnPageChange(
				$editor,
				$title,
				$timestamp,
				$summary,
				$minorEdit,
				$oldid,
				$watchers,
				$pageStatus
			);
		}
	}

	/**
	 * Immediate version of notifyOnPageChange().
	 *
	 * Send emails corresponding to the user $editor editing the page $title.
	 *
	 * @note Do not call directly. Use notifyOnPageChange so that wl_notificationtimestamp is updated.
	 * @param User $editor
	 * @param Title $title
	 * @param string $timestamp Edit timestamp
	 * @param string $summary Edit summary
	 * @param bool $minorEdit
	 * @param int $oldid Revision ID
	 * @param array $watchers Array of user IDs
	 * @param string $pageStatus
	 * @throws MWException
	 */
	public function actuallyNotifyOnPageChange( $editor, $title, $timestamp, $summary, $minorEdit,
		$oldid, $watchers, $pageStatus = 'changed' ) {
		# we use $wgPasswordSender as sender's address
		global $wgEnotifWatchlist, $wgBlockDisablesLogin;
		global $wgEnotifMinorEdits, $wgEnotifUserTalk;

		# The following code is only run, if several conditions are met:
		# 1. EmailNotification for pages (other than user_talk pages) must be enabled
		# 2. minor edits (changes) are only regarded if the global flag indicates so

		$isUserTalkPage = ( $title->getNamespace() == NS_USER_TALK );

		$this->title = $title;
		$this->timestamp = $timestamp;
		$this->summary = $summary;
		$this->minorEdit = $minorEdit;
		$this->oldid = $oldid;
		$this->editor = $editor;
		$this->composed_common = false;
		$this->pageStatus = $pageStatus;

		$formattedPageStatus = array( 'deleted', 'created', 'moved', 'restored', 'changed' );

		Hooks::run( 'UpdateUserMailerFormattedPageStatus', array( &$formattedPageStatus ) );
		if ( !in_array( $this->pageStatus, $formattedPageStatus ) ) {
			throw new MWException( 'Not a valid page status!' );
		}

		$userTalkId = false;

		if ( !$minorEdit || ( $wgEnotifMinorEdits && !$editor->isAllowed( 'nominornewtalk' ) ) ) {
			if ( $wgEnotifUserTalk
				&& $isUserTalkPage
				&& $this->canSendUserTalkEmail( $editor, $title, $minorEdit )
			) {
				$targetUser = User::newFromName( $title->getText() );
				$this->compose( $targetUser, self::USER_TALK );
				$userTalkId = $targetUser->getId();
			}

			if ( $wgEnotifWatchlist ) {
				// Send updates to watchers other than the current editor
				// and don't send to watchers who are blocked and cannot login
				$userArray = UserArray::newFromIDs( $watchers );
				foreach ( $userArray as $watchingUser ) {
					if ( $watchingUser->getOption( 'enotifwatchlistpages' )
						&& ( !$minorEdit || $watchingUser->getOption( 'enotifminoredits' ) )
						&& $watchingUser->isEmailConfirmed()
						&& $watchingUser->getID() != $userTalkId
						&& !( $wgBlockDisablesLogin && $watchingUser->isBlocked() )
// <IntraACL>
						&& !$title->getUserPermissionsErrors( 'read', $watchingUser ) // Check page read access
// </IntraACL>
					) {
						if ( Hooks::run( 'SendWatchlistEmailNotification', array( $watchingUser, $title, $this ) ) ) {
							$this->compose( $watchingUser, self::WATCHLIST );
						}
					}
				}
			}
		}

		global $wgUsersNotifiedOnAllChanges;
		foreach ( $wgUsersNotifiedOnAllChanges as $name ) {
			if ( $editor->getName() == $name ) {
				// No point notifying the user that actually made the change!
				continue;
			}
			$user = User::newFromName( $name );
// <IntraACL>
			if ( !$title->getUserPermissionsErrors( 'read', $user ) ) {
				// Check page read access
				$this->compose( $user, self::ALL_CHANGES );
			}
// </IntraACL>
		}

		$this->sendMails();
	}

	/**
	 * @param User $editor
	 * @param Title $title
	 * @param bool $minorEdit
	 * @return bool
	 */
	private function canSendUserTalkEmail( $editor, $title, $minorEdit ) {
		global $wgEnotifUserTalk, $wgBlockDisablesLogin;
		$isUserTalkPage = ( $title->getNamespace() == NS_USER_TALK );

		if ( $wgEnotifUserTalk && $isUserTalkPage ) {
			$targetUser = User::newFromName( $title->getText() );

			if ( !$targetUser || $targetUser->isAnon() ) {
				wfDebug( __METHOD__ . ": user talk page edited, but user does not exist\n" );
			} elseif ( $targetUser->getId() == $editor->getId() ) {
				wfDebug( __METHOD__ . ": user edited their own talk page, no notification sent\n" );
			} elseif ( $wgBlockDisablesLogin && $targetUser->isBlocked() ) {
				wfDebug( __METHOD__ . ": talk page owner is blocked and cannot login, no notification sent\n" );
			} elseif ( $targetUser->getOption( 'enotifusertalkpages' )
				&& ( !$minorEdit || $targetUser->getOption( 'enotifminoredits' ) )
			) {
				if ( !$targetUser->isEmailConfirmed() ) {
					wfDebug( __METHOD__ . ": talk page owner doesn't have validated email\n" );
				} elseif ( !Hooks::run( 'AbortTalkPageEmailNotification', array( $targetUser, $title ) ) ) {
					wfDebug( __METHOD__ . ": talk page update notification is aborted for this user\n" );
				} else {
					wfDebug( __METHOD__ . ": sending talk page update notification\n" );
					return true;
				}
			} else {
				wfDebug( __METHOD__ . ": talk page owner doesn't want notifications\n" );
			}
		}
		return false;
	}

	/**
	 * Generate the generic "this page has been changed" e-mail text.
	 */
	private function composeCommonMailtext() {
		global $wgPasswordSender, $wgNoReplyAddress;
		global $wgEnotifFromEditor, $wgEnotifRevealEditorAddress;
		global $wgEnotifImpersonal, $wgEnotifUseRealName;

		$this->composed_common = true;

		# You as the WikiAdmin and Sysops can make use of plenty of
		# named variables when composing your notification emails while
		# simply editing the Meta pages

		$keys = $keysHtml = array();
		$postTransformKeys = array();
		$pageTitleUrl = $this->title->getCanonicalURL();
		$pageTitle = $this->title->getPrefixedText();

		if ( $this->oldid ) {
			// Always show a link to the diff which triggered the mail. See bug 32210.
			$keys['$NEWPAGE'] = "\n\n" . wfMessage( 'enotif_lastdiff',
					$this->title->getCanonicalURL( array( 'diff' => 'next', 'oldid' => $this->oldid ) ) )
					->inContentLanguage()->text();
			$keysHtml['$NEWPAGE'] = wfMessage( 'enotif_lastdiff_html',
					$this->title->getCanonicalURL( array( 'diff' => 'next', 'oldid' => $this->oldid ) ) )
					->inContentLanguage()->plain();

			if ( !$wgEnotifImpersonal ) {
				// For personal mail, also show a link to the diff of all changes
				// since last visited.
				$keys['$NEWPAGE'] .= "\n\n" . wfMessage( 'enotif_lastvisited',
						$this->title->getCanonicalURL( array( 'diff' => '0', 'oldid' => $this->oldid ) ) )
						->inContentLanguage()->text();
				$keysHtml['$NEWPAGE'] .= wfMessage( 'enotif_lastvisited_html',
						$this->title->getCanonicalURL( array( 'diff' => '0', 'oldid' => $this->oldid ) ) )
						->inContentLanguage()->plain();
			}
			$keys['$OLDID'] = $this->oldid;
			// Deprecated since MediaWiki 1.21, not used by default. Kept for backwards-compatibility.
			$keys['$CHANGEDORCREATED'] = wfMessage( 'changed' )->inContentLanguage()->text();
		} else {
			# clear $OLDID placeholder in the message template
			$keys['$OLDID'] = '';
			$keys['$NEWPAGE'] = '';
			// Deprecated since MediaWiki 1.21, not used by default. Kept for backwards-compatibility.
			$keys['$CHANGEDORCREATED'] = wfMessage( 'created' )->inContentLanguage()->text();
		}

		$keys['$PAGETITLE'] = $this->title->getPrefixedText();
		$keys['$PAGETITLE_URL'] = $this->title->getCanonicalURL();
		$keys['$PAGEMINOREDIT'] = $this->minorEdit ?
			wfMessage( 'minoredit' )->inContentLanguage()->text() : '';
		$keys['$UNWATCHURL'] = $this->title->getCanonicalURL( 'action=unwatch' );

		if ( $this->editor->isAnon() ) {
			# real anon (user:xxx.xxx.xxx.xxx)
			$keys['$PAGEEDITOR'] = wfMessage( 'enotif_anon_editor', $this->editor->getName() )
				->inContentLanguage()->text();
			$keys['$PAGEEDITOR_EMAIL'] = wfMessage( 'noemailtitle' )->inContentLanguage()->text();

		} else {
			$keys['$PAGEEDITOR'] = $wgEnotifUseRealName && $this->editor->getRealName() !== ''
				? $this->editor->getRealName() : $this->editor->getName();
			$emailPage = SpecialPage::getSafeTitleFor( 'Emailuser', $this->editor->getName() );
			$keys['$PAGEEDITOR_EMAIL'] = $emailPage->getCanonicalURL();
		}

		$keys['$PAGEEDITOR_WIKI'] = $this->editor->getUserPage()->getCanonicalURL();
		$keys['$HELPPAGE'] = wfExpandUrl(
			Skin::makeInternalOrExternalUrl( wfMessage( 'helppage' )->inContentLanguage()->text() )
		);

		// Generate diff
		$context = new DerivativeContext( RequestContext::getMain() );
		$context->setTitle( $this->title );
		$out = new OutputPage( $context );
		$context->setOutput( $out );
		$de = new DifferenceEngine( $context, $keys['$OLDID'], 'next' );
		$de->showDiffPage( true );
		$diff = $out->getHTML();
		$diff = preg_replace( '#^(.*?)<tr[^<>]*>.*?</tr\s*>#is', '\1', $diff, 1 );
		$diff = preg_replace( '#class=[\"\']?diff-deletedline[\"\']?#is', 'style="background-color: #ffffaa"', $diff );
		$diff = preg_replace( '#class=[\"\']?diff-addedline[\"\']?#is', 'style="background-color: #ccffcc"', $diff );
		$this->diff = preg_replace( '#class=[\"\']?diffchange\s*diffchange-inline[\"\']?#is', 'style="color: red; font-weight: bold"', $diff );

		# Replace this after transforming the message, bug 35019
		$postTransformKeys['$PAGESUMMARY'] = $this->summary == '' ? wfMessage( 'enotif_empty_summary' )->text() : $this->summary;

		// Now build message's subject and body

		// Messages:
		// enotif_subject_deleted, enotif_subject_created, enotif_subject_moved,
		// enotif_subject_restored, enotif_subject_changed
		$this->subject = wfMessage( 'enotif_subject_' . $this->pageStatus )->inContentLanguage()
			->params( $pageTitle, $keys['$PAGEEDITOR'] )->text();

		// Messages:
		// enotif_body_intro_deleted, enotif_body_intro_created, enotif_body_intro_moved,
		// enotif_body_intro_restored, enotif_body_intro_changed
		$keys['$PAGEINTRO'] = wfMessage( 'enotif_body_intro_' . $this->pageStatus )
			->inContentLanguage()->params( $pageTitle, $keys['$PAGEEDITOR'], $pageTitleUrl )
			->text();
		$keysHtml['$PAGEINTRO'] = wfMessage( 'enotif_body_intro_' . $this->pageStatus )
			->inContentLanguage()->params( htmlspecialchars( $pageTitle ), htmlspecialchars( $keys['$PAGEEDITOR'] ),
				'<a href="'.$pageTitleUrl.'">'.htmlspecialchars( urldecode( $this->title->getCanonicalUrl() ) ).'</a>' )
			->plain();

		$body = wfMessage( 'enotif_body' )->inContentLanguage()->plain();
		$body = strtr( $body, $keys );
		$body = MessageCache::singleton()->transform( $body, false, null, $this->title );
		$this->body = wordwrap( strtr( $body, $postTransformKeys ), 72 );

		foreach ( $keys as $k => $v ) {
			if ( !isset( $keysHtml[$k] ) ) {
				$keysHtml[$k] = htmlspecialchars( $v );
			}
		}
		$bodyHtml = wfMessage( 'enotif_body_html' )->inContentLanguage()->plain();
		$bodyHtml = strtr( $bodyHtml, $keysHtml );
		$bodyHtml = MessageCache::singleton()->transform( $bodyHtml, false, null, $this->title );
		$this->bodyHtml = strtr( $bodyHtml, $postTransformKeys );

		# Reveal the page editor's address as REPLY-TO address only if
		# the user has not opted-out and the option is enabled at the
		# global configuration level.
		$adminAddress = new MailAddress( $wgPasswordSender,
			wfMessage( 'emailsender' )->inContentLanguage()->text() );
		if ( $wgEnotifRevealEditorAddress
			&& ( $this->editor->getEmail() != '' )
			&& $this->editor->getOption( 'enotifrevealaddr' )
		) {
			$editorAddress = MailAddress::newFromUser( $this->editor );
			if ( $wgEnotifFromEditor ) {
				$this->from = $editorAddress;
			} else {
				$this->from = $adminAddress;
				$this->replyto = $editorAddress;
			}
		} else {
			$this->from = $adminAddress;
			$this->replyto = new MailAddress( $wgNoReplyAddress );
		}
	}

	/**
	 * Compose a mail to a given user and either queue it for sending, or send it now,
	 * depending on settings.
	 *
	 * Call sendMails() to send any mails that were queued.
	 * @param User $user
	 * @param string $source
	 */
	function compose( $user, $source ) {
		global $wgEnotifImpersonal;

		if ( !$this->composed_common ) {
			$this->composeCommonMailtext();
		}

		if ( $wgEnotifImpersonal ) {
			$this->mailTargets[] = MailAddress::newFromUser( $user );
		} else {
			$this->sendPersonalised( $user, $source );
		}
	}

	/**
	 * Send any queued mails
	 */
	function sendMails() {
		global $wgEnotifImpersonal;
		if ( $wgEnotifImpersonal ) {
			$this->sendImpersonal( $this->mailTargets );
		}
	}

	/**
	 * Does the per-user customizations to a notification e-mail (name,
	 * timestamp in proper timezone, etc) and sends it out.
	 * Returns true if the mail was sent successfully.
	 *
	 * @param User $watchingUser
	 * @param string $source
	 * @return bool
	 * @private
	 */
	function sendPersonalised( $watchingUser, $source ) {
		global $wgContLang, $wgEnotifUseRealName;
		// From the PHP manual:
		//   Note: The to parameter cannot be an address in the form of
		//   "Something <someone@example.com>". The mail command will not parse
		//   this properly while talking with the MTA.
		$to = MailAddress::newFromUser( $watchingUser );

		$nofurther = $watchingUser->getOption( 'enotifsenddiffs' ) ||
			$watchingUser->getOption( 'enotifsendmultiple' )
			? '' : wfMessage( 'enotif_no_further_notice' )->inContentLanguage()->text();

		# $PAGEEDITDATE is the time and date of the page change
		# expressed in terms of individual local time of the notification
		# recipient, i.e. watching user
		$repl = array(
			'$WATCHINGUSERNAME' => $wgEnotifUseRealName && $watchingUser->getRealName() !== ''
				? $watchingUser->getRealName() : $watchingUser->getName(),
			'$PAGEEDITDATE' => $wgContLang->userDate( $this->timestamp, $watchingUser ),
			'$PAGEEDITTIME' => $wgContLang->userTime( $this->timestamp, $watchingUser ),
			'$NOFURTHERNOTICE' => $nofurther ? "\n\n$nofurther" : '',
		);
		$body = strtr( $this->body, $repl );
		$repl['$WATCHINGUSERNAME'] = htmlspecialchars( $repl['$WATCHINGUSERNAME'] );
		$repl['$NOFURTHERNOTICE'] = $nofurther ? "\n<p>$nofurther</p>" : '';
		$repl['$DIFF'] = $watchingUser->getOption( 'enotifsenddiffs' ) ? $this->diff : '';
		$bodyHtml = strtr( $this->bodyHtml, $repl );
		$body = array(
			'text' => $body,
			'html' => $bodyHtml,
		);

		$headers = array();
		if ( $source === self::WATCHLIST ) {
			$headers['List-Help'] = 'https://www.mediawiki.org/wiki/Special:MyLanguage/Help:Watchlist';
		}

		return UserMailer::send( $to, $this->from, $this->subject, $body, array(
			'replyTo' => $this->replyto,
			'headers' => $headers,
		) );
	}

	/**
	 * Same as sendPersonalised but does impersonal mail suitable for bulk
	 * mailing.  Takes an array of MailAddress objects.
	 * @param MailAddress[] $addresses
	 * @return Status|null
	 */
	function sendImpersonal( $addresses ) {
		global $wgContLang;

		if ( empty( $addresses ) ) {
			return null;
		}

		$body = str_replace(
			array( '$WATCHINGUSERNAME',
				'$PAGEEDITDATE',
				'$PAGEEDITTIME' ),
			array( wfMessage( 'enotif_impersonal_salutation' )->inContentLanguage()->text(),
				$wgContLang->date( $this->timestamp, false, false ),
				$wgContLang->time( $this->timestamp, false, false ) ),
			$this->body );

		return UserMailer::send( $addresses, $this->from, $this->subject, $body, array(
			'replyTo' => $this->replyto,
		) );
	}

}
