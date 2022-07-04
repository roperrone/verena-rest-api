<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\index.php';

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Appointment_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/appointment';
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
                'callback'  => array( $this, 'get_appointment' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_appointment' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'put_appointment' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'DELETE',
                'callback'  => array( $this, 'delete_appointment' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_appointment() {
        $user = wp_get_current_user();

        $consultations = $this->getConsultationsFromMember($user);
        $consultationsIds = implode(',', $consultations);

        global $wpdb;

        // List all appointments
        $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value IN ({$consultationsIds})", '_appointment_product_id');
        $appointments = $wpdb->get_results($query, ARRAY_A);

        $appointments = array_map(fn($appointment) => get_wc_appointment($appointment['post_id']), $appointments);

        $json = [];
        foreach($appointments as $appointment) {
            $dateStart = new \DateTime();
            $dateStart->setTimestamp($appointment->get_start(false));
            $dateStart->setTimezone(new \DateTimeZone('Europe/Paris'));
    
            $dateStop = new \DateTime();
            $dateStop->setTimestamp($appointment->get_end(false));
            $dateStop->setTimezone(new \DateTimeZone('Europe/Paris'));

            $json[] = array(
                "appointmentId" => $appointment->get_id(),
                "clientId" => $appointment->get_customer_id(),
                "consultationTypeId" => $appointment->get_product_id(),
                "timeStart" => $dateStart->format(\DateTime::W3C),
                "timeEnd" => $dateStop->format(\DateTime::W3C),
                "status" => $appointment->get_status(),
                "videoconferenceUrl" => "https://zoom.us/ef02ace96", // TODO: update this link
            );
        }

        return rest_ensure_response( $json );
    }

    public function post_appointment() {
        return rest_ensure_response( array() );
    }

    public function put_appointment() {
        return rest_ensure_response( array() );
    }

    public function delete_appointment() {
        return rest_ensure_response( array() );
    }

    protected function getConsultationsFromMember($member) : array {
        $query = array(
           'posts_per_page'   => -1,
           'post_type'        => 'product',
           'meta_key'         => '_wcfm_product_author',
           'meta_value'       =>  $member->ID,
       );
       $results = new \WP_Query( $query );
       $products = $results->posts;

       foreach($products as $product) {
           $consultationsIds[] = $product->ID;
       }

       return $consultationsIds;
   }
}
