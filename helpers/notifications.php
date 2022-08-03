<?php
class Verena_Notifications_Helper {

    static function add_notification($message, $type_id = 0) {
        global $wpdb;
        $notification_table = "{$wpdb->prefix}verena_notifications";

        $user = wp_get_current_user();

        $query = $wpdb->prepare("INSERT INTO {$notification_table} (notifications_type_id, member_id, message) VALUES (%d, %d, %s)", $type_id, $user->ID, $message);
        $wpdb->query($query);
    }
}

?>