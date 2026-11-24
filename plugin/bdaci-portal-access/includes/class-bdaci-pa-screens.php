<?php
/**
 * The portal screens (what each portal page shows).
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Screens.
 */
class BDACI_PA_Screens {

	/**
	 * Render a screen.
	 *
	 * @param string $screen  Screen.
	 * @param int    $post_id Page.
	 * @return string
	 */
	public static function render( $screen, $post_id ) {
		switch ( $screen ) {
			case 'dashboard':
				return self::dashboard();
			case 'calendar':
				return self::calendar();
			case 'meetings':
				return self::meetings();
			case 'leadership':
				return self::leadership( $post_id );
			case 'governance':
				return self::governance( $post_id );
			case 'orientation':
			case 'qa':
				return self::signoff( $screen, $post_id );
			case 'education':
			case 'finance':
				return self::committee( $screen, $post_id );
			case 'section':
				return self::section( $post_id );
			case 'profile':
				return self::profile();
			case 'help':
				return self::help();
		}
		return BDACI_PA_Portal::head( get_the_title( $post_id ) );
	}

	/**
	 * Short alias.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function e( $s ) {
		return BDACI_PA_Portal::e( $s );
	}

	/**
	 * Query arg from the URL.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private static function arg( $k ) {
		return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* ---------------------------------------------------------------------
	 * Pieces
	 * ------------------------------------------------------------------ */

	/**
	 * RSVP buttons.
	 *
	 * @param object $m        Meeting.
	 * @param bool   $on_panel Light background.
	 * @return string
	 */
	public static function rsvp_block( $m, $on_panel = false ) {
		$uid = get_current_user_id();
		$cur = BDACI_PA_Meetings::rsvp( $m->id, $uid );
		$btn = '';
		foreach ( BDACI_PA_Meetings::RESPONSES as $r ) {
			$btn .= BDACI_PA_Portal::form( 'rsvp', array( 'meeting' => $m->id, 'response' => $r ), '<button type="submit" aria-pressed="' . ( $cur && $cur->response === $r ? 'true' : 'false' ) . '">' . self::e( BDACI_PA_Meetings::response_label( $r ) ) . '</button>' );
		}
		$msg = $cur
			/* translators: 1: response, 2: date */
			? sprintf( __( 'Your response: %1$s. Saved %2$s.', 'bdaci-portal-access' ), '<b>' . self::e( BDACI_PA_Meetings::response_label( $cur->response ) ) . '</b>', self::e( BDACI_PA_Signoffs::fmt( $cur->updated_at ) ) )
			: esc_html__( 'Please let the Secretary know if you can attend.', 'bdaci-portal-access' );
		return '<div class="rsvp' . ( $on_panel ? ' on-panel' : '' ) . '" role="group" aria-label="' . esc_attr__( 'RSVP', 'bdaci-portal-access' ) . '">' . $btn . '<span class="rsvp-status">' . $msg . '</span></div>';
	}

	/**
	 * Document buttons for a meeting (only those the person may open).
	 *
	 * @param object $m     Meeting.
	 * @param string $class Button class.
	 * @return array
	 */
	private static function meeting_docs( $m, $class = 'btn btn-sm' ) {
		$out = array();
		$def = array(
			'agenda_id'  => __( 'Agenda', 'bdaci-portal-access' ),
			'package_id' => __( 'Meeting package', 'bdaci-portal-access' ),
			'minutes_id' => __( 'Minutes', 'bdaci-portal-access' ),
		);
		foreach ( $def as $k => $label ) {
			$id = (int) $m->$k;
			if ( $id && get_post( $id ) && BDACI_PA_Sections::can_open( $id ) ) {
				$out[ $k ] = '<a class="' . esc_attr( $class ) . '" href="' . esc_url( BDACI_PA_Documents::link( $id ) ) . '" target="_blank" rel="noopener">' . self::e( $label ) . '</a>';
			}
		}
		return $out;
	}

	/**
	 * Group pill.
	 *
	 * @param string $grp Group.
	 * @return string
	 */
	private static function pill( $grp ) {
		$g = BDACI_PA_Meetings::groups();
		$x = $g[ $grp ] ?? $g['other'];
		return '<span class="pill ' . esc_attr( $x[1] ) . '">' . self::e( $x[0] ) . '</span>';
	}

	/**
	 * Directory cards.
	 *
	 * @param array $people From BDACI_PA_Profile::directory().
	 * @return string
	 */
	private static function people( array $people ) {
		if ( ! $people ) {
			return '<p class="muted">' . esc_html__( 'No one is listed yet.', 'bdaci-portal-access' ) . '</p>';
		}
		$h = '<div class="people">';
		foreach ( $people as $p ) {
			list( $u, $pos, $note ) = $p;
			$phone = get_user_meta( $u->ID, 'bdaci_show_phone', true ) ? (string) get_user_meta( $u->ID, 'bdaci_phone', true ) : '';
			$h    .= '<article class="person"><div class="top">' . BDACI_PA_Profile::avatar( $u->ID ) . '<div><b>' . self::e( $u->display_name ) . '</b><br><span class="small" style="color:var(--brand);font-weight:700">' . self::e( $pos ) . '</span></div></div>';
			$h    .= $note ? '<p class="term">' . self::e( $note ) . '</p>' : '';
			$h    .= '<div class="contact"><p class="small"><span class="muted">' . esc_html__( 'Email', 'bdaci-portal-access' ) . '</span><br><a class="contact-link" href="mailto:' . esc_attr( $u->user_email ) . '">' . self::e( $u->user_email ) . '</a></p>';
			if ( $phone ) {
				$h .= '<p class="small tabnum"><span class="muted">' . esc_html__( 'Phone', 'bdaci-portal-access' ) . '</span><br><a class="contact-link" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . self::e( $phone ) . '</a></p>';
			}
			$h .= '</div></article>';
		}
		return $h . '</div>';
	}

	/**
	 * Year buttons.
	 *
	 * @param array  $years Years.
	 * @param string $cur   Current.
	 * @param array  $keep  Query args to keep.
	 * @return string
	 */
	private static function year_tabs( array $years, $cur, array $keep = array() ) {
		$h = '<div class="years" role="group" aria-label="' . esc_attr__( 'Year', 'bdaci-portal-access' ) . '" style="margin-bottom:12px">';
		foreach ( $years as $y ) {
			$h .= '<a href="' . esc_url( add_query_arg( array_merge( $keep, array( 'year' => $y ) ) ) ) . '" aria-current="' . ( (string) $y === (string) $cur ? 'true' : 'false' ) . '">' . self::e( $y ) . '</a>';
		}
		return $h . '</div>';
	}

	/**
	 * Years of a list of documents, newest first.
	 *
	 * @param WP_Post[] $docs Docs.
	 * @return array
	 */
	private static function years_of( array $docs ) {
		$y = array();
		foreach ( $docs as $d ) {
			$y[ substr( BDACI_PA_Sections::doc_date( $d->ID ), 0, 4 ) ] = true;
		}
		$y = array_keys( $y );
		rsort( $y );
		return $y;
	}

