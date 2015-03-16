<?php

/*
Plugin Name: WPU Display Instagram
Description: Displays the latest image for an Instagram account
Version: 0.8
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram
{

    private $notices_categories = array(
        'updated',
        'update-nag',
        'error'
    );

    function __construct() {
        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        $this->options = array(
            'id' => 'wpu-display-instagram',
            'name' => 'Display Instagram',
            'post_type' => 'instagram_posts',
            'cache_duration' => 60
        );

        add_filter('wpu_options_tabs', array(&$this,
            'options_tabs'
        ) , 10, 3);
        add_filter('wpu_options_boxes', array(&$this,
            'options_boxes'
        ) , 12, 3);
        add_filter('wpu_options_fields', array(&$this,
            'options_fields'
        ) , 12, 3);
        add_filter('init', array(&$this,
            'init'
        ));
        add_action('init', array(&$this,
            'check_dependencies'
        ));
        add_action('init', array(&$this,
            'register_post_types'
        ));
        add_action('admin_init', array(&$this,
            'set_token'
        ));
        add_action('admin_init', array(&$this,
            'admin_import_postAction'
        ));
        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));

        // Display notices
        add_action('admin_notices', array(&$this,
            'admin_notices'
        ));
    }

    function init() {
        global $current_user;
        $this->transient_prefix = $this->options['id'] . $current_user->ID;
        $this->nonce_import = $this->options['id'] . '__nonce_import';

        // Instagram config
        $this->client_token = trim(get_option('wpu_get_instagram__client_token'));
        $this->client_id = trim(get_option('wpu_get_instagram__client_id'));
        $this->client_secret = trim(get_option('wpu_get_instagram__client_secret'));
        $this->user_id = trim(get_option('wpu_get_instagram__user_id'));

        // Admin URL
        $this->redirect_uri = admin_url('admin.php?page=' . $this->options['id']);

        // Transient
        $this->transient_id = $this->transient_prefix . '__json_instagram_' . $this->user_id;
        $this->transient_msg = $this->transient_prefix . '__messages';
    }

    function check_dependencies() {
        if (!is_admin()) {
            return;
        }
        include_once (ABSPATH . 'wp-admin/includes/plugin.php');

        // Check for Plugins activation
        $this->plugins = array(
            'wpuoptions' => array(
                'installed' => true,
                'path' => 'wpuoptions/wpuoptions.php',
                'message_url' => '<a target="_blank" href="https://github.com/WordPressUtilities/wpuoptions">WPU Options</a>',
            )
        );
        foreach ($this->plugins as $id => $plugin) {
            if (!is_plugin_active($plugin['path'])) {
                $this->plugins[$id]['installed'] = false;
                $this->set_message($id . '__not_installed', sprintf($this->__('The plugin %s should be installed.') , $plugin['message_url']) , 'error');
            }
        }
    }

    /* ----------------------------------------------------------
      API
    ---------------------------------------------------------- */

    function set_token() {

        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] != $this->options['id'] || !isset($_GET['code'])) {
            return;
        }

        $url = 'https://api.instagram.com/oauth/access_token';
        $request = new WP_Http;
        $result = wp_remote_post($url, array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect_uri,
                'code' => $_GET['code'],
            )
        ));

        $token = '';
        $response = '{}';
        if (!isset($result['body'])) {
            $this->set_message('token_no_body', $this->__('The response from Instagram is invalid.') , 'error');
            return;
        }
        $response = json_decode($result['body']);

        if (!isset($response->access_token)) {
            $this->set_message('token_no_token', $this->__('The access token from Instagram could not be retrieved.') , 'error');
            return;
        }

        $this->user_id = $response->user->id;
        $this->client_token = $response->access_token;

        update_option('wpu_get_instagram__client_token', $this->client_token);
        update_option('wpu_get_instagram__user_id', $this->user_id);

        $this->set_message('token_success', $this->__('The token have been successfully imported.') , 'updated');
        wp_redirect($this->redirect_uri);
        exit();
    }

    function import() {
        if (empty($this->client_id)) {
            $this->set_token();
        }

        $nb_items = 10;
        $imported_items = $this->get_imported_items();
        $request_url = 'https://api.instagram.com/v1/users/' . $this->user_id . '/media/recent/?count=' . $nb_items . '&access_token=' . $this->client_token;

        // Get cached JSON
        $json_instagram = get_transient($this->transient_id);
        if (empty($json_instagram)) {
            $json_instagram = file_get_contents($request_url);
            set_transient($this->transient_id, $json_instagram, $this->options['cache_duration']);
        }

        // Extract and return informations
        $imginsta = json_decode($json_instagram);

        if (!is_array($imginsta->data)) {
            $this->set_message('no_array_insta', $this->__('The datas sent by Instagram are invalid.') , 'error');
            return;
        }

        // Import each post if not in database
        $count = 0;
        foreach ($imginsta->data as $item) {
            $datas = $this->get_datas_from_item($item);
            if (!in_array($datas['id'], $imported_items)) {
                $count++;
                $this->import_item($datas, $item);
            }
        }
        return $count;
    }

    /* ----------------------------------------------------------
      Import functions
    ---------------------------------------------------------- */

    function import_item($datas, $original_item) {

        // Set post details

        $post_details = array(
            'post_title' => $datas['caption'],
            'post_content' => '',
            'guid' => sanitize_title($datas['caption'], 'Instagram post') ,
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']) ,
            'post_author' => 1,
            'post_type' => $this->options['post_type']
        );

        // Add hashtags
        preg_match("/#(\\w+)/", $datas['caption'], $matches);
        if (!empty($matches[1])) {
            $post_details['tags_input'] = implode(', ', $matches[1]);
        }

        // Create a new post
        $post_id = wp_insert_post($post_details);

        // Save datas
        update_post_meta($post_id, 'instagram_post_id', $datas['id']);
        update_post_meta($post_id, 'instagram_post_link', $datas['link']);
        update_post_meta($post_id, 'instagram_post_datas', $datas);

        // Add required classes
        require_once (ABSPATH . 'wp-admin/includes/media.php');
        require_once (ABSPATH . 'wp-admin/includes/file.php');
        require_once (ABSPATH . 'wp-admin/includes/image.php');

        // Import image as an attachment
        $image = media_sideload_image($datas['image'], $post_id, $datas['caption']);

        // then find the last image added to the post attachments
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'image'
        ));

        // set image as the post thumbnail
        if (sizeof($attachments) > 0) {
            set_post_thumbnail($post_id, $attachments[0]->ID);
        }
    }

    function get_imported_items() {
        $ids = array();
        $wpq_instagram_posts = new WP_Query(array(
            'posts_per_page' => 100,
            'post_type' => $this->options['post_type']
        ));
        if ($wpq_instagram_posts->have_posts()) {
            while ($wpq_instagram_posts->have_posts()) {
                $wpq_instagram_posts->the_post();
                $ids[] = get_post_meta(get_the_ID() , 'instagram_post_id', 1);
            }
        }
        wp_reset_postdata();
        return $ids;
    }

    function get_datas_from_item($details) {
        $datas = array(
            'image' => '',
            'link' => '#',
            'created_time' => '0',
            'caption' => '',
            'id' => 0
        );

        // Image
        if (isset($details->id)) {
            $datas['id'] = $details->id;
        }

        // Image
        if (isset($details->images->standard_resolution->url)) {
            $datas['image'] = $details->images->standard_resolution->url;
        }

        // Link
        if (isset($details->link)) {
            $datas['link'] = $details->link;
        }

        // Created time
        if (isset($details->created_time)) {
            $datas['created_time'] = $details->created_time;
        }

        // Caption
        if (isset($details->caption->text)) {
            $datas['caption'] = $details->caption->text;
        }

        return $datas;
    }

    /* ----------------------------------------------------------
      Post type
    ---------------------------------------------------------- */

    function register_post_types() {
        register_post_type($this->options['post_type'], array(
            'public' => true,
            'label' => 'Instagram posts',
            'supports' => array(
                'title',
                'editor',
                'thumbnail'
            )
        ));
    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    function add_menu_page() {
        if ($this->plugins['wpuoptions']['installed']) {
            add_management_page($this->options['name'], $this->options['name'], 'manage_options', $this->options['id'], array(&$this,
                'admin_page'
            ) , 'dashicons-admin-generic');
        }
    }

    function admin_import_postAction() {
        if (isset($_POST[$this->nonce_import]) && wp_verify_nonce($_POST[$this->nonce_import], $this->nonce_import . 'action')) {

            $count_import = $this->import();
            if ($count_import === false) {
                $this->set_message('import_error', $this->__('The import has failed.') , 'updated');
            }
            else {

                $msg_import = sprintf($this->__('%s files have been imported.') , $count_import);
                if ($count_import < 2) {
                    $msg_import = sprintf($this->__('%s file have been imported.') , $count_import);
                }
                if ($count_import < 1) {
                    $msg_import = sprintf($this->__('No file have been imported.') , $count_import);
                }
                $this->set_message('import_success', $msg_import, 'updated');
                update_option('wpudisplayinstagram_latestimport', current_time('timestamp', 1));
            }
            wp_redirect($this->redirect_uri);
            exit();
        }
    }

    function admin_page() {
        $_plugin_ok = true;
        $admin_link = admin_url('admin.php?page=wpuoptions-settings&tab=instagram_tab');
        $register_link = 'https://instagram.com/developer/clients/register/';
        $api_link = 'https://api.instagram.com/oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code';
        $latestimport = get_option('wpudisplayinstagram_latestimport');

        echo '<div class="wrap">';
        echo '<h2>' . $this->options['name'] . '</h2>';

        if (empty($this->client_token)) {
            $_plugin_ok = false;
            if (empty($this->client_id) || empty($this->client_secret) || empty($this->redirect_uri)) {
                echo '<p>' . sprintf($this->__('Please fill in <a href="%s">Config details</a> or create a <a target="_blank" href="%s">new Instagram app</a>') , $admin_link, $register_link) . '</p>';
            }
            else {
                echo '<p>' . sprintf($this->__('Please <a href="%s">login here</a>') , $api_link) . '.</p>';
            }
            echo '<p><strong>' . $this->__('Request URI') . '</strong> : <span contenteditable>' . $this->redirect_uri . '</span></p>';
        }
        else {
            echo '<p>' . $this->__('The plugin is configured !') . '</p>';
        }

        if ($_plugin_ok) {
            if (is_numeric($latestimport)) {
                echo '<p>' . sprintf($this->__('Latest import : %s ago') , human_time_diff($latestimport)) . '.</p>';
            }

            echo '<form action="' . $this->redirect_uri . '" method="post">
            ' . wp_nonce_field($this->nonce_import . 'action', $this->nonce_import) . '
                <p>
                    ' . get_submit_button($this->__('Import now') , 'primary', $this->options['id'] . 'import-datas') . '
                </p>
            </form>';

            $wpq_instagram_posts = new WP_Query(array(
                'posts_per_page' => 5,
                'post_type' => $this->options['post_type']
            ));

            if ($wpq_instagram_posts->have_posts()) {
                echo '<hr/><h3>' . $this->__('Latest imports') . '</h3><ul>';
                while ($wpq_instagram_posts->have_posts()) {
                    $wpq_instagram_posts->the_post();
                    echo '<li style="float: left;"><a href="' . get_edit_post_link(get_the_id()) . '">' . get_the_post_thumbnail(get_the_id() , 'thumbnail') . '</a></li>';
                }
                echo '</ul><hr style="clear: both;"/>';
            }
            wp_reset_postdata();
        }

        echo '</div>';
    }

    /* ----------------------------------------------------------
      Options for config
    ---------------------------------------------------------- */

    function options_tabs($tabs) {
        $tabs['instagram_tab'] = array(
            'name' => 'Plugin : Display Instagram',
        );
        return $tabs;
    }

    function options_boxes($boxes) {
        $boxes['instagram_config'] = array(
            'tab' => 'instagram_tab',
            'name' => 'Display Instagram'
        );
        return $boxes;
    }

    function options_fields($options) {
        $options['wpu_get_instagram__client_id'] = array(
            'label' => $this->__('Client ID') ,
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_secret'] = array(
            'label' => $this->__('Client Secret') ,
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_token'] = array(
            'label' => $this->__('Access token') ,
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__user_id'] = array(
            'label' => $this->__('User ID') ,
            'box' => 'instagram_config'
        );
        return $options;
    }

    /* ----------------------------------------------------------
      WordPress Utilities
    ---------------------------------------------------------- */

    /* Translate
     -------------------------- */

    private function __($string) {
        return __($string, 'wpudisplayinstagram');
    }

    /* Set notices messages */
    private function set_message($id, $message, $group = '') {
        $messages = (array)get_transient($this->transient_msg);
        if (!in_array($group, $this->notices_categories)) {
            $group = $this->notices_categories[0];
        }
        $messages[$group][$id] = $message;
        set_transient($this->transient_msg, $messages);
    }

    /* Display notices */
    function admin_notices() {
        $messages = (array)get_transient($this->transient_msg);
        if (!empty($messages)) {
            foreach ($messages as $group_id => $group) {
                if (is_array($group)) {
                    foreach ($group as $message) {
                        echo '<div class="' . $group_id . '"><p>' . $message . '</p></div>';
                    }
                }
            }
        }

        // Empty messages
        delete_transient($this->transient_msg);
    }
}

$wpu_display_instagram = new wpu_display_instagram();

/* ----------------------------------------------------------
  Activation
---------------------------------------------------------- */

register_activation_hook(__FILE__, 'wpu_display_instagram__activation');
function wpu_display_instagram__activation() {
    wp_schedule_event(time() , 'hourly', 'wpu_display_instagram__cron_hook');
}

add_action('wpu_display_instagram__cron_hook', 'wpu_display_instagram__import');
function wpu_display_instagram__import() {
    global $wpu_display_instagram;
    $wpu_display_instagram->import();
}

