<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../helpers/notifications.php';
require_once __DIR__ . '/../index.php';

use Ramsey\Uuid\Uuid;
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
        global $wpdb;
        $user = wp_get_current_user();

        $clients = get_users(array(
            'meta_key' => 'wcfm_vendor_id',
            'meta_value' => $user->ID,
        ));

        $json = [];

        foreach($clients as $client) {
            $client_meta = array_map(fn($element) => $element[0], get_user_meta($client->ID));

            // List all appointments
            $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %d", '_appointment_customer_id', $client->ID);
            $appointments = $wpdb->get_results($query, ARRAY_A);

            $lastAppointments = null;
            $countAppointments = 0;

            if($appointments) {                
                $appointments = array_map(fn($appointment) => get_wc_appointment($appointment['post_id']), $appointments);
                usort($appointments, function($a, $b) {
                    if( $a->get_start(false) == $b->get_start(false)) {
                        return 0;
                    }
                    
                    return ($a->get_start(false) > $b->get_start(false)) ? -1 : 1;
                });

                $lastAppointments = new \DateTime('@'.$appointments[0]->get_start(false));
                $lastAppointments = str_replace('+00:00', '+02:00', $lastAppointments->format(\DateTime::W3C));
                $countAppointments = sizeof($appointments);
            }

            $json[] = array(
                "clientId" => (int)$client->ID,
                "name" => $client_meta['first_name']." ".$client_meta['last_name'],
                "firstname" => $client_meta['first_name'],
                "lastname" => $client_meta['last_name'],
                "email" => $client_meta['billing_email'],
                "phone" => $client_meta['billing_phone'],
                "address" => $client_meta['billing_address_1'],
                "additionalInfos" => $client_meta['additional_infos'],
                "lastAppointment" => $lastAppointments,
                "countAppointments"=> $countAppointments,
                "deleted" => $client_meta['is_deleted'] ?? false,
            );
        }
        return rest_ensure_response( ['clients' => $json] );
    }

    public function post_client(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "firstname" => 'required',
            "lastname" => 'required',
            "email" => 'required|email',
            "address" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $vendor = wp_get_current_user();
                
        $username = Uuid::uuid4()->toString();

        // generate a random password
        $password = bin2hex(openssl_random_pseudo_bytes(10));
        $user_id = wp_insert_user(
            array(
                'user_login' => $username, 
                'user_pass' => $password,
                'display_name' => $data['firstname'].' '.$data['lastname'],
                'nickname' => $data['firstname'].' '.$data['lastname'],
            )
        );

        // update the account
        update_user_meta( $user_id, 'first_name', $data['firstname']);
        update_user_meta( $user_id, 'last_name', $data['lastname']);
        update_user_meta( $user_id, 'billing_email', $data['email']);
        update_user_meta( $user_id, 'billing_phone', $data['phone'] ?? '');
        update_user_meta( $user_id, 'billing_address_1', $data['address']);
        update_user_meta( $user_id, 'additional_infos', $data['additionalInfos'] ?? '');
        update_user_meta( $user_id, 'hide_admin_area', 1);
        update_user_meta( $user_id, 'wp_capabilities', ['customer' => true]);
        update_user_meta( $user_id, 'wcfm_vendor_id', $vendor->ID);    

        $success = $user_id > 0;

        if ( $success ) {
            \Verena_Notifications_Helper::add_notification(
                sprintf("%s %s. vient d'être ajouté comme client", ucwords($data['firstname']), $data['lastname'][0])
            );
        }

        return rest_ensure_response(['success' => $success]);
    }

    public function put_client(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "clientId" => 'required|numeric',
            "firstname" => 'required',
            "lastname" => 'required',
            "email" => 'required|email',
            "address" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $vendor = wp_get_current_user();
        $client = get_user_by('id', $data['clientId']);

        if( !$client ) {
            return new \WP_Error( '403', 'This client doesn\'t exist or cannot be updated', array( 'status' => 403 ) );
        }

        $client_meta = array_map(fn($element) => $element[0], get_user_meta($client->ID));
        
        if( !array_key_exists( 'wcfm_vendor_id', $client_meta) 
            || $client_meta['wcfm_vendor_id'] != $vendor->ID 
            || $client_meta['is_deleted']
        ) {
            return new \WP_Error( '403', 'This client doesn\'t exist or cannot be updated', array( 'status' => 403 ) );
        }

        // update the account
        update_user_meta( $client->ID, 'first_name', $data['firstname']);
        update_user_meta( $client->ID, 'last_name', $data['lastname']);
        update_user_meta( $client->ID, 'billing_email', $data['email']);
        update_user_meta( $client->ID, 'billing_phone', $data['phone'] ?? '');
        update_user_meta( $client->ID, 'billing_address_1', $data['address']);
        update_user_meta( $client->ID, 'additional_infos', $data['additionalInfos'] ?? '');

        $user_id = wp_update_user(
            array(
                'ID' => $client->ID,
                'display_name' => $data['firstname'].' '.$data['lastname'],
                'nickname' => $data['firstname'].' '.$data['lastname'],
            )
        );

        $success = $user_id > 0;
        return rest_ensure_response(['success' => $success]);
    }

    public function delete_client(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "clientId" => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $client = get_user_by('id', $data['clientId']);

        if( !$client ) {
            return new \WP_Error( '403', 'This client doesn\'t exist or cannot be updated', array( 'status' => 403 ) );
        }

        // update the account
        $success = update_user_meta( $client->ID, 'is_deleted', true);

        if ($success) {
            \Verena_Notifications_Helper::add_notification(
                sprintf("Une fiche client (#%s) vient d'être supprimée", $data['clientId'])
            );
        }
        
        // TODO: Only allow deletion if no order is attached to this client
        return rest_ensure_response( ['success' => $success] );
    }
}
