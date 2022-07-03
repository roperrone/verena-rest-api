<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\vendor\autoload.php';
require_once __DIR__ . '\..\index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Invoice_Controller {

    public function __construct() {
        global $wpdb;

        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->invoice_table = "{$wpdb->prefix}verena_invoice";
        $this->endpoint      = '/invoice';
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
                'callback'  => array( $this, 'get_invoice' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/list' , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_invoice_list' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_invoice' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'put_invoice' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_invoice(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'id' => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        $query = $wpdb->prepare("SELECT * FROM {$this->invoice_table} WHERE member_id = %d AND id = %d LIMIT 1", $user->ID, $data['id']);
        $invoices = $wpdb->get_results($query, ARRAY_A);

        if( empty($invoices) ) {
            return rest_ensure_response( (object)[] );
        }

        $invoice = $this->format_invoice($invoices[0]);
        return rest_ensure_response($invoice);
    }

    public function get_invoice_list() {
        global $wpdb;
        $user = wp_get_current_user();

        $query = $wpdb->prepare("SELECT * FROM {$this->invoice_table} WHERE member_id = %d", $user->ID);
        $invoices = $wpdb->get_results($query, ARRAY_A);

        if( empty($invoices) ) {
            return rest_ensure_response( ["invoices" => []] );
        }

        $json = [];
        foreach($invoices as $invoice) {
            $json[] = $this->format_invoice($invoice);
        }

        return rest_ensure_response(["invoices" => $json]);
    }

    public function post_invoice(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "clientId" =>  'required|numeric',
            "consultationId" => 'required|numeric',
            "email" => 'required|email',
            "consultationDetails" => 'required',
            "status" => 'required|numeric',
            "price" => 'required|numeric',
            "time" =>  'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        $query = $wpdb->prepare("
            INSERT INTO {$this->invoice_table} (member_id, client_id, consultation_id, email, consultation_details, invoice_status, price, date) 
            VALUES (%d, %d, %d, %s, %s, %d, %d, %s)", $user->ID, $data['clientId'], $data['consultationId'], $data['email'], $data['consultationDetails'], $data['invoiceStatus'], $data['price'], $data['time'],
        );
        $success = $wpdb->query($query);
        return rest_ensure_response(['success' => (boolean)$success]);
    }

    public function put_invoice(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'invoiceId' => 'numeric',
            "email" => 'required|email',
            "consultationDetails" => 'required',
            "price" => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        $query = $wpdb->prepare("SELECT * FROM {$this->invoice_table} WHERE member_id = %d AND id = %d", $user->ID, $data['invoiceId'] );
        $invoices = $wpdb->get_results($query, ARRAY_A);

        if( empty($invoices) ) {
            return new \WP_Error( '403', 'This invoice doesn\'t exist or its access is restricted', array( 'status' => 403 ) );
        }

        // Update:
        $query = $wpdb->prepare("
            UPDATE {$this->invoice_table} 
            SET email = %s, consultation_details = %s, price = %d
            WHERE id = %d", $data['email'], $data['consultationDetails'], $data['price'], $data['invoiceId']
        );
        $success = $wpdb->query($query);
        return rest_ensure_response(['success' => (boolean)$success]);
    }

    protected function format_invoice($invoice) {
        $date = new \DateTime($invoice['time']);
        $date->setTimezone(new \DateTimeZone('Europe/Paris'));

        return array(
            "invoiceId" => (int)$invoice['id'],
            "clientId" => (int)$invoice['client_id'],
            "consultationId" => (int)$invoice['consultation_id'],
            "email" => $invoice['email'],
            "consultationDetails" => $invoice['consultation_details'],
            "status" => (int)$invoice['invoice_status'],
            "price" => (int)$invoice['price'],
            "time" =>  $date->format(\DateTime::W3C),
        );
    }
}