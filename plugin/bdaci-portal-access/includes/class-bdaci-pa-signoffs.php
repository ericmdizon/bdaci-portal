<?php
/**
 * Digital sign-offs (New Director Orientation, Quality Assurance) and their audit trail.
 *
 * Each sign-off ("pack") has documents (the private documents in its section), the people who must
 * sign, an optional due date and an optional copy address. Every step is an event: sent, reminded,
 * opened (per document) and signed. "Start a new round" (for the yearly QA sign-off) starts a new
 * cycle; older events stay in the log but no longer count.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sign-offs.
 */
class BDACI_PA_Signoffs {

	const OPT = 'bdaci_pa_signoffs';

	/** Pseudo pack for single-document sign-offs (events carry the document ID). */
	const DOC = 'doc';

	/** Document meta: ask people to sign off after reading. */
	const META_NEEDS = '_bdaci_signoff';

	/** Document meta: the statement people confirm (optional). */
	const META_STATEMENT = '_bdaci_signoff_statement';

	/**
	 * Per-request cache of events.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Events table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_signoff_events';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pack varchar(32) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				event varchar(16) NOT NULL,
				doc_id bigint(20) unsigned NOT NULL DEFAULT 0,
				by_user bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				detail mediumtext NULL,
				PRIMARY KEY  (id),
				KEY pack_user (pack,user_id),
				KEY created_at (created_at)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bdaci_pa_document_opened', array( __CLASS__, 'record_open' ), 10, 2 );
		add_action( 'admin_post_bdaci_pa_signoff_receipt', array( __CLASS__, 'receipt' ) );
	}

	/* ---------------------------------------------------------------------
	 * Single-document sign-offs
	 * ------------------------------------------------------------------ */

	/**
	 * Does this document ask readers to sign off?
	 *
	 * @param int $doc_id Document.
	 * @return bool
	 */
	public static function doc_needs( $doc_id ) {
		return (bool) get_post_meta( $doc_id, self::META_NEEDS, true );
	}

	/**
	 * Statement people confirm for a document.
	 *
	 * @param int $doc_id Document.
	 * @return string
	 */
	public static function doc_statement( $doc_id ) {
		$s = trim( (string) get_post_meta( $doc_id, self::META_STATEMENT, true ) );
		/* translators: %s: document title */
		return '' !== $s ? $s : sprintf( __( 'I have read and understood "%s".', 'bdaci-portal-access' ), get_the_title( $doc_id ) );
	}

	/**
	 * Turn the sign-off request on or off for a document.
	 *
	 * @param int    $doc_id    Document.
	 * @param bool   $on        On.
	 * @param string $statement Statement ('' = default).
	 */
	public static function set_doc_needs( $doc_id, $on, $statement = '' ) {
		if ( $on ) {
			update_post_meta( $doc_id, self::META_NEEDS, 1 );
		} else {
			delete_post_meta( $doc_id, self::META_NEEDS );
		}
		$statement = trim( sanitize_text_field( $statement ) );
		if ( '' !== $statement ) {
			update_post_meta( $doc_id, self::META_STATEMENT, mb_substr( $statement, 0, 300 ) );
		} else {
			delete_post_meta( $doc_id, self::META_STATEMENT );
		}
	}

	/**
	 * A person's status for one document: opened and signed times, and the signature.
	 *
	 * @param int $doc_id  Document.
	 * @param int $user_id User.
	 * @return array opened, signed, detail
	 */
	public static function doc_status( $doc_id, $user_id ) {
		$s = array(
			'opened' => '',
			'signed' => '',
			'detail' => array(),
		);
		foreach ( self::events( self::DOC ) as $e ) {
			if ( (int) $e->doc_id !== (int) $doc_id || (int) $e->user_id !== (int) $user_id ) {
				continue;
			}
			if ( 'opened' === $e->event && ! $s['opened'] ) {
				$s['opened'] = $e->created_at;
			} elseif ( 'signed' === $e->event && ! $s['signed'] ) {
				$s['signed'] = $e->created_at;
				$s['detail'] = self::detail( $e );
			}
		}
		return $s;
	}

	/**
	 * People who are asked to sign a document: active portal members who can open it.
	 *
	 * @param int $doc_id Document.
	 * @return WP_User[]
	 */
	public static function doc_recipients( $doc_id ) {
		$out = array();
		foreach ( BDACI_PA_Members::all() as $row ) {
			if ( BDACI_PA_Members::ACTIVE !== $row->status ) {
				continue;
			}
			$u = $row->user_id ? get_userdata( $row->user_id ) : get_user_by( 'email', $row->email );
			if ( $u && ! isset( $out[ $u->ID ] ) && BDACI_PA_Sections::can_open( $doc_id, $u->ID ) ) {
				$out[ $u->ID ] = $u;
			}
		}
		uasort( $out, static function ( $a, $b ) { return strcasecmp( $a->display_name, $b->display_name ); } );
		return array_values( $out );
	}

