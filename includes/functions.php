<?php

if (!function_exists('esc_sql_underscores')) {
    /**
     * "_" means "any character" in SQL "... LIKE batch_%". Transform the
     * wildcard into a common character.
     *
     * @param string $pattern
     * @return string
     */
    function esc_sql_underscores($pattern)
    {
        return str_replace('_', '\_', $pattern);
    }
}

if (!function_exists('get_uncached_option')) {
    /**
     * @param string $option Option name.
     * @param mixed $default Optional. FALSE by default.
     * @return mixed Option value or default value.
     *
     * @global \wpdb $wpdb
     */
    function get_uncached_option($option, $default = false)
    {
        global $wpdb;

        // The code partly from function get_option()
        $suppressStatus = $wpdb->suppress_errors(); // Set to suppress errors and
                                                    // save the previous value

        $query = $wpdb->prepare("SELECT `option_value` FROM {$wpdb->options} WHERE `option_name` = %s LIMIT 1", $option);
        $row   = $wpdb->get_row($query);

        $wpdb->suppress_errors($suppressStatus);

        if (is_object($row)) {
            return maybe_unserialize($row->option_value);
        } else {
            return $default;
        }
    }
}

if (!function_exists('wp_doing_ajax')) {
    /**
     * @return bool
     *
     * @since 1.0
     * @since WordPress 4.7
     */
    function wp_doing_ajax()
    {
        return apply_filters('wp_doing_ajax', defined('DOING_AJAX') && DOING_AJAX);
    }
}

if (!function_exists('wp_doing_cron')) {
    /**
     * @return bool
     *
     * @since 1.0
     * @since WordPress 4.8
     */
    function wp_doing_cron()
    {
        return apply_filters('wp_doing_cron', defined('DOING_CRON') && DOING_CRON);
    }
}