	/**
	 * Document list.
	 *
	 * @param WP_Post[] $docs  Docs.
	 * @param string    $empty Text when empty.
	 * @param string    $kind  Thumbnail kind.
	 * @return string
	 */
	private static function doc_list( array $docs, $empty, $kind = '' ) {
		if ( ! $docs ) {
			return '<p class="muted">' . self::e( $empty ) . '</p>';
		}
		$h = '<ul class="list">';
		foreach ( $docs as $d ) {
			$h .= BDACI_PA_Portal::doc_item( $d, null, $kind );
		}
		return $h . '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Home.
	 *
	 * @return string
	 */
	public static function dashboard() {
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		$hour = (int) current_time( 'G' );
		$greet = $hour < 12 ? __( 'Good morning', 'bdaci-portal-access' ) : ( $hour < 18 ? __( 'Good afternoon', 'bdaci-portal-access' ) : __( 'Good evening', 'bdaci-portal-access' ) );
		$first = $user->first_name ? $user->first_name : strtok( $user->display_name, ' ' );
		$m     = BDACI_PA_Meetings::next_for( $uid );

		$card = '';
		if ( $m ) {
			$where = self::e( $m->location );
			if ( $m->video_url ) {
				$where .= ( $where ? '<br>' : '' ) . '<a href="' . esc_url( $m->video_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join video call', 'bdaci-portal-access' ) . '</a>';
			}
			$docs = self::meeting_docs( $m, 'btn' );
			if ( isset( $docs['agenda_id'] ) ) {
				$docs['agenda_id'] = str_replace( 'class="btn"', 'class="btn btn-primary"', $docs['agenda_id'] );
			}
			$card = '<section class="panel next" id="next" aria-labelledby="next-h"><div><p class="eyebrow">' . esc_html__( 'Next meeting', 'bdaci-portal-access' ) . '</p><h2 id="next-h">' . self::e( $m->title ) . '</h2></div>' .
				'<dl class="next-meta" style="margin:0"><div><dt>' . esc_html__( 'Date', 'bdaci-portal-access' ) . '</dt><dd class="tabnum">' . self::e( BDACI_PA_Meetings::fmt_date( $m->starts_at ) ) . '</dd></div><div><dt>' . esc_html__( 'Time', 'bdaci-portal-access' ) . '</dt><dd class="tabnum">' . self::e( BDACI_PA_Meetings::fmt_time( $m ) ) . '</dd></div><div><dt>' . esc_html__( 'Location', 'bdaci-portal-access' ) . '</dt><dd>' . ( $where ? $where : '–' ) . '</dd></div></dl>' .
				self::rsvp_block( $m ) . ( $docs ? '<div class="row-btns">' . implode( '', $docs ) . '</div>' : '<p class="small" style="opacity:.85">' . esc_html__( 'The agenda will be posted about one week before the meeting.', 'bdaci-portal-access' ) . '</p>' ) . '</section>';
		} else {
			$card = '<section class="panel"><h2>' . esc_html__( 'Next meeting', 'bdaci-portal-access' ) . '</h2><p class="muted">' . esc_html__( 'No upcoming meetings have been scheduled yet.', 'bdaci-portal-access' ) . '</p></section>';
		}

		$tasks = array();
		foreach ( BDACI_PA_Signoffs::packs() as $key => $pack ) {
			if ( BDACI_PA_Signoffs::needs_action( $key, $uid ) ) {
				$sub     = $pack['due'] ? sprintf( /* translators: %s: date */ __( 'Due %s', 'bdaci-portal-access' ), date_i18n( 'F j, Y', strtotime( $pack['due'] ) ) ) : __( 'Read the documents and confirm', 'bdaci-portal-access' );
				$tasks[] = '<li><span class="pill pill-warn">' . esc_html__( 'Action required', 'bdaci-portal-access' ) . '</span><div class="grow"><b>' . self::e( sprintf( /* translators: %s: sign-off */ __( '%s sign-off', 'bdaci-portal-access' ), $pack['title'] ) ) . '</b><span class="muted small">' . self::e( $sub ) . '</span></div><a class="btn btn-sm" href="' . esc_url( BDACI_PA_Portal::url( $key ) ) . '">' . esc_html__( 'Open', 'bdaci-portal-access' ) . '</a></li>';
			}
		}
		if ( $m && ! BDACI_PA_Meetings::rsvp( $m->id, $uid ) ) {
			$tasks[] = '<li><span class="pill pill-muted">RSVP</span><div class="grow"><b>' . self::e( $m->title ) . '</b><span class="muted small">' . self::e( BDACI_PA_Meetings::fmt_short( $m->starts_at ) ) . '</span></div><a class="btn btn-sm" href="#next">' . esc_html__( 'Respond', 'bdaci-portal-access' ) . '</a></li>';
		}
		$todo = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Your to-do list', 'bdaci-portal-access' ) . '</h2><span class="pill ' . ( $tasks ? 'pill-warn' : 'pill-ok' ) . '">' . ( $tasks ? self::e( sprintf( /* translators: %d: count */ __( '%d open', 'bdaci-portal-access' ), count( $tasks ) ) ) : esc_html__( 'All done', 'bdaci-portal-access' ) ) . '</span></div>' .
			( $tasks ? '<ul class="list todo">' . implode( '', $tasks ) . '</ul>' : '<p class="muted">' . esc_html__( 'Nothing needs your attention right now.', 'bdaci-portal-access' ) . '</p>' ) . '</section>';

		$recent = '';
		foreach ( BDACI_PA_Sections::docs( array( 'limit' => 0 ) ) as $d ) {
			if ( ! isset( $list ) ) {
				$list = array();
			}
			$list[] = $d;
		}
		if ( ! empty( $list ) ) {
			usort( $list, static function ( $a, $b ) { return strcmp( $b->post_date, $a->post_date ); } );
			foreach ( array_slice( $list, 0, 5 ) as $d ) {
				$secs    = array_map( array( 'BDACI_PA_Sections', 'label' ), BDACI_PA_Sections::doc_sections( $d->ID ) );
				$recent .= BDACI_PA_Portal::doc_item( $d, sprintf( /* translators: %s: date */ __( 'Posted %s', 'bdaci-portal-access' ), get_the_date( 'M j, g:i A', $d ) ) . ( $secs ? ' · ' . implode( ', ', $secs ) : '' ) );
			}
		}
		$recent = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Recently posted', 'bdaci-portal-access' ) . '</h2><a href="' . esc_url( BDACI_PA_Portal::url( 'meetings' ) ) . '" class="small">' . esc_html__( 'All meetings', 'bdaci-portal-access' ) . '</a></div>' . ( $recent ? '<ul class="list">' . $recent . '</ul>' : '<p class="muted">' . esc_html__( 'Nothing has been posted yet.', 'bdaci-portal-access' ) . '</p>' ) . '</section>';

		return BDACI_PA_Portal::head( $greet . ', ' . $first, date_i18n( 'l, F j, Y' ) ) .
			'<div class="grid-dash"><div class="stack">' . $card . $todo . $recent . '</div><aside class="stack" aria-label="' . esc_attr__( 'Calendar', 'bdaci-portal-access' ) . '">' . self::mini_cal() . '</aside></div>';
	}

	/**
	 * Month to show (Y-m) from a query arg, default this month.
	 *
	 * @param string $arg Query arg.
	 * @return array [year, month]
	 */
	private static function month_arg( $arg ) {
		$v = self::arg( $arg );
		if ( preg_match( '/^(\d{4})-(\d{2})$/', $v, $mm ) && (int) $mm[2] >= 1 && (int) $mm[2] <= 12 ) {
			return array( (int) $mm[1], (int) $mm[2] );
		}
		return array( (int) current_time( 'Y' ), (int) current_time( 'n' ) );
	}

	/**
	 * Meetings a user can see, by date (Y-m-d), for a month grid.
	 *
	 * @param int $y Year.
	 * @param int $m Month.
	 * @return array
	 */
	private static function by_day( $y, $m ) {
		$uid  = get_current_user_id();
		$from = gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $m, 1 - 7, $y ) ) . ' 00:00:00';
		$to   = gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $m + 1, 7, $y ) ) . ' 23:59:59';
		$out  = array();
		foreach ( BDACI_PA_Meetings::between( $from, $to ) as $mt ) {
			if ( BDACI_PA_Meetings::visible( $mt, $uid ) ) {
				$out[ substr( $mt->starts_at, 0, 10 ) ][] = $mt;
			}
		}
		return $out;
	}

	/**
	 * Legend.
	 *
	 * @return string
	 */
	private static function legend() {
		$h = '<div class="legend">';
		foreach ( BDACI_PA_Meetings::groups() as $k => $g ) {
			if ( BDACI_PA_Access::user_meets( $g[2], get_current_user_id() ) ) {
				$h .= '<span><i class="dot" style="background:' . BDACI_PA_Meetings::color( $k ) . '"></i>' . self::e( $g[0] ) . '</span>';
			}
		}
		return $h . '</div>';
	}

	/**
	 * Small calendar for the home page.
	 *
	 * @return string
	 */
	private static function mini_cal() {
		list( $y, $mo ) = self::month_arg( 'mini' );
		$days  = self::by_day( $y, $mo );
		$first = gmmktime( 0, 0, 0, $mo, 1, $y );
		$start = (int) gmdate( 'w', $first );
		$today = current_time( 'Y-m-d' );
		$cells = '';
		foreach ( array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' ) as $d ) {
			$cells .= '<div class="dow">' . $d . '</div>';
		}
		$cal = BDACI_PA_Portal::url( 'calendar' );
		for ( $i = 0; $i < 42; $i++ ) {
			$ts = gmmktime( 0, 0, 0, $mo, 1 - $start + $i, $y );
			if ( $i >= 35 && (int) gmdate( 'n', $ts ) !== $mo ) {
				break;
			}
			$ymd = gmdate( 'Y-m-d', $ts );
			$ev  = $days[ $ymd ] ?? array();
			$cls = 'd' . ( (int) gmdate( 'n', $ts ) !== $mo ? ' out' : '' ) . ( $ymd === $today ? ' today' : '' ) . ( $ev ? ' has' : '' );
			$lbl = date_i18n( 'F j', $ts ) . ( $ev ? ': ' . implode( ', ', wp_list_pluck( $ev, 'title' ) ) : '' );
			$dot = '';
			foreach ( $ev as $e ) {
				$dot .= '<i style="background:' . BDACI_PA_Meetings::color( $e->grp ) . '"></i>';
			}
			$num    = gmdate( 'j', $ts ) . ( $dot ? '<span class="ev">' . $dot . '</span>' : '' );
			$cells .= $ev ? '<a class="' . $cls . '" title="' . esc_attr( $lbl ) . '" href="' . esc_url( add_query_arg( array( 'cal' => gmdate( 'Y-m', $ts ), 'ev' => $ev[0]->id ), $cal ) ) . '#evdetail" style="color:inherit;text-decoration:none">' . $num . '</a>' : '<div class="' . $cls . '" title="' . esc_attr( $lbl ) . '">' . $num . '</div>';
		}
		$prev = gmdate( 'Y-m', gmmktime( 0, 0, 0, $mo - 1, 1, $y ) );
		$next = gmdate( 'Y-m', gmmktime( 0, 0, 0, $mo + 1, 1, $y ) );
		$up   = '';
		foreach ( array_slice( BDACI_PA_Meetings::for_user( get_current_user_id(), 'upcoming' ), 0, 4 ) as $m ) {
			$up .= '<div><span class="datebox"><small>' . self::e( date_i18n( 'M', strtotime( $m->starts_at ) ) ) . '</small><b class="tabnum">' . self::e( date_i18n( 'j', strtotime( $m->starts_at ) ) ) . '</b></span><span><b>' . self::e( $m->title ) . '</b><br>' . self::pill( $m->grp ) . '</span></div>';
		}
		return '<section class="panel"><div class="cal-h"><a href="' . esc_url( add_query_arg( 'mini', $prev ) ) . '" aria-label="' . esc_attr__( 'Previous month', 'bdaci-portal-access' ) . '">‹</a><h2 class="tabnum">' . self::e( date_i18n( 'F Y', $first ) ) . '</h2><a href="' . esc_url( add_query_arg( 'mini', $next ) ) . '" aria-label="' . esc_attr__( 'Next month', 'bdaci-portal-access' ) . '">›</a></div><div class="mini">' . $cells . '</div>' . self::legend() .
			'<div class="upnext"><p class="eyebrow">' . esc_html__( 'Coming up', 'bdaci-portal-access' ) . '</p>' . ( $up ? $up : '<p class="small muted">' . esc_html__( 'Nothing scheduled.', 'bdaci-portal-access' ) . '</p>' ) . '</div></section>';
	}

	/* ---------------------------------------------------------------------
	 * Calendar and meetings
	 * ------------------------------------------------------------------ */

	/**
	 * Month calendar.
	 *
	 * @return string
	 */
	public static function calendar() {
		list( $y, $mo ) = self::month_arg( 'cal' );
		$days  = self::by_day( $y, $mo );
		$first = gmmktime( 0, 0, 0, $mo, 1, $y );
		$start = (int) gmdate( 'w', $first );
		$today = current_time( 'Y-m-d' );
		$g     = '';
		foreach ( array( __( 'Sun', 'bdaci-portal-access' ), __( 'Mon', 'bdaci-portal-access' ), __( 'Tue', 'bdaci-portal-access' ), __( 'Wed', 'bdaci-portal-access' ), __( 'Thu', 'bdaci-portal-access' ), __( 'Fri', 'bdaci-portal-access' ), __( 'Sat', 'bdaci-portal-access' ) ) as $d ) {
			$g .= '<div class="dow">' . self::e( $d ) . '</div>';
		}
		for ( $i = 0; $i < 42; $i++ ) {
			$ts = gmmktime( 0, 0, 0, $mo, 1 - $start + $i, $y );
			if ( $i >= 35 && (int) gmdate( 'n', $ts ) !== $mo ) {
				break;
			}
			$ymd = gmdate( 'Y-m-d', $ts );
			$g  .= '<div class="cell' . ( (int) gmdate( 'n', $ts ) !== $mo ? ' out' : '' ) . ( $ymd === $today ? ' today' : '' ) . '"><span class="num tabnum">' . gmdate( 'j', $ts ) . '</span>';
			foreach ( $days[ $ymd ] ?? array() as $e ) {
				$g .= '<a class="evchip" style="border-left-color:' . BDACI_PA_Meetings::color( $e->grp ) . '" href="' . esc_url( add_query_arg( array( 'cal' => sprintf( '%04d-%02d', $y, $mo ), 'ev' => $e->id ) ) ) . '#evdetail"><b>' . self::e( $e->title ) . '</b><br><span class="muted tabnum">' . self::e( date_i18n( get_option( 'time_format', 'g:i a' ), strtotime( $e->starts_at ) ) ) . '</span></a>';
			}
			$g .= '</div>';
		}
		$prev  = gmdate( 'Y-m', gmmktime( 0, 0, 0, $mo - 1, 1, $y ) );
		$next  = gmdate( 'Y-m', gmmktime( 0, 0, 0, $mo + 1, 1, $y ) );
		$right = '<div class="row-btns"><a class="btn btn-sm" href="' . esc_url( add_query_arg( 'cal', $prev, remove_query_arg( 'ev' ) ) ) . '">‹ ' . esc_html__( 'Previous', 'bdaci-portal-access' ) . '</a><a class="btn btn-sm" href="' . esc_url( remove_query_arg( array( 'cal', 'ev' ) ) ) . '">' . esc_html__( 'Today', 'bdaci-portal-access' ) . '</a><a class="btn btn-sm" href="' . esc_url( add_query_arg( 'cal', $next, remove_query_arg( 'ev' ) ) ) . '">' . esc_html__( 'Next', 'bdaci-portal-access' ) . ' ›</a></div>';
		$ev     = absint( self::arg( 'ev' ) );
		$m      = $ev ? BDACI_PA_Meetings::get( $ev ) : null;
		$detail = $m && BDACI_PA_Meetings::visible( $m, get_current_user_id() ) ? self::event_detail( $m ) : '<p class="muted">' . esc_html__( 'Select a meeting to see its details.', 'bdaci-portal-access' ) . '</p>';
		return BDACI_PA_Portal::head( __( 'Calendar', 'bdaci-portal-access' ), __( 'All upcoming Board and committee meetings. Colours show which group is meeting.', 'bdaci-portal-access' ), $right ) .
			'<section class="panel"><div class="panel-h"><h2 class="tabnum">' . self::e( date_i18n( 'F Y', $first ) ) . '</h2>' . str_replace( 'class="legend"', 'class="legend" style="margin:0"', self::legend() ) . '</div><div class="bigcal-wrap"><div class="bigcal">' . $g . '</div></div></section>' .
			'<section class="panel" id="evdetail">' . $detail . '</section>';
	}

	/**
	 * Meeting details.
	 *
	 * @param object $m Meeting.
	 * @return string
	 */
	public static function event_detail( $m ) {
		$uid  = get_current_user_id();
		$past = BDACI_PA_Meetings::is_past( $m );
		$h    = '<div class="panel-h"><div>' . self::pill( $m->grp ) . '<h2 style="margin-top:6px">' . self::e( $m->title ) . '</h2></div></div>';
		$h   .= '<p class="tabnum"><b>' . self::e( BDACI_PA_Meetings::fmt_date( $m->starts_at ) ) . '</b> · ' . self::e( BDACI_PA_Meetings::fmt_time( $m ) ) . '</p>';
		$h   .= $m->location ? '<p class="muted">' . self::e( $m->location ) . '</p>' : '';
		if ( $m->video_url && ! $past ) {
			$h .= '<p><a href="' . esc_url( $m->video_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Join video call', 'bdaci-portal-access' ) . '</a></p>';
		}
		$h .= $m->notes ? '<p class="small" style="margin-top:8px;white-space:pre-line">' . self::e( $m->notes ) . '</p>' : '';
		if ( 'other' !== $m->grp && ! $past ) {
			$h .= '<div style="margin-top:12px">' . self::rsvp_block( $m, true ) . '</div>';
		}
		$docs = self::meeting_docs( $m );
		$h   .= '<div class="row-btns" style="margin-top:14px">' . ( $docs ? implode( '', $docs ) : '<span class="muted small">' . ( $past ? esc_html__( 'No documents were posted.', 'bdaci-portal-access' ) : esc_html__( 'The agenda will be posted about one week before the meeting.', 'bdaci-portal-access' ) ) . '</span>' ) . '</div>';
		if ( 'other' !== $m->grp && BDACI_PA_Meetings::sees_replies( $uid ) ) {
			$rows = '';
			foreach ( BDACI_PA_Meetings::rsvps( $m->id ) as $r ) {
				$u = get_userdata( $r->user_id );
				if ( $u ) {
					$rows .= '<tr><td><span class="who">' . BDACI_PA_Profile::avatar( $u->ID, 'av-sm' ) . self::e( $u->display_name ) . '</span></td><td>' . self::e( BDACI_PA_Meetings::response_label( $r->response ) ) . '</td><td class="tabnum small">' . self::e( BDACI_PA_Signoffs::fmt( $r->updated_at ) ) . '</td></tr>';
				}
			}
			$h .= '<h3 style="margin-top:18px">' . esc_html__( 'Replies', 'bdaci-portal-access' ) . '</h3>' . ( $rows ? '<div class="tablewrap"><table><thead><tr><th>' . esc_html__( 'Person', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Response', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'When', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '<p class="muted small">' . esc_html__( 'No replies yet.', 'bdaci-portal-access' ) . '</p>' );
		}
		return $h;
	}

	/**
	 * Meetings list.
	 *
	 * @return string
	 */
	public static function meetings() {
		$uid    = get_current_user_id();
		$filter = sanitize_key( self::arg( 'g' ) );
		$groups = BDACI_PA_Meetings::groups();
		$opts   = array( 'all' => __( 'All', 'bdaci-portal-access' ) );
		foreach ( array( 'board' => __( 'Board', 'bdaci-portal-access' ), 'edu' => __( 'Education', 'bdaci-portal-access' ), 'fin' => __( 'Finance', 'bdaci-portal-access' ) ) as $k => $l ) {
			if ( BDACI_PA_Access::user_meets( $groups[ $k ][2], $uid ) ) {
				$opts[ $k ] = $l;
			}
		}
		if ( ! isset( $opts[ $filter ] ) ) {
			$filter = 'all';
		}
		$right = '<div class="years" role="group" aria-label="' . esc_attr__( 'Filter', 'bdaci-portal-access' ) . '">';
		foreach ( $opts as $k => $l ) {
			$right .= '<a href="' . esc_url( 'all' === $k ? remove_query_arg( 'g' ) : add_query_arg( 'g', $k ) ) . '" aria-current="' . ( $filter === $k ? 'true' : 'false' ) . '">' . self::e( $l ) . '</a>';
		}
		$right .= '</div>';
		$rows   = function ( array $list, $past ) use ( $filter ) {
			$r = '';
			foreach ( $list as $m ) {
				if ( 'other' === $m->grp || ( 'all' !== $filter && $m->grp !== $filter ) ) {
					continue;
				}
				$docs = self::meeting_docs( $m );
				$r   .= '<tr><td class="tabnum" style="white-space:nowrap"><b>' . self::e( BDACI_PA_Meetings::fmt_short( $m->starts_at ) ) . '</b><br><span class="muted small">' . self::e( BDACI_PA_Meetings::fmt_time( $m ) ) . '</span></td><td><b>' . self::e( $m->title ) . '</b><br><span class="muted small">' . self::e( $m->location ) . '</span></td><td>' . self::pill( $m->grp ) . '</td><td><div class="row-btns" style="gap:6px">' . ( $docs ? implode( '', $docs ) : '<span class="muted small">' . ( $past ? '–' : esc_html__( 'Not posted yet', 'bdaci-portal-access' ) ) . '</span>' ) . '</div></td></tr>';
			}
			return $r;
		};
		$tbl = function ( $title, $r ) {
			return '<section class="panel"><h2>' . self::e( $title ) . '</h2><div class="tablewrap"><table><thead><tr><th>' . esc_html__( 'Date', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Meeting', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Group', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Documents', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . ( $r ? $r : '<tr><td colspan="4" class="muted">' . esc_html__( 'No meetings.', 'bdaci-portal-access' ) . '</td></tr>' ) . '</tbody></table></div></section>';
		};
		return BDACI_PA_Portal::head( __( 'Meetings', 'bdaci-portal-access' ), __( 'Current and upcoming meetings with agendas, meeting packages and minutes. PDFs open in your browser.', 'bdaci-portal-access' ), $right ) .
			$tbl( __( 'Upcoming', 'bdaci-portal-access' ), $rows( BDACI_PA_Meetings::for_user( $uid, 'upcoming' ), false ) ) .
			$tbl( __( 'Recent', 'bdaci-portal-access' ), $rows( array_slice( BDACI_PA_Meetings::for_user( $uid, 'past' ), 0, 30 ), true ) );
	}

	/* ---------------------------------------------------------------------
	 * Leadership, governance, committees, sections
	 * ------------------------------------------------------------------ */

	/**
	 * Leadership directory.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function leadership( $post_id ) {
		$edit = BDACI_PA_Sections::is_admin( get_current_user_id() ) ? '<a class="btn btn-sm" href="' . esc_url( BDACI_PA_Admin_Portal::to_front( admin_url( 'admin.php?page=bdaci-portal' ) ) ) . '">' . esc_html__( 'Edit directory', 'bdaci-portal-access' ) . '</a>' : '';
		return BDACI_PA_Portal::head( get_the_title( $post_id ), __( 'Names, roles and contact details for BDACI\'s board leadership.', 'bdaci-portal-access' ), $edit ) . '<section class="panel">' . self::people( BDACI_PA_Profile::directory( 'board' ) ) . '</section>';
	}

	/**
	 * Governance folders.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function governance( $post_id ) {
		$admin   = BDACI_PA_Sections::is_admin( get_current_user_id() );
		$folders = array(
			'bylaws'    => array( __( 'Bylaws', 'bdaci-portal-access' ), __( 'Current bylaws and amendments', 'bdaci-portal-access' ), 'policy' ),
			'policies'  => array( __( 'Board Policies', 'bdaci-portal-access' ), __( 'Governance policy manual', 'bdaci-portal-access' ), 'policy' ),
			'strategic' => array( __( 'Strategic Plan', 'bdaci-portal-access' ), __( 'Current strategic plan', 'bdaci-portal-access' ), 'doc' ),
			'reports'   => array( __( 'Financial Reports', 'bdaci-portal-access' ), __( 'Current and archived', 'bdaci-portal-access' ), 'finance' ),
			'minutes'   => array( __( 'Previous Meeting Minutes', 'bdaci-portal-access' ), __( 'Organized by year', 'bdaci-portal-access' ), 'minutes' ),
		);
		foreach ( array_keys( $folders ) as $k ) {
			if ( 'minutes' !== $k && BDACI_PA_Sections::is_hidden( $k ) && ! $admin ) {
				unset( $folders[ $k ] );
			}
		}
		$cur = sanitize_key( self::arg( 'folder' ) );
		if ( ! isset( $folders[ $cur ] ) ) {
			$cur = 'bylaws';
		}
		$h = '<div class="folders" role="group" aria-label="' . esc_attr__( 'Folders', 'bdaci-portal-access' ) . '">';
		foreach ( $folders as $k => $f ) {
			$hidden = BDACI_PA_Sections::is_hidden( $k );
			$h     .= '<a class="folder" href="' . esc_url( add_query_arg( 'folder', $k, remove_query_arg( 'year' ) ) ) . '" aria-pressed="' . ( $cur === $k ? 'true' : 'false' ) . '">' . BDACI_PA_Portal::thumb( $f[0], '', $f[2] ) . '<span><b>' . self::e( $f[0] ) . '</b><span class="muted small">' . self::e( $hidden ? __( 'Hidden from users', 'bdaci-portal-access' ) : $f[1] ) . '</span></span></a>';
		}
		$h .= '</div>';
		$f  = $folders[ $cur ];
		if ( 'minutes' === $cur ) {
			$all   = BDACI_PA_Sections::docs( array( 'section' => 'minutes', 'exclude' => array( 'edu', 'fincom' ) ) );
			$years = self::years_of( $all );
			$year  = in_array( self::arg( 'year' ), $years, true ) ? self::arg( 'year' ) : ( $years[0] ?? '' );
			$docs  = array_filter( $all, static function ( $d ) use ( $year ) { return 0 === strpos( BDACI_PA_Sections::doc_date( $d->ID ), $year ); } );
			$body  = ( $years ? self::year_tabs( $years, $year, array( 'folder' => 'minutes' ) ) : '' ) . self::doc_list( array_values( $docs ), __( 'No minutes have been posted yet.', 'bdaci-portal-access' ), 'minutes' );
		} else {
			$note = '';
			if ( BDACI_PA_Sections::is_hidden( $cur ) ) {
				$note = '<div class="note" style="margin-bottom:12px"><b>' . esc_html__( 'Hidden from users.', 'bdaci-portal-access' ) . '</b> ' . esc_html__( 'Only Portal Admins can see this folder.', 'bdaci-portal-access' ) . ' <a href="' . esc_url( BDACI_PA_Admin_Portal::to_front( admin_url( 'admin.php?page=bdaci-portal-docs#bdaci-visibility' ) ) ) . '">' . esc_html__( 'Show to users', 'bdaci-portal-access' ) . '</a></div>';
			}
			$empty = 'strategic' === $cur ? __( 'No documents yet. The Strategic Plan will be added here once the board approves it.', 'bdaci-portal-access' ) : __( 'No documents in this folder yet.', 'bdaci-portal-access' );
			$body  = $note . self::doc_list( BDACI_PA_Sections::docs( array( 'section' => $cur ) ), $empty, $f[2] );
		}
		return BDACI_PA_Portal::head( get_the_title( $post_id ), __( 'Bylaws, policies, financial reports and past minutes.', 'bdaci-portal-access' ) ) . $h . '<section class="panel"><h2>' . self::e( $f[0] ) . '</h2>' . $body . '</section>';
	}

	/**
	 * Education or Finance Committee.
	 *
	 * @param string $screen  education|finance.
	 * @param int    $post_id Page.
	 * @return string
	 */
	public static function committee( $screen, $post_id ) {
		$is_fin  = 'finance' === $screen;
		$sec     = $is_fin ? 'fincom' : 'edu';
		$grp     = $is_fin ? 'fin' : 'edu';
		$pill    = $is_fin ? 'pill-fin' : 'pill-edu';
		$uid     = get_current_user_id();
		$people  = BDACI_PA_Profile::directory( $is_fin ? 'finance' : 'committee' );
		$next    = BDACI_PA_Meetings::next_of( $grp, $uid );
		$current = array();
		if ( $next ) {
			foreach ( array( 'agenda_id', 'package_id', 'minutes_id' ) as $k ) {
				if ( $next->$k && get_post( $next->$k ) && BDACI_PA_Sections::can_open( $next->$k ) ) {
					$current[ $next->$k ] = get_post( $next->$k );
				}
			}
		}
		foreach ( BDACI_PA_Sections::docs( array( 'section' => $sec, 'limit' => 8 ) ) as $d ) {
			if ( count( $current ) >= 6 ) {
				break;
			}
			if ( ! isset( $current[ $d->ID ] ) && ( ! in_array( 'minutes', BDACI_PA_Sections::doc_sections( $d->ID ), true ) || ( time() - strtotime( $d->post_date_gmt . ' UTC' ) ) < 60 * DAY_IN_SECONDS ) ) {
				$current[ $d->ID ] = $d;
			}
		}
		$badge = $next ? '<span class="pill ' . $pill . '">' . self::e( sprintf( /* translators: %s: date */ __( 'Next meeting %s', 'bdaci-portal-access' ), BDACI_PA_Meetings::fmt_short( $next->starts_at ) ) ) . '</span>' : '';
		$cur   = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Current agenda & minutes', 'bdaci-portal-access' ) . '</h2>' . $badge . '</div>' . self::doc_list( array_values( $current ), __( 'Nothing posted for the next meeting yet.', 'bdaci-portal-access' ) ) . '</section>';
		$all   = BDACI_PA_Sections::docs( array( 'all_of' => array( $sec, 'minutes' ) ) );
		$years = self::years_of( $all );
		$year  = in_array( self::arg( 'year' ), $years, true ) ? self::arg( 'year' ) : ( $years[0] ?? '' );
		$docs  = array_values( array_filter( $all, static function ( $d ) use ( $year ) { return 0 === strpos( BDACI_PA_Sections::doc_date( $d->ID ), $year ); } ) );
		$arch  = '<section class="panel"><h2>' . esc_html__( 'Approved minutes archive', 'bdaci-portal-access' ) . '</h2>' . ( $years ? self::year_tabs( $years, $year ) : '' ) . self::doc_list( $docs, __( 'No approved minutes yet.', 'bdaci-portal-access' ), 'minutes' ) . '</section>';
		$sub   = $is_fin ? __( 'Committee directory, current agenda and minutes, and approved minutes. Open to Board Members and Finance Committee members.', 'bdaci-portal-access' ) : __( 'Committee directory, current agenda and minutes, and the approved minutes archive.', 'bdaci-portal-access' );
		return BDACI_PA_Portal::head( get_the_title( $post_id ), $sub ) .
			'<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Committee directory', 'bdaci-portal-access' ) . '</h2><span class="muted small">' . self::e( sprintf( /* translators: %d: count */ _n( '%d member', '%d members', count( $people ), 'bdaci-portal-access' ), count( $people ) ) ) . '</span></div>' . self::people( $people ) . '</section><div class="two">' . $cur . $arch . '</div>';
	}

	/**
	 * Admin-made section: its documents.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function section( $post_id ) {
		$id   = (string) get_post_meta( $post_id, BDACI_PA_Sections::META_PAGE, true );
		$menu = (string) get_post_meta( $post_id, BDACI_PA_Portal::META_MENU, true );
		$sub  = sprintf( /* translators: 1: menu, 2: who */ __( '%1$s section · open to %2$s.', 'bdaci-portal-access' ), 'committees' === $menu ? __( 'Committees', 'bdaci-portal-access' ) : __( 'Board', 'bdaci-portal-access' ), BDACI_PA_Sections::who_text( BDACI_PA_Access::level( $post_id ) ) );
		$add  = BDACI_PA_Sections::is_admin( get_current_user_id() ) ? ' <a href="' . esc_url( BDACI_PA_Admin_Portal::to_front( admin_url( 'admin.php?page=bdaci-portal-docs' ) ) ) . '">' . esc_html__( 'Add documents', 'bdaci-portal-access' ) . '</a>' : '';
		$docs = $id ? BDACI_PA_Sections::docs( array( 'section' => $id ) ) : array();
		return BDACI_PA_Portal::head( get_the_title( $post_id ), $sub ) . '<section class="panel">' . ( $docs ? self::doc_list( $docs, '' ) : '<p class="muted">' . esc_html__( 'No documents yet.', 'bdaci-portal-access' ) . $add . '</p>' ) . '</section>';
	}

	/* ---------------------------------------------------------------------
	 * Sign-offs
	 * ------------------------------------------------------------------ */

	/**
	 * Orientation / QA sign-off page.
	 *
	 * @param string $key     Pack.
	 * @param int    $post_id Page.
	 * @return string
	 */
	public static function signoff( $key, $post_id ) {
		$uid   = get_current_user_id();
		$pack  = BDACI_PA_Signoffs::pack( $key );
		$docs  = BDACI_PA_Signoffs::docs( $key, $uid );
		$st    = BDACI_PA_Signoffs::status( $key, $uid );
		$rec   = BDACI_PA_Signoffs::is_recipient( $key, $uid );
		$intro = $pack['intro'] . ( $pack['due'] ? ' ' . sprintf( /* translators: %s: date */ __( 'Due %s.', 'bdaci-portal-access' ), date_i18n( 'F j, Y', strtotime( $pack['due'] ) ) ) : '' );

		$list = '<ul class="list" id="bp-so-docs">';
		foreach ( $docs as $d ) {
			$opened = isset( $st['opened'][ $d->ID ] );
			$file   = (string) get_post_meta( $d->ID, '_wp_attached_file', true );
			$list  .= '<li>' . BDACI_PA_Portal::thumb( get_the_title( $d ), $file, 'qa' === $key ? 'qa' : 'orient' ) . '<div class="grow"><b>' . self::e( get_the_title( $d ) ) . '</b><span class="muted small">' . self::e( BDACI_PA_Portal::doc_when( $d ) ) . '</span></div>' .
				( $rec ? ( $opened ? '<span class="pill pill-ok" data-so-pill>' . esc_html__( 'Opened', 'bdaci-portal-access' ) . '</span>' : '<span class="pill pill-muted" data-so-pill>' . esc_html__( 'Not opened', 'bdaci-portal-access' ) . '</span>' ) : '' ) .
				'<a class="btn btn-sm" data-so-open href="' . esc_url( BDACI_PA_Documents::link( $d->ID ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'bdaci-portal-access' ) . '</a></li>';
		}
		$list .= '</ul>';
		if ( ! $docs ) {
			$list = '<p class="muted">' . esc_html__( 'The documents for this sign-off haven\'t been posted yet.', 'bdaci-portal-access' ) . '</p>';
		}

		$box = '';
		if ( ! $rec ) {
			$box = '<p class="note">' . esc_html__( 'This sign-off isn\'t assigned to you, so there is nothing to sign. You can still read the documents.', 'bdaci-portal-access' ) . '</p>';
		} elseif ( $st['signed'] ) {
			$url  = wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_signoff_receipt&pack=' . $key ), 'bdaci_pa_receipt_' . $key );
			$cc   = $pack['cc'] ? sprintf( /* translators: %s: email */ __( 'A confirmation copy was emailed to %s and to you.', 'bdaci-portal-access' ), $pack['cc'] ) : __( 'A confirmation was emailed to you.', 'bdaci-portal-access' );
			$box  = '<section class="signoff done" aria-live="polite"><h2>' . esc_html__( 'Signed off', 'bdaci-portal-access' ) . '</h2><div class="receipt"><span><b>' . self::e( wp_get_current_user()->display_name ) . '</b> ' . esc_html__( 'confirmed on', 'bdaci-portal-access' ) . ' <b class="tabnum">' . self::e( BDACI_PA_Signoffs::fmt( $st['signed'] ) ) . '</b>.</span><span class="muted">' . esc_html__( 'This is recorded in the portal\'s sign-off audit trail.', 'bdaci-portal-access' ) . '</span><span>' . self::e( $cc ) . '</span>';
			$box .= '<div class="row-btns" style="margin-top:6px"><a class="btn btn-sm" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Export report (PDF)', 'bdaci-portal-access' ) . '</a></div></div></section>';
		} else {
			$read = count( array_intersect( wp_list_pluck( $docs, 'ID' ), array_keys( $st['opened'] ) ) );
			$all  = $docs && $read === count( $docs );
			$pct  = $docs ? round( $read / count( $docs ) * 100 ) : 0;
			$inner = '<div class="panel-h" style="margin:0"><h2>' . esc_html__( 'Action required: digital sign-off', 'bdaci-portal-access' ) . '</h2><span class="small tabnum" data-so-count>' . self::e( sprintf( /* translators: 1: opened, 2: total */ __( '%1$d of %2$d opened', 'bdaci-portal-access' ), $read, count( $docs ) ) ) . '</span></div><div class="progress" aria-hidden="true"><i style="width:' . (int) $pct . '%"></i></div>' .
				'<label class="check"><input type="checkbox" name="ack" value="1" required' . ( $all ? '' : ' disabled' ) . ' data-so-ack> ' . self::e( $pack['statement'] ) . '</label>' .
				( $all ? '' : '<p class="small muted" data-so-hint>' . esc_html__( 'Open each document above to unlock the sign-off.', 'bdaci-portal-access' ) . '</p>' ) .
				'<div><button class="btn btn-primary" type="submit"' . ( $all ? '' : ' disabled' ) . ' data-so-btn>' . esc_html__( 'Sign off', 'bdaci-portal-access' ) . '</button></div>';
			$box = BDACI_PA_Portal::form( 'signoff', array( 'pack' => $key ), $inner, 'class="signoff"' );
		}
		return BDACI_PA_Portal::head( get_the_title( $post_id ), $intro ) . '<section class="panel"><h2>' . esc_html__( 'Documents', 'bdaci-portal-access' ) . '</h2>' . $list . '</section>' . $box;
	}

	/* ---------------------------------------------------------------------
	 * Profile and help
	 * ------------------------------------------------------------------ */

	/**
	 * My profile.
	 *
	 * @return string
	 */
	public static function profile() {
		$uid  = get_current_user_id();
		$user = wp_get_current_user();
		list( $type, $val ) = BDACI_PA_Profile::choice( $uid );
		$using = array(
			'preset'   => __( 'Using a preset picture.', 'bdaci-portal-access' ),
			'upload'   => __( 'Using your uploaded photo.', 'bdaci-portal-access' ),
			'initials' => __( 'Showing your initials.', 'bdaci-portal-access' ),
		);
		$upload = BDACI_PA_Portal::form( 'photo', array( 'mode' => 'upload' ), '<label class="btn btn-primary btn-sm" style="cursor:pointer">' . esc_html__( 'Upload a photo', 'bdaci-portal-access' ) . '<input type="file" name="photo" accept="image/png,image/jpeg" hidden data-autosubmit></label><noscript><button class="btn btn-sm" type="submit">' . esc_html__( 'Upload', 'bdaci-portal-access' ) . '</button></noscript>', 'enctype="multipart/form-data" style="display:inline"' );
		$init   = 'initials' !== $type ? BDACI_PA_Portal::form( 'photo', array( 'mode' => 'initials' ), '<button type="submit" class="btn btn-sm">' . esc_html__( 'Use my initials', 'bdaci-portal-access' ) . '</button>', 'style="display:inline"' ) : '';
		$cur    = '<div class="pf-current">' . BDACI_PA_Profile::avatar( $uid, 'av-xl' ) . '<div style="display:grid;gap:8px;min-width:0"><div><b style="font-size:1.1rem">' . self::e( $user->display_name ) . '</b><br><span class="muted small">' . self::e( BDACI_PA_Portal::role_label( $uid ) ) . '</span></div><p class="small muted" style="margin:0">' . self::e( $using[ $type ] ) . '</p><div class="row-btns">' . $upload . $init . '</div><p class="small muted" style="margin:0">' . esc_html__( 'JPG or PNG, up to 5 MB. Your picture appears in the top bar and on the Leadership and committee pages, and only signed-in portal members can see it.', 'bdaci-portal-access' ) . '</p></div></div>';

		$skins = array( '#f6d3b8', '#eab894', '#d39a6e', '#b07a52', '#8a5a3b', '#5f3d29' );
		$names = array( __( 'Light', 'bdaci-portal-access' ), __( 'Light-medium', 'bdaci-portal-access' ), __( 'Medium', 'bdaci-portal-access' ), __( 'Medium-deep', 'bdaci-portal-access' ), __( 'Deep', 'bdaci-portal-access' ), __( 'Very deep', 'bdaci-portal-access' ) );
		$chip  = function ( $kind, $v, $label, $sw = null ) {
			return '<button type="button" data-pff="' . esc_attr( $kind . '|' . $v ) . '" aria-pressed="' . ( 'all' === $v ? 'true' : 'false' ) . '">' . ( null !== $sw ? '<span class="swatch" style="background:' . esc_attr( $sw ) . '"></span>' : '' ) . $label . '</button>';
		};
		$f = '<div class="pf-filters"><div><span class="eyebrow">' . esc_html__( 'Skin tone', 'bdaci-portal-access' ) . '</span><div class="years">' . $chip( 'skin', 'all', esc_html__( 'All', 'bdaci-portal-access' ) );
		foreach ( $skins as $i => $c ) {
			$f .= $chip( 'skin', (string) $i, '<span class="sr-only">' . self::e( $names[ $i ] ) . '</span>', $c );
		}
		$f .= '</div></div><div><span class="eyebrow">' . esc_html__( 'Hair & head', 'bdaci-portal-access' ) . '</span><div class="years">';
		foreach ( array( 'all' => __( 'All', 'bdaci-portal-access' ), 'short' => __( 'Short', 'bdaci-portal-access' ), 'long' => __( 'Long', 'bdaci-portal-access' ), 'curly' => __( 'Curly', 'bdaci-portal-access' ), 'none' => __( 'No hair', 'bdaci-portal-access' ), 'cover' => __( 'Head covering', 'bdaci-portal-access' ) ) as $k => $l ) {
			$f .= $chip( 'head', $k, self::e( $l ) );
		}
		$f .= '</div></div><div><span class="eyebrow">' . esc_html__( 'Age', 'bdaci-portal-access' ) . '</span><div class="years">';
		foreach ( array( 'all' => __( 'All', 'bdaci-portal-access' ), 'y' => __( 'Younger', 'bdaci-portal-access' ), 'm' => __( 'Middle', 'bdaci-portal-access' ), 'o' => __( 'Older', 'bdaci-portal-access' ) ) as $k => $l ) {
			$f .= $chip( 'age', $k, self::e( $l ) );
		}
		$f   .= '</div></div></div>';
		$grid = '<div class="pf-grid" role="radiogroup" aria-label="' . esc_attr__( 'Preset pictures', 'bdaci-portal-access' ) . '">';
		$n    = 0;
		foreach ( BDACI_PA_Profile::presets() as $id => $p ) {
			++$n;
			$grid .= '<label class="pf-opt" data-skin="' . (int) $p[0] . '" data-head="' . esc_attr( $p[1] ) . '" data-age="' . esc_attr( $p[2] ) . '"><input type="radio" name="preset" value="' . esc_attr( $id ) . '"' . checked( 'preset' === $type && $val === $id, true, false ) . '><img src="' . esc_url( BDACI_PA_URL . 'assets/avatars/' . $id . '.svg' ) . '" alt="' . esc_attr( sprintf( /* translators: %d: number */ __( 'Preset picture %d', 'bdaci-portal-access' ), $n ) ) . '"></label>';
		}
		$grid   .= '</div><p class="muted" data-pf-none hidden>' . esc_html__( 'No pictures match these filters.', 'bdaci-portal-access' ) . '</p>';
		$presets = BDACI_PA_Portal::form( 'photo', array( 'mode' => 'preset' ), $f . $grid . '<div style="margin-top:14px"><button class="btn btn-primary" type="submit">' . esc_html__( 'Use this picture', 'bdaci-portal-access' ) . '</button></div>' );

		$phone   = (string) get_user_meta( $uid, 'bdaci_phone', true );
		$details = BDACI_PA_Portal::form(
			'profile',
			array(),
			'<h2>' . esc_html__( 'Contact details', 'bdaci-portal-access' ) . '</h2><div class="two" style="gap:14px;margin-top:14px"><div class="field"><label for="pf-email">' . esc_html__( 'Email (used to sign in)', 'bdaci-portal-access' ) . '</label><input id="pf-email" value="' . esc_attr( $user->user_email ) . '" disabled></div><div class="field"><label for="pf-phone">' . esc_html__( 'Phone (optional)', 'bdaci-portal-access' ) . '</label><input id="pf-phone" name="phone" value="' . esc_attr( $phone ) . '" placeholder="(613) 555-0100" autocomplete="tel"></div></div>' .
			'<label class="small" style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="show_phone" value="1"' . checked( (bool) get_user_meta( $uid, 'bdaci_show_phone', true ), true, false ) . '> ' . esc_html__( 'Show my phone number to other portal members', 'bdaci-portal-access' ) . '</label><p class="small muted" style="margin-top:8px">' . esc_html__( 'To change your email address, contact the portal administrator.', 'bdaci-portal-access' ) . '</p><div style="margin-top:12px"><button class="btn btn-primary" type="submit">' . esc_html__( 'Save', 'bdaci-portal-access' ) . '</button></div>',
			'class="panel"'
		);
		return BDACI_PA_Portal::head( __( 'My profile', 'bdaci-portal-access' ), __( 'Choose how you appear to other board and committee members.', 'bdaci-portal-access' ) ) .
			'<section class="panel"><h2>' . esc_html__( 'Profile picture', 'bdaci-portal-access' ) . '</h2>' . $cur . '</section>' .
			'<section class="panel" id="bp-presets"><div class="panel-h"><h2>' . esc_html__( 'Or pick a preset picture', 'bdaci-portal-access' ) . '</h2><span class="small muted" data-pf-count>' . self::e( sprintf( /* translators: %d: count */ __( '%d pictures', 'bdaci-portal-access' ), count( BDACI_PA_Profile::presets() ) ) ) . '</span></div>' . $presets . '</section>' . $details;
	}

	/**
	 * Support page.
	 *
	 * @return string
	 */
	public static function help() {
		$topics = array( __( 'I can\'t sign in', 'bdaci-portal-access' ), __( 'A document won\'t open', 'bdaci-portal-access' ), __( 'I need access to a section', 'bdaci-portal-access' ), __( 'Something else', 'bdaci-portal-access' ) );
		$opts   = '';
		foreach ( $topics as $t ) {
			$opts .= '<option>' . self::e( $t ) . '</option>';
		}
		$form  = BDACI_PA_Portal::form(
			'help',
			array(),
			'<h2>' . esc_html__( 'Contact portal support', 'bdaci-portal-access' ) . '</h2><div class="field"><label for="h-topic">' . esc_html__( 'What do you need help with?', 'bdaci-portal-access' ) . '</label><select id="h-topic" name="topic">' . $opts . '</select></div><div class="field"><label for="h-msg">' . esc_html__( 'Message', 'bdaci-portal-access' ) . '</label><textarea id="h-msg" name="message" required placeholder="' . esc_attr__( 'Tell us what happened and what you were trying to do.', 'bdaci-portal-access' ) . '"></textarea></div><div><button class="btn btn-primary" type="submit">' . esc_html__( 'Send message', 'bdaci-portal-access' ) . '</button></div>',
			'class="panel" style="display:grid;gap:14px"'
		);
		$email = BDACI_PA_Settings::support_email();
		$faq   = '<section class="panel"><h2>' . esc_html__( 'Common questions', 'bdaci-portal-access' ) . '</h2><ul class="list">' .
			'<li><div class="grow"><b>' . esc_html__( 'I forgot my password', 'bdaci-portal-access' ) . '</b><span class="muted small">' . esc_html__( 'Use "Forgot your password?" on the sign-in page. The reset link arrives by email.', 'bdaci-portal-access' ) . '</span></div></li>' .
			'<li><div class="grow"><b>' . esc_html__( 'I didn\'t get my verification code', 'bdaci-portal-access' ) . '</b><span class="muted small">' . esc_html__( 'Check your junk folder, then choose "Send a new code". Codes expire after 10 minutes.', 'bdaci-portal-access' ) . '</span></div></li>' .
			'<li><div class="grow"><b>' . esc_html__( 'A PDF opens as a download', 'bdaci-portal-access' ) . '</b><span class="muted small">' . esc_html__( 'Your browser settings decide this. In Chrome or Edge, PDFs open in a new tab by default.', 'bdaci-portal-access' ) . '</span></div></li>' .
			'<li><div class="grow"><b>' . esc_html__( 'Office contact', 'bdaci-portal-access' ) . '</b><span class="muted small"><a href="mailto:' . esc_attr( $email ) . '">' . self::e( $email ) . '</a></span></div></li></ul></section>';
		return BDACI_PA_Portal::head( __( 'Support & Portal Help', 'bdaci-portal-access' ), __( 'Having trouble? Send a message and the BDACI office will get back to you.', 'bdaci-portal-access' ) ) . '<div class="two">' . $form . $faq . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Restricted
	 * ------------------------------------------------------------------ */

	/**
	 * Panel for a page the person can't open.
	 *
	 * @param int    $post_id Page.
	 * @param string $screen  Screen.
	 * @return string
	 */
	public static function restricted( $post_id, $screen ) {
		$title = get_the_title( $post_id );
		$lock  = '<span style="color:var(--muted)">' . BDACI_PA_Portal::icon( 'lock', 40 ) . '</span>';
		$areas = BDACI_PA_Requests::areas();
		if ( isset( $areas[ $screen ] ) ) {
			$req = BDACI_PA_Requests::pending_for( get_current_user_id(), $screen );
			if ( $req ) {
				$inner = '<span class="pill pill-warn">' . esc_html__( 'Request pending', 'bdaci-portal-access' ) . '</span><h2>' . esc_html__( 'Your request has been sent', 'bdaci-portal-access' ) . '</h2><p class="muted" style="max-width:52ch">' . self::e( sprintf( /* translators: %s: date */ __( 'You asked for access on %s. The portal administrator will review it, and you\'ll get an email as soon as it\'s approved.', 'bdaci-portal-access' ), BDACI_PA_Signoffs::fmt( $req->created_at ) ) ) . '</p>' .
					( $req->reason ? '<p class="small" style="max-width:52ch"><span class="muted">' . esc_html__( 'Your note:', 'bdaci-portal-access' ) . '</span> “' . self::e( $req->reason ) . '”</p>' : '' ) .
					BDACI_PA_Portal::form( 'request_cancel', array( 'area' => $screen ), '<button type="submit" class="linkbtn">' . esc_html__( 'Cancel request', 'bdaci-portal-access' ) . '</button>' );
			} else {
				$inner = $lock . '<h2>' . self::e( sprintf( /* translators: %s: section */ __( 'The %s section is for committee members', 'bdaci-portal-access' ), $areas[ $screen ][0] ) ) . '</h2><p class="muted" style="max-width:52ch">' . esc_html__( 'Your account doesn\'t include this section yet. If you sit on the committee or need to see its documents, ask for access and the portal administrator will review your request. Once approved, your access stays in place until a Portal Admin removes it.', 'bdaci-portal-access' ) . '</p>' .
					BDACI_PA_Portal::form( 'request', array( 'area' => $screen ), '<div class="field"><label for="req-reason">' . esc_html__( 'Why do you need access?', 'bdaci-portal-access' ) . ' <span class="muted" style="font-weight:400">' . esc_html__( '(optional)', 'bdaci-portal-access' ) . '</span></label><textarea id="req-reason" name="reason" maxlength="1000"></textarea></div><div class="row-btns" style="justify-content:center"><button class="btn btn-primary" type="submit">' . esc_html__( 'Request access', 'bdaci-portal-access' ) . '</button></div>', 'class="req-form"' );
			}
		} else {
			$inner = $lock . '<h2>' . self::e( $title ) . '</h2><p class="muted" style="max-width:52ch">' . self::e( sprintf( /* translators: %s: who */ __( 'This section is open to %s. Ask the portal administrator if you need access.', 'bdaci-portal-access' ), BDACI_PA_Sections::who_text( BDACI_PA_Access::level( $post_id ) ) ) ) . '</p>';
		}
		return BDACI_PA_Portal::head( $title ) . '<div class="panel restricted">' . $inner . '</div>';
	}
}
