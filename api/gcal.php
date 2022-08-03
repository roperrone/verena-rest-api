<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Gcal_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/gcal';
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
                'callback'  => array( $this, 'get_gcal' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'set_gcal' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_gcal() {
        $user = wp_get_current_user();

        $wc_gcal = wc_appointments_integration_gcal();

        if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1') {
            $wc_gcal->set_redirect_after_oauth_uri('http://localhost:3000');
        } else {
           $wc_gcal->set_redirect_after_oauth_uri('https://praticien.verena.care');
        } 
        
        $wc_gcal->set_user_id($user->ID);

        $jwt = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']);
        $logout = $wc_gcal->get_redirect_uri();

        // Oauth2
        $oauth2 = array(
         'scope' => 'https://www.googleapis.com/auth/calendar',
         'redirect_uri' => $wc_gcal->get_redirect_uri(),
         'response_type' => 'code',
         'client_id' => $wc_gcal->get_client_id(),
         'state' => $jwt,
         'approval_prompt' => 'force',
         'access_type' => 'offline',
        );

        $calendars = $wc_gcal->get_calendars() ?? [];

        $json = array(
            'connected' => sizeof($calendars) > 0,
            'login' => 'https://accounts.google.com/o/oauth2/auth?'.http_build_query($oauth2),
            'logout' => $logout.'?state='.$jwt.'&logout=true',
            'calendars' => $calendars,
            'syncedCalendar' => $wc_gcal->get_calendar_id(),
        );

        return rest_ensure_response( $json );
    }

    public function set_gcal(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "calendarId" => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $wc_gcal = wc_appointments_integration_gcal();
        $wc_gcal->set_user_id($user->ID);

        $calendars = $wc_gcal->get_calendars();

        if(!array_key_exists($data['calendarId'], $calendars)) {
            return new \WP_Error( '404', 'Calendar not found.', array( 'status' => 404 ) );
        }

        update_user_meta( $user->ID, 'wc_appointments_gcal_calendar_id', $data['calendarId']);

        return rest_ensure_response( ['success' => true] );
    }
}
