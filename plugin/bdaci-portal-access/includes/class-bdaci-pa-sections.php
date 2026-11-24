<?php
/**
 * Document sections (folders) and "who can open it" for private documents.
 *
 * A private document can sit in several sections (Agenda, Minutes, Education Committee, ...).
 * Who can open it is stored in the document's access level, which may combine groups with "+",
 * for example "board+committee".
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sections registry and document queries.
 */
class BDACI_PA_Sections {

	const OPT_CUSTOM = 'bdaci_pa_sections';
	const OPT_HIDDEN = 'bdaci_pa_hidden_folders';
	const META_SECS  = '_bdaci_sections';
	const META_DATE  = '_bdaci_doc_date';
	const META_PAGE  = '_bdaci_section';

	/**
	 * Built-in sections.
	 *
	 * @return array id => [label, group, colour, icon]
	 */
	public static function builtin() {
		return array(
			'agenda'      => array( __( 'Agenda', 'bdaci-portal-access' ), 'Meetings', 'board', 'cal' ),
			'package'     => array( __( 'Meeting package', 'bdaci-portal-access' ), 'Meetings', 'board', 'box' ),
			'minutes'     => array( __( 'Minutes', 'bdaci-portal-access' ), 'Meetings', 'board', 'pen' ),
			'bylaws'      => array( __( 'Bylaws', 'bdaci-portal-access' ), 'Governance', 'other', 'book' ),
			'policies'    => array( __( 'Board Policies', 'bdaci-portal-access' ), 'Governance', 'other', 'shield' ),
			'strategic'   => array( __( 'Strategic Plan', 'bdaci-portal-access' ), 'Governance', 'other', 'star' ),
			'reports'     => array( __( 'Financial Reports', 'bdaci-portal-access' ), 'Governance', 'fin', 'coin' ),
			'orientation' => array( __( 'New Director Orientation', 'bdaci-portal-access' ), 'Sign-off', 'fin', 'star' ),
			'qa'          => array( __( 'Quality Assurance', 'bdaci-portal-access' ), 'Sign-off', 'fin', 'check' ),
			'edu'         => array( __( 'Education Committee', 'bdaci-portal-access' ), 'Committees', 'edu', 'grad' ),
			'fincom'      => array( __( 'Finance Committee', 'bdaci-portal-access' ), 'Committees', 'fin', 'coin' ),
		);
	}

