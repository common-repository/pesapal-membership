<?php
/*
Plugin Name: PesaPal Membership
Description: Collect Memberships on your WordPress site using PesaPal
Version: 1.0
Author: rixeo
Author URI: http://shumipress.com/
Plugin URI: http://shumipress.com/pesapalmembership
License: GPL2
License URI: license.txt
*/

define('PESAPAL_MEMBERSHIP_PLUGIN_BASENAME',plugin_basename(__FILE__));

define('PESAPAL_MEMBERSHIP_PLUGIN_URL', WP_PLUGIN_URL.'/'.dirname(plugin_basename(__FILE__)));
define('PESAPAL_MEMBERSHIP_PLUGIN_DIR', WP_PLUGIN_DIR.'/'.dirname(plugin_basename(__FILE__)));

define('PESAPAL_MEMBERSHIP_URL', PESAPAL_MEMBERSHIP_PLUGIN_URL.'/pesapal_membership');
define('PESAPAL_MEMBERSHIP_DIR', PESAPAL_MEMBERSHIP_PLUGIN_DIR.'/pesapal_membership');

define('PESAPAL_MEMBERSHIP_LIB_DIR', PESAPAL_MEMBERSHIP_DIR.'/lib/');

require_once(PESAPAL_MEMBERSHIP_DIR.'/pesapal_membership-init.php');

?>