<?php

/*
Plugin Name: WPU Import Instagram
Description: Import the latest instagram images
Version: 0.18.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram {

    public $test_user_id = 25025320;

    public $options = array();
    public $messages = false;
    public $basecron = false;
    public $register_link = 'https://instagram.com/developer/clients/register/';
    public $option_user_ids_opt = 'wpu_get_instagram__user_ids_opt';

    public $plugin_version = '0.18.1';

    public function __construct() {
        $this->options = array(
            'plugin_id' => 'wpudisplayinstagram',
            'id' => 'wpu-display-instagram',
            'name' => 'Import Instagram',
            'post_type' => apply_filters('wpudisplayinstagram__post_type_id', 'instagram_posts')
        );

        add_filter('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));
        add_filter('init', array(&$this,
            'init'
        ));
        add_action('init', array(&$this,
            'register_post_types'
        ));
        add_action('admin_init', array(&$this,
            'set_token'
        ));
        add_action('admin_post_' . $this->options['plugin_id'], array(&$this,
            'admin_postAction'
        ));
        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__), array(&$this,
            'settings_link'
        ));
        add_action('wpu_display_instagram__cron_hook', array(&$this,
            'cron_action'
        ));

        // Listing
        add_filter('manage_edit-instagram_posts_columns', array(&$this,
            'posts_columns'
        ));
        add_action('manage_instagram_posts_posts_custom_column', array(&$this,
            'posts_column_content'
        ), 10, 2);
        add_filter('manage_edit-instagram_posts_sortable_columns', array(&$this,
            'sortable_posts_column'
        ));
        add_action('pre_get_posts', array(&$this,
            'posts_columns_orderby'
        ));
        add_filter('parse_query', array(&$this,
            'filter_admin_results'
        ));

        // Single
        add_action('add_meta_boxes', array(&$this,
            'add_meta_boxes'
        ));

        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        // Settings
        $this->settings_details = array(
            'plugin_id' => 'wpudisplayinstagram',
            'option_id' => 'wpudisplayinstagram_options',
            'sections' => array(
                'import' => array(
                    'name' => __('Import settings', 'wpudisplayinstagram')
                ),
                'user' => array(
                    'name' => __('User details', 'wpudisplayinstagram')
                )
            )
        );

        $this->settings = array(
            'client_id' => array(
                'section' => 'import',
                'label' => __('Client ID', 'wpudisplayinstagram')
            ),
            'client_secret' => array(
                'section' => 'import',
                'label' => __('Client Secret', 'wpudisplayinstagram')
            ),
            'client_token' => array(
                'section' => 'import',
                'label' => __('Access token', 'wpudisplayinstagram')
            ),
            'user_names' => array(
                'section' => 'user',
                'type' => 'textarea',
                'label' => __('Usernames', 'wpudisplayinstagram'),
                'help' => __('One user name by line', 'wpudisplayinstagram')
            ),
            'import_as_draft' => array(
                'section' => 'user',
                'label' => __('Import as draft', 'wpudisplayinstagram'),
                'label_check' => __('Import image as a draft post', 'wpudisplayinstagram'),
                'type' => 'checkbox'
            )
        );

        $this->options_values = get_option($this->settings_details['option_id']);
        if (!is_array($this->options_values)) {
            $this->options_values = array();
        }
    }

    public function cron_action() {
        $this->init_content();
        $this->import();
    }

    public function plugins_loaded() {
        // Messages
        include_once 'inc/WPUBaseMessages.php';
        $this->messages = new \wpudisplayinstagram\WPUBaseMessages($this->options['plugin_id']);
        add_action('wpuimporttwitter_admin_notices', array(&$this->messages,
            'admin_notices'
        ));

        // Cron
        include_once 'inc/WPUBaseCron.php';
        $this->basecron = new \wpudisplayinstagram\WPUBaseCron();
        $this->basecron->init(array(
            'pluginname' => $this->options['name'],
            'cronhook' => 'wpu_display_instagram__cron_hook',
            'croninterval' => 3600
        ));
        $this->basesettings = false;
        if (is_admin()) {
            include_once 'inc/WPUBaseSettings.php';
            $this->basesettings = new \wpudisplayinstagram\WPUBaseSettings($this->settings_details, $this->settings);
        }

    }

    public function init() {
        $this->init_content();
    }

    public function init_content() {
        $this->nonce_import = $this->options['id'] . '__nonce_import';

        // Instagram config
        $this->client_token = isset($this->options_values['client_token']) ? trim($this->options_values['client_token']) : '';
        $this->client_id = isset($this->options_values['client_id']) ? trim($this->options_values['client_id']) : '';
        $this->client_secret = isset($this->options_values['client_secret']) ? trim($this->options_values['client_secret']) : '';
        $this->user_name = isset($this->options_values['user_name']) ? trim($this->options_values['user_name']) : '';
        $this->user_names = isset($this->options_values['user_names']) ? trim($this->options_values['user_names']) : '';
        $this->import_as_draft = isset($this->options_values['import_as_draft']) ? trim($this->options_values['import_as_draft']) : false;

        $opt_sandboxmode = get_option('wpu_get_instagram__sandboxmode');
        $this->sandboxmode = ($opt_sandboxmode != '0') ? '1' : '0';
        if (is_null($opt_sandboxmode) || $opt_sandboxmode === false) {
            $this->test_sandbox_mode();
        }

        // Admin URL
        $this->admin_uri = 'edit.php?post_type=' . $this->options['post_type'];
        $this->redirect_uri = admin_url($this->admin_uri . '&page=' . $this->options['id']);

        $this->config_ok = !empty($this->client_id) && !empty($this->client_secret);

    }

    /* ----------------------------------------------------------
      API
    ---------------------------------------------------------- */

    public function get_user_names() {
        $user_names = array();
        $_baseUserNames = str_replace(array(',', ';', ' '), "\n", $this->user_names);
        $_baseUserNames = explode("\n", $_baseUserNames);
        foreach ($_baseUserNames as $user_name) {
            $user_name = trim(esc_html($user_name));
            if (!empty($user_name)) {
                $user_id = $this->get_user_id($user_name);
                $request_url = $this->get_request_url($user_id);
                $user_names[] = array(
                    'user_name' => $user_name,
                    'user_id' => $user_id,
                    'request_url' => $request_url
                );
            }
        }
        return $user_names;
    }

    public function get_request_url($user_id = false) {

        if (!$user_id || !is_numeric($user_id)) {
            $user_id = $this->get_user_id();
        }

        if (!is_numeric($user_id)) {
            return false;
        }

        return 'https://api.instagram.com/v1/users/' . $user_id . '/media/recent/?count=%s&access_token=' . $this->client_token;
    }

    public function get_user_id($user_name = '') {
        $_option_user_id = 'wpu_get_instagram__user_id__' . $user_name;
        $_user_id = false;

        if (empty($user_name)) {
            $token_parts = explode('.', $this->client_token);
            if (is_numeric($token_parts[0])) {
                return $token_parts[0];
            }
        }

        /* Set master user ids function */
        $opt_user_id = get_option($this->option_user_ids_opt);
        if (!is_array($opt_user_id)) {
            $opt_user_id = array();
        }

        if (!in_array($_option_user_id, $opt_user_id)) {
            $opt_user_id[] = $_option_user_id;
            update_option($this->option_user_ids_opt, $opt_user_id);
        }

        /* Get from DB */
        if (!property_exists($this, 'user_id')) {
            $_user_id = trim(get_option($_option_user_id));
        }

        /* Test if valid */
        if (is_numeric($_user_id)) {
            return $_user_id;
        }

        /* Try to get username */
        if (empty($user_name) || !preg_match("/[A-Za-z0-9_]+/i", $user_name)) {
            return false;
        }

        /* Try to get user id from API */
        $_url = "https://api.instagram.com/v1/users/search?q=" . $user_name . "&access_token=" . $this->client_token;
        $_request = wp_remote_get($_url);
        if (is_wp_error($_request)) {
            return false;
        }
        $json = json_decode(wp_remote_retrieve_body($_request));
        if (!is_object($json) || !is_array($json->data) || !isset($json->data[0])) {
            return false;
        }

        $base_username = strtolower($user_name);
        foreach ($json->data as $_user) {
            $tmp_username = strtolower($_user->username);
            if ($tmp_username == $base_username) {
                $_user_id = $_user->id;
                update_option($_option_user_id, $_user_id);
                return $_user_id;
            }
        }
        return false;
    }

    public function set_token() {

        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] != $this->options['id'] || !isset($_GET['code'])) {
            return;
        }

        $url = 'https://api.instagram.com/oauth/access_token';
        $result = wp_remote_post($url, array(
            'body' => array(
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirect_uri,
                'code' => $_GET['code']
            )
        ));

        $token = '';
        $response = '{}';
        if (is_wp_error($result)) {
            $this->messages->set_message('token_no_body', __('The response from Instagram is invalid.', 'wpudisplayinstagram'), 'error');
            return;
        }
        $response = json_decode(wp_remote_retrieve_body($result));

        if (!isset($response->access_token)) {
            $this->messages->set_message('token_no_token', __('The access token from Instagram could not be retrieved.', 'wpudisplayinstagram'), 'error');
            return;
        }

        $this->client_token = $response->access_token;

        $this->test_sandbox_mode();

        // Update options
        $this->basesettings->update_setting('client_token', $this->client_token);

        $this->messages->set_message('token_success', __('The token have been successfully imported.', 'wpudisplayinstagram'), 'updated');
        wp_redirect($this->redirect_uri);
        exit();
    }

    public function import() {
        if (empty($this->client_token)) {
            $this->set_token();
        }

        if ($this->sandboxmode) {
            $this->test_sandbox_mode();
        }

        $imported_items = $this->get_imported_items();
        $user_names = $this->get_user_names();
        $nb_items = 10;
        if (count($user_names) > 1) {
            $nb_items = 5;
        }
        $nb_items = apply_filters('wpudisplayinstagram__nb_items', $nb_items);

        if (empty($user_names)) {
            return 0;
        }
        $base_userid = $this->get_user_id();

        $total_count = 0;
        foreach ($user_names as $user_name) {
            // If sandbox, ignore if not base username
            if ($this->sandboxmode && $base_userid != $user_name['user_id']) {
                continue;
            }
            $total_count += $this->import_for_user($user_name, $imported_items, $nb_items);
        }

        return $total_count;

    }

    public function import_for_user($user_name = '', $imported_items = array(), $nb_items = 1) {
        $request_url = sprintf($user_name['request_url'], $nb_items);

        // Send request
        $request = wp_remote_get($request_url);
        if (is_wp_error($request)) {
            $this->messages->set_message('no_array_insta', __('The datas sent by Instagram are invalid.', 'wpudisplayinstagram'), 'error');
            if (!$this->sandboxmode) {
                $this->test_sandbox_mode();
            }
            return 0;
        }

        // Extract and return informations
        $imginsta = json_decode(wp_remote_retrieve_body($request));
        if (!is_object($imginsta) || !property_exists($imginsta, 'data') || !is_array($imginsta->data)) {
            $this->messages->set_message('no_array_insta', __('The datas sent by Instagram are invalid.', 'wpudisplayinstagram'), 'error');
            if (!$this->sandboxmode) {
                $this->test_sandbox_mode();
            }
            return 0;
        }

        // Import each post if not in database
        $count = 0;
        foreach ($imginsta->data as $item) {
            $datas = $this->get_datas_from_item($item);
            if (!in_array($datas['id'], $imported_items)) {
                $count++;
                $imported_items[] = $this->import_item($datas);
            }
        }
        return $count;

    }

    /* ----------------------------------------------------------
      Import functions
    ---------------------------------------------------------- */

    public function import_item($datas = array()) {

        // Set post details

        $post_title = wp_trim_words($datas['caption'], 20);

        $post_details = array(
            'post_title' => $post_title,
            'post_content' => $datas['caption'],
            'post_name' => preg_replace('/([^a-z0-9-$]*)/isU', '', sanitize_title($post_title)),
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']),
            'post_author' => 1,
            'post_type' => $this->options['post_type']
        );

        // Import as draft
        if ($this->import_as_draft) {
            $post_details['post_status'] = 'draft';
        }

        // Add hashtags
        $matches = array();
        preg_match_all("/#(\\w+)/", $datas['caption'], $matches);
        if (!empty($matches[1])) {
            $post_details['tags_input'] = implode(', ', $matches[1]);
        }

        // Create a new post
        $post_id = wp_insert_post($post_details);

        // Save datas
        update_post_meta($post_id, 'instagram_post_id', $datas['id']);
        update_post_meta($post_id, 'instagram_post_link', $datas['link']);
        update_post_meta($post_id, 'instagram_post_username', $datas['username']);
        update_post_meta($post_id, 'instagram_post_full_name', $datas['full_name']);
        update_post_meta($post_id, 'instagram_post_datas', $datas);

        if ($datas['location']['latitude'] != 0) {
            update_post_meta($post_id, 'instagram_post_latitude', $datas['location']['latitude']);
            update_post_meta($post_id, 'instagram_post_longitude', $datas['location']['longitude']);
        }

        // Add required classes
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

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

        return $datas['id'];
    }

    public function get_imported_items() {
        global $wpdb;
        $wpids = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'instagram_post_id'");
        return is_array($wpids) ? $wpids : array();
    }

    public function get_datas_from_item($details) {
        $datas = array(
            'image' => '',
            'username' => '',
            'full_name' => '',
            'link' => '#',
            'created_time' => '0',
            'caption' => '',
            'location' => array(
                'latitude' => 0,
                'longitude' => 0
            ),
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

        // Name
        if (isset($details->user, $details->user->username, $details->user->full_name)) {
            $datas['username'] = $details->user->username;
            $datas['full_name'] = $details->user->full_name;
        }

        // Created time
        if (isset($details->created_time)) {
            $datas['created_time'] = $details->created_time;
        }

        // Caption
        if (isset($details->caption->text)) {
            $datas['caption'] = $details->caption->text;
        }

        if (isset($details->location->name)) {
            if (!empty($datas['caption'])) {
                $datas['caption'] .= ' - ';
            }
            $datas['caption'] .= $details->location->name;
        }

        // Location
        if (isset($details->location->latitude, $details->location->longitude)) {
            $datas['location'] = array(
                'latitude' => $details->location->latitude,
                'longitude' => $details->location->longitude
            );
        }

        return $datas;
    }

    /* ----------------------------------------------------------
      Post type
    ---------------------------------------------------------- */

    public function register_post_types() {
        register_post_type($this->options['post_type'], apply_filters('wpudisplayinstagram__post_type_infos', array(
            'public' => true,
            'label' => __('Instagram posts', 'wpudisplayinstagram'),
            'labels' => array(
                'singular_name' => __('Instagram post', 'wpudisplayinstagram')
            ),
            'menu_icon' => 'dashicons-format-image',
            'supports' => array(
                'title',
                'editor',
                'thumbnail'
            )
        )));
    }

    /* ----------------------------------------------------------
      Admin page
    ---------------------------------------------------------- */

    public function add_menu_page() {
        add_submenu_page($this->admin_uri, $this->options['name'], __('Import settings', 'wpudisplayinstagram'), 'manage_options', $this->options['id'], array(&$this,
            'admin_page'
        ));
    }

    /**
     * Settings link
     */
    public function settings_link($links = array()) {
        $settings_link = '<a href="' . $this->redirect_uri . '">' . __('Settings', 'wpudisplayinstagram') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function admin_postAction() {
        if (isset($_POST[$this->nonce_import]) && wp_verify_nonce($_POST[$this->nonce_import], $this->nonce_import . 'action')) {
            if (isset($_POST[$this->options['id'] . 'import-datas'])) {
                $this->admin_postAction_import();
            } else if (isset($_POST[$this->options['id'] . 'import-test'])) {
                $this->test_sandbox_mode();
                $returnTest = $this->admin_postAction_importTest();
                $this->messages->set_message('importtest_success', ($returnTest ? __('The API works great.', 'wpudisplayinstagram') : __('The API does not work.', 'wpudisplayinstagram')), ($returnTest ? 'updated' : 'error'));
            }
            wp_redirect($this->redirect_uri);
            exit();
        }
    }

    private function admin_postAction_importTest($id = false) {
        $_nb_items_test = 1;
        $request_url = $this->get_request_url($id);
        if (!$request_url) {
            return false;
        }

        $_request = wp_remote_get(sprintf($request_url, $_nb_items_test));
        if (is_wp_error($_request)) {
            return false;
        }

        $_json = json_decode(wp_remote_retrieve_body($_request));
        if (!is_object($_json) || !property_exists($_json, 'data') || count($_json->data) != $_nb_items_test) {
            return false;
        }

        return true;
    }

    private function test_sandbox_mode() {
        $sandbox_mode = $this->sandboxmode;
        $this->sandboxmode = $this->admin_postAction_importTest($this->test_user_id) ? 0 : 1;
        if ($sandbox_mode != $this->sandboxmode) {
            update_option('wpu_get_instagram__sandboxmode', $this->sandboxmode);
        }
    }

    private function admin_postAction_import() {
        $count_import = $this->import();
        if ($count_import === false) {
            $this->messages->set_message('import_error', __('The import has failed.', 'wpudisplayinstagram'), 'updated');
        } else {
            $msg_import = sprintf(__('%s files have been imported.', 'wpudisplayinstagram'), $count_import);
            if ($count_import < 2) {
                $msg_import = sprintf(__('%s file have been imported.', 'wpudisplayinstagram'), $count_import);
            }
            if ($count_import < 1) {
                $msg_import = sprintf(__('No file have been imported.', 'wpudisplayinstagram'), $count_import);
            }
            $this->messages->set_message('import_success', $msg_import, 'updated');
            update_option('wpudisplayinstagram_latestimport', current_time('timestamp', 1));
        }
    }

    public function admin_page() {
        $_plugin_ok = true;
        $api_link = 'https://api.instagram.com/oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code';
        $latestimport = get_option('wpudisplayinstagram_latestimport');

        echo '<div class="wrap">';
        if ($this->sandboxmode && $this->config_ok) {
            echo '<div class="error notice"><p>' . __('Sandbox mode is enabled for this app.', 'wpudisplayinstagram') . '</p></div>';
        }
        echo '<h1>' . get_admin_page_title() . '</h1>';

        settings_errors($this->settings_details['option_id']);

        if (defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL) {
            $_plugin_ok = false;
            echo '<div class="notice error"><p>' . __('The WordPress external requests are disabled.', 'wpudisplayinstagram') . '</p></div>';
        }

        if (empty($this->client_token)) {
            $_plugin_ok = false;
            if (!$this->config_ok) {
                echo '<p>' . sprintf(__('Please fill in Config details or create a <a target="_blank" href="%s">new Instagram app</a>', 'wpudisplayinstagram'), $this->register_link) . '</p>';
            } else {
                echo '<p>' . sprintf(__('Please <a href="%s">login here</a>', 'wpudisplayinstagram'), $api_link) . '.</p>';
            }
            echo '<p><strong>' . __('Request URI', 'wpudisplayinstagram') . '</strong> : <span contenteditable>' . $this->redirect_uri . '</span></p>';
        }

        if ($_plugin_ok) {
            $next_scheduled = wp_next_scheduled('wpu_display_instagram__cron_hook');
            if (is_numeric($next_scheduled)) {
                echo '<p>' . sprintf(__('Next import: in %s', 'wpudisplayinstagram'), human_time_diff($next_scheduled)) . '.</p>';
            }

            echo '<form action="' . admin_url('admin-post.php') . '" method="post">';
            echo '<input type="hidden" name="action" value="' . $this->options['plugin_id'] . '" />';
            echo wp_nonce_field($this->nonce_import . 'action', $this->nonce_import);
            echo get_submit_button(__('Import now', 'wpudisplayinstagram'), 'primary', $this->options['id'] . 'import-datas', false) . ' ';
            echo get_submit_button(__('Test import', 'wpudisplayinstagram'), 'secondary', $this->options['id'] . 'import-test', false);
            echo '</form>';

            $wpq_instagram_posts = get_posts(array(
                'posts_per_page' => 5,
                'post_type' => $this->options['post_type'],
                'orderby' => 'post_date',
                'order' => 'DESC',
                'post_status' => 'any',
                'fields' => 'ids'
            ));

            if (!empty($wpq_instagram_posts)) {
                echo '<br /><hr/><h3>' . __('Latest imports', 'wpudisplayinstagram') . '</h3><ul>';
                foreach ($wpq_instagram_posts as $post_id) {
                    echo '<li style="float:left">';
                    echo '<a href="' . get_edit_post_link($post_id) . '">' . get_the_post_thumbnail($post_id, 'thumbnail') . '</a><br />';
                    echo $this->display_author($post_id);
                    echo '</li>';
                }
                echo '</ul><div style="clear: both;"></div>';
            }
            echo '<br /><hr/>';
            wp_reset_postdata();
        }

        // Settings
        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->options['plugin_id']);
        echo submit_button(__('Save Changes', 'wpudisplayinstagram'));
        echo '</form>';

        echo '</div>';
    }

    /* ----------------------------------------------------------
      Listing
    ---------------------------------------------------------- */

    public function posts_columns($columns = array()) {
        $columns['instagram_post_username'] = __('Author', 'wpudisplayinstagram');
        return $columns;
    }

    public function display_author($post_id = 1) {
        $fullname = get_post_meta($post_id, 'instagram_post_full_name', true);
        $username = get_post_meta($post_id, 'instagram_post_username', true);
        if (empty($fullname) || empty($username)) {
            return '';
        }
        $external_url = 'https://instagram.com/' . $username;
        $url = admin_url('edit.php?post_type=' . $this->options['post_type'] . '&instagram_post_username=' . esc_attr($username));
        return sprintf('<strong><a href="%s" target="_blank"><span style="text-decoration:none;font-size:1em;vertical-align:middle;" class="dashicons dashicons-format-image"></span></a> %s</strong><br />&rarr; <a href="%s">%s</a>', $external_url, $fullname, $url, $username);
    }

    public function posts_column_content($column_name = '', $post_id = 1) {
        if ('instagram_post_username' != $column_name) {
            return;
        }
        echo $this->display_author($post_id);
    }

    public function sortable_posts_column($columns = array()) {
        $columns['instagram_post_username'] = 'instagram_post_username';
        return $columns;
    }

    public function posts_columns_orderby($query) {
        if (!is_admin()) {
            return;
        }

        // Order by
        $orderby = $query->get('orderby');
        if ('instagram_post_username' == $orderby) {
            $query->set('meta_key', 'instagram_post_username');
            $query->set('orderby', 'meta_value');
        }

    }

    public function filter_admin_results($query) {
        global $pagenow;
        $type = 'post';
        if (isset($_GET['post_type'])) {
            $type = $_GET['post_type'];
        }
        if ($this->options['post_type'] == $type && is_admin() && $pagenow == 'edit.php' && isset($_GET['instagram_post_username']) && $_GET['instagram_post_username'] != '') {
            $query->query_vars['meta_key'] = 'instagram_post_username';
            $query->query_vars['meta_value'] = $_GET['instagram_post_username'];
        }
    }

    /* ----------------------------------------------------------
      Single
    ---------------------------------------------------------- */

    public function add_meta_boxes() {
        add_meta_box('wpu_display_instagram-author', __('Author', 'wpudisplayinstagram'), array(&$this, 'meta_box_callback_author'), $this->options['post_type'], 'side');
    }

    public function meta_box_callback_author($post) {
        if (is_object($post)) {
            echo $this->display_author($post->ID);
        }
    }

    /* ----------------------------------------------------------
      Activation / Deactivation
    ---------------------------------------------------------- */

    public function activation() {
        delete_option('wpu_get_instagram__sandboxmode');
        flush_rewrite_rules();
    }

    public function deactivation() {
        delete_option('wpu_get_instagram__sandboxmode');
        flush_rewrite_rules();
    }

    public function uninstall() {

        $this->basecron->uninstall();

        // Delete options
        $options_users = get_option($this->option_user_ids_opt);
        if (is_array($options_users)) {
            foreach ($options_users as $option_user_id) {
                delete_option($option_user_id);
            }
        }
        delete_option($this->option_user_ids_opt);
        delete_option('wpu_get_instagram__sandboxmode');
        delete_option('wpu_get_instagram__client_secret');
        delete_option('wpu_get_instagram__client_token');
        delete_option('wpu_get_instagram__user_id');
        delete_option('wpu_get_instagram__user_name');
        delete_option('wpudisplayinstagram_latestimport');
        delete_option($this->settings_details['option_id']);

        // Delete fields
        delete_post_meta_by_key('instagram_post_username');
        delete_post_meta_by_key('instagram_post_full_name');
        delete_post_meta_by_key('instagram_post_id');
        delete_post_meta_by_key('instagram_post_link');
        delete_post_meta_by_key('instagram_post_datas');
        delete_post_meta_by_key('instagram_post_latitude');
        delete_post_meta_by_key('instagram_post_longitude');
    }

}

