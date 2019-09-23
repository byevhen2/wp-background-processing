<?php

if (!function_exists('wp_doing_ajax')) {
    /**
     * @since WordPress 4.7
     *
     * @return bool
     */
    function wp_doing_ajax()
    {
        return apply_filters('wp_doing_ajax', defined('DOING_AJAX') && DOING_AJAX);
    }
}
