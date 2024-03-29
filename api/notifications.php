<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../index.php';

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Notification_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/notification';
    }

   /**
   * Check permissions
   *
   * @param WP_REST_Request $request Current request.
   */
    public function permission_callback( $request ) {
        if ( ! current_user_can( 'read' ) ) {
            return new \WP_Error( 'rest_forbidden', esc_html__( 'You cannot view the post resource.' ), array( 'status' => 403 ) );
        }
    
        return true;
    }

    public function register_routes() {  
        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_notifications' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_seen_notifications' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_notifications() {
        global $wpdb;
        $notification_table = "{$wpdb->prefix}verena_notifications";

        $user = wp_get_current_user();
        $query = $wpdb->prepare("SELECT * FROM {$notification_table} WHERE member_id = %d ORDER BY time DESC", $user->ID);

        $notifications = $wpdb->get_results($query, ARRAY_A);

        if(!$notifications) {
            rest_ensure_response( ["notifications" => []] );
        }

        $json = [];
        foreach($notifications as $notification) {
            $date = new \DateTime('@'.$notification['time']);
            $date->setTimezone(new \DateTimeZone('Europe/Paris'));

            $json[] = array(
                "id" => (int)$notification['id'],
                "type" => (int)$notification['notifications_type_id'],
                "message" => $notification['message'],
                "read" => (boolean)$notification['seen'] ?? false,
                "url" => $notification['url'],
                "time" => $date->format("Y-m-d\TH:i:s"),
            );
        }

        return rest_ensure_response(["notifications" => $json]);
    }

    public function post_seen_notifications() {
        global $wpdb;
        $notification_table = "{$wpdb->prefix}verena_notifications";

        $user = wp_get_current_user();
        $query = $wpdb->prepare("UPDATE {$notification_table} SET seen = %d WHERE member_id = %d", 1, $user->ID);
        
        $wpdb->query($query);

        return rest_ensure_response(["success" => true]);
    }
}
