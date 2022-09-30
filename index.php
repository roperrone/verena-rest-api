<?php
/**
 * Plugin Name: Verena REST API
 * Description: This plugin integrates Verena with Booking WP, WCFM and Woocommerce
 * Version: 1.0.0
 * Author: Romain Perrone
 */

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_API_Controller {
 
  const REST_API_NAMESPACE = '/verena/v1';

  public function __construct() {
      $this->load_dependencies();

      $this->account = new \VerenaRestApi\Verena_REST_Account_Controller();
      $this->appointment = new \VerenaRestApi\Verena_REST_Appointment_Controller();
      $this->client = new \VerenaRestApi\Verena_REST_Client_Controller();
      $this->consultation = new \VerenaRestApi\Verena_REST_Consultation_Controller();
      $this->invoice = new \VerenaRestApi\Verena_REST_Invoice_Controller();
      $this->notifications = new \VerenaRestApi\Verena_REST_Notification_Controller();
      $this->profile = new \VerenaRestApi\Verena_REST_Profile_Controller();
      $this->gcal = new \VerenaRestApi\Verena_REST_Gcal_Controller();
      $this->book = new \VerenaRestApi\Verena_REST_Book_Controller();
  }

  public function register_routes() {
      $this->account->register_routes();
      $this->appointment->register_routes();
      $this->client->register_routes();
      $this->consultation->register_routes();
      $this->invoice->register_routes();
      $this->notifications->register_routes();
      $this->profile->register_routes();
      $this->gcal->register_routes();
      $this->book->register_routes();
  }

  
  protected function load_dependencies() {
    $files = glob( __DIR__  . '/api/*.php');

    foreach($files as $filename) {
      require_once $filename;
    }
  }

}

// Register our new routes from the controller.
function verena_register_my_rest_routes() {
  $controller = new Verena_REST_API_Controller();
  $controller->register_routes();
}

add_action( 'rest_api_init', 'verena_register_my_rest_routes' );

/** 
 * Add support for CORS
 */
function add_api_cors_headers() {   
    add_filter( 'rest_pre_serve_request', function( $value ) {
//      $origin = ($_SERVER['REMOTE_ADDR'] === '127.0.0.1') ? 'http://localhost:3000' : 'https://praticien.verena.care';
      header( 'Access-Control-Allow-Headers: *');
      header( 'Access-Control-Allow-Origin: *');
      header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE' );
      header( 'Access-Control-Allow-Credentials: true' );

      return $value;
  } );
}

add_action( 'rest_api_init', 'add_api_cors_headers', 15 );