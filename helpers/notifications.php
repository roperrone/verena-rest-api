<?php
class Verena_Notifications_Helper {

    static function add_notification($message, $type_id = 0, $userId = null) {
        global $wpdb;
        $notification_table = "{$wpdb->prefix}verena_notifications";

        if (!$userId) {
            $user = wp_get_current_user();
            $userId = $user->ID;
        }

        $query = $wpdb->prepare("INSERT INTO {$notification_table} (notifications_type_id, member_id, message) VALUES (%d, %d, %s)", $type_id, $userId, $message);
        $wpdb->query($query);
    }
}

?>