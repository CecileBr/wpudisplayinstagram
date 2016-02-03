<?php

/*
Plugin Name: WPU Display Instagram
Description: Displays the latest image for an Instagram account
Version: 0.12.3
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class wpu_display_instagram {
    public $plugin_version = '0.12.3';

    private $notices_categories = array(
        'updated',
        'update-nag',
        'error'
    );

    function __construct() {
        $this->options = array(
            'id' => 'wpu-display-instagram',
            'name' => 'Display Instagram',
            'post_type' => 'instagram_posts',
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
            'admin_postAction'
        ));
        add_action('admin_menu', array(&$this,
            'add_menu_page'
        ));
        add_filter("plugin_action_links_" . plugin_basename(__FILE__) , array(&$this,
            'settings_link'
        ));

        // Display notices
        add_action('admin_notices', array(&$this,
            'admin_notices'
        ));
    }

    function init() {
        $this->init_content(false);
    }

    function init_content($cron = true) {
        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        global $current_user;
        $this->transient_prefix = $this->options['id'];
        if (!$cron) {
            $this->transient_prefix.= $current_user->ID;
        }
        $this->transient_prefix.= '__' . $this->plugin_version;
        $this->nonce_import = $this->options['id'] . '__nonce_import';

        // Instagram config
        $this->client_token = trim(get_option('wpu_get_instagram__client_token'));
        $this->client_id = trim(get_option('wpu_get_instagram__client_id'));
        $this->client_secret = trim(get_option('wpu_get_instagram__client_secret'));
        $this->user_id = $this->get_user_id();
        $this->request_url = 'https://api.instagram.com/v1/users/' . $this->user_id . '/media/recent/?count=%s&access_token=' . $this->client_token;

        // Admin URL
        $this->admin_uri = 'edit.php?post_type=' . $this->options['post_type'];
        $this->redirect_uri = admin_url($this->admin_uri . '&page=' . $this->options['id']);

        // Transient
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

    function get_user_id() {

        /* Get from DB */
        if (!property_exists($this, 'user_id')) {
            $this->user_id = trim(get_option('wpu_get_instagram__user_id'));
        }

        if (!property_exists($this, 'user_name')) {
            $this->user_name = trim(get_option('wpu_get_instagram__user_name'));
        }

        /* Test if valid */
        if (is_numeric($this->user_id)) {
            return $this->user_id;
        }

        /* Try to get username */
        if (empty($this->user_name) || !preg_match("/[A-Za-z0-9_]+/i", $this->user_name)) {
            return false;
        }

        /* Try to get user id from API */
        $_url = "https://api.instagram.com/v1/users/search?q=" . $this->user_name . "&access_token=" . $this->client_token;
        $_request = wp_remote_get($_url);
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }
        $json = json_decode($_request['body']);
        if (!is_object($json) || !is_array($json->data) || !isset($json->data[0])) {
            return false;
        }

        $base_username = strtolower($this->user_name);
        foreach ($json->data as $_user) {
            $tmp_username = strtolower($_user->username);
            if ($tmp_username == $base_username) {
                $this->user_id = $_user->id;
                update_option('wpu_get_instagram__user_id', $this->user_id);
                return $this->user_id;
            }
        }
        return false;
    }

    function set_token() {

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

    function import($cron = false) {
        if (empty($this->client_id)) {
            $this->set_token();
        }

        $nb_items = 10;
        $imported_items = $this->get_imported_items();
        $request_url = sprintf($this->request_url, $nb_items);

        // Send request
        $request = wp_remote_get($request_url);
        if (!is_array($request) || !isset($request['body'])) {
            if (!$cron) {
                $this->set_message('no_array_insta', $this->__('The datas sent by Instagram are invalid.') , 'error');
            }
            return;
        }

        // Extract and return informations
        $imginsta = json_decode($request['body']);
        if (!is_object($imginsta) || !is_array($imginsta->data)) {
            if (!$cron) {
                $this->set_message('no_array_insta', $this->__('The datas sent by Instagram are invalid.') , 'error');
            }
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

        $post_title = wp_trim_words($datas['caption'], 20);

        $post_details = array(
            'post_title' => $post_title,
            'post_content' => $datas['caption'],
            'post_name' => preg_replace('/([^a-z0-9-$]*)/isU', '', sanitize_title($post_title)) ,
            'post_status' => 'publish',
            'post_date' => date('Y-m-d H:i:s', $datas['created_time']) ,
            'post_author' => 1,
            'post_type' => $this->options['post_type']
        );

        // Import as draft
        $import_as_draft = get_option('wpu_get_instagram__import_as_draft');
        if ($import_as_draft) {
            $post_details['post_status'] = 'draft';
        }

        // Add hashtags
        preg_match_all("/#(\\w+)/", $datas['caption'], $matches);
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
        global $wpdb;
        $wpids = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'instagram_post_id'");
        return is_array($wpids) ? $wpids : array();
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

        if (isset($details->location->name)) {
            if (!empty($datas['caption'])) {
                $datas['caption'].= ' - ';
            }
            $datas['caption'].= $details->location->name;
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
            'menu_icon' => 'dashicons-format-image',
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
            add_submenu_page($this->admin_uri, $this->options['name'], __('Settings') , 'manage_options', $this->options['id'], array(&$this,
                'admin_page'
            ));
        }
    }

    /**
     * Settings link
     */
    function settings_link($links) {
        $settings_link = '<a href="' . $this->redirect_uri . '">' . $this->__('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    function admin_postAction() {
        if (isset($_POST[$this->nonce_import]) && wp_verify_nonce($_POST[$this->nonce_import], $this->nonce_import . 'action')) {
            if (isset($_POST[$this->options['id'] . 'import-datas'])) {
                $this->admin_postAction_import();
            }
            else if (isset($_POST[$this->options['id'] . 'import-test'])) {
                $returnTest = $this->admin_postAction_importTest();
                $this->set_message('importtest_success', ($returnTest ? $this->__('The API works great.') : $this->__('The API do not work.')) , ($returnTest ? 'updated' : 'error'));
            }
            else if (isset($_POST[$this->options['id'] . 'enable-schedule'])) {
                wp_schedule_event(time() , 'hourly', 'wpu_display_instagram__cron_hook');
                $this->set_message('schedule_success', $this->__('The automatic import has been enabled.') , 'updated');
            }
            else if (isset($_POST[$this->options['id'] . 'disable-schedule'])) {
                wp_unschedule_event(wp_next_scheduled('wpu_display_instagram__cron_hook') , 'wpu_display_instagram__cron_hook');
                $this->set_message('schedule_success', $this->__('The automatic import has been disabled.') , 'updated');
            }
            wp_redirect($this->redirect_uri);
            exit();
        }
    }

    private function admin_postAction_importTest() {

        $_nb_items_test = 1;

        $_request = wp_remote_get(sprintf($this->request_url, $_nb_items_test));
        if (!is_array($_request) || !isset($_request['body'])) {
            return false;
        }

        $_json = json_decode($_request['body']);
        if (!is_object($_json)) {
            return false;
        }

        if (count($_json->data) != $_nb_items_test) {
            return false;
        }

        return true;
    }

    private function admin_postAction_import() {
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
    }

    function admin_page() {
        $_plugin_ok = true;
        $admin_link = admin_url('admin.php?page=wpuoptions-settings&tab=instagram_tab');
        $register_link = 'https://instagram.com/developer/clients/register/';
        $api_link = 'https://api.instagram.com/oauth/authorize/?client_id=' . $this->client_id . '&redirect_uri=' . urlencode($this->redirect_uri) . '&response_type=code';
        $latestimport = get_option('wpudisplayinstagram_latestimport');

        echo '<div class="wrap">';
        echo '<h1>' . get_admin_page_title() . '</h1>';

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

        if ($_plugin_ok) {
            if (is_numeric($latestimport)) {
                echo '<p>' . sprintf($this->__('Latest import : %s ago') , human_time_diff($latestimport)) . '.</p>';
            }
            else {
                echo '<p>' . $this->__('The plugin is configured !') . '</p>';
            }

            $schedule = wp_get_schedule('wpu_display_instagram__cron_hook');
            echo '<form action="' . $this->redirect_uri . '" method="post">';
            echo wp_nonce_field($this->nonce_import . 'action', $this->nonce_import);
            echo get_submit_button($this->__('Import now') , 'primary', $this->options['id'] . 'import-datas', false) . ' ';
            echo get_submit_button($this->__('Test import') , 'secondary', $this->options['id'] . 'import-test', false);
            if ($schedule !== false) {
                echo get_submit_button($this->__('Disable automatic import') , 'primary', $this->options['id'] . 'disable-schedule');
            }
            else {
                echo get_submit_button($this->__('Enable automatic import') , 'primary', $this->options['id'] . 'enable-schedule');
            }
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
                echo '<hr/><h3>' . $this->__('Latest imports') . '</h3><ul>';
                foreach ($wpq_instagram_posts as $id) {
                    echo '<li style="float: left;"><a href="' . get_edit_post_link($id) . '">' . get_the_post_thumbnail($id, 'thumbnail') . '</a></li>';
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
        $options['wpu_get_instagram__user_name'] = array(
            'label' => $this->__('User name') ,
            'box' => 'instagram_config'
        );
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
        $options['wpu_get_instagram__import_as_draft'] = array(
            'label' => $this->__('Import as draft') ,
            'type' => 'select',
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
        set_transient($this->transient_msg, $messages, 2 * MINUTE_IN_SECONDS);
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

    public function uninstall() {

        // Delete options
        $fields = $this->options_fields();
        foreach ($fields as $key => $field) {
            delete_option($key);
        }

        // Delete fields
        delete_post_meta_by_key('instagram_post_id');
        delete_post_meta_by_key('instagram_post_link');
        delete_post_meta_by_key('instagram_post_datas');
    }

}

$wpu_display_instagram = new wpu_display_instagram();

/* ----------------------------------------------------------
  Activation
---------------------------------------------------------- */

register_activation_hook(__FILE__, 'wpu_display_instagram__activation');
function wpu_display_instagram__activation() {
    $wpu_display_instagram = new wpu_display_instagram();
    $wpu_display_instagram->register_post_types();
    flush_rewrite_rules();
    wp_schedule_event(time() , 'hourly', 'wpu_display_instagram__cron_hook');
}

register_deactivation_hook(__FILE__, 'wpu_display_instagram__deactivation');
function wpu_display_instagram__deactivation() {
    $wpu_display_instagram = new wpu_display_instagram();
    $wpu_display_instagram->register_post_types();
    $timestamp = wp_next_scheduled( 'wpu_display_instagram__cron_hook' );
    wp_clear_scheduled_hook('wpu_display_instagram__cron_hook');
    wp_unschedule_event($timestamp , 'hourly', 'wpu_display_instagram__cron_hook');
    flush_rewrite_rules();
}

add_action('wpu_display_instagram__cron_hook', 'wpu_display_instagram__import');
function wpu_display_instagram__import() {
    $wpu_display_instagram = new wpu_display_instagram();
    $wpu_display_instagram->init_content(true);
    $wpu_display_instagram->import(true);
}

/* ----------------------------------------------------------
  Widget
---------------------------------------------------------- */

add_action('widgets_init', 'wpudisplayinstagram_register_widgets');
function wpudisplayinstagram_register_widgets() {
    register_widget('wpudisplayinstagram');
}

class wpudisplayinstagram extends WP_Widget {
    function __construct() {
        parent::__construct(false, '[WPU] Display Instagram', array(
            'description' => 'Display Instagram'
        ));
    }
    function form($instance) {
        load_plugin_textdomain('wpudisplayinstagram', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        $nb_items = is_numeric($instance['nb_items']) ? $instance['nb_items'] : 1; ?>
        <p>
        <label for="<?php echo $this->get_field_id('nb_items'); ?>"><?php _e('Number of pictures displayed:', 'wpudisplayinstagram'); ?></label>
        <input class="widefat" id="<?php echo $this->get_field_id('nb_items'); ?>" name="<?php echo $this->get_field_name('nb_items'); ?>" type="text" value="<?php echo esc_attr($nb_items); ?>">
        </p>
        <?php
    }
    function update($new_instance, $old_instance) {
        return array(
            'nb_items' => is_numeric($new_instance['nb_items']) ? $new_instance['nb_items'] : 1
        );
    }
    function widget($args, $instance) {
        $nb_items = isset($instance['nb_items']) && is_numeric($instance['nb_items']) ? $instance['nb_items'] : 5;
        global $wpu_display_instagram;
        echo $args['before_widget'];
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
                echo '<a class="instagram-link" target="_blank" href="' . get_post_meta(get_the_ID() , 'instagram_post_link', 1) . '">';
                the_post_thumbnail();
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }
        wp_reset_postdata();

        echo $args['after_widget'];
    }
}
