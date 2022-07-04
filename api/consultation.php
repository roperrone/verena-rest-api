<?php

namespace VerenaRestApi;

require_once __DIR__ . '\..\vendor\autoload.php';
require_once __DIR__ . '\..\index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Consultation_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/consultation';
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
                'callback'  => array( $this, 'get_consultation' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_consultation' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'put_consultation' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'DELETE',
                'callback'  => array( $this, 'delete_consultation' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_consultation() {
        $user = wp_get_current_user();

        $query = array(
            'posts_per_page'   => -1,
            'post_type'        => 'product',
            'meta_key'         => '_wcfm_product_author',
            'meta_value'       =>  22, // TODO: replace with $user->ID
        );
        $results = new \WP_Query( $query );
        $products = $results->posts;

        $productIds = implode(',', array_map(fn($product) => $product->ID, $products));
        
        global $wpdb;
        $query = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}wc_appointments_availability WHERE kind = %s AND kind_id IN ({$productIds})", 'availability#product');
        $availability = $wpdb->get_results($query, ARRAY_A);

        $json = [];
        foreach($products as $product) {
            $metadata = array_map(fn($item) => $item[0], get_post_meta($product->ID));
            $productAvailability = array_filter($availability, fn($v, $k) => $v['kind_id'] == $product->ID, ARRAY_FILTER_USE_BOTH);
            
            $fromRange = '';
            $toRange = '';

            for($i=1; $i <= 7; $i++) {
                $isAvailable[$i] = false;
                $filter = array_filter($productAvailability, fn($v, $k) => $v['range_type'] == 'time:'.$i, ARRAY_FILTER_USE_BOTH); 

                if(!empty($filter)) {
                    $isAvailable[$i] = array_values($filter)[0]['appointable'] == 'yes';

                    // This timeslot is appointable. Get the from/to range
                    if ($isAvailable[$i]) {
                        $fromRange = array_values($filter)[0]['from_range'];
                        $toRange = array_values($filter)[0]['to_range'];
                    }
                }
            }

            $json[] = array(
                "consultationId" => $product->ID,
                "name" => $product->post_title,
                "price" => $metadata['_price'],
                "duration" => $metadata['_wc_appointment_duration'],
                "location" => $metadata['product_address'],
                "description" => $product->post_content,
                "availability"=> array(
                    "Mo" => $isAvailable[1],
                    "Tu" => $isAvailable[2],
                    "We" => $isAvailable[3],
                    "Th" => $isAvailable[4],
                    "Fr" => $isAvailable[5],
                    "Sa" => $isAvailable[6],
                    "Su" => $isAvailable[7],
                ),
                "online" => $metadata['_online'],
                "availableFrom" => $fromRange,
                "availableTo" => $toRange,
                "timeInterval" => $metadata['_wc_appointment_interval']
            );
        }

        return rest_ensure_response( $json );
    }

    public function post_consultation(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "name" => 'required',
            "price" => 'required|numeric',
            "duration" => 'required|numeric',
            "location" => 'required',
            "description" => 'required',
            "availability" => 'required|json',
            "online" => 'required|boolean',
            "availableFrom" =>  'required',
            "availableTo" =>  'required',
            "timeInterval" =>  'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;
        $user = wp_get_current_user();

        // Create the product
        $my_post = array(
            'post_title'    => $data['name'],
            'post_content'  => $data['description'],
            'post_status'   => 'publish',
            'post_type'     => 'product', 
            'post_author'   => $user->ID,
            'meta_input' => array(
                '_regular_price' => $data['price'],
                '_price' => $data['price'],
                '_virtual' => 'yes',
                '_downloadable' => 'no',
                '_manage_stock' => 'no',
                '_backorders' => 'no',
                '_sold_individually' => 'no',
                '_tax_status' => 'taxable',
                '_product_address' => 'field_6294e231e67c0',
                'product_address' => $data['location'],
                '_wcfm_product_author' => $user->ID,
            )
        );

        // Insert the product into the database
        $post_id = wp_insert_post( $my_post );

        // Cast the product into WC_Product_Appointment
        $classname = \WC_Product_Factory::get_product_classname( $post_id, 'appointment');
        $product   = new $classname( $post_id );

        $product->set_qty(1);
        $product->set_qty_min(1);
        $product->set_qty_max(1);

        $product->set_duration($data['duration']);
        $product->set_duration_unit('minute');
        $product->set_interval($data['timeInterval']);
        $product->set_interval_unit('minute');
        $product->save();

        $available = array_values(json_decode($data['availability'], true));

        // Add availabilities
        for($i=1; $i<=7; $i++) {
            $wpdb->insert(
                $wpdb->prefix . 'wc_appointments_availability',
                array(
                    'kind'          => 'availability#product',
                    'kind_id'       => $product->get_id(),
                    'title'         => '',
                    'range_type'    => 'time:'.$i,
                    'from_range'    => $data['availableFrom'],
                    'to_range'      => $data['availableTo'],
                    'from_date'     => '',
                    'to_date'       => '',
                    'appointable'   => $available[$i - 1] ? 'yes' : 'no',
                    'priority'      => 10,
                    'ordering'      => $i - 1,
                    'rrule'         => '',
                    'date_created'  => current_time( 'mysql' ),
                    'date_modified' => current_time( 'mysql' ),
                )
            );
        }

       return rest_ensure_response(['success' => true]);
    }

    public function put_consultation(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "consultationId" => 'required|numeric',
            "name" => 'required',
            "price" => 'required|numeric',
            "duration" => 'required|numeric',
            "location" => 'required',
            "description" => 'required',
            "availability" => 'required|json',
            "online" => 'required|boolean',
            "availableFrom" =>  'required',
            "availableTo" =>  'required',
            "timeInterval" =>  'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $product = get_post($data['consultationId']);
        if ($product->post_author != $user->ID) {
            return new \WP_Error( '403', 'The product doesn\'t exist or you don\'t have access to it', array( 'status' => 403 ) );
        }

        // Update the product title and content
        wp_update_post(
            array(
                'ID'            => $product->ID,
                'post_title'    => $data['name'],
                'post_content'  => $data['description']
            )
        );

        // Update the product's metadata
        update_post_meta($product->ID, '_regular_price', $data['price']);
        update_post_meta($product->ID, '_price', $data['price']);
        update_post_meta($product->ID, 'product_address', $data['location']);

        // Update the WC_Product_Appointment options
        $classname = \WC_Product_Factory::get_product_classname( $product->ID, 'appointment');
        $product   = new $classname( $product->ID );

        $product->set_duration($data['duration']);
        $product->set_duration_unit('minute');
        $product->set_interval($data['timeInterval']);
        $product->set_interval_unit('minute');
        $product->save();

        // Update availabilities
        global $wpdb;
        $query = $wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_appointments_availability WHERE kind = %s AND kind_id = %d", 'availability#product', $data['consultationId']);
        $wpdb->query($query);

        $availableData = array_values(json_decode($data['availability'], true));

        // Add availabilities
        for($i=1; $i<=7; $i++) {
            $wpdb->insert(
                $wpdb->prefix . 'wc_appointments_availability',
                array(
                    'kind'          => 'availability#product',
                    'kind_id'       => $product->get_id(),
                    'title'         => '',
                    'range_type'    => 'time:'.$i,
                    'from_range'    => $data['availableFrom'],
                    'to_range'      => $data['availableTo'],
                    'from_date'     => '',
                    'to_date'       => '',
                    'appointable'   => $availableData[$i - 1] ? 'yes' : 'no',
                    'priority'      => 10,
                    'ordering'      => $i - 1,
                    'rrule'         => '',
                    'date_created'  => current_time( 'mysql' ),
                    'date_modified' => current_time( 'mysql' ),
                )
            );
        }

        return rest_ensure_response(['success' => true]);
    }

    public function delete_consultation(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "consultationId" => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $product = get_post($data['consultationId']);
        if ($product->post_author != $user->ID) {
            return new \WP_Error( '403', 'The product doesn\'t exist or you don\'t have access to it', array( 'status' => 403 ) );
        }

        $classname = \WC_Product_Factory::get_product_classname( $product->ID, 'appointment');
        $product   = new $classname( $product->ID );
        $success = $product->delete(true);

        return rest_ensure_response(['success' => $success]);
    }
}
