<?php
/**
 * Test data for staging: add sample people, meetings, documents and activity with one click,
 * and remove exactly what was added (plus, optionally, all portal activity) to get back to a clean launch state.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sample data tool (BDACI Portal → Test data). Site administrators only.
 */
class BDACI_PA_Demo {

	const OPTION   = 'bdaci_pa_demo';
	const PASSWORD = 'bdaci_pa_demo_password';
	const META     = '_bdaci_pa_demo';

	/**
	 * Hooks.
	 */
	public static function init() {
		foreach ( array( 'demo_add', 'demo_clear', 'demo_launch' ) as $a ) {
			add_action( 'admin_post_bdaci_pa_' . $a, array( __CLASS__, 'handle_' . str_replace( 'demo_', '', $a ) ) );
		}
	}

	/**
	 * What the tool has added so far.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		foreach ( array( 'users', 'meetings', 'docs', 'requests' ) as $k ) {
			$s[ $k ] = isset( $s[ $k ] ) ? array_values( array_map( 'intval', (array) $s[ $k ] ) ) : array();
		}
		$s['batch'] = isset( $s['batch'] ) ? (int) $s['batch'] : 0;
		return $s;
	}

	/**
	 * Save state.
	 *
	 * @param array $s State.
	 */
	private static function save_state( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	/**
	 * Shared password for test people (made once per site, shown on the screen).
	 *
	 * @return string
	 */
	public static function password() {
		$p = (string) get_option( self::PASSWORD, '' );
		if ( '' === $p ) {
			$p = 'Test-' . wp_generate_password( 10, false ) . '-2026!';
			update_option( self::PASSWORD, $p, false );
		}
		return $p;
	}

	/**
	 * Email for a test person: the admin's own mailbox with a "+tag", so sign-in codes reach the admin.
	 *
	 * @param string $slug Tag.
	 * @return string
	 */
	public static function email_for( $slug ) {
		$base = wp_get_current_user()->user_email;
		if ( ! is_email( $base ) ) {
			$base = get_option( 'admin_email' );
		}
		list( $local, $domain ) = array_pad( explode( '@', $base, 2 ), 2, 'example.org' );
		$local = preg_replace( '/\+.*$/', '', $local );
		return strtolower( $local . '+bdaci-test-' . $slug . '@' . $domain );
	}

	/**
	 * Sample people. [first, last, roles, board position, committee position].
	 *
	 * @return array
	 */
	private static function people() {
		return array(
			array( 'Margaret', 'Hughes', array( 'board' ), 'President', '' ),
			array( 'Daniel', 'Okafor', array( 'board' ), 'Vice-President', '' ),
			array( 'Priya', 'Raman', array( 'board', 'finance' ), 'Treasurer', 'Chair' ),
			array( 'Louise', 'Tremblay', array( 'board' ), 'Secretary', '' ),
			array( 'Samuel', 'Chen', array( 'committee' ), '', 'Chair' ),
			array( 'Aisha', 'Mahmoud', array( 'committee' ), '', 'Member' ),
			array( 'Robert', 'MacLeod', array( 'finance' ), '', 'Member' ),
			array( 'Hannah', 'Kowalski', array( 'board', 'committee' ), 'Director', 'Member' ),
			array( 'Marcus', 'Bell', array( 'board' ), 'Director', '' ),
			array( 'Elena', 'Garcia', array( 'committee' ), '', 'Member' ),
			array( 'Thomas', 'Wright', array( 'finance' ), '', 'Member' ),
			array( 'Grace', 'Nakamura', array( 'board' ), 'Director', '' ),
		);
	}

	/**
	 * Sample documents. [title, sections, who, days ago].
	 *
	 * @return array
	 */
	private static function documents() {
		return array(
			array( 'Board Meeting Agenda', array( 'agenda' ), array( 'board' ), 3 ),
			array( 'Board Meeting Package', array( 'package' ), array( 'board' ), 3 ),
			array( 'Board Meeting Minutes', array( 'minutes' ), array( 'board' ), 40 ),
			array( 'Board Meeting Minutes', array( 'minutes' ), array( 'board' ), 400 ),
			array( 'BDACI Bylaws', array( 'bylaws', 'orientation' ), array( 'board' ), 200 ),
			array( 'Governance Policy Manual', array( 'policies', 'orientation' ), array( 'board' ), 150 ),
			array( 'Conflict of Interest Policy', array( 'policies', 'qa' ), array( 'board' ), 120 ),
			array( 'Quarterly Financial Statements', array( 'reports', 'fincom' ), array( 'board', 'finance' ), 20 ),
			array( 'Annual Budget', array( 'reports', 'fincom' ), array( 'board', 'finance' ), 60 ),
			array( 'Education Committee Agenda', array( 'edu', 'agenda' ), array( 'committee' ), 5 ),
			array( 'Education Committee Minutes', array( 'edu', 'minutes' ), array( 'committee' ), 35 ),
			array( 'Finance Committee Agenda', array( 'fincom', 'agenda' ), array( 'finance' ), 6 ),
			array( 'Strategic Plan Draft', array( 'strategic' ), array( 'board' ), 10 ),
			array( 'Quality Assurance Checklist', array( 'qa' ), array( 'board' ), 15 ),
		);
	}

	/**
	 * A small one-page PDF with a title line.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function pdf( $title ) {
		$t       = preg_replace( '/[^\x20-\x7E]/', '', $title );
		$t       = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $t );
		$text    = "BT /F1 22 Tf 72 720 Td ({$t}) Tj ET\nBT /F1 12 Tf 72 690 Td (TEST DOCUMENT - sample data added on the staging site.) Tj ET";
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $text ) . " >>\nstream\n" . $text . "\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);
		$out     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $o ) {
			$offsets[] = strlen( $out );
			$out      .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $out );
		$out .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$out .= sprintf( "%010d 00000 n \n", $off );
		}
		$out .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
		return $out;
	}

	/**
	 * Add one batch of sample data.
	 *
	 * @return array Counts.
	 */
	public static function add_batch() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$s      = self::state();
		$batch  = $s['batch'] + 1;
		$pw     = self::password();
		$counts = array( 'people' => 0, 'meetings' => 0, 'docs' => 0 );

		// People: 6 per press, cycling through the list.
		$all     = self::people();
		$new_ids = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$n    = ( ( $batch - 1 ) * 6 + $i );
			$p    = $all[ $n % count( $all ) ];
			$lap  = intdiv( $n, count( $all ) );
			$last = $p[1] . ( $lap ? ' ' . ( $lap + 1 ) : '' );
			$slug = sanitize_key( strtolower( $p[0] . '-' . $p[1] . ( $lap ? '-' . ( $lap + 1 ) : '' ) ) );
			$mail = self::email_for( $slug );
			if ( get_user_by( 'email', $mail ) || BDACI_PA_Members::get_by_email( $mail ) ) {
				continue;
			}
			$uid = wp_insert_user(
				array(
					'user_login'   => 'bdaci-test-' . $slug,
					'user_email'   => $mail,
					'user_pass'    => $pw,
					'display_name' => $p[0] . ' ' . $last,
					'first_name'   => $p[0],
					'last_name'    => $last,
					'role'         => '',
				)
			);
			if ( is_wp_error( $uid ) ) {
				continue;
			}
			update_user_meta( $uid, self::META, 1 );
			$mid = BDACI_PA_Members::add( $mail, $p[0] . ' ' . $last, $p[2] );
			if ( ! is_wp_error( $mid ) ) {
				BDACI_PA_Members::update( $mid, array( 'user_id' => $uid ) );
				BDACI_PA_Members::apply_to_account( $mid );
			}
			$meta = array(
				'bdaci_position'    => $p[3],
				'bdaci_term'        => $p[3] ? ( 2024 + $n % 3 ) . '–' . ( 2027 + $n % 3 ) : '',
				'bdaci_affiliation' => array( 'Community member', 'Parent representative', 'Local business', 'Retired educator' )[ $n % 4 ],
				'bdaci_phone'       => '(613) 555-' . str_pad( (string) ( 100 + $n ), 4, '0', STR_PAD_LEFT ),
				'bdaci_show_phone'  => $n % 2 ? '1' : '',
				'bdaci_avatar'      => 'preset:p' . ( 1 + ( $n * 7 ) % 30 ),
			);
			if ( in_array( 'committee', $p[2], true ) && $p[4] ) {
				$meta['bdaci_edu_position'] = $p[4];
			}
			if ( in_array( 'finance', $p[2], true ) && $p[4] ) {
				$meta['bdaci_fin_position'] = $p[4];
			}
			foreach ( $meta as $k => $v ) {
				if ( '' !== $v ) {
					update_user_meta( $uid, $k, $v );
				}
			}
			$s['users'][] = (int) $uid;
			$new_ids[]    = array( (int) $uid, $p[2] );
			++$counts['people'];
		}