$wpu_display_instagram = new wpu_display_instagram();

register_activation_hook(__FILE__, array(&$wpu_display_instagram,
    'activation'
));
register_deactivation_hook(__FILE__, array(&$wpu_display_instagram,
    'deactivation'
));

/* ----------------------------------------------------------
  Widget
---------------------------------------------------------- */

add_action('widgets_init', 'wpudisplayinstagram_register_widgets');
function wpudisplayinstagram_register_widgets() {
    register_widget('wpudisplayinstagram');
}

class wpudisplayinstagram extends WP_Widget {
    public function __construct() {
        parent::__construct(false, '[WPU] Import Instagram', array(
            'description' => 'Import Instagram'
        ));
    }
    public function form($instance) {
        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        $nb_items = is_numeric($instance['nb_items']) ? $instance['nb_items'] : 1;?>
        <p>
        <label for="<?php echo $this->get_field_id('nb_items'); ?>"><?php _e('Number of pictures displayed:', 'wpudisplayinstagram');?></label>
        <input class="widefat" id="<?php echo $this->get_field_id('nb_items'); ?>" name="<?php echo $this->get_field_name('nb_items'); ?>" type="text" value="<?php echo esc_attr($nb_items); ?>">
        </p>
        <?php
}
    public function update($new_instance, $old_instance) {
        return array(
            'nb_items' => is_numeric($new_instance['nb_items']) ? $new_instance['nb_items'] : 1
        );
    }
    public function widget($widget_args = array(), $instance = array()) {
        $nb_items = isset($instance['nb_items']) && is_numeric($instance['nb_items']) ? $instance['nb_items'] : 5;
        global $wpu_display_instagram;
        echo $widget_args['before_widget'];
        $wpq_instagram_posts = new WP_Query(array(
            'posts_per_page' => $nb_items,
            'post_type' => $wpu_display_instagram->options['post_type'],
            'orderby' => 'ID',
            'order' => 'DESC',
            'post_status' => 'any'
        ));
        if ($wpq_instagram_posts->have_posts()) {
            echo '<ul class="wpu-display-instagram__list">';
            while ($wpq_instagram_posts->have_posts()) {
                $wpq_instagram_posts->the_post();
                echo '<li class="instagram-item">';
                echo '<a class="instagram-link" target="_blank" href="' . get_post_meta(get_the_ID(), 'instagram_post_link', 1) . '">';
                the_post_thumbnail();
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }
        wp_reset_postdata();

        echo $widget_args['after_widget'];
    }
}