	/**
	 * Places in the portal that a section can appear: key => [menu (top|board|committees|home), short label].
	 *
	 * @return array
	 */
	public static function places() {
		$p = array(
			'home'        => array( 'top', __( 'Home', 'bdaci-portal-access' ) ),
			'calendar'    => array( 'top', __( 'Calendar', 'bdaci-portal-access' ) ),
			'meetings'    => array( 'top', __( 'Meetings', 'bdaci-portal-access' ) ),
			'support'     => array( 'top', __( 'Support', 'bdaci-portal-access' ) ),
			'leadership'  => array( 'board', __( 'Leadership', 'bdaci-portal-access' ) ),
			'governance'  => array( 'board', __( 'Governance', 'bdaci-portal-access' ) ),
			'orientation' => array( 'board', __( 'Orientation', 'bdaci-portal-access' ) ),
			'qa'          => array( 'board', __( 'Quality Assurance', 'bdaci-portal-access' ) ),
			'education'   => array( 'committees', __( 'Education', 'bdaci-portal-access' ) ),
			'finance'     => array( 'committees', __( 'Finance', 'bdaci-portal-access' ) ),
		);
		$board = array();
		$coms  = array();
		foreach ( self::custom() as $id => $c ) {
			if ( 'committees' === $c['menu'] ) {
				$coms[ $id ] = array( 'committees', $c['label'] );
			} else {
				$board[ $id ] = array( 'board', $c['label'] );
			}
		}
		// Keep added sections next to their menu group.
		$out = array();
		foreach ( $p as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'qa' === $k ) {
				$out += $board;
			}
		}
		return $out + $coms;
	}

	/**
	 * Where a section's documents appear: list of [place key, menu path, spot on the page].
	 *
	 * @param string $id Section.
	 * @return array
	 */
	public static function where( $id ) {
		$top  = __( 'Top menu', 'bdaci-portal-access' );
		$side = __( 'Side menu', 'bdaci-portal-access' );
		$gov  = $side . ' › ' . __( 'Board', 'bdaci-portal-access' ) . ' › ' . __( 'Governance & Core Documents', 'bdaci-portal-access' );
		$meet = $top . ' › ' . __( 'Meetings', 'bdaci-portal-access' ) . ' / ' . __( 'Calendar', 'bdaci-portal-access' );
		$home = array( 'home', $top . ' › ' . __( 'Home', 'bdaci-portal-access' ), __( 'the "Next meeting" card', 'bdaci-portal-access' ) );
		switch ( $id ) {
			case 'agenda':
				return array( array( 'meetings', $meet, __( '"Agenda" button on the meeting you attach it to', 'bdaci-portal-access' ) ), $home );
			case 'package':
				return array( array( 'meetings', $meet, __( '"Meeting package" button on the meeting you attach it to', 'bdaci-portal-access' ) ), $home );
			case 'minutes':
				return array(
					array( 'meetings', $meet, __( '"Minutes" button on the meeting you attach it to', 'bdaci-portal-access' ) ),
					array( 'governance', $gov, __( 'Previous Meeting Minutes folder, sorted by year (committee minutes go to the committee page instead)', 'bdaci-portal-access' ) ),
				);
			case 'bylaws':
				return array( array( 'governance', $gov, __( 'Bylaws folder', 'bdaci-portal-access' ) ) );
			case 'policies':
				return array( array( 'governance', $gov, __( 'Board Policies folder', 'bdaci-portal-access' ) ) );
			case 'strategic':
				return array( array( 'governance', $gov, self::is_hidden( 'strategic' ) ? __( 'Strategic Plan folder (hidden from members until you show it)', 'bdaci-portal-access' ) : __( 'Strategic Plan folder', 'bdaci-portal-access' ) ) );
			case 'reports':
				return array( array( 'governance', $gov, __( 'Financial Reports folder', 'bdaci-portal-access' ) ) );
			case 'orientation':
				return array( array( 'orientation', $side . ' › ' . __( 'Board', 'bdaci-portal-access' ) . ' › ' . __( 'New Director Orientation', 'bdaci-portal-access' ), __( 'documents to read before signing off', 'bdaci-portal-access' ) ) );
			case 'qa':
				return array( array( 'qa', $side . ' › ' . __( 'Board', 'bdaci-portal-access' ) . ' › ' . __( 'Quality Assurance', 'bdaci-portal-access' ), __( 'documents to read before signing off', 'bdaci-portal-access' ) ) );
			case 'edu':
				return array( array( 'education', $side . ' › ' . __( 'Committees', 'bdaci-portal-access' ) . ' › ' . __( 'Education Committee', 'bdaci-portal-access' ), __( '"Current agenda & minutes" (tick Minutes too to file it in the approved minutes archive)', 'bdaci-portal-access' ) ) );
			case 'fincom':
				return array( array( 'finance', $side . ' › ' . __( 'Committees', 'bdaci-portal-access' ) . ' › ' . __( 'Finance Committee', 'bdaci-portal-access' ), __( '"Current agenda & minutes" (tick Minutes too to file it in the approved minutes archive)', 'bdaci-portal-access' ) ) );
		}
		$c = self::custom();
		if ( isset( $c[ $id ] ) ) {
			$menu = 'committees' === $c[ $id ]['menu'] ? __( 'Committees', 'bdaci-portal-access' ) : __( 'Board', 'bdaci-portal-access' );
			return array( array( $id, $side . ' › ' . $menu . ' › ' . $c[ $id ]['label'], __( 'the section\'s document list', 'bdaci-portal-access' ) ) );
		}
		return array();
	}

	/**
	 * Short name of the spot on the page where a section's files show (for the page maps).
	 *
	 * @param string $id Section.
	 * @return string
	 */
	public static function spot( $id ) {
		$s = array(
			'agenda'      => __( '"Agenda" button on each meeting', 'bdaci-portal-access' ),
			'package'     => __( '"Meeting package" button on each meeting', 'bdaci-portal-access' ),
			'minutes'     => __( '"Minutes" button + Previous Meeting Minutes folder', 'bdaci-portal-access' ),
			'bylaws'      => __( 'Bylaws folder', 'bdaci-portal-access' ),
			'policies'    => __( 'Board Policies folder', 'bdaci-portal-access' ),
			'strategic'   => __( 'Strategic Plan folder', 'bdaci-portal-access' ),
			'reports'     => __( 'Financial Reports folder', 'bdaci-portal-access' ),
			'orientation' => __( 'Orientation: documents to read and sign', 'bdaci-portal-access' ),
			'qa'          => __( 'Quality Assurance: documents to read and sign', 'bdaci-portal-access' ),
			'edu'         => __( 'Education Committee: current agenda & minutes', 'bdaci-portal-access' ),
			'fincom'      => __( 'Finance Committee: current agenda & minutes', 'bdaci-portal-access' ),
		);
		if ( isset( $s[ $id ] ) ) {
			return $s[ $id ];
		}
		$c = self::custom();
		/* translators: %s: section name */
		return isset( $c[ $id ] ) ? sprintf( __( '%s: document list', 'bdaci-portal-access' ), $c[ $id ]['label'] ) : $id;
	}

	/**
	 * Sections grouped by where they appear: key => [title, explanation, section ids, places to highlight].
	 *
	 * @return array
	 */
	public static function groups() {
		$custom = array_keys( self::custom() );
		return array(
			'meetings'   => array( __( 'Meeting documents', 'bdaci-portal-access' ), __( 'Top menu › Meetings and Calendar, and the Home page. They appear on a meeting once you attach them on the Meetings screen.', 'bdaci-portal-access' ), array( 'agenda', 'package', 'minutes' ), array( 'meetings', 'calendar', 'home' ) ),
			'governance' => array( __( 'Governance & Core Documents', 'bdaci-portal-access' ), __( 'Side menu › Board › Governance & Core Documents. Each is a folder on that page.', 'bdaci-portal-access' ), array( 'bylaws', 'policies', 'strategic', 'reports' ), array( 'governance' ) ),
			'signoff'    => array( __( 'Board sign-offs', 'bdaci-portal-access' ), __( 'Side menu › Board › New Director Orientation or Quality Assurance. Board members open each one before they can sign off.', 'bdaci-portal-access' ), array( 'orientation', 'qa' ), array( 'orientation', 'qa' ) ),
			'committees' => array( __( 'Committee pages', 'bdaci-portal-access' ), __( 'Side menu › Committees › Education or Finance Committee, under "Current agenda & minutes".', 'bdaci-portal-access' ), array( 'edu', 'fincom' ), array( 'education', 'finance' ) ),
			'added'      => array( __( 'Sections you added', 'bdaci-portal-access' ), __( 'Each has its own page in the side menu, under Board or Committees.', 'bdaci-portal-access' ), $custom, $custom ),
		);
	}

	/**
	 * Sections added by admins.
	 *
	 * @return array id => [label, menu, level, page_id]
	 */
	public static function custom() {
		$c = get_option( self::OPT_CUSTOM, array() );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Every section, built-in first.
	 *
	 * @return array id => [label, group, colour, icon, custom(bool)]
	 */
	public static function all() {
		$out = array();
		foreach ( self::builtin() as $id => $s ) {
			$out[ $id ] = array( $s[0], $s[1], $s[2], $s[3], false );
		}
		foreach ( self::custom() as $id => $s ) {
			$out[ $id ] = array( $s['label'], 'board' === $s['menu'] ? 'Board' : 'Committees', 'board' === $s['menu'] ? 'board' : 'edu', 'folder', true );
		}
		return $out;
	}

	/**
	 * Section label.
	 *
	 * @param string $id Section.
	 * @return string
	 */
	public static function label( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ][0] : $id;
	}

	/**
	 * "Who can open it" choices.
	 *
	 * @return array key => [label, sub, colour, icon]
	 */
	public static function who_options() {
		return array(
			'all'       => array( __( 'All portal users', 'bdaci-portal-access' ), __( 'Everyone who can sign in', 'bdaci-portal-access' ), 'other', 'people' ),
			'board'     => array( __( 'Board Members', 'bdaci-portal-access' ), __( 'Directors only', 'bdaci-portal-access' ), 'board', 'gavel' ),
			'committee' => array( __( 'Education Committee', 'bdaci-portal-access' ), __( 'Committee members', 'bdaci-portal-access' ), 'edu', 'grad' ),
			'finance'   => array( __( 'Finance Committee', 'bdaci-portal-access' ), __( 'Committee members, plus Board Members', 'bdaci-portal-access' ), 'fin', 'coin' ),
		);
	}

	/**
	 * Turn a list of "who" keys into an access level string.
	 *
	 * @param array $who Keys.
	 * @return string
	 */
	public static function who_to_level( array $who ) {
		$who = array_values( array_intersect( array( 'all', 'board', 'committee', 'finance' ), array_map( 'sanitize_key', $who ) ) );
		if ( ! $who || in_array( 'all', $who, true ) ) {
			return 'portal';
		}
		return implode( '+', $who );
	}

	/**
	 * Level string to "who" keys.
	 *
	 * @param string $level Level.
	 * @return array
	 */
	public static function level_to_who( $level ) {
		if ( '' === $level || 'portal' === $level || 'public' === $level ) {
			return array( 'all' );
		}
		return array_values( array_intersect( array( 'board', 'committee', 'finance' ), explode( '+', $level ) ) );
	}

	/**
	 * Plain-language "who".
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function who_text( $level ) {
		$opts = self::who_options();
		$who  = self::level_to_who( $level );
		if ( in_array( 'all', $who, true ) ) {
			return $opts['all'][0];
		}
		return implode( ' + ', array_map( static function ( $w ) use ( $opts ) { return $opts[ $w ][0]; }, $who ) );
	}

	/**
	 * Hidden folders (sections hidden from everyone but admins).
	 *
	 * @return array
	 */
	public static function hidden_folders() {
		$h = get_option( self::OPT_HIDDEN, null );
		return is_array( $h ) ? $h : array( 'strategic' );
	}

	/**
	 * Whether a folder is hidden.
	 *
	 * @param string $id Section.
	 * @return bool
	 */
	public static function is_hidden( $id ) {
		return in_array( $id, self::hidden_folders(), true );
	}

	/**
	 * Hide or show a folder.
	 *
	 * @param string $id   Section.
	 * @param bool   $hide Hide.
	 */
	public static function set_hidden( $id, $hide ) {
		$h = array_values( array_diff( self::hidden_folders(), array( $id ) ) );
		if ( $hide ) {
			$h[] = $id;
		}
		update_option( self::OPT_HIDDEN, $h, false );
		BDACI_PA_Log::add( $hide ? 'section_hidden' : 'section_shown', '', get_current_user_id(), self::label( $id ) . ' (folder)' );
	}

	/**
	 * Sections of a document.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	public static function doc_sections( $id ) {
		$raw = (string) get_post_meta( $id, self::META_SECS, true );
		return '' === $raw ? array() : array_values( array_filter( explode( ',', $raw ) ) );
	}

	/**
	 * Save a document's sections.
	 *
	 * @param int   $id   Attachment ID.
	 * @param array $secs Sections.
	 */
	public static function set_doc_sections( $id, array $secs ) {
		$valid = array_keys( self::all() );
		$secs  = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $secs ), $valid ) ) );
		update_post_meta( $id, self::META_SECS, implode( ',', $secs ) );
	}

	/**
	 * Document date (Y-m-d): the date the document is about, defaulting to the upload date.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function doc_date( $id ) {
		$d = (string) get_post_meta( $id, self::META_DATE, true );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : get_the_date( 'Y-m-d', $id );
	}

	/**
	 * Whether a user can open a document (its level, and not only in hidden folders).
	 *
	 * @param int $id      Attachment ID.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function can_open( $id, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! BDACI_PA_Access::can_view( $id, $user_id ) ) {
			return false;
		}
		$secs = self::doc_sections( $id );
		if ( $secs && ! array_diff( $secs, self::hidden_folders() ) && ! self::is_admin( $user_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Portal admin check.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_admin( $user_id ) {
		return $user_id && ( user_can( $user_id, 'bdaci_manage_portal' ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * Private documents a user can open, optionally limited to sections.
	 *
	 * @param array $args section (string|array, any of), all_of (array), exclude (array), user_id, limit, year.
	 * @return WP_Post[]
	 */
	public static function docs( array $args = array() ) {
		$args    = wp_parse_args(
			$args,
			array(
				'section' => array(),
				'all_of'  => array(),
				'exclude' => array(),
				'user_id' => get_current_user_id(),
				'limit'   => 0,
				'year'    => '',
				'check'   => true,
			)
		);
		$any     = (array) $args['section'];
		$out     = array();
		foreach ( BDACI_PA_Documents::all() as $doc ) {
			$secs = self::doc_sections( $doc->ID );
			if ( $any && ! array_intersect( $any, $secs ) ) {
				continue;
			}
			if ( $args['all_of'] && array_diff( (array) $args['all_of'], $secs ) ) {
				continue;
			}
			if ( $args['exclude'] && array_intersect( (array) $args['exclude'], $secs ) ) {
				continue;
			}
			if ( $args['year'] && 0 !== strpos( self::doc_date( $doc->ID ), (string) $args['year'] ) ) {
				continue;
			}
			if ( $args['check'] && ! self::can_open( $doc->ID, (int) $args['user_id'] ) ) {
				continue;
			}
			$out[] = $doc;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				$c = strcmp( self::doc_date( $b->ID ), self::doc_date( $a->ID ) );
				return $c ? $c : strcmp( $b->post_date, $a->post_date );
			}
		);
		return $args['limit'] ? array_slice( $out, 0, (int) $args['limit'] ) : $out;
	}

	/**
	 * Create an admin-defined section: a portal page in the Board or Committees menu.
	 *
	 * @param string $label Name.
	 * @param string $menu  board|committees.
	 * @param array  $who   Who keys.
	 * @return string|WP_Error Section ID.
	 */
	public static function create_custom( $label, $menu, array $who ) {
		$label = trim( wp_strip_all_tags( $label ) );
		if ( '' === $label ) {
			return new WP_Error( 'bdaci_name', __( 'Give the section a name.', 'bdaci-portal-access' ) );
		}
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		if ( ! $root ) {
			return new WP_Error( 'bdaci_root', __( 'Set up the portal pages first.', 'bdaci-portal-access' ) );
		}
		$menu  = 'committees' === $menu ? 'committees' : 'board';
		$level = self::who_to_level( $who );
		$id    = 'c-' . substr( sanitize_title( $label ), 0, 30 ) . '-' . strtolower( wp_generate_password( 4, false, false ) );
		$page  = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $label,
				'post_parent'  => $root,
				'post_content' => '',
				'menu_order'   => 'board' === $menu ? 60 : 90,
			),
			true
		);
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		update_post_meta( $page, BDACI_PA_Portal::META_SCREEN, 'section' );
		update_post_meta( $page, BDACI_PA_Portal::META_MENU, $menu );
		update_post_meta( $page, self::META_PAGE, $id );
		update_post_meta( $page, BDACI_PA_Access::META, $level );
		$custom        = self::custom();
		$custom[ $id ] = array(
			'label'   => $label,
			'menu'    => $menu,
			'level'   => $level,
			'page_id' => (int) $page,
		);
		update_option( self::OPT_CUSTOM, $custom, false );
		BDACI_PA_Access::flush_cache();
		BDACI_PA_Portal::flush_screens();
		BDACI_PA_Log::add( 'section_created', '', get_current_user_id(), $label . ' (' . $level . ')' );
		return $id;
	}

	/**
	 * Remove an admin-defined section (its page goes to the trash; documents are kept).
	 *
	 * @param string $id Section.
	 */
	public static function delete_custom( $id ) {
		$custom = self::custom();
		if ( ! isset( $custom[ $id ] ) ) {
			return;
		}
		if ( ! empty( $custom[ $id ]['page_id'] ) ) {
			wp_trash_post( (int) $custom[ $id ]['page_id'] );
		}
		BDACI_PA_Log::add( 'section_removed', '', get_current_user_id(), $custom[ $id ]['label'] );
		unset( $custom[ $id ] );
		update_option( self::OPT_CUSTOM, $custom, false );
		BDACI_PA_Access::flush_cache();
		BDACI_PA_Portal::flush_screens();
	}
}