	/**
	 * Does this person still have to sign this document?
	 *
	 * @param int $doc_id  Document.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function doc_needs_action( $doc_id, $user_id ) {
		return $user_id && self::doc_needs( $doc_id ) && BDACI_PA_Members::is_authorized( $user_id ) && BDACI_PA_Sections::can_open( $doc_id, $user_id ) && ! self::doc_status( $doc_id, $user_id )['signed'];
	}

	/**
	 * Documents that ask for a sign-off.
	 *
	 * @return WP_Post[]
	 */
	public static function sign_docs() {
		return get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'numberposts'      => 200,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'meta_key'         => self::META_NEEDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
				'bdaci_pa_all'     => true,
			)
		);
	}

	/**
	 * Documents this person still has to sign.
	 *
	 * @param int $user_id User.
	 * @return WP_Post[]
	 */
	public static function docs_to_sign( $user_id ) {
		return array_values(
			array_filter(
				self::sign_docs(),
				static function ( $d ) use ( $user_id ) {
					return self::doc_needs_action( $d->ID, $user_id );
				}
			)
		);
	}

	/**
	 * Signature sent with a sign-off form: typed name or a drawn picture.
	 *
	 * @param string $statement The statement confirmed.
	 * @return array|WP_Error
	 */
	public static function signature_from_post( $statement ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		$how  = isset( $_POST['sig_how'] ) ? sanitize_key( wp_unslash( $_POST['sig_how'] ) ) : '';
		$name = isset( $_POST['sig_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sig_name'] ) ) ) : '';
		$img  = isset( $_POST['sig_img'] ) ? trim( (string) wp_unslash( $_POST['sig_img'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded and checked as a PNG below.
		// phpcs:enable
		$user = wp_get_current_user();
		$out  = array(
			'how'       => 'drawn' === $how ? 'drawn' : 'typed',
			'name'      => mb_substr( $name, 0, 80 ),
			'statement' => $statement,
			'ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		);
		if ( 'drawn' === $out['how'] ) {
			if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $img, $m ) || strlen( $m[1] ) > 400000 ) {
				return new WP_Error( 'sig', __( 'Draw your signature in the box, or switch to Type and type your name.', 'bdaci-portal-access' ) );
			}
			$bin  = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$info = $bin ? @getimagesizefromstring( $bin ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] > 2000 || $info[1] > 1000 || strlen( $bin ) < 200 ) {
				return new WP_Error( 'sig', __( 'Draw your signature in the box, or switch to Type and type your name.', 'bdaci-portal-access' ) );
			}
			$out['img'] = 'data:image/png;base64,' . base64_encode( $bin ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$out['name'] = $user->display_name; // A drawn signature is always in the signed-in person's name.
		} elseif ( mb_strlen( $out['name'] ) < 2 ) {
			return new WP_Error( 'sig', __( 'Type your full name as your signature.', 'bdaci-portal-access' ) );
		}
		return $out;
	}

	/**
	 * Signature details stored with a "signed" event.
	 *
	 * @param object $e Event row.
	 * @return array
	 */
	public static function detail( $e ) {
		$d = isset( $e->detail ) && $e->detail ? json_decode( (string) $e->detail, true ) : array();
		return is_array( $d ) ? $d : array();
	}

	/**
	 * A signature for display: the drawn picture or the typed name in script.
	 *
	 * @param array  $d     Detail.
	 * @param string $class Extra class.
	 * @return string HTML.
	 */
	public static function signature_html( array $d, $class = '' ) {
		if ( ! $d ) {
			return '';
		}
		if ( 'drawn' === ( $d['how'] ?? '' ) && ! empty( $d['img'] ) && 0 === strpos( $d['img'], 'data:image/png;base64,' ) ) {
			return '<img class="sig-img ' . esc_attr( $class ) . '" src="' . esc_attr( $d['img'] ) . '" alt="' . esc_attr( sprintf( /* translators: %s: name */ __( 'Signature of %s', 'bdaci-portal-access' ), $d['name'] ?? '' ) ) . '">';
		}
		return '<span class="sig-typed ' . esc_attr( $class ) . '" title="' . esc_attr__( 'Typed signature', 'bdaci-portal-access' ) . '">' . esc_html( $d['name'] ?? '' ) . '</span>';
	}

	/**
	 * Signature fields for a sign-off form (typed or drawn).
	 *
	 * @param string $id_prefix Unique id prefix.
	 * @return string HTML.
	 */
	public static function sig_fields( $id_prefix ) {
		$u    = wp_get_current_user();
		$id   = esc_attr( $id_prefix );
		$full = trim( get_user_meta( $u->ID, 'first_name', true ) . ' ' . get_user_meta( $u->ID, 'last_name', true ) );
		if ( '' === $full ) {
			$full = $u->display_name;
		}
		return '<fieldset class="sig" data-sig><legend>' . esc_html__( 'Your signature', 'bdaci-portal-access' ) . '</legend>' .
			'<div class="sig-tabs" role="group" aria-label="' . esc_attr__( 'How to sign', 'bdaci-portal-access' ) . '"><button type="button" class="btn btn-sm" data-sig-mode="typed" aria-pressed="true">' . esc_html__( 'Type', 'bdaci-portal-access' ) . '</button><button type="button" class="btn btn-sm" data-sig-mode="drawn" aria-pressed="false">' . esc_html__( 'Draw', 'bdaci-portal-access' ) . '</button></div>' .
			'<div data-sig-pane="typed"><label class="small" for="' . $id . '-name">' . esc_html__( 'Your name as it will appear (you can change it)', 'bdaci-portal-access' ) . '</label><div class="sig-namerow"><input id="' . $id . '-name" name="sig_name" autocomplete="name" maxlength="80" value="' . esc_attr( $full ) . '" placeholder="' . esc_attr( $full ) . '"><button type="button" class="btn btn-sm" data-sig-reset data-name="' . esc_attr( $full ) . '" hidden>' . esc_html__( 'Use my name', 'bdaci-portal-access' ) . '</button></div><div class="sig-typed sig-preview" aria-hidden="true">' . esc_html( $full ) . '</div><p class="small muted sig-hint">' . esc_html__( 'Your name is shown as a signature. Change it above, or choose Draw to sign by hand instead.', 'bdaci-portal-access' ) . '</p></div>' .
			'<div data-sig-pane="drawn" hidden><canvas class="sig-pad" width="600" height="170" aria-label="' . esc_attr__( 'Signature box. Draw with your mouse, finger or pen.', 'bdaci-portal-access' ) . '"></canvas><div class="row-btns"><span class="small muted">' . esc_html__( 'Sign with your mouse, finger or pen.', 'bdaci-portal-access' ) . '</span><button type="button" class="btn btn-sm" data-sig-clear>' . esc_html__( 'Clear', 'bdaci-portal-access' ) . '</button></div></div>' .
			'<input type="hidden" name="sig_how" value="typed"><input type="hidden" name="sig_img" value="">' .
			'<p class="small muted sig-when">' . esc_html( sprintf( /* translators: 1: name, 2: date and time */ __( 'Signing as %1$s · %2$s. The date, time and your signature are recorded in the audit trail.', 'bdaci-portal-access' ), $u->display_name, date_i18n( 'M j, Y g:i A' ) ) ) . '</p></fieldset>';
	}

	/**
	 * Sign one document (from the document viewer).
	 *
	 * @param int $user_id User.
	 */
	public static function handle_doc_sign( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		$doc = isset( $_POST['doc'] ) ? absint( $_POST['doc'] ) : 0;
		$ack = ! empty( $_POST['ack'] );
		// phpcs:enable
		if ( ! $doc || ! get_post_meta( $doc, BDACI_PA_Documents::META_PRIVATE, true ) || ! BDACI_PA_Sections::can_open( $doc, $user_id ) || ! self::doc_needs( $doc ) ) {
			BDACI_PA_Portal::flash( __( 'This document does not need your sign-off.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$st = self::doc_status( $doc, $user_id );
		if ( $st['signed'] ) {
			BDACI_PA_Portal::flash( __( 'You have already signed off on this document.', 'bdaci-portal-access' ) );
			return;
		}
		if ( ! $st['opened'] ) {
			BDACI_PA_Portal::flash( __( 'Open the document before signing off.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		if ( ! $ack ) {
			BDACI_PA_Portal::flash( __( 'Tick the box to confirm, then sign off.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$statement = self::doc_statement( $doc );
		$sig       = self::signature_from_post( $statement );
		if ( is_wp_error( $sig ) ) {
			BDACI_PA_Portal::flash( $sig->get_error_message(), 'err' );
			return;
		}
		$sig['doc'] = get_the_title( $doc );
		self::add( self::DOC, $user_id, 'signed', $doc, $sig );
		$user = get_userdata( $user_id );
		BDACI_PA_Log::add( 'document_signed', $user->user_email, $user_id, get_the_title( $doc ) );
		/* translators: 1: name, 2: document, 3: date and time, 4: statement, 5: signature */
		$body = sprintf( __( "%1\$s signed off on \"%2\$s\" on %3\$s.\n\nStatement: %4\$s\nSignature: %5\$s\n\nThis is recorded in the portal's sign-off audit trail.", 'bdaci-portal-access' ), $user->display_name, get_the_title( $doc ), self::fmt( current_time( 'mysql' ) ), $statement, self::sig_text( $sig ) );
		/* translators: 1: document, 2: name */
		wp_mail( $user->user_email, sprintf( __( 'Sign-off confirmation: %1$s (%2$s)', 'bdaci-portal-access' ), get_the_title( $doc ), $user->display_name ), $body );
		/* translators: %s: document */
		BDACI_PA_Portal::flash( sprintf( __( 'Signed off on "%s". A confirmation has been emailed to you.', 'bdaci-portal-access' ), get_the_title( $doc ) ) );
	}

	/**
	 * Email everyone who still has to sign a document.
	 *
	 * @param int $doc_id Document.
	 * @return int Number emailed.
	 */
	public static function doc_remind( $doc_id ) {
		$n = 0;
		foreach ( self::doc_recipients( $doc_id ) as $u ) {
			if ( self::doc_status( $doc_id, $u->ID )['signed'] ) {
				continue;
			}
			/* translators: %s: document */
			$subject = sprintf( __( 'Reminder: please read and sign off on %s', 'bdaci-portal-access' ), get_the_title( $doc_id ) );
			/* translators: 1: name, 2: document, 3: link */
			$body = sprintf( __( "Hello %1\$s,\n\nPlease read \"%2\$s\" in the BDACI portal and sign off. It is on your to-do list on the portal Home page.\n\n%3\$s\n\nThank you.", 'bdaci-portal-access' ), $u->display_name, get_the_title( $doc_id ), BDACI_PA_Settings::portal_home_url() );
			wp_mail( $u->user_email, $subject, $body );
			self::add( self::DOC, $u->ID, 'reminded', $doc_id );
			++$n;
		}
		BDACI_PA_Log::add( 'document_signoff_reminded', '', get_current_user_id(), get_the_title( $doc_id ) . ' (' . $n . ')' );
		return $n;
	}

	/**
	 * "Typed: Jane Doe" / "Drawn by Jane Doe".
	 *
	 * @param array $d Detail.
	 * @return string
	 */
	public static function sig_text( array $d ) {
		if ( ! $d ) {
			return '';
		}
		/* translators: %s: name */
		return sprintf( 'drawn' === ( $d['how'] ?? '' ) ? __( 'drawn by %s', 'bdaci-portal-access' ) : __( 'typed name "%s"', 'bdaci-portal-access' ), $d['name'] ?? '' );
	}

	/**
	 * Default packs.
	 *
	 * @return array
	 */
	public static function defaults() {
		$year = current_time( 'Y' );
		return array(
			'orientation' => array(
				'title'     => __( 'New Director Orientation', 'bdaci-portal-access' ),
				'short'     => __( 'Orientation', 'bdaci-portal-access' ),
				'intro'     => __( 'Onboarding materials and core governance documents for new directors. Read each one, then sign off below.', 'bdaci-portal-access' ),
				'statement' => __( 'I have read and understood the New Director Orientation materials.', 'bdaci-portal-access' ),
				'due'       => '',
				'roles'     => array( 'board' ),
				'users'     => array(),
				'cc'        => '',
				'cycle'     => '',
			),
			'qa'          => array(
				/* translators: %s: year */
				'title'     => sprintf( __( 'Quality Assurance %s', 'bdaci-portal-access' ), $year ),
				'short'     => __( 'QA', 'bdaci-portal-access' ),
				'intro'     => __( 'Every director signs off on the yearly compliance and Quality Assurance documents.', 'bdaci-portal-access' ),
				/* translators: %s: year */
				'statement' => sprintf( __( 'I have reviewed the %s Quality Assurance documents and confirm my annual sign-off.', 'bdaci-portal-access' ), $year ),
				'due'       => $year . '-12-31',
				'roles'     => array( 'board' ),
				'users'     => array(),
				'cc'        => 'afenlong@bdaci.com',
				'cycle'     => '',
			),
		);
	}

	/**
	 * All packs.
	 *
	 * @return array
	 */
	public static function packs() {
		$saved = get_option( self::OPT, array() );
		$out   = self::defaults();
		foreach ( $out as $k => $def ) {
			if ( isset( $saved[ $k ] ) && is_array( $saved[ $k ] ) ) {
				$out[ $k ] = array_merge( $def, $saved[ $k ] );
			}
		}
		return $out;
	}

	/**
	 * One pack.
	 *
	 * @param string $key Pack.
	 * @return array|null
	 */
	public static function pack( $key ) {
		$p = self::packs();
		return $p[ $key ] ?? null;
	}

	/**
	 * Save pack settings.
	 *
	 * @param string $key  Pack.
	 * @param array  $data Fields.
	 */
	public static function save_pack( $key, array $data ) {
		$saved         = get_option( self::OPT, array() );
		$saved[ $key ] = array_merge( isset( $saved[ $key ] ) ? $saved[ $key ] : array(), $data );
		update_option( self::OPT, $saved, false );
	}

	/**
	 * People who must sign.
	 *
	 * @param string $key Pack.
	 * @return WP_User[]
	 */
	public static function recipients( $key ) {
		static $memo = array();
		if ( isset( $memo[ $key ] ) && ! doing_action( 'admin_post_bdaci_pa_signoff_admin' ) ) {
			return $memo[ $key ];
		}
		$pack = self::pack( $key );
		if ( ! $pack ) {
			return array();
		}
		$users = array();
		if ( $pack['users'] ) {
			foreach ( (array) $pack['users'] as $id ) {
				$u = get_userdata( (int) $id );
				if ( $u ) {
					$users[ $u->ID ] = $u;
				}
			}
		} else {
			$map = BDACI_PA_Roles::map();
			foreach ( (array) $pack['roles'] as $r ) {
				if ( isset( $map[ $r ] ) ) {
					foreach ( get_users( array( 'role' => $map[ $r ] ) ) as $u ) {
						$users[ $u->ID ] = $u;
					}
				}
			}
		}
		$users = array_filter(
			$users,
			static function ( $u ) {
				return BDACI_PA_Members::is_authorized( $u->ID );
			}
		);
		uasort(
			$users,
			static function ( $a, $b ) {
				return strcasecmp( $a->display_name, $b->display_name );
			}
		);
		$memo[ $key ] = array_values( $users );
		return $memo[ $key ];
	}

	/**
	 * Whether a user must sign a pack.
	 *
	 * @param string $key     Pack.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function is_recipient( $key, $user_id ) {
		foreach ( self::recipients( $key ) as $u ) {
			if ( (int) $u->ID === (int) $user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Documents of a pack that a user can open.
	 *
	 * @param string $key     Pack.
	 * @param int    $user_id User.
	 * @return WP_Post[]
	 */
	public static function docs( $key, $user_id ) {
		return BDACI_PA_Sections::docs(
			array(
				'section' => $key,
				'user_id' => $user_id,
			)
		);
	}

	/**
	 * Events of the current round, oldest first.
	 *
	 * @param string $key Pack.
	 * @return array
	 */
	public static function events( $key ) {
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		global $wpdb;
		$pack  = self::pack( $key );
		$since = $pack && $pack['cycle'] ? $pack['cycle'] : '1970-01-01 00:00:00';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$cache[ $key ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE pack = %s AND created_at >= %s ORDER BY created_at ASC, id ASC', $key, $since ) );
		return self::$cache[ $key ];
	}

	/**
	 * Add an event.
	 *
	 * @param string $key     Pack.
	 * @param int    $user_id User.
	 * @param string $event   sent|reminded|opened|signed.
	 * @param int    $doc_id  Document.
	 * @param array  $detail  Signature details (signed events).
	 */
	public static function add( $key, $user_id, $event, $doc_id = 0, array $detail = array() ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'pack'       => $key,
				'user_id'    => (int) $user_id,
				'event'      => $event,
				'doc_id'     => (int) $doc_id,
				'by_user'    => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
				'detail'     => $detail ? wp_json_encode( $detail ) : null,
			)
		);
		unset( self::$cache[ $key ] );
	}

	/**
	 * A person's status in the current round.
	 *
	 * @param string $key     Pack.
	 * @param int    $user_id User.
	 * @return array sent, reminders[], opened[doc => time], signed
	 */
	public static function status( $key, $user_id ) {
		$s = array(
			'sent'      => '',
			'reminders' => array(),
			'opened'    => array(),
			'signed'    => '',
			'detail'    => array(),
		);
		foreach ( self::events( $key ) as $e ) {
			if ( (int) $e->user_id !== (int) $user_id ) {
				continue;
			}
			if ( 'sent' === $e->event && ! $s['sent'] ) {
				$s['sent'] = $e->created_at;
			} elseif ( 'reminded' === $e->event ) {
				$s['reminders'][] = $e->created_at;
			} elseif ( 'opened' === $e->event && ! isset( $s['opened'][ (int) $e->doc_id ] ) ) {
				$s['opened'][ (int) $e->doc_id ] = $e->created_at;
			} elseif ( 'signed' === $e->event ) {
				$s['signed'] = $e->created_at;
				$s['detail'] = self::detail( $e );
			}
		}
		return $s;
	}

	/**
	 * Whether a user still has to sign.
	 *
	 * @param string $key     Pack.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function needs_action( $key, $user_id ) {
		return self::is_recipient( $key, $user_id ) && ! self::status( $key, $user_id )['signed'];
	}

	/**
	 * Record that a recipient opened one of a pack's documents.
	 *
	 * @param int $doc_id  Document.
	 * @param int $user_id User.
	 */
	public static function record_open( $doc_id, $user_id ) {
		if ( self::doc_needs( $doc_id ) && BDACI_PA_Members::is_authorized( $user_id ) && ! self::doc_status( $doc_id, $user_id )['opened'] ) {
			self::add( self::DOC, $user_id, 'opened', $doc_id );
		}
		$secs = BDACI_PA_Sections::doc_sections( $doc_id );
		foreach ( array_keys( self::packs() ) as $key ) {
			if ( ! in_array( $key, $secs, true ) || ! self::is_recipient( $key, $user_id ) ) {
				continue;
			}
			$st = self::status( $key, $user_id );
			if ( ! isset( $st['opened'][ (int) $doc_id ] ) && ! $st['signed'] ) {
				self::add( $key, $user_id, 'opened', $doc_id );
			}
		}
	}

	/**
	 * Sign off from the portal.
	 *
	 * @param int $user_id User.
	 */
	public static function handle_sign( $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		$key = isset( $_POST['pack'] ) ? sanitize_key( wp_unslash( $_POST['pack'] ) ) : '';
		$ack = ! empty( $_POST['ack'] );
		// phpcs:enable
		$pack = self::pack( $key );
		if ( ! $pack || ! self::is_recipient( $key, $user_id ) ) {
			BDACI_PA_Portal::flash( __( 'This sign-off is not assigned to you.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$st = self::status( $key, $user_id );
		if ( $st['signed'] ) {
			BDACI_PA_Portal::flash( __( 'You have already signed off.', 'bdaci-portal-access' ) );
			return;
		}
		$docs = self::docs( $key, $user_id );
		foreach ( $docs as $d ) {
			if ( ! isset( $st['opened'][ $d->ID ] ) ) {
				BDACI_PA_Portal::flash( __( 'Open each document before signing off.', 'bdaci-portal-access' ), 'err' );
				return;
			}
		}
		if ( ! $docs || ! $ack ) {
			BDACI_PA_Portal::flash( $docs ? __( 'Tick the box to confirm, then sign off.', 'bdaci-portal-access' ) : __( 'There are no documents to sign off yet.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$sig = self::signature_from_post( $pack['statement'] );
		if ( is_wp_error( $sig ) ) {
			BDACI_PA_Portal::flash( $sig->get_error_message(), 'err' );
			return;
		}
		self::add( $key, $user_id, 'signed', 0, $sig );
		$user = get_userdata( $user_id );
		$when = current_time( 'mysql' );
		BDACI_PA_Log::add( 'signed_off', $user->user_email, $user_id, $pack['title'] );
		$titles = implode( "\n", array_map( static function ( $d ) { return '- ' . get_the_title( $d ); }, $docs ) );
		/* translators: 1: name, 2: sign-off, 3: date and time, 4: statement, 5: documents, 6: signature */
		$body    = sprintf( __( "%1\$s signed off on %2\$s on %3\$s.\n\nStatement: %4\$s\nSignature: %6\$s\n\nDocuments:\n%5\$s\n\nThis is recorded in the portal's sign-off audit trail.", 'bdaci-portal-access' ), $user->display_name, $pack['title'], self::fmt( $when ), $pack['statement'], $titles, self::sig_text( $sig ) );
		/* translators: 1: sign-off, 2: name */
		$subject = sprintf( __( 'Sign-off confirmation: %1$s (%2$s)', 'bdaci-portal-access' ), $pack['title'], $user->display_name );
		$headers = array();
		if ( $pack['cc'] && is_email( $pack['cc'] ) ) {
			$headers[] = 'Cc: ' . $pack['cc'];
		}
		wp_mail( $user->user_email, $subject, $body, $headers );
		BDACI_PA_Portal::flash( __( 'Signed off. A confirmation has been emailed to you.', 'bdaci-portal-access' ) );
	}

	/**
	 * Email recipients who have not been sent the sign-off yet in this round.
	 *
	 * @param string $key Pack.
	 * @return int Number sent.
	 */
	public static function send( $key ) {
		$pack = self::pack( $key );
		$n    = 0;
		foreach ( self::recipients( $key ) as $u ) {
			$st = self::status( $key, $u->ID );
			if ( $st['sent'] || $st['signed'] ) {
				continue;
			}
			self::mail( $key, $u, false );
			self::add( $key, $u->ID, 'sent' );
			++$n;
		}
		if ( $n ) {
			BDACI_PA_Log::add( 'signoff_sent', '', get_current_user_id(), $pack['title'] . ' x' . $n );
		}
		return $n;
	}

	/**
	 * Remind people who have not signed.
	 *
	 * @param string $key      Pack.
	 * @param array  $user_ids Only these (empty = everyone not signed).
	 * @return int Number reminded.
	 */
	public static function remind( $key, array $user_ids = array() ) {
		$pack = self::pack( $key );
		$n    = 0;
		foreach ( self::recipients( $key ) as $u ) {
			if ( $user_ids && ! in_array( (int) $u->ID, array_map( 'intval', $user_ids ), true ) ) {
				continue;
			}
			$st = self::status( $key, $u->ID );
			if ( $st['signed'] ) {
				continue;
			}
			self::mail( $key, $u, true );
			self::add( $key, $u->ID, $st['sent'] ? 'reminded' : 'sent' );
			++$n;
		}
		if ( $n ) {
			BDACI_PA_Log::add( 'signoff_reminded', '', get_current_user_id(), $pack['title'] . ' x' . $n );
		}
		return $n;
	}

	/**
	 * The sign-off email.
	 *
	 * @param string  $key      Pack.
	 * @param WP_User $u        Person.
	 * @param bool    $reminder Reminder wording.
	 */
	private static function mail( $key, WP_User $u, $reminder ) {
		$pack = self::pack( $key );
		$due  = $pack['due'] ? date_i18n( 'F j, Y', strtotime( $pack['due'] ) ) : '';
		$subject = $reminder
			/* translators: %s: sign-off */
			? sprintf( __( 'Reminder: please sign off on %s', 'bdaci-portal-access' ), $pack['title'] )
			/* translators: %s: sign-off */
			: sprintf( __( 'Action required: %s sign-off', 'bdaci-portal-access' ), $pack['title'] );
		/* translators: 1: name, 2: sign-off, 3: due line, 4: link */
		$body = sprintf( __( "Hello %1\$s,\n\nPlease read the %2\$s documents in the BDACI portal and sign off.%3\$s\n\n%4\$s\n\nThank you.", 'bdaci-portal-access' ), $u->display_name, $pack['title'], $due ? ' ' . sprintf( /* translators: %s: date */ __( 'Due %s.', 'bdaci-portal-access' ), $due ) : '', BDACI_PA_Portal::url( $key ) );
		wp_mail( $u->user_email, $subject, $body );
	}

	/**
	 * Start a new round (e.g. next year's QA sign-off). Earlier events stay in the log.
	 *
	 * @param string $key Pack.
	 */
	public static function new_round( $key ) {
		self::save_pack( $key, array( 'cycle' => current_time( 'mysql' ) ) );
		self::$cache = array();
		BDACI_PA_Log::add( 'signoff_new_round', '', get_current_user_id(), self::pack( $key )['title'] );
	}

	/**
	 * Every event of a pack (all rounds), newest first, for the log and exports.
	 *
	 * @param string $key Pack ('' = all).
	 * @return array
	 */
	public static function log( $key = '' ) {
		global $wpdb;
		$where = $key ? $wpdb->prepare( 'WHERE pack = %s', $key ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . " {$where} ORDER BY created_at DESC, id DESC LIMIT 2000" );
	}

	/**
	 * "Nov 19, 2026 9:12 AM".
	 *
	 * @param string $dt Datetime.
	 * @return string
	 */
	public static function fmt( $dt ) {
		return $dt ? date_i18n( 'M j, Y g:i A', strtotime( $dt ) ) : '';
	}

	/**
	 * Printable sign-off receipt for the signed-in person (browser "Save as PDF").
	 */
	public static function receipt() {
		$key = isset( $_GET['pack'] ) ? sanitize_key( wp_unslash( $_GET['pack'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'bdaci_pa_receipt_' . $key );
		$uid  = get_current_user_id();
		if ( self::DOC === $key ) {
			self::doc_receipt( $uid );
		}
		$pack = self::pack( $key );
		if ( ! $pack || ! BDACI_PA_Members::is_authorized( $uid ) ) {
			wp_die( esc_html__( 'Not found.', 'bdaci-portal-access' ), '', array( 'response' => 404 ) );
		}
		$st   = self::status( $key, $uid );
		$user = wp_get_current_user();
		$rows = '';
		foreach ( self::docs( $key, $uid ) as $d ) {
			$rows .= '<tr><td>' . esc_html( get_the_title( $d ) ) . '</td><td>' . esc_html( isset( $st['opened'][ $d->ID ] ) ? self::fmt( $st['opened'][ $d->ID ] ) : '-' ) . '</td></tr>';
		}
		self::print_page(
			$pack['title'] . ' – ' . $user->display_name,
			'<h1>' . esc_html( $pack['title'] ) . '</h1><p><b>' . esc_html( $user->display_name ) . '</b> (' . esc_html( $user->user_email ) . ')</p>' .
			'<p>' . ( $st['signed'] ? esc_html( sprintf( /* translators: %s: date */ __( 'Signed off on %s.', 'bdaci-portal-access' ), self::fmt( $st['signed'] ) ) ) : esc_html__( 'Not signed yet.', 'bdaci-portal-access' ) ) . '</p>' .
			'<p><i>' . esc_html( $st['detail']['statement'] ?? $pack['statement'] ) . '</i></p>' . ( $st['detail'] ? '<p>' . esc_html__( 'Signature:', 'bdaci-portal-access' ) . '<br>' . self::signature_html( $st['detail'] ) . '</p>' : '' ) . '<table><thead><tr><th>' . esc_html__( 'Document', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Opened', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
		);
	}

	/**
	 * Printable receipt for one signed document.
	 *
	 * @param int $uid User.
	 */
	private static function doc_receipt( $uid ) {
		$doc = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked by the caller.
		if ( ! $doc || ! BDACI_PA_Members::is_authorized( $uid ) || ! BDACI_PA_Sections::can_open( $doc, $uid ) ) {
			wp_die( esc_html__( 'Not found.', 'bdaci-portal-access' ), '', array( 'response' => 404 ) );
		}
		$st   = self::doc_status( $doc, $uid );
		$user = wp_get_current_user();
		self::print_page(
			get_the_title( $doc ) . ' – ' . $user->display_name,
			'<h1>' . esc_html__( 'Document sign-off', 'bdaci-portal-access' ) . '</h1><p><b>' . esc_html( get_the_title( $doc ) ) . '</b></p><p><b>' . esc_html( $user->display_name ) . '</b> (' . esc_html( $user->user_email ) . ')</p>' .
			'<table><tbody><tr><th>' . esc_html__( 'Opened', 'bdaci-portal-access' ) . '</th><td>' . esc_html( $st['opened'] ? self::fmt( $st['opened'] ) : '-' ) . '</td></tr><tr><th>' . esc_html__( 'Signed', 'bdaci-portal-access' ) . '</th><td>' . esc_html( $st['signed'] ? self::fmt( $st['signed'] ) : __( 'Not signed yet.', 'bdaci-portal-access' ) ) . '</td></tr>' .
			'<tr><th>' . esc_html__( 'Statement', 'bdaci-portal-access' ) . '</th><td><i>' . esc_html( $st['detail']['statement'] ?? self::doc_statement( $doc ) ) . '</i></td></tr>' . ( $st['detail'] ? '<tr><th>' . esc_html__( 'Signature', 'bdaci-portal-access' ) . '</th><td>' . self::signature_html( $st['detail'] ) . '</td></tr>' : '' ) . '</tbody></table>'
		);
	}

	/**
	 * A plain printable page that opens the print dialog.
	 *
	 * @param string $title Title.
	 * @param string $body  Body HTML (escaped by the caller).
	 */
	public static function print_page( $title, $body ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		$bdaci_font = plugins_url( 'assets/fonts/', BDACI_PA_FILE );
		echo '<style>@font-face{font-family:"BDACI Signature";font-style:normal;font-weight:400;font-display:swap;src:url(' . esc_url( $bdaci_font ) . 'great-vibes-latin-ext-400-normal.woff2) format("woff2");unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}@font-face{font-family:"BDACI Signature";font-style:normal;font-weight:400;font-display:swap;src:url(' . esc_url( $bdaci_font ) . 'great-vibes-latin-400-normal.woff2) format("woff2");unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}</style>';
		echo '<style>body{font:14px/1.5 "Open Sans",Arial,sans-serif;color:#1c2a36;margin:32px;max-width:960px}h1{font:600 22px Ubuntu,Arial,sans-serif;margin:0 0 8px}h2{font:600 17px Ubuntu,Arial,sans-serif;margin:24px 0 8px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{text-align:left;border-bottom:1px solid #d8dfe6;padding:6px 8px;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#566573}.sig-img{max-height:70px;max-width:260px}.sig-typed{font:400 32px "BDACI Signature","Segoe Script","Brush Script MT",cursive}.logo{height:48px;margin-bottom:16px}.muted{color:#566573}.noprint{margin-bottom:16px}@media print{.noprint{display:none}body{margin:0}}</style></head><body>';
		echo '<p class="noprint"><button onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'bdaci-portal-access' ) . '</button></p>';
		echo '<img class="logo" src="' . esc_url( BDACI_PA_URL . 'assets/logo-full.png' ) . '" alt="">';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		echo '<p class="muted" style="margin-top:24px">' . esc_html( sprintf( /* translators: %s: date */ __( 'Generated %s', 'bdaci-portal-access' ), self::fmt( current_time( 'mysql' ) ) ) ) . '</p>';
		echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script></body></html>';
		exit;
	}
}
