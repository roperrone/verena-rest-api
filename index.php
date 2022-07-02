<?php
/**
 * Plugin Name: Verena REST API
 * Description: This plugin integrates Verena with Booking WP, WCFM and Woocommerce
 * Version: 1.0.0
 * Author: Romain Perrone
 */

require_once __DIR__ . '/api/account.php';
require_once __DIR__ . '/api/appointment.php';
require_once __DIR__ . '/api/client.php';
require_once __DIR__ . '/api/consultation.php';
require_once __DIR__ . '/api/invoice.php';
require_once __DIR__ . '/api/notifications.php';
require_once __DIR__ . '/api/profile.php';

use \VerenaRestApi\Verena_REST_Account_Controller as Verena_REST_Account_Controller;
use \VerenaRestApi\Verena_REST_Appointment_Controller as Verena_REST_Appointment_Controller;
use \VerenaRestApi\Verena_REST_Client_Controller as Verena_REST_Client_Controller;
use \VerenaRestApi\Verena_REST_Consultation_Controller as Verena_REST_Consultation_Controller;
use \VerenaRestApi\Verena_REST_Invoice_Controller as Verena_REST_Invoice_Controller;
use \VerenaRestApi\Verena_REST_Notification_Controller as Verena_REST_Notification_Controller;
use \VerenaRestApi\Verena_REST_Profile_Controller as Verena_REST_Profile_Controller;


if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_API_Controller {
 
  const REST_API_NAMESPACE = '/verena/v1';

  public function __construct() {
      $this->account = new Verena_REST_Account_Controller();
      $this->appointment = new Verena_REST_Appointment_Controller();
      $this->client = new Verena_REST_Client_Controller();
      $this->consultation = new Verena_REST_Consultation_Controller();
      $this->invoice = new Verena_REST_Invoice_Controller();
      $this->notifications = new Verena_REST_Notification_Controller();
      $this->profile = new Verena_REST_Profile_Controller();
  }

  public function register_routes() {
      $this->account->register_routes();
      $this->appointment->register_routes();
      $this->client->register_routes();
      $this->consultation->register_routes();
      $this->invoice->register_routes();
      $this->notifications->register_routes();
      $this->profile->register_routes();
  }

}

// Register our new routes from the controller.
function verena_register_my_rest_routes() {
  $controller = new Verena_REST_API_Controller();
  $controller->register_routes();
}

add_action( 'rest_api_init', 'verena_register_my_rest_routes' );