<?php
/**
 * QA Testing: test scenarios grouped by topic, pass/fail marks with who and when,
 * repeatable test rounds, a full record of every check, and a list of who may see the page.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * QA Testing page.
 */
class BDACI_PA_QA {

	const OPT_USERS   = 'bdaci_pa_qa_users';
	const OPT_ROUNDS  = 'bdaci_pa_qa_rounds';
	const OPT_CURRENT = 'bdaci_pa_qa_current';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_qa_log';
	}

	/**
	 * Create the record table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				round_id int(10) unsigned NOT NULL,
				scenario varchar(20) NOT NULL,
				status varchar(8) NOT NULL,
				note text NULL,
				shots text NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY round_scenario (round_id,scenario),
				KEY user_id (user_id)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'mark', 'round', 'access', 'csv', 'shot' ) as $a ) {
			add_action( 'admin_post_bdaci_pa_qa_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Access
	 * ------------------------------------------------------------------ */

	/**
	 * User ids given access (besides Portal Admins).
	 *
	 * @return int[]
	 */
	public static function allowed_users() {
		return array_values( array_filter( array_map( 'intval', (array) get_option( self::OPT_USERS, array() ) ) ) );
	}

	/**
	 * Can this user see and use the QA page?
	 *
	 * @param int $uid User (default current).
	 * @return bool
	 */
	public static function can_view( $uid = 0 ) {
		$uid = $uid ? (int) $uid : get_current_user_id();
		if ( ! $uid ) {
			return false;
		}
		if ( user_can( $uid, 'bdaci_manage_portal' ) ) {
			return true;
		}
		return in_array( $uid, self::allowed_users(), true ) && BDACI_PA_Members::is_authorized( $uid );
	}

	/**
	 * Can this user manage rounds and access?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'bdaci_manage_portal' );
	}

	/* ---------------------------------------------------------------------
	 * Rounds
	 * ------------------------------------------------------------------ */

	/**
	 * All rounds, newest first: id => [name, started (GMT), by].
	 *
	 * @return array
	 */
	public static function rounds() {
		$r = get_option( self::OPT_ROUNDS, array() );
		$r = is_array( $r ) ? $r : array();
		krsort( $r );
		return $r;
	}

	/**
	 * Current round id (creates Round 1 the first time).
	 *
	 * @return int
	 */
	public static function current_round() {
		$cur    = (int) get_option( self::OPT_CURRENT, 0 );
		$rounds = self::rounds();
		if ( $cur && isset( $rounds[ $cur ] ) ) {
			return $cur;
		}
		if ( $rounds ) {
			return (int) key( $rounds );
		}
		return self::start_round( __( 'Round 1', 'bdaci-portal-access' ) );
	}

	/**
	 * Start a new round and make it current.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	public static function start_round( $name ) {
		$rounds = self::rounds();
		$id     = $rounds ? max( array_keys( $rounds ) ) + 1 : 1;
		/* translators: %d: round number */
		$rounds[ $id ] = array( 'name' => $name ? $name : sprintf( __( 'Round %d', 'bdaci-portal-access' ), $id ), 'started' => current_time( 'mysql', true ), 'by' => get_current_user_id() );
		update_option( self::OPT_ROUNDS, $rounds, false );
		update_option( self::OPT_CURRENT, $id, false );
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * Scenarios
	 * ------------------------------------------------------------------ */

	/**
	 * Role labels for "Sign in as".
	 *
	 * @return array
	 */
	public static function roles() {
		return array(
			'admin' => __( 'Portal Admin', 'bdaci-portal-access' ),
			'board' => __( 'Board Member', 'bdaci-portal-access' ),
			'edu'   => __( 'Education Committee', 'bdaci-portal-access' ),
			'fin'   => __( 'Finance Committee', 'bdaci-portal-access' ),
			'out'   => __( 'Signed out', 'bdaci-portal-access' ),
			'any'   => __( 'Any member', 'bdaci-portal-access' ),
			'phone' => __( 'On a phone', 'bdaci-portal-access' ),
		);
	}

	/**
	 * Test groups: key => [title, intro, cases[ id => [title, role, steps[], expected] ]].
	 *
	 * @return array
	 */
	public static function groups() {
		$login = BDACI_PA_Login::url();
		return array(
			'sign'    => array( __( 'Sign-in and security', 'bdaci-portal-access' ), sprintf( /* translators: %s: sign-in address */ __( 'The sign-in page is %s. Use test people from Portal admin › Test data.', 'bdaci-portal-access' ), $login ), array(
				'SIGN-01' => array( 'Sign-in page looks right', 'out', array( 'Open the sign-in page in a private window.', 'Check the left panel (badge, illustration, headline) and the right side (logo, buttons, form).' ), 'The split layout shows with the BDACI logo, Microsoft and Google buttons (if turned on), email and password fields, and the help contact.' ),
				'SIGN-02' => array( 'Sign in with email, password and emailed code', 'board', array( "Enter a Board Member's email and the test password.", "Check that person's inbox for the 6-digit code.", 'Enter the code.' ), 'A code arrives within a minute. After entering it, you land on the portal Home page with your name in the top bar.' ),
				'SIGN-03' => array( 'Wrong password', 'out', array( 'Enter a real email with a wrong password.' ), 'A clear error says the email or password is wrong. No code is sent.' ),
				'SIGN-04' => array( 'Wrong or expired code', 'board', array( 'Sign in, then type 000000 as the code.', 'Use Send a new code and enter the new one.' ), 'The wrong code is refused with a message. The new code works. An old code stops working once a new one is sent.' ),
				'SIGN-05' => array( 'Too many wrong passwords', 'out', array( 'Enter a wrong password 5 times in a row for the same email.' ), 'Sign-in is paused for that email with a message to try again later.' ),
				'SIGN-06' => array( 'Forgot password', 'board', array( 'Click Forgot your password?', 'Request a reset link, open the email, set a new password.', 'Sign in with the new password.' ), 'The reset email arrives, the new password is accepted (12+ characters), and sign-in works.' ),
				'SIGN-07' => array( 'Microsoft 365 sign-in', 'board', array( 'Click Sign in with Microsoft 365 and sign in with an allowed Microsoft account.' ), "You return to the portal signed in. Skip this if Microsoft sign-in isn't set up yet." ),
				'SIGN-08' => array( 'Google sign-in', 'board', array( 'Click Sign in with Google and sign in with an allowed Google account.' ), "You return to the portal signed in. Skip this if Google sign-in isn't set up yet." ),
				'SIGN-09' => array( 'Unknown Microsoft or Google account becomes a request', 'out', array( 'Sign in with Microsoft or Google using an account that is not on the list.' ), 'You are told your request was sent. An admin sees it as Pending under Authorized Users.' ),
				'SIGN-10' => array( 'Portal pages need sign-in', 'out', array( 'In a private window, open the portal home page and a Governance page directly.' ), 'Each sends you to the sign-in page. After signing in you land on the page you asked for.' ),
				'SIGN-11' => array( 'Private document links need sign-in', 'out', array( 'Copy a document link while signed in, then open it in a private window.' ), 'The file does not open. You are asked to sign in first.' ),
				'SIGN-12' => array( 'Sign out', 'any', array( 'Click Sign out in the top bar (on a phone, in the menu).', 'Press the browser Back button.' ), 'You are signed out and Back does not show portal content.' ),
			) ),
			'access'  => array( __( 'Roles and access', 'bdaci-portal-access' ), __( 'Check that each role sees what it should, and only that.', 'bdaci-portal-access' ), array(
				'ACC-01' => array( 'Board Member sees Board pages', 'board', array( 'Open Leadership, Governance & Core Documents, New Director Orientation and Quality Assurance.' ), 'All four open. Education and Finance Committee show Members only unless the person also has those roles.' ),
				'ACC-02' => array( 'Education Committee member is kept out of Board pages', 'edu', array( 'Open Governance & Core Documents from the menu or by typing its address.' ), 'The page is blocked with a Restricted message and a Request access button.' ),
				'ACC-03' => array( 'Request access and approval', 'edu', array( 'On a restricted page, press Request access and add a short note.', 'As Portal Admin, open Authorized Users and approve the request.', 'As the member, reload the page.' ), 'The admin sees the request with the note. After approval the member can open the page, and access stays until an admin revokes it.' ),
				'ACC-04' => array( 'Finance Committee member', 'fin', array( 'Open Finance Committee, then try Education Committee.' ), 'Finance Committee opens. Education Committee shows Members only.' ),
				'ACC-05' => array( 'Revoke takes effect at once', 'admin', array( 'Keep a member signed in in another window.', 'As admin, press Revoke for that person.', 'As the member, click any portal link.' ), "The member is signed out right away and can't sign back in. Restore gives access back." ),
				'ACC-06' => array( 'Hidden section', 'admin', array( 'In Sections & visibility, hide a section (e.g. Strategic Plan).', 'Check the menu as a Board Member, then as Portal Admin.' ), "Members don't see it at all. Admins still see it, marked hidden." ),
			) ),
			'home'    => array( __( 'Home, menus and calendar', 'bdaci-portal-access' ), '', array(
				'HOME-01' => array( 'Home page', 'board', array( 'Open Home after signing in.' ), 'A greeting, the next meeting card, a to-do list (sign-offs, RSVPs), recently posted documents and a mini calendar all show.' ),
				'HOME-02' => array( 'Side menu and top menu', 'board', array( 'Click every item in the top menu and the side menu.' ), "Each opens the right page and the current item is highlighted. No page shows the site theme's header or a 404." ),
				'HOME-03' => array( 'Calendar', 'board', array( 'Open Calendar, move to the next and previous month, click a meeting.' ), "Meetings show in their group colour. Clicking one shows its time, place, video link and documents. Board Members don't see Education-only meetings." ),
				'HOME-04' => array( 'Meetings list', 'board', array( 'Open Meetings.' ), 'Upcoming and past meetings show with Agenda, Meeting package and Minutes buttons where documents are attached. PDFs open in the browser.' ),
				'HOME-05' => array( 'Directory', 'board', array( 'Open Leadership.' ), 'Each person shows picture, position, term and email. Phone shows only for people who chose to share it.' ),
				'HOME-06' => array( 'Support form', 'any', array( 'Open Support, fill in the form and send it.' ), 'A confirmation shows, and the help contact address receives the message.' ),
			) ),
			'meetings' => array( __( 'Meetings and RSVP', 'bdaci-portal-access' ), '', array(
				'MEET-01' => array( 'Add a meeting', 'admin', array( 'Open Portal admin › Meetings.', 'Add a Board meeting next week with a place and a Teams link.' ), "The meeting is saved and shows on Calendar, Meetings and the Home page's next meeting card." ),
				'MEET-02' => array( 'Video link must be https', 'admin', array( 'Add a meeting with a video link that starts with http:// (not https).' ), 'The link is refused with a message asking for an https address.' ),
				'MEET-03' => array( 'RSVP', 'board', array( 'On Home or Meetings, choose Attending, then change to Not attending.' ), "The choice is saved, shows on reload, and the admin sees it in the meeting's RSVP list." ),
				'MEET-04' => array( 'Attach documents to a meeting', 'admin', array( 'Upload an agenda and attach it to the meeting.', 'As a Board Member, open the meeting.' ), 'The Agenda button appears on that meeting and opens the file.' ),
				'MEET-05' => array( 'Edit and delete a meeting', 'admin', array( "Change a meeting's time, then delete a test meeting." ), 'Changes show everywhere right away. The deleted meeting disappears from all pages.' ),
			) ),
			'docs'    => array( __( 'Documents and sections', 'bdaci-portal-access' ), __( 'Upload screen: Portal admin › Sections & Uploads.', 'bdaci-portal-access' ), array(
				'DOC-01' => array( 'Upload by dragging, pasting and browsing', 'admin', array( 'Drag a PDF onto the dotted box.', 'Copy a file or screenshot and press Ctrl+V / ⌘+V.', 'Click the box and pick a file.' ), 'Each method adds the file to the upload list. Files over 2 MB or of the wrong type are refused with a message.' ),
				'DOC-02' => array( 'Upload several files at once', 'admin', array( 'Add 3 files, pick a section and who can open them, press Upload.' ), 'All 3 are uploaded and each is titled from its file name.' ),
				'DOC-03' => array( 'Section picker shows where files go', 'admin', array( 'Point at or click each section card.', 'Click a map to zoom it, then close it.' ), "The matching menu item and spot on the page light up on the group's map. The zoomed map opens full screen and closes with Close or Esc." ),
				'DOC-04' => array( 'Where this file will appear', 'admin', array( 'Tick Minutes and Education Committee.', 'Change who can open it.' ), "The box above Upload lists Meetings / Calendar and Education Committee › Approved minutes archive, and updates the 'Who can open it' line." ),
				'DOC-05' => array( 'Who can open a document', 'admin', array( 'Upload a file for Board Members only.', 'Open its link as an Education Committee member.' ), 'The Board Member can open it. The Education member is refused.' ),
				'DOC-06' => array( 'Document shows in the right place', 'board', array( 'Find the files uploaded in DOC-02 and DOC-05 on their pages.' ), 'Each file appears in every section that was ticked, with the right title and date.' ),
				'DOC-07' => array( 'Edit and delete a document', 'admin', array( "Change a document's title, sections and who can open it.", 'Delete a test document.' ), "Changes show on the member pages. The deleted file's link no longer opens." ),
				'DOC-08' => array( 'Upload history and CSV', 'admin', array( 'Scroll to Upload history, filter by section, press Export CSV.' ), 'The history lists who uploaded what and when. The CSV opens in Excel with the same rows.' ),
				'DOC-09' => array( 'Add a new section', 'admin', array( 'Press + New section, name it, choose Board or Committees and who can see it, create it.' ), "The section appears in the side menu in the chosen group, with its own page, and as a card in the 'Sections you added' group." ),
			) ),
			'signoff' => array( __( 'Sign-offs', 'bdaci-portal-access' ), __( 'New Director Orientation and Quality Assurance.', 'bdaci-portal-access' ), array(
				'SO-01' => array( 'Sign-off needs every document opened', 'board', array( 'Open New Director Orientation.', 'Try to sign before opening the documents, then open each one.' ), 'Sign off stays disabled until every document has been opened. The progress bar fills as you go.' ),
				'SO-02' => array( 'Sign off', 'board', array( 'Tick the confirmation and press Sign off.' ), 'A receipt shows your name with the date and time. The to-do item disappears from Home.' ),
				'SO-03' => array( 'Audit trail', 'admin', array( 'Open Portal admin › Sign-off Audit Trail.' ), 'The chart and table show who signed, when, and who is still waiting.' ),
				'SO-04' => array( 'Send reminders', 'admin', array( 'Press Remind people who have not signed.' ), 'Waiting people get an email, and the trail records that a reminder was sent.' ),
				'SO-05' => array( 'Quality Assurance sign-off', 'board', array( 'Repeat SO-01 and SO-02 on Quality Assurance.' ), 'Works the same way and is recorded separately in the audit trail.' ),
			) ),
			'admin'   => array( __( 'Running the portal (admins)', 'bdaci-portal-access' ), '', array(
				'ADM-01' => array( 'Add one person', 'admin', array( 'Open Portal admin › Authorized Users.', 'Add an email, name and role, with the invitation ticked.' ), 'The person appears in the list and receives an email to set a password.' ),
				'ADM-02' => array( 'Add many at once', 'admin', array( 'Open Add many at once and paste 3 lines like: jane@example.org, Jane Doe, board;finance' ), 'All 3 are added with the right roles. Bad lines are reported, not silently skipped.' ),
				'ADM-03' => array( 'Change roles', 'admin', array( 'Give a Board Member the Finance Committee role too.', 'As that person, reload the portal.' ), 'Finance Committee now opens for them.' ),
				'ADM-04' => array( 'Directory details', 'admin', array( "Open a person's Directory details, set position and term, save." ), 'The details show on the Leadership page.' ),
				'ADM-05' => array( 'Sign-in page preview', 'admin', array( 'While signed in, open Settings › Preview the sign-in page.' ), "The sign-in page shows with a yellow preview bar, and its buttons don't do anything." ),
				'ADM-06' => array( 'Test data add and clear', 'admin', array( 'Open Test data, press Add sample data twice, then clear it.' ), 'Each press adds more people, meetings and documents. Clearing removes only the test items.' ),
				'ADM-07' => array( 'Same screens in WordPress admin', 'admin', array( 'Open BDACI Portal in the WordPress admin menu and try Authorized Users and Sections & Uploads.' ), 'The same screens work there and return you to WordPress admin after saving.' ),
				'ADM-08' => array( 'QA Testing access', 'admin', array( 'On this page, open Who can see this page, give one member access and save.', 'Sign in as that member.' ), 'The member sees QA Testing in the side menu and can mark scenarios. Members without access get no menu item and are refused if they type the address.' ),
			) ),
			'profile' => array( __( 'My profile', 'bdaci-portal-access' ), '', array(
				'PROF-01' => array( 'Upload a profile photo', 'any', array( 'Open My profile and upload a JPG or PNG under 5 MB.' ), 'The photo shows in the top bar and on the directory pages.' ),
				'PROF-02' => array( 'Pick an illustrated picture or initials', 'any', array( 'Choose one of the illustrated pictures, then switch to initials.' ), 'The top bar updates after each save.' ),
				'PROF-03' => array( 'Phone number sharing', 'any', array( 'Add a phone number and leave sharing off. Check Leadership as another member. Turn sharing on and check again.' ), 'The phone shows only when sharing is on.' ),
			) ),
			'devices' => array( __( 'Phones, tablets and browsers', 'bdaci-portal-access' ), __( 'Repeat a few key scenarios on other devices.', 'bdaci-portal-access' ), array(
				'DEV-01' => array( 'Sign in on a phone', 'phone', array( 'Sign in on an iPhone or Android phone.' ), 'The page fits the screen with no sideways scrolling, and the code field brings up the number keyboard.' ),
				'DEV-02' => array( 'Menu on a phone', 'phone', array( 'Open the Menu button and visit 3 pages.', 'Sign out from the menu.' ), 'The menu opens and closes, links work, and Sign out is in the menu.' ),
				'DEV-03' => array( 'Upload on a phone or tablet', 'phone', array( 'As admin, upload a photo from the phone.' ), 'The upload works and the section picker and maps fit the screen.' ),
				'DEV-04' => array( 'Other browsers', 'any', array( 'Repeat SIGN-02, HOME-02 and DOC-01 in Safari, Edge and Firefox.' ), "Everything works the same. Note any browser where it doesn't." ),
			) ),
		);
	}

	/**
	 * Where each scenario is tested: id => [breadcrumb parts, address].
	 *
	 * @return array
	 */
	public static function where() {
		$portal = __( 'Portal', 'bdaci-portal-access' );
		$top    = __( 'Top menu', 'bdaci-portal-access' );
		$board  = __( 'Board', 'bdaci-portal-access' );
		$comm   = __( 'Committees', 'bdaci-portal-access' );
		$adm    = __( 'Portal admin', 'bdaci-portal-access' );
		$wpa    = array( __( 'WordPress admin', 'bdaci-portal-access' ), 'BDACI Portal' );
		$sc     = BDACI_PA_Portal::screens();
		$p      = static function ( $s ) {
			return BDACI_PA_Portal::url( $s );
		};
		$a      = static function ( $k, $args = array() ) {
			return BDACI_PA_Admin_Portal::front_url( $k, $args );
		};
		$login  = array( array( __( 'Sign-in page', 'bdaci-portal-access' ) ), BDACI_PA_Login::url() );
		$users  = array( array( $portal, $adm, __( 'Authorized Users', 'bdaci-portal-access' ) ), $a( 'users' ) );
		$docs   = array( array( $portal, $adm, __( 'Sections & Uploads', 'bdaci-portal-access' ) ), $a( 'docs' ) );
		$meet   = array( array( $portal, $adm, __( 'Meetings', 'bdaci-portal-access' ) ), $a( 'meetings' ) );
		$trail  = array( array( $portal, $adm, __( 'Sign-off Audit Trail', 'bdaci-portal-access' ) ), $a( 'signoffs' ) );
		$gov    = array( array( $portal, $board, $sc['governance'][0] ), $p( 'governance' ) );
		$home   = array( array( $portal, __( 'Home', 'bdaci-portal-access' ) ), BDACI_PA_Settings::portal_home_url() );
		$prof   = array( array( $portal, __( 'Top bar', 'bdaci-portal-access' ), __( 'your name', 'bdaci-portal-access' ), $sc['profile'][0] ), $p( 'profile' ) );
		$out    = array();
		foreach ( array( 'SIGN-01', 'SIGN-02', 'SIGN-03', 'SIGN-04', 'SIGN-05', 'SIGN-07', 'SIGN-08', 'SIGN-09', 'DEV-01', 'DEV-04' ) as $id ) {
			$out[ $id ] = $login;
		}
		$out['SIGN-06'] = array( array( __( 'Sign-in page', 'bdaci-portal-access' ), __( 'Forgot your password?', 'bdaci-portal-access' ) ), BDACI_PA_Login::url() );
		$out['SIGN-10'] = $home;
		$out['SIGN-11'] = $gov;
		$out['SIGN-12'] = array( array( $portal, __( 'Top bar', 'bdaci-portal-access' ), __( 'Sign out', 'bdaci-portal-access' ) ), BDACI_PA_Settings::portal_home_url() );
		$out['ACC-01']  = array( array( $portal, $board, $sc['leadership'][0] ), $p( 'leadership' ) );
		$out['ACC-02']  = $gov;
		$out['ACC-03']  = $gov;
		$out['ACC-04']  = array( array( $portal, $comm, $sc['finance'][0] ), $p( 'finance' ) );
		$out['ACC-05']  = $users;
		$out['ACC-06']  = $docs;
		$out['HOME-01'] = $home;
		$out['HOME-02'] = $home;
		$out['HOME-03'] = array( array( $portal, $top, $sc['calendar'][0] ), $p( 'calendar' ) );
		$out['HOME-04'] = array( array( $portal, $top, $sc['meetings'][0] ), $p( 'meetings' ) );
		$out['HOME-05'] = array( array( $portal, $board, $sc['leadership'][0] ), $p( 'leadership' ) );
		$out['HOME-06'] = array( array( $portal, $top, $sc['help'][0] ), $p( 'help' ) );
		foreach ( array( 'MEET-01', 'MEET-02', 'MEET-04', 'MEET-05' ) as $id ) {
			$out[ $id ] = $meet;
		}
		$out['MEET-03'] = $out['HOME-04'];
		foreach ( array( 'DOC-01', 'DOC-02', 'DOC-03', 'DOC-04', 'DOC-05', 'DOC-07', 'DOC-08', 'DOC-09', 'DEV-03' ) as $id ) {
			$out[ $id ] = $docs;
		}
		$out['DOC-06']  = $gov;
		$out['SO-01']   = array( array( $portal, $board, $sc['orientation'][0] ), $p( 'orientation' ) );
		$out['SO-02']   = $out['SO-01'];
		$out['SO-03']   = $trail;
		$out['SO-04']   = $trail;
		$out['SO-05']   = array( array( $portal, $board, $sc['qa'][0] ), $p( 'qa' ) );
		$out['ADM-01']  = $users;
		$out['ADM-02']  = $users;
		$out['ADM-03']  = $users;
		$out['ADM-04']  = array( array( $portal, $adm, __( 'Authorized Users', 'bdaci-portal-access' ), __( 'Directory details', 'bdaci-portal-access' ) ), $a( 'users' ) );
		$out['ADM-05']  = array( array_merge( $wpa, array( __( 'Settings', 'bdaci-portal-access' ), __( 'Preview the sign-in page', 'bdaci-portal-access' ) ) ), admin_url( 'admin.php?page=bdaci-portal-settings' ) );
		$out['ADM-06']  = array( array( $portal, $adm, __( 'Test data', 'bdaci-portal-access' ) ), $a( 'test' ) );
		$out['ADM-07']  = array( $wpa, admin_url( 'admin.php?page=bdaci-portal' ) );
		$out['ADM-08']  = array( array( $portal, $adm, __( 'QA Testing', 'bdaci-portal-access' ), __( 'Who can see this page', 'bdaci-portal-access' ) ), $a( 'qa', array( 'view' => 'access' ) ) );
		foreach ( array( 'PROF-01', 'PROF-02', 'PROF-03' ) as $id ) {
			$out[ $id ] = $prof;
		}
		$out['DEV-02']  = array( array( $portal, __( 'Menu button (phone)', 'bdaci-portal-access' ) ), BDACI_PA_Settings::portal_home_url() );
		return $out;
	}

	/**
	 * Breadcrumb showing where a scenario is tested, with an Open link.
	 *
	 * @param string $id Scenario.
	 * @return string
	 */
	public static function where_html( $id ) {
		$w = self::where()[ $id ] ?? null;
		if ( ! $w ) {
			return '';
		}
		$h = '<nav class="qa-where" aria-label="' . esc_attr( sprintf( /* translators: %s: scenario id */ __( 'Where to find %s', 'bdaci-portal-access' ), $id ) ) . '"><span class="small muted">' . esc_html__( 'Where to find it:', 'bdaci-portal-access' ) . '</span><ol class="crumbs">';
		foreach ( $w[0] as $part ) {
			$h .= '<li>' . esc_html( $part ) . '</li>';
		}
		return $h . '</ol><a class="btn btn-sm" href="' . esc_url( $w[1] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'bdaci-portal-access' ) . ' <span aria-hidden="true">↗</span><span class="sr-only screen-reader-text"> ' . esc_html__( '(new tab)', 'bdaci-portal-access' ) . '</span></a></nav>';
	}

	/**
	 * Scenario id => group key.
	 *
	 * @return array
	 */
	public static function index() {
		$out = array();
		foreach ( self::groups() as $g => $grp ) {
			foreach ( array_keys( $grp[2] ) as $id ) {
				$out[ $id ] = $g;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Records
	 * ------------------------------------------------------------------ */

	/**
	 * Every record for a round (oldest first), or for all rounds.
	 *
	 * @param int $round Round id, 0 for all.
	 * @return array
	 */
	public static function records( $round = 0 ) {
		global $wpdb;
		$t = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $round
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE round_id = %d ORDER BY id ASC", $round ) )
			: $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC" );
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest result per scenario in a round (cleared ones left out).
	 *
	 * @param array $records Records of one round.
	 * @return array scenario => row
	 */
	public static function latest( array $records ) {
		$out = array();
		foreach ( $records as $r ) {
			if ( 'note' !== $r->status ) {
				$out[ $r->scenario ] = $r;
			}
		}
		return array_filter(
			$out,
			static function ( $r ) {
				return 'clear' !== $r->status;
			}
		);
	}

	/**
	 * Add a record.
	 *
	 * @param int    $round    Round.
	 * @param string $scenario Scenario id.
	 * @param string $status   pass|fail|skip|clear.
	 * @param string $note     Note.
	 * @param array  $shots    Screenshot file names.
	 * @return int Record id.
	 */
	public static function add( $round, $scenario, $status, $note, array $shots = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'round_id'   => (int) $round,
				'scenario'   => $scenario,
				'status'     => $status,
				'note'       => $note,
				'shots'      => $shots ? wp_json_encode( array_values( $shots ) ) : null,
				'user_id'    => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------------
	 * Screenshots
	 * ------------------------------------------------------------------ */

	const MAX_SHOTS = 6;
	const MAX_SHOT_BYTES = 8388608; // 8 MB.

	/**
	 * Largest screenshot accepted: 8 MB or the server's upload limit, whichever is smaller.
	 *
	 * @return int Bytes.
	 */
	public static function shot_limit() {
		$server = (int) wp_max_upload_size();
		return $server > 0 ? min( self::MAX_SHOT_BYTES, $server ) : self::MAX_SHOT_BYTES;
	}

	/**
	 * Write a CSV row, neutralising cells that a spreadsheet would run as a formula.
	 *
	 * @param resource $out  Stream.
	 * @param array    $row  Cells.
	 */
	private static function csv_row( $out, array $row ) {
		fputcsv(
			$out,
			array_map(
				static function ( $v ) {
					$v = (string) $v;
					return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
				},
				$row
			)
		);
	}

	/**
	 * Allowed screenshot types: extension => mime.
	 *
	 * @return array
	 */
	public static function shot_types() {
		return array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
	}

	/**
	 * Private folder for screenshots (inside the protected documents folder).
	 *
	 * @return string
	 */
	public static function shot_dir() {
		return trailingslashit( BDACI_PA_Documents::base_dir() ) . 'qa-screenshots';
	}

	/**
	 * Screenshot file names of a record.
	 *
	 * @param object $r Record.
	 * @return string[]
	 */
	public static function shots_of( $r ) {
		$list = ! empty( $r->shots ) ? json_decode( (string) $r->shots, true ) : array();
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * Address that shows one screenshot to people who can see QA Testing.
	 *
	 * @param int $rid Record id.
	 * @param int $n   Index.
	 * @return string
	 */
	public static function shot_url( $rid, $n ) {
		return add_query_arg(
			array(
				'action' => 'bdaci_pa_qa_shot',
				'r'      => (int) $rid,
				'n'      => (int) $n,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Thumbnails of a record's screenshots.
	 *
	 * @param object $r Record.
	 * @return string
	 */
	public static function shots_html( $r ) {
		$list = self::shots_of( $r );
		if ( ! $list ) {
			return '';
		}
		$h = '<span class="qa-shots">';
		foreach ( $list as $n => $f ) {
			$u  = self::shot_url( $r->id, $n );
			$h .= '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener" title="' . esc_attr__( 'Open the screenshot full size', 'bdaci-portal-access' ) . '"><img src="' . esc_url( $u ) . '" alt="' . esc_attr( sprintf( /* translators: 1: number, 2: scenario */ __( 'Screenshot %1$d for %2$s', 'bdaci-portal-access' ), $n + 1, $r->scenario ) ) . '" loading="lazy"></a>';
		}
		return $h . '</span>';
	}

	/**
	 * Save uploaded screenshots from $_FILES['shots'].
	 *
	 * @param string[] $errors Filled with problems.
	 * @return string[] Saved file names.
	 */
	public static function save_shots( array &$errors ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by the caller.
		if ( empty( $_FILES['shots'] ) || ! is_array( $_FILES['shots']['name'] ?? null ) ) {
			return array();
		}
		$files = $_FILES['shots']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
		$types = self::shot_types();
		$saved = array();
		$dir   = '';
		foreach ( array_keys( $files['name'] ) as $i ) {
			$err = (int) ( $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE );
			if ( UPLOAD_ERR_NO_FILE === $err ) {
				continue;
			}
			$name = sanitize_file_name( (string) $files['name'][ $i ] );
			if ( count( $saved ) >= self::MAX_SHOTS ) {
				/* translators: %d: max screenshots */
				$errors[] = sprintf( __( 'Only %d screenshots can be added at a time.', 'bdaci-portal-access' ), self::MAX_SHOTS );
				break;
			}
			$tmp = (string) $files['tmp_name'][ $i ];
			if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
				/* translators: 1: file name, 2: size limit */
				$errors[] = sprintf( __( '%1$s is larger than %2$s.', 'bdaci-portal-access' ), $name, size_format( self::shot_limit() ) );
				continue;
			}
			if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( $tmp ) ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( '%s did not upload. Try again.', 'bdaci-portal-access' ), $name );
				continue;
			}
			if ( filesize( $tmp ) > self::shot_limit() ) {
				/* translators: 1: file name, 2: size limit */
				$errors[] = sprintf( __( '%1$s is larger than %2$s.', 'bdaci-portal-access' ), $name, size_format( self::shot_limit() ) );
				continue;
			}
			$info = function_exists( 'getimagesize' ) ? @getimagesize( $tmp ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$ext  = $info ? array_search( $info['mime'], $types, true ) : false;
			if ( ! $ext ) {
				/* translators: %s: file name */
				$errors[] = sprintf( __( '%s is not a picture (PNG, JPG, GIF or WebP).', 'bdaci-portal-access' ), $name );
				continue;
			}
			if ( ! $dir ) {
				BDACI_PA_Documents::ensure_private_dir();
				$dir = self::shot_dir();
				wp_mkdir_p( $dir );
				if ( ! file_exists( $dir . '/index.php' ) ) {
					file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
			}
			$file = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 12, false, false ) ) . '.' . $ext;
			if ( move_uploaded_file( $tmp, $dir . '/' . $file ) ) {
				$saved[] = $file;
			} else {
				/* translators: %s: file name */
				$errors[] = sprintf( __( '%s could not be saved.', 'bdaci-portal-access' ), $name );
			}
		}
		return $saved;
	}

	/**
	 * Send one screenshot (only to people who can see QA Testing).
	 */
	public static function handle_shot() {
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You do not have access to QA Testing.', 'bdaci-portal-access' ), 403 );
		}
		global $wpdb;
		$rid = isset( $_GET['r'] ) ? absint( $_GET['r'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n   = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list = $row ? self::shots_of( $row ) : array();
		$file = isset( $list[ $n ] ) ? basename( $list[ $n ] ) : '';
		$path = $file ? self::shot_dir() . '/' . $file : '';
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$types = self::shot_types();
		if ( ! $path || ! isset( $types[ $ext ] ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Screenshot not found.', 'bdaci-portal-access' ), 404 );
		}
		nocache_headers();
		header( 'Content-Type: ' . $types[ $ext ] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . $row->scenario . '-' . ( $n + 1 ) . '.' . $ext . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Local date and time of a stored GMT time.
	 *
	 * @param string $gmt GMT datetime.
	 * @return string
	 */
	public static function when( $gmt ) {
		$ts = strtotime( $gmt . ' UTC' );
		return $ts ? wp_date( get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), $ts ) : '';
	}

	/**
	 * Person's name.
	 *
	 * @param int $uid User.
	 * @return string
	 */
	public static function who( $uid ) {
		$u = $uid ? get_userdata( (int) $uid ) : null;
		return $u ? $u->display_name : __( 'Someone', 'bdaci-portal-access' );
	}

	/**
	 * Result label.
	 *
	 * @param string $s Status.
	 * @return string
	 */
	public static function label( $s ) {
		$l = array(
			'pass'  => __( 'Passed', 'bdaci-portal-access' ),
			'fail'  => __( 'Failed', 'bdaci-portal-access' ),
			'skip'  => __( 'Skipped', 'bdaci-portal-access' ),
			'clear' => __( 'Cleared', 'bdaci-portal-access' ),
			'note'  => __( 'Note added', 'bdaci-portal-access' ),
		);
		return $l[ $s ] ?? $s;
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * A link to this page keeping the given arguments.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private static function link( array $args ) {
		$args = array_filter(
			$args,
			static function ( $v ) {
				return '' !== $v && null !== $v && 0 !== $v;
			}
		);
		return BDACI_PA_Admin_Portal::$front ? BDACI_PA_Admin_Portal::front_url( 'qa', $args ) : add_query_arg( $args, admin_url( 'admin.php?page=bdaci-portal-qa' ) );
	}

	/**
	 * Read a query argument.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private static function arg( $k ) {
		return isset( $_GET[ $k ] ) ? sanitize_key( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Form opener for this page's actions.
	 *
	 * @param string $action Action.
	 * @param string $extra  Extra attributes.
	 * @return string
	 */
	private static function form( $action, $extra = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php?action=bdaci_pa_qa_' . $action ) ) . '"' . $extra . '><input type="hidden" name="action" value="bdaci_pa_qa_' . esc_attr( $action ) . '">' . wp_nonce_field( 'bdaci_pa_qa_' . $action, '_wpnonce', true, false ) . BDACI_PA_Admin_Portal::front_field();
	}

	/**
	 * Draw the page.
	 */
	public static function page() {
		if ( ! self::can_view() ) {
			return;
		}
		$groups  = self::groups();
		$current = self::current_round();
		$rounds  = self::rounds();
		$round   = (int) self::arg( 'round' );
		$round   = isset( $rounds[ $round ] ) ? $round : $current;
		$view    = self::arg( 'view' );
		$view    = in_array( $view, array( 'record', 'access' ), true ) ? $view : 'tests';
		if ( 'access' === $view && ! self::can_manage() ) {
			$view = 'tests';
		}
		$group  = self::arg( 'group' );
		$group  = isset( $groups[ $group ] ) ? $group : '';
		$filter = self::arg( 'show' );
		$filter = in_array( $filter, array( 'todo', 'fail', 'mine' ), true ) ? $filter : '';
		$recs   = self::records( $round );
		$latest = self::latest( $recs );
		$total  = count( self::index() );

		BDACI_PA_Admin_Portal::open_page( __( 'QA Testing', 'bdaci-portal-access' ), __( 'Test scenarios for checking the portal before launch. Every check is saved with who did it and when. Start a new round to test everything again.', 'bdaci-portal-access' ) );
		BDACI_PA_Admin_Portal::notice();
		echo '<div class="bp bp-admin qa">';

		// Round bar.
		$n = array(
			'pass' => 0,
			'fail' => 0,
			'skip' => 0,
		);
		foreach ( $latest as $r ) {
			if ( isset( $n[ $r->status ] ) ) {
				++$n[ $r->status ];
			}
		}
		$done = array_sum( $n );
		$h    = '<section class="panel qa-bar"><div class="qa-row"><form method="get" action="' . esc_url( BDACI_PA_Admin_Portal::$front ? BDACI_PA_Settings::portal_home_url() : admin_url( 'admin.php' ) ) . '" class="qa-row">';
		$h   .= BDACI_PA_Admin_Portal::$front ? '<input type="hidden" name="manage" value="qa">' : '<input type="hidden" name="page" value="bdaci-portal-qa">';
		$h   .= '<input type="hidden" name="view" value="' . esc_attr( 'tests' === $view ? '' : $view ) . '"><label for="qa-round"><b>' . esc_html__( 'Test round', 'bdaci-portal-access' ) . '</b></label> <select id="qa-round" name="round" onchange="this.form.submit()">';
		foreach ( $rounds as $id => $r ) {
			$h .= '<option value="' . (int) $id . '"' . selected( $id, $round, false ) . '>' . esc_html( $r['name'] . ( (int) $id === $current ? ' ' . __( '(current)', 'bdaci-portal-access' ) : '' ) ) . '</option>';
		}
		$h .= '</select><noscript><button class="btn btn-sm">' . esc_html__( 'Show', 'bdaci-portal-access' ) . '</button></noscript></form>';
		if ( self::can_manage() ) {
			$h .= '<details class="qa-new"><summary class="btn btn-sm">' . esc_html__( 'Start a new round', 'bdaci-portal-access' ) . '</summary>' . self::form( 'round', ' class="qa-row"' ) . '<label class="screen-reader-text sr-only" for="qa-round-name">' . esc_html__( 'Round name', 'bdaci-portal-access' ) . '</label><input id="qa-round-name" name="name" maxlength="60" value="' . esc_attr( sprintf( /* translators: %d: round number */ __( 'Round %d', 'bdaci-portal-access' ), ( $rounds ? max( array_keys( $rounds ) ) : 0 ) + 1 ) ) . '"> <button class="btn btn-primary btn-sm">' . esc_html__( 'Start round', 'bdaci-portal-access' ) . '</button><span class="small muted">' . esc_html__( 'Everyone switches to the new round. Earlier results are kept.', 'bdaci-portal-access' ) . '</span></form></details>';
		}
		$h .= '<a class="btn btn-sm" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_qa_csv&round=' . $round ), 'bdaci_pa_qa_csv' ) ) . '">' . esc_html__( 'Download results (CSV)', 'bdaci-portal-access' ) . '</a></div>';
		$h .= '<div class="qa-row"><div class="qa-meter" aria-hidden="true"><i class="p" style="width:' . esc_attr( round( $n['pass'] / $total * 100, 2 ) ) . '%"></i><i class="f" style="width:' . esc_attr( round( $n['fail'] / $total * 100, 2 ) ) . '%"></i><i class="s" style="width:' . esc_attr( round( $n['skip'] / $total * 100, 2 ) ) . '%"></i></div>';
		$h .= '<span class="tabnum"><b>' . (int) $done . '</b>/' . (int) $total . ' ' . esc_html__( 'tested', 'bdaci-portal-access' ) . '</span><span class="pill pill-ok">' . (int) $n['pass'] . ' ' . esc_html__( 'passed', 'bdaci-portal-access' ) . '</span><span class="pill pill-err">' . (int) $n['fail'] . ' ' . esc_html__( 'failed', 'bdaci-portal-access' ) . '</span><span class="pill pill-warn">' . (int) $n['skip'] . ' ' . esc_html__( 'skipped', 'bdaci-portal-access' ) . '</span></div>';
		if ( $round !== $current ) {
			$h .= '<p class="notice-bar err" style="margin:0">' . esc_html__( 'You are looking at an earlier round. Marks here change that round. Switch to the current round to keep testing.', 'bdaci-portal-access' ) . '</p>';
		}
		// Tabs.
		$tabs = array(
			'tests'  => __( 'Test scenarios', 'bdaci-portal-access' ),
			'record' => __( 'QA record', 'bdaci-portal-access' ),
		);
		if ( self::can_manage() ) {
			$tabs['access'] = __( 'Who can see this page', 'bdaci-portal-access' );
		}
		$h .= '<nav class="qa-tabs" aria-label="' . esc_attr__( 'QA views', 'bdaci-portal-access' ) . '">';
		foreach ( $tabs as $k => $l ) {
			$h .= '<a href="' . esc_url( self::link( array( 'view' => 'tests' === $k ? '' : $k, 'round' => $round !== $current ? $round : 0 ) ) ) . '"' . ( $view === $k ? ' aria-current="page"' : '' ) . '>' . esc_html( $l ) . '</a>';
		}
		$h .= '</nav></section>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		if ( 'record' === $view ) {
			echo self::record_view( $recs, $round, $groups ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( 'access' === $view ) {
			echo self::access_view(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo self::tests_view( $groups, $group, $filter, $recs, $latest, $round, $current ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';
	}

	/**
	 * Paste, drop and pick screenshots on a scenario card.
	 *
	 * @return string
	 */
	private static function script() {
		/* translators: %s: size limit */
		$none = esc_js( sprintf( __( 'PNG, JPG, GIF or WebP, up to %s each. You can also drop them on this card.', 'bdaci-portal-access' ), size_format( self::shot_limit() ) ) );
		/* translators: %s: size limit */
		$big  = esc_js( sprintf( __( 'Left out (larger than %s or not a picture):', 'bdaci-portal-access' ), size_format( self::shot_limit() ) ) );
		$lim  = (int) self::shot_limit();
		$sel  = esc_js( __( 'Ready to save:', 'bdaci-portal-access' ) );
		$max  = (int) self::MAX_SHOTS;
		return <<<JS
<script>
(function(){
	var LEFT = {};
	function okType(f){ return /^image\/(png|jpeg|gif|webp)$/.test(f.type); }
	function add(form, files, replace){
		var input = form.querySelector('input[type=file]'); if(!input){return;}
		var dt = new DataTransfer(), n = 0, left = [];
		if(!replace){ Array.prototype.forEach.call(input.files, function(f){ dt.items.add(f); n++; }); }
		Array.prototype.forEach.call(files, function(f, i){
			if(!okType(f) || f.size > {$lim} || n >= {$max}){ left.push(f.name || 'pasted picture'); return; }
			var name = f.name && f.name !== 'image.png' ? f.name : 'screenshot-' + Date.now() + '-' + i + '.png';
			dt.items.add(new File([f], name, {type: f.type})); n++;
		});
		input.files = dt.files; LEFT[form.dataset.qaId] = left; show(form);
	}
	function show(form){
		var input = form.querySelector('input[type=file]'), out = form.querySelector('.qa-picked'); if(!out){return;}
		out.textContent = '';
		var left = LEFT[form.dataset.qaId] || [];
		if(left.length){ var w = document.createElement('span'); w.style.color = 'var(--danger)'; w.textContent = '{$big} ' + left.join(', ') + '. '; out.appendChild(w); }
		if(!input.files.length){ if(!left.length){ out.textContent = '{$none}'; } return; }
		var b = document.createElement('b'); b.textContent = '{$sel} '; out.appendChild(b);
		Array.prototype.forEach.call(input.files, function(f){
			var img = document.createElement('img'); img.alt = f.name; img.className = 'qa-thumb';
			img.src = URL.createObjectURL(f); out.appendChild(img);
		});
	}
	document.querySelectorAll('.qa-case form.qa-act').forEach(function(form){
		var card = form.closest('.qa-case'), input = form.querySelector('input[type=file]');
		form.dataset.qaId = Math.random().toString(36).slice(2);
		if(input){ input.addEventListener('change', function(){ add(form, Array.prototype.slice.call(input.files), true); }); }
		var ta = form.querySelector('textarea');
		if(ta){ ta.addEventListener('paste', function(e){
			var items = (e.clipboardData || {}).files || [];
			if(items.length){ e.preventDefault(); add(form, items); }
		}); }
		card.addEventListener('dragover', function(e){ e.preventDefault(); card.classList.add('drop'); });
		card.addEventListener('dragleave', function(e){ if(e.target === card){ card.classList.remove('drop'); } });
		card.addEventListener('drop', function(e){ e.preventDefault(); card.classList.remove('drop'); add(form, e.dataTransfer.files); });
	});
})();
</script>
JS;
	}

	/**
	 * Scenarios, grouped, with group tabs and filters.
	 *
	 * @param array  $groups  Groups.
	 * @param string $group   Selected group ('' = overview of all).
	 * @param string $filter  Filter.
	 * @param array  $recs    Round records.
	 * @param array  $latest  Latest per scenario.
	 * @param int    $round   Round shown.
	 * @param int    $current Current round.
	 * @return string
	 */
	private static function tests_view( array $groups, $group, $filter, array $recs, array $latest, $round, $current ) {
		$keep = array( 'round' => $round !== $current ? $round : 0 );
		$me   = get_current_user_id();

		// Group tabs with progress.
		$h = '<nav class="qa-groups" aria-label="' . esc_attr__( 'Test groups', 'bdaci-portal-access' ) . '">';
		$h .= '<a href="' . esc_url( self::link( $keep + array( 'show' => $filter ) ) ) . '"' . ( '' === $group ? ' aria-current="page"' : '' ) . '><b>' . esc_html__( 'All groups', 'bdaci-portal-access' ) . '</b><span class="small muted tabnum">' . count( $latest ) . '/' . count( self::index() ) . '</span></a>';
		$rows = '';
		foreach ( $groups as $g => $grp ) {
			$ids = array_keys( $grp[2] );
			$c   = array(
				'pass' => 0,
				'fail' => 0,
				'skip' => 0,
			);
			$last = null;
			foreach ( $ids as $id ) {
				if ( isset( $latest[ $id ] ) ) {
					++$c[ $latest[ $id ]->status ];
					if ( ! $last || $latest[ $id ]->id > $last->id ) {
						$last = $latest[ $id ];
					}
				}
			}
			$d   = array_sum( $c );
			$cls = $c['fail'] ? ' has-fail' : ( count( $ids ) === $d ? ' all-done' : '' );
			$h  .= '<a class="' . esc_attr( trim( $cls ) ) . '" href="' . esc_url( self::link( $keep + array( 'group' => $g, 'show' => $filter ) ) ) . '"' . ( $group === $g ? ' aria-current="page"' : '' ) . '><b>' . esc_html( $grp[0] ) . '</b><span class="small muted tabnum">' . (int) $d . '/' . count( $ids ) . ( $c['fail'] ? ' · ' . (int) $c['fail'] . ' ' . esc_html__( 'failed', 'bdaci-portal-access' ) : '' ) . '</span></a>';
			$w     = count( $ids );
			$rows .= '<tr><td><a href="' . esc_url( self::link( $keep + array( 'group' => $g ) ) ) . '"><b>' . esc_html( $grp[0] ) . '</b></a></td><td><div class="qa-meter sm" aria-hidden="true"><i class="p" style="width:' . esc_attr( round( $c['pass'] / $w * 100, 2 ) ) . '%"></i><i class="f" style="width:' . esc_attr( round( $c['fail'] / $w * 100, 2 ) ) . '%"></i><i class="s" style="width:' . esc_attr( round( $c['skip'] / $w * 100, 2 ) ) . '%"></i></div></td><td class="tabnum">' . (int) $d . '/' . (int) $w . '</td><td class="tabnum">' . (int) $c['pass'] . '</td><td class="tabnum">' . (int) $c['fail'] . '</td><td class="tabnum">' . (int) $c['skip'] . '</td><td>' . ( $last ? esc_html( self::who( $last->user_id ) ) . '<br><span class="small muted">' . esc_html( self::when( $last->created_at ) ) . '</span>' : '<span class="muted">–</span>' ) . '</td></tr>';
		}
		$h .= '</nav>';

		// Filters.
		$h .= '<div class="qa-row qa-filters"><span class="small muted">' . esc_html__( 'Show:', 'bdaci-portal-access' ) . '</span>';
		foreach ( array(
			''     => __( 'All', 'bdaci-portal-access' ),
			'todo' => __( 'Not tested', 'bdaci-portal-access' ),
			'fail' => __( 'Failed', 'bdaci-portal-access' ),
			'mine' => __( 'Marked by me', 'bdaci-portal-access' ),
		) as $k => $l ) {
			$h .= '<a class="chip" href="' . esc_url( self::link( $keep + array( 'group' => $group, 'show' => $k ) ) ) . '"' . ( $filter === $k ? ' aria-current="page"' : '' ) . '>' . esc_html( $l ) . '</a>';
		}
		$h .= '</div>';

		if ( '' === $group && '' === $filter ) {
			$h .= '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Progress by group', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html__( 'Pick a group to test it on its own.', 'bdaci-portal-access' ) . '</span></div><div class="tablewrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Group', 'bdaci-portal-access' ) . '</th><th style="min-width:140px">' . esc_html__( 'Progress', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Tested', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Passed', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Failed', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Skipped', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Last checked', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
		}

		// History per scenario.
		$hist = array();
		foreach ( $recs as $r ) {
			$hist[ $r->scenario ][] = $r;
		}
		$roles = self::roles();
		$shown = 0;
		foreach ( $groups as $g => $grp ) {
			if ( $group && $group !== $g ) {
				continue;
			}
			$cards = '';
			foreach ( $grp[2] as $id => $c ) {
				$r = $latest[ $id ] ?? null;
				if ( ( 'todo' === $filter && $r ) || ( 'fail' === $filter && ( ! $r || 'fail' !== $r->status ) ) || ( 'mine' === $filter && ( ! $r || (int) $r->user_id !== $me ) ) ) {
					continue;
				}
				++$shown;
				$st     = $r ? $r->status : '';
				$cards .= '<article class="qa-case' . ( $st ? ' st-' . esc_attr( $st ) : '' ) . '" id="qa-' . esc_attr( $id ) . '"><div class="qa-case-h"><span class="qa-id tabnum">' . esc_html( $id ) . '</span><h3>' . esc_html( $c[0] ) . '</h3><span class="pill">' . esc_html( $roles[ $c[1] ] ?? '' ) . '</span></div>' . self::where_html( $id );
				$cards .= '<div class="qa-body"><ol>';
				foreach ( $c[2] as $step ) {
					$cards .= '<li>' . esc_html( $step ) . '</li>';
				}
				$cards .= '</ol><div class="qa-expect"><b>' . esc_html__( 'Expected', 'bdaci-portal-access' ) . '</b>' . esc_html( $c[3] ) . '</div></div>';
				$cards .= self::form( 'mark', ' class="qa-act" enctype="multipart/form-data"' ) . '<input type="hidden" name="scenario" value="' . esc_attr( $id ) . '"><input type="hidden" name="round" value="' . (int) $round . '"><input type="hidden" name="group" value="' . esc_attr( $group ) . '"><input type="hidden" name="show" value="' . esc_attr( $filter ) . '">';
				$cards .= '<div class="qa-row">';
				foreach ( array( 'pass', 'fail', 'skip' ) as $s ) {
					$cards .= '<button class="qa-mark st-' . esc_attr( $s ) . '" name="status" value="' . esc_attr( $s ) . '" aria-pressed="' . ( $st === $s ? 'true' : 'false' ) . '">' . esc_html( self::label( $s ) ) . '</button>';
				}
				if ( $r ) {
					$cards .= '<span class="small">' . esc_html( self::label( $st ) ) . ' ' . esc_html__( 'by', 'bdaci-portal-access' ) . ' <b>' . esc_html( self::who( $r->user_id ) ) . '</b> · <span class="tabnum">' . esc_html( self::when( $r->created_at ) ) . '</span></span><button class="qa-mark" name="status" value="clear" formnovalidate>' . esc_html__( 'Clear', 'bdaci-portal-access' ) . '</button>';
				} else {
					$cards .= '<span class="small muted">' . esc_html__( 'Not tested yet', 'bdaci-portal-access' ) . '</span>';
				}
				$cards .= '</div>';
				// Notes and screenshots added since the latest result (and the result's own).
				$found = '';
				foreach ( array_reverse( $hist[ $id ] ?? array() ) as $x ) {
					if ( $r && $x->id < $r->id ) {
						break;
					}
					if ( ! $r && 'clear' === $x->status ) {
						break;
					}
					if ( '' !== (string) $x->note || $x->shots ) {
						$found = '<li><span class="small muted">' . esc_html( self::who( $x->user_id ) ) . ' · <span class="tabnum">' . esc_html( self::when( $x->created_at ) ) . '</span>' . ( 'note' === $x->status ? '' : ' · ' . esc_html( self::label( $x->status ) ) ) . '</span>' . ( '' !== (string) $x->note ? '<span class="qa-note-text">' . nl2br( esc_html( $x->note ) ) . '</span>' : '' ) . self::shots_html( $x ) . '</li>' . $found;
					}
				}
				if ( $found ) {
					$cards .= '<ul class="qa-notes">' . $found . '</ul>';
				}
				$cards .= '<div class="qa-add"><label class="sr-only screen-reader-text" for="qa-note-' . esc_attr( $id ) . '">' . esc_html__( 'Note', 'bdaci-portal-access' ) . '</label><textarea id="qa-note-' . esc_attr( $id ) . '" name="note" rows="2" placeholder="' . esc_attr__( 'Add a note: what happened, page address, device and browser. Paste a screenshot here with Ctrl+V / ⌘+V.', 'bdaci-portal-access' ) . '"></textarea>';
				$cards .= '<div class="qa-row qa-files"><label class="btn btn-sm qa-file"><input type="file" name="shots[]" multiple accept="image/png,image/jpeg,image/gif,image/webp" class="sr-only">' . esc_html__( 'Add screenshots', 'bdaci-portal-access' ) . '</label><span class="qa-picked small muted">' . esc_html( sprintf( /* translators: %s: size limit */ __( 'PNG, JPG, GIF or WebP, up to %s each. You can also drop them on this card.', 'bdaci-portal-access' ), size_format( self::shot_limit() ) ) ) . '</span><button class="btn btn-sm" name="status" value="note">' . esc_html__( 'Save note only', 'bdaci-portal-access' ) . '</button></div><p class="small muted qa-hint">' . esc_html__( 'Pressing Passed, Failed or Skipped also saves the note and screenshots with your result.', 'bdaci-portal-access' ) . '</p></div></form>';
				if ( ! empty( $hist[ $id ] ) ) {
					$cards .= '<details class="qa-hist"><summary>' . esc_html( sprintf( /* translators: %d: number of checks */ _n( 'History this round (%d check)', 'History this round (%d checks)', count( $hist[ $id ] ), 'bdaci-portal-access' ), count( $hist[ $id ] ) ) ) . '</summary><ul>';
					foreach ( array_reverse( $hist[ $id ] ) as $x ) {
						$cards .= '<li><span class="tabnum">' . esc_html( self::when( $x->created_at ) ) . '</span> · <b>' . esc_html( self::who( $x->user_id ) ) . '</b> · ' . esc_html( self::label( $x->status ) ) . ( $x->note ? ' · <span class="muted">' . esc_html( $x->note ) . '</span>' : '' ) . self::shots_html( $x ) . '</li>';
					}
					$cards .= '</ul></details>';
				}
				$cards .= '</article>';
			}
			if ( $cards ) {
				$h .= '<section class="qa-group" id="qa-group-' . esc_attr( $g ) . '"><div class="panel-h"><h2>' . esc_html( $grp[0] ) . '</h2></div>' . ( $grp[1] ? '<p class="small muted">' . esc_html( $grp[1] ) . '</p>' : '' ) . $cards . '</section>';
			}
		}
		if ( ! $shown ) {
			$h .= '<p class="muted" style="text-align:center;padding:24px">' . esc_html__( 'Nothing matches this filter.', 'bdaci-portal-access' ) . '</p>';
		}
		return $h;
	}

	/**
	 * Every check in the round, newest first, filterable by group, tester and result.
	 *
	 * @param array $recs   Records.
	 * @param int   $round  Round.
	 * @param array $groups Groups.
	 * @return string
	 */
	private static function record_view( array $recs, $round, array $groups ) {
		$idx    = self::index();
		$g      = self::arg( 'group' );
		$res    = self::arg( 'result' );
		$tester = isset( $_GET['tester'] ) ? absint( $_GET['tester'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$people = array();
		foreach ( $recs as $r ) {
			$people[ (int) $r->user_id ] = self::who( $r->user_id );
		}
		$rows = array_reverse(
			array_filter(
				$recs,
				static function ( $r ) use ( $g, $res, $tester, $idx ) {
					return ( ! $g || ( $idx[ $r->scenario ] ?? '' ) === $g ) && ( ! $res || $r->status === $res ) && ( ! $tester || (int) $r->user_id === $tester );
				}
			)
		);
		$h  = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'QA record', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html__( 'Every check in this round, newest first, including changes and cleared marks.', 'bdaci-portal-access' ) . '</span></div>';
		$h .= '<form method="get" class="qa-row" action="' . esc_url( BDACI_PA_Admin_Portal::$front ? BDACI_PA_Settings::portal_home_url() : admin_url( 'admin.php' ) ) . '">' . ( BDACI_PA_Admin_Portal::$front ? '<input type="hidden" name="manage" value="qa">' : '<input type="hidden" name="page" value="bdaci-portal-qa">' ) . '<input type="hidden" name="view" value="record"><input type="hidden" name="round" value="' . (int) $round . '">';
		$h .= '<label>' . esc_html__( 'Group', 'bdaci-portal-access' ) . ' <select name="group"><option value="">' . esc_html__( 'All', 'bdaci-portal-access' ) . '</option>';
		foreach ( $groups as $k => $grp ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $k, $g, false ) . '>' . esc_html( $grp[0] ) . '</option>';
		}
		$h .= '</select></label> <label>' . esc_html__( 'Tester', 'bdaci-portal-access' ) . ' <select name="tester"><option value="0">' . esc_html__( 'Everyone', 'bdaci-portal-access' ) . '</option>';
		foreach ( $people as $id => $name ) {
			$h .= '<option value="' . (int) $id . '"' . selected( $id, $tester, false ) . '>' . esc_html( $name ) . '</option>';
		}
		$h .= '</select></label> <label>' . esc_html__( 'Result', 'bdaci-portal-access' ) . ' <select name="result"><option value="">' . esc_html__( 'Any', 'bdaci-portal-access' ) . '</option>';
		foreach ( array( 'pass', 'fail', 'skip', 'note', 'clear' ) as $s ) {
			$h .= '<option value="' . esc_attr( $s ) . '"' . selected( $s, $res, false ) . '>' . esc_html( self::label( $s ) ) . '</option>';
		}
		$h .= '</select></label> <button class="btn btn-sm">' . esc_html__( 'Filter', 'bdaci-portal-access' ) . '</button></form>';
		if ( ! $rows ) {
			return $h . '<p class="muted">' . esc_html__( 'No checks yet.', 'bdaci-portal-access' ) . '</p></section>';
		}
		$all = array();
		foreach ( $groups as $grp ) {
			$all += $grp[2];
		}
		$h .= '<div class="tablewrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Tester', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Scenario', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Result', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Note and screenshots', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$pill = array(
				'pass'  => 'pill-ok',
				'fail'  => 'pill-err',
				'skip'  => 'pill-warn',
				'clear' => 'pill-muted',
				'note'  => '',
			);
			$h   .= '<tr><td class="tabnum" style="white-space:nowrap">' . esc_html( self::when( $r->created_at ) ) . '</td><td>' . esc_html( self::who( $r->user_id ) ) . '</td><td><span class="qa-id tabnum">' . esc_html( $r->scenario ) . '</span> ' . esc_html( $all[ $r->scenario ][0] ?? '' ) . '</td><td><span class="pill ' . esc_attr( $pill[ $r->status ] ?? '' ) . '">' . esc_html( self::label( $r->status ) ) . '</span></td><td style="white-space:normal">' . nl2br( esc_html( (string) $r->note ) ) . self::shots_html( $r ) . '</td></tr>';
		}
		return $h . '</tbody></table></div></section>';
	}

	/**
	 * Choose who can see the page.
	 *
	 * @return string
	 */
	private static function access_view() {
		$allowed = self::allowed_users();
		$labels  = BDACI_PA_Roles::labels();
		$h       = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Who can see this page', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html__( 'Portal Admins always can. Tick the other portal members who will test. They see QA Testing in their side menu.', 'bdaci-portal-access' ) . '</span></div>';
		$h      .= self::form( 'access' ) . '<div class="tablewrap"><table class="widefat striped"><thead><tr><th style="width:60px">' . esc_html__( 'Access', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Name', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Email', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Roles', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>';
		$any     = false;
		foreach ( BDACI_PA_Members::all() as $m ) {
			if ( 'active' !== $m->status || ! $m->user_id ) {
				continue;
			}
			$any   = true;
			$admin = user_can( (int) $m->user_id, 'bdaci_manage_portal' );
			$roles = array();
			foreach ( array_filter( explode( ',', (string) $m->roles ) ) as $r ) {
				$roles[] = $labels[ $r ] ?? $r;
			}
			$h .= '<tr><td><input type="checkbox" name="users[]" value="' . (int) $m->user_id . '" aria-label="' . esc_attr( $m->display_name ) . '"' . checked( $admin || in_array( (int) $m->user_id, $allowed, true ), true, false ) . disabled( $admin, true, false ) . '></td><td>' . esc_html( $m->display_name ? $m->display_name : self::who( $m->user_id ) ) . ( $admin ? ' <span class="pill pill-ok">' . esc_html__( 'Portal Admin', 'bdaci-portal-access' ) . '</span>' : '' ) . '</td><td>' . esc_html( $m->email ) . '</td><td>' . esc_html( implode( ', ', $roles ) ) . '</td></tr>';
		}
		if ( ! $any ) {
			$h .= '<tr><td colspan="4" class="muted">' . esc_html__( 'No active portal members with accounts yet. Add people under Authorized Users first.', 'bdaci-portal-access' ) . '</td></tr>';
		}
		return $h . '</tbody></table></div><p><button class="btn btn-primary">' . esc_html__( 'Save who can see this page', 'bdaci-portal-access' ) . '</button></p></form></section>';
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	/**
	 * Back to the page with a notice.
	 *
	 * @param array  $args   Query args.
	 * @param string $notice Notice.
	 * @param string $type   success|error.
	 * @param string $frag   Fragment.
	 */
	private static function back( array $args, $notice, $type = 'success', $frag = '' ) {
		if ( $notice ) {
			set_transient( 'bdaci_pa_notice_' . get_current_user_id(), array( $notice, $type ), 60 );
		}
		BDACI_PA_Admin_Portal::$front = BDACI_PA_Admin_Portal::from_front();
		$url                          = self::link( $args );
		BDACI_PA_Admin_Portal::$front = false;
		wp_safe_redirect( $url . $frag );
		exit;
	}

	/**
	 * Save a mark.
	 */
	public static function handle_mark() {
		// A post bigger than the server allows arrives empty: say so instead of a blank "link expired" page.
		if ( empty( $_POST ) && ! empty( $_SERVER['CONTENT_LENGTH'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			/* translators: %s: size limit */
			set_transient( 'bdaci_pa_notice_' . get_current_user_id(), array( sprintf( __( 'Nothing was saved: the screenshots were too large to send together. Add them in smaller batches (each up to %s).', 'bdaci-portal-access' ), size_format( self::shot_limit() ) ), 'error' ), 60 );
			$ref = wp_get_referer();
			wp_safe_redirect( $ref ? $ref : self::link( array() ) );
			exit;
		}
		check_admin_referer( 'bdaci_pa_qa_mark' );
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You do not have access to QA Testing.', 'bdaci-portal-access' ), 403 );
		}
		$id     = isset( $_POST['scenario'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['scenario'] ) ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$round  = isset( $_POST['round'] ) ? absint( $_POST['round'] ) : 0;
		$note   = isset( $_POST['note'] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ), 0, 2000 ) : '';
		$args   = array(
			'group' => isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '',
			'show'  => isset( $_POST['show'] ) ? sanitize_key( wp_unslash( $_POST['show'] ) ) : '',
		);
		$rounds = self::rounds();
		if ( ! isset( self::index()[ $id ] ) || ! in_array( $status, array( 'pass', 'fail', 'skip', 'clear', 'note' ), true ) || ! isset( $rounds[ $round ] ) ) {
			self::back( $args, __( 'That check could not be saved. Reload the page and try again.', 'bdaci-portal-access' ), 'error' );
		}
		if ( $round !== self::current_round() ) {
			$args['round'] = $round;
		}
		$errors = array();
		$shots  = 'clear' === $status ? array() : self::save_shots( $errors );
		if ( 'note' === $status && '' === $note && ! $shots ) {
			self::back( $args, $errors ? implode( ' ', $errors ) : __( 'Type a note or add a screenshot first.', 'bdaci-portal-access' ), 'error', '#qa-' . $id );
		}
		self::add( $round, $id, $status, 'clear' === $status ? '' : $note, $shots );
		if ( 'note' === $status ) {
			/* translators: %s: scenario id */
			$msg = sprintf( __( 'Note saved on %s.', 'bdaci-portal-access' ), $id );
		} else {
			/* translators: 1: scenario id, 2: result */
			$msg = sprintf( __( '%1$s marked %2$s.', 'bdaci-portal-access' ), $id, self::label( $status ) );
		}
		if ( $shots ) {
			/* translators: %d: number of screenshots */
			$msg .= ' ' . sprintf( _n( '%d screenshot added.', '%d screenshots added.', count( $shots ), 'bdaci-portal-access' ), count( $shots ) );
		}
		if ( $errors ) {
			self::back( $args, $msg . ' ' . implode( ' ', $errors ), 'error', '#qa-' . $id );
		}
		self::back( $args, $msg, 'success', '#qa-' . $id );
	}

	/**
	 * Start a round.
	 */
	public static function handle_round() {
		check_admin_referer( 'bdaci_pa_qa_round' );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Only Portal Admins can start a round.', 'bdaci-portal-access' ), 403 );
		}
		$name = isset( $_POST['name'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 60 ) : '';
		self::start_round( $name );
		/* translators: %s: round name */
		self::back( array(), sprintf( __( 'Started %s. Everyone is now testing this round.', 'bdaci-portal-access' ), $name ? $name : __( 'a new round', 'bdaci-portal-access' ) ) );
	}

	/**
	 * Save who can see the page.
	 */
	public static function handle_access() {
		check_admin_referer( 'bdaci_pa_qa_access' );
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Only Portal Admins can change this.', 'bdaci-portal-access' ), 403 );
		}
		$ids = isset( $_POST['users'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['users'] ) ) ) ) ) : array();
		update_option( self::OPT_USERS, $ids, false );
		BDACI_PA_Log::add( 'qa_access', '', 0, 'QA Testing access: ' . implode( ',', $ids ) );
		/* translators: %d: number of people */
		self::back( array( 'view' => 'access' ), sprintf( _n( 'Saved. %d member besides Portal Admins can see QA Testing.', 'Saved. %d members besides Portal Admins can see QA Testing.', count( $ids ), 'bdaci-portal-access' ), count( $ids ) ) );
	}

	/**
	 * CSV of a round: the latest result per scenario, then every check.
	 */
	public static function handle_csv() {
		check_admin_referer( 'bdaci_pa_qa_csv' );
		if ( ! self::can_view() ) {
			wp_die( esc_html__( 'You do not have access to QA Testing.', 'bdaci-portal-access' ), 403 );
		}
		$round  = isset( $_GET['round'] ) ? absint( $_GET['round'] ) : self::current_round();
		$rounds = self::rounds();
		$name   = $rounds[ $round ]['name'] ?? '';
		$recs   = self::records( $round );
		$latest = self::latest( $recs );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="bdaci-qa-' . sanitize_file_name( strtolower( $name ? $name : 'round' ) ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Excel needs the BOM for UTF-8.
		self::csv_row( $out, array( 'Round', 'Group', 'ID', 'Scenario', 'Where to find it', 'Result', 'Checked by', 'Checked at', 'Note' ) );
		$where = self::where();
		foreach ( self::groups() as $grp ) {
			foreach ( $grp[2] as $id => $c ) {
				$r = $latest[ $id ] ?? null;
				self::csv_row( $out, array( $name, $grp[0], $id, $c[0], isset( $where[ $id ] ) ? implode( ' > ', $where[ $id ][0] ) : '', $r ? self::label( $r->status ) : 'Not tested', $r ? self::who( $r->user_id ) : '', $r ? self::when( $r->created_at ) : '', $r ? $r->note : '' ) );
			}
		}
		self::csv_row( $out, array() );
		self::csv_row( $out, array( 'Every check (QA record)' ) );
		self::csv_row( $out, array( 'When', 'Tester', 'ID', 'Result', 'Note', 'Screenshots' ) );
		foreach ( $recs as $r ) {
			$links = array();
			foreach ( array_keys( self::shots_of( $r ) ) as $n ) {
				$links[] = self::shot_url( $r->id, $n );
			}
			self::csv_row( $out, array( self::when( $r->created_at ), self::who( $r->user_id ), $r->scenario, self::label( $r->status ), $r->note, implode( ' ', $links ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
