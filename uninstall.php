<?php
if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )
    exit();

require_once dirname( __FILE__ ).'/wpudisplayinstagram.php';

$wpu_display_instagram->uninstall();
