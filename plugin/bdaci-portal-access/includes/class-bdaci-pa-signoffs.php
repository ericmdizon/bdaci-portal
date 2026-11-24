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
	 */
	public static function add( $key, $user_id, $event, $doc_id = 0 ) {
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
		self::add( $key, $user_id, 'signed' );
		$user = get_userdata( $user_id );
		$when = current_time( 'mysql' );
		BDACI_PA_Log::add( 'signed_off', $user->user_email, $user_id, $pack['title'] );
		$titles = implode( "\n", array_map( static function ( $d ) { return '- ' . get_the_title( $d ); }, $docs ) );
		/* translators: 1: name, 2: sign-off, 3: date and time, 4: statement, 5: documents */
		$body    = sprintf( __( "%1\$s signed off on %2\$s on %3\$s.\n\nStatement: %4\$s\n\nDocuments:\n%5\$s\n\nThis is recorded in the portal's sign-off audit trail.", 'bdaci-portal-access' ), $user->display_name, $pack['title'], self::fmt( $when ), $pack['statement'], $titles );
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
			'<p><i>' . esc_html( $pack['statement'] ) . '</i></p><table><thead><tr><th>' . esc_html__( 'Document', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Opened', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
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
		echo '<style>body{font:14px/1.5 "Open Sans",Arial,sans-serif;color:#1c2a36;margin:32px;max-width:960px}h1{font:600 22px Ubuntu,Arial,sans-serif;margin:0 0 8px}h2{font:600 17px Ubuntu,Arial,sans-serif;margin:24px 0 8px}table{border-collapse:collapse;width:100%;margin-top:12px}th,td{text-align:left;border-bottom:1px solid #d8dfe6;padding:6px 8px;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#566573}.logo{height:48px;margin-bottom:16px}.muted{color:#566573}.noprint{margin-bottom:16px}@media print{.noprint{display:none}body{margin:0}}</style></head><body>';
		echo '<p class="noprint"><button onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'bdaci-portal-access' ) . '</button></p>';
		echo '<img class="logo" src="' . esc_url( BDACI_PA_URL . 'assets/logo-full.png' ) . '" alt="">';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller.
		echo '<p class="muted" style="margin-top:24px">' . esc_html( sprintf( /* translators: %s: date */ __( 'Generated %s', 'bdaci-portal-access' ), self::fmt( current_time( 'mysql' ) ) ) ) . '</p>';
		echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)})</script></body></html>';
		exit;
	}
}
