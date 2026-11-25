<?php
/**
 * Admin screens: authorized users, documents, settings, activity log, and the page access box.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class BDACI_PA_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
		foreach ( array( 'member_add', 'member_import', 'member_action', 'member_roles', 'settings', 'doc_upload', 'doc_action', 'section_visibility' ) as $action ) {
			add_action( 'admin_post_bdaci_pa_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		$pending = BDACI_PA_Members::pending_count() + BDACI_PA_Requests::pending_count();
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_menu_page( 'BDACI Portal', 'BDACI Portal' . $badge, 'bdaci_manage_portal', 'bdaci-portal', array( __CLASS__, 'page_members' ), 'dashicons-lock', 70 );
		add_submenu_page( 'bdaci-portal', __( 'Authorized Users', 'bdaci-portal-access' ), __( 'Authorized Users', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal', array( __CLASS__, 'page_members' ) );
		BDACI_PA_Admin_Portal::menu();
		// The page-level Show/Hide list now lives on Sections & Uploads; keep the old screen reachable by URL.
		add_submenu_page( 'bdaci-portal', __( 'Portal Sections', 'bdaci-portal-access' ), __( 'Portal Sections', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-sections', array( __CLASS__, 'page_sections' ) );
		add_submenu_page( 'bdaci-portal', __( 'Activity Log', 'bdaci-portal-access' ), __( 'Activity Log', 'bdaci-portal-access' ), 'bdaci_manage_portal', 'bdaci-portal-log', array( __CLASS__, 'page_log' ) );
		add_submenu_page( 'bdaci-portal', __( 'Portal Settings', 'bdaci-portal-access' ), __( 'Settings', 'bdaci-portal-access' ), 'manage_options', 'bdaci-portal-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'bdaci-portal', __( 'Test data', 'bdaci-portal-access' ), __( 'Test data', 'bdaci-portal-access' ), 'manage_options', 'bdaci-portal-test-data', array( 'BDACI_PA_Demo', 'page' ) );
	}

	/**
	 * Remind admins to pick the portal page.
	 */
	public static function setup_notice() {
		if ( ! current_user_can( 'manage_options' ) || BDACI_PA_Settings::get( 'portal_root_page' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . wp_kses_post(
			sprintf(
				/* translators: %s: settings link */
				__( '<strong>BDACI Portal Access:</strong> choose the portal home page in %s so the portal pages are protected.', 'bdaci-portal-access' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=bdaci-portal-settings' ) ) . '">' . esc_html__( 'Portal Settings', 'bdaci-portal-access' ) . '</a>'
			)
		) . '</p></div>';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Check capability and nonce for an admin-post handler.
	 *
	 * @param string $action Nonce action.
	 * @param string $cap    Capability.
	 */
	private static function guard( $action, $cap = 'bdaci_manage_portal' ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'bdaci-portal-access' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to a screen with a notice.
	 *
	 * @param string $page   Page slug.
	 * @param string $notice Notice text.
	 * @param string $type   success|error.
	 */
	private static function back( $page, $notice, $type = 'success' ) {
		set_transient( 'bdaci_pa_notice_' . get_current_user_id(), array( $notice, $type ), 60 );
		$url = admin_url( 'admin.php?page=' . $page );
		wp_safe_redirect( BDACI_PA_Admin_Portal::from_front() ? BDACI_PA_Admin_Portal::to_front( $url ) : $url );
		exit;
	}

	/**
	 * Print and clear the pending notice.
	 */
	private static function print_notice() {
		$key    = 'bdaci_pa_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $notice[1] ? 'error' : 'success', esc_html( $notice[0] ) );
		}
	}

	/**
	 * Role checkboxes.
	 *
	 * @param string $name    Field name.
	 * @param array  $checked Checked keys.
	 */
	private static function role_boxes( $name, array $checked ) {
		foreach ( BDACI_PA_Roles::labels() as $key => $label ) {
			printf(
				'<label style="margin-right:12px"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $key ),
				checked( in_array( $key, $checked, true ), true, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Roles from POST.
	 *
	 * @param string $field Field.
	 * @return array
	 */
	private static function posted_roles( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called after guard().
		$raw = isset( $_POST[ $field ] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST[ $field ] ) ) : array();
		return BDACI_PA_Members::clean_roles( $raw );
	}

	/**
	 * Access level labels.
	 *
	 * @return array
	 */
	public static function level_labels() {
		return array(
			''          => __( 'Inherit (protected if under the portal page)', 'bdaci-portal-access' ),
			'public'    => __( 'Public', 'bdaci-portal-access' ),
			'portal'    => __( 'All portal users', 'bdaci-portal-access' ),
			'board'     => __( 'Board Members only', 'bdaci-portal-access' ),
			'committee' => __( 'Education Committee only', 'bdaci-portal-access' ),
			'finance'   => __( 'Finance Committee + Board Members', 'bdaci-portal-access' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Authorized users
	 * ------------------------------------------------------------------ */

	/**
	 * Authorized users screen.
	 */
	public static function page_members() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$rows   = BDACI_PA_Members::all();
		$labels = BDACI_PA_Roles::labels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Authorized Users', 'bdaci-portal-access' ); ?></h1>
			<?php self::print_notice(); ?>
			<?php echo BDACI_PA_Admin_Portal::requests_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts. ?>
			<p><?php esc_html_e( 'Only people on this list (plus site administrators) can sign in to the portal. Removing or revoking someone signs them out immediately.', 'bdaci-portal-access' ); ?></p>

			<h2><?php esc_html_e( 'Add a person', 'bdaci-portal-access' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bdaci_pa_member_add' ); ?>
				<input type="hidden" name="action" value="bdaci_pa_member_add">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="bdaci-email"><?php esc_html_e( 'Email', 'bdaci-portal-access' ); ?></label></th>
						<td><input type="email" id="bdaci-email" name="email" class="regular-text" required></td></tr>
					<tr><th scope="row"><label for="bdaci-name"><?php esc_html_e( 'Name', 'bdaci-portal-access' ); ?></label></th>
						<td><input type="text" id="bdaci-name" name="display_name" class="regular-text"></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Roles', 'bdaci-portal-access' ); ?></th>
						<td><?php self::role_boxes( 'roles', array( 'board' ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Invitation', 'bdaci-portal-access' ); ?></th>
						<td><label><input type="checkbox" name="invite" value="1" checked> <?php esc_html_e( 'Create their account now and email them a link to set a password', 'bdaci-portal-access' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave unticked if they will only use Microsoft 365 or Google sign-in; their account is created the first time they sign in.', 'bdaci-portal-access' ); ?></p></td></tr>
				</table>
				<?php submit_button( __( 'Add authorized user', 'bdaci-portal-access' ) ); ?>
			</form>

			<details style="margin-bottom:24px">
				<summary><strong><?php esc_html_e( 'Add many at once', 'bdaci-portal-access' ); ?></strong></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'bdaci_pa_member_import' ); ?>
					<input type="hidden" name="action" value="bdaci_pa_member_import">
					<p><?php esc_html_e( 'One person per line: email, name, roles. Roles are board, committee (Education), finance and/or admin separated by semicolons. Example:', 'bdaci-portal-access' ); ?><br><code>jane@example.org, Jane Doe, board;committee</code></p>
					<textarea name="lines" rows="6" class="large-text code"></textarea>
					<p><label><input type="checkbox" name="invite" value="1"> <?php esc_html_e( 'Create accounts and email set-password links', 'bdaci-portal-access' ); ?></label></p>
					<?php submit_button( __( 'Import', 'bdaci-portal-access' ), 'secondary' ); ?>
				</form>
			</details>

			<h2><?php esc_html_e( 'People', 'bdaci-portal-access' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Email', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Name', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Status', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Roles', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Last sign-in (UTC)', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'bdaci-portal-access' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nobody yet.', 'bdaci-portal-access' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php $roles = BDACI_PA_Members::clean_roles( $row->roles ); ?>
					<tr>
						<td><?php echo esc_html( $row->email ); ?><?php echo 'admin' !== $row->source ? '<br><small>' . esc_html( sprintf( /* translators: %s: provider */ __( 'requested via %s', 'bdaci-portal-access' ), $row->source ) ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $row->display_name ); ?></td>
						<td><strong><?php echo esc_html( ucfirst( $row->status ) ); ?></strong></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'bdaci_pa_member_roles_' . $row->id ); ?>
								<input type="hidden" name="action" value="bdaci_pa_member_roles">
								<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
								<?php self::role_boxes( 'roles', $roles ); ?>
								<button class="button button-small"><?php echo BDACI_PA_Members::PENDING === $row->status ? esc_html__( 'Approve with these roles', 'bdaci-portal-access' ) : esc_html__( 'Save roles', 'bdaci-portal-access' ); ?></button>
							</form>
						</td>
						<td><?php echo esc_html( $row->last_login ? $row->last_login : '—' ); ?></td>
						<td>
							<?php
							$actions = array();
							if ( BDACI_PA_Members::ACTIVE === $row->status ) {
								$actions['revoke'] = __( 'Revoke', 'bdaci-portal-access' );
								$actions['invite'] = __( 'Send set-password email', 'bdaci-portal-access' );
							} elseif ( BDACI_PA_Members::REVOKED === $row->status ) {
								$actions['activate'] = __( 'Restore', 'bdaci-portal-access' );
							} else {
								$actions['reject'] = __( 'Reject', 'bdaci-portal-access' );
							}
							$actions['delete'] = __( 'Remove from list', 'bdaci-portal-access' );
							if ( $row->user_id ) {
								echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=bdaci-portal-person&user=' . (int) $row->user_id ) ) . '">' . esc_html__( 'Directory details', 'bdaci-portal-access' ) . '</a> ';
							}
							foreach ( $actions as $key => $label ) :
								?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'bdaci_pa_member_action_' . $row->id ); ?>
									<input type="hidden" name="action" value="bdaci_pa_member_action">
									<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
									<input type="hidden" name="do" value="<?php echo esc_attr( $key ); ?>">
									<button class="button button-small<?php echo 'delete' === $key ? ' button-link-delete' : ''; ?>"><?php echo esc_html( $label ); ?></button>
								</form>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( implode( ' · ', $labels ) ); ?>: <?php esc_html_e( 'Portal Admins see everything and manage this list.', 'bdaci-portal-access' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Add one person.
	 */
	public static function handle_member_add() {
		self::guard( 'bdaci_pa_member_add' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$roles = self::posted_roles( 'roles' );
		if ( ! $roles ) {
			self::back( 'bdaci-portal', __( 'Pick at least one role.', 'bdaci-portal-access' ), 'error' );
		}
		$id = BDACI_PA_Members::add( $email, $name, $roles );
		if ( is_wp_error( $id ) ) {
			self::back( 'bdaci-portal', $id->get_error_message(), 'error' );
		}
		BDACI_PA_Log::add( 'member_added', $email, get_current_user_id(), implode( ',', $roles ) );
		if ( ! empty( $_POST['invite'] ) ) {
			$user_id = BDACI_PA_Members::create_account( $id, true );
			if ( is_wp_error( $user_id ) ) {
				self::back( 'bdaci-portal', $user_id->get_error_message(), 'error' );
			}
		}
		// phpcs:enable
		self::back( 'bdaci-portal', __( 'Added.', 'bdaci-portal-access' ) );
	}

	/**
	 * Bulk import.
	 */
	public static function handle_member_import() {
		self::guard( 'bdaci_pa_member_import' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checked the nonce.
		$lines  = isset( $_POST['lines'] ) ? explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['lines'] ) ) ) : array();
		$invite = ! empty( $_POST['invite'] );
		// phpcs:enable
		$added  = 0;
		$failed = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', str_getcsv( $line ) );
			$roles = BDACI_PA_Members::clean_roles( explode( ';', strtolower( $parts[2] ?? 'board' ) ) );
			$id    = BDACI_PA_Members::add( $parts[0] ?? '', $parts[1] ?? '', $roles ? $roles : array( 'board' ) );
			if ( is_wp_error( $id ) ) {
				$failed[] = $parts[0] ?? $line;
				continue;
			}
			++$added;
			BDACI_PA_Log::add( 'member_added', $parts[0], get_current_user_id(), 'import' );
			if ( $invite ) {
				BDACI_PA_Members::create_account( $id, true );
			}
		}
		/* translators: %d: count */
		$msg = sprintf( __( 'Imported %d.', 'bdaci-portal-access' ), $added );
		if ( $failed ) {
			/* translators: %s: list of emails */
			$msg .= ' ' . sprintf( __( 'Skipped (invalid or already listed): %s', 'bdaci-portal-access' ), implode( ', ', $failed ) );
		}
		self::back( 'bdaci-portal', $msg, $failed ? 'error' : 'success' );
	}

	/**
	 * Change roles (and approve a pending request).
	 */
	public static function handle_member_roles() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard() below with the per-row action.
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'bdaci_pa_member_roles_' . $id );
		$row   = BDACI_PA_Members::get( $id );
		$roles = self::posted_roles( 'roles' );
		if ( ! $row ) {
			self::back( 'bdaci-portal', __( 'Entry not found.', 'bdaci-portal-access' ), 'error' );
		}
		if ( ! $roles ) {
			self::back( 'bdaci-portal', __( 'Pick at least one role.', 'bdaci-portal-access' ), 'error' );
		}
		$fields = array( 'roles' => $roles );
		if ( BDACI_PA_Members::PENDING === $row->status ) {
			$fields['status'] = BDACI_PA_Members::ACTIVE;
		}
		BDACI_PA_Members::update( $id, $fields );
		if ( isset( $fields['status'] ) ) {
			BDACI_PA_Log::add( 'member_approved', $row->email, get_current_user_id(), implode( ',', $roles ) );
			self::email_approved( $row->email );
			self::back( 'bdaci-portal', __( 'Approved. They have been emailed that they can now sign in.', 'bdaci-portal-access' ) );
		}
		BDACI_PA_Log::add( 'member_roles_changed', $row->email, get_current_user_id(), implode( ',', $roles ) );
		self::back( 'bdaci-portal', __( 'Roles saved.', 'bdaci-portal-access' ) );
	}

	/**
	 * Revoke / restore / reject / delete / invite.
	 */
	public static function handle_member_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard() with the per-row action.
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'bdaci_pa_member_action_' . $id );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		// phpcs:enable
		$row = BDACI_PA_Members::get( $id );
		if ( ! $row ) {
			self::back( 'bdaci-portal', __( 'Entry not found.', 'bdaci-portal-access' ), 'error' );
		}
		if ( (int) $row->user_id === get_current_user_id() && in_array( $do, array( 'revoke', 'reject', 'delete' ), true ) && ! current_user_can( 'manage_options' ) ) {
			self::back( 'bdaci-portal', __( 'You cannot remove your own access.', 'bdaci-portal-access' ), 'error' );
		}
		switch ( $do ) {
			case 'revoke':
			case 'reject':
				BDACI_PA_Members::update( $id, array( 'status' => BDACI_PA_Members::REVOKED ) );
				BDACI_PA_Log::add( 'member_' . $do . 'ed', $row->email, get_current_user_id() );
				self::back( 'bdaci-portal', __( 'Access revoked and any open sessions signed out.', 'bdaci-portal-access' ) );
				break;
			case 'activate':
				BDACI_PA_Members::update( $id, array( 'status' => BDACI_PA_Members::ACTIVE ) );
				BDACI_PA_Log::add( 'member_restored', $row->email, get_current_user_id() );
				self::back( 'bdaci-portal', __( 'Access restored.', 'bdaci-portal-access' ) );
				break;
			case 'delete':
				BDACI_PA_Members::delete( $id );
				BDACI_PA_Log::add( 'member_removed', $row->email, get_current_user_id() );
				self::back( 'bdaci-portal', __( 'Removed from the list. Their WordPress account (if any) is kept without portal access.', 'bdaci-portal-access' ) );
				break;
			case 'invite':
				$result = BDACI_PA_Members::create_account( $id, true );
				if ( is_wp_error( $result ) ) {
					self::back( 'bdaci-portal', $result->get_error_message(), 'error' );
				}
				BDACI_PA_Log::add( 'member_invited', $row->email, get_current_user_id() );
				self::back( 'bdaci-portal', __( 'Set-password email sent.', 'bdaci-portal-access' ) );
				break;
		}
		self::back( 'bdaci-portal', __( 'Nothing changed.', 'bdaci-portal-access' ), 'error' );
	}

	/**
	 * Tell someone their request was approved.
	 *
	 * @param string $email Email.
	 */
	private static function email_approved( $email ) {
		$portal = BDACI_PA_Settings::get( 'portal_name' );
		wp_mail(
			$email,
			/* translators: %s: portal name */
			sprintf( __( 'Your %s access is approved', 'bdaci-portal-access' ), $portal ),
			/* translators: 1: portal name, 2: URL */
			sprintf( __( "Your access to the %1\$s has been approved. Sign in here:\n\n%2\$s\n", 'bdaci-portal-access' ), $portal, BDACI_PA_Login::url() )
		);
	}

	/* ---------------------------------------------------------------------
	 * Documents
	 * ------------------------------------------------------------------ */

	/**
	 * Documents screen.
	 */
	public static function page_docs() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$docs   = BDACI_PA_Documents::all();
		$levels = self::level_labels();
		unset( $levels[''], $levels['public'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portal Documents', 'bdaci-portal-access' ); ?></h1>
			<?php self::print_notice(); ?>
			<p><?php esc_html_e( 'Files uploaded here are stored in a private folder and only open for signed-in users with access. Files uploaded through the normal Media Library are public to anyone who has the link, so upload agendas, minutes and reports here instead.', 'bdaci-portal-access' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bdaci_pa_doc_upload' ); ?>
				<input type="hidden" name="action" value="bdaci_pa_doc_upload">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="bdaci-file"><?php esc_html_e( 'Files', 'bdaci-portal-access' ); ?></label></th>
						<td>
							<div class="bdaci-dropzone" id="bdaci-dropzone" tabindex="0" role="button" aria-describedby="bdaci-dz-help">
								<span class="dashicons dashicons-upload" aria-hidden="true"></span>
								<strong><?php esc_html_e( 'Drag files here, or paste them', 'bdaci-portal-access' ); ?></strong>
								<span id="bdaci-dz-help" class="description">
									<?php
									echo wp_kses(
										__( 'Copy a file and press <kbd>Ctrl</kbd>+<kbd>V</kbd> (<kbd>⌘</kbd>+<kbd>V</kbd> on a Mac) anywhere on this page, or click here to browse. PDF, Word, Excel, PowerPoint, JPG, PNG, TXT or CSV.', 'bdaci-portal-access' ),
										array( 'kbd' => array() )
									);
									?>
								</span>
								<input type="file" id="bdaci-file" name="bdaci_file[]" multiple required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.txt,.csv">
							</div>
							<ul class="bdaci-queue" id="bdaci-queue" aria-live="polite"></ul>
							<p class="description"><?php echo esc_html( sprintf( /* translators: %s: max upload size */ __( 'Maximum size per file on this server: %s.', 'bdaci-portal-access' ), size_format( wp_max_upload_size() ) ) ); ?></p>
						</td></tr>
					<tr><th scope="row"><label for="bdaci-title"><?php esc_html_e( 'Title', 'bdaci-portal-access' ); ?></label></th>
						<td><input type="text" id="bdaci-title" name="title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Board Meeting Agenda – January 2027', 'bdaci-portal-access' ); ?>">
						<p class="description"><?php esc_html_e( 'Used when you upload one file. With several files, each one is titled from its file name.', 'bdaci-portal-access' ); ?></p></td></tr>
					<tr><th scope="row"><label for="bdaci-level"><?php esc_html_e( 'Who can open it', 'bdaci-portal-access' ); ?></label></th>
						<td><select id="bdaci-level" name="level">
						<?php foreach ( $levels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
						</select></td></tr>
				</table>
				<?php submit_button( __( 'Upload', 'bdaci-portal-access' ), 'primary', 'submit', true, array( 'id' => 'bdaci-upload-btn' ) ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Title', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Access', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Link / shortcode to put on a portal page', 'bdaci-portal-access' ); ?></th>
					<th><?php esc_html_e( 'Uploaded', 'bdaci-portal-access' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $docs ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No documents yet.', 'bdaci-portal-access' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $docs as $doc ) : ?>
					<?php $level = (string) get_post_meta( $doc->ID, BDACI_PA_Access::META, true ); ?>
					<tr>
						<td><a href="<?php echo esc_url( BDACI_PA_Documents::link( $doc->ID ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $doc ) ); ?></a></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'bdaci_pa_doc_action_' . $doc->ID ); ?>
								<input type="hidden" name="action" value="bdaci_pa_doc_action">
								<input type="hidden" name="id" value="<?php echo (int) $doc->ID; ?>">
								<input type="hidden" name="do" value="level">
								<select name="level">
								<?php foreach ( $levels as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $level, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								</select>
								<button class="button button-small"><?php esc_html_e( 'Save', 'bdaci-portal-access' ); ?></button>
							</form>
						</td>
						<td><input type="text" readonly class="regular-text code" onfocus="this.select()" value="<?php echo esc_attr( '[bdaci_document id="' . $doc->ID . '"]' ); ?>"></td>
						<td><?php echo esc_html( get_the_date( 'M j, Y g:i A', $doc ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this file permanently?', 'bdaci-portal-access' ) ); ?>');">
								<?php wp_nonce_field( 'bdaci_pa_doc_action_' . $doc->ID ); ?>
								<input type="hidden" name="action" value="bdaci_pa_doc_action">
								<input type="hidden" name="id" value="<?php echo (int) $doc->ID; ?>">
								<input type="hidden" name="do" value="delete">
								<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'bdaci-portal-access' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Upload a private document.
	 */
	public static function handle_doc_upload() {
		self::guard( 'bdaci_pa_doc_upload' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- guard() checked the nonce; file fields are validated by media_handle_upload().
		$files = isset( $_FILES['bdaci_file'] ) && is_array( $_FILES['bdaci_file'] ) ? $_FILES['bdaci_file'] : array();
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : 'portal';
		$who   = isset( $_POST['who'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['who'] ) ) : array();
		$secs  = isset( $_POST['sections'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['sections'] ) ) : array();
		$date  = isset( $_POST['doc_date'] ) ? sanitize_text_field( wp_unslash( $_POST['doc_date'] ) ) : '';
		// phpcs:enable
		if ( $who ) {
			$level = BDACI_PA_Sections::who_to_level( $who );
		}
		$secs = array_values( array_intersect( $secs, array_keys( BDACI_PA_Sections::all() ) ) );
		if ( isset( $_POST['sections_sent'] ) && ! $secs ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::back( 'bdaci-portal-docs', __( 'Pick at least one section for the file.', 'bdaci-portal-access' ), 'error' );
		}
		$date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );

		// Normalize to a list of single-file arrays (the field accepts one or many files).
		$list = array();
		if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
			foreach ( array_keys( $files['name'] ) as $i ) {
				if ( '' === (string) $files['name'][ $i ] || UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
					continue;
				}
				$list[] = array(
					'name'     => $files['name'][ $i ],
					'type'     => $files['type'][ $i ],
					'tmp_name' => $files['tmp_name'][ $i ],
					'error'    => $files['error'][ $i ],
					'size'     => $files['size'][ $i ],
				);
			}
		} elseif ( ! empty( $files['name'] ) ) {
			$list[] = $files;
		}
		if ( ! $list ) {
			self::back( 'bdaci-portal-docs', __( 'Choose, drag in or paste a file to upload.', 'bdaci-portal-access' ), 'error' );
		}
		if ( count( $list ) > 20 ) {
			self::back( 'bdaci-portal-docs', __( 'Upload up to 20 files at a time.', 'bdaci-portal-access' ), 'error' );
		}

		$done   = 0;
		$failed = array();
		foreach ( $list as $file ) {
			$name       = sanitize_file_name( wp_unslash( $file['name'] ) );
			$this_title = ( 1 === count( $list ) && '' !== $title ) ? $title : sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) );
			$_FILES['bdaci_pa_single'] = $file; // media_handle_upload() reads one file from $_FILES by key.
			$id = BDACI_PA_Documents::upload( 'bdaci_pa_single', $this_title, $level );
			unset( $_FILES['bdaci_pa_single'] );
			if ( is_wp_error( $id ) ) {
				$failed[] = $name . ': ' . $id->get_error_message();
				continue;
			}
			++$done;
			if ( $secs ) {
				BDACI_PA_Sections::set_doc_sections( $id, $secs );
			}
			update_post_meta( $id, '_bdaci_doc_date', $date );
			BDACI_PA_Log::add( 'document_uploaded', '', get_current_user_id(), $this_title . ' (' . $level . ')' );
		}

		if ( $failed ) {
			$msg = $done
				/* translators: 1: number uploaded, 2: list of failures */
				? sprintf( __( 'Uploaded %1$d. Not uploaded: %2$s', 'bdaci-portal-access' ), $done, implode( '; ', $failed ) )
				/* translators: %s: list of failures */
				: sprintf( __( 'Not uploaded: %s', 'bdaci-portal-access' ), implode( '; ', $failed ) );
			self::back( 'bdaci-portal-docs', $msg, 'error' );
		}
		/* translators: %d: number of files */
		self::back( 'bdaci-portal-docs', 1 === $done ? __( 'Uploaded.', 'bdaci-portal-access' ) : sprintf( __( 'Uploaded %d files.', 'bdaci-portal-access' ), $done ) );
	}

	/**
	 * Load the drag-and-drop / paste uploader on the Documents screen only.
	 *
	 * @param string $hook Screen hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'bdaci-portal-docs' ) ) {
			return;
		}
		wp_enqueue_style( 'bdaci-pa-upload', BDACI_PA_URL . 'assets/admin-upload.css', array(), BDACI_PA_VERSION );
		wp_enqueue_script( 'bdaci-pa-upload', BDACI_PA_URL . 'assets/admin-upload.js', array(), BDACI_PA_VERSION, true );
		wp_localize_script(
			'bdaci-pa-upload',
			'bdaciUpload',
			array(
				'maxBytes'  => (int) wp_max_upload_size(),
				'remove'    => __( 'Remove', 'bdaci-portal-access' ),
				'ready'     => __( 'Ready to upload', 'bdaci-portal-access' ),
				'skipped'   => __( 'Not added (unsupported type or too large):', 'bdaci-portal-access' ),
				'nothing'   => __( 'Nothing to upload in the clipboard. Copy the file itself, then paste again.', 'bdaci-portal-access' ),
				'pasted'    => __( 'Pasted image', 'bdaci-portal-access' ),
				'uploadOne' => __( 'Upload', 'bdaci-portal-access' ),
				/* translators: %d: number of files */
				'uploadN'   => __( 'Upload %d files', 'bdaci-portal-access' ),
			)
		);
	}

	/**
	 * Change level or delete a private document.
	 */
	public static function handle_doc_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard() with the per-document action.
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'bdaci_pa_doc_action_' . $id );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( ! $id || ! get_post_meta( $id, BDACI_PA_Documents::META_PRIVATE, true ) ) {
			self::back( 'bdaci-portal-docs', __( 'Document not found.', 'bdaci-portal-access' ), 'error' );
		}
		if ( 'level' === $do ) {
			$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : 'portal';
			update_post_meta( $id, BDACI_PA_Access::META, in_array( $level, BDACI_PA_Access::LEVELS, true ) ? $level : 'portal' );
			self::back( 'bdaci-portal-docs', __( 'Access updated.', 'bdaci-portal-access' ) );
		}
		// phpcs:enable
		if ( 'delete' === $do ) {
			$title = get_the_title( $id );
			wp_delete_attachment( $id, true );
			BDACI_PA_Log::add( 'document_deleted', '', get_current_user_id(), $title );
			self::back( 'bdaci-portal-docs', __( 'Deleted.', 'bdaci-portal-access' ) );
		}
		self::back( 'bdaci-portal-docs', __( 'Nothing changed.', 'bdaci-portal-access' ), 'error' );
	}

	/* ---------------------------------------------------------------------
	 * Sections (show / hide)
	 * ------------------------------------------------------------------ */

	/**
	 * Set or clear the hidden flag on a page, and log it when it changes.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $hide    Hide it.
	 */
	public static function set_hidden( $post_id, $hide ) {
		$was = '1' === (string) get_post_meta( $post_id, BDACI_PA_Access::HIDDEN, true );
		if ( $hide === $was ) {
			return;
		}
		if ( $hide ) {
			update_post_meta( $post_id, BDACI_PA_Access::HIDDEN, '1' );
		} else {
			delete_post_meta( $post_id, BDACI_PA_Access::HIDDEN );
		}
		BDACI_PA_Access::flush_cache();
		BDACI_PA_Log::add( $hide ? 'section_hidden' : 'section_shown', '', get_current_user_id(), get_the_title( $post_id ) . ' (#' . $post_id . ')' );
	}

	/**
	 * Sections screen: every page under the portal home, its access, and a Show / Hide button.
	 */
	public static function page_sections() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$root = (int) BDACI_PA_Settings::get( 'portal_root_page' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Portal Sections', 'bdaci-portal-access' ) . '</h1>';
		self::print_notice();
		echo '<p>' . esc_html__( 'Hide a section until it\'s ready (for example the Strategic Plan). Hidden sections and every page under them disappear from menus and searches, and show "page not found" to everyone except Portal Admins. To add a section, create a new page under the portal home (or under Leadership or a committee page) and choose its access in the "Portal access" box.', 'bdaci-portal-access' ) . '</p>';
		if ( ! $root || ! get_post( $root ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Choose the portal home page in Settings first.', 'bdaci-portal-access' ) . '</p></div></div>';
			return;
		}
		$pages  = get_pages(
			array(
				'child_of'    => $root,
				'sort_column' => 'menu_order,post_title',
				'post_status' => array( 'publish', 'private', 'draft' ),
			)
		);
		$root_p = get_post( $root );
		array_unshift( $pages, $root_p );
		$depth  = array( $root => 0 );
		$labels = array(
			'portal'    => __( 'All portal users', 'bdaci-portal-access' ),
			'board'     => __( 'Board Members', 'bdaci-portal-access' ),
			'committee' => __( 'Education Committee', 'bdaci-portal-access' ),
			'finance'   => __( 'Finance Committee + Board', 'bdaci-portal-access' ),
		);
		echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>' . esc_html__( 'Section', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Who can see it', 'bdaci-portal-access' ) . '</th><th>' . esc_html__( 'Status', 'bdaci-portal-access' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $pages as $page ) {
			$id     = (int) $page->ID;
			$d      = $id === $root ? 0 : ( ( $depth[ (int) $page->post_parent ] ?? 0 ) + 1 );
			$depth[ $id ] = $d;
			$own    = '1' === (string) get_post_meta( $id, BDACI_PA_Access::HIDDEN, true );
			$level  = BDACI_PA_Access::level( $id );
			$raw    = (string) get_post_meta( $id, BDACI_PA_Access::META, true );
			$access = 'hidden' === $level ? ( $labels[ $raw ] ?? __( 'Inherited', 'bdaci-portal-access' ) ) : ( $labels[ $level ] ?? __( 'Public', 'bdaci-portal-access' ) );
			echo '<tr><td style="padding-left:' . esc_attr( 10 + 22 * $d ) . 'px">' . ( $d ? '<span aria-hidden="true">&#8627; </span>' : '' ) . '<strong>' . esc_html( get_the_title( $page ) ) . '</strong>' . ( $id === $root ? ' <em>(' . esc_html__( 'portal home', 'bdaci-portal-access' ) . ')</em>' : '' ) . ( 'publish' !== $page->post_status ? ' <em>(' . esc_html( $page->post_status ) . ')</em>' : '' ) . '</td>';
			echo '<td>' . esc_html( $access ) . '</td><td>';
			if ( $own ) {
				echo '<span class="dashicons dashicons-hidden"></span> ' . esc_html__( 'Hidden', 'bdaci-portal-access' );
			} elseif ( 'hidden' === $level ) {
				echo '<span class="dashicons dashicons-hidden"></span> ' . esc_html__( 'Hidden by parent', 'bdaci-portal-access' );
			} else {
				echo '<span class="dashicons dashicons-visibility"></span> ' . esc_html__( 'Visible', 'bdaci-portal-access' );
			}
			echo '</td><td style="white-space:nowrap">';
			if ( $id !== $root && ( $own || 'hidden' !== $level ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( 'bdaci_pa_section_visibility_' . $id );
				echo '<input type="hidden" name="action" value="bdaci_pa_section_visibility"><input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="hide" value="' . ( $own ? '0' : '1' ) . '">';
				submit_button( $own ? __( 'Show', 'bdaci-portal-access' ) : __( 'Hide', 'bdaci-portal-access' ), $own ? 'primary small' : 'secondary small', 'submit', false );
				echo '</form> ';
			}
			echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html__( 'Edit', 'bdaci-portal-access' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '">' . esc_html__( 'Add a section (new page)', 'bdaci-portal-access' ) . '</a></p></div>';
	}

	/**
	 * Show / hide one section.
	 */
	public static function handle_section_visibility() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in guard() with the per-page action.
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		self::guard( 'bdaci_pa_section_visibility_' . $id );
		$hide = ! empty( $_POST['hide'] );
		// phpcs:enable
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type ) {
			self::back( 'bdaci-portal-sections', __( 'Section not found.', 'bdaci-portal-access' ), 'error' );
		}
		self::set_hidden( $id, $hide );
		BDACI_PA_Portal::flush_screens();
		/* translators: %s: page title */
		self::back( isset( $_POST['back'] ) && 'sections' === $_POST['back'] ? 'bdaci-portal-sections' : 'bdaci-portal-docs', sprintf( $hide ? __( '"%s" is now hidden.', 'bdaci-portal-access' ) : __( '"%s" is now visible.', 'bdaci-portal-access' ), get_the_title( $id ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Activity log
	 * ------------------------------------------------------------------ */

	/**
	 * Log screen.
	 */
	public static function page_log() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) ) {
			return;
		}
		$rows = BDACI_PA_Log::latest( 300 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Activity Log', 'bdaci-portal-access' ); ?></h1>
			<p><?php esc_html_e( 'The latest 300 sign-in and access events. Times are UTC.', 'bdaci-portal-access' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Time', 'bdaci-portal-access' ); ?></th><th><?php esc_html_e( 'Event', 'bdaci-portal-access' ); ?></th><th><?php esc_html_e( 'Email', 'bdaci-portal-access' ); ?></th><th><?php esc_html_e( 'User', 'bdaci-portal-access' ); ?></th><th><?php esc_html_e( 'Details', 'bdaci-portal-access' ); ?></th><th>IP</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $u = $row->user_id ? get_userdata( (int) $row->user_id ) : null; ?>
					<tr>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><code><?php echo esc_html( $row->event ); ?></code></td>
						<td><?php echo esc_html( $row->email ); ?></td>
						<td><?php echo esc_html( $u ? $u->display_name : '' ); ?></td>
						<td><?php echo esc_html( $row->details ); ?></td>
						<td><?php echo esc_html( $row->ip ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * Settings screen.
	 */
	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = BDACI_PA_Settings::all();
		$pages = get_pages( array( 'post_status' => 'publish,private,draft' ) );
		$field = function ( $key, $label, $type = 'text', $help = '' ) use ( $s ) {
			echo '<tr><th scope="row"><label for="bdaci-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" id="bdaci-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( (int) $s[ $key ], 1, false ) . '> ' . esc_html( $help ) . '</label>';
				$help = '';
			} elseif ( 'textarea' === $type ) {
				echo '<textarea id="bdaci-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="3" class="large-text">' . esc_textarea( $s[ $key ] ) . '</textarea>';
			} elseif ( 'secret' === $type ) {
				$set = '' !== (string) $s[ $key ];
				echo '<input type="password" autocomplete="new-password" id="bdaci-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text" placeholder="' . esc_attr( $set ? __( 'Saved — leave blank to keep', 'bdaci-portal-access' ) : '' ) . '">';
				if ( $set ) {
					echo ' <label><input type="checkbox" name="' . esc_attr( $key ) . '_clear" value="1"> ' . esc_html__( 'Clear', 'bdaci-portal-access' ) . '</label>';
				}
			} else {
				echo '<input type="' . esc_attr( $type ) . '" id="bdaci-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '" class="regular-text">';
			}
			if ( $help ) {
				echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Portal Settings', 'bdaci-portal-access' ); ?></h1>
			<?php self::print_notice(); ?>
			<p><?php esc_html_e( 'Portal sign-in page:', 'bdaci-portal-access' ); ?> <a href="<?php echo esc_url( BDACI_PA_Login::url() ); ?>" target="_blank"><?php echo esc_html( BDACI_PA_Login::url() ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bdaci_pa_settings' ); ?>
				<input type="hidden" name="action" value="bdaci_pa_settings">

				<h2><?php esc_html_e( 'Portal', 'bdaci-portal-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="bdaci-root"><?php esc_html_e( 'Portal home page', 'bdaci-portal-access' ); ?></label></th><td>
						<select id="bdaci-root" name="portal_root_page">
							<option value="0"><?php esc_html_e( '— Choose a page —', 'bdaci-portal-access' ); ?></option>
							<?php foreach ( $pages as $page ) : ?>
								<option value="<?php echo (int) $page->ID; ?>" <?php selected( (int) $s['portal_root_page'], (int) $page->ID ); ?>><?php echo esc_html( str_repeat( '— ', count( get_post_ancestors( $page ) ) ) . $page->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'This page and every page beneath it are visible to authorized users only. People land here after signing in. Individual pages can be narrowed to Board or Committee members in the "Portal access" box when editing them.', 'bdaci-portal-access' ); ?></p>
					</td></tr>
					<?php
					$field( 'board_sees_committee', __( 'Education Committee pages', 'bdaci-portal-access' ), 'checkbox', __( 'Board Members can also see pages marked "Education Committee only" (BDACI decided: leave off; others use Request access)', 'bdaci-portal-access' ) );
					$field( 'allow_requests', __( 'Access requests', 'bdaci-portal-access' ), 'checkbox', __( 'When someone not on the list signs in with Microsoft or Google, record a request for an admin to approve', 'bdaci-portal-access' ) );
					$field( 'notify_email', __( 'Send requests to', 'bdaci-portal-access' ), 'email', __( 'Defaults to the site admin email.', 'bdaci-portal-access' ) );
					$field( 'portal_layout', __( 'Portal layout', 'bdaci-portal-access' ), 'checkbox', __( 'Show portal pages in the portal\'s own layout (top bar, side menu, dashboard, calendar, meetings). Untick to use the site theme instead.', 'bdaci-portal-access' ) );
					$field( 'portal_fonts', __( 'Portal fonts', 'bdaci-portal-access' ), 'checkbox', __( 'Load Ubuntu and Open Sans from Google Fonts', 'bdaci-portal-access' ) );
					$field( 'portal_keep_theme_css', __( 'Theme styles', 'bdaci-portal-access' ), 'checkbox', __( 'Keep the theme\'s and Elementor\'s stylesheets on portal-layout pages (leave off unless page content looks unstyled)', 'bdaci-portal-access' ) );
					?>
				</table>
				<p><?php esc_html_e( 'Portal pages:', 'bdaci-portal-access' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=bdaci-portal-docs' ) ); ?>"><?php esc_html_e( 'create or check them on Sections & Uploads', 'bdaci-portal-access' ); ?></a></p>

				<h2><?php esc_html_e( 'Sign-in page', 'bdaci-portal-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'portal_name', __( 'Portal name', 'bdaci-portal-access' ) );
					$field( 'welcome_text', __( 'Welcome text', 'bdaci-portal-access' ), 'textarea' );
					$field( 'logo_url', __( 'Logo URL', 'bdaci-portal-access' ), 'url', __( 'Paste the URL of the BDACI logo from the Media Library.', 'bdaci-portal-access' ) );
					$field( 'signin_image', __( 'Sign-in picture', 'bdaci-portal-access' ), 'url', __( 'Optional. Paste the URL of a photo from the Media Library to fill the left side of the sign-in page (it is tinted with the BDACI colours). Leave blank to use the built-in illustration.', 'bdaci-portal-access' ) );
					$field( 'signin_quote', __( 'Sign-in headline', 'bdaci-portal-access' ), 'textarea' );
					$field( 'signin_blurb', __( 'Sign-in tagline', 'bdaci-portal-access' ), 'textarea' );
					echo '<tr><th></th><td><a class="button" target="_blank" href="' . esc_url( BDACI_PA_Login::url( array( 'preview' => '1' ) ) ) . '">' . esc_html__( 'Preview the sign-in page', 'bdaci-portal-access' ) . '</a></td></tr>';
					$field( 'brand_color', __( 'Brand color', 'bdaci-portal-access' ), 'text', __( 'Hex color, e.g. #1d3f72', 'bdaci-portal-access' ) );
					$field( 'support_email', __( 'Help contact email', 'bdaci-portal-access' ), 'email' );
					?>
				</table>

				<h2><?php esc_html_e( 'Security', 'bdaci-portal-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$field( 'mfa_password', __( 'Email + password sign-in', 'bdaci-portal-access' ), 'checkbox', __( 'Require a 6-digit code sent by email after the password (recommended)', 'bdaci-portal-access' ) );
					$field( 'mfa_sso', __( 'Microsoft / Google sign-in', 'bdaci-portal-access' ), 'checkbox', __( 'Also require the emailed code (usually not needed: Microsoft and Google apply their own multi-factor checks)', 'bdaci-portal-access' ) );
					$field( 'min_password_length', __( 'Minimum password length', 'bdaci-portal-access' ), 'number' );
					?>
				</table>

				<h2><?php esc_html_e( 'Microsoft 365', 'bdaci-portal-access' ); ?></h2>
				<p><?php esc_html_e( 'Redirect URI to register in Microsoft Entra:', 'bdaci-portal-access' ); ?> <code><?php echo esc_html( BDACI_PA_Login::callback_url( 'microsoft' ) ); ?></code></p>
				<table class="form-table" role="presentation">
					<?php
					$field( 'ms_enabled', __( 'Enable', 'bdaci-portal-access' ), 'checkbox', __( 'Show "Sign in with Microsoft 365"', 'bdaci-portal-access' ) );
					$field( 'ms_client_id', __( 'Application (client) ID', 'bdaci-portal-access' ) );
					$field( 'ms_tenant', __( 'Directory (tenant) ID', 'bdaci-portal-access' ), 'text', __( 'BDACI\'s tenant ID (recommended). Use <code>organizations</code> to accept work accounts from any organization or <code>common</code> to also accept personal Microsoft accounts; see the setup guide for the extra step those need.', 'bdaci-portal-access' ) );
					$field( 'ms_client_secret', __( 'Client secret value', 'bdaci-portal-access' ), 'secret', defined( 'BDACI_PA_MS_CLIENT_SECRET' ) ? __( 'Currently set in wp-config.php, which takes priority.', 'bdaci-portal-access' ) : __( 'Or define BDACI_PA_MS_CLIENT_SECRET in wp-config.php.', 'bdaci-portal-access' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Google', 'bdaci-portal-access' ); ?></h2>
				<p><?php esc_html_e( 'Authorized redirect URI to register in Google Cloud:', 'bdaci-portal-access' ); ?> <code><?php echo esc_html( BDACI_PA_Login::callback_url( 'google' ) ); ?></code></p>
				<table class="form-table" role="presentation">
					<?php
					$field( 'google_enabled', __( 'Enable', 'bdaci-portal-access' ), 'checkbox', __( 'Show "Sign in with Google"', 'bdaci-portal-access' ) );
					$field( 'google_client_id', __( 'Client ID', 'bdaci-portal-access' ) );
					$field( 'google_client_secret', __( 'Client secret', 'bdaci-portal-access' ), 'secret', defined( 'BDACI_PA_GOOGLE_CLIENT_SECRET' ) ? __( 'Currently set in wp-config.php, which takes priority.', 'bdaci-portal-access' ) : __( 'Or define BDACI_PA_GOOGLE_CLIENT_SECRET in wp-config.php.', 'bdaci-portal-access' ) );
					$field( 'google_hosted_domain', __( 'Limit to Google Workspace domain', 'bdaci-portal-access' ), 'text', __( 'Optional, e.g. bdaci.com. Leave blank to allow any Google account on the authorized list (including Gmail).', 'bdaci-portal-access' ) );
					?>
				</table>

				<h2 id="bdaci-updates"><?php esc_html_e( 'Updates', 'bdaci-portal-access' ); ?></h2>
				<?php echo BDACI_PA_Updater::status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html(). ?>
				<p class="description"><?php esc_html_e( 'New versions are published on GitHub. They show on the Plugins screen like any other plugin update, with "Check for updates" and "View details".', 'bdaci-portal-access' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$field( 'update_auto', __( 'Automatic updates', 'bdaci-portal-access' ), 'checkbox', __( 'Install new versions automatically (WordPress checks about twice a day)', 'bdaci-portal-access' ) );
					$field( 'update_repo', __( 'GitHub repository', 'bdaci-portal-access' ), 'text', defined( 'BDACI_PA_UPDATE_REPO' ) ? __( 'Currently set in wp-config.php (BDACI_PA_UPDATE_REPO), which takes priority.', 'bdaci-portal-access' ) : __( 'owner/name, e.g. ericmdizon/bdaci-portal. Leave blank to turn update checks off.', 'bdaci-portal-access' ) );
					$field( 'update_token', __( 'GitHub access key', 'bdaci-portal-access' ), 'secret', defined( 'BDACI_PA_UPDATE_TOKEN' ) ? __( 'Currently set in wp-config.php, which takes priority.', 'bdaci-portal-access' ) : __( 'Needed because the repository is private. Use a fine-grained personal access token limited to this repository with Contents: Read-only. Or define BDACI_PA_UPDATE_TOKEN in wp-config.php.', 'bdaci-portal-access' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Uninstall', 'bdaci-portal-access' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php $field( 'remove_data_on_uninstall', __( 'Data', 'bdaci-portal-access' ), 'checkbox', __( 'Delete the authorized-user list, log, settings and portal roles when the plugin is deleted (private files are kept)', 'bdaci-portal-access' ) ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings() {
		self::guard( 'bdaci_pa_settings', 'manage_options' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checked the nonce; BDACI_PA_Settings::save() sanitizes every field.
		BDACI_PA_Settings::save( wp_unslash( $_POST ) );
		flush_rewrite_rules();
		BDACI_PA_Log::add( 'settings_saved', '', get_current_user_id() );
		self::back( 'bdaci-portal-settings', __( 'Settings saved.', 'bdaci-portal-access' ) );
	}

	/* ---------------------------------------------------------------------
	 * Per-page access box
	 * ------------------------------------------------------------------ */

	/**
	 * Register the box on pages and posts.
	 */
	public static function add_meta_box() {
		if ( ! current_user_can( 'bdaci_manage_portal' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( array( 'page', 'post' ) as $type ) {
			add_meta_box( 'bdaci-pa-access', __( 'Portal access', 'bdaci-portal-access' ), array( __CLASS__, 'render_meta_box' ), $type, 'side', 'high' );
		}
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'bdaci_pa_meta_' . $post->ID, '_bdaci_pa_meta_nonce' );
		$current   = (string) get_post_meta( $post->ID, BDACI_PA_Access::META, true );
		$effective = BDACI_PA_Access::level( $post->ID );
		echo '<select name="bdaci_access" style="width:100%">';
		foreach ( self::level_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $current, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">';
		if ( $effective ) {
			$labels = self::level_labels() + array( 'hidden' => __( 'Hidden (Portal Admins only)', 'bdaci-portal-access' ) );
			/* translators: %s: access level */
			echo esc_html( sprintf( __( 'Currently protected: %s.', 'bdaci-portal-access' ), $labels[ $effective ] ?? $effective ) );
		} else {
			esc_html_e( 'Currently public.', 'bdaci-portal-access' );
		}
		echo '</p>';
		$hidden = '1' === (string) get_post_meta( $post->ID, BDACI_PA_Access::HIDDEN, true );
		printf(
			'<p><label><input type="checkbox" name="bdaci_hidden" value="1" %1$s> %2$s</label></p><p class="description">%3$s</p>',
			checked( $hidden, true, false ),
			esc_html__( 'Hide this section', 'bdaci-portal-access' ),
			esc_html__( 'Only Portal Admins can see it and the pages under it. Everyone else gets "page not found" and it disappears from menus. Use it for sections that aren\'t ready yet.', 'bdaci-portal-access' )
		);
		if ( ! $hidden && 'hidden' === $effective ) {
			echo '<p class="description"><strong>' . esc_html__( 'Hidden because a parent page is hidden.', 'bdaci-portal-access' ) . '</strong></p>';
		}
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['_bdaci_pa_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bdaci_pa_meta_nonce'] ) ), 'bdaci_pa_meta_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! ( current_user_can( 'bdaci_manage_portal' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$level = isset( $_POST['bdaci_access'] ) ? sanitize_key( wp_unslash( $_POST['bdaci_access'] ) ) : '';
		if ( ! array_key_exists( $level, self::level_labels() ) ) {
			$level = '';
		}
		if ( '' === $level ) {
			delete_post_meta( $post_id, BDACI_PA_Access::META );
		} else {
			update_post_meta( $post_id, BDACI_PA_Access::META, $level );
		}
		self::set_hidden( $post_id, ! empty( $_POST['bdaci_hidden'] ) );
		BDACI_PA_Access::flush_cache();
	}

	/**
	 * Pages list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function column( $columns ) {
		$columns['bdaci_access'] = __( 'Portal access', 'bdaci-portal-access' );
		return $columns;
	}

	/**
	 * Pages list column value.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column_value( $column, $post_id ) {
		if ( 'bdaci_access' !== $column ) {
			return;
		}
		$level  = BDACI_PA_Access::level( $post_id );
		$labels = array(
			'portal'    => __( 'Portal users', 'bdaci-portal-access' ),
			'board'     => __( 'Board only', 'bdaci-portal-access' ),
			'committee' => __( 'Education Committee only', 'bdaci-portal-access' ),
			'finance'   => __( 'Finance + Board', 'bdaci-portal-access' ),
			'hidden'    => __( 'Hidden (admins only)', 'bdaci-portal-access' ),
		);
		echo $level ? '<span class="dashicons dashicons-lock"></span> ' . esc_html( $labels[ $level ] ?? $level ) : '—';
	}
}
