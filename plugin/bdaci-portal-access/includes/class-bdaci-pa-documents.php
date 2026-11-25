<?php
/**
 * Private document storage. Files uploaded here live in a folder the web server refuses to serve
 * directly, and are streamed only to signed-in users who are allowed to see them.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Private documents.
 */
class BDACI_PA_Documents {

	const META_PRIVATE = '_bdaci_pa_private';

	/**
	 * Allowed upload types.
	 *
	 * @return array
	 */
	public static function mimes() {
		return array(
			'pdf'          => 'application/pdf',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'          => 'application/vnd.ms-excel',
			'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'          => 'application/vnd.ms-powerpoint',
			'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'txt'          => 'text/plain',
			'csv'          => 'text/csv',
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), -5 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'attachment_url' ), 10, 2 );
		add_shortcode( 'bdaci_document', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Private folder name (random, so it cannot be guessed on servers that ignore .htaccess).
	 *
	 * @return string
	 */
	public static function dir_name() {
		$name = get_option( 'bdaci_pa_private_dir' );
		if ( ! $name ) {
			$name = 'bdaci-portal-private-' . strtolower( wp_generate_password( 16, false, false ) );
			update_option( 'bdaci_pa_private_dir', $name, false );
		}
		return $name;
	}

	/**
	 * Absolute path of the private folder.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::dir_name();
	}

	/**
	 * Create the folder and its deny rules.
	 */
	public static function ensure_private_dir() {
		$dir = self::base_dir();
		wp_mkdir_p( $dir );
		$files = array(
			'.htaccess'  => "# BDACI Portal Access: never serve these files directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * upload_dir filter used only while our upload runs.
	 *
	 * @param array $dirs Upload dirs.
	 * @return array
	 */
	public static function private_upload_dir( $dirs ) {
		$sub            = '/' . self::dir_name() . '/' . gmdate( 'Y' );
		$dirs['subdir'] = $sub;
		$dirs['path']   = $dirs['basedir'] . $sub;
		$dirs['url']    = $dirs['baseurl'] . $sub;
		return $dirs;
	}

	/**
	 * Store an uploaded file privately.
	 *
	 * @param string $field   $_FILES key.
	 * @param string $title   Title.
	 * @param string $level   portal|board|committee|finance.
	 * @param int    $parent  Page to attach to (optional).
	 * @return int|WP_Error Attachment ID.
	 */
	public static function upload( $field, $title, $level, $parent = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		self::ensure_private_dir();
		$no_sizes = '__return_empty_array';
		add_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );
		add_filter( 'intermediate_image_sizes_advanced', $no_sizes );
		add_filter( 'fallback_intermediate_image_sizes', $no_sizes );
		add_filter( 'big_image_size_threshold', '__return_false' );

		$id = media_handle_upload(
			$field,
			(int) $parent,
			array( 'post_title' => $title ),
			array(
				'test_form' => false,
				'mimes'     => self::mimes(),
			)
		);

		remove_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );
		remove_filter( 'intermediate_image_sizes_advanced', $no_sizes );
		remove_filter( 'fallback_intermediate_image_sizes', $no_sizes );
		remove_filter( 'big_image_size_threshold', '__return_false' );

		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::META_PRIVATE, 1 );
		update_post_meta( $id, BDACI_PA_Access::META, self::clean_level( $level ) );
		return $id;
	}

	/**
	 * Store a file that is already on the server (used by the test-data tool) as a private document.
	 *
	 * @param string $path  File path (it is moved into the private folder).
	 * @param string $name  File name.
	 * @param string $title Title.
	 * @param string $level Access level.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function sideload( $path, $name, $title, $level ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		self::ensure_private_dir();
		add_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );
		$id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $path,
			),
			0,
			null,
			array( 'post_title' => $title )
		);
		remove_filter( 'upload_dir', array( __CLASS__, 'private_upload_dir' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, self::META_PRIVATE, 1 );
		update_post_meta( $id, BDACI_PA_Access::META, self::clean_level( $level ) );
		return $id;
	}

	/**
	 * Validate a document level: one of the levels, or several joined with "+".
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function clean_level( $level ) {
		$parts = array_values( array_intersect( BDACI_PA_Access::LEVELS, explode( '+', (string) $level ) ) );
		if ( ! $parts || in_array( 'portal', $parts, true ) ) {
			return 'portal';
		}
		return implode( '+', array_unique( $parts ) );
	}

	/**
	 * Private documents, newest first.
	 *
	 * @return WP_Post[]
	 */
	public static function all() {
		return get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 500,
				'meta_key'         => self::META_PRIVATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'bdaci_pa_all'     => true,
			)
		);
	}

	/**
	 * Link that streams a private document.
	 *
	 * @param int  $id       Attachment ID.
	 * @param bool $download Force download.
	 * @return string
	 */
	public static function link( $id, $download = false ) {
		$args = array( 'bdaci_doc' => (int) $id );
		if ( $download ) {
			$args['download'] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Point attachment URLs for private files at the streaming endpoint.
	 *
	 * @param string $url URL.
	 * @param int    $id  Attachment ID.
	 * @return string
	 */
	public static function attachment_url( $url, $id ) {
		return get_post_meta( $id, self::META_PRIVATE, true ) ? self::link( $id ) : $url;
	}

	/**
	 * Stream a private file after checking access.
	 */
	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['bdaci_doc'] ) ? absint( $_GET['bdaci_doc'] ) : 0;
		if ( ! $id ) {
			return;
		}
		nocache_headers();
		if ( ! get_post_meta( $id, self::META_PRIVATE, true ) || 'attachment' !== get_post_type( $id ) ) {
			status_header( 404 );
			exit;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( BDACI_PA_Login::url( array( 'redirect_to' => self::link( $id ) ) ) );
			exit;
		}
		if ( ! BDACI_PA_Sections::can_open( $id ) ) {
			BDACI_PA_Log::add( 'document_denied', wp_get_current_user()->user_email, get_current_user_id(), 'doc ' . $id );
			wp_die( esc_html__( 'Your account does not have access to this document.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}

		$file = get_attached_file( $id, true );
		$base = realpath( self::base_dir() );
		$real = $file ? realpath( $file ) : false;
		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) ) {
			status_header( 404 );
			exit;
		}

		/**
		 * A signed-in user opened a private document (used to track sign-off reading).
		 *
		 * @param int $id      Attachment ID.
		 * @param int $user_id User ID.
		 */
		do_action( 'bdaci_pa_document_opened', $id, get_current_user_id() );

		$type = wp_check_filetype( $real, self::mimes() );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$inline = empty( $_GET['download'] ) && in_array( $mime, array( 'application/pdf', 'image/jpeg', 'image/png', 'text/plain' ), true );
		$name   = sanitize_file_name( basename( $real ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $real ) );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		if ( 'application/pdf' !== $mime ) {
			// A sandbox policy would stop browsers' built-in PDF viewers, so it is applied to other types only.
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		}
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * [bdaci_document id="123" text="Agenda (PDF)"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'text' => '',
			),
			$atts,
			'bdaci_document'
		);
		$id   = absint( $atts['id'] );
		if ( ! $id || ! get_post_meta( $id, self::META_PRIVATE, true ) || ! BDACI_PA_Sections::can_open( $id ) ) {
			return '';
		}
		$text = $atts['text'] ? $atts['text'] : get_the_title( $id );
		return '<a class="bdaci-document" href="' . esc_url( self::link( $id ) ) . '" target="_blank" rel="noopener"' . ( class_exists( 'BDACI_PA_Portal' ) ? BDACI_PA_Portal::view_attrs( $id ) : '' ) . '>' . esc_html( $text ) . '</a>';
	}
}
