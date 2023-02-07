<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Account_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/account';
    }

   /**
   * Check permissions
   *
   * @param WP_REST_Request $request Current request.
   */
    public function permission_callback(\WP_REST_Request $request ) {
        if ( ! current_user_can( 'read' ) ) {
            return new \WP_Error( '403', esc_html__( 'You cannot view the post resource.' ), array( 'status' => 403 ) );
        }
    
        return true;
    }

    public function register_routes() {  
        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_account' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_account' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        
        register_rest_route( $this->namespace, $this->endpoint . '/invoice/settings' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_invoice_settings' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_account() {
        $user = wp_get_current_user();
        $metadata = array_map(fn($item) => $item[0], get_user_meta($user->ID));

        $json = array(
            "firstname" => $metadata['first_name'] ?? null,
            "lastname" => $metadata['last_name'] ?? null,
            "birthdate" => $metadata['birthdate'] ?? null,
            "email" => $user->data->user_email ?? null,
            "phoneNumber" => $metadata['billing_phone'] ?? null,
            "billingAddress" => $metadata['formatted_address'] ?? null,
            "siren" => $metadata['siren'] ?? null,
            "availabilities" => json_decode($metadata['availabilities'] ?? '[]'),
            "inputInterval" => $metadata['input_interval'] ?? null,
            "invoiceSettings" => [
                "tvaApplicable" => (boolean)$metadata['tva_applicable'] ?? null,
                "tvaNumber" => $metadata['tva_number'] ?? null,
                "siret" => $metadata['siret'] ?? null,
                "adeli" => $metadata['adeli'] ?? null,
                "paymentMethod" => $metadata['payment_method'] ?? null,
                "paymentDeadline" => $metadata['payment_deadline'] ?? null,
                "iban" => $metadata['iban'] ?? null,
            ]
        );

        return rest_ensure_response( $json );
    }

    public function post_account(\WP_REST_Request $request ) {
        $user = wp_get_current_user();
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'firstname' => 'required',
            'lastname' => 'required',
            'birthdate' => 'date',
            'email' => 'required|email',
            'phoneNumber' => 'required|digits:10',
            'billingAddress' => 'required',
            'siren' => 'required|numeric',
            'availabilities' => 'json',
            'inputInterval' => 'numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        // update the account
        update_user_meta( $user->ID, 'first_name', $data['firstname']);
        update_user_meta( $user->ID, 'last_name', $data['lastname']);
        update_user_meta( $user->ID, 'birthdate', $data['birthdate'] ?? null);
        update_user_meta( $user->ID, 'billing_phone', $data['phoneNumber']);
        update_user_meta( $user->ID, 'formatted_address', $data['billingAddress']);
        update_user_meta( $user->ID, 'siren', $data['siren']);
        update_user_meta( $user->ID, 'availabilities', $data['availabilities']);
        update_user_meta( $user->ID, 'input_interval', $data['inputInterval']);

        // update user email
        $user_id = wp_update_user( array( 'ID' => $user->ID, 'user_email' => $data['email'] ) );

        $success = $user_id > 0;
        return rest_ensure_response( ['success' => $success] );
    }

    public function post_invoice_settings(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "tvaApplicable" =>  'required',
            "siret" => 'required',
            "paymentMethod" => 'required',
            "paymentDeadline" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        update_user_meta( $user->ID, 'tva_applicable', $data['tvaApplicable']);
        update_user_meta( $user->ID, 'tva_number', $data['tvaNumber']);
        update_user_meta( $user->ID, 'siret', $data['siret']);
        update_user_meta( $user->ID, 'adeli', $data['adeli']);
        update_user_meta( $user->ID, 'payment_method', $data['paymentMethod']);
        update_user_meta( $user->ID, 'payment_deadline', $data['paymentDeadline']);
        update_user_meta( $user->ID, 'iban', $data['iban']);

        return rest_ensure_response( ['success' => true] );
    }

}
