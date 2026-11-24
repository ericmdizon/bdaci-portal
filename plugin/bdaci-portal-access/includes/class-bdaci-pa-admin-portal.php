<?php
/**
 * Admin screens for the portal features: Sections & Uploads, Meetings, Sign-off Audit Trail,
 * access requests and directory details.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portal admin screens.
 */
class BDACI_PA_Admin_Portal {

	/**
	 * True while a screen is being drawn inside the portal (not in wp-admin).
	 *
	 * @var bool
	 */
	public static $front = false;

	/**
	 * wp-admin page slug => portal "manage" key.
	 *
	 * @return array
	 */
	public static function slugs() {
		return array(
			'bdaci-portal'           => 'users',
			'bdaci-portal-docs'      => 'docs',
			'bdaci-portal-meetings'  => 'meetings',
			'bdaci-portal-signoffs'  => 'signoffs',
			'bdaci-portal-person'    => 'person',
			'bdaci-portal-test-data' => 'test',
			'bdaci-portal-qa'        => 'qa',
		);
	}

	/**
	 * Link to an admin screen: in wp-admin, or inside the portal when drawing the portal version.
	 *
	 * @param string $q "slug&arg=1#frag".
	 * @return string
	 */
	public static function url( $q ) {
		$u = admin_url( 'admin.php?page=' . $q );
		return self::$front ? self::to_front( $u ) : $u;
	}

	/**
	 * Portal address of an admin screen.
	 *
	 * @param string $key  Manage key.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function front_url( $key, array $args = array() ) {
		return add_query_arg( array_merge( array( 'manage' => $key ), $args ), BDACI_PA_Settings::portal_home_url() );
	}

	/**
	 * Turn an admin.php?page=… address into the portal's version of that screen.
	 *
	 * @param string $url Admin URL.
	 * @return string
	 */
	public static function to_front( $url ) {
		$frag = '';
		if ( false !== strpos( $url, '#' ) ) {
			list( $url, $frag ) = explode( '#', $url, 2 );
			$frag              = '#' . $frag;
		}
		$q = array();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$slugs = self::slugs();
		if ( false === strpos( $url, 'admin.php' ) || empty( $q['page'] ) || ! isset( $slugs[ $q['page'] ] ) ) {
			return $url . $frag;
		}
		$key = $slugs[ $q['page'] ];
		unset( $q['page'] );
		return self::front_url( $key, $q ) . $frag;
	}

	/**
	 * Hidden field that marks a form posted from the portal.
	 *
	 * @return string
	 */
	public static function front_field() {
		return self::$front ? '<input type="hidden" name="_bdaci_front" value="1">' : '';
	}

