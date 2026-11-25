<?php
/**
 * Portal layout: the top bar, side menu and screens that wrap every page under the portal home.
 *
 * Each portal page has a "screen" (post meta `_bdaci_screen`) that decides what the plugin shows on it:
 * dashboard, calendar, meetings, leadership, governance, orientation, qa, education, finance,
 * section (an admin-made section), profile or help. The page's own content is shown under the screen.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Portal layout and front-end actions.
 */
class BDACI_PA_Portal {

	const META_SCREEN = '_bdaci_screen';
	const META_MENU   = '_bdaci_menu';
	const SCREEN_KEY  = 'bdaci_pa_screen_map';

	/**
	 * Set by the gate when a signed-in member opens a page they can't see.
	 *
	 * @var bool
	 */
	public static $restricted = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'wp_print_styles', array( __CLASS__, 'drop_theme_styles' ), 100 );
		add_action( 'admin_post_bdaci_pa_portal', array( __CLASS__, 'handle' ) );
		add_action( 'save_post_page', array( __CLASS__, 'flush_screens' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_screens' ) );
	}

	/* ---------------------------------------------------------------------
	 * Pages and screens
	 * ------------------------------------------------------------------ */

	/**
	 * Screens the plugin can show, with their default page title, menu and access.
	 *
	 * @return array screen => [title, menu, level, order]
	 */
	public static function screens() {
		return array(
			'dashboard'   => array( __( 'Board & Committee Portal', 'bdaci-portal-access' ), '', 'portal', 0 ),
			'calendar'    => array( __( 'Calendar', 'bdaci-portal-access' ), 'top', 'portal', 1 ),
			'meetings'    => array( __( 'Meetings', 'bdaci-portal-access' ), 'top', 'portal', 2 ),
			'help'        => array( __( 'Support', 'bdaci-portal-access' ), 'top', 'portal', 3 ),
			'profile'     => array( __( 'My profile', 'bdaci-portal-access' ), '', 'portal', 4 ),
			'leadership'  => array( __( 'Leadership', 'bdaci-portal-access' ), 'board', 'portal', 10 ),
			'governance'  => array( __( 'Governance & Core Documents', 'bdaci-portal-access' ), 'board', 'portal', 20 ),
			'orientation' => array( __( 'New Director Orientation', 'bdaci-portal-access' ), 'board', 'board', 30 ),
			'qa'          => array( __( 'Quality Assurance', 'bdaci-portal-access' ), 'board', 'board', 40 ),
			'education'   => array( __( 'Education Committee', 'bdaci-portal-access' ), 'committees', 'committee', 70 ),
			'finance'     => array( __( 'Finance Committee', 'bdaci-portal-access' ), 'committees', 'finance', 80 ),
		);
	}

	/**
	 * Clear the screen => page cache.
	 */
	public static function flush_screens() {
		delete_transient( self::SCREEN_KEY );
	}

	/**
	 * Map of screen => page ID (first published page with that screen under the portal home).
	 *
	 * @return array
	 */
	public static function screen_map() {
		$map = get_transient( self::SCREEN_KEY );
		if ( is_array( $map ) ) {
			return $map;
		}
		$map  = array();
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		if ( $root ) {
			$map['dashboard'] = $root;
			$pages            = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'posts_per_page'   => 200,
					'meta_key'         => self::META_SCREEN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'          => 'menu_order',
					'order'            => 'ASC',
					'suppress_filters' => true,
					'bdaci_pa_all'     => true,
				)
			);
			foreach ( $pages as $p ) {
				$s = (string) get_post_meta( $p->ID, self::META_SCREEN, true );
				if ( 'section' !== $s && $s && ! isset( $map[ $s ] ) && ( (int) $p->ID === $root || in_array( $root, get_post_ancestors( $p ), true ) ) ) {
					$map[ $s ] = (int) $p->ID;
				}
			}
		}
		set_transient( self::SCREEN_KEY, $map, DAY_IN_SECONDS );
		return $map;
	}

	/**
	 * URL of a screen's page.
	 *
	 * @param string $screen Screen.
	 * @param array  $args   Query args.
	 * @return string
	 */
	public static function url( $screen, array $args = array() ) {
		$map = self::screen_map();
		$url = isset( $map[ $screen ] ) ? get_permalink( $map[ $screen ] ) : BDACI_PA_Settings::portal_home_url();
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Screen of a page.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function screen_of( $post_id ) {
		$s = (string) get_post_meta( $post_id, self::META_SCREEN, true );
		if ( '' === $s && (int) $post_id === (int) BDACI_PA_Settings::get( 'portal_root_page' ) ) {
			$s = 'dashboard';
		}
		return $s;
	}

	/**
	 * Whether a page is shown in the portal layout.
	 *
	 * @param int $post_id Page.
	 * @return bool
	 */
	public static function uses_layout( $post_id ) {
		if ( ! BDACI_PA_Settings::get( 'portal_layout' ) || 'page' !== get_post_type( $post_id ) ) {
			return false;
		}
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		if ( ! $root ) {
			return false;
		}
		return (int) $post_id === $root || in_array( $root, get_post_ancestors( $post_id ), true );
	}

	/**
	 * Create (or complete) the portal page tree. Existing pages are reused, never overwritten.
	 *
	 * @return array Titles of pages created.
	 */
	public static function setup_pages() {
		$created = array();
		$root    = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		$screens = self::screens();
		if ( ! $root || ! get_post( $root ) ) {
			$root = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => $screens['dashboard'][0],
					'post_name'   => 'board-portal',
				)
			);
			$s                     = get_option( BDACI_PA_Settings::OPTION, array() );
			$s['portal_root_page'] = (int) $root;
			update_option( BDACI_PA_Settings::OPTION, $s, false );
			$created[] = $screens['dashboard'][0];
		}
		if ( ! get_post_meta( $root, self::META_SCREEN, true ) ) {
			update_post_meta( $root, self::META_SCREEN, 'dashboard' );
		}
		self::flush_screens();
		$have = self::screen_map();
		foreach ( $screens as $screen => $def ) {
			if ( 'dashboard' === $screen || isset( $have[ $screen ] ) ) {
				continue;
			}
			// Reuse a page that already exists under the portal home with the same title (e.g. "Education Committee").
			$existing = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'post_parent'    => $root,
					'title'          => $def[0],
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( array( 'key' => self::META_SCREEN, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $existing ) {
				$id = (int) $existing[0];
				update_post_meta( $id, self::META_SCREEN, $screen );
				if ( $def[1] && ! get_post_meta( $id, self::META_MENU, true ) ) {
					update_post_meta( $id, self::META_MENU, $def[1] );
				}
				if ( 'portal' !== $def[2] && ! get_post_meta( $id, BDACI_PA_Access::META, true ) ) {
					update_post_meta( $id, BDACI_PA_Access::META, $def[2] );
				}
				if ( 'publish' !== get_post_status( $id ) ) {
					wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
				}
				/* translators: %s: page title */
				$created[] = sprintf( __( '%s (existing page)', 'bdaci-portal-access' ), $def[0] );
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => $def[0],
					'post_parent' => $root,
					'menu_order'  => $def[3],
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, self::META_SCREEN, $screen );
			if ( $def[1] ) {
				update_post_meta( $id, self::META_MENU, $def[1] );
			}
			if ( 'portal' !== $def[2] ) {
				update_post_meta( $id, BDACI_PA_Access::META, $def[2] );
			}
			$created[] = $def[0];
		}
		BDACI_PA_Access::flush_cache();
		self::flush_screens();
		if ( $created ) {
			BDACI_PA_Log::add( 'portal_pages_created', '', get_current_user_id(), implode( ', ', $created ) );
		}
		return $created;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Use the portal template on portal pages.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( is_page() && self::uses_layout( get_queried_object_id() ) && is_user_logged_in() ) {
			return BDACI_PA_DIR . 'templates/portal.php';
		}
		return $template;
	}

	/**
	 * Whether the current request is a portal page.
	 *
	 * @return bool
	 */
	public static function is_portal_request() {
		return is_page() && is_user_logged_in() && self::uses_layout( get_queried_object_id() );
	}

	/**
	 * Styles and scripts.
	 */
	public static function assets() {
		if ( ! self::is_portal_request() ) {
			return;
		}
		if ( BDACI_PA_Settings::get( 'portal_fonts' ) ) {
			wp_enqueue_style( 'bdaci-pa-fonts', 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Ubuntu:wght@500;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'bdaci-pa-portal', BDACI_PA_URL . 'assets/portal.css', array(), BDACI_PA_VERSION );
		wp_enqueue_script( 'bdaci-pa-portal', BDACI_PA_URL . 'assets/portal.js', array(), BDACI_PA_VERSION, true );
		wp_add_inline_script( 'bdaci-pa-portal', 'window.bpView=' . wp_json_encode( self::viewer_data() ) . ';', 'before' );
		$manage = self::manage_key();
		if ( $manage && ( current_user_can( 'bdaci_manage_portal' ) || ( 'qa' === $manage && BDACI_PA_QA::can_view() ) ) ) {
			BDACI_PA_Admin_Portal::enqueue( 'bdaci-portal' ); // Pick cards and other admin-screen styles.
			if ( 'docs' === $manage ) {
				wp_enqueue_style( 'dashicons' );
				BDACI_PA_Admin::enqueue( 'bdaci-portal-docs' ); // Drag-and-drop / paste uploader.
			}
		}
	}

	/**
	 * Which admin screen is being shown inside the portal (?manage=…), if any.
	 *
	 * @return string
	 */
	public static function manage_key() {
		return isset( $_GET['manage'] ) ? sanitize_key( wp_unslash( $_GET['manage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Remove the theme's own stylesheets from portal pages so the portal looks the same on any theme.
	 */
	public static function drop_theme_styles() {
		if ( ! self::is_portal_request() || BDACI_PA_Settings::get( 'portal_keep_theme_css' ) ) {
			return;
		}
		global $wp_styles;
		if ( ! $wp_styles instanceof WP_Styles ) {
			return;
		}
		$theme_root = wp_normalize_path( get_theme_root_uri() );
		foreach ( (array) $wp_styles->queue as $handle ) {
			$src = isset( $wp_styles->registered[ $handle ] ) ? (string) $wp_styles->registered[ $handle ]->src : '';
			$path = wp_normalize_path( $src );
			// Theme styles, and Elementor's global (kit) styles, which would otherwise restyle the portal's headings and text.
			if ( $src && ( false !== strpos( $path, $theme_root ) || false !== strpos( $path, '/elementor' ) ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Escape helper.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function e( $s ) {
		return esc_html( (string) $s );
	}

	/**
	 * Inline SVG icon.
	 *
	 * @param string $k    Icon.
	 * @param int    $size Size.
	 * @return string
	 */
	public static function icon( $k, $size = 18 ) {
		$ic = array(
			'cal'    => 'M7 2v2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2zm-2 7h14v10H5z',
			'box'    => 'M4 4h16v4H4zm1 6h14v10H5zm4 2v2h6v-2z',
			'pen'    => 'M4 17.3V20h2.7l8-8-2.7-2.7zM17.7 9 15 6.3l1.4-1.4a1 1 0 0 1 1.4 0l1.3 1.3a1 1 0 0 1 0 1.4z',
			'book'   => 'M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm1 3v4h10V5z',
			'shield' => 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5z',
			'coin'   => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15v1.5h-2V17c-1.6-.3-2.8-1.3-3-2.8h2c.2.7.9 1.2 2 1.2 1.2 0 1.8-.5 1.8-1.1 0-.7-.6-1-2.3-1.4-2-.5-3.2-1.2-3.2-2.8 0-1.3 1-2.3 2.7-2.6V6h2v1.5c1.5.3 2.5 1.3 2.6 2.7h-2c-.1-.7-.7-1.2-1.7-1.2-1.1 0-1.6.5-1.6 1 0 .6.6.9 2.2 1.3 2.1.5 3.3 1.2 3.3 2.9 0 1.4-1 2.5-2.8 2.8z',
			'star'   => 'm12 2 3 6.5 7 .8-5.2 4.8 1.4 7L12 17.6 5.8 21l1.4-7L2 9.3l7-.8z',
			'check'  => 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z',
			'grad'   => 'M12 3 1 9l11 6 9-4.9V17h2V9zM5 13.2v4L12 21l7-3.8v-4L12 17z',
			'people' => 'M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 2c-2.7 0-8 1.3-8 4v3h16v-3c0-2.7-5.3-4-8-4zm8 0c-.3 0-.7 0-1.1.1 1.3.9 2.1 2.2 2.1 3.9v3h7v-3c0-2.7-5.3-4-8-4z',
			'gavel'  => 'M1 21h12v2H1zM5.2 8.1l2.8-2.8 14.1 14.1-2.8 2.8zM12.3 1l5.6 5.7-2.8 2.8-5.7-5.6zM3.8 9.5l5.6 5.7-2.8 2.8L1 12.3z',
			'folder' => 'M10 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8z',
			'plus'   => 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z',
			'lock'   => 'M12 1a5 5 0 0 0-5 5v3H5v13h14V9h-2V6a5 5 0 0 0-5-5zm-3 8V6a3 3 0 0 1 6 0v3z',
		);
		$d  = isset( $ic[ $k ] ) ? $ic[ $k ] : $ic['folder'];
		return '<svg width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="' . esc_attr( $d ) . '"/></svg>';
	}

	/**
	 * Document thumbnail (the coloured page icon).
	 *
	 * @param string $title Title.
	 * @param string $file  File name.
	 * @param string $kind  Kind override.
	 * @return string
	 */
	public static function thumb( $title, $file = '', $kind = '' ) {
		$kinds = array(
			'agenda'  => array( '#0077a8', 'AGENDA', '<path d="M14 25h3M14 30h3M14 35h3" stroke="#0077a8" stroke-width="2"/><path d="M20 25h12M20 30h10M20 35h12" stroke="#9fb7c4" stroke-width="2"/>' ),
			'minutes' => array( '#3f8a1f', 'MINUTES', '<path d="M12 25h20M12 30h18M12 35h20M12 40h14" stroke="#9fb7c4" stroke-width="2"/>' ),
			'package' => array( '#0b6d8f', 'PACKAGE', '<rect x="12" y="24" width="20" height="16" rx="2" fill="#d9eef7"/><path d="M12 28h20M22 24v16" stroke="#0b6d8f" stroke-width="1.6"/>' ),
			'finance' => array( '#b7791f', 'FINANCE', '<path d="M13 40V33M19 40V28M25 40V31M31 40V24" stroke="#b7791f" stroke-width="3.2"/><path d="M11 41h22" stroke="#9fb7c4" stroke-width="1.4"/>' ),
			'policy'  => array( '#305f8a', 'POLICY', '<path d="M22 23l9 3v6c0 5-4 8-9 10-5-2-9-5-9-10v-6z" fill="#dfe9f3" stroke="#305f8a" stroke-width="1.6"/><path d="M18 32l3 3 5-6" fill="none" stroke="#305f8a" stroke-width="1.8"/>' ),
			'qa'      => array( '#3f8a1f', 'QA', '<path d="M13 26l2 2 4-4M13 33l2 2 4-4M13 40l2 2 4-4" fill="none" stroke="#3f8a1f" stroke-width="1.8"/><path d="M22 26h10M22 33h10M22 40h8" stroke="#9fb7c4" stroke-width="2"/>' ),
			'orient'  => array( '#0093cc', 'GUIDE', '<circle cx="22" cy="31" r="8" fill="#d9eef7" stroke="#0093cc" stroke-width="1.6"/><path d="M22 31l4-5-2 7z" fill="#0093cc"/>' ),
			'doc'     => array( '#566573', 'DOC', '<path d="M12 25h20M12 30h20M12 35h16" stroke="#9fb7c4" stroke-width="2"/>' ),
		);
		$exts  = array(
			'pdf'  => array( 'PDF', '#c8102e' ),
			'doc'  => array( 'DOC', '#2b579a' ),
			'docx' => array( 'DOCX', '#2b579a' ),
			'xls'  => array( 'XLS', '#217346' ),
			'xlsx' => array( 'XLSX', '#217346' ),
			'csv'  => array( 'CSV', '#217346' ),
			'ppt'  => array( 'PPT', '#d24726' ),
			'pptx' => array( 'PPTX', '#d24726' ),
			'jpg'  => array( 'JPG', '#7a4e9a' ),
			'jpeg' => array( 'JPG', '#7a4e9a' ),
			'png'  => array( 'PNG', '#7a4e9a' ),
			'txt'  => array( 'TXT', '#566573' ),
		);
		if ( ! $kind ) {
			$n = strtolower( $title );
			if ( preg_match( '/agenda/', $n ) ) {
				$kind = 'agenda';
			} elseif ( preg_match( '/minute/', $n ) ) {
				$kind = 'minutes';
			} elseif ( preg_match( '/package/', $n ) ) {
				$kind = 'package';
			} elseif ( preg_match( '/financ|budget|statement|audit/', $n ) ) {
				$kind = 'finance';
			} elseif ( preg_match( '/quality|compliance|aoda|safety|privacy/', $n ) ) {
				$kind = 'qa';
			} elseif ( preg_match( '/welcome|orientation|roles/', $n ) ) {
				$kind = 'orient';
			} elseif ( preg_match( '/polic|bylaw|code of conduct|conflict/', $n ) ) {
				$kind = 'policy';
			} else {
				$kind = 'doc';
			}
		}
		$k   = $kinds[ $kind ] ?? $kinds['doc'];
		$ext = strtolower( pathinfo( (string) $file, PATHINFO_EXTENSION ) );
		$e   = $exts[ $ext ] ?? $exts['pdf'];
		$w   = strlen( $e[0] ) > 3 ? 19 : 15;
		$x   = strlen( $e[0] ) > 3 ? 11.5 : 9.5;
		return '<span class="thumb"><svg viewBox="0 0 44 56" aria-hidden="true"><path d="M2 2h30l10 10v42H2z" fill="#ffffff" stroke="#c9d6de"/><path d="M32 2v10h10" fill="#eef3f6" stroke="#c9d6de"/><rect x="2" y="12" width="40" height="8" fill="' . $k[0] . '"/><text x="22" y="18.3" text-anchor="middle" font-family="Ubuntu, Arial, sans-serif" font-size="5.6" font-weight="700" fill="#ffffff" letter-spacing=".3">' . $k[1] . '</text>' . $k[2] . '<rect x="2" y="47" width="' . $w . '" height="7" rx="1" fill="' . $e[1] . '"/><text x="' . $x . '" y="52.4" text-anchor="middle" font-family="Arial, sans-serif" font-size="4.8" font-weight="700" fill="#ffffff">' . $e[0] . '</text></svg></span>';
	}

	/**
	 * One document as a list item.
	 *
	 * @param WP_Post|int $doc  Attachment.
	 * @param string      $meta Meta line (defaults to the document date).
	 * @param string      $kind Thumbnail kind.
	 * @return string
	 */
	public static function doc_item( $doc, $meta = null, $kind = '' ) {
		$doc  = get_post( $doc );
		$file = (string) get_post_meta( $doc->ID, '_wp_attached_file', true );
		if ( null === $meta ) {
			$meta = self::doc_when( $doc );
		}
		$sign = '';
		$uid  = get_current_user_id();
		if ( BDACI_PA_Signoffs::doc_needs( $doc->ID ) && BDACI_PA_Members::is_authorized( $uid ) ) {
			$st   = BDACI_PA_Signoffs::doc_status( $doc->ID, $uid );
			$sign = $st['signed'] ? '<span class="pill pill-ok" title="' . esc_attr( BDACI_PA_Signoffs::fmt( $st['signed'] ) ) . '">' . esc_html__( 'Signed', 'bdaci-portal-access' ) . '</span>' : '<span class="pill pill-warn">' . esc_html__( 'Sign-off needed', 'bdaci-portal-access' ) . '</span>';
		}
		return '<li>' . self::thumb( get_the_title( $doc ), $file, $kind ) . '<div class="grow"><b>' . self::e( get_the_title( $doc ) ) . '</b><span class="muted small">' . self::e( $meta ) . '</span></div>' . $sign . '<a class="btn btn-sm" href="' . esc_url( BDACI_PA_Documents::link( $doc->ID ) ) . '" target="_blank" rel="noopener"' . self::view_attrs( $doc->ID ) . '>' . esc_html__( 'Open', 'bdaci-portal-access' ) . '</a></li>';
	}

	/**
	 * Data attributes that make a document link open in the pop-up viewer.
	 * Without JavaScript the link still opens the file in a new tab.
	 *
	 * @param int $id Document.
	 * @return string Attributes, starting with a space.
	 */
	public static function view_attrs( $id ) {
		$id   = (int) $id;
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$type = 'pdf' === $ext ? 'pdf' : ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ? 'img' : ( in_array( $ext, array( 'txt', 'csv' ), true ) ? 'text' : 'file' ) );
		$uid  = get_current_user_id();
		$a    = array(
			'data-view'  => $id,
			'data-title' => get_the_title( $id ),
			'data-type'  => $type,
			'data-ext'   => strtoupper( $ext ),
			'data-meta'  => self::doc_when( $id ),
			'data-dl'    => BDACI_PA_Documents::link( $id, true ),
		);
		if ( BDACI_PA_Signoffs::doc_needs( $id ) && BDACI_PA_Members::is_authorized( $uid ) && BDACI_PA_Sections::can_open( $id, $uid ) ) {
			$st                  = BDACI_PA_Signoffs::doc_status( $id, $uid );
			$a['data-statement'] = BDACI_PA_Signoffs::doc_statement( $id );
			if ( $st['signed'] ) {
				$a['data-signed'] = BDACI_PA_Signoffs::fmt( $st['signed'] );
				$a['data-receipt'] = wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_signoff_receipt&pack=doc&doc=' . $id ), 'bdaci_pa_receipt_doc' );
			} else {
				$a['data-sign'] = '1';
			}
		}
		$packs = array();
		foreach ( array_keys( BDACI_PA_Signoffs::packs() ) as $k ) {
			if ( in_array( $k, BDACI_PA_Sections::doc_sections( $id ), true ) && BDACI_PA_Signoffs::needs_action( $k, $uid ) ) {
				$packs[] = $k;
			}
		}
		if ( $packs ) {
			$a['data-packs'] = implode( ',', $packs );
		}
		$h = '';
		foreach ( $a as $k => $v ) {
			$h .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
		}
		return $h;
	}

	/**
	 * What the document viewer needs: where to post sign-offs, the open sign-off packs and its words.
	 *
	 * @return array
	 */
	public static function viewer_data() {
		$uid   = get_current_user_id();
		$packs = array();
		foreach ( BDACI_PA_Signoffs::packs() as $k => $p ) {
			if ( ! BDACI_PA_Signoffs::needs_action( $k, $uid ) ) {
				continue;
			}
			$st   = BDACI_PA_Signoffs::status( $k, $uid );
			$docs = array();
			foreach ( BDACI_PA_Signoffs::docs( $k, $uid ) as $d ) {
				$docs[] = array(
					'id'    => $d->ID,
					'title' => get_the_title( $d ),
				);
			}
			$packs[ $k ] = array(
				'title'     => $p['title'],
				'statement' => $p['statement'],
				'docs'      => $docs,
				'opened'    => array_map( 'intval', array_keys( $st['opened'] ) ),
			);
		}
		$u = wp_get_current_user();
		return array(
			'post'   => admin_url( 'admin-post.php' ),
			'nonce'  => array(
				'docsign' => wp_create_nonce( 'bdaci_pa_portal_docsign' ),
				'signoff' => wp_create_nonce( 'bdaci_pa_portal_signoff' ),
			),
			'me'     => $u->display_name,
			'now'    => date_i18n( 'M j, Y g:i A' ),
			'packs'  => $packs,
			'sig'    => $uid ? BDACI_PA_Signoffs::sig_fields( 'bpv-sig' ) : '',
			'i18n'   => array(
				'close'       => __( 'Close', 'bdaci-portal-access' ),
				'download'    => __( 'Download', 'bdaci-portal-access' ),
				'print'       => __( 'Print', 'bdaci-portal-access' ),
				'newtab'      => __( 'Open in new tab', 'bdaci-portal-access' ),
				'expand'      => __( 'Expand', 'bdaci-portal-access' ),
				'shrink'      => __( 'Exit expanded view', 'bdaci-portal-access' ),
				'full'        => __( 'Full screen', 'bdaci-portal-access' ),
				'exitfull'    => __( 'Exit full screen', 'bdaci-portal-access' ),
				'loading'     => __( 'Loading…', 'bdaci-portal-access' ),
				'noPreview'   => __( 'This type of file can\'t be shown in the browser. Download it to read it in Word, Excel or PowerPoint.', 'bdaci-portal-access' ),
				'tooBig'      => __( 'This file is too large to show here. Download it to read it.', 'bdaci-portal-access' ),
				'loadFail'    => __( 'The document could not be loaded. Try Open in new tab or Download.', 'bdaci-portal-access' ),
				'signTitle'   => __( 'Sign off on this document', 'bdaci-portal-access' ),
				'signHint'    => __( 'When you have read it, confirm and sign below.', 'bdaci-portal-access' ),
				'signFile'    => __( 'Download and read the file, then come back here to sign.', 'bdaci-portal-access' ),
				'signBtn'     => __( 'Sign off', 'bdaci-portal-access' ),
				'signed'      => __( 'Signed off', 'bdaci-portal-access' ),
				/* translators: %s: date and time */
				'signedOn'    => __( 'You signed off on %s.', 'bdaci-portal-access' ),
				'receipt'     => __( 'Print receipt', 'bdaci-portal-access' ),
				/* translators: %s: sign-off title */
				'packTitle'   => __( '%s sign-off', 'bdaci-portal-access' ),
				/* translators: 1: opened, 2: total */
				'packProg'    => __( '%1$d of %2$d documents opened. Open each one, then sign off here.', 'bdaci-portal-access' ),
				'packReady'   => __( 'You have opened every document. Confirm and sign below.', 'bdaci-portal-access' ),
				'next'        => __( 'Next:', 'bdaci-portal-access' ),
				'leaveTitle'  => __( 'You haven\'t signed off yet', 'bdaci-portal-access' ),
				'leaveText'   => __( 'This document asks for your sign-off. Sign now, or close and come back to it later from your to-do list.', 'bdaci-portal-access' ),
				'signNow'     => __( 'Sign now', 'bdaci-portal-access' ),
				'later'       => __( 'Close, sign later', 'bdaci-portal-access' ),
				'needSig'     => __( 'Type your name or draw your signature.', 'bdaci-portal-access' ),
				'dialog'      => __( 'Document viewer', 'bdaci-portal-access' ),
			),
		);
	}

	/**
	 * Document date plus when it was uploaded, e.g. "Nov 10, 2026 · Uploaded Nov 24, 2026 11:21 AM",
	 * or "Nov 24, 2026 · Uploaded 11:21 AM" when both fall on the same day.
	 *
	 * @param WP_Post|int $doc Attachment.
	 * @return string
	 */
	public static function doc_when( $doc ) {
		$doc  = get_post( $doc );
		$date = BDACI_PA_Sections::doc_date( $doc->ID );
		$out  = date_i18n( 'M j, Y', strtotime( $date ) );
		$up   = self::uploaded_at( $doc );
		return $out . ' · ' . sprintf( /* translators: %s: date and time */ __( 'Uploaded %s', 'bdaci-portal-access' ), substr( $doc->post_date, 0, 10 ) === $date ? mysql2date( 'g:i A', $doc->post_date ) : $up );
	}

	/**
	 * "Nov 24, 2026 11:21 AM" for when a document was uploaded.
	 *
	 * @param WP_Post|int $doc Attachment.
	 * @return string
	 */
	public static function uploaded_at( $doc ) {
		$doc = get_post( $doc );
		return $doc ? mysql2date( 'M j, Y g:i A', $doc->post_date ) : '';
	}

	/**
	 * Page heading.
	 *
	 * @param string $title Title (escaped here).
	 * @param string $sub   Subtitle (escaped here).
	 * @param string $right Right side HTML.
	 * @return string
	 */
	public static function head( $title, $sub = '', $right = '' ) {
		return '<div class="pagehead"><div><h1>' . self::e( $title ) . '</h1>' . ( $sub ? '<p class="muted">' . self::e( $sub ) . '</p>' : '' ) . '</div>' . $right . '</div>';
	}

	/**
	 * A small form posting a portal action.
	 *
	 * @param string $do     Action.
	 * @param array  $fields Hidden fields.
	 * @param string $inner  Inner HTML (buttons, inputs).
	 * @param string $attrs  Extra form attributes.
	 * @return string
	 */
	public static function form( $do, array $fields, $inner, $attrs = '' ) {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attrs . '>';
		$h .= '<input type="hidden" name="action" value="bdaci_pa_portal"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		$h .= wp_nonce_field( 'bdaci_pa_portal_' . $do, '_bdaci_nonce', true, false );
		foreach ( $fields as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		return $h . $inner . '</form>';
	}

	/**
	 * Store a one-time message for the current user.
	 *
	 * @param string $msg  Message.
	 * @param string $type ok|err.
	 */
	public static function flash( $msg, $type = 'ok' ) {
		set_transient( 'bdaci_pa_flash_' . get_current_user_id(), array( $msg, $type ), 120 );
	}

	/**
	 * Print and clear the one-time message.
	 *
	 * @return string
	 */
	public static function take_flash() {
		$key = 'bdaci_pa_flash_' . get_current_user_id();
		$f   = get_transient( $key );
		if ( ! is_array( $f ) ) {
			return '';
		}
		delete_transient( $key );
		return '<div class="notice-bar ' . ( 'err' === $f[1] ? 'err' : 'ok' ) . '" role="status">' . self::e( $f[0] ) . '</div>';
	}

	/**
	 * Role summary for the top bar.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function role_label( $user_id ) {
		$labels = BDACI_PA_Roles::labels();
		$out    = array();
		foreach ( BDACI_PA_Roles::user_keys( $user_id ) as $k ) {
			$out[] = $labels[ $k ];
		}
		if ( ! $out && user_can( $user_id, 'manage_options' ) ) {
			$out[] = __( 'Site administrator', 'bdaci-portal-access' );
		}
		return implode( ', ', $out );
	}

	/**
	 * Full page: top bar, side menu and the screen.
	 *
	 * @param int $post_id Page.
	 * @return string
	 */
	public static function render( $post_id ) {
		$uid    = get_current_user_id();
		$user   = wp_get_current_user();
		$screen = self::screen_of( $post_id );
		$map    = self::screen_map();
		$logo   = BDACI_PA_Settings::get( 'logo_url' ) ? BDACI_PA_Settings::get( 'logo_url' ) : BDACI_PA_URL . 'assets/logo-full.png';

		$top = array();
		foreach ( self::menu_pages( 'top' ) as $p ) {
			$top[] = '<a href="' . esc_url( get_permalink( $p ) ) . '"' . ( (int) $p->ID === (int) $post_id ? ' aria-current="page"' : '' ) . '>' . self::e( get_the_title( $p ) ) . '</a>';
		}
		$home_link = '<a href="' . esc_url( BDACI_PA_Settings::portal_home_url() ) . '"' . ( 'dashboard' === $screen && ! self::manage_key() ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Home', 'bdaci-portal-access' ) . '</a>';
		$profile   = isset( $map['profile'] ) ? get_permalink( $map['profile'] ) : '';

		$h  = '<div class="bp" id="bp"><a class="skip" href="#bp-main">' . esc_html__( 'Skip to content', 'bdaci-portal-access' ) . '</a>';
		$h .= '<header class="topbar"><div class="topbar-in"><button class="menubtn" id="bp-menubtn" aria-expanded="false" aria-controls="bp-side">' . esc_html__( 'Menu', 'bdaci-portal-access' ) . '</button>';
		$h .= '<a class="brandlogo" href="' . esc_url( BDACI_PA_Settings::portal_home_url() ) . '"><img src="' . esc_url( $logo ) . '" alt="' . esc_attr( BDACI_PA_Settings::get( 'portal_name' ) ) . '"></a>';
		$h .= '<nav class="topnav" aria-label="' . esc_attr__( 'Main', 'bdaci-portal-access' ) . '">' . $home_link . implode( '', $top ) . '</nav>';
		$h .= '<div class="userchip">' . ( $profile ? '<a class="mechip" href="' . esc_url( $profile ) . '"' . ( 'profile' === $screen ? ' aria-current="page"' : '' ) . '>' : '<span class="mechip">' );
		$h .= '<span class="uname" style="text-align:right;line-height:1.2"><b>' . self::e( $user->display_name ) . '</b><br><span style="opacity:.8;font-size:.8rem">' . self::e( self::role_label( $uid ) ) . '</span></span>' . BDACI_PA_Profile::avatar( $uid ) . ( $profile ? '</a>' : '</span>' );
		$h .= '<a class="linkbtn signout" href="' . esc_url( wp_logout_url( BDACI_PA_Login::url() ) ) . '">' . esc_html__( 'Sign out', 'bdaci-portal-access' ) . '</a></div></div></header>';
		$h .= '<div class="shell">' . self::sidebar( $post_id, $home_link . implode( '', $top ) . '<a href="' . esc_url( wp_logout_url( BDACI_PA_Login::url() ) ) . '">' . esc_html__( 'Sign out', 'bdaci-portal-access' ) . '</a>' ) . '<main class="work" id="bp-main">' . self::take_flash();

		$manage = self::manage_key();
		$admin_screen = ( $manage && ! self::$restricted ) ? BDACI_PA_Admin_Portal::front( $manage ) : '';
		$h           .= self::crumbs( $post_id, $screen, '' !== $admin_screen ? $manage : '' );
		if ( '' !== $admin_screen ) {
			$h .= $admin_screen;
		} elseif ( self::$restricted ) {
			$h .= BDACI_PA_Screens::restricted( $post_id, $screen );
		} else {
			$h .= BDACI_PA_Screens::render( $screen, $post_id );
			$post = get_post( $post_id );
			if ( $post && '' !== trim( $post->post_content ) ) {
				$h .= '<section class="page-content">' . apply_filters( 'the_content', $post->post_content ) . '</section>'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}
		$h .= '</main></div></div>';
		return $h;
	}

	/**
	 * Names of the admin screens shown inside the portal.
	 *
	 * @return array key => label
	 */
	public static function admin_labels() {
		return array(
			'users'    => __( 'Authorized Users', 'bdaci-portal-access' ),
			'signoffs' => __( 'Sign-off Audit Trail', 'bdaci-portal-access' ),
			'docs'     => __( 'Sections & Uploads', 'bdaci-portal-access' ),
			'meetings' => __( 'Meetings', 'bdaci-portal-access' ),
			'qa'       => __( 'QA Testing', 'bdaci-portal-access' ),
			'test'     => __( 'Test data', 'bdaci-portal-access' ),
		);
	}

	/**
	 * Breadcrumb above the page: where this page sits in the portal menus.
	 *
	 * @param int    $post_id Page.
	 * @param string $screen  Screen.
	 * @param string $manage  Admin screen key shown, if any.
	 * @return string
	 */
	public static function crumbs( $post_id, $screen, $manage = '' ) {
		$home  = array( __( 'Home', 'bdaci-portal-access' ), BDACI_PA_Settings::portal_home_url() );
		$parts = array();
		if ( $manage ) {
			$labels = self::admin_labels();
			$admin  = BDACI_PA_Sections::is_admin( get_current_user_id() );
			$parts[] = array( $admin ? __( 'Portal admin', 'bdaci-portal-access' ) : __( 'Testing', 'bdaci-portal-access' ), '' );
			if ( 'person' === $manage ) {
				$parts[] = array( $labels['users'], BDACI_PA_Admin_Portal::front_url( 'users' ) );
				$parts[] = array( __( 'Directory details', 'bdaci-portal-access' ), '' );
			} else {
				$parts[] = array( $labels[ $manage ] ?? $manage, '' );
			}
		} elseif ( 'dashboard' === $screen ) {
			return '';
		} else {
			$menu   = (string) get_post_meta( $post_id, self::META_MENU, true );
			$groups = array(
				'top'        => __( 'Top menu', 'bdaci-portal-access' ),
				'board'      => __( 'Board', 'bdaci-portal-access' ),
				'committees' => __( 'Committees', 'bdaci-portal-access' ),
			);
			if ( 'profile' === $screen ) {
				$parts[] = array( __( 'Your name (top bar)', 'bdaci-portal-access' ), '' );
			} elseif ( isset( $groups[ $menu ] ) ) {
				$parts[] = array( $groups[ $menu ], '' );
			}
			$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
			foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $anc ) {
				if ( (int) $anc !== $root && in_array( $root, get_post_ancestors( $anc ), true ) ) {
					$parts[] = array( get_the_title( $anc ), get_permalink( $anc ) );
				}
			}
			$parts[] = array( get_the_title( $post_id ), '' );
		}
		$h = '<nav class="crumbs-bar" aria-label="' . esc_attr__( 'Breadcrumb', 'bdaci-portal-access' ) . '"><ol class="crumbs"><li><a href="' . esc_url( $home[1] ) . '">' . esc_html( $home[0] ) . '</a></li>';
		$last = count( $parts ) - 1;
		foreach ( $parts as $i => $p ) {
			$h .= '<li' . ( $i === $last ? ' aria-current="page"' : '' ) . '>' . ( $p[1] && $i !== $last ? '<a href="' . esc_url( $p[1] ) . '">' . self::e( $p[0] ) . '</a>' : self::e( $p[0] ) ) . '</li>';
		}
		return $h . '</ol></nav>';
	}

	/**
	 * Published portal pages in a menu group, in menu order.
	 *
	 * @param string $menu top|board|committees.
	 * @return WP_Post[]
	 */
	public static function menu_pages( $menu ) {
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		if ( ! $root ) {
			return array();
		}
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'post_parent'      => $root,
				'posts_per_page'   => 100,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'meta_key'         => self::META_MENU, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $menu, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
				'bdaci_pa_all'     => true,
			)
		);
		$admin = BDACI_PA_Sections::is_admin( get_current_user_id() );
		return array_values(
			array_filter(
				$pages,
				static function ( $p ) use ( $admin ) {
					return $admin || 'hidden' !== BDACI_PA_Access::level( $p->ID );
				}
			)
		);
	}

	/**
	 * Side menu.
	 *
	 * @param int    $post_id Current page.
	 * @param string $mobile  Top links repeated for small screens.
	 * @return string
	 */
	public static function sidebar( $post_id, $mobile ) {
		$uid   = get_current_user_id();
		$admin = BDACI_PA_Sections::is_admin( $uid );
		$h     = '<nav class="side" id="bp-side" aria-label="' . esc_attr__( 'Portal sections', 'bdaci-portal-access' ) . '">';
		$h    .= '<div class="mobile-only"><h4>' . esc_html__( 'Portal', 'bdaci-portal-access' ) . '</h4>' . $mobile . '</div>';
		$groups = array(
			'board'      => __( 'Board', 'bdaci-portal-access' ),
			'committees' => __( 'Committees', 'bdaci-portal-access' ),
		);
		foreach ( $groups as $menu => $label ) {
			$items = '';
			foreach ( self::menu_pages( $menu ) as $p ) {
				$screen = self::screen_of( $p->ID );
				$can    = BDACI_PA_Access::can_view( $p->ID, $uid );
				$extra  = '';
				if ( 'hidden' === BDACI_PA_Access::level( $p->ID ) ) {
					$extra .= ' <span class="lock">' . esc_html__( 'Hidden', 'bdaci-portal-access' ) . '</span>';
				}
				if ( ! $can ) {
					if ( in_array( $screen, array( 'education', 'finance' ), true ) ) {
						$req    = BDACI_PA_Requests::pending_for( $uid, $screen );
						$extra .= $req ? ' <span class="pill pill-warn">' . esc_html__( 'Requested', 'bdaci-portal-access' ) . '</span>' : ' <span class="lock">' . esc_html__( 'Members only', 'bdaci-portal-access' ) . '</span>';
					} elseif ( 'section' === $screen ) {
						$extra .= ' <span class="lock">' . esc_html__( 'Restricted', 'bdaci-portal-access' ) . '</span>';
					} else {
						continue; // Pages for other groups (e.g. Board sign-offs) are left out entirely.
					}
				} elseif ( in_array( $screen, array( 'orientation', 'qa' ), true ) && BDACI_PA_Signoffs::needs_action( $screen, $uid ) ) {
					$extra .= ' <span class="badge-req">' . esc_html__( 'Action', 'bdaci-portal-access' ) . '</span>';
				}
				$title = self::e( get_the_title( $p ) );
				if ( 'committees' === $menu ) {
					$c     = 'finance' === $screen ? 'fin' : ( 'education' === $screen ? 'edu' : 'other' );
					$title = '<span style="display:inline-flex;gap:8px;align-items:center"><span class="dot" style="background:var(--cat-' . $c . ')"></span>' . $title . '</span>';
				}
				$items .= '<a href="' . esc_url( get_permalink( $p ) ) . '"' . ( (int) $p->ID === (int) $post_id ? ' aria-current="page"' : '' ) . '>' . $title . $extra . '</a>';
			}
			if ( $items ) {
				$h .= '<h4>' . esc_html( $label ) . '</h4>' . $items;
			}
		}
		if ( $admin ) {
			$h .= '<h4>' . esc_html__( 'Portal admin', 'bdaci-portal-access' ) . '</h4>';
			$req = BDACI_PA_Members::pending_count() + BDACI_PA_Requests::pending_count();
			$cur   = self::manage_key();
			$items = array(
				'users'    => __( 'Authorized Users', 'bdaci-portal-access' ) . ( $req ? ' <span class="badge-req">' . (int) $req . '</span>' : '' ),
				'signoffs' => __( 'Sign-off Audit Trail', 'bdaci-portal-access' ),
				'docs'     => __( 'Sections & Uploads', 'bdaci-portal-access' ),
				'meetings' => __( 'Meetings', 'bdaci-portal-access' ),
				'qa'       => __( 'QA Testing', 'bdaci-portal-access' ),
			);
			if ( current_user_can( 'manage_options' ) ) {
				$items['test'] = __( 'Test data', 'bdaci-portal-access' );
			}
			foreach ( $items as $k => $label ) {
				$h .= '<a href="' . esc_url( BDACI_PA_Admin_Portal::front_url( $k ) ) . '"' . ( $cur === $k || ( 'person' === $cur && 'users' === $k ) ? ' aria-current="page"' : '' ) . '>' . wp_kses( $label, array( 'span' => array( 'class' => array() ) ) ) . '</a>';
			}
			$h .= '<a href="' . esc_url( admin_url( 'admin.php?page=bdaci-portal-log' ) ) . '">' . esc_html__( 'Activity Log', 'bdaci-portal-access' ) . ' <span class="small muted">↗</span></a>';
		} elseif ( BDACI_PA_QA::can_view( $uid ) ) {
			$h .= '<h4>' . esc_html__( 'Testing', 'bdaci-portal-access' ) . '</h4><a href="' . esc_url( BDACI_PA_Admin_Portal::front_url( 'qa' ) ) . '"' . ( 'qa' === self::manage_key() ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'QA Testing', 'bdaci-portal-access' ) . '</a>';
		}
		$h .= '<div class="side-foot"><img src="' . esc_url( BDACI_PA_URL . 'assets/logo-badge.png' ) . '" alt=""><span>' . esc_html__( 'Celebrating 70 years of community involvement', 'bdaci-portal-access' ) . '</span></div>';
		return $h . '</nav>';
	}

	/* ---------------------------------------------------------------------
	 * Front-end actions (RSVP, sign-off, access requests, profile, support)
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a portal form post.
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below with the per-action nonce.
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$uid = get_current_user_id();
		if ( ! $uid || ! BDACI_PA_Members::is_authorized( $uid ) ) {
			wp_die( esc_html__( 'Please sign in to the portal.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST['_bdaci_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bdaci_nonce'] ) ), 'bdaci_pa_portal_' . $do ) ) {
			wp_die( esc_html__( 'This form has expired. Go back, reload the page and try again.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}
		// phpcs:enable
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = BDACI_PA_Settings::portal_home_url();
		}
		switch ( $do ) {
			case 'rsvp':
				BDACI_PA_Meetings::handle_rsvp( $uid );
				break;
			case 'signoff':
				BDACI_PA_Signoffs::handle_sign( $uid );
				break;
			case 'docsign':
				BDACI_PA_Signoffs::handle_doc_sign( $uid );
				break;
			case 'request':
			case 'request_cancel':
				BDACI_PA_Requests::handle_front( $do, $uid );
				break;
			case 'profile':
			case 'photo':
				BDACI_PA_Profile::handle( $do, $uid );
				break;
			case 'help':
				self::handle_help( $uid );
				break;
		}
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Support form: email the office.
	 *
	 * @param int $uid User.
	 */
	private static function handle_help( $uid ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in handle().
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$msg   = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		// phpcs:enable
		if ( '' === trim( $msg ) ) {
			self::flash( __( 'Please write a message before sending.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		$rate = 'bdaci_pa_help_' . $uid;
		if ( (int) get_transient( $rate ) >= 5 ) {
			self::flash( __( 'You have sent several messages in the last hour. Please wait a little, or email the office directly.', 'bdaci-portal-access' ), 'err' );
			return;
		}
		set_transient( $rate, (int) get_transient( $rate ) + 1, HOUR_IN_SECONDS );
		$user = wp_get_current_user();
		$to   = BDACI_PA_Settings::support_email();
		/* translators: 1: topic, 2: person */
		$subject = sprintf( __( '[Portal support] %1$s (%2$s)', 'bdaci-portal-access' ), $topic, $user->display_name );
		$body    = sprintf( "%s <%s>\n%s: %s\n\n%s", $user->display_name, $user->user_email, __( 'Topic', 'bdaci-portal-access' ), $topic, $msg );
		$ok      = wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>' ) );
		BDACI_PA_Log::add( 'support_message', $user->user_email, $uid, $topic );
		self::flash( $ok ? __( 'Thanks. Your message has been sent to the BDACI office.', 'bdaci-portal-access' ) : __( 'Your message could not be sent. Please email the office directly.', 'bdaci-portal-access' ), $ok ? 'ok' : 'err' );
	}
}
