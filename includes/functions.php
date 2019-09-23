<?php

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
