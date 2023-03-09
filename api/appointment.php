<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Ramsey\Uuid\Uuid;
use Rakit\Validation\Validator;

require_once __DIR__ . '/../index.php';

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

        // This route is called when a user deletes the appointment himself
        register_rest_route( $this->namespace, $this->endpoint . '/delete' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'delete_appointment_from_email_link' ),
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
            $json[] = $this->formatAppointment($appointment);
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
            "allDay" => 'boolean'
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
        $client = get_user_by('id', $data['clientId']);
        
        if( !$client ) {
            return new \WP_Error( '404', 'Invalid client id', array( 'status' => 404 ) );
        }

        $client_meta = array_map(fn($element) => $element[0], get_user_meta($client->ID));
        
        if( !array_key_exists( 'wcfm_vendor_id', $client_meta) || $client_meta['wcfm_vendor_id'] != $user->ID ) {
            return new \WP_Error( '403', 'Invalid client id', array( 'status' => 403 ) );
        }

        // Get the client address
        $address = array(
            'first_name' => $client_meta['first_name'],
            'last_name'  => $client_meta['last_name'],
            'email'      => $client_meta['billing_email'],
            'phone'      => $client_meta['billing_phone'],
            'address_1'  => $client_meta['billing_address_1'],
        );
      
        $order = wc_create_order();
      
        // Get the corresponding product
        $product   = new \WC_Product( $data['consultationId'] );
        
        $order->add_product( $product, 1);
        $order->set_address( $address, 'billing' );
        $order->set_payment_method('check');
        $order->calculate_totals();
        $order->update_status('Pending payment', 'Imported order', TRUE);
        $order_id = $order->save();

        $appointment = new \WC_Appointment();
        $appointment->set_order_id($order_id);
        $appointment->set_customer_id($data['clientId']);
        $appointment->set_timezone('Europe/Paris');
        $appointment->set_start($data['timeStart']);
        $appointment->set_end($data['timeEnd']);
        $appointment->set_product_id($data['consultationId']);
        $appointment->set_status('confirmed');
        $appointment->set_staff_id($user->ID);

        if ($data['allDay']) {
            $appointment->set_all_day($data['allDay']);
        }

        $appointmentId = $appointment->save();

        // Create delete token
        $deleteToken = Uuid::uuid4()->toString();
        update_post_meta($appointmentId, 'appointment_delete_token', $deleteToken);

        $success = ($order_id > 0 && $appointmentId > 0);

        // Sync to GCal
        $wc_appointment = wc_appointments_integration_gcal();
        $wc_appointment->sync_to_gcal( $appointmentId );

        $json =  $this->formatAppointment($appointment);
        return rest_ensure_response(['success' => $success, 'appointment' => $json ]);
    }

    public function put_appointment(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "appointmentId" => 'required|numeric',
            "consultationId" => 'required|numeric',
            "status" => 'required',
            "allDay" => 'boolean',
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

        if( ($data['timeStart'] && !$data['timeEnd']) || (!$data['timeStart'] && $data['timeEnd'])) {
            return new \WP_Error( '400', 'Invalid time range. Both timeStart and timeEnd should be provided.', array( 'status' => 400 ) );
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

        $prevStartDate = $appointment->get_start_date();
        $prevEndDate = $appointment->get_end_date();

        if ($data['timeStart']) {
            $appointment->set_start($data['timeStart']);
        }

        if ($data['timeEnd']) {
            $appointment->set_end($data['timeEnd']);
        }

        if ($data['allDay']) {
            $appointment->set_all_day($data['allDay']);
        }

        $appointmentId = $appointment->save();
        $success = $appointmentId > 0;

        if( $data['timeStart'] && $data['timeEnd'] ) {
            // Send the rescheduled email
            $email = WC()->mailer()->get_emails()['WC_Email_Appointment_Rescheduled'];
            $email->trigger( $appointment->ID, $prevStartDate, $prevEndDate );
        }
        
        $json =  $this->formatAppointment($appointment);
        return rest_ensure_response(['success' => $success, 'appointment' => $json ]);
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

        // Remove from GCal
        $wc_appointment = wc_appointments_integration_gcal();
        $wc_appointment->set_user_id( $user->ID );
        $wc_appointment->remove_from_gcal( $appointment->ID, $user->ID );

        $appointment->set_status('cancelled');
        $appointmentId = $appointment->save();

        $success = $appointmentId > 0;

        return rest_ensure_response( ['success' => $success] );
    }

    public function delete_appointment_from_email_link(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "appointmentId" => 'required|numeric',
            "token" => 'required',
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

        $appointmentDeleteToken = get_post_meta($appointment->get_id(), 'appointment_delete_token', true);
        
        if( $data['token'] != $appointmentDeleteToken) {
            return new \WP_Error( '403', 'Invalid token', array( 'status' => 403 ) );
        }

        if($appointment->get_status() == 'cancelled') {
            return new \WP_Error( '422', 'This appointment has already been deleted', array( 'status' => 422 ) );
        }

        $appointment_metadata = array_map(fn($item) => $item[0], get_post_meta($appointment->ID));
        $staffId = (int)$appointment_metadata['_appointment_staff_id'];

        // Remove from GCal
        $wc_appointment = wc_appointments_integration_gcal();
        $wc_appointment->set_user_id( $staffId );
        $wc_appointment->remove_from_gcal( $appointment->ID, $staffId );

        $appointment->set_status('cancelled');
        $appointmentId = $appointment->save();

        $success = $appointmentId > 0;

        if ($success) {
            \Verena_Notifications_Helper::add_notification(
                sprintf("Un RDV (#%s) vient d'être supprimé", $data['appointmentId']), 0, $staffId
            );
        }

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

   protected function formatAppointment($appointment): array {
        $dateStart = new \DateTime('@'.$appointment->get_start(false));
        $dateStop = new \DateTime('@'.$appointment->get_end(false));

        // Get video conference URL
        $videoconferenceUrl = get_post_meta($appointment->get_id(), 'google_calendar_event_details', true);

        return array(
            "appointmentId" => $appointment->get_id(),
            "clientId" => $appointment->get_customer_id(),
            "consultationTypeId" => $appointment->get_product_id(),
            "timeStart" => $dateStart->format("Y-m-d\TH:i:s"),
            "timeEnd" => $dateStop->format("Y-m-d\TH:i:s"),
            "status" => $appointment->get_status(),
            "videoconferenceUrl" => !empty($videoconferenceUrl) ? $videoconferenceUrl : null,
        );
   }
}
