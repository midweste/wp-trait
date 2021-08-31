<?php

namespace WPTrait\Has;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!trait_exists('HasNotice')) {

    trait HasNotice
    {

        public function register_notice()
        {
            // Admin Page Notice
            $this->add_action('admin_notices', 'admin_notices');
        }

        public function admin_notices()
        {

        }

        public function add_alert($text, $model = "success", $close_button = true, $echo = true, $style_extra = 'padding: 12px;')
        {
            $content = '<div class="notice notice-' . $model . '' . ($close_button === true ? " is-dismissible" : "") . '">';
            $content .= '<div style="' . $style_extra . '">' . $text . '</div>';
            $content .= '</div>';
            if ($echo) {
                echo $content;
            } else {
                return $content;
            }
        }

        public function remove_query_arg_url($args = array())
        {
            $_SERVER['REQUEST_URI'] = remove_query_arg($args);
        }

        public function inline_admin_notice($alert, $page_url_args = array(), $priority = 10)
        {
            $this->remove_query_arg_url($page_url_args);
            add_action('admin_notices', function () use ($alert) {
                echo $alert;
            }, $priority);
        }
    }

}