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
        $user = wp_get_current_user();

        $clients = get_users(array(
            'meta_key' => 'wcfm_vendor_id',
            'meta_value' => $user->ID,
        ));

        $json = [];
        foreach($clients as $client) {
            $client_meta = array_map(fn($element) => $element[0], get_user_meta($client->ID));

            $date = new \DateTime('now');
            $date->setTimezone(new \DateTimeZone('Europe/Paris'));

            $json[] = array(
                "clientId" => (int)$client->ID,
                "name" => $client_meta['first_name']." ".$client_meta['last_name'],
                "firstname" => $client_meta['first_name'],
                "lastname" => $client_meta['last_name'],
                "email" => $client_meta['billing_email'],
                "phone" => $client_meta['billing_phone'],
                "address" => $client_meta['billing_address_1'],
                "additionalInfos" => $client_meta['additional_infos'],
                "lastAppointment" => $date->format(\DateTime::W3C),
                "countAppointments"=> 0,
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
            "phone" => 'required|numeric',
            "address" => 'required',
            "additionalInfos" =>  'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $vendor = wp_get_current_user();
        
        \Verena_Notifications_Helper::add_notification('Test');
        
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
        update_user_meta( $user_id, 'billing_phone', $data['phone']);
        update_user_meta( $user_id, 'billing_address_1', $data['address']);
        update_user_meta( $user_id, 'additional_infos', $data['additionalInfos']);
        update_user_meta( $user_id, 'hide_admin_area', 1);
        update_user_meta( $user_id, 'wp_capabilities', ['customer' => true]);
        update_user_meta( $user_id, 'wcfm_vendor_id', $vendor->ID);    

        $success = $user_id > 0;
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
            "phone" => 'required|numeric',
            "address" => 'required',
            "additionalInfos" =>  'required',
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
        
        if( !array_key_exists( 'wcfm_vendor_id', $client_meta) || $client_meta['wcfm_vendor_id'] != $vendor->ID ) {
            return new \WP_Error( '403', 'This client doesn\'t exist or cannot be updated', array( 'status' => 403 ) );
        }

        // update the account
        update_user_meta( $client->ID, 'first_name', $data['firstname']);
        update_user_meta( $client->ID, 'last_name', $data['lastname']);
        update_user_meta( $client->ID, 'billing_email', $data['email']);
        update_user_meta( $client->ID, 'billing_phone', $data['phone']);
        update_user_meta( $client->ID, 'billing_address_1', $data['address']);
        update_user_meta( $client->ID, 'additional_infos', $data['additionalInfos']);

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

        $vendor = wp_get_current_user();
        $client = get_user_by('id', $data['clientId']);

        if( !$client ) {
            return new \WP_Error( '403', 'This client doesn\'t exist or cannot be updated', array( 'status' => 403 ) );
        }

        $success = wp_delete_user($client->ID);

        // TODO: Only allow deletion if no order is attached to this client
        return rest_ensure_response( ['success' => $success] );
    }
}