	/**
	 * Was this form posted from the portal?
	 *
	 * @return bool
	 */
	public static function from_front() {
		return ! empty( $_POST['_bdaci_front'] ) || ! empty( $_GET['_bdaci_front'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Page title: the wp-admin heading, or the portal's page head.
	 *
	 * @param string $title Title.
	 * @param string $sub   Sub-title (portal only).
	 */
	public static function open_page( $title, $sub = '' ) {
		if ( self::$front ) {
			echo '<div class="bp-front">' . BDACI_PA_Portal::head( $title, $sub ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		echo '<div class="wrap"><p class="bdaci-wp-crumbs" style="margin:12px 0 0;color:#50575e">' . esc_html__( 'WordPress admin', 'bdaci-portal-access' ) . ' › BDACI Portal › <b>' . esc_html( $title ) . '</b> · ' . esc_html__( 'also in the portal under Portal admin', 'bdaci-portal-access' ) . '</p><h1>' . esc_html( $title ) . '</h1>';
	}

	/**
	 * Draw an admin screen inside the portal.
	 *
	 * @param string $key Manage key.
	 * @return string HTML, or '' if not allowed.
	 */
	public static function front( $key ) {
		if ( 'qa' === $key && BDACI_PA_QA::can_view() ) {
			self::$front = true;
			ob_start();
			BDACI_PA_QA::page();
			$h           = ob_get_clean();
			self::$front = false;
			return $h;
		}
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return '';
		}
		$map = array(
			'users'    => array( __CLASS__, 'front_users' ),
			'docs'     => array( __CLASS__, 'page_docs' ),
			'meetings' => array( __CLASS__, 'page_meetings' ),
			'signoffs' => array( __CLASS__, 'page_signoffs' ),
			'person'   => array( __CLASS__, 'page_person' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$map['test'] = array( 'BDACI_PA_Demo', 'page' );
		}
		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}
		self::$front = true;
		ob_start();
		call_user_func( $map[ $key ] );
		$h           = ob_get_clean();
		self::$front = false;
		return $h;
	}

	/**
	 * Authorized Users, portal version (the wp-admin screen has the same actions).
	 */
	public static function front_users() {
		$rows   = BDACI_PA_Members::all();
		$labels = BDACI_PA_Roles::labels();
		self::open_page( __( 'Authorized Users', 'bdaci-portal-access' ), __( 'Only people on this list can sign in. Revoking someone signs them out right away.', 'bdaci-portal-access' ) );
		self::notice();
		echo self::requests_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$boxes = static function ( $name, array $on ) use ( $labels ) {
			$h = '<div class="years" role="group" aria-label="' . esc_attr__( 'Roles', 'bdaci-portal-access' ) . '">';
			foreach ( $labels as $k => $l ) {
				$h .= '<label class="check" style="font-weight:400"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $on, true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
			}
			return $h . '</div>';
		};
		$h  = '<div class="bp bp-admin">';
		$h .= self::form_open( 'member_add', 'bdaci_pa_member_add', 'class="panel" style="display:grid;gap:12px"' ) . '<h2>' . esc_html__( 'Add a person', 'bdaci-portal-access' ) . '</h2>';
		$h .= '<div class="two" style="gap:12px"><div class="field"><label for="a-email">' . esc_html__( 'Email', 'bdaci-portal-access' ) . '</label><input id="a-email" type="email" name="email" required placeholder="name@example.org"></div><div class="field"><label for="a-name">' . esc_html__( 'Name', 'bdaci-portal-access' ) . '</label><input id="a-name" name="display_name"></div></div>';
		$h .= $boxes( 'roles', array( 'board' ) );
		$h .= '<label class="check" style="font-weight:400"><input type="checkbox" name="invite" value="1" checked> ' . esc_html__( 'Create their account now and email them a link to set a password', 'bdaci-portal-access' ) . '</label><span class="small muted" style="margin-top:-6px">' . esc_html__( 'Leave unticked if they will only use Microsoft 365 or Google sign-in.', 'bdaci-portal-access' ) . '</span>';
		$h .= '<div><button class="btn btn-primary" type="submit">' . esc_html__( 'Add authorized user', 'bdaci-portal-access' ) . '</button></div></form>';
		$h .= '<details class="panel"><summary>' . esc_html__( 'Add many at once', 'bdaci-portal-access' ) . '</summary>' . self::form_open( 'member_import', 'bdaci_pa_member_import', 'style="display:grid;gap:12px;margin-top:12px"' ) . '<p class="small muted">' . esc_html__( 'One person per line: email, name, roles. Roles are board, committee (Education), finance and/or admin separated by semicolons.', 'bdaci-portal-access' ) . ' <code>jane@example.org, Jane Doe, board;committee</code></p><div class="field"><textarea name="lines" rows="5"></textarea></div><label class="check" style="font-weight:400"><input type="checkbox" name="invite" value="1"> ' . esc_html__( 'Create accounts and email set-password links', 'bdaci-portal-access' ) . '</label><div><button class="btn">' . esc_html__( 'Import', 'bdaci-portal-access' ) . '</button></div></form></details>';

		$pill = array(
			BDACI_PA_Members::ACTIVE  => array( 'pill-ok', __( 'Active', 'bdaci-portal-access' ) ),
			BDACI_PA_Members::PENDING => array( 'pill-warn', __( 'Pending', 'bdaci-portal-access' ) ),
			BDACI_PA_Members::REVOKED => array( 'pill-muted', __( 'Revoked', 'bdaci-portal-access' ) ),
		);
		$t = '';
		foreach ( $rows as $r ) {
			$roles = BDACI_PA_Members::clean_roles( $r->roles );
			$names = array_map( static function ( $k ) use ( $labels ) { return $labels[ $k ] ?? $k; }, $roles );
			$act   = static function ( $do, $label, $cls = '' ) use ( $r ) {
				return self::form_open( 'member_action', 'bdaci_pa_member_action_' . $r->id, 'class="inline-form"' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><button class="btn btn-sm' . esc_attr( $cls ) . '"' . ( 'delete' === $do ? ' onclick="return confirm(\'' . esc_js( __( 'Remove this person from the list?', 'bdaci-portal-access' ) ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
			};
			$btns = '';
			if ( BDACI_PA_Members::PENDING === $r->status ) {
				$btns .= $act( 'reject', __( 'Reject', 'bdaci-portal-access' ) );
			} elseif ( BDACI_PA_Members::ACTIVE === $r->status ) {
				$btns .= $act( 'revoke', __( 'Revoke', 'bdaci-portal-access' ) );
			} else {
				$btns .= $act( 'activate', __( 'Restore', 'bdaci-portal-access' ) );
			}
			$edit  = '<details><summary class="small">' . ( BDACI_PA_Members::PENDING === $r->status ? esc_html__( 'Approve…', 'bdaci-portal-access' ) : esc_html__( 'Edit', 'bdaci-portal-access' ) ) . '</summary><div style="display:grid;gap:10px;margin-top:10px">';
			$edit .= self::form_open( 'member_roles', 'bdaci_pa_member_roles_' . $r->id, 'style="display:grid;gap:8px"' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '">' . $boxes( 'roles', $roles ) . '<div><button class="btn btn-sm btn-primary">' . ( BDACI_PA_Members::PENDING === $r->status ? esc_html__( 'Approve with these roles', 'bdaci-portal-access' ) : esc_html__( 'Save roles', 'bdaci-portal-access' ) ) . '</button></div></form>';
			$edit .= '<div class="row-btns">' . ( BDACI_PA_Members::ACTIVE === $r->status ? $act( 'invite', __( 'Send set-password email', 'bdaci-portal-access' ) ) : '' ) . ( $r->user_id ? '<a class="btn btn-sm" href="' . esc_url( self::url( 'bdaci-portal-person&user=' . (int) $r->user_id ) ) . '">' . esc_html__( 'Directory details', 'bdaci-portal-access' ) . '</a> ' : '' ) . $act( 'delete', __( 'Remove from list', 'bdaci-portal-access' ) ) . '</div></div></details>';
			$p     = $pill[ $r->status ] ?? array( 'pill-muted', $r->status );
			$t    .= '<tr><td><span class="who">' . ( $r->user_id ? BDACI_PA_Profile::avatar( (int) $r->user_id, 'av-sm' ) : '' ) . '<span><b>' . esc_html( $r->display_name ? $r->display_name : $r->email ) . '</b><br><span class="muted small">' . esc_html( $r->email ) . ( 'admin' !== $r->source ? ' · ' . esc_html( sprintf( /* translators: %s: provider */ __( 'requested via %s', 'bdaci-portal-access' ), ucfirst( $r->source ) ) ) : '' ) . '</span></span></span></td><td class="small">' . esc_html( $names ? implode( ', ', $names ) : '—' ) . '</td><td><span class="pill ' . esc_attr( $p[0] ) . '">' . esc_html( $p[1] ) . '</span></td><td class="tabnum small">' . esc_html( $r->last_login ? date_i18n( 'M j, Y', strtotime( get_date_from_gmt( $r->last_login ) ) ) : '—' ) . '</td><td>' . $btns . $edit . '</td></tr>';
		}
		$h .= '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'People', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d person', '%d people', count( $rows ), 'bdaci-portal-access' ), count( $rows ) ) ) . '</span></div><div class="tablewrap"><table><thead><tr><th>' . esc_html__( 'Person', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Roles', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Status', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Last sign-in', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Actions', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . ( $t ? $t : '<tr><td colspan="5" class="muted">' . esc_html__( 'Nobody yet.', 'bdaci-portal-access' ) . '</td></tr>' ) . '</tbody></table></div></section>';
		$h .= '</div></div>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		$actions = array( 'setup_pages', 'section_create', 'section_delete', 'folder_visibility', 'doc_edit', 'meeting_save', 'meeting_delete', 'signoff_admin', 'signoff_export', 'history_export', 'request_decide', 'person_save' );
		foreach ( $actions as $a ) {
			add_action( 'admin_post_bdaci_pa_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Submenus (called from BDACI_PA_Admin::menu()).
	 */
	public static function menu() {
		add_submenu_page( 'bdaci-portal', __( 'Sections & Uploads', 'bdaci-portal-access' ), __( 'Sections & Uploads', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-docs', array( __CLASS__, 'page_docs' ) );
		add_submenu_page( 'bdaci-portal', __( 'Meetings', 'bdaci-portal-access' ), __( 'Meetings', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-meetings', array( __CLASS__, 'page_meetings' ) );
		add_submenu_page( 'bdaci-portal', __( 'Sign-off Audit Trail', 'bdaci-portal-access' ), __( 'Sign-off Audit Trail', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-signoffs', array( __CLASS__, 'page_signoffs' ) );
		add_submenu_page( 'bdaci-portal', __( 'QA Testing', 'bdaci-portal-access' ), __( 'QA Testing', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-qa', array( 'BDACI_PA_QA', 'page' ) );
		add_submenu_page( 'bdaci-portal', __( 'Directory details', 'bdaci-portal-access' ), __( 'Directory details', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-person', array( __CLASS__, 'page_person' ) );
		// Reachable by link only: keep the page registered (so it has a title) but out of the menu.
		add_action(
			'admin_head',
			static function () {
				remove_submenu_page( 'bdaci-portal', 'bdaci-portal-person' );
				remove_submenu_page( 'bdaci-portal', 'bdaci-portal-sections' );
			}
		);
	}

	/**
	 * Styles on our screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'bdaci-portal' ) ) {
			return;
		}
		wp_enqueue_style( 'bdaci-pa-portal', BDACI_PA_URL . 'assets/portal.css', array(), BDACI_PA_VERSION );
		wp_add_inline_style( 'bdaci-pa-portal', '.bp.bp-admin{background:transparent;min-height:0;font-size:14px;margin-top:12px;display:grid;gap:18px;max-width:1180px}.bp.bp-admin .panel>h2{font-size:1.15rem}.bp.bp-admin input[type=checkbox]{margin:0}.bp .pick input{position:absolute;opacity:0;pointer-events:none}.bp .pick:has(input:checked){border-color:var(--c);background:color-mix(in srgb,var(--c) 8%,var(--surface))}.bp .pick:has(input:checked) .ck{background:var(--c);border-color:var(--c);color:#fff}.bp .pick:has(input:focus-visible){outline:3px solid var(--focus)}.bp .pick .ck::after{content:"\2713"}.bp details>summary{cursor:pointer;font-weight:700}.bp .inline-form{display:inline}.bp .field input[type=date],.bp .field input[type=time]{max-width:220px}.bp .grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}@media(max-width:782px){.bp .grid3{grid-template-columns:minmax(0,1fr)}}' );
	}

	/**
	 * Guard an admin-post handler.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Redirect with a notice.
	 *
	 * @param string $url    Where.
	 * @param string $notice Text.
	 * @param string $type   success|error.
	 */
	private static function back( $url, $notice, $type = 'success' ) {
		set_transient( 'bdaci_pa_notice_' . get_current_user_id(), array( $notice, $type ), 60 );
		wp_safe_redirect( self::from_front() ? self::to_front( $url ) : $url );
		exit;
	}

	/**
	 * Print the pending notice.
	 */
	public static function notice() {
		$key = 'bdaci_pa_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( is_array( $n ) && self::$front ) {
			delete_transient( $key );
			echo '<div class="notice-bar ' . ( 'error' === $n[1] ? 'err' : 'ok' ) . '" role="status">' . esc_html( $n[0] ) . '</div>';
		} elseif ( is_array( $n ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $n[1] ? 'error' : 'success', esc_html( $n[0] ) );
		}
	}

	/**
	 * Start of an admin form.
	 *
	 * @param string $action Action (without prefix).
	 * @param string $nonce  Nonce action.
	 * @param string $attrs  Attributes.
	 * @return string
	 */
	private static function form_open( $action, $nonce, $attrs = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attrs . '><input type="hidden" name="action" value="bdaci_pa_' . esc_attr( $action ) . '">' . wp_nonce_field( $nonce, '_wpnonce', true, false ) . self::front_field();
	}

	/**
	 * Pick card (checkbox or radio).
	 *
	 * @param string $type  checkbox|radio.
	 * @param string $name  Field.
	 * @param string $value Value.
	 * @param bool   $on    Checked.
	 * @param string $label Label.
	 * @param string $sub   Sub line.
	 * @param string $c     Colour class.
	 * @param string $icon  Icon.
	 * @return string
	 */
	private static function pick( $type, $name, $value, $on, $label, $sub, $c, $icon ) {
		return '<label class="pick c-' . esc_attr( $c ) . '"><input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $on, true, false ) . '><span class="ic">' . BDACI_PA_Portal::icon( $icon ) . '</span><span class="tx"><b>' . esc_html( $label ) . '</b><span>' . esc_html( $sub ) . '</span></span><span class="ck" aria-hidden="true"></span></label>';
	}

	/**
	 * Section pick cards.
	 *
	 * @param string $name     Field.
	 * @param array  $selected Selected.
	 * @return string
	 */
	private static function section_picks( $name, array $selected, $maps = true ) {
		$all = BDACI_PA_Sections::all();
		static $n = 0;
		++$n;
		$h   = '<div class="sec-search"><label class="sr-only screen-reader-text" for="bdaci-secq-' . $n . '">' . esc_html__( 'Search sections', 'bdaci-portal-access' ) . '</label><input type="search" id="bdaci-secq-' . $n . '" class="sec-q" placeholder="' . esc_attr__( 'Search sections, e.g. minutes, finance, bylaws', 'bdaci-portal-access' ) . '" autocomplete="off"><span class="small muted sec-count" aria-live="polite"></span></div><div class="secgroups">';
		foreach ( BDACI_PA_Sections::groups() as $g => $grp ) {
			$cards = '';
			foreach ( $grp[2] as $id ) {
				if ( ! isset( $all[ $id ] ) ) {
					continue;
				}
				$sec    = $all[ $id ];
				$places = BDACI_PA_Sections::places();
				$where  = array();
				foreach ( BDACI_PA_Sections::where( $id ) as $w ) {
					$where[] = $places[ $w[0] ][1] ?? $w[0];
				}
				/* translators: %s: page names */
				$sub    = $where ? sprintf( __( 'Shows on: %s', 'bdaci-portal-access' ), implode( ', ', array_unique( $where ) ) ) : $sec[1];
				$sub   .= BDACI_PA_Sections::is_hidden( $id ) ? ' · ' . __( 'hidden', 'bdaci-portal-access' ) : '';
				$cards .= self::pick( 'checkbox', $name . '[]', $id, in_array( $id, $selected, true ), $sec[0], $sub, $sec[2], $sec[3] );
			}
			if ( 'added' === $g && $maps ) {
				$cards .= '<a class="pick pick-new" href="#bdaci-newsec" onclick="var d=document.getElementById(\'bdaci-newsec\');if(d){d.open=true;}"><span class="ic">+</span><span class="tx"><b>' . esc_html__( 'New section', 'bdaci-portal-access' ) . '</b><span>' . esc_html__( 'Add a page to the side menu', 'bdaci-portal-access' ) . '</span></span></a>';
			}
			if ( '' === $cards ) {
				continue;
			}
			if ( ! $maps ) {
				$h .= '<div class="secgroup compact"><div class="secgroup-h"><b>' . esc_html( $grp[0] ) . '</b></div><div class="picks">' . $cards . '</div></div>';
				continue;
			}
			$spots = array();
			foreach ( $grp[2] as $id ) {
				if ( isset( $all[ $id ] ) ) {
					$spots[ $id ] = array( BDACI_PA_Sections::spot( $id ), array_values( array_unique( wp_list_pluck( BDACI_PA_Sections::where( $id ), 0 ) ) ) );
				}
			}
			$h .= '<div class="secgroup"><div class="secgroup-h"><div><b>' . esc_html( $grp[0] ) . '</b><span class="small muted">' . esc_html( $grp[1] ) . '</span></div><span class="small muted mm-hint">' . esc_html__( 'Click or point at a section to see exactly where it shows. Click the map to zoom.', 'bdaci-portal-access' ) . '</span></div><div class="picks">' . $cards . '</div>' . self::minimap( $grp[3], '', $grp[0], $spots ) . '</div>';
		}
		$h .= '<p class="muted small sec-none" hidden>' . esc_html__( 'No sections match. Clear the search to see them all.', 'bdaci-portal-access' ) . '</p>';
		$h .= self::map_script() . self::search_script();
		return $h . '</div>';
	}

	/**
	 * Filter section cards as you type (printed once).
	 *
	 * @return string
	 */
	public static function search_script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		/* translators: 1: shown, 2: total */
		$fmt = esc_js( __( '%1$s of %2$s sections', 'bdaci-portal-access' ) );
		return "<script>(function(){function norm(s){return (s||'').toLowerCase().normalize('NFD').replace(/[\\u0300-\\u036f]/g,'');}document.addEventListener('input',function(e){var q=e.target;if(!q.classList||!q.classList.contains('sec-q')){return;}var box=q.closest('.sec-search').nextElementSibling;if(!box){return;}var words=norm(q.value).split(/\\s+/).filter(Boolean),shown=0,total=0;box.querySelectorAll('.secgroup').forEach(function(g){var any=false,gt=norm((g.querySelector('.secgroup-h b')||{}).textContent);g.querySelectorAll('.pick').forEach(function(c){if(c.classList.contains('pick-new')){c.hidden=words.length>0;return;}total++;var t=gt+' '+norm(c.textContent),ok=words.every(function(w){return t.indexOf(w)!==-1;});c.hidden=!ok;if(ok){shown++;any=true;}});g.hidden=!any;});var none=box.querySelector('.sec-none');if(none){none.hidden=shown>0;}var cnt=q.parentNode.querySelector('.sec-count');if(cnt){cnt.textContent=words.length?'{$fmt}'.replace('%1\$s',shown).replace('%2\$s',total):'';}});document.addEventListener('keydown',function(e){if(e.key==='Enter'&&e.target.classList&&e.target.classList.contains('sec-q')){e.preventDefault();}});})();</script>";
	}

	/**
	 * A small drawing of the portal (top menu, side menu, page) with some places highlighted.
	 *
	 * @param array  $on   Place keys to highlight.
	 * @param string $id   Element id (for the live preview).
	 * @return string
	 */
	public static function minimap( array $on, $id = '', $title = '', array $spots = array() ) {
		$places = BDACI_PA_Sections::places();
		$item   = static function ( $k, $label ) use ( $on ) {
			return '<span data-i="' . esc_attr( $k ) . '"' . ( in_array( $k, $on, true ) ? ' class="on"' : '' ) . '>' . esc_html( $label ) . '</span>';
		};
		$top   = '';
		$board = '';
		$coms  = '';
		foreach ( $places as $k => $p ) {
			if ( 'top' === $p[0] ) {
				$top .= $item( $k, $p[1] );
			} elseif ( 'board' === $p[0] ) {
				$board .= $item( $k, $p[1] );
			} else {
				$coms .= $item( $k, $p[1] );
			}
		}
		$any  = (bool) array_intersect( $on, array_keys( $places ) );
		$main = '<i></i><i></i><i class="s"></i>';
		if ( $spots ) {
			$main = '<strong class="mm-title">' . esc_html( $title ) . '</strong><div class="mm-spots">';
			foreach ( $spots as $sid => $sp ) {
				$main .= '<span class="mm-spot" data-spot="' . esc_attr( $sid ) . '" data-p="' . esc_attr( implode( ' ', $sp[1] ) ) . '">' . esc_html( $sp[0] ) . '</span>';
			}
			$main .= '</div>';
		}
		return '<div class="mm' . ( $spots ? ' mm-big' : '' ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' role="button" tabindex="0" title="' . esc_attr__( 'Click to zoom', 'bdaci-portal-access' ) . '" aria-label="' . esc_attr__( 'Map of the portal. Click to zoom.', 'bdaci-portal-access' ) . '"><span class="mm-zoombtn" aria-hidden="true">&#x2922; ' . esc_html__( 'Zoom', 'bdaci-portal-access' ) . '</span><div class="mm-top"><i class="mm-logo"></i>' . $top . '<i class="mm-me"></i></div><div class="mm-body"><div class="mm-side"><em>' . esc_html__( 'Board', 'bdaci-portal-access' ) . '</em>' . $board . '<em>' . esc_html__( 'Committees', 'bdaci-portal-access' ) . '</em>' . $coms . '</div><div class="mm-main' . ( $any ? ' on' : '' ) . '">' . $main . '</div></div></div>';
	}

	/**
	 * Script for the page maps: point at a section to spotlight it, click a map to zoom. Printed once.
	 *
	 * @return string
	 */
	public static function map_script() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		$close = esc_js( __( 'Close', 'bdaci-portal-access' ) );
		return '<script>(function(){function spot(card){var g=card.closest(".secgroup");if(!g)return;var i=card.querySelector("input");if(!i)return;var m=g.querySelector(".mm-big");if(!m)return;m.querySelectorAll(".hit").forEach(function(e){e.classList.remove("hit");});var s=m.querySelector("[data-spot=\""+i.value+"\"]");if(!s)return;s.classList.add("hit");(s.getAttribute("data-p")||"").split(" ").forEach(function(k){var e=m.querySelector("[data-i=\""+k+"\"]");if(e)e.classList.add("hit");});m.classList.add("spotting");}function clear(card){var g=card.closest(".secgroup"),m=g&&g.querySelector(".mm-big");if(!m)return;m.classList.remove("spotting");m.querySelectorAll(".hit").forEach(function(e){e.classList.remove("hit");});}document.querySelectorAll(".secgroup .pick").forEach(function(c){c.addEventListener("mouseenter",function(){spot(c);});c.addEventListener("focusin",function(){spot(c);});c.addEventListener("click",function(){spot(c);});c.addEventListener("mouseleave",function(){clear(c);});});var dlg;function zoom(m){if(!dlg){dlg=document.createElement("dialog");dlg.className="bp mm-dialog";dlg.innerHTML="<button type=\"button\" class=\"button mm-close\">' . $close . '</button><div class=\"mm-zoom\"></div>";document.body.appendChild(dlg);dlg.querySelector(".mm-close").onclick=function(){dlg.close();};dlg.addEventListener("click",function(e){if(e.target===dlg)dlg.close();});}var c=m.cloneNode(true);c.removeAttribute("id");c.removeAttribute("role");c.removeAttribute("tabindex");var z=dlg.querySelector(".mm-zoom");z.innerHTML="";z.appendChild(c);if(dlg.showModal)dlg.showModal();else dlg.setAttribute("open","");}document.addEventListener("click",function(e){var m=e.target.closest&&e.target.closest(".mm[role=button]");if(m)zoom(m);});document.addEventListener("keydown",function(e){if((e.key==="Enter"||e.key===" ")&&e.target.classList&&e.target.classList.contains("mm")&&e.target.getAttribute("role")==="button"){e.preventDefault();zoom(e.target);}});})();</script>';
	}

	/**
	 * Live "where this file will appear" panel (updated as sections are ticked).
	 *
	 * @return string
	 */
	private static function shows_panel() {
		$data = array();
		foreach ( array_keys( BDACI_PA_Sections::all() ) as $id ) {
			$data[ $id ] = BDACI_PA_Sections::where( $id );
		}
		$who = array();
		foreach ( BDACI_PA_Sections::who_options() as $k => $w ) {
			$who[ $k ] = $w[0];
		}
		$h  = '<div class="shows" id="bdaci-shows"><div>' . self::minimap( array(), 'bdaci-shows-map' ) . '</div><div><b>' . esc_html__( 'Where this file will appear', 'bdaci-portal-access' ) . '</b><ul id="bdaci-shows-list"><li class="muted">' . esc_html__( 'Pick a section above to see where the file will show.', 'bdaci-portal-access' ) . '</li></ul><p class="small muted" id="bdaci-shows-who"></p><p class="small muted">' . esc_html__( 'It is also listed under "Recently posted" on the Home page for the people who can open it.', 'bdaci-portal-access' ) . '</p></div></div>';
		$h .= '<script>(function(){var D=' . wp_json_encode( $data ) . ',W=' . wp_json_encode( $who ) . ',T=' . wp_json_encode(
			array(
				'none'   => __( 'Pick a section above to see where the file will show.', 'bdaci-portal-access' ),
				'who'    => __( 'Who can open it:', 'bdaci-portal-access' ),
				'nobody' => __( 'pick at least one group', 'bdaci-portal-access' ),
				'arch'   => __( '"Approved minutes archive", sorted by year', 'bdaci-portal-access' ),
			)
		) . ';var box=document.getElementById("bdaci-shows");if(!box)return;var form=box.closest("form");function upd(){var on={},items=[],sel=[];form.querySelectorAll("input[name=\'sections[]\']:checked").forEach(function(c){sel.push(c.value);});var com=sel.indexOf("edu")>-1||sel.indexOf("fincom")>-1,min=sel.indexOf("minutes")>-1;sel.forEach(function(v){(D[v]||[]).forEach(function(w){if(com&&v==="minutes"&&w[0]==="governance")return;if(min&&(v==="edu"||v==="fincom"))w=[w[0],w[1],T.arch];on[w[0]]=1;items.push(w);});});box.querySelectorAll("#bdaci-shows-map [data-i]").forEach(function(s){s.classList.toggle("on",!!on[s.getAttribute("data-i")]);});box.querySelector(".mm-main").classList.toggle("on",items.length>0);var ul=document.getElementById("bdaci-shows-list");ul.innerHTML="";if(!items.length){var li=document.createElement("li");li.className="muted";li.textContent=T.none;ul.appendChild(li);}items.forEach(function(w){var li=document.createElement("li"),b=document.createElement("b");b.textContent=w[1];li.appendChild(b);li.appendChild(document.createTextNode(" \u2014 "+w[2]));ul.appendChild(li);});var who=[];form.querySelectorAll("input[name=\'who[]\']:checked").forEach(function(c){who.push(W[c.value]);});document.getElementById("bdaci-shows-who").textContent=T.who+" "+(who.length?who.join(", "):T.nobody);}form.addEventListener("change",upd);upd();})();</script>';
		return $h;
	}

	/**
	 * "Who can open it" pick cards.
	 *
	 * @param string $name     Field.
	 * @param array  $selected Selected.
	 * @return string
	 */
	private static function who_picks( $name, array $selected ) {
		$h = '<div class="picks who">';
		foreach ( BDACI_PA_Sections::who_options() as $k => $w ) {
			$h .= self::pick( 'checkbox', $name . '[]', $k, in_array( $k, $selected, true ), $w[0], $w[1], $w[2], $w[3] );
		}
		return $h . '</div>';
	}

	/**
	 * Posted list.
	 *
	 * @param string $k Field.
	 * @return array
	 */
	private static function posted_list( $k ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers run guard() first.
		return isset( $_POST[ $k ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $k ] ) ) : array();
	}

	/**
	 * Posted text.
	 *
	 * @param string $k Field.
	 * @return string
	 */
	private static function posted( $k ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers run guard() first.
		return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Sections & Uploads
	 * ------------------------------------------------------------------ */

	/**
	 * Sections & Uploads screen.
	 */
	public static function page_docs() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		self::open_page( __( 'Sections & Uploads', 'bdaci-portal-access' ) );
		self::notice();
		$missing = array_diff( array_keys( BDACI_PA_Portal::screens() ), array_keys( BDACI_PA_Portal::screen_map() ) );
		if ( ! $root || $missing ) {
			echo '<div class="notice notice-warning inline"><p>' . ( count( $missing ) >= count( BDACI_PA_Portal::screens() ) - 1 ? esc_html__( 'The portal pages are not set up yet.', 'bdaci-portal-access' ) : esc_html( sprintf( /* translators: %s: list of pages */ __( 'Some portal pages are missing: %s.', 'bdaci-portal-access' ), implode( ', ', array_map( static function ( $k ) { return BDACI_PA_Portal::screens()[ $k ][0]; }, $missing ) ) ) ) ) . ' ' . self::form_open( 'setup_pages', 'bdaci_pa_setup_pages', 'style="display:inline"' ) . '<button class="button button-primary">' . esc_html__( 'Create the missing pages', 'bdaci-portal-access' ) . '</button></form></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<div class="bp bp-admin">';

		// Upload form.
		$out  = self::form_open( 'doc_upload', 'bdaci_pa_doc_upload', 'enctype="multipart/form-data" class="panel" style="display:grid;gap:16px"' );
		$out .= '<h2>' . esc_html__( 'Upload a document', 'bdaci-portal-access' ) . '</h2><p class="muted small" style="margin-top:-8px">' . esc_html__( 'Files uploaded here are private. They only open for signed-in people who are allowed to see them.', 'bdaci-portal-access' ) . '</p>';
		$out .= '<div class="bdaci-dropzone" id="bdaci-dropzone" tabindex="0" role="button" aria-describedby="bdaci-dz-help" style="max-width:none"><span class="dashicons dashicons-upload" aria-hidden="true"></span><strong>' . esc_html__( 'Drag files here, or paste them', 'bdaci-portal-access' ) . '</strong><span id="bdaci-dz-help" class="description">' . wp_kses( __( 'Copy a file and press <kbd>Ctrl</kbd>+<kbd>V</kbd> (<kbd>⌘</kbd>+<kbd>V</kbd> on a Mac) anywhere on this page, or click here to browse. PDF, Word, Excel, PowerPoint, JPG, PNG, TXT or CSV.', 'bdaci-portal-access' ), array( 'kbd' => array() ) ) . ' ' . esc_html( sprintf( /* translators: %s: size */ __( 'Up to %s each.', 'bdaci-portal-access' ), size_format( wp_max_upload_size() ) ) ) . '</span><input type="file" id="bdaci-file" name="bdaci_file[]" multiple required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt,.csv"></div><ul class="bdaci-queue" id="bdaci-queue" aria-live="polite" style="max-width:none"></ul>';
		$out .= '<div class="grid3"><div class="field" style="grid-column:span 2"><label for="bdaci-title">' . esc_html__( 'Title', 'bdaci-portal-access' ) . '</label><input type="text" id="bdaci-title" name="title" placeholder="' . esc_attr__( 'e.g. Board Meeting Agenda – January 2027', 'bdaci-portal-access' ) . '"><span class="small muted">' . esc_html__( 'Used when you upload one file. With several files, each is titled from its file name.', 'bdaci-portal-access' ) . '</span></div><div class="field"><label for="bdaci-date">' . esc_html__( 'Document date', 'bdaci-portal-access' ) . '</label><input type="date" id="bdaci-date" name="doc_date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"><span class="small muted">' . esc_html__( 'Today unless you change it, e.g. to the meeting date. Sorts the minutes archive by year. The upload time is recorded automatically.', 'bdaci-portal-access' ) . '</span></div></div>';
		$out .= '<div role="group" aria-labelledby="bdaci-sec-h"><div class="pick-h"><b id="bdaci-sec-h">' . esc_html__( 'Section', 'bdaci-portal-access' ) . '</b><span class="small muted">' . esc_html__( 'Pick one or more. The file appears in each one.', 'bdaci-portal-access' ) . '</span></div>' . self::section_picks( 'sections', array() ) . '<input type="hidden" name="sections_sent" value="1"></div>';
		$out .= '<div role="group" aria-labelledby="bdaci-who-h"><div class="pick-h"><b id="bdaci-who-h">' . esc_html__( 'Who can open it', 'bdaci-portal-access' ) . '</b><span class="small muted">' . esc_html__( 'Pick one or more. Portal Admins can always open everything.', 'bdaci-portal-access' ) . '</span></div>' . self::who_picks( 'who', array( 'board' ) ) . '</div>';
		$out .= self::shows_panel();
		$out .= '<div><button class="btn btn-primary" id="bdaci-upload-btn" type="submit">' . esc_html__( 'Upload', 'bdaci-portal-access' ) . '</button></div></form>';
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.

		echo self::sections_panel( $root ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::documents_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::history_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div></div>';
	}

	/**
	 * New section + Sections & visibility.
	 *
	 * @param int $root Portal home.
	 * @return string
	 */
	private static function sections_panel( $root ) {
		$h  = '<section class="panel" id="bdaci-visibility"><div class="panel-h"><h2>' . esc_html__( 'Sections & visibility', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html__( 'Hide a section until it\'s ready. Hidden sections disappear for everyone except Portal Admins.', 'bdaci-portal-access' ) . '</span></div><ul class="secmgr">';
		$row = function ( $label, $where, $who, $hidden, $toggle, $extra = '' ) {
			return '<li>' . BDACI_PA_Portal::icon( 'folder', 16 ) . '<div class="grow"><b>' . esc_html( $label ) . '</b> <span class="muted small">' . esc_html( $where ) . '</span>' . ( $who ? '<div class="tags"><span class="tag lock">' . BDACI_PA_Portal::icon( 'lock', 10 ) . ' ' . esc_html( $who ) . '</span></div>' : '' ) . '</div><span class="pill ' . ( $hidden ? 'pill-muted' : 'pill-ok' ) . '">' . ( $hidden ? esc_html__( 'Hidden', 'bdaci-portal-access' ) : esc_html__( 'Visible', 'bdaci-portal-access' ) ) . '</span>' . $toggle . $extra . '</li>';
		};
		if ( $root ) {
			$pages = get_pages( array( 'child_of' => $root, 'sort_column' => 'menu_order,post_title', 'post_status' => array( 'publish', 'private', 'draft' ) ) );
			foreach ( $pages as $p ) {
				$own    = '1' === (string) get_post_meta( $p->ID, BDACI_PA_Access::HIDDEN, true );
				$level  = BDACI_PA_Access::level( $p->ID );
				$menu   = (string) get_post_meta( $p->ID, BDACI_PA_Portal::META_MENU, true );
				$where  = 'board' === $menu ? __( 'Board menu', 'bdaci-portal-access' ) : ( 'committees' === $menu ? __( 'Committees menu', 'bdaci-portal-access' ) : ( 'top' === $menu ? __( 'Top menu', 'bdaci-portal-access' ) : __( 'Portal page', 'bdaci-portal-access' ) ) );
				$raw    = (string) get_post_meta( $p->ID, BDACI_PA_Access::META, true );
				$who    = BDACI_PA_Sections::who_text( 'hidden' === $level ? $raw : $level );
				$toggle = '';
				if ( $own || 'hidden' !== $level ) {
					$toggle = self::form_open( 'section_visibility', 'bdaci_pa_section_visibility_' . $p->ID, 'class="inline-form"' ) . '<input type="hidden" name="id" value="' . (int) $p->ID . '"><input type="hidden" name="hide" value="' . ( $own ? '0' : '1' ) . '"><button class="btn btn-sm">' . ( $own ? esc_html__( 'Show', 'bdaci-portal-access' ) : esc_html__( 'Hide', 'bdaci-portal-access' ) ) . '</button></form>';
				}
				$sec   = (string) get_post_meta( $p->ID, BDACI_PA_Sections::META_PAGE, true );
				$extra = '<a class="btn btn-sm" href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html__( 'View', 'bdaci-portal-access' ) . '</a>';
				if ( $sec ) {
					$extra .= self::form_open( 'section_delete', 'bdaci_pa_section_delete_' . $sec, 'class="inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Remove this section from the menu? Its documents are kept.', 'bdaci-portal-access' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . esc_attr( $sec ) . '"><button class="btn btn-sm">' . esc_html__( 'Remove', 'bdaci-portal-access' ) . '</button></form>';
					$where .= ' · ' . __( 'added by you', 'bdaci-portal-access' );
				}
				$h .= $row( get_the_title( $p ), $where, $who, $own || 'hidden' === $level, $toggle, $extra );
			}
		}
		foreach ( array( 'bylaws', 'policies', 'strategic', 'reports' ) as $f ) {
			$hid = BDACI_PA_Sections::is_hidden( $f );
			$tog = self::form_open( 'folder_visibility', 'bdaci_pa_folder_visibility', 'class="inline-form"' ) . '<input type="hidden" name="id" value="' . esc_attr( $f ) . '"><input type="hidden" name="hide" value="' . ( $hid ? '0' : '1' ) . '"><button class="btn btn-sm">' . ( $hid ? esc_html__( 'Show', 'bdaci-portal-access' ) : esc_html__( 'Hide', 'bdaci-portal-access' ) ) . '</button></form>';
			$h  .= $row( BDACI_PA_Sections::label( $f ), __( 'Folder in Governance', 'bdaci-portal-access' ), '', $hid, $tog );
		}
		$h .= '</ul>';

		$menus = self::pick( 'radio', 'menu', 'board', true, __( 'Board', 'bdaci-portal-access' ), __( 'Under BOARD in the left menu', 'bdaci-portal-access' ), 'board', 'gavel' ) . self::pick( 'radio', 'menu', 'committees', false, __( 'Committees', 'bdaci-portal-access' ), __( 'Under COMMITTEES in the left menu', 'bdaci-portal-access' ), 'edu', 'grad' );
		$h    .= '<details id="bdaci-newsec" style="margin-top:14px"><summary>' . esc_html__( 'Add a new section to the menu', 'bdaci-portal-access' ) . '</summary><div class="newsec">' . self::form_open( 'section_create', 'bdaci_pa_section_create', 'style="display:grid;gap:14px"' ) .
			'<div class="field"><label for="ns-name">' . esc_html__( 'Section name', 'bdaci-portal-access' ) . '</label><input id="ns-name" name="label" maxlength="60" required placeholder="' . esc_attr__( 'e.g. Audit Committee', 'bdaci-portal-access' ) . '"></div>' .
			'<div><div class="pick-h"><b>' . esc_html__( 'Show in the menu under', 'bdaci-portal-access' ) . '</b></div><div class="picks who">' . $menus . '</div></div>' .
			'<div><div class="pick-h"><b>' . esc_html__( 'Who can see this section', 'bdaci-portal-access' ) . '</b><span class="small muted">' . esc_html__( 'Others see it in the menu as "Restricted".', 'bdaci-portal-access' ) . '</span></div>' . self::who_picks( 'who', array( 'board' ) ) . '</div>' .
			'<div class="row-btns"><button class="btn btn-primary" type="submit">' . esc_html__( 'Create section', 'bdaci-portal-access' ) . '</button></div></form></div></details>';
		return $h . '</section>';
	}

	/**
	 * Documents table with edit and delete.
	 *
	 * @return string
	 */
	private static function documents_panel() {
		$docs = BDACI_PA_Documents::all();
		$h    = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Documents', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html( sprintf( /* translators: %d: count */ _n( '%d document', '%d documents', count( $docs ), 'bdaci-portal-access' ), count( $docs ) ) ) . '</span></div>';
		if ( ! $docs ) {
			return $h . '<p class="muted">' . esc_html__( 'No documents yet.', 'bdaci-portal-access' ) . '</p></section>';
		}
		$h .= '<ul class="list">';
		$i  = 0;
		foreach ( $docs as $d ) {
			if ( 15 === $i++ ) {
				/* translators: %d: number of documents */
				$h .= '</ul><details><summary class="small" style="margin:12px 0">' . esc_html( sprintf( __( 'Show %d older documents', 'bdaci-portal-access' ), count( $docs ) - 15 ) ) . '</summary><ul class="list">';
			}
			$level = (string) get_post_meta( $d->ID, BDACI_PA_Access::META, true );
			$secs  = BDACI_PA_Sections::doc_sections( $d->ID );
			$tags  = '';
			$all   = BDACI_PA_Sections::all();
			foreach ( $secs as $s ) {
				if ( isset( $all[ $s ] ) ) {
					$tags .= '<span class="tag c-' . esc_attr( $all[ $s ][2] ) . '">' . esc_html( $all[ $s ][0] ) . '</span>';
				}
			}
			$tags .= '<span class="tag lock">' . BDACI_PA_Portal::icon( 'lock', 10 ) . ' ' . esc_html( BDACI_PA_Sections::who_text( $level ) ) . '</span>';
			$file  = (string) get_post_meta( $d->ID, '_wp_attached_file', true );
			$edit  = self::form_open( 'doc_edit', 'bdaci_pa_doc_edit_' . $d->ID, 'style="display:grid;gap:12px;margin-top:10px"' ) . '<input type="hidden" name="id" value="' . (int) $d->ID . '">' .
				'<div class="grid3"><div class="field" style="grid-column:span 2"><label for="bdaci-t-' . (int) $d->ID . '">' . esc_html__( 'Title', 'bdaci-portal-access' ) . '</label><input id="bdaci-t-' . (int) $d->ID . '" name="title" value="' . esc_attr( get_the_title( $d ) ) . '"><span class="small muted">' . esc_html__( 'The name members see in the lists.', 'bdaci-portal-access' ) . '</span></div><div class="field"><label for="bdaci-d-' . (int) $d->ID . '">' . esc_html__( 'Document date', 'bdaci-portal-access' ) . '</label><input id="bdaci-d-' . (int) $d->ID . '" type="date" name="doc_date" value="' . esc_attr( BDACI_PA_Sections::doc_date( $d->ID ) ) . '"></div></div>' .
				'<div class="field"><label for="bdaci-fn-' . (int) $d->ID . '">' . esc_html__( 'File name', 'bdaci-portal-access' ) . '</label><span class="fn-row"><input id="bdaci-fn-' . (int) $d->ID . '" name="file_name" value="' . esc_attr( pathinfo( $file, PATHINFO_FILENAME ) ) . '" maxlength="120"><span class="fn-ext">.' . esc_html( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) . '</span></span><span class="small muted">' . esc_html__( 'The name the file has when someone downloads it. The type (.pdf, .docx…) stays the same. Links to the document keep working.', 'bdaci-portal-access' ) . '</span></div>' .
				'<div><div class="pick-h"><b>' . esc_html__( 'Section (move it here)', 'bdaci-portal-access' ) . '</b><span class="small muted">' . esc_html__( 'To move the file, untick where it is now and tick where it should go. Tick several to show it in more than one place.', 'bdaci-portal-access' ) . '</span></div>' . self::section_picks( 'sections', $secs, false ) . '</div><div><div class="pick-h"><b>' . esc_html__( 'Who can open it', 'bdaci-portal-access' ) . '</b></div>' . self::who_picks( 'who', BDACI_PA_Sections::level_to_who( $level ) ) . '</div>' .
				'<div class="row-btns"><button class="btn btn-primary btn-sm" name="do" value="save">' . esc_html__( 'Save changes', 'bdaci-portal-access' ) . '</button><button class="btn btn-sm" name="do" value="delete" style="color:var(--danger)" onclick="return confirm(\'' . esc_js( __( 'Delete this file permanently?', 'bdaci-portal-access' ) ) . '\')">' . esc_html__( 'Delete', 'bdaci-portal-access' ) . '</button></div></form>';
			$by    = get_userdata( $d->post_author );
			$h    .= '<li style="align-items:flex-start">' . BDACI_PA_Portal::thumb( get_the_title( $d ), $file ) . '<div class="grow"><b><a href="' . esc_url( BDACI_PA_Documents::link( $d->ID ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $d ) ) . '</a></b><span class="muted small">' . esc_html( date_i18n( 'M j, Y', strtotime( BDACI_PA_Sections::doc_date( $d->ID ) ) ) . ' · ' . ( $by ? sprintf( /* translators: 1: name, 2: date and time */ __( 'uploaded by %1$s on %2$s', 'bdaci-portal-access' ), $by->display_name, BDACI_PA_Portal::uploaded_at( $d ) ) : sprintf( /* translators: %s: date and time */ __( 'uploaded %s', 'bdaci-portal-access' ), BDACI_PA_Portal::uploaded_at( $d ) ) ) ) . ' · <span class="fn">' . esc_html( basename( $file ) ) . '</span> · <code>[bdaci_document id="' . (int) $d->ID . '"]</code></span><div class="tags">' . $tags . '</div>' . self::doc_changes( $d->ID ) . '<details><summary class="small">' . esc_html__( 'Edit, rename or move', 'bdaci-portal-access' ) . '</summary>' . $edit . '</details></div></li>';
		}
		return $h . '</ul>' . ( count( $docs ) > 15 ? '</details>' : '' ) . '</section>';
	}

	/**
	 * Upload history: stats and a day-by-day timeline.
	 *
	 * @return string
	 */
	private static function history_panel() {
		$docs   = BDACI_PA_Documents::all();
		$filter = isset( $_GET['hist'] ) ? sanitize_text_field( wp_unslash( $_GET['hist'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$all    = BDACI_PA_Sections::all();
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$people = array();
		$recent = 0;
		foreach ( $docs as $d ) {
			if ( $d->post_date_gmt >= $since ) {
				++$recent;
				$by = get_userdata( $d->post_author );
				$n  = $by ? $by->display_name : '?';
				$people[ $n ] = ( $people[ $n ] ?? 0 ) + 1;
			}
		}
		arsort( $people );
		$top = $people ? array_key_first( $people ) : '–';
		$h   = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Upload history', 'bdaci-portal-access' ) . '</h2><a class="btn btn-sm" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_history_export' ), 'bdaci_pa_history_export' ) ) . '">' . esc_html__( 'Export CSV', 'bdaci-portal-access' ) . '</a></div>';
		$h  .= '<div class="hist-stats"><div class="so-stat"><span class="k">' . esc_html__( 'Uploads, last 30 days', 'bdaci-portal-access' ) . '</span><span class="v tabnum">' . (int) $recent . '</span></div><div class="so-stat"><span class="k">' . esc_html__( 'People uploading', 'bdaci-portal-access' ) . '</span><span class="v tabnum">' . count( $people ) . '</span></div><div class="so-stat"><span class="k">' . esc_html__( 'Most active', 'bdaci-portal-access' ) . '</span><span class="v" style="font-size:1rem;padding-top:6px">' . esc_html( $top ) . '</span></div></div>';
		$h  .= '<div class="years" role="group" aria-label="' . esc_attr__( 'Filter by area', 'bdaci-portal-access' ) . '" style="margin-bottom:14px">';
		foreach ( array( 'all', 'Meetings', 'Governance', 'Sign-off', 'Committees', 'Board' ) as $g ) {
			$h .= '<a href="' . esc_url( add_query_arg( 'hist', $g ) ) . '" aria-current="' . ( $filter === $g ? 'true' : 'false' ) . '">' . esc_html( 'all' === $g ? __( 'All', 'bdaci-portal-access' ) : $g ) . '</a>';
		}
		$h   .= '</div>';
		$last = '';
		$body = '';
		$tz   = wp_timezone();
		foreach ( array_slice( $docs, 0, 150 ) as $d ) {
			$secs = BDACI_PA_Sections::doc_sections( $d->ID );
			if ( 'all' !== $filter ) {
				$match = false;
				foreach ( $secs as $s ) {
					if ( isset( $all[ $s ] ) && $all[ $s ][1] === $filter ) {
						$match = true;
					}
				}
				if ( ! $match ) {
					continue;
				}
			}
			$day = substr( $d->post_date, 0, 10 );
			if ( $day !== $last ) {
				$today = current_time( 'Y-m-d' );
				$label = $day === $today ? __( 'Today', 'bdaci-portal-access' ) : ( gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) === $day ? __( 'Yesterday', 'bdaci-portal-access' ) : date_i18n( 'D, F j, Y', strtotime( $day ) ) );
				$body .= ( $last ? '</ul>' : '' ) . '<p class="hist-day">' . esc_html( $label ) . '</p><ul class="tl">';
				$last  = $day;
			}
			$tags = '';
			foreach ( $secs as $s ) {
				if ( isset( $all[ $s ] ) ) {
					$tags .= '<span class="tag c-' . esc_attr( $all[ $s ][2] ) . '">' . esc_html( $all[ $s ][0] ) . '</span>';
				}
			}
			$tags .= '<span class="tag lock">' . BDACI_PA_Portal::icon( 'lock', 10 ) . ' ' . esc_html( BDACI_PA_Sections::who_text( (string) get_post_meta( $d->ID, BDACI_PA_Access::META, true ) ) ) . '</span>';
			$by    = get_userdata( $d->post_author );
			$file  = get_attached_file( $d->ID );
			$size  = $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '';
			$fresh = $d->post_date_gmt >= gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$body .= '<li' . ( $fresh ? ' class="new"' : '' ) . '>' . BDACI_PA_Portal::thumb( get_the_title( $d ), (string) get_post_meta( $d->ID, '_wp_attached_file', true ) ) . '<div class="grow"><b>' . esc_html( get_the_title( $d ) ) . '</b>' . ( $fresh ? ' <span class="pill pill-ok">' . esc_html__( 'New', 'bdaci-portal-access' ) . '</span>' : '' ) . '<div class="tags">' . $tags . '</div><div class="by">' . ( $by ? BDACI_PA_Profile::avatar( $by->ID ) : '' ) . '<span>' . esc_html__( 'Uploaded by', 'bdaci-portal-access' ) . ' <b style="color:var(--fg)">' . esc_html( $by ? $by->display_name : '?' ) . '</b> ' . esc_html__( 'at', 'bdaci-portal-access' ) . ' <span class="tabnum">' . esc_html( mysql2date( get_option( 'time_format', 'g:i a' ), $d->post_date ) ) . '</span>' . ( $size ? ' · ' . esc_html( $size ) : '' ) . '</span></div></div></li>';
		}
		unset( $tz );
		$body .= $body ? '</ul>' : '<p class="muted small">' . esc_html__( 'Nothing uploaded in this area yet.', 'bdaci-portal-access' ) . '</p>';
		return $h . $body . '</section>';
	}

	/**
	 * Create the portal pages.
	 */
	public static function handle_setup_pages() {
		self::guard( 'bdaci_pa_setup_pages' );
		$made = BDACI_PA_Portal::setup_pages();
		$back = wp_get_referer() ? wp_get_referer() : self::url( 'bdaci-portal-docs' );
		/* translators: %s: list of pages */
		self::back( $back, $made ? sprintf( __( 'Created: %s.', 'bdaci-portal-access' ), implode( ', ', $made ) ) : __( 'All portal pages already exist.', 'bdaci-portal-access' ) );
	}

	/**
	 * New section.
	 */
	public static function handle_section_create() {
		self::guard( 'bdaci_pa_section_create' );
		$r = BDACI_PA_Sections::create_custom( self::posted( 'label' ), self::posted( 'menu' ), self::posted_list( 'who' ) );
		$u = self::url( 'bdaci-portal-docs#bdaci-visibility' );
		/* translators: %s: section */
		self::back( $u, is_wp_error( $r ) ? $r->get_error_message() : sprintf( __( 'Section "%s" created and added to the menu.', 'bdaci-portal-access' ), self::posted( 'label' ) ), is_wp_error( $r ) ? 'error' : 'success' );
	}

	/**
	 * Remove a section.
	 */
	public static function handle_section_delete() {
		$id = self::posted( 'id' );
		self::guard( 'bdaci_pa_section_delete_' . $id );
		BDACI_PA_Sections::delete_custom( $id );
		self::back( self::url( 'bdaci-portal-docs#bdaci-visibility' ), __( 'Section removed from the menu. Its documents are kept.', 'bdaci-portal-access' ) );
	}

	/**
	 * Hide / show a Governance folder.
	 */
	public static function handle_folder_visibility() {
		self::guard( 'bdaci_pa_folder_visibility' );
		$id = sanitize_key( self::posted( 'id' ) );
		if ( in_array( $id, array( 'bylaws', 'policies', 'strategic', 'reports' ), true ) ) {
			BDACI_PA_Sections::set_hidden( $id, '1' === self::posted( 'hide' ) );
		}
		$back = wp_get_referer() ? wp_get_referer() : self::url( 'bdaci-portal-docs' );
		/* translators: %s: folder */
		self::back( $back, sprintf( '1' === self::posted( 'hide' ) ? __( '"%s" is now hidden.', 'bdaci-portal-access' ) : __( '"%s" is now visible.', 'bdaci-portal-access' ), BDACI_PA_Sections::label( $id ) ) );
	}

	/**
	 * Edit or delete a document.
	 */
	public static function handle_doc_edit() {
		$id = absint( self::posted( 'id' ) );
		self::guard( 'bdaci_pa_doc_edit_' . $id );
		$u = self::url( 'bdaci-portal-docs' );
		if ( ! $id || ! get_post_meta( $id, BDACI_PA_Documents::META_PRIVATE, true ) ) {
			self::back( $u, __( 'Document not found.', 'bdaci-portal-access' ), 'error' );
		}
		if ( 'delete' === self::posted( 'do' ) ) {
			$t = get_the_title( $id );
			wp_delete_attachment( $id, true );
			BDACI_PA_Log::add( 'document_deleted', '', get_current_user_id(), $t );
			self::back( $u, __( 'Deleted.', 'bdaci-portal-access' ) );
		}
		$done  = array();
		$title = self::posted( 'title' );
		if ( '' !== $title && get_the_title( $id ) !== $title ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
		}
		$date = self::posted( 'doc_date' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			update_post_meta( $id, BDACI_PA_Sections::META_DATE, $date );
		}
		$fname = self::posted( 'file_name' );
		if ( '' !== $fname ) {
			$res = self::rename_file( $id, $fname );
			if ( is_wp_error( $res ) ) {
				self::back( $u, $res->get_error_message(), 'error' );
			}
			if ( $res ) {
				/* translators: %s: new file name */
				$done[] = sprintf( __( 'Renamed to %s.', 'bdaci-portal-access' ), $res );
			}
		}
		$old = BDACI_PA_Sections::doc_sections( $id );
		BDACI_PA_Sections::set_doc_sections( $id, self::posted_list( 'sections' ) );
		$new = BDACI_PA_Sections::doc_sections( $id );
		$a   = $old;
		$b   = $new;
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			$lbl = static function ( array $ids ) {
				return $ids ? implode( ', ', array_map( array( 'BDACI_PA_Sections', 'label' ), $ids ) ) : __( 'no section', 'bdaci-portal-access' );
			};
			self::add_change( $id, 'moved', $lbl( $old ), $lbl( $new ) );
			BDACI_PA_Log::add( 'document_moved', '', get_current_user_id(), get_the_title( $id ) . ': ' . $lbl( $old ) . ' → ' . $lbl( $new ) );
			/* translators: %s: sections */
			$done[] = sprintf( __( 'Moved to %s.', 'bdaci-portal-access' ), $lbl( $new ) );
		}
		$level = BDACI_PA_Sections::who_to_level( self::posted_list( 'who' ) );
		update_post_meta( $id, BDACI_PA_Access::META, $level );
		BDACI_PA_Log::add( 'document_updated', '', get_current_user_id(), get_the_title( $id ) . ' (' . $level . ')' );
		self::back( $u, trim( __( 'Document updated.', 'bdaci-portal-access' ) . ' ' . implode( ' ', $done ) ) );
	}

	/**
	 * Rename a private document's file, keeping its type and folder.
	 *
	 * @param int    $id   Attachment.
	 * @param string $want New name, with or without the extension.
	 * @return string|false|WP_Error New file name, false if unchanged, or an error.
	 */
	public static function rename_file( $id, $want ) {
		$path = get_attached_file( $id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'missing', __( 'The file could not be found on the server, so it was not renamed.', 'bdaci-portal-access' ) );
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$want = trim( preg_replace( '/\.' . preg_quote( $ext, '/' ) . '$/i', '', trim( $want ) ) );
		$base = sanitize_file_name( $want );
		$base = trim( preg_replace( '/\.+/', '-', $base ), '-_ ' );
		if ( '' === $base ) {
			return new WP_Error( 'empty', __( 'Type a file name using letters or numbers.', 'bdaci-portal-access' ) );
		}
		$base = mb_substr( $base, 0, 120 );
		if ( pathinfo( $path, PATHINFO_FILENAME ) === $base ) {
			return false;
		}
		$dir  = dirname( $path );
		$name = wp_unique_filename( $dir, $base . '.' . $ext );
		$dest = trailingslashit( $dir ) . $name;
		if ( ! @rename( $path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'rename', __( 'The server would not rename the file. Nothing was changed.', 'bdaci-portal-access' ) );
		}
		update_attached_file( $id, $dest );
		$old = basename( $path );
		self::add_change( $id, 'renamed', $old, $name );
		BDACI_PA_Log::add( 'document_renamed', '', get_current_user_id(), $old . ' → ' . $name );
		return $name;
	}

	/**
	 * Remember a rename or move on the document (newest last, up to 20).
	 *
	 * @param int    $id   Attachment.
	 * @param string $what renamed|moved.
	 * @param string $from From.
	 * @param string $to   To.
	 */
	private static function add_change( $id, $what, $from, $to ) {
		$list   = get_post_meta( $id, '_bdaci_doc_changes', true );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'what' => $what,
			'from' => $from,
			'to'   => $to,
			'by'   => get_current_user_id(),
			'at'   => current_time( 'mysql' ),
		);
		update_post_meta( $id, '_bdaci_doc_changes', array_slice( $list, -20 ) );
	}

	/**
	 * Renames and moves of a document, newest first.
	 *
	 * @param int $id Attachment.
	 * @return string
	 */
	public static function doc_changes( $id ) {
		$list = get_post_meta( $id, '_bdaci_doc_changes', true );
		if ( ! is_array( $list ) || ! $list ) {
			return '';
		}
		$items = '';
		foreach ( array_reverse( $list ) as $c ) {
			$by     = get_userdata( (int) $c['by'] );
			$verb   = 'moved' === $c['what'] ? __( 'Moved', 'bdaci-portal-access' ) : __( 'Renamed', 'bdaci-portal-access' );
			$items .= '<li>' . esc_html( $verb ) . ' <span class="muted">' . esc_html( $c['from'] ) . '</span> → <b>' . esc_html( $c['to'] ) . '</b> <span class="muted">· ' . esc_html( ( $by ? $by->display_name . ', ' : '' ) . mysql2date( 'M j, Y g:i A', $c['at'] ) ) . '</span></li>';
		}
		$last = end( $list );
		$sum  = ( 'moved' === $last['what'] ? __( 'Last moved', 'bdaci-portal-access' ) : __( 'Last renamed', 'bdaci-portal-access' ) ) . ' ' . mysql2date( 'M j, Y g:i A', $last['at'] );
		/* translators: %d: number of changes */
		return '<details class="doc-changes small"><summary>' . esc_html( $sum . ' · ' . sprintf( _n( '%d change', '%d changes', count( $list ), 'bdaci-portal-access' ), count( $list ) ) ) . '</summary><ul>' . $items . '</ul></details>';
	}

	/**
	 * Upload history as CSV.
	 */
	public static function handle_history_export() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bdaci_pa_history_export' );
		$rows = array( array( 'Uploaded', 'Title', 'File', 'Sections', 'Who can open it', 'Document date', 'Uploaded by' ) );
		foreach ( BDACI_PA_Documents::all() as $d ) {
			$by     = get_userdata( $d->post_author );
			$rows[] = array( $d->post_date, get_the_title( $d ), basename( (string) get_post_meta( $d->ID, '_wp_attached_file', true ) ), implode( '; ', array_map( array( 'BDACI_PA_Sections', 'label' ), BDACI_PA_Sections::doc_sections( $d->ID ) ) ), BDACI_PA_Sections::who_text( (string) get_post_meta( $d->ID, BDACI_PA_Access::META, true ) ), BDACI_PA_Sections::doc_date( $d->ID ), $by ? $by->display_name : '' );
		}
		self::csv( 'portal-upload-history-' . current_time( 'Y-m-d' ) . '.csv', $rows );
	}

	/**
	 * Send a CSV download.
	 *
	 * @param string $name File name.
	 * @param array  $rows Rows.
	 */
	private static function csv( $name, array $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( $rows as $r ) {
			// Neutralise spreadsheet formulas in user-entered text.
			$r = array_map( static function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; }, $r );
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Meetings
	 * ------------------------------------------------------------------ */

	/**
	 * Meetings screen.
	 */
	public static function page_meetings() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$edit    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$replies = isset( $_GET['replies'] ) ? absint( $_GET['replies'] ) : 0;
		// phpcs:enable
		$m = $edit ? BDACI_PA_Meetings::get( $edit ) : null;
		self::open_page( __( 'Meetings', 'bdaci-portal-access' ) );
		self::notice();
		echo '<div class="bp bp-admin">';
		if ( $replies && ( $rm = BDACI_PA_Meetings::get( $replies ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			echo '<section class="panel">' . BDACI_PA_Screens::event_detail( $rm ) . '<p style="margin-top:12px"><a href="' . esc_url( self::url( 'bdaci-portal-meetings' ) ) . '">' . esc_html__( '← All meetings', 'bdaci-portal-access' ) . '</a></p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$docs    = BDACI_PA_Documents::all();
		$doc_sel = function ( $name, $cur, $prefer ) use ( $docs ) {
			$o = '<option value="0">' . esc_html__( '— None yet —', 'bdaci-portal-access' ) . '</option>';
			usort( $docs, static function ( $a, $b ) use ( $prefer ) { return ( in_array( $prefer, BDACI_PA_Sections::doc_sections( $b->ID ), true ) <=> in_array( $prefer, BDACI_PA_Sections::doc_sections( $a->ID ), true ) ); } );
			foreach ( $docs as $d ) {
				$o .= '<option value="' . (int) $d->ID . '"' . selected( (int) $cur, (int) $d->ID, false ) . '>' . esc_html( get_the_title( $d ) . ' (' . BDACI_PA_Sections::doc_date( $d->ID ) . ')' ) . '</option>';
			}
			return '<select name="' . esc_attr( $name ) . '">' . $o . '</select>';
		};
		$groups = '';
		foreach ( BDACI_PA_Meetings::groups() as $k => $g ) {
			$c       = array( 'board' => 'board', 'edu' => 'edu', 'fin' => 'fin' )[ $k ] ?? 'other';
			$sub     = array(
				'board' => __( 'Board Members', 'bdaci-portal-access' ),
				'edu'   => __( 'Education Committee members', 'bdaci-portal-access' ),
				'fin'   => __( 'Finance Committee + Board', 'bdaci-portal-access' ),
				'other' => __( 'Everyone; calendar only, no RSVP', 'bdaci-portal-access' ),
			)[ $k ];
			$groups .= self::pick( 'radio', 'grp', $k, ( $m ? $m->grp : 'board' ) === $k, $g[0], $sub, $c, 'cal' );
		}
		$f  = self::form_open( 'meeting_save', 'bdaci_pa_meeting_save', 'class="panel" style="display:grid;gap:14px"' ) . '<input type="hidden" name="id" value="' . (int) ( $m ? $m->id : 0 ) . '">';
		$f .= '<h2>' . ( $m ? esc_html__( 'Edit meeting', 'bdaci-portal-access' ) : esc_html__( 'Add a meeting', 'bdaci-portal-access' ) ) . '</h2>';
		$f .= '<div><div class="pick-h"><b>' . esc_html__( 'Group (who is invited)', 'bdaci-portal-access' ) . '</b></div><div class="picks who">' . $groups . '</div></div>';
		$f .= '<div class="field"><label for="mt-title">' . esc_html__( 'Title', 'bdaci-portal-access' ) . '</label><input id="mt-title" name="title" required value="' . esc_attr( $m ? $m->title : '' ) . '" placeholder="' . esc_attr__( 'e.g. Board of Directors Meeting', 'bdaci-portal-access' ) . '"></div>';
		$f .= '<div class="grid3"><div class="field"><label for="mt-date">' . esc_html__( 'Date', 'bdaci-portal-access' ) . '</label><input type="date" id="mt-date" name="date" required value="' . esc_attr( $m ? substr( $m->starts_at, 0, 10 ) : '' ) . '"></div><div class="field"><label for="mt-start">' . esc_html__( 'Start time', 'bdaci-portal-access' ) . '</label><input type="time" id="mt-start" name="start" required value="' . esc_attr( $m ? substr( $m->starts_at, 11, 5 ) : '18:00' ) . '"></div><div class="field"><label for="mt-end">' . esc_html__( 'End time (optional)', 'bdaci-portal-access' ) . '</label><input type="time" id="mt-end" name="end" value="' . esc_attr( $m && $m->ends_at ? substr( $m->ends_at, 11, 5 ) : '' ) . '"></div></div>';
		$f .= '<div class="two" style="gap:12px"><div class="field"><label for="mt-loc">' . esc_html__( 'Location', 'bdaci-portal-access' ) . '</label><input id="mt-loc" name="location" value="' . esc_attr( $m ? $m->location : '' ) . '" placeholder="' . esc_attr__( 'BDACI Boardroom, Brockville', 'bdaci-portal-access' ) . '"></div><div class="field"><label for="mt-video">' . esc_html__( 'Microsoft Teams link (optional)', 'bdaci-portal-access' ) . '</label><input id="mt-video" name="video_url" type="url" value="' . esc_attr( $m ? $m->video_url : '' ) . '" placeholder="https://teams.microsoft.com/..."></div></div>';
		$f .= '<div class="grid3"><div class="field"><label>' . esc_html__( 'Agenda', 'bdaci-portal-access' ) . '</label>' . $doc_sel( 'agenda_id', $m ? $m->agenda_id : 0, 'agenda' ) . '</div><div class="field"><label>' . esc_html__( 'Meeting package', 'bdaci-portal-access' ) . '</label>' . $doc_sel( 'package_id', $m ? $m->package_id : 0, 'package' ) . '</div><div class="field"><label>' . esc_html__( 'Minutes', 'bdaci-portal-access' ) . '</label>' . $doc_sel( 'minutes_id', $m ? $m->minutes_id : 0, 'minutes' ) . '</div></div><p class="small muted" style="margin-top:-6px">' . esc_html__( 'Upload the files on Sections & Uploads first, then pick them here. People only see the documents they are allowed to open.', 'bdaci-portal-access' ) . '</p>';
		$f .= '<div class="field"><label for="mt-notes">' . esc_html__( 'Notes (optional)', 'bdaci-portal-access' ) . '</label><textarea id="mt-notes" name="notes">' . esc_textarea( $m ? (string) $m->notes : '' ) . '</textarea></div>';
		$f .= '<div class="row-btns"><button class="btn btn-primary" type="submit">' . ( $m ? esc_html__( 'Save meeting', 'bdaci-portal-access' ) : esc_html__( 'Add meeting', 'bdaci-portal-access' ) ) . '</button>' . ( $m ? '<a class="btn" href="' . esc_url( self::url( 'bdaci-portal-meetings' ) ) . '">' . esc_html__( 'Cancel', 'bdaci-portal-access' ) . '</a>' : '' ) . '</div></form>';
		echo $f; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$all = BDACI_PA_Meetings::all();
		foreach ( array( 'upcoming' => __( 'Upcoming', 'bdaci-portal-access' ), 'past' => __( 'Past', 'bdaci-portal-access' ) ) as $when => $label ) {
			$list = array_values( array_filter( $all, static function ( $x ) use ( $when ) { return ( 'past' === $when ) === BDACI_PA_Meetings::is_past( $x ); } ) );
			if ( 'past' === $when ) {
				$list = array_reverse( $list );
			}
			$rows = '';
			foreach ( $list as $x ) {
				$counts = array( 'attending' => 0, 'regrets' => 0, 'tentative' => 0 );
				foreach ( BDACI_PA_Meetings::rsvps( $x->id ) as $r ) {
					if ( isset( $counts[ $r->response ] ) ) {
						++$counts[ $r->response ];
					}
				}
				$g     = BDACI_PA_Meetings::groups()[ $x->grp ];
				$rows .= '<tr><td class="tabnum"><b>' . esc_html( BDACI_PA_Meetings::fmt_short( $x->starts_at ) ) . '</b><br><span class="muted small">' . esc_html( BDACI_PA_Meetings::fmt_time( $x ) ) . '</span></td><td><b>' . esc_html( $x->title ) . '</b><br><span class="muted small">' . esc_html( $x->location ) . '</span></td><td><span class="pill ' . esc_attr( $g[1] ) . '">' . esc_html( $g[0] ) . '</span></td><td class="small">' . ( 'other' === $x->grp ? '–' : esc_html( sprintf( /* translators: 1: attending, 2: regrets, 3: tentative */ __( '%1$d attending · %2$d regrets · %3$d tentative', 'bdaci-portal-access' ), $counts['attending'], $counts['regrets'], $counts['tentative'] ) ) ) . '</td><td style="white-space:nowrap"><a class="btn btn-sm" href="' . esc_url( self::url( 'bdaci-portal-meetings&edit=' . $x->id ) ) . '">' . esc_html__( 'Edit', 'bdaci-portal-access' ) . '</a> <a class="btn btn-sm" href="' . esc_url( self::url( 'bdaci-portal-meetings&replies=' . $x->id ) ) . '">' . esc_html__( 'Replies', 'bdaci-portal-access' ) . '</a> ' .
					self::form_open( 'meeting_delete', 'bdaci_pa_meeting_delete_' . $x->id, 'class="inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Delete this meeting and its RSVPs?', 'bdaci-portal-access' ) ) . '\')"' ) . '<input type="hidden" name="id" value="' . (int) $x->id . '"><button class="btn btn-sm">' . esc_html__( 'Delete', 'bdaci-portal-access' ) . '</button></form></td></tr>';
			}
			echo '<section class="panel"><h2>' . esc_html( $label ) . '</h2><div class="tablewrap"><table><thead><tr><th>' . esc_html__( 'Date', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Meeting', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Group', 'bdaci-portal-access' ) . '</th><th>RSVP</th><th></th></tr></thead><tbody>' . ( $rows ? $rows : '<tr><td colspan="5" class="muted">' . esc_html__( 'No meetings.', 'bdaci-portal-access' ) . '</td></tr>' ) . '</tbody></table></div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div></div>';
	}

	/**
	 * Save a meeting.
	 */
	public static function handle_meeting_save() {
		self::guard( 'bdaci_pa_meeting_save' );
		$id   = absint( self::posted( 'id' ) );
		$data = array();
		foreach ( array( 'grp', 'title', 'date', 'start', 'end', 'location', 'video_url', 'agenda_id', 'package_id', 'minutes_id' ) as $k ) {
			$data[ $k ] = self::posted( $k );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() above.
		$data['notes'] = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$r             = BDACI_PA_Meetings::save( $data, $id );
		if ( is_wp_error( $r ) ) {
			self::back( self::url( 'bdaci-portal-meetings' . ( $id ? '&edit=' . $id : '' ) ), $r->get_error_message(), 'error' );
		}
		self::back( self::url( 'bdaci-portal-meetings' ), $id ? __( 'Meeting saved.', 'bdaci-portal-access' ) : __( 'Meeting added. It now shows on the calendar for the people invited.', 'bdaci-portal-access' ) );
	}

	/**
	 * Delete a meeting.
	 */
	public static function handle_meeting_delete() {
		$id = absint( self::posted( 'id' ) );
		self::guard( 'bdaci_pa_meeting_delete_' . $id );
		BDACI_PA_Meetings::delete( $id );
		self::back( self::url( 'bdaci-portal-meetings' ), __( 'Meeting deleted.', 'bdaci-portal-access' ) );
	}

	/* ---------------------------------------------------------------------
	 * Sign-off audit trail
	 * ------------------------------------------------------------------ */

	/**
	 * Donut chart.
	 *
	 * @param int $done  Signed.
	 * @param int $total Total.
	 * @return string
	 */
	private static function donut( $done, $total ) {
		$c    = 2 * M_PI * 52;
		$frac = $total ? $done / $total : 0;
		return '<div class="donut" role="img" aria-label="' . esc_attr( sprintf( /* translators: 1: signed, 2: total */ __( '%1$d of %2$d signed', 'bdaci-portal-access' ), $done, $total ) ) . '"><svg viewBox="0 0 120 120"><circle class="ring-bg" cx="60" cy="60" r="52" fill="none" stroke-width="14"/>' . ( $done ? '<circle class="ring-fg" cx="60" cy="60" r="52" fill="none" stroke-width="14" stroke-linecap="' . ( $done === $total ? 'butt' : 'round' ) . '" stroke-dasharray="' . number_format( $c * $frac, 1, '.', '' ) . ' ' . number_format( $c, 1, '.', '' ) . '"/>' : '' ) . '</svg><div class="mid"><b class="tabnum">' . (int) $done . '/' . (int) $total . '</b><span class="small muted">' . esc_html__( 'signed', 'bdaci-portal-access' ) . '</span></div></div>';
	}

	/**
	 * Steps line (sent, reminded, opened, signed / waiting).
	 *
	 * @param array $st Status.
	 * @param int   $docs Number of documents.
	 * @return string
	 */
	private static function steps( array $st, $docs ) {
		$h = '<div class="steps">';
		$h .= $st['sent'] ? '<span class="st done" title="' . esc_attr( BDACI_PA_Signoffs::fmt( $st['sent'] ) ) . '"><i></i>' . esc_html( sprintf( /* translators: %s: date */ __( 'Sent %s', 'bdaci-portal-access' ), mysql2date( 'M j, g:i A', $st['sent'] ) ) ) . '</span>' : '<span class="st"><i style="background:var(--line)"></i>' . esc_html__( 'Not sent yet', 'bdaci-portal-access' ) . '</span>';
		if ( $st['reminders'] ) {
			$last = end( $st['reminders'] );
			$h   .= '<span class="st rem" title="' . esc_attr( BDACI_PA_Signoffs::fmt( $last ) ) . '"><i></i>' . esc_html( sprintf( /* translators: 1: times, 2: date */ __( 'Reminded%1$s %2$s', 'bdaci-portal-access' ), count( $st['reminders'] ) > 1 ? ' ×' . count( $st['reminders'] ) : '', mysql2date( 'M j, g:i A', $last ) ) ) . '</span>';
		}
		if ( $docs ) {
			$h .= '<span class="st"><i style="background:var(--cat-board)"></i>' . esc_html( sprintf( /* translators: 1: opened, 2: total */ __( 'Opened %1$d/%2$d', 'bdaci-portal-access' ), count( $st['opened'] ), $docs ) ) . '</span>';
		}
		if ( $st['signed'] ) {
			$h .= '<span class="st ok"><i></i>' . esc_html( sprintf( /* translators: %s: date */ __( 'Signed %s', 'bdaci-portal-access' ), mysql2date( 'M j, g:i A', $st['signed'] ) ) ) . '</span>';
		} elseif ( $st['sent'] ) {
			$days = max( 0, (int) floor( ( strtotime( current_time( 'mysql' ) ) - strtotime( $st['sent'] ) ) / DAY_IN_SECONDS ) );
			$h   .= '<span class="st wait"><i></i>' . esc_html( sprintf( /* translators: %d: days */ _n( 'Waiting %d day', 'Waiting %d days', $days, 'bdaci-portal-access' ), $days ) ) . '</span>';
		}
		return $h . '</div>';
	}

	/**
	 * Sign-off Audit Trail screen.
	 */
	public static function page_signoffs() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$packs = BDACI_PA_Signoffs::packs();
		$key   = isset( $_GET['pack'] ) ? sanitize_key( wp_unslash( $_GET['pack'] ) ) : 'qa'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $packs[ $key ] ) ) {
			$key = 'qa';
		}
		$pack   = $packs[ $key ];
		$people = BDACI_PA_Signoffs::recipients( $key );
		$base   = self::url( 'bdaci-portal-signoffs&pack=' . $key );
		self::open_page( __( 'Sign-off Audit Trail', 'bdaci-portal-access' ) );
		self::notice();
		$h = '<div class="bp bp-admin"><div class="pagehead"><p class="muted" style="max-width:70ch">' . esc_html__( 'When each sign-off was sent, who has signed and who still needs to, with names, dates and times.', 'bdaci-portal-access' ) . '</p><div class="row-btns"><a class="btn btn-sm" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_signoff_export&format=csv&pack=' . $key ), 'bdaci_pa_signoff_export' ) ) . '">' . esc_html__( 'Export CSV', 'bdaci-portal-access' ) . '</a><a class="btn btn-sm" target="_blank" rel="noopener" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_signoff_export&format=print&pack=' . $key ), 'bdaci_pa_signoff_export' ) ) . '">' . esc_html__( 'Print / save as PDF', 'bdaci-portal-access' ) . '</a></div></div>';

		$h .= '<div class="years" role="group" aria-label="' . esc_attr__( 'Sign-off', 'bdaci-portal-access' ) . '">';
		foreach ( $packs as $k => $p ) {
			$l    = BDACI_PA_Signoffs::recipients( $k );
			$done = 0;
			foreach ( $l as $u ) {
				if ( BDACI_PA_Signoffs::status( $k, $u->ID )['signed'] ) {
					++$done;
				}
			}
			$h .= '<a href="' . esc_url( self::url( 'bdaci-portal-signoffs&pack=' . $k ) ) . '" aria-current="' . ( $k === $key ? 'true' : 'false' ) . '">' . esc_html( $p['title'] ) . ' <span class="tabnum" style="opacity:.75">' . (int) $done . '/' . count( $l ) . '</span></a>';
		}
		$h .= '</div>';

		$signed  = array();
		$pending = array();
		$unsent  = 0;
		$ndocs   = count( BDACI_PA_Sections::docs( array( 'section' => $key, 'check' => false ) ) );
		foreach ( $people as $u ) {
			$st = BDACI_PA_Signoffs::status( $key, $u->ID );
			if ( $st['signed'] ) {
				$signed[] = array( $u, $st );
			} else {
				$pending[] = array( $u, $st );
				if ( ! $st['sent'] ) {
					++$unsent;
				}
			}
		}
		usort( $signed, static function ( $a, $b ) { return strcmp( $a[1]['signed'], $b[1]['signed'] ); } );
		$total   = count( $people );
		$pct     = $total ? round( count( $signed ) / $total * 100 ) : 0;
		$left    = $pack['due'] ? (int) floor( ( strtotime( $pack['due'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
		$overdue = null !== $left && $left < 0;
		$first   = '';
		foreach ( $people as $u ) {
			$s = BDACI_PA_Signoffs::status( $key, $u->ID )['sent'];
			if ( $s && ( ! $first || $s < $first ) ) {
				$first = $s;
			}
		}
		$who = $pack['users'] ? __( 'Selected people', 'bdaci-portal-access' ) : implode( ', ', array_map( static function ( $r ) { return BDACI_PA_Roles::labels()[ $r ] ?? $r; }, (array) $pack['roles'] ) );
		$h  .= '<section class="panel"><div class="panel-h"><h2>' . esc_html( $pack['title'] ) . '</h2><span class="small muted">' . esc_html( $who . ( $pack['due'] ? ' · ' . sprintf( /* translators: %s: date */ __( 'due %s', 'bdaci-portal-access' ), date_i18n( 'F j, Y', strtotime( $pack['due'] ) ) ) : '' ) . ( $pack['cycle'] ? ' · ' . sprintf( /* translators: %s: date */ __( 'round started %s', 'bdaci-portal-access' ), mysql2date( 'M j, Y', $pack['cycle'] ) ) : '' ) ) . '</span></div>';
		$h  .= '<div class="so-summary">' . self::donut( count( $signed ), $total ) . '<div style="display:grid;gap:12px;min-width:0;width:100%"><div class="so-stats">' .
			'<div class="so-stat"><span class="k"><i class="dot dot-ok"></i>' . esc_html__( 'Signed', 'bdaci-portal-access' ) . '</span><span class="v tabnum">' . count( $signed ) . '</span></div>' .
			'<div class="so-stat"><span class="k"><i class="dot dot-warn"></i>' . esc_html__( 'Not signed', 'bdaci-portal-access' ) . '</span><span class="v tabnum">' . count( $pending ) . '</span></div>' .
			'<div class="so-stat"><span class="k">' . esc_html__( 'Sent to', 'bdaci-portal-access' ) . '</span><span class="v tabnum">' . (int) ( $total - $unsent ) . '</span><span class="small muted">' . esc_html( $first ? sprintf( /* translators: %s: date */ __( 'first sent %s', 'bdaci-portal-access' ), mysql2date( 'M j', $first ) ) : __( 'not sent yet', 'bdaci-portal-access' ) ) . '</span></div>' .
			'<div class="so-stat"><span class="k">' . esc_html( null === $left ? __( 'Due date', 'bdaci-portal-access' ) : ( $overdue ? __( 'Overdue by', 'bdaci-portal-access' ) : __( 'Days left', 'bdaci-portal-access' ) ) ) . '</span><span class="v tabnum">' . esc_html( null === $left ? '–' : (string) abs( $left ) ) . '</span></div>' .
			'</div><div class="progress" aria-hidden="true"><i style="width:' . (int) $pct . '%"></i></div><span class="small muted tabnum">' . esc_html( $pct . '% ' . __( 'complete', 'bdaci-portal-access' ) . ( $pending ? ' · ' . sprintf( /* translators: %d: count */ __( '%d still to sign', 'bdaci-portal-access' ), count( $pending ) ) : ( $total ? ' · ' . __( 'everyone has signed', 'bdaci-portal-access' ) : '' ) ) ) . '</span>';
		$h  .= '<div class="row-btns">' . ( $unsent ? self::form_open( 'signoff_admin', 'bdaci_pa_signoff_admin', 'class="inline-form"' ) . '<input type="hidden" name="pack" value="' . esc_attr( $key ) . '"><button class="btn btn-sm btn-primary" name="do" value="send">' . esc_html( sprintf( /* translators: %d: count */ _n( 'Send to %d person not sent yet', 'Send to %d people not sent yet', $unsent, 'bdaci-portal-access' ), $unsent ) ) . '</button></form>' : '' ) .
			( $ndocs ? '' : '<span class="small" style="color:var(--warn)">' . esc_html__( 'No documents are in this sign-off yet. Upload them on Sections & Uploads and pick this section.', 'bdaci-portal-access' ) . '</span>' ) . '</div></div></div></section>';

		$li_signed = '';
		foreach ( $signed as $x ) {
			$li_signed .= '<li>' . BDACI_PA_Profile::avatar( $x[0]->ID, 'av-sm' ) . '<div class="grow"><b>' . esc_html( $x[0]->display_name ) . '</b><span class="small muted">' . esc_html( $x[0]->user_email ) . '</span>' . self::steps( $x[1], $ndocs ) . '</div><span class="small tabnum" style="text-align:right">' . esc_html( mysql2date( 'Y-m-d', $x[1]['signed'] ) ) . '<br><span class="muted">' . esc_html( mysql2date( 'g:i A', $x[1]['signed'] ) ) . '</span></span></li>';
		}
		$li_pend = '';
		foreach ( $pending as $x ) {
			$li_pend .= '<li>' . BDACI_PA_Profile::avatar( $x[0]->ID, 'av-sm' ) . '<div class="grow"><b>' . esc_html( $x[0]->display_name ) . '</b><span class="small muted">' . esc_html( $x[0]->user_email ) . '</span>' . self::steps( $x[1], $ndocs ) . '</div>' . ( $pack['due'] ? '<span class="pill ' . ( $overdue ? 'pill-warn' : 'pill-muted' ) . '">' . esc_html( $overdue ? __( 'Overdue', 'bdaci-portal-access' ) : sprintf( /* translators: %s: date */ __( 'Due %s', 'bdaci-portal-access' ), mysql2date( 'M j', $pack['due'] ) ) ) . '</span>' : '' ) .
				self::form_open( 'signoff_admin', 'bdaci_pa_signoff_admin', 'class="inline-form"' ) . '<input type="hidden" name="pack" value="' . esc_attr( $key ) . '"><input type="hidden" name="user" value="' . (int) $x[0]->ID . '"><button class="btn btn-sm" name="do" value="remind">' . ( $x[1]['sent'] ? esc_html__( 'Remind', 'bdaci-portal-access' ) : esc_html__( 'Send', 'bdaci-portal-access' ) ) . '</button></form></li>';
		}
		$remind_all = count( $pending ) > 1 ? self::form_open( 'signoff_admin', 'bdaci_pa_signoff_admin', 'class="inline-form"' ) . '<input type="hidden" name="pack" value="' . esc_attr( $key ) . '"><button class="btn btn-sm btn-primary" name="do" value="remind_all">' . esc_html__( 'Remind all', 'bdaci-portal-access' ) . '</button></form>' : '';
		$h         .= '<div class="so-cols"><section class="panel"><div class="panel-h"><h2><i class="dot dot-ok"></i>' . esc_html__( 'Signed', 'bdaci-portal-access' ) . ' <span class="pill pill-ok tabnum">' . count( $signed ) . '</span></h2></div>' . ( $li_signed ? '<ul class="list">' . $li_signed . '</ul>' : '<p class="muted small">' . esc_html__( 'No one has signed yet.', 'bdaci-portal-access' ) . '</p>' ) . '</section>';
		$h         .= '<section class="panel so-pend"><div class="panel-h"><h2><i class="dot dot-warn"></i>' . esc_html__( 'Not signed', 'bdaci-portal-access' ) . ' <span class="pill pill-warn tabnum">' . count( $pending ) . '</span></h2>' . $remind_all . '</div>' . ( $li_pend ? '<ul class="list">' . $li_pend . '</ul>' : '<p class="muted small">' . ( $total ? esc_html__( 'Everyone has signed.', 'bdaci-portal-access' ) : esc_html__( 'No one is assigned to this sign-off yet.', 'bdaci-portal-access' ) ) . '</p>' ) . '</section></div>';

		// Matrix: every board member and portal member who must sign anything.
		$everyone = array();
		foreach ( $packs as $k => $p ) {
			foreach ( BDACI_PA_Signoffs::recipients( $k ) as $u ) {
				$everyone[ $u->ID ] = $u;
			}
		}
		uasort( $everyone, static function ( $a, $b ) { return strcasecmp( $a->display_name, $b->display_name ); } );
		$mx = '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Everyone at a glance', 'bdaci-portal-access' ) . '</h2><span class="small muted">' . esc_html__( '✓ signed · ✗ not signed · – not required', 'bdaci-portal-access' ) . '</span></div><div class="tablewrap"><table class="matrix"><thead><tr><th>' . esc_html__( 'Person', 'bdaci-portal-access' ) . '</th>';
		foreach ( $packs as $p ) {
			$mx .= '<th class="c">' . esc_html( $p['short'] ) . '</th>';
		}
		$mx .= '</tr></thead><tbody>';
		foreach ( $everyone as $u ) {
			$mx .= '<tr><td><span class="who">' . BDACI_PA_Profile::avatar( $u->ID, 'av-sm' ) . '<b>' . esc_html( $u->display_name ) . '</b></span></td>';
			foreach ( array_keys( $packs ) as $k ) {
				if ( ! BDACI_PA_Signoffs::is_recipient( $k, $u->ID ) ) {
					$mx .= '<td class="c mark-na">–</td>';
					continue;
				}
				$st  = BDACI_PA_Signoffs::status( $k, $u->ID );
				$mx .= $st['signed'] ? '<td class="c"><span class="mark-ok">✓</span><br><span class="small muted tabnum">' . esc_html( mysql2date( 'm/d', $st['signed'] ) ) . '<br>' . esc_html( mysql2date( 'g:i A', $st['signed'] ) ) . '</span></td>' : '<td class="c mark-no">✗</td>';
			}
			$mx .= '</tr>';
		}
		$h .= $mx . ( $everyone ? '' : '<tr><td class="muted" colspan="3">' . esc_html__( 'No one yet.', 'bdaci-portal-access' ) . '</td></tr>' ) . '</tbody></table></div></section>';

		// Log.
		$lf  = isset( $_GET['log'] ) ? sanitize_key( wp_unslash( $_GET['log'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$evl = array(
			'sent'     => '<span class="pill pill-board">' . esc_html__( 'Sent', 'bdaci-portal-access' ) . '</span>',
			'reminded' => '<span class="pill pill-other">' . esc_html__( 'Reminder sent', 'bdaci-portal-access' ) . '</span>',
			'opened'   => '<span class="pill pill-muted">' . esc_html__( 'Opened', 'bdaci-portal-access' ) . '</span>',
			'signed'   => '<span class="pill pill-ok">' . esc_html__( 'Signed', 'bdaci-portal-access' ) . '</span>',
		);
		$rows = '';
		$n    = 0;
		foreach ( BDACI_PA_Signoffs::log( $key ) as $e ) {
			if ( 'all' !== $lf && $e->event !== $lf ) {
				continue;
			}
			if ( ++$n > 300 ) {
				break;
			}
			$u     = get_userdata( $e->user_id );
			$rows .= '<tr><td><span class="who">' . ( $u ? BDACI_PA_Profile::avatar( $u->ID, 'av-sm' ) . '<b>' . esc_html( $u->display_name ) . '</b>' : '#' . (int) $e->user_id ) . '</span></td><td>' . ( $evl[ $e->event ] ?? esc_html( $e->event ) ) . '</td><td>' . esc_html( $e->doc_id ? get_the_title( $e->doc_id ) : $pack['title'] ) . '</td><td class="tabnum">' . esc_html( mysql2date( 'Y-m-d', $e->created_at ) ) . '</td><td class="tabnum">' . esc_html( mysql2date( 'g:i A', $e->created_at ) ) . '</td></tr>';
		}
		$h .= '<section class="panel"><div class="panel-h"><h2>' . esc_html__( 'Sign-off log', 'bdaci-portal-access' ) . '</h2><div class="years" role="group" aria-label="' . esc_attr__( 'Filter log', 'bdaci-portal-access' ) . '">';
		foreach ( array( 'all' => __( 'All', 'bdaci-portal-access' ), 'sent' => __( 'Sent', 'bdaci-portal-access' ), 'reminded' => __( 'Reminders', 'bdaci-portal-access' ), 'opened' => __( 'Opened', 'bdaci-portal-access' ), 'signed' => __( 'Signed', 'bdaci-portal-access' ) ) as $k => $l ) {
			$h .= '<a href="' . esc_url( add_query_arg( 'log', $k, $base ) ) . '" aria-current="' . ( $lf === $k ? 'true' : 'false' ) . '">' . esc_html( $l ) . '</a>';
		}
		$h .= '</div></div><p class="small muted" style="margin:-6px 0 10px">' . esc_html__( 'When each sign-off was sent, every reminder, each document opened, and every sign-off. Newest first; includes earlier rounds.', 'bdaci-portal-access' ) . '</p><div class="tablewrap"><table><thead><tr><th>' . esc_html__( 'User name', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Event', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Document / section', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Date', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Time', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . ( $rows ? $rows : '<tr><td colspan="5" class="muted">' . esc_html__( 'Nothing yet.', 'bdaci-portal-access' ) . '</td></tr>' ) . '</tbody></table></div></section>';

		// Settings.
		$roles = '';
		foreach ( BDACI_PA_Roles::labels() as $k => $l ) {
			$roles .= '<label style="margin-right:14px"><input type="checkbox" name="roles[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $pack['roles'], true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		$opts = '';
		foreach ( BDACI_PA_Members::all() as $row ) {
			if ( BDACI_PA_Members::ACTIVE !== $row->status ) {
				continue;
			}
			$u = $row->user_id ? get_userdata( $row->user_id ) : get_user_by( 'email', $row->email );
			if ( $u ) {
				$opts .= '<option value="' . (int) $u->ID . '"' . selected( in_array( (int) $u->ID, array_map( 'intval', (array) $pack['users'] ), true ), true, false ) . '>' . esc_html( $u->display_name . ' <' . $u->user_email . '>' ) . '</option>';
			}
		}
		$h .= '<details class="panel"><summary>' . esc_html__( 'Sign-off settings', 'bdaci-portal-access' ) . '</summary>' . self::form_open( 'signoff_admin', 'bdaci_pa_signoff_admin', 'style="display:grid;gap:12px;margin-top:14px"' ) . '<input type="hidden" name="pack" value="' . esc_attr( $key ) . '"><input type="hidden" name="do" value="settings">' .
			'<div class="two" style="gap:12px"><div class="field"><label>' . esc_html__( 'Title', 'bdaci-portal-access' ) . '</label><input name="title" value="' . esc_attr( $pack['title'] ) . '"></div><div class="field"><label>' . esc_html__( 'Due date (optional)', 'bdaci-portal-access' ) . '</label><input type="date" name="due" value="' . esc_attr( $pack['due'] ) . '"></div></div>' .
			'<div class="field"><label>' . esc_html__( 'Introduction', 'bdaci-portal-access' ) . '</label><input name="intro" value="' . esc_attr( $pack['intro'] ) . '"></div>' .
			'<div class="field"><label>' . esc_html__( 'Statement people confirm', 'bdaci-portal-access' ) . '</label><input name="statement" value="' . esc_attr( $pack['statement'] ) . '"></div>' .
			'<div class="field"><label>' . esc_html__( 'Who must sign', 'bdaci-portal-access' ) . '</label><div>' . $roles . '</div></div>' .
			'<div class="field"><label>' . esc_html__( 'Or only these people (overrides the roles above)', 'bdaci-portal-access' ) . '</label><select name="users[]" multiple size="6">' . $opts . '</select><span class="small muted">' . esc_html__( 'Use Ctrl/⌘-click to pick several, e.g. only new directors for orientation. Leave none selected to use the roles.', 'bdaci-portal-access' ) . '</span></div>' .
			'<div class="field"><label>' . esc_html__( 'Send a copy of each confirmation to (optional)', 'bdaci-portal-access' ) . '</label><input type="email" name="cc" value="' . esc_attr( $pack['cc'] ) . '"></div>' .
			'<div class="row-btns"><button class="btn btn-primary" type="submit">' . esc_html__( 'Save settings', 'bdaci-portal-access' ) . '</button></div></form>' .
			self::form_open( 'signoff_admin', 'bdaci_pa_signoff_admin', 'style="margin-top:16px;border-top:1px solid var(--line);padding-top:14px" onsubmit="return confirm(\'' . esc_js( __( 'Start a new round? Everyone will need to sign again. The current results stay in the log.', 'bdaci-portal-access' ) ) . '\')"' ) . '<input type="hidden" name="pack" value="' . esc_attr( $key ) . '"><p class="small muted">' . esc_html__( 'For a yearly sign-off: start a new round so everyone signs again. Update the title, due date and documents first.', 'bdaci-portal-access' ) . '</p><button class="btn btn-sm" name="do" value="new_round">' . esc_html__( 'Start a new round', 'bdaci-portal-access' ) . '</button></form></details>';

		echo $h . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
	}

	/**
	 * Sign-off actions: send, remind, remind all, settings, new round.
	 */
	public static function handle_signoff_admin() {
		self::guard( 'bdaci_pa_signoff_admin' );
		$key = sanitize_key( self::posted( 'pack' ) );
		$u   = self::url( 'bdaci-portal-signoffs&pack=' . $key );
		if ( ! BDACI_PA_Signoffs::pack( $key ) ) {
			self::back( self::url( 'bdaci-portal-signoffs' ), __( 'Unknown sign-off.', 'bdaci-portal-access' ), 'error' );
		}
		switch ( self::posted( 'do' ) ) {
			case 'send':
				$n = BDACI_PA_Signoffs::send( $key );
				/* translators: %d: count */
				self::back( $u, sprintf( _n( 'Sent to %d person.', 'Sent to %d people.', $n, 'bdaci-portal-access' ), $n ) );
				break;
			case 'remind':
				$n = BDACI_PA_Signoffs::remind( $key, array( absint( self::posted( 'user' ) ) ) );
				self::back( $u, $n ? __( 'Reminder sent.', 'bdaci-portal-access' ) : __( 'Nothing to send: that person has already signed.', 'bdaci-portal-access' ) );
				break;
			case 'remind_all':
				$n = BDACI_PA_Signoffs::remind( $key );
				/* translators: %d: count */
				self::back( $u, sprintf( _n( 'Reminder sent to %d person.', 'Reminders sent to %d people.', $n, 'bdaci-portal-access' ), $n ) );
				break;
			case 'new_round':
				BDACI_PA_Signoffs::new_round( $key );
				self::back( $u, __( 'New round started. Use "Send" to email everyone.', 'bdaci-portal-access' ) );
				break;
			case 'settings':
				$due = self::posted( 'due' );
				$cc  = sanitize_email( self::posted( 'cc' ) );
				$roles = array_values( array_intersect( array_keys( BDACI_PA_Roles::map() ), self::posted_list( 'roles' ) ) );
				$users = array_values( array_filter( array_map( 'absint', self::posted_list( 'users' ) ) ) );
				if ( ! $roles && ! $users ) {
					self::back( $u, __( 'Pick at least one role or person who must sign.', 'bdaci-portal-access' ), 'error' );
				}
				BDACI_PA_Signoffs::save_pack(
					$key,
					array(
						'title'     => self::posted( 'title' ) ? self::posted( 'title' ) : BDACI_PA_Signoffs::pack( $key )['title'],
						'intro'     => self::posted( 'intro' ),
						'statement' => self::posted( 'statement' ) ? self::posted( 'statement' ) : BDACI_PA_Signoffs::pack( $key )['statement'],
						'due'       => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ? $due : '',
						'roles'     => $roles,
						'users'     => $users,
						'cc'        => $cc,
					)
				);
				BDACI_PA_Log::add( 'signoff_settings', '', get_current_user_id(), $key );
				self::back( $u, __( 'Sign-off settings saved.', 'bdaci-portal-access' ) );
				break;
		}
		self::back( $u, __( 'Nothing changed.', 'bdaci-portal-access' ), 'error' );
	}

	/**
	 * Export the audit trail (CSV or printable page).
	 */
	public static function handle_signoff_export() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bdaci_pa_signoff_export' );
		$key    = isset( $_GET['pack'] ) ? sanitize_key( wp_unslash( $_GET['pack'] ) ) : '';
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		$pack   = BDACI_PA_Signoffs::pack( $key );
		if ( ! $pack ) {
			wp_die( esc_html__( 'Unknown sign-off.', 'bdaci-portal-access' ) );
		}
		$people = BDACI_PA_Signoffs::recipients( $key );
		if ( 'print' === $format ) {
			$rows   = '';
			$signed = 0;
			foreach ( $people as $u ) {
				$st = BDACI_PA_Signoffs::status( $key, $u->ID );
				$signed += $st['signed'] ? 1 : 0;
				$rows   .= '<tr><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( BDACI_PA_Signoffs::fmt( $st['sent'] ) ) . '</td><td>' . esc_html( (string) count( $st['reminders'] ) ) . '</td><td>' . esc_html( $st['signed'] ? BDACI_PA_Signoffs::fmt( $st['signed'] ) : __( 'Not signed', 'bdaci-portal-access' ) ) . '</td></tr>';
			}
			$log = '';
			foreach ( array_slice( BDACI_PA_Signoffs::log( $key ), 0, 500 ) as $e ) {
				$u    = get_userdata( $e->user_id );
				$log .= '<tr><td>' . esc_html( BDACI_PA_Signoffs::fmt( $e->created_at ) ) . '</td><td>' . esc_html( $u ? $u->display_name : '#' . $e->user_id ) . '</td><td>' . esc_html( $e->event ) . '</td><td>' . esc_html( $e->doc_id ? get_the_title( $e->doc_id ) : '' ) . '</td></tr>';
			}
			BDACI_PA_Signoffs::print_page(
				$pack['title'] . ' – ' . __( 'audit trail', 'bdaci-portal-access' ),
				'<h1>' . esc_html( $pack['title'] ) . ' – ' . esc_html__( 'sign-off audit trail', 'bdaci-portal-access' ) . '</h1><p>' . esc_html( sprintf( /* translators: 1: signed, 2: total */ __( '%1$d of %2$d signed.', 'bdaci-portal-access' ), $signed, count( $people ) ) ) . ( $pack['due'] ? ' ' . esc_html( sprintf( /* translators: %s: date */ __( 'Due %s.', 'bdaci-portal-access' ), date_i18n( 'F j, Y', strtotime( $pack['due'] ) ) ) ) : '' ) . '</p>' .
				'<table><thead><tr><th>' . esc_html__( 'Name', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Email', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Sent', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Reminders', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Signed', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
				'<h2>' . esc_html__( 'Log', 'bdaci-portal-access' ) . '</h2><table><thead><tr><th>' . esc_html__( 'When', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Person', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Event', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Document', 'bdaci-portal-access' ) . '</th></tr></thead><tbody>' . $log . '</tbody></table>'
			);
		}
		$rows = array( array( 'Sign-off', 'Date', 'Time', 'Name', 'Email', 'Event', 'Document' ) );
		foreach ( BDACI_PA_Signoffs::log( $key ) as $e ) {
			$u      = get_userdata( $e->user_id );
			$rows[] = array( $pack['title'], mysql2date( 'Y-m-d', $e->created_at ), mysql2date( 'H:i', $e->created_at ), $u ? $u->display_name : '#' . $e->user_id, $u ? $u->user_email : '', $e->event, $e->doc_id ? get_the_title( $e->doc_id ) : '' );
		}
		self::csv( 'signoff-' . $key . '-' . current_time( 'Y-m-d' ) . '.csv', $rows );
	}

	/* ---------------------------------------------------------------------
	 * Access requests and directory details (on Authorized Users)
	 * ------------------------------------------------------------------ */

	/**
	 * Pending access requests panel.
	 *
	 * @return string
	 */
	public static function requests_panel() {
		$reqs = BDACI_PA_Requests::pending();
		if ( ! $reqs ) {
			return '';
		}
		$areas = BDACI_PA_Requests::areas();
		$h     = '<div class="bp bp-admin"><section class="panel" style="border-color:var(--warn)"><div class="panel-h"><h2>' . esc_html__( 'Access requests', 'bdaci-portal-access' ) . '</h2><span class="pill pill-warn">' . esc_html( sprintf( /* translators: %d: count */ __( '%d waiting', 'bdaci-portal-access' ), count( $reqs ) ) ) . '</span></div><ul class="list">';
		foreach ( $reqs as $r ) {
			$u = get_userdata( $r->user_id );
			if ( ! $u ) {
				continue;
			}
			$h .= '<li>' . BDACI_PA_Profile::avatar( $u->ID, 'av-sm' ) . '<div class="grow"><b>' . esc_html( $u->display_name ) . '</b><span class="muted small">' . esc_html( sprintf( /* translators: 1: section, 2: date */ __( 'Asked for %1$s access · %2$s', 'bdaci-portal-access' ), $areas[ $r->area ][0] ?? $r->area, BDACI_PA_Signoffs::fmt( $r->created_at ) ) ) . ( $r->reason ? ' · “' . esc_html( $r->reason ) . '”' : '' ) . '</span></div>' .
				self::form_open( 'request_decide', 'bdaci_pa_request_decide_' . $r->id, 'class="inline-form"' ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="btn btn-sm btn-primary" name="do" value="approve">' . esc_html__( 'Approve', 'bdaci-portal-access' ) . '</button> <button class="btn btn-sm" name="do" value="decline">' . esc_html__( 'Decline', 'bdaci-portal-access' ) . '</button></form></li>';
		}
		return $h . '</ul><p class="small muted">' . esc_html__( 'Approving adds the committee role to their account. It stays until you remove it from their roles below.', 'bdaci-portal-access' ) . '</p></section></div>';
	}

	/**
	 * Approve / decline.
	 */
	public static function handle_request_decide() {
		$id = absint( self::posted( 'id' ) );
		self::guard( 'bdaci_pa_request_decide_' . $id );
		$r = BDACI_PA_Requests::decide( $id, 'approve' === self::posted( 'do' ) );
		self::back( self::url( 'bdaci-portal' ), is_wp_error( $r ) ? $r->get_error_message() : $r, is_wp_error( $r ) ? 'error' : 'success' );
	}

	/**
	 * Directory details for one person.
	 */
	public static function page_person() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$uid = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$u   = $uid ? get_userdata( $uid ) : null;
		self::open_page( __( 'Directory details', 'bdaci-portal-access' ) );
		self::notice();
		if ( ! $u ) {
			echo '<p>' . esc_html__( 'This person has no account yet. It is created when they set a password or first sign in.', 'bdaci-portal-access' ) . '</p></div>';
			return;
		}
		$h = '<div class="bp bp-admin">' . self::form_open( 'person_save', 'bdaci_pa_person_save_' . $uid, 'class="panel" style="display:grid;gap:12px;max-width:760px"' ) . '<input type="hidden" name="user" value="' . (int) $uid . '"><div class="pf-current">' . BDACI_PA_Profile::avatar( $uid, 'av-xl' ) . '<div><b style="font-size:1.1rem">' . esc_html( $u->display_name ) . '</b><br><span class="muted small">' . esc_html( $u->user_email ) . ' · ' . esc_html( BDACI_PA_Portal::role_label( $uid ) ) . '</span></div></div>';
		$h .= '<div class="field"><label for="p-name">' . esc_html__( 'Name shown in the portal', 'bdaci-portal-access' ) . '</label><input id="p-name" name="display_name" value="' . esc_attr( $u->display_name ) . '"></div>';
		foreach ( BDACI_PA_Profile::fields() as $k => $label ) {
			$h .= '<div class="field"><label for="p-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label><input id="p-' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) get_user_meta( $uid, $k, true ) ) . '"' . ( 'bdaci_dir_order' === $k ? ' type="number" style="max-width:140px"' : '' ) . '></div>';
		}
		$h .= '<label class="check" style="font-weight:400"><input type="checkbox" name="bdaci_show_phone" value="1"' . checked( (bool) get_user_meta( $uid, 'bdaci_show_phone', true ), true, false ) . '> ' . esc_html__( 'Show phone number to portal members', 'bdaci-portal-access' ) . '</label>';
		$h .= '<label class="check" style="font-weight:400"><input type="checkbox" name="bdaci_dir_hide" value="1"' . checked( (bool) get_user_meta( $uid, 'bdaci_dir_hide', true ), true, false ) . '> ' . esc_html__( 'Leave this person out of the Leadership and committee directories', 'bdaci-portal-access' ) . '</label>';
		$h .= '<div class="row-btns"><button class="btn btn-primary" type="submit">' . esc_html__( 'Save', 'bdaci-portal-access' ) . '</button><a class="btn" href="' . esc_url( self::url( 'bdaci-portal' ) ) . '">' . esc_html__( 'Back to Authorized Users', 'bdaci-portal-access' ) . '</a></div></form></div></div>';
		echo $h; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Save directory details.
	 */
	public static function handle_person_save() {
		$uid = absint( self::posted( 'user' ) );
		self::guard( 'bdaci_pa_person_save_' . $uid );
		$u = get_userdata( $uid );
		if ( ! $u ) {
			self::back( self::url( 'bdaci-portal' ), __( 'Person not found.', 'bdaci-portal-access' ), 'error' );
		}
		$name = self::posted( 'display_name' );
		if ( '' !== $name && $name !== $u->display_name ) {
			wp_update_user( array( 'ID' => $uid, 'display_name' => $name ) );
			$row = BDACI_PA_Members::for_user( $uid );
			if ( $row ) {
				BDACI_PA_Members::update( $row->id, array( 'display_name' => $name ) );
			}
		}
		foreach ( array_keys( BDACI_PA_Profile::fields() ) as $k ) {
			$v = self::posted( $k );
			if ( 'bdaci_dir_order' === $k ) {
				$v = '' === $v ? '' : (string) intval( $v );
			}
			update_user_meta( $uid, $k, mb_substr( $v, 0, 120 ) );
		}
		update_user_meta( $uid, 'bdaci_show_phone', '1' === self::posted( 'bdaci_show_phone' ) ? 1 : 0 );
		update_user_meta( $uid, 'bdaci_dir_hide', '1' === self::posted( 'bdaci_dir_hide' ) ? 1 : 0 );
		BDACI_PA_Log::add( 'directory_updated', $u->user_email, get_current_user_id() );
		self::back( self::url( 'bdaci-portal-person&user=' . $uid ), __( 'Directory details saved.', 'bdaci-portal-access' ) );
	}
}
