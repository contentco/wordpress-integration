<?php 
	class Bolt_Admin {
	const BASE_SLUG = 'bolt-app-admin';

	protected static function get_url( $params = array() ) {
		$url = admin_url( 'users.php' );
		$params = array( 'page' => self::BASE_SLUG ) + wp_parse_args( $params );
		return add_query_arg( urlencode_deep( $params ), $url );
	}

	/**
	 * Get the current page action.
	 *
	 * @return string One of 'add', 'edit', 'delete', or '' for default (list)
	 */
	protected static function current_action() {
		return isset( $_GET['action'] ) ? $_GET['action'] : '';
	}

	/**
	 * Register the admin page
	 */
	public static function register() {
		/**
		 * Include anything we need that relies on admin classes/functions
		 */

		$hook = add_users_page(
			// Page title
			__( 'Registered OAuth Bolt Applications', 'rest_oauth1' ),

			// Menu title
			_x( 'Bolt Media Apps', 'menu title', 'rest_oauth1' ),

			// Capability
			'list_users',

			// Menu slug
			self::BASE_SLUG,

			// Callback
			array( get_class(), 'dispatch' )
		);

		add_action( 'load-' . $hook, array( get_class(), 'load' ) );
	}

	/**
	 * Load data for our page.
	 */
	public static function load() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
				return self::render_edit_page();

			case 'delete':
				return self::handle_delete();

			case 'regenerate':
				return self::handle_regenerate();

			default:
				global $wp_list_table;
				$wp_list_table = new WP_Bolt_ListTable();
				$wp_list_table->prepare_items();
				return;
		}
	}

	public static function dispatch() {
		switch ( self::current_action() ) {
			case 'add':
			case 'edit':
			case 'delete':
				return;

			default:
				return self::render();
		}
	}

	public static function render() {
		global $wp_list_table;
		?>
		<div class="wrap">
			<h2>
				<?php
				esc_html_e( 'Registered Bolt Applications');

				if ( current_user_can( 'create_users' ) ): ?>
					<a href="<?php echo esc_url( self::get_url( 'action=add' ) ) ?>"
						class="add-new-h2"><?php echo esc_html_x( 'Add New', 'application', 'rest_oauth1' ); ?></a>
				<?php
				endif;
				?>
			</h2>
			
			<?php $wp_list_table->views(); ?>

			<form action="" method="get">

				<?php $wp_list_table->search_box( __( 'Search Applications', 'rest_oauth1' ), 'rest_oauth1' ); ?>
				<?php $wp_list_table->display(); ?>

			</form>

			<br class="clear" />

		</div>
		<?php
	}

	protected static function validate_parameters( $params ) {

		$valid = array();

		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_name', __( 'Consumer name is required', 'rest_oauth1' ) );
		}
		$valid['name'] = wp_filter_post_kses( $params['name'] );

		if ( empty( $params['description'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_description', __( 'Consumer description is required', 'rest_oauth1' ) );
		}
		$valid['description'] = wp_filter_post_kses( $params['description'] );

		if ( empty( $params['callback'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_description', __( 'Consumer callback is required and must be a valid URL.', 'rest_oauth1' ) );
		}
		if ( ! empty( $params['callback'] ) ) {
			$valid['callback'] = $params['callback'];
		}

		if ( empty( $params['web_hook'] ) ) {
			return new WP_Error( 'rest_oauth1_missing_description', __( 'Web Hook URL is required and must be a valid URL.', 'rest_oauth1' ) );
		}
		if ( ! empty( $params['web_hook'] ) ) {
			$valid['web_hook'] = $params['web_hook'];
		}
		return $valid;
	}

	/**
	 * Handle submission of the add page
	 *
	 * @return array|null List of errors. Issues a redirect and exits on success.
	 */
	protected static function handle_edit_submit( $consumer ) {
		$messages = array();
		if ( empty( $consumer ) ) {
			$did_action = 'add';
			check_admin_referer( 'rest-oauth1-add' );
		}
		else {
			$did_action = 'edit';
			check_admin_referer( 'rest-oauth1-edit-' . $consumer->ID );
		}

		// Check that the parameters are correct first
		$params = self::validate_parameters( wp_unslash( $_POST ) );

		if ( is_wp_error( $params ) ) {
			$messages[] = $params->get_error_message();
			return $messages;
		}

		if ( empty( $consumer ) ) {
			$authenticator = new WP_REST_OAuth1();

			// Create the consumer
			$data = array(
				'name' => $params['name'],
				'description' => $params['description'],
				'meta' => array(
					'callback' => $params['callback'],
					'web_hook' => $params['web_hook']
				),
			);
			$consumer = $result = WP_BOLT_REST_Client::create( $data );
		}
		else {
			// Update the existing consumer post
			$data = array(
				'name' => $params['name'],
				'description' => $params['description'],
				'meta' => array(
					'callback' => $params['callback'],
					'web_hook' => $params['web_hook']
				),
			);

			$result = $consumer->update( $data );
		}

		if ( is_wp_error( $result ) ) {
			$messages[] = $result->get_error_message();

			return $messages;
		}

		// Success, redirect to alias page
		$location = self::get_url(
			array(
				'action'     => 'edit',
				'id'         => $consumer->ID,
				'did_action' => $did_action,
			)
		);
		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * Output alias editing page
	 */
	public static function render_edit_page() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( __( 'You do not have permission to access this page.', 'bolt_oauth1' ) );
		}
		// Are we editing?
		$consumer = null;
		$form_action = self::get_url('action=add');
		if ( ! empty( $_REQUEST['id'] ) ) {
			$id = absint( $_REQUEST['id'] );
			$consumer = WP_BOLT_REST_Client::get( $id );
			if ( is_wp_error( $consumer ) || empty( $consumer ) ) {
				wp_die( __( 'Invalid consumer ID.', 'rest_oauth1' ) );
			}

			$form_action = self::get_url( array( 'action' => 'edit', 'id' => $id ) );
			$regenerate_action = self::get_url( array( 'action' => 'regenerate', 'id' => $id ) );
		}

		// Handle form submission
		$messages = array();
		if ( ! empty( $_POST['submit'] ) ) {
			$messages = self::handle_edit_submit( $consumer );
		}

		if ( ! empty( $_GET['did_action'] ) ) {
			switch ( $_GET['did_action'] ) {
				case 'edit':
					$messages[] = __( 'Updated application.', 'rest_oauth1' );
					break;

				case 'regenerate':
					$messages[] = __( 'Regenerated secret.', 'rest_oauth1' );
					break;

				default:
					$messages[] = __( 'Successfully created application.', 'rest_oauth1' );
					break;
			}
		}

		$data = array();

		if ( empty( $consumer ) || ! empty( $_POST['_wpnonce'] ) ) {
			foreach ( array( 'name', 'description', 'callback','web_hook' ) as $key ) {
				$data[ $key ] = empty( $_POST[ $key ] ) ? '' : wp_unslash( $_POST[ $key ] );
			}
		}
		else {
			$data['name'] = $consumer->post_title;
			$data['description'] = $consumer->post_content;
			$data['callback'] = $consumer->callback;
			$data['web_hook'] = $consumer->web_hook;
		}
		// Header time!
		global $title, $parent_file, $submenu_file;
		$title = $consumer ? __( 'Edit Bolt Application') : __( 'Add Bolt Application');
		$parent_file = 'users.php';
		$submenu_file = self::BASE_SLUG;

		include( ABSPATH . 'wp-admin/admin-header.php' );
		?>

	<div class="wrap">
		<h2 id="edit-site"><?php echo esc_html( $title ) ?></h2>

		<?php
		if ( ! empty( $messages ) ) {
			foreach ( $messages as $msg )
				echo '<div id="message" class="updated"><p>' . esc_html( $msg ) . '</p></div>';
		}
		?>

		<form method="post" action="<?php echo esc_url( $form_action ) ?>">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="oauth-name">
							<?php echo esc_html_x( 'Bolt Consumer Name', 'field name', 'rest_oauth1' ) ?>
						</label>
					</th>
					<td>
						<input type="text" class="regular-text"
							name="name" id="oauth-name"
							value="<?php echo esc_attr( $data['name'] ) ?>" />
						<p class="description"><?php esc_html_e( 'This is shown to users during authorization and in their profile.', 'rest_oauth1' ) ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oauth-description"><?php echo esc_html_x( 'Description', 'field name', 'rest_oauth1' ) ?></label>
					</th>
					<td>
						<textarea class="regular-text" name="description" id="oauth-description"
							cols="30" rows="5" style="width: 500px"><?php echo esc_textarea( $data['description'] ) ?></textarea>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oauth-callback"><?php echo esc_html_x( 'Callback', 'field name', 'rest_oauth1' ) ?></label>
					</th>
					<td>
						<input type="text" class="regular-text"
							name="callback" id="oauth-callback"
							value="<?php echo esc_attr( $data['callback'] ) ?>" />

						<input type="hidden" class="regular-text"
							name="web_hook" id="oauth-web-hook"
							value="<?php echo esc_attr( $data['web_hook'] ) ?>" />
					</td>
				</tr>
			</table>

			<?php
			if ( empty( $consumer ) ) {
				wp_nonce_field( 'rest-oauth1-add' );
				submit_button( __( 'Add Consumer', 'rest_oauth1' ) );
			}
			else {
				echo '<input type="hidden" name="id" value="' . esc_attr( $consumer->ID ) . '" />';
				wp_nonce_field( 'rest-oauth1-edit-' . $consumer->ID );
				submit_button( __( 'Save Consumer', 'rest_oauth1' ) );
			}
			?>
		</form>

		<?php if ( ! empty( $consumer ) ): ?>
			<?php 
				$callback_url_arr = parse_url($consumer->callback);
				$key = $callback_url_arr['scheme'].'://'.$callback_url_arr['host'];
				// var_dump($callback_url_arr);
				// echo '<hr/>';
				$web_hook_arr = array(
					'http://127.0.0.1' => 'http://127.0.0.1:8000/api/v1',
					'http://dev.boltmedia.co' => 'http://bolt-dev-2.ap-southeast-1.elasticbeanstalk.com/api/v1',
					'https://staging.boltmedia.co/' => 'https://staging-api.boltmedia.co/api/v1',
					'https://app.boltmedia.co/' => 'https://api.boltmedia.co/api/v1',					
				);
				//echo $key;echo "<hr/>";
				//$key = $consumer->callback;
				$consumer->web_hook = $web_hook_arr[$key].'/wp-webhook/';
				echo $consumer->web_hook;
			 ?>
			<!-- send to bolt platform -->
			<form method="post" action="<?= $consumer->web_hook ?>">
				<h3><?php esc_html_e( 'Send authorization', 'rest_oauth1' ) ?></h3>

				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Client Key', 'rest_oauth1' ) ?>
						</th>
						<td>
							<input type="text" class="regular-text" name="client_key" id="oauth-name" value="<?= $consumer->key; ?>" readonly>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Client Secret', 'rest_oauth1' ) ?>
						</th>
						<td>
							<input type="text" class="regular-text" name="secret_key" id="oauth-name" value="<?= $consumer->secret; ?>" readonly>
						</td>
						<input type="hidden" name="name" value="<?= site_url(); ?>">
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">Get Authorize</button>
				</p>
			</form>
			<!-- end of sending -->
			<form method="post" action="<?php echo esc_url( $regenerate_action ) ?>">
				<h3><?php esc_html_e( 'Bolt OAuth Credentials', 'rest_oauth1' ) ?></h3>

				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Client Key', 'rest_oauth1' ) ?>
						</th>
						<td>
							<code><?php echo esc_html( $consumer->key ) ?></code>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Client Secret', 'rest_oauth1' ) ?>
						</th>
						<td>
							<code><?php echo esc_html( $consumer->secret ) ?></code>
						</td>
					</tr>
				</table>

				<?php
				wp_nonce_field( 'rest-oauth1-regenerate:' . $consumer->ID );
				submit_button( __( 'Regenerate Secret', 'rest_oauth1' ), 'delete' );
				?>
			</form>
		<?php endif ?>
	</div>
	<?php
	}

	public static function handle_delete() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = $_GET['id'];
		check_admin_referer( 'rest-oauth1-delete:' . $id );

		if ( ! current_user_can( 'delete_post', $id ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?', 'rest_oauth1' ) . '</h1>' .
				'<p>' . __( 'You are not allowed to delete this application.', 'rest_oauth1' ) . '</p>',
				403
			);
		}

		$client = WP_BOLT_REST_Client::get( $id );
		if ( is_wp_error( $client ) ) {
			wp_die( $client );
			return;
		}

		if ( ! $client->delete() ) {
			$message = 'Invalid consumer ID';
			wp_die( $message );
			return;
		}

		wp_safe_redirect( self::get_url( 'deleted=1' ) );
		exit;
	}

	public static function handle_regenerate() {
		if ( empty( $_GET['id'] ) ) {
			return;
		}

		$id = $_GET['id'];
		check_admin_referer( 'rest-oauth1-regenerate:' . $id );

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die(
				'<h1>' . __( 'Cheatin&#8217; uh?', 'rest_oauth1' ) . '</h1>' .
				'<p>' . __( 'You are not allowed to edit this application.', 'rest_oauth1' ) . '</p>',
				403
			);
		}

		$client = WP_BOLT_REST_Client::get( $id );
		$client->regenerate_secret();

		wp_safe_redirect( self::get_url( array( 'action' => 'edit', 'id' => $id, 'did_action' => 'regenerate' ) ) );
		exit;
	}

}














