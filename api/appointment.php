<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\vendor\autoload.php';
require_once __DIR__ . '\..\index.php';

use Rakit\Validation\Validator;

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

        return rest_ensure_response( ['appointments' => $json] );
    }

    public function post_appointment(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "clientId" => 'required|numeric',
            "consultationId" => 'required|numeric',
            "timeStart" => 'required',
            "timeEnd" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        // Check if this consultation belongs to our user
        $consultations = $this->getConsultationsFromMember($user);
        if( !in_array($data['consultationId'], $consultations) ) {
            return new \WP_Error( '403', 'Invalid consultation id', array( 'status' => 403 ) );
        }

        global $wpdb;

        // Check if the client belongs to our user
        $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}verena_clients WHERE created_by = %d AND id = %d LIMIT 1", $user->ID, $data['clientId'] );
        $client = $wpdb->get_results($query, ARRAY_A);

        if( empty($client) ) {
            return new \WP_Error( '403', 'Invalid client id', array( 'status' => 403 ) );
        }

        $client = $client[0];

        // We first need to create a Woocommerce order
        global $woocommerce;

        // Get the client address
        $address = array(
            'first_name' => $client['firstname'],
            'last_name'  => $client['lastname'],
            'email'      => $client['email'],
            'phone'      => $client['phone'],
            'address_1'  => $client['address'],
        );
      
        $order = wc_create_order();
      
        // Get the corresponding product
        $product   = new \WC_Product( $data['consultationId'] );
        
        $order->add_product( $product, 1);
        $order->set_address( $address, 'billing' );
        $order->set_payment_method('check');//
        $order->calculate_totals();
        $order->update_status('Pending payment', 'Imported order', TRUE);
        $order_id = $order->save();

        $appointment = new \WC_Appointment();
        $appointment->set_order_id($order_id);
        $appointment->set_customer_id($data['clientId']);
        $appointment->set_start($data['timeStart']);
        $appointment->set_end($data['timeEnd']);
        $appointment->set_product_id($data['consultationId']);
        $appointment->set_status('pending-confirmation');
        $appointmentId = $appointment->save();

        $success = ($order_id > 0 && $appointmentId > 0);
        return rest_ensure_response(['success' => $success]);
    }

    public function put_appointment(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "appointmentId" => 'required|numeric',
            "consultationId" => 'required|numeric',
            "status" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        try {
            $appointment = new \WC_Appointment($data['appointmentId']);
        } catch( \Exception $e) {
            return new \WP_Error( '404', 'This appointment doesn\'t exist', array( 'status' => 404 ) );
        }

        $consultationId = $appointment->get_product()->get_id();

        $consultations = $this->getConsultationsFromMember($user);
        if( !in_array($consultationId, $consultations) ) {
            return new \WP_Error( '403', 'You can\'t update this appointment', array( 'status' => 403 ) );
        }

        if( !in_array($data['consultationId'], $consultations) ) {
            return new \WP_Error( '403', 'This consultation id does\'t belong to this user', array( 'status' => 403 ) );
        }

        // update the consultation id
        $appointment->set_product_id($data['consultationId']);

        switch($data['status']) {
            case 'pending-confirmation':
                $appointment->set_status('pending-confirmation');
                break;
            case 'confirmed':
                $appointment->set_status('confirmed');
                break;
            case 'complete':
                $appointment->set_status('complete');
                break;
            default:
                return new \WP_Error( '400', 'Invalid status', array( 'status' => 403 ) );
        }

        $appointmentId = $appointment->save();
        $success = $appointmentId > 0;

        return rest_ensure_response(['success' => $success]);
    }

    public function delete_appointment(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "appointmentId" => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        try {
            $appointment = new \WC_Appointment($data['appointmentId']);
        } catch( \Exception $e) {
            return new \WP_Error( '404', 'This appointment doesn\'t exist', array( 'status' => 404 ) );
        }

        $consultationId = $appointment->get_product()->get_id();

        $consultations = $this->getConsultationsFromMember($user);
        if( !in_array($consultationId, $consultations) ) {
            return new \WP_Error( '403', 'You can\'t delete this appointment', array( 'status' => 403 ) );
        }

        $success = $appointment->delete(true);
        return rest_ensure_response( ['success' => $success] );
    }

    protected function getConsultationsFromMember($member): array {
        $query = array(
           'posts_per_page'   => -1,
           'post_type'        => 'product',
           'meta_key'         => '_wcfm_product_author',
           'meta_value'       =>  $member->ID,
       );
       $results = new \WP_Query( $query );
       $products = $results->posts;

       $consultationsIds = [];

       foreach($products as $product) {
           $consultationsIds[] = $product->ID;
       }

       return $consultationsIds;
   }
}