		// Documents: one set per press.
		$docs = array();
		$tmp  = get_temp_dir();
		foreach ( self::documents() as $i => $d ) {
			$title = $d[0] . ( $batch > 1 ? ' (set ' . $batch . ')' : '' );
			$date  = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( $d[3] + ( $batch - 1 ) * 30 ) . ' days' ) );
			$file  = wp_tempnam( 'bdaci-test-' . $i, $tmp );
			file_put_contents( $file, self::pdf( $title . ' – ' . date_i18n( 'F j, Y', strtotime( $date ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$id = BDACI_PA_Documents::sideload( $file, sanitize_file_name( strtolower( str_replace( ' ', '-', $title ) ) ) . '.pdf', $title, BDACI_PA_Sections::who_to_level( $d[2] ) );
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
			if ( is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, self::META, 1 );
			BDACI_PA_Sections::set_doc_sections( $id, $d[1] );
			update_post_meta( $id, BDACI_PA_Sections::META_DATE, $date );
			$s['docs'][] = (int) $id;
			$docs[ $i ]  = (int) $id;
			++$counts['docs'];
		}

		// Meetings: upcoming Board / Education / Finance / Other plus a past Board meeting, a few weeks apart per press.
		$base     = strtotime( current_time( 'Y-m-d' ) );
		$shift    = ( $batch - 1 ) * 28;
		$meetings = array(
			array( 'board', 'Board of Directors Meeting', 9 + $shift, '18:00', '20:00', 'BDACI Boardroom, Brockville', true, $docs[0] ?? 0, $docs[1] ?? 0, 0 ),
			array( 'edu', 'Education Committee Meeting', 14 + $shift, '17:00', '18:00', '', true, $docs[9] ?? 0, 0, 0 ),
			array( 'fin', 'Finance Committee Meeting', 21 + $shift, '12:00', '13:00', 'BDACI Office', true, $docs[11] ?? 0, 0, 0 ),
			array( 'other', 'Annual General Meeting', 45 + $shift, '10:00', '12:00', 'Community Hall', false, 0, 0, 0 ),
			array( 'board', 'Board of Directors Meeting', -30 - $shift, '18:00', '20:00', 'BDACI Boardroom, Brockville', false, 0, 0, $docs[2] ?? 0 ),
		);
		$mids     = array();
		foreach ( $meetings as $m ) {
			$id = BDACI_PA_Meetings::save(
				array(
					'grp'        => $m[0],
					'title'      => $m[1],
					'date'       => gmdate( 'Y-m-d', $base + $m[2] * DAY_IN_SECONDS ),
					'start'      => $m[3],
					'end'        => $m[4],
					'location'   => $m[5],
					'video_url'  => $m[6] ? 'https://teams.microsoft.com/l/meetup-join/test-meeting' : '',
					'agenda_id'  => $m[7],
					'package_id' => $m[8],
					'minutes_id' => $m[9],
					'notes'      => __( 'Test meeting added from BDACI Portal → Test data.', 'bdaci-portal-access' ),
				)
			);
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			$s['meetings'][] = (int) $id;
			$mids[]          = array( (int) $id, $m[0] );
			++$counts['meetings'];
		}

		// RSVPs from the new people for the upcoming meetings they are invited to.
		$answers = array( 'attending', 'attending', 'regrets', 'tentative' );
		foreach ( $mids as $k => $m ) {
			if ( in_array( $m[1], array( 'other' ), true ) ) {
				continue;
			}
			$meeting = BDACI_PA_Meetings::get( $m[0] );
			if ( ! $meeting || BDACI_PA_Meetings::is_past( $meeting ) ) {
				continue;
			}
			foreach ( $new_ids as $j => $u ) {
				if ( ( $j + $k ) % 3 === 2 || ! BDACI_PA_Meetings::visible( $meeting, $u[0] ) ) {
					continue; // Leave some people without an answer.
				}
				$wpdb->insert( BDACI_PA_Meetings::rsvp_table(), array( 'meeting_id' => $m[0], 'user_id' => $u[0], 'response' => $answers[ ( $j + $k ) % 4 ], 'updated_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		// Sign-off activity: send to the new board members; some open the documents, one signs.
		$board = array_values( array_filter( $new_ids, static function ( $u ) { return in_array( 'board', $u[1], true ); } ) );
		foreach ( array( 'orientation', 'qa' ) as $pack ) {
			foreach ( $board as $j => $u ) {
				if ( ! BDACI_PA_Signoffs::is_recipient( $pack, $u[0] ) ) {
					continue;
				}
				BDACI_PA_Signoffs::add( $pack, $u[0], 'sent' );
				if ( 0 === $j % 2 ) {
					foreach ( BDACI_PA_Signoffs::docs( $pack, $u[0] ) as $d ) {
						BDACI_PA_Signoffs::add( $pack, $u[0], 'opened', $d->ID );
					}
					if ( 0 === $j ) {
						BDACI_PA_Signoffs::add( $pack, $u[0], 'signed' );
					}
				} elseif ( 1 === $j ) {
					BDACI_PA_Signoffs::add( $pack, $u[0], 'reminded' );
				}
			}
		}

		// One access request: a new board member who isn't on the Education Committee asks for it.
		foreach ( $new_ids as $u ) {
			if ( in_array( 'board', $u[1], true ) && ! in_array( 'committee', $u[1], true ) && ! BDACI_PA_Requests::pending_for( $u[0], 'education' ) ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					BDACI_PA_Requests::table(),
					array(
						'user_id'    => $u[0],
						'area'       => 'education',
						'reason'     => __( 'I would like to follow the committee\'s work on inclusive education. (test request)', 'bdaci-portal-access' ),
						'status'     => 'pending',
						'created_at' => current_time( 'mysql' ),
					)
				);
				$s['requests'][] = (int) $wpdb->insert_id;
				break;
			}
		}

		$s['batch'] = $batch;
		self::save_state( $s );
		BDACI_PA_Access::flush_cache();
		BDACI_PA_Log::add( 'test_data_added', '', get_current_user_id(), sprintf( '%d people, %d meetings, %d documents', $counts['people'], $counts['meetings'], $counts['docs'] ) );
		return $counts;
	}

	/**
	 * Remove everything the tool added (and only that).
	 *
	 * @return array Counts.
	 */
	public static function clear() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$s = self::state();

		// Also find tagged items, in case the saved list was lost.
		$users = array_unique( array_merge( $s['users'], array_map( 'intval', get_users( array( 'meta_key' => self::META, 'fields' => 'ID' ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$docs  = array_unique(
			array_merge(
				$s['docs'],
				array_map(
					'intval',
					get_posts(
						array(
							'post_type'        => 'attachment',
							'post_status'      => 'any',
							'posts_per_page'   => -1,
							'fields'           => 'ids',
							'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'suppress_filters' => true,
							'bdaci_pa_all'     => true,
						)
					)
				)
			)
		);
		$counts = array( 'people' => 0, 'meetings' => 0, 'docs' => 0 );

		foreach ( $s['meetings'] as $id ) {
			if ( BDACI_PA_Meetings::get( $id ) ) {
				$wpdb->delete( BDACI_PA_Meetings::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( BDACI_PA_Meetings::rsvp_table(), array( 'meeting_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$counts['meetings'];
			}
		}
		foreach ( $users as $uid ) {
			$user = get_userdata( $uid );
			if ( ! $user || ! get_user_meta( $uid, self::META, true ) ) {
				continue; // Never delete a real account.
			}
			$row = BDACI_PA_Members::get_by_email( $user->user_email );
			if ( $row ) {
				BDACI_PA_Members::delete( $row->id );
			}
			$wpdb->delete( BDACI_PA_Meetings::rsvp_table(), array( 'user_id' => $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( BDACI_PA_Requests::table(), array( 'user_id' => $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( BDACI_PA_Signoffs::table(), array( 'user_id' => $uid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$photo = (string) get_user_meta( $uid, 'bdaci_avatar', true );
			if ( 0 === strpos( $photo, 'upload:' ) ) {
				wp_delete_attachment( (int) substr( $photo, 7 ), true );
			}
			wp_delete_user( $uid, get_current_user_id() );
			++$counts['people'];
		}
		foreach ( $docs as $id ) {
			if ( ! get_post_meta( $id, self::META, true ) ) {
				continue;
			}
			$wpdb->delete( BDACI_PA_Signoffs::table(), array( 'doc_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( array( 'agenda_id', 'package_id', 'minutes_id' ) as $col ) {
				$wpdb->update( BDACI_PA_Meetings::table(), array( $col => 0 ), array( $col => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( wp_delete_attachment( $id, true ) ) {
				++$counts['docs'];
			}
		}
		delete_option( self::OPTION );
		BDACI_PA_Access::flush_cache();
		BDACI_PA_Log::add( 'test_data_removed', '', get_current_user_id(), sprintf( '%d people, %d meetings, %d documents', $counts['people'], $counts['meetings'], $counts['docs'] ) );
		return $counts;
	}

	/**
	 * Launch reset: clear all portal activity (RSVPs, sign-off history, access requests, activity log),
	 * keeping people, meetings, documents, pages and settings.
	 */
	public static function launch_reset() {
		global $wpdb;
		foreach ( array( BDACI_PA_Meetings::rsvp_table(), BDACI_PA_Signoffs::table(), BDACI_PA_Requests::table(), BDACI_PA_Log::table() ) as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		BDACI_PA_Log::add( 'launch_reset', '', get_current_user_id(), 'RSVPs, sign-off history, access requests and the activity log were cleared' );
	}

	/**
	 * Guard.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site administrators can use the test-data tool.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Back to the screen with a notice.
	 *
	 * @param string $msg  Message.
	 * @param string $type success|error.
	 */
	private static function back( $msg, $type = 'success' ) {
		set_transient( 'bdaci_pa_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
		$url = admin_url( 'admin.php?page=bdaci-portal-test-data' );
		wp_safe_redirect( BDACI_PA_Admin_Portal::from_front() ? BDACI_PA_Admin_Portal::to_front( $url ) : $url );
		exit;
	}

	/**
	 * "Add sample data".
	 */
	public static function handle_add() {
		self::guard( 'bdaci_pa_demo_add' );
		if ( count( self::state()['users'] ) >= 120 ) {
			self::back( __( 'That\'s a lot of test data already. Remove it first, then add more.', 'bdaci-portal-access' ), 'error' );
		}
		$c = self::add_batch();
		/* translators: 1: people, 2: meetings, 3: documents */
		self::back( sprintf( __( 'Added %1$d test people, %2$d meetings and %3$d documents, with RSVPs, sign-off activity and an access request.', 'bdaci-portal-access' ), $c['people'], $c['meetings'], $c['docs'] ) );
	}

	/**
	 * "Remove all test data".
	 */
	public static function handle_clear() {
		self::guard( 'bdaci_pa_demo_clear' );
		$c = self::clear();
		/* translators: 1: people, 2: meetings, 3: documents */
		self::back( sprintf( __( 'Removed %1$d test people, %2$d meetings and %3$d documents. Everything you added yourself was kept.', 'bdaci-portal-access' ), $c['people'], $c['meetings'], $c['docs'] ) );
	}

	/**
	 * "Clear activity for launch".
	 */
	public static function handle_launch() {
		self::guard( 'bdaci_pa_demo_launch' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above.
		if ( 'RESET' !== ( isset( $_POST['confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) ) : '' ) ) {
			self::back( __( 'Type RESET in the box to confirm.', 'bdaci-portal-access' ), 'error' );
		}
		$also = ! empty( $_POST['with_test_data'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $also ) {
			self::clear();
		}
		self::launch_reset();
		self::back( $also ? __( 'Test data removed and all activity cleared. The portal is at its launch state.', 'bdaci-portal-access' ) : __( 'All RSVPs, sign-off history, access requests and the activity log were cleared.', 'bdaci-portal-access' ) );
	}

	/**
	 * Screen.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$s     = self::state();
		$users = array_filter( array_map( 'get_userdata', $s['users'] ) );
		$form  = static function ( $action, $inner, $attrs = '' ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attrs . '><input type="hidden" name="action" value="bdaci_pa_' . esc_attr( $action ) . '">' . wp_nonce_field( 'bdaci_pa_' . $action, '_wpnonce', true, false ) . BDACI_PA_Admin_Portal::front_field() . $inner . '</form>';
		};
		BDACI_PA_Admin_Portal::open_page( __( 'Test data', 'bdaci-portal-access' ), __( 'Sample people, meetings and documents for trying the portal on a staging site.', 'bdaci-portal-access' ) );
		BDACI_PA_Admin_Portal::notice();
		echo '<div class="' . ( BDACI_PA_Admin_Portal::$front ? 'notice-bar err' : 'notice notice-warning inline' ) . '"><p style="margin:0"><strong>' . esc_html__( 'For staging and training only.', 'bdaci-portal-access' ) . '</strong> ' . esc_html__( 'Sample data shows up in the portal for everyone, exactly like real data. Remove it before launch.', 'bdaci-portal-access' ) . '</p></div>';
		echo '<div class="bp bp-admin">';

		// Add.
		$h  = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Add sample data', 'bdaci-portal-access' ) . '</h2>';
		$h .= '<span class="pill ' . ( $s['batch'] ? 'pill-warn' : 'pill-ok' ) . '">' . esc_html( $s['batch'] ? sprintf( /* translators: %d: number of presses */ _n( 'Added %d time', 'Added %d times', $s['batch'], 'bdaci-portal-access' ), $s['batch'] ) : __( 'None added', 'bdaci-portal-access' ) ) . '</span></div>';
		$h .= '<p>' . esc_html__( 'Each press adds:', 'bdaci-portal-access' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		$h .= '<li>' . esc_html__( '6 people (Board, Education Committee and Finance Committee) with positions, terms, pictures and phone numbers for the directories', 'bdaci-portal-access' ) . '</li>';
		$h .= '<li>' . esc_html__( '14 private PDF documents filed in the sections (agendas, packages, minutes, bylaws, policies, financial reports, orientation, QA, committees, a hidden Strategic Plan draft)', 'bdaci-portal-access' ) . '</li>';
		$h .= '<li>' . esc_html__( '5 meetings (Board, Education, Finance, an AGM and a past Board meeting with minutes), with RSVPs from the new people', 'bdaci-portal-access' ) . '</li>';
		$h .= '<li>' . esc_html__( 'Sign-off activity (sent, reminded, opened, one signed) and one access request to approve', 'bdaci-portal-access' ) . '</li></ul>';
		$h .= '<p class="small muted">' . esc_html__( 'Press again for more. Later presses use new names and move the meetings a few weeks later. No emails are sent.', 'bdaci-portal-access' ) . '</p>';
		$h .= $form( 'demo_add', '<button class="btn btn-primary">' . esc_html__( 'Add sample data', 'bdaci-portal-access' ) . '</button>' ) . '</section>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		// What's there.
		if ( $users ) {
			$h  = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Test people', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html( sprintf( /* translators: 1: people, 2: meetings, 3: documents */ __( '%1$d people · %2$d meetings · %3$d documents', 'bdaci-portal-access' ), count( $users ), count( $s['meetings'] ), count( $s['docs'] ) ) ) . '</span></div>';
			$h .= '<p>' . wp_kses( sprintf( /* translators: %s: password */ __( 'To see the portal as one of them, sign in at the portal sign-in page with their email and the password <code>%s</code>.', 'bdaci-portal-access' ), esc_html( self::password() ) ), array( 'code' => array() ) ) . ' ' . esc_html__( 'Their emails are your own address with a "+bdaci-test-…" tag, so the 6-digit sign-in code arrives in your inbox (Gmail and Microsoft 365 support this).', 'bdaci-portal-access' ) . '</p>';
			$h .= '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>' . esc_html__( 'Name', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Email', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Roles', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>';
			foreach ( $users as $u ) {
				$h .= '<tr><td>' . esc_html( $u->display_name ) . '</td><td><code>' . esc_html( $u->user_email ) . '</code></td><td>' . esc_html( BDACI_PA_Portal::role_label( $u->ID ) ) . '</td></tr>';
			}
			$h .= '</tbody></table></section>';
			echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		}

		// Remove.
		$h  = '<section class="panel"><h2>' . esc_html__( 'Remove all test data', 'bdaci-portal-access' ) . '</h2>';
		$h .= '<p>' . esc_html__( 'Deletes only what "Add sample data" created: the test people (and their RSVPs, requests and sign-offs), test meetings and test documents. People, meetings and documents you added yourself are kept.', 'bdaci-portal-access' ) . '</p>';
		$h .= $form( 'demo_clear', '<button class="btn"' . ( $s['batch'] || $users ? '' : ' disabled' ) . '>' . esc_html__( 'Remove all test data', 'bdaci-portal-access' ) . '</button>', 'onsubmit="return confirm(\'' . esc_js( __( 'Remove all test data?', 'bdaci-portal-access' ) ) . '\')"' ) . '</section>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		// Launch.
		$counts = array();
		foreach ( array( 'rsvps' => BDACI_PA_Meetings::rsvp_table(), 'signoffs' => BDACI_PA_Signoffs::table(), 'requests' => BDACI_PA_Requests::table(), 'log' => BDACI_PA_Log::table() ) as $k => $t ) {
			$counts[ $k ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$h  = '<section class="panel" style="border-color:var(--danger)"><h2>' . esc_html__( 'Get ready for launch', 'bdaci-portal-access' ) . '</h2>';
		$h .= '<p>' . esc_html__( 'Clears all portal activity so the portal starts fresh: every RSVP, all sign-off history, all access requests and the activity log. People, meetings, documents, pages and settings are kept. This cannot be undone.', 'bdaci-portal-access' ) . '</p>';
		$h .= '<p class="small muted">' . esc_html( sprintf( /* translators: 1-4: counts */ __( 'Now: %1$d RSVPs · %2$d sign-off events · %3$d access requests · %4$d activity log entries', 'bdaci-portal-access' ), $counts['rsvps'], $counts['signoffs'], $counts['requests'], $counts['log'] ) ) . '</p>';
		$h .= $form( 'demo_launch', '<p><label><input type="checkbox" name="with_test_data" value="1" checked> ' . esc_html__( 'Also remove all test data first', 'bdaci-portal-access' ) . '</label></p><p><label for="bdaci-reset">' . wp_kses( __( 'Type <strong>RESET</strong> to confirm:', 'bdaci-portal-access' ), array( 'strong' => array() ) ) . '</label> <input id="bdaci-reset" name="confirm" autocomplete="off" style="width:120px"> <button class="btn" style="color:var(--danger)">' . esc_html__( 'Clear activity for launch', 'bdaci-portal-access' ) . '</button></p>' );
		$h .= '</section>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		echo '</div></div>';
	}
}
