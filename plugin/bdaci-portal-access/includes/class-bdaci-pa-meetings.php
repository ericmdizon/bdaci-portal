<?php
/**
 * Meetings, the calendar and RSVPs.
 *
 * Times are stored in the site's time zone (Settings > General).
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meetings.
 */
class BDACI_PA_Meetings {

	const RESPONSES = array( 'attending', 'regrets', 'tentative' );

	/**
	 * Table names.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_meetings';
	}

	/**
	 * RSVP table.
	 *
	 * @return string
	 */
	public static function rsvp_table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_rsvps';
	}

	/**
	 * Create tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grp varchar(16) NOT NULL DEFAULT 'board',
				title varchar(190) NOT NULL DEFAULT '',
				starts_at datetime NOT NULL,
				ends_at datetime NULL,
				location varchar(255) NOT NULL DEFAULT '',
				video_url varchar(500) NOT NULL DEFAULT '',
				agenda_id bigint(20) unsigned NOT NULL DEFAULT 0,
				package_id bigint(20) unsigned NOT NULL DEFAULT 0,
				minutes_id bigint(20) unsigned NOT NULL DEFAULT 0,
				notes text NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY starts_at (starts_at),
				KEY grp (grp)
			) {$charset};"
		);
		dbDelta(
			'CREATE TABLE ' . self::rsvp_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				meeting_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				response varchar(16) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY meeting_user (meeting_id,user_id)
			) {$charset};"
		);
	}

	/**
	 * Groups.
	 *
	 * @return array key => [label, pill class, access level]
	 */
	public static function groups() {
		return array(
			'board' => array( __( 'Board', 'bdaci-portal-access' ), 'pill-board', 'board' ),
			'edu'   => array( __( 'Education Committee', 'bdaci-portal-access' ), 'pill-edu', 'committee' ),
			'fin'   => array( __( 'Finance Committee', 'bdaci-portal-access' ), 'pill-fin', 'finance' ),
			'other' => array( __( 'Other', 'bdaci-portal-access' ), 'pill-other', 'portal' ),
		);
	}

	/**
	 * Group colour variable.
	 *
	 * @param string $grp Group.
	 * @return string
	 */
	public static function color( $grp ) {
		$c = array(
			'board' => 'var(--cat-board)',
			'edu'   => 'var(--cat-edu)',
			'fin'   => 'var(--cat-fin)',
		);
		return $c[ $grp ] ?? 'var(--cat-other)';
	}

	/**
	 * Current site-local time as Y-m-d H:i:s.
	 *
	 * @return string
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * When a meeting is over (end time, or two hours after the start).
	 *
	 * @param object $m Meeting.
	 * @return string
	 */
	public static function ends( $m ) {
		return $m->ends_at ? $m->ends_at : gmdate( 'Y-m-d H:i:s', strtotime( $m->starts_at . ' UTC' ) + 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Whether a meeting has ended.
	 *
	 * @param object $m Meeting.
	 * @return bool
	 */
	public static function is_past( $m ) {
		return self::ends( $m ) <= self::now();
	}

	/**
	 * Whether a user is invited to (can see) a meeting.
	 *
	 * @param object $m       Meeting.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function visible( $m, $user_id ) {
		$g = self::groups();
		return BDACI_PA_Access::user_meets( $g[ $m->grp ][2] ?? 'board', $user_id );
	}

	/**
	 * One meeting.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * Meetings between two local datetimes (inclusive), oldest first.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return array
	 */
	public static function between( $from, $to ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE starts_at >= %s AND starts_at <= %s ORDER BY starts_at ASC', $from, $to ) );
	}

	/**
	 * All meetings, oldest first.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY starts_at ASC' );
	}

	/**
	 * Meetings a user can see.
	 *
	 * @param int    $user_id User.
	 * @param string $when    upcoming|past|all.
	 * @return array
	 */
	public static function for_user( $user_id, $when = 'all' ) {
		$out = array();
		foreach ( self::all() as $m ) {
			if ( ! self::visible( $m, $user_id ) ) {
				continue;
			}
			$past = self::is_past( $m );
			if ( ( 'upcoming' === $when && $past ) || ( 'past' === $when && ! $past ) ) {
				continue;
			}
			$out[] = $m;
		}
		if ( 'past' === $when ) {
			$out = array_reverse( $out );
		}
		return $out;
	}

	/**
	 * The meeting for the home page's Next Meeting card: the next Board meeting for Board Members,
	 * otherwise the soonest meeting the person is invited to. Social events ("other") never show here.
	 *
	 * @param int $user_id User.
	 * @return object|null
	 */
	public static function next_for( $user_id ) {
		$up = array_values(
			array_filter(
				self::for_user( $user_id, 'upcoming' ),
				static function ( $m ) {
					return 'other' !== $m->grp;
				}
			)
		);
		if ( ! $up ) {
			return null;
		}
		if ( user_can( $user_id, 'bdaci_view_board' ) ) {
			foreach ( $up as $m ) {
				if ( 'board' === $m->grp ) {
					return $m;
				}
			}
		}
		return $up[0];
	}

	/**
	 * Next meeting of a group that a user can see.
	 *
	 * @param string $grp     Group.
	 * @param int    $user_id User.
	 * @return object|null
	 */
	public static function next_of( $grp, $user_id ) {
		foreach ( self::for_user( $user_id, 'upcoming' ) as $m ) {
			if ( $grp === $m->grp ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Save a meeting.
	 *
	 * @param array $data Fields.
	 * @param int   $id   Existing ID, or 0.
	 * @return int|WP_Error
	 */
	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$grp = sanitize_key( $data['grp'] ?? 'board' );
		if ( ! isset( self::groups()[ $grp ] ) ) {
			$grp = 'board';
		}
		$title = sanitize_text_field( $data['title'] ?? '' );
		$date  = sanitize_text_field( $data['date'] ?? '' );
		$start = sanitize_text_field( $data['start'] ?? '' );
		$end   = sanitize_text_field( $data['end'] ?? '' );
		if ( '' === $title || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $start ) ) {
			return new WP_Error( 'bdaci_meeting', __( 'Enter a title, a date and a start time.', 'bdaci-portal-access' ) );
		}
		if ( '' !== $end && ( ! preg_match( '/^\d{2}:\d{2}$/', $end ) || $end <= $start ) ) {
			return new WP_Error( 'bdaci_meeting', __( 'The end time must be after the start time.', 'bdaci-portal-access' ) );
		}
		$video = esc_url_raw( $data['video_url'] ?? '' );
		if ( $video && ! preg_match( '#^https://#i', $video ) ) {
			return new WP_Error( 'bdaci_meeting', __( 'The video call link must start with https://', 'bdaci-portal-access' ) );
		}
		$docs = array();
		foreach ( array( 'agenda_id', 'package_id', 'minutes_id' ) as $k ) {
			$d          = absint( $data[ $k ] ?? 0 );
			$docs[ $k ] = ( $d && get_post_meta( $d, BDACI_PA_Documents::META_PRIVATE, true ) ) ? $d : 0;
		}
		$row = array(
			'grp'        => $grp,
			'title'      => $title,
			'starts_at'  => $date . ' ' . $start . ':00',
			'ends_at'    => '' !== $end ? $date . ' ' . $end . ':00' : null,
			'location'   => sanitize_text_field( $data['location'] ?? '' ),
			'video_url'  => $video,
			'agenda_id'  => $docs['agenda_id'],
			'package_id' => $docs['package_id'],
			'minutes_id' => $docs['minutes_id'],
			'notes'      => sanitize_textarea_field( $data['notes'] ?? '' ),
			'updated_at' => self::now(),
		);
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			BDACI_PA_Log::add( 'meeting_updated', '', get_current_user_id(), $title . ' ' . $row['starts_at'] );
			return (int) $id;
		}
		$row['created_by'] = get_current_user_id();
		$row['created_at'] = self::now();
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new = (int) $wpdb->insert_id; // Read before logging, which runs its own insert.
		BDACI_PA_Log::add( 'meeting_added', '', get_current_user_id(), $title . ' ' . $row['starts_at'] );
		return $new;
	}

	/**
	 * Delete a meeting and its RSVPs.
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$m = self::get( $id );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::rsvp_table(), array( 'meeting_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $m ) {
			BDACI_PA_Log::add( 'meeting_deleted', '', get_current_user_id(), $m->title . ' ' . $m->starts_at );
		}
	}

	/**
	 * A user's RSVP row.
	 *
	 * @param int $meeting_id Meeting.
	 * @param int $user_id    User.
	 * @return object|null
	 */
	public static function rsvp( $meeting_id, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::rsvp_table() . ' WHERE meeting_id = %d AND user_id = %d', $meeting_id, $user_id ) );
	}

	/**
	 * All RSVPs for a meeting.
	 *
	 * @param int $meeting_id Meeting.
	 * @return array
	 */
	public static function rsvps( $meeting_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::rsvp_table() . ' WHERE meeting_id = %d ORDER BY updated_at DESC', $meeting_id ) );
	}

	/**
	 * Save an RSVP from the portal.
	 *
	 * @param int $user_id User.
	 */
	public static function handle_rsvp( $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		$mid  = isset( $_POST['meeting'] ) ? absint( $_POST['meeting'] ) : 0;
		$resp = isset( $_POST['response'] ) ? sanitize_key( wp_unslash( $_POST['response'] ) ) : '';
		// phpcs:enable
		$m = $mid ? self::get( $mid ) : null;
		if ( ! $m || ! self::visible( $m, $user_id ) || 'other' === $m->grp || self::is_past( $m ) || ! in_array( $resp, self::RESPONSES, true ) ) {
			BDACI_PA_Portal::flash( __( 'Your response could not be saved.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$existing = self::rsvp( $mid, $user_id );
		if ( $existing ) {
			$wpdb->update( self::rsvp_table(), array( 'response' => $resp, 'updated_at' => self::now() ), array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( self::rsvp_table(), array( 'meeting_id' => $mid, 'user_id' => $user_id, 'response' => $resp, 'updated_at' => self::now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		BDACI_PA_Log::add( 'rsvp', wp_get_current_user()->user_email, $user_id, $m->title . ' ' . $m->starts_at . ': ' . $resp );
		/* translators: 1: response, 2: meeting */
		BDACI_PA_Portal::flash( sprintf( __( 'Saved: %1$s for %2$s.', 'bdaci-portal-access' ), self::response_label( $resp ), $m->title ) );
	}

	/**
	 * Response label.
	 *
	 * @param string $r Response.
	 * @return string
	 */
	public static function response_label( $r ) {
		$l = array(
			'attending' => __( 'Attending', 'bdaci-portal-access' ),
			'regrets'   => __( 'Regrets', 'bdaci-portal-access' ),
			'tentative' => __( 'Tentative', 'bdaci-portal-access' ),
		);
		return $l[ $r ] ?? $r;
	}

	/**
	 * Whether a user may see everyone's RSVPs (Portal Admins and the Secretary).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function sees_replies( $user_id ) {
		return BDACI_PA_Sections::is_admin( $user_id ) || false !== stripos( (string) get_user_meta( $user_id, 'bdaci_position', true ), 'secretary' );
	}

	/**
	 * "Tuesday, December 8, 2026".
	 *
	 * @param string $dt Datetime.
	 * @return string
	 */
	public static function fmt_date( $dt ) {
		return date_i18n( 'l, F j, Y', strtotime( $dt ) );
	}

	/**
	 * "Dec 8, 2026".
	 *
	 * @param string $dt Datetime.
	 * @return string
	 */
	public static function fmt_short( $dt ) {
		return date_i18n( 'M j, Y', strtotime( $dt ) );
	}

	/**
	 * "6:00 PM – 8:00 PM".
	 *
	 * @param object $m Meeting.
	 * @return string
	 */
	public static function fmt_time( $m ) {
		$f = get_option( 'time_format', 'g:i a' );
		$s = date_i18n( $f, strtotime( $m->starts_at ) );
		return $m->ends_at ? $s . ' – ' . date_i18n( $f, strtotime( $m->ends_at ) ) : $s;
	}
}
