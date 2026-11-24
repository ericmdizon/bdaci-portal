<?php
/**
 * Content gate: decides who can see which pages and enforces it everywhere content leaks.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access rules.
 *
 * Each page/post has an access level stored in post meta `_bdaci_access`:
 *   ''          inherit (protected as "portal" if it sits under the portal root page)
 *   'public'    explicitly public, even under the portal root
 *   'portal'    any authorized portal user
 *   'board'     Board Members and Portal Admins
 *   'committee' Education Committee Members and Portal Admins (plus Board Members if the setting allows)
 *   'finance'   Finance Committee Members, Board Members and Portal Admins
 *
 * A page can also be hidden (post meta `_bdaci_hidden` = 1). Hidden pages and everything under them
 * get the level 'hidden': only Portal Admins and site administrators can see them; everyone else gets a 404.
 */
class BDACI_PA_Access {

	const META      = '_bdaci_access';
	const HIDDEN    = '_bdaci_hidden';
	const LEVELS    = array( 'portal', 'board', 'committee', 'finance' );
	const CACHE_KEY = 'bdaci_pa_protected_map';

	/**
	 * Per-request cache of the protected map.
	 *
	 * @var array|null
	 */
	private static $map = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'enforce_allowlist_on_session' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'gate' ), 0 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_queries' ) );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_sitemap' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'rest_gate' ), 10, 3 );
		add_filter( 'get_pages', array( __CLASS__, 'filter_page_list' ) );
		add_filter( 'the_content', array( __CLASS__, 'content_fallback' ), 1 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'excerpt_fallback' ), 1, 2 );
		add_filter( 'comments_array', array( __CLASS__, 'filter_comments' ), 10, 2 );
		add_shortcode( 'bdaci_portal_only', array( __CLASS__, 'shortcode_only' ) );
		add_shortcode( 'bdaci_logout_link', array( __CLASS__, 'shortcode_logout' ) );
		add_shortcode( 'bdaci_login_link', array( __CLASS__, 'shortcode_login' ) );

		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_flush_on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_flush_on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_flush_on_meta' ), 10, 3 );
	}

	/**
	 * Clear the cached map of protected posts.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		self::$map = null;
	}

	/**
	 * Flush when our meta changes.
	 *
	 * @param mixed  $meta_id  Unused.
	 * @param int    $post_id  Unused.
	 * @param string $meta_key Key.
	 */
	public static function maybe_flush_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( self::META === $meta_key || self::HIDDEN === $meta_key ) {
			self::flush_cache();
		}
	}

	/**
	 * Map of protected post ID => required level.
	 *
	 * @return array
	 */
	public static function protected_map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			self::$map = $cached;
			return $cached;
		}

		global $wpdb;
		$map      = array();
		$explicit = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", self::META ) );
		foreach ( $rows as $row ) {
			$explicit[ (int) $row->post_id ] = $row->meta_value;
		}
		foreach ( $explicit as $id => $level ) {
			if ( 'public' !== $level ) {
				$map[ $id ] = $level;
			}
		}
		$hidden = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", self::HIDDEN ) ) as $hid ) {
			$hidden[ (int) $hid ] = true;
			$map[ (int) $hid ]    = 'hidden';
		}

		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		if ( $root ) {
			// Walk the page tree under the root. Children inherit their nearest ancestor's level.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pages    = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('auto-draft', 'trash')" );
			$children = array();
			foreach ( $pages as $page ) {
				$children[ (int) $page->post_parent ][] = (int) $page->ID;
			}
			$root_level = isset( $explicit[ $root ] ) && 'public' !== $explicit[ $root ] ? $explicit[ $root ] : 'portal';
			$stack      = array( array( $root, $root_level ) );
			$seen       = array();
			while ( $stack ) {
				list( $id, $inherited ) = array_pop( $stack );
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$level       = isset( $explicit[ $id ] ) ? $explicit[ $id ] : $inherited;
				if ( $id === $root && isset( $explicit[ $id ] ) && 'public' === $explicit[ $id ] ) {
					$level = 'portal'; // The portal home itself can never be public.
				}
				if ( 'hidden' === $inherited || isset( $hidden[ $id ] ) ) {
					$level = 'hidden'; // A hidden section hides everything under it.
				}
				if ( 'public' === $level ) {
					unset( $map[ $id ] );
				} else {
					$map[ $id ] = $level;
				}
				foreach ( $children[ $id ] ?? array() as $child ) {
					$stack[] = array( $child, 'public' === $level ? 'portal' : $level );
				}
			}
		}

		set_transient( self::CACHE_KEY, $map, DAY_IN_SECONDS );
		self::$map = $map;
		return $map;
	}

	/**
	 * Level required for a post, or '' when it is public.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function level( $post_id ) {
		$map = self::protected_map();
		return $map[ (int) $post_id ] ?? '';
	}

	/**
	 * Whether a user meets a level.
	 *
	 * @param string $level   Level.
	 * @param int    $user_id User ID (0 = anonymous).
	 * @return bool
	 */
	public static function user_meets( $level, $user_id ) {
		if ( '' === $level || 'public' === $level ) {
			return true;
		}
		if ( ! $user_id || ! BDACI_PA_Members::is_authorized( $user_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'bdaci_manage_portal' ) || user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		if ( false !== strpos( $level, '+' ) ) {
			// Combined level, e.g. "board+committee": any one of the groups is enough.
			foreach ( explode( '+', $level ) as $part ) {
				if ( in_array( $part, self::LEVELS, true ) && self::user_meets( $part, $user_id ) ) {
					return true;
				}
			}
			return false;
		}
		switch ( $level ) {
			case 'board':
				return user_can( $user_id, 'bdaci_view_board' );
			case 'committee':
				return user_can( $user_id, 'bdaci_view_committee' )
					|| ( BDACI_PA_Settings::get( 'board_sees_committee' ) && user_can( $user_id, 'bdaci_view_board' ) );
			case 'finance':
				return user_can( $user_id, 'bdaci_view_finance' ) || user_can( $user_id, 'bdaci_view_board' );
			case 'hidden':
				return false;
			case 'portal':
			default:
				return user_can( $user_id, 'bdaci_view_portal' );
		}
	}

	/**
	 * Whether a user can view a post.
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $user_id User ID, defaults to current.
	 * @return bool
	 */
	public static function can_view( $post_id, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return self::user_meets( self::level( $post_id ), $user_id );
	}

	/**
	 * IDs the current user cannot view.
	 *
	 * @return int[]
	 */
	public static function hidden_ids() {
		$user_id = get_current_user_id();
		$hidden  = array();
		foreach ( self::protected_map() as $id => $level ) {
			if ( ! self::user_meets( $level, $user_id ) ) {
				$hidden[] = (int) $id;
			}
		}
		return $hidden;
	}

	/**
	 * Sign out anyone whose allowlist entry was revoked while they were signed in.
	 */
	public static function enforce_allowlist_on_session() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( BDACI_PA_Roles::is_portal_only( $user ) || user_can( $user, 'bdaci_manage_portal' ) ) {
			if ( ! BDACI_PA_Members::is_authorized( $user->ID ) ) {
				BDACI_PA_Log::add( 'session_ended_unauthorized', $user->user_email, $user->ID );
				wp_logout();
				if ( ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
					wp_safe_redirect( BDACI_PA_Login::url( array( 'msg' => 'revoked' ) ) );
					exit;
				}
			}
		}
	}

	/**
	 * Front-end gate for singular views (pages, posts, attachments).
	 */
	public static function gate() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$level   = self::level( $post_id );
		if ( '' === $level ) {
			return;
		}

		// Never let page caches store or serve protected pages.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();

		if ( 'hidden' === $level && ! self::can_view( $post_id ) ) {
			// Hidden sections don't exist for anyone but admins: no sign-in prompt, no "access restricted".
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( ! is_user_logged_in() ) {
			$here = get_permalink( $post_id );
			wp_safe_redirect( BDACI_PA_Login::url( array( 'redirect_to' => $here ) ) );
			exit;
		}
		if ( ! self::can_view( $post_id ) ) {
			BDACI_PA_Log::add( 'access_denied', wp_get_current_user()->user_email, get_current_user_id(), 'post ' . $post_id );
			if ( BDACI_PA_Members::is_authorized( get_current_user_id() ) && BDACI_PA_Portal::uses_layout( $post_id ) ) {
				// Signed-in members see the portal page with a "members only" panel (and Request access where offered).
				BDACI_PA_Portal::$restricted = true;
				status_header( 403 );
				return;
			}
			wp_die(
				esc_html__( 'Your account does not have access to this page. If you think this is a mistake, please contact the portal administrator.', 'bdaci-portal-access' ),
				esc_html__( 'Access restricted', 'bdaci-portal-access' ),
				array(
					'response'  => 403,
					'link_url'  => esc_url( BDACI_PA_Settings::portal_home_url() ),
					'link_text' => esc_html__( 'Back to the portal', 'bdaci-portal-access' ),
				)
			);
		}
	}

	/**
	 * Hide protected items from searches, archives, feeds and secondary queries.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_queries( $query ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( $query->get( 'bdaci_pa_all' ) ) {
			return; // Internal portal query; the caller checks access itself.
		}
		if ( $query->is_singular() && $query->is_main_query() ) {
			return; // The gate handles single views with a redirect or 403.
		}
		$hidden = self::hidden_ids();
		if ( ! $hidden ) {
			return;
		}
		$existing = (array) $query->get( 'post__not_in' );
		$query->set( 'post__not_in', array_unique( array_merge( $existing, $hidden ) ) );
	}

	/**
	 * Remove menu items pointing at pages the visitor cannot see.
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function filter_menu( $items ) {
		$hidden = array_flip( self::hidden_ids() );
		if ( ! $hidden ) {
			return $items;
		}
		$removed = array();
		foreach ( $items as $key => $item ) {
			$parent_gone = isset( $removed[ (int) $item->menu_item_parent ] );
			if ( $parent_gone || ( 'post_type' === $item->type && isset( $hidden[ (int) $item->object_id ] ) ) ) {
				$removed[ (int) $item->ID ] = true;
				unset( $items[ $key ] );
			}
		}
		return $items;
	}

	/**
	 * Keep protected items out of wp-sitemap.xml.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function filter_sitemap( $args ) {
		$ids = array_keys( self::protected_map() );
		if ( $ids ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $ids );
		}
		return $args;
	}

	/**
	 * Ask search engines not to index protected pages.
	 *
	 * @param array $robots Directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular() && '' !== self::level( get_queried_object_id() ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		if ( BDACI_PA_Login::is_login_request() ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * Remove protected pages from wp_list_pages()/get_pages() output.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function filter_page_list( $pages ) {
		$hidden = array_flip( self::hidden_ids() );
		if ( ! $hidden ) {
			return $pages;
		}
		return array_values(
			array_filter(
				$pages,
				function ( $page ) use ( $hidden ) {
					return ! isset( $hidden[ (int) $page->ID ] );
				}
			)
		);
	}

	/**
	 * Block single-item REST reads and oEmbed of protected content.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function rest_gate( $response, $handler, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$route   = $request->get_route();
		$post_id = 0;
		if ( preg_match( '#^/wp/v2/(posts|pages|media)/(\d+)(/|$)#', $route, $m ) ) {
			$post_id = (int) $m[2];
		} elseif ( '/oembed/1.0/embed' === $route || '/oembed/1.0/proxy' === $route ) {
			$post_id = url_to_postid( (string) $request->get_param( 'url' ) );
		}
		if ( $post_id && get_post( $post_id ) && ! self::can_view( $post_id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'bdaci-portal-access' ), array( 'status' => 404 ) );
		}
		return $response;
	}

	/**
	 * Fallback in case a theme renders protected content outside the normal template flow.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content_fallback( $content ) {
		$post = get_post();
		if ( $post && ! self::can_view( $post->ID ) ) {
			return '<p>' . esc_html__( 'This content is available to authorized portal users only.', 'bdaci-portal-access' ) . '</p>';
		}
		return $content;
	}

	/**
	 * Excerpt fallback.
	 *
	 * @param string  $excerpt Excerpt.
	 * @param WP_Post $post    Post.
	 * @return string
	 */
	public static function excerpt_fallback( $excerpt, $post = null ) {
		if ( $post && ! self::can_view( $post->ID ) ) {
			return '';
		}
		return $excerpt;
	}

	/**
	 * Hide comments on protected posts.
	 *
	 * @param array $comments Comments.
	 * @param int   $post_id  Post ID.
	 * @return array
	 */
	public static function filter_comments( $comments, $post_id ) {
		return self::can_view( $post_id ) ? $comments : array();
	}

	/**
	 * [bdaci_portal_only level="board|committee|finance|portal"]...[/bdaci_portal_only]
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Enclosed content.
	 * @return string
	 */
	public static function shortcode_only( $atts, $content = '' ) {
		$atts  = shortcode_atts( array( 'level' => 'portal' ), $atts, 'bdaci_portal_only' );
		$level = in_array( $atts['level'], self::LEVELS, true ) ? $atts['level'] : 'portal';
		if ( self::user_meets( $level, get_current_user_id() ) ) {
			return do_shortcode( $content );
		}
		return '';
	}

	/**
	 * [bdaci_logout_link text="Sign out"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_logout( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'text' => __( 'Sign out', 'bdaci-portal-access' ) ), $atts, 'bdaci_logout_link' );
		return '<a class="bdaci-logout" href="' . esc_url( wp_logout_url( BDACI_PA_Login::url( array( 'msg' => 'signedout' ) ) ) ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}

	/**
	 * [bdaci_login_link text="Board Portal"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_login( $atts ) {
		$atts = shortcode_atts( array( 'text' => __( 'Board Portal sign in', 'bdaci-portal-access' ) ), $atts, 'bdaci_login_link' );
		$url  = is_user_logged_in() ? BDACI_PA_Settings::portal_home_url() : BDACI_PA_Login::url();
		return '<a class="bdaci-login" href="' . esc_url( $url ) . '">' . esc_html( $atts['text'] ) . '</a>';
	}
}
