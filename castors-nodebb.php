<?php
/*
Plugin Name: Castors NobeBB
Plugin URI: https://les-castors.fr
Description: Synchronize Wordpress website with NodeBB forum. Uses "Les Castors" child of Astra theme.
Author: Marc Delpont
Version: 1.0.0
Author URI: https://arkabase.fr
License: GPLv2
Text Domain: castors-nodebb
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

define('NODEBB_API_USER_ID', 1);

require_once('nodebb.php');

add_action('init', ['Castors_NodeBB', 'init']);
add_action('admin_init', ['Castors_NodeBB', 'admin_init']);