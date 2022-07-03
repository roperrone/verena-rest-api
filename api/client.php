<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\vendor\autoload.php';
require_once __DIR__ . '\..\index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Client_Controller {

    public function __construct() {
        global $wpdb;

        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->client_table  = "{$wpdb->prefix}verena_clients";
        $this->endpoint      = '/client';
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
                'callback'  => array( $this, 'get_client' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_client' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'put_client' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'DELETE',
                'callback'  => array( $this, 'delete_client' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_client() {
        $user = wp_get_current_user();

        $consultations = $this->getConsultationsFromMember($user);
        $consultationsIds = implode(',', $consultations);

        global $wpdb;

        // List all appointments
        $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value IN ({$consultationsIds})", '_appointment_product_id');
        $appointments = $wpdb->get_results($query, ARRAY_A);

        $clientIds = [];
        $appointmentIds = [];
        $lastAppointmentList = [];

        // Get the client ids
        foreach($appointments as $appointment) {
            $post = get_post($appointment['post_id']);
            $metadata = get_post_meta($appointment['post_id'], '', '', true);

            $clientIds[$appointment['post_id']] = $metadata['_verena_customer_id'][0];

            if ( array_key_exists($metadata['_verena_customer_id'][0], $lastAppointmentList) )
                $lastAppointmentList[$metadata['_verena_customer_id'][0]] = $metadata['_appointment_start'][0];
            else if ( $lastAppointmentList[$metadata['_verena_customer_id'][0]] < $metadata['_appointment_start'][0]) 
                $lastAppointmentList[$metadata['_verena_customer_id'][0]] = $metadata['_appointment_start'][0];
        }

        // Retrieve the client information
        $clientIdsSQL = implode(',', array_values($clientIds));

        $query = $wpdb->prepare("SELECT DISTINCT * FROM {$this->client_table} WHERE id IN ({$clientIdsSQL})");
        $clients = $wpdb->get_results($query, ARRAY_A);

        $json = [];
        foreach($clients as $client) {
            $json[] = array(
                "clientId" => (int)$client['id'],
                "name" => $client['firstname']." ".$client['lastname'],
                "firstname" => $client['firstname'],
                "lastname" => $client['lastname'],
                "email" => $client['email'],
                "phone" => $client['phone'],
                "address" => $client['address'],
                "additionalInfos" => $client['additional_infos'],
                "lastAppointment" => $lastAppointmentList[$client['id']],
                "countAppointments"=> sizeof(array_filter($clientIds, fn($value, $key) => $value == $client['id'], ARRAY_FILTER_USE_BOTH))
            );
        }
        return rest_ensure_response( ['clients' => $json] );
    }

    public function post_client() {
        return rest_ensure_response( array() );
    }

    public function put_client() {
        return rest_ensure_response( array() );
    }

    public function delete_client() {
        return rest_ensure_response( array() );
    }

    protected function getConsultationsFromMember($member) : array {
         $query = array(
            'posts_per_page'   => -1,
            'post_type'        => 'product',
            'meta_key'         => '_wcfm_product_author',
            'meta_value'       =>  22,
        );
        $results = new \WP_Query( $query );
        $products = $results->posts;

        foreach($products as $product) {
            $appointmentIds[] = $product->ID;
        }

        return $appointmentIds;
    }
}