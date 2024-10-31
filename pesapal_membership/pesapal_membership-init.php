<?php
if(!class_exists('PesaPal_Membership')) {
	
	class PesaPal_Membership{
	
		/**
		 * Table Name
		 *
		 * @since 1.0
		 *
		 * @var string
		 */
		public $table_name = '';
		
		
		/**
		 * PesaPal Live Post URL
		 *
		 * @since 1.0
		 *
		 * @var string
		 */
		public $post_url = 'https://www.pesapal.com/api/PostPesapalDirectOrderV4';
	
		/**
		 * PesaPal SandBox Post URL
		 *
		 * @since 1.0
		 *
		 * @var string
		 */
		public $test_post_url = 'http://demo.pesapal.com/api/PostPesapalDirectOrderV4';
		
		/**
		 * PesaPal Live Payment Status URL
		 *
		 * @since 1.0
		 *
		 * @var string
		 */
		public $status_request = 'https://www.pesapal.com/api/querypaymentstatus';
		
		/**
		 * PesaPal SandBox Payment Status URL
		 *
		 * @since 1.0
		 *
		 * @var string
		 */
		public $test_status_request = 'https://demo.pesapal.com/api/querypaymentstatus';
		
		/**
		 * Configures the plugin and future actions.
		 *
		 * @since 1.0
		 */
		public function __construct() {
			global $wpdb;
			
			$this->table_name = $wpdb->prefix.'pesapal_member';
			
			add_action('init', array(&$this, 'database'));;
			add_action('admin_menu', array(&$this, 'admin_menu'));
			add_filter('wp_authenticate_user', array(&$this, 'authenticate_user'), 1 );
			add_action('user_register', array(&$this, 'user_register'));
			
			//Registration form additional field
			add_action('register_form',array(&$this, 'register_form'));
			
			//Ajax function for callback from Pesapal. Since Pesapal cannot log in, we need to add the no priviledge action
			add_action("wp_ajax_nopriv_sp_verify_user", array(&$this,'verify_user'));
			add_action("wp_ajax_sp_verify_user", array(&$this,'verify_user') );
			
			add_action("wp_ajax_nopriv_pesapal_member_return", array(&$this,'callback'));
			add_action("wp_ajax_pesapal_member_return", array(&$this,'callback') );
			
			require_once(PESAPAL_MEMBERSHIP_LIB_DIR.'pesapal/OAuth.php');
			
		}
		
		/**
		 * Create the database that will be used. We want to store those ids 
		 * of registered users so that we can tell them to activate their accounts.
		 * 
		 */
		function database(){
			global $wpdb;
			$charset_collate = '';	
			if ( ! empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			if ( ! empty($wpdb->collate) )
				$charset_collate .= " COLLATE $wpdb->collate";
			$table_name = $this->table_name;
			if($wpdb->get_var("show tables like '$table_name'") != $table_name) {
				$sql =  "CREATE TABLE `$table_name` (
                `id` INT( 5 ) NOT NULL AUTO_INCREMENT,
                `userid` INT(11) NOT NULL,
				`transactionid` VARCHAR(50) NOT NULL,
                `date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				`paymentstatus` ENUM ('Pending', 'Paid', 'Canceled'),
				`amount` FLOAT NOT NULL,
				`active` INT(1) NOT NULL DEFAULT '0',
                UNIQUE (`userid`),
				UNIQUE (`transactionid`),
                PRIMARY KEY  (id)
                )";
				require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
				dbDelta($sql);
			}
		}
		
	
		
		/**
		 * Set up admin menu
		 *
		 */
		function admin_menu(){
			$user = wp_get_current_user();
			if ( current_user_can('manage_options') && current_user_can('edit_others_posts')){
				add_object_page( __('Membership', 'pesapal_membership'), __('Membership', 'pesapal_membership'), 'edit_others_posts', 'pesapal-members', '', PESAPAL_MEMBERSHIP_URL . '/img/icon.png');
				add_submenu_page('pesapal-members', __('Membership', 'pesapal_membership'), __('Membership', 'pesapal_membership'), 'edit_others_posts', 'pesapal-members', array(&$this,'settings_page'));
				add_submenu_page('pesapal-members', __('Users', 'pesapal_membership'), __('Users', 'pesapal_membership'), 'edit_others_posts', 'pesapal-members-users', array(&$this,'users_page'));
			}
		}
		
		/** 
		 * Settings page
		 *
		 */
		function settings_page(){
			wp_nonce_field( PESAPAL_MEMBERSHIP_PLUGIN_BASENAME, 'spmemb_noncename' );
			//Check if the current user has permission to access this page
			if (!current_user_can('manage_options')) {
				wp_die(__('You do not have sufficient permissions to access this page.','pesapal_membership'));
			}
			//Check if the for is posted and start saving
			if (! empty( $_POST ) && check_admin_referer('spmemb_settings','spmemb_noncename') ){
				$pesapal_membership_settings = get_option('pesapal_membership_settings');
				$setting = $_POST['spm'];
				foreach($setting as $key => $value) {
					$pesapal_membership_settings[$key] = $value;
				}
				update_option('pesapal_membership_settings', $pesapal_membership_settings);
			}
			$settings = get_option('pesapal_membership_settings');
			?>
			<div class="wrap">
				<h2><?php _e('Settings','pesapal_membership'); ?></h2>
				<form action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>" method="post">
					<?php wp_nonce_field('spmemb_settings','spmemb_noncename'); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e('Registration Cost','pesapal_membership') ?></th>
							<td>
								<p>
									<?php _e('KSHS','pesapal_membership'); ?> <input value="<?php echo $settings['pesapal']['cost']; ?>" size="10" name="spm[pesapal][cost]" type="text" />
								</p>
								
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e('PesaPal Settings','pesapal_membership') ?></th>
							<td>
								<p>
									<?php _e('PesaPal requires Full names and email or  phone number. To handle APN return requests, please set the url '); ?>
									<strong><?php echo admin_url("admin-ajax.php?action=pesapal_member_return"); ?></strong>
									<?php _e(' on your <a href="https://www.pesapal.com/merchantdashboard" target="_blank">pesapal</a> account settings'); ?>
								</p>
								
							</td>
						</tr>
						<tr>
							<th scope="row"><?php _e('PesaPal Merchant Credentials','pesapal_membership'); ?></th>
							<td>
								<p>
									<label><?php _e('Use PesaPal Sandbox','pesapal_membership'); ?><br />
									  <input value="checked" name="spm[pesapal][sandbox]" type="checkbox" <?php echo ($settings['pesapal']['sandbox'] == 'checked') ? "checked='checked'": ""; ?> />
									</label>
								</p>
								<p>
									<label><?php _e('Customer Key','pesapal_membership') ?><br />
									  <input value="<?php echo $settings['pesapal']['customer_key']; ?>" size="30" name="spm[pesapal][customer_key]" type="text" />
									</label>
								</p>
								<p>
									<label><?php _e('Customer Secret','pesapal_membership') ?><br />
										 <input value="<?php echo $settings['pesapal']['customer_secret']; ?>" size="30" name="spm[pesapal][customer_secret]" type="text" />
									</label>
								</p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<input class='button-primary' type='submit' value='<?php _e('Save Settings', 'pesapal_membership'); ?>'/>
					</p>
				</form>
			</div>
			<?php
		}
		
		/**
		 * Users Page
		 */
		function users_page(){
			wp_nonce_field( PESAPAL_MEMBERSHIP_PLUGIN_BASENAME, 'spmemb_noncename' );
			//Check if the current user has permission to access this page
			if (!current_user_can('manage_options')) {
				wp_die(__('You do not have sufficient permissions to access this page.','pesapal_membership'));
			}
			global $wpdb;
			$table_name = $this->table_name;
			$sql = "SELECT * FROM $table_name ORDER BY `id` DESC";
			$users = $wpdb->get_results($sql);
			?>
			<div class="wrap">
				<h2><?php _e('Members','pesapal_membership'); ?></h2>
				<?php if (is_array($users) && count($users) > 0) { ?>
					<table width="100%" border="0" class="widefat">
						<thead>
							<tr>
								<th width="1%" align="left" scope="col">&nbsp;</th>
								<th width="20%" align="left" scope="col"><?php _e("Transaction ID","pesapal_membership"); ?></th>
								<th width="30%" align="left" scope="col"><?php _e("Login ID","pesapal_membership"); ?></th>
								<th width="20%" align="left" scope="col"><?php _e("Status","pesapal_membership"); ?></th>
								<th width="20%" align="left" scope="col"><?php _e("Registration Date","pesapal_membership"); ?></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<th align="left" scope="col">&nbsp;</th>
								<th align="left" scope="col"><?php _e("Transaction ID","pesapal_membership"); ?></th>
								<th align="left" scope="col"><?php _e("Login ID","pesapal_membership"); ?></th>
								<th align="left" scope="col"><?php _e("Status","pesapal_membership"); ?></th>
								<th align="left" scope="col"><?php _e("Registration Date","pesapal_membership"); ?></th>
							</tr>
						</tfoot>
						<tbody>
							<?php
								$count = 1;
								$user_url = admin_url("user-edit.php?user_id=");
								foreach($users as $user => $u){
									$wp_user = get_userdata($u->userid);
									if($wp_user){
										$profile_url = $user_url.$u->userid;
										?>
										<tr>
											<td align="left"><a href="<?php echo esc_url($profile_url); ?>"><?php echo $count; ?></a></td>
											<td align="left"><?php echo $u->transactionid; ?></td>
											<td align="left"><a href="<?php echo esc_url($profile_url); ?>"><?php echo $wp_user->user_login ; ?></a></td>
											<td align="left"><?php echo $u->paymentstatus; ?></td>
											<td align="left"><?php echo date("d-m-Y", strtotime($u->date)); ?></td>
										</tr>
										<?php
										$count++;
									}else{
										//Delete the entry since user does not exist anymore
										$sql_delete = "DELETE FROM $table_name WHERE `id` = $u->id";
										$wpdb->query($sql_delete);
									}
								}
							?>
						</tbody>
					</table>
				<?php } else {
					_e('No members found','pesapal_membership');
				}?>
			</div>
			<?php
		}
		
		/**
		 * Add the cost on the registration form
		 */
		function register_form(){
			$settings = get_option('pesapal_membership_settings');
			$amount =  $settings['pesapal']['cost'];
			if(!empty($amount) && intval($amount) > 0){
				$amount = floatval($amount);
				?>
				<p>
					<label for="registration_cost">
						<?php _e("Registration Cost ","pesapal_membership"); ?> : <strong><?php echo $amount; ?></strong>
					</label>
				</p>
				<?php
			}
			
		}
		
		/**
		 * Check if the users account is active
		 */
		function auth_check($user){
			global $wpdb;
			$table_name = $this->table_name;
			$userid = $user->ID;
			$sql = "SELECT * FROM $table_name WHERE `userid` = $userid AND `paymentstatus` != 'Paid' AND `active` = 0 ";
			$user_membmer= $wpdb->get_row($sql);
			return (is_array($user_membmer) && count($user_membmer) > 0);
		}
		
		
		/**
		 * Handle the authentication
		 *
		 */
		function authenticate_user($user){
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			if($this->auth_check($user)){
				return new WP_Error( 'pesapal_member_disabled', 'Account has been disabled ' );
			}
			return $user;
		}
		
		function generate_transaction_id() {
			$order_id = date('yzB');
			$order_id = apply_filters( 'pesapal_membership_transaction', $order_id ); //Very important to make sure order numbers are unique and not sequential if filtering
			return $order_id;
		}
		
		/** 
		 * After registration we want to resirect them to pay
		 *
		 */
		function user_register($user_id, $password = "", $meta = array()){
			global $wpdb;
			$table_name = $this->table_name;
			$settings = get_option('pesapal_membership_settings');
			$amount =  $settings['pesapal']['cost'];
			$amount = floatval($amount);
			$transactionid = $this->generate_transaction_id();
			$sql = "INSERT INTO $table_name(`userid`,`transactionid`,`paymentstatus`,`amount`) VALUES($user_id,'$transactionid','Pending',$amount)";
			$wpdb->query($sql);
			update_user_meta( $user_id, 'sp_memb_transaction_id', $transactionid );
			
		}
		
		function update_user($transactionid, $status, $code = ""){
			global $wpdb;
			$table_name = $this->table_name;
			$active = 0;
			if($status === 'Paid'){
				$active = 1;
			}
			$sql = "SELECT `userid` FROM $table_name WHERE `transactionid` = '$transactionid' and `active` = 0";
			$userid = $wpdb->get_var($sql);
			if(!empty($userid) && intval($userid) > 0){
				$sql = "UPDATE $table_name SET `paymentstatus` = '$status' , `active` = $active WHERE `transactionid` = '$transactionid'";
				$wpdb->query($sql);
				
				$user = get_userdata( $userid );
				//Send mail
				$user_login = stripslashes( $user->user_login );
				$user_email = stripslashes( $user->user_email );
				
				if($status === 'Paid'){
					
					
					$plaintext_pass = wp_generate_password(12,false );
					
					wp_set_password( $plaintext_pass, $userid );

					// The blogname option is escaped with esc_html on the way into the database in sanitize_option
					// we want to reverse this for the plain text arena of emails.
					$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

					$message  = sprintf( __( 'Username: %s' ), $user_login) . "\r\n";
					$message .= sprintf( __( 'Password: %s' ), $plaintext_pass) . "\r\n";
					$message .= wp_login_url() . "\r\n";

					wp_mail( $user_email, sprintf( __( '[%s] Your username and password' ), $blogname ), $message );
				}else{
					wp_mail( $user_email, "Account Created", "Your account has been created but we are still waiting for PesaPal to confirm the transaction. Status : $status ");
				}
			}
			
		}
		
		function callback(){
			global $wpdb;

			$settings = get_option('pesapal_membership_settings');
			$consumer_key = $settings['pesapal']['customer_key'];
			$consumer_secret = $settings['pesapal']['customer_secret'];
			
			$transaction_tracking_id = $_REQUEST['pesapal_transaction_tracking_id'];
			$payment_notification = $_REQUEST['pesapal_notification_type'];
			$invoice = $_REQUEST['pesapal_merchant_reference'];
			
			$updated_status = 'Pending';
			
			$statusrequestAPI = $this->status_request;
			if($settings['pesapal']['sandbox'] == 'checked'){
				$statusrequestAPI = $this->test_status_request;
			}
			if($payment_notification=="CHANGE" && $transaction_tracking_id!=''){
				$token = $params = NULL;
				$consumer = new OAuthConsumer($consumer_key, $consumer_secret);
				$signature_method = new OAuthSignatureMethod_HMAC_SHA1();

				//get transaction status
				$request_status = OAuthRequest::from_consumer_and_token($consumer, $token, "GET", $statusrequestAPI, $params);
				$request_status->set_parameter("pesapal_merchant_reference", $invoice);
				$request_status->set_parameter("pesapal_transaction_tracking_id",$transaction_tracking_id);
				$request_status->sign_request($signature_method, $consumer, $token);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $request_status);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HEADER, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
				 if(defined('CURL_PROXY_REQUIRED')) if (CURL_PROXY_REQUIRED == 'True'){
					$proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
					curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
					curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
					curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
				}

				$response = curl_exec($ch);

				$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$raw_header  = substr($response, 0, $header_size - 4);
				$headerArray = explode("\r\n\r\n", $raw_header);
				$header      = $headerArray[count($headerArray) - 1];

				 //transaction status
				$elements = preg_split("/=/",substr($response, $header_size));
				$status = $elements[1];
				
				
				
				curl_close ($ch);
				switch ($status) {
					case 'PENDING':
						$updated_status = 'Pending';
						break;
					case 'COMPLETED':
						$updated_status = 'Paid';
						break;
					case 'FAILED':
						$updated_status = 'Canceled';
						break;
					default:
						$updated_status = 'Canceled';
						break;
				}
				
			}
			$this->update_user($invoice,$updated_status);
		}
		
		
		function verify_user(){
			global $wpdb;
			
		
			$settings = get_option('pesapal_membership_settings');
			$consumer_key = $settings['pesapal']['customer_key'];
			$consumer_secret = $settings['pesapal']['customer_secret'];
			
			$transaction_tracking_id = $_REQUEST['pesapal_transaction_tracking_id'];
			$payment_notification = $_REQUEST['pesapal_notification_type'];
			$invoice = $_REQUEST['pesapal_merchant_reference'];
			
			$updated_status = 'Pending';
			
			$statusrequestAPI = $this->status_request;
			if($settings['pesapal']['sandbox'] == 'checked'){
				$statusrequestAPI = $this->test_status_request;
			}
			if($payment_notification=="CHANGE" && $transaction_tracking_id!=''){
				$token = $params = NULL;
				$consumer = new OAuthConsumer($consumer_key, $consumer_secret);
				$signature_method = new OAuthSignatureMethod_HMAC_SHA1();

				//get transaction status
				$request_status = OAuthRequest::from_consumer_and_token($consumer, $token, "GET", $statusrequestAPI, $params);
				$request_status->set_parameter("pesapal_merchant_reference", $invoice);
				$request_status->set_parameter("pesapal_transaction_tracking_id",$transaction_tracking_id);
				$request_status->sign_request($signature_method, $consumer, $token);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $request_status);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HEADER, 1);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
				 if(defined('CURL_PROXY_REQUIRED')) if (CURL_PROXY_REQUIRED == 'True'){
					$proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
					curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
					curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
					curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
				}

				$response = curl_exec($ch);

				$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
				$raw_header  = substr($response, 0, $header_size - 4);
				$headerArray = explode("\r\n\r\n", $raw_header);
				$header      = $headerArray[count($headerArray) - 1];

				 //transaction status
				$elements = preg_split("/=/",substr($response, $header_size));
				$status = $elements[1];

				curl_close ($ch);
				switch ($status) {
					case 'PENDING':
						$updated_status = 'Pending';
						break;
					case 'COMPLETED':
						$updated_status = 'Paid';
						break;
					case 'FAILED':
						$updated_status = 'Canceled';
						break;
					default:
						$updated_status = 'Canceled';
						break;
				}
				
			}
			$this->update_user($invoice,$updated_status);
			wp_redirect(wp_login_url());
		}

	}
}

if ( ! function_exists( 'wp_new_user_notification' ) ) :
	if ( ! is_admin() ) {
		function wp_new_user_notification( $user_id, $plaintext_pass = '' ) {
			/** Return early if no password is set */
			if ( empty( $plaintext_pass ) )
				return;
				
			$user = get_userdata( $user_id );
				
			$settings = get_option('pesapal_membership_settings');
			$invoice_id = esc_attr( get_the_author_meta( 'sp_memb_transaction_id', $user->ID ) );
			$return_path = admin_url("admin-ajax.php?action=sp_verify_user");;
			$amount =  $settings['pesapal']['cost'];
			
			$token = $params = NULL;
			$consumer_key = $settings['pesapal']['customer_key'];
			$consumer_secret = $settings['pesapal']['customer_secret'];
			$signature_method = new OAuthSignatureMethod_HMAC_SHA1();
			
			//get form details
			$desc = 'Your Order No.: '.$invoice_id;
			$type = 'MERCHANT';
			$reference = $invoice_id;
			$first_name = '';
			$fullnames = 
			$last_name = '';
			$email = stripslashes( $user->user_email );
			$username = stripslashes( $user->user_login ); //same as email
			$phonenumber = '';//leave blank
			$payment_method = '';//leave blank
			$code = '';//leave blank
			
			$callback_url = $return_path; //redirect url, the page that will handle the response from pesapal.
			$post_xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?><PesapalDirectOrderInfo xmlns:xsi=\"http://www.w3.org/2001/XMLSchemainstance\" xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\" Amount=\"".$amount."\" Description=\"".$desc."\" Code=\"".$code."\" Type=\"".$type."\" PaymentMethod=\"".$payment_method."\" Reference=\"".$reference."\" FirstName=\"".$first_name."\" LastName=\"".$last_name."\" Email=\"".$email."\" PhoneNumber=\"".$phonenumber."\" UserName=\"".$username."\" xmlns=\"http://www.pesapal.com\" />";
			$post_xml = htmlentities($post_xml);
			
			$consumer = new OAuthConsumer($consumer_key, $consumer_secret);
			
			$post_url = 'https://www.pesapal.com/api/PostPesapalDirectOrderV4';
		
			$test_post_url = 'http://demo.pesapal.com/api/PostPesapalDirectOrderV4';
		
			//post transaction to pesapal
			$post_url = $post_url;
			if($options['sandbox'] == 'checked'){
				$post_url = $test_post_url;
			}
			$iframe_src = OAuthRequest::from_consumer_and_token($consumer, $token, "GET", $post_url, $params);
			$iframe_src->set_parameter("oauth_callback", $callback_url);
			$iframe_src->set_parameter("pesapal_request_data", $post_xml);
			$iframe_src->sign_request($signature_method, $consumer, $token);
			
			$output = '<iframe src="'.$iframe_src.'" width="100%" height="620px"  scrolling="no" frameBorder="0" >';
			$output .= '</iframe>';
			die($output);
		}
	}
endif;


/**
 * Load plugin function during the WordPress init action
 *
 * @since 3.6.2
 *
 * @return void
 */
function pesapal_membership_action_init() {
	global $pesapal_membership;
	$pesapal_membership = new PesaPal_Membership();
}
add_action( 'init', 'pesapal_membership_action_init', 0 ); // load before widgets_init at 1
?>