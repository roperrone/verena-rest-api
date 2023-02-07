<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Stripe_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->stripe        =  new \Stripe\StripeClient(STRIPE_API_KEY);
        $this->endpoint      = '/stripe';
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
                'callback'  => array( $this, 'get_stripe' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/payment_links' , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_payment_links' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/payments' , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_payment_list' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/new/payment_link' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'create_payment_link' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_stripe() {
        $user = wp_get_current_user();

        $account_id = $this->create_stripe_user($user);

        $link = $this->get_onboarding_link($account_id);
        $account_details = $this->get_stripe_account($account_id);

        $json = array(
            'onboarding_link' => $link,
            'account' => array(
                'id' => $account_details['id'] ?? null,
                'charges_enabled' => $account_details['charges_enabled'] ?? false,
                'country' => $account_details['country'] ?? null,
                'created' => $account_details['created'] ?? null,
            ),
        );

        return rest_ensure_response( $json );
    }

    public function get_payment_links() {
        $user = wp_get_current_user();

        global $wpdb;

        // Save the link in our database
        $results = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT title, price, link, `date` FROM wp_payment_links WHERE praticien_id = %d ORDER BY date DESC",
                $user->ID
            )
        );

        $display_results = [];
        foreach($results as $result){
            $date = new \DateTime('@'.$result->date);
            $date->setTimezone(new \DateTimeZone('Europe/Paris'));

            $display_results[] = [
                'date' => $date->format("Y-m-d\TH:i:s"),
                'title' => $result->title,
                'price' => (double)$result->price,
                'link' => $result->link,
            ];
        }

        $json = array(
            'links' => $display_results
        );
        
        return rest_ensure_response( $json );
    }

    public function get_payment_list() {
        $user = wp_get_current_user();

        $metadata = array_map(fn($item) => $item[0], get_user_meta($user->ID));

        if(!isset($metadata['stripe_acct_id'])) {
            return rest_ensure_response( [ 'data' => [] ] );
        }

        $stripe_account_id = $metadata['stripe_acct_id'];
        $account_details = $this->get_stripe_account($stripe_account_id);

        if(!$account_details['charges_enabled']) {
            return rest_ensure_response( [ 'data' => [] ] );
        }

        // Check if a product with the same name already exists 
        $payments = $this->stripe->paymentIntents->all([], ['stripe_account' => $stripe_account_id ]);
        $payment_arr = $payments->serializeParameters(true)['data'];

        $display_results = [];
        foreach($payment_arr as $payment){
            $charges = $payment['charges']->serializeParameters(true)['data'][0];

            $display_results[] = [
                'id' => $payment['id'],
                'amount' => $payment['amount']/100,
                'amount_received' => $payment['amount_received']/100,
                'billing' => $charges['billing_details'] ?? null,
                'receipt_url' => $charges['receipt_url'] ?? null, 
                'livemode' => $payment['livemode'],
                'created' => $payment['created'],
                'status' => $payment['status'],
            ];
        }

        $json = array(
            'data' => $display_results,
        );

        return rest_ensure_response( $json );
    }

    public function create_payment_link(\WP_REST_Request $request) {
        $user = wp_get_current_user();
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'title' => 'required',
            'amount' => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $metadata = array_map(fn($item) => $item[0], get_user_meta($user->ID));

        if(!isset($metadata['stripe_acct_id'])) {
            return new \WP_Error( '403', 'Stripe account not configured', array( 'status' => 403 ) );
        }

        $stripe_account_id = $metadata['stripe_acct_id'];
        $account_details = $this->get_stripe_account($stripe_account_id);

        if(!$account_details['charges_enabled']) {
            return new \WP_Error( '403', 'Stripe account setup not completed', array( 'status' => 403 ) );
        }

        // Check if a product with the same name already exists 
        $product = $this->stripe->products->search([
            'query' => "name:\"".$data['title']."\" AND active:\"true\""
        ], ['stripe_account' => $stripe_account_id ]);

        $product_arr =  $product->serializeParameters(true);

        if(is_array($product_arr['data']) && sizeof($product_arr['data']) > 0) {
            $product_arr = $product_arr['data'][0];
        } else {
            // Create product
            $product = $this->stripe->products->create([
                'name' => $data['title'], 
            ], ['stripe_account' => $stripe_account_id ]);

            $product_arr = $product->serializeParameters(true);
        }
                
        // Create price
        $price = $this->stripe->prices->create([
            'currency' => 'eur', 
            'unit_amount' => (double)$data['amount']*100, 
            'product' => $product_arr['id'],
        ], ['stripe_account' => $stripe_account_id ]);

        $price_arr = $price->serializeParameters(true);

        // Create payment link
        $payment_link = $this->stripe->paymentLinks->create([
            'line_items' => [
              [
                'price' => $price_arr['id'],
                'quantity' => 1,
              ],
            ],
          ], ['stripe_account' => $stripe_account_id ]);
        $payment_link_arr = $payment_link->serializeParameters(true);

        global $wpdb;

        $timestamp = time();

        // Save the link in our database
        $wpdb->insert("wp_payment_links", array(
            "praticien_id" => $user->ID,
            "title" => $data['title'],
            "price" => $data['amount'],
            "link" => $payment_link_arr['url'],
            "date" => $timestamp
        ), array('%d', '%s', '%s', '%s', '%d'));

        $json = array(
            'payment_link' => $payment_link_arr['url'] ?? null,
        ); 

        return rest_ensure_response( $json );
    }

    private function get_stripe_account($account_id) {
        $account = $this->stripe->accounts->retrieve($account_id);
        return $account->serializeParameters(true);
    }

    private function create_stripe_user($user) {
        // Check if the praticien is already attached to an account
        $metadata = array_map(fn($item) => $item[0], get_user_meta($user->ID));
        $account_id = null;

        if(!isset($metadata['stripe_acct_id'])) {

            $account = $this->stripe->accounts->create([
                'type' => 'standard',
                'country' => 'FR',
            ]);

            $account_id = $account->serializeParameters(true)['id'];
            update_user_meta( $user->ID, 'stripe_acct_id', $account_id);
        }  else {
            $account_id = $metadata['stripe_acct_id'];
        }

        return $account_id;
    }

    private function get_onboarding_link($account_id) {
        $link = $this->stripe->accountLinks->create(
            [
                'account' => $account_id,
                'refresh_url' => STRIPE_REDIRECT_URI . '?success=false',
                'return_url' => STRIPE_REDIRECT_URI . '?success=true',
                'type' => 'account_onboarding',
            ]
        );

        return $link->serializeParameters(true)['url'];
    }
}
