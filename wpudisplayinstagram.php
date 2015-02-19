<?php
/*
Plugin Name: WPU Display Instagram
Description: Displays the latest image for an Instagram account
Version: 0.5
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram
{
    function __construct() {

        $this->options = array(
            'id' => 'wpu-display-instagram',
            'name' => 'Display Instagram'
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
            'register_post_types'
        ));

        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));
        add_action('admin_init', array(&$this,
            'set_token'
        ));
    }

    function init() {
        $this->client_token = trim(get_option('wpu_get_instagram__client_token'));
        $this->client_id = trim(get_option('wpu_get_instagram__client_id'));
        $this->client_secret = trim(get_option('wpu_get_instagram__client_secret'));
        $this->user_id = trim(get_option('wpu_get_instagram__user_id'));
        $this->redirect_uri = admin_url('admin.php?page=' . $this->options['id']);
        $this->transient_id = 'json_instagram_' . $this->user_id;
        $this->latest_id = 'latest_id_instagram_' . $this->user_id;
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
            return;
        }
        $response = json_decode($result['body']);

        if (!isset($response->access_token)) {
            return;
        }

        $this->user_id = $response->user->id;
        $this->client_token = $response->access_token;

        update_option('wpu_get_instagram__client_token', $this->client_token);
        update_option('wpu_get_instagram__user_id', $this->user_id);

        wp_redirect($this->redirect_uri);
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
        if (!empty($json_instagram)) {
            $json_instagram = file_get_contents($request_url);
            set_transient($this->transient_id, $json_instagram, HOUR_IN_SECONDS);
        }

        // Extract and return informations
        $imginsta = json_decode($json_instagram);

        if (!is_array($imginsta->data)) {
            return;
        }

        // Import each post if not in database
        foreach ($imginsta->data as $item) {
            $datas = $this->get_datas_from_item($item);
            if (!in_array($datas['id'], $imported_items)) {
                $this->import_item($datas, $item);
            }
        }
    }

    /* ----------------------------------------------------------
      Import functions
    ---------------------------------------------------------- */

    function import_item($datas, $original_item) {

        // Create a new post
        $post_id = wp_insert_post(array(
            'post_title' => $datas['caption'],
            'post_content' => '',
            'guid' => sanitize_title($datas['caption'], 'Instagram post') ,
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']) ,
            'post_author' => 1,
            'post_type' => 'instagram_posts'
        ));

        // Save postid
        update_post_meta($post_id, 'instagram_post_id', $datas['id']);

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
            'posts_per_page' => 10,
            'post_type' => 'instagram_posts'
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
        register_post_type('instagram_posts', array(
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
        add_menu_page($this->options['name'], $this->options['name'], 'manage_options', $this->options['id'], array(&$this,
            'admin_page'
        ) , 'dashicons-admin-generic');
    }

    function admin_page() {
        echo '<div class="wrap">';
        echo '<h2>' . $this->options['name'] . '</h2>';

        if (empty($this->client_token)) {

            if (empty($this->client_id) || empty($this->client_secret) || empty($this->redirect_uri)) {
                echo '<p>Please fill in <a href="' . admin_url('admin.php?page=wpuoptions-settings&tab=instagram_tab') . '">Config details</a> or create a <a target="_blank" href="https://instagram.com/developer/clients/register/">new Instagram app</a></p>';
            } else {
                echo '<p>Please <a href="https://api.instagram.com/oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code">login here</a>.</p>';
            }
            echo '<p><strong>Request URI</strong> : <span contenteditable>' . $this->redirect_uri . '</span></p>';
        } else {
            echo '<p>The plugin is configured !</p>';
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
            'label' => 'Client ID',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_secret'] = array(
            'label' => 'Client Secret',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__client_token'] = array(
            'label' => 'Access token',
            'box' => 'instagram_config'
        );
        $options['wpu_get_instagram__user_id'] = array(
            'label' => 'User ID',
            'box' => 'instagram_config'
        );
        return $options;
    }
}

$wpu_display_instagram = new wpu_display_instagram();

// add_action('wp_loaded', 'instagram_import');
// function instagram_import() {
//     global $wpu_display_instagram;
//     $wpu_display_instagram->import();
// }
