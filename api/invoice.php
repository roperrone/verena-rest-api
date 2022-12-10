<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

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
            "email" => 'required|email',
            "invoiceLineItems" => 'required',
            "time" =>  'required',
            "tvaApplicable" => 'required',
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

        $invoice_number = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(invoice_number) + 1 FROM {$this->invoice_table} WHERE member_id = %d", $user->ID ), ARRAY_A);
        $invoice_number = array_shift($invoice_number) ?? 1;

        global $wpdb;

        $price = 0;
        $invoice_data = array(
            "items" => $data['invoiceLineItems'],
            "settings" => array(
                "tva_applicable" => $data['tvaApplicable'],
                "tva_number" => $data['tvaNumber'] ?? null,
                "siret" => $data['siret'],
                "adeli" => $data['adeli'] ?? null,
                "payment_method" => $data['paymentMethod'],
                "payment_deadline" => $data['paymentDeadline'],
                "iban" => $data['iban'] ?? null,
            )
        );

        foreach($data['invoiceLineItems'] as $item) {
            $price += (double)$item['price'] ?? 0;
        }

        // Save the link in our database
        $wpdb->insert( $this->invoice_table, array(
            "invoice_number" => $invoice_number,
            "member_id" => $user->ID,
            "client_id" =>  $data['clientId'],
            "email" => $data['email'],
            "data" => json_encode($invoice_data),
            "price" => $price,
            "date" => $data['time'],
        ), 
            array('%d', '%d', '%d', '%s', '%s', '%d', '%s')
        );

        return rest_ensure_response(['success' => true]);
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
        $date = new \DateTime($invoice['date'], new \DateTimeZone('Europe/Paris'));
        $invoice_data = json_decode($invoice['data'], TRUE);
        $invoice_items = array_map(
            fn($el) => array('name' => $el['name'], 'price' => $el['price']. ' €'), 
            $invoice_data['items']
        );

        return array(
            "invoiceId" => (int)$invoice['id'],
            "invoiceNumber" => $invoice['invoice_number'] ? (int)$invoice['invoice_number'] : null,
            "clientId" => (int)$invoice['client_id'],
            "email" => $invoice['email'],
            "data" => array(
                'items' => $invoice_items,
                'settings' => $invoice_data['settings']
            ),
            "price_ht" => $invoice['price']." €",
            "tva" => $invoice_data['settings']['tva_applicable'] ? (double)($invoice['price']*0.20) . " €" : "0 €",
            "price_ttc" => $invoice_data['settings']['tva_applicable'] ? (double)($invoice['price']*1.20) . " €" : $invoice['price']." €",
            "time" =>  $date->format("Y-m-d\TH:i:s"),
        );
    }
}
