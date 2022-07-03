<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\vendor\autoload.php';
require_once __DIR__ . '\..\index.php';

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
            'birthdate' => 'required|date',
            'email' => 'required|email',
            'phoneNumber' => 'required|digits:10',
            'billingAddress' => 'required',
            'siren' => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        // update the account
        update_user_meta( $user->ID, 'first_name', $data['firstname']);
        update_user_meta( $user->ID, 'last_name', $data['lastname']);
        update_user_meta( $user->ID, 'birthdate', $data['birthdate']);
        update_user_meta( $user->ID, 'billing_phone', $data['phoneNumber']);
        update_user_meta( $user->ID, 'formatted_address', $data['billingAddress']);
        update_user_meta( $user->ID, 'siren', $data['siren']);

        // update user email
        wp_update_user( array( 'ID' => $user->ID, 'user_email' => $data['email'] ) );

        return rest_ensure_response( (object)[] );
    }
}