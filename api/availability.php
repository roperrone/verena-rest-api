<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Ramsey\Uuid\Uuid;
use Rakit\Validation\Validator;

require_once __DIR__ . '/../index.php';

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Availability_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/availability';
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
                'callback'  => array( $this, 'get_availability' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_availability' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'PUT',
                'callback'  => array( $this, 'put_availability' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'DELETE',
                'callback'  => array( $this, 'delete_availability' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_availability() {
        $user = wp_get_current_user();

        $json = [];
        $availabilities = \WC_Appointments_Availability_Data_Store::get_staff_availability($user->ID);
        
        foreach($availabilities as $availability) {
            // Skip if the range type is different from custom:daterange
            if( !($availability['range_type'] === 'custom:daterange') ) {
                continue;
            }

            $json[] = $this->formatAvailability($availability);
        }
        
        return rest_ensure_response($json);
    }

    public function post_availability(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "name" => "required",
            "timeStart" => 'required',
            "timeEnd" => 'required',
            "allDay" => 'boolean'
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $availability = new \WC_Appointments_Availability();
        $availability->set_kind('availability#staff');
        $availability->set_kind_id($user->ID);
        $availability->set_title($data['name']);
        $availability->set_range_type('custom:daterange');

        $start = new \Datetime($data['timeStart']);

        $availability->set_from_date($start->format('Y-m-d'));
        $availability->set_from_range($start->format('H:i'));

        $end =  new \Datetime($data['timeEnd']);

        $availability->set_to_date($end->format('Y-m-d'));
        $availability->set_to_range($end->format('H:i'));

        $availability->set_appointable(false);
        $availability->set_priority(10);

        $availabilityId = $availability->save();

        $success = $availabilityId > 0;

        $json = $this->formatAvailability($availability);
        return rest_ensure_response(['success' => $success, 'availability' => $json]);
    }

    public function put_availability(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "availabilityId" => "required|numeric",
            "name" => "required",
            "timeStart" => 'required',
            "timeEnd" => 'required',
            "allDay" => 'boolean'
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $availability = get_wc_appointments_availability($data['availabilityId']);

        if (!$availability) {
            return new \WP_Error( '404', 'Availability not found', array( 'status' => 404 ) );
        }

        if ($availability->get_kind_id() != $user->ID ) {
            return new \WP_Error( '403', 'You don\'t have the rights to edit this availability', array( 'status' => 403 ) );
        }

        $availability->set_title($data['name']);

        $availability->set_range_type('custom:daterange');
        $availability->set_appointable(false);
        
        $start = new \Datetime($data['timeStart']);

        $availability->set_from_date($start->format('Y-m-d'));
        $availability->set_from_range($start->format('H:i'));

        $end =  new \Datetime($data['timeEnd']);

        $availability->set_to_date($end->format('Y-m-d'));
        $availability->set_to_range($end->format('H:i'));

        $availabilityId = $availability->save();

        $success = $availabilityId > 0;
        $json = $this->formatAvailability($availability);

        return rest_ensure_response(['success' => $success, 'availability' => $json]);
    }

    public function delete_availability(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            "availabilityId" => "required|numeric",
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $availability = get_wc_appointments_availability($data['availabilityId']);

        if (!$availability) {
            return new \WP_Error( '404', 'Availability not found', array( 'status' => 404 ) );
        }

        if ($availability->get_kind_id() != $user->ID ) {
            return new \WP_Error( '403', 'You don\'t have the rights to delete this availability', array( 'status' => 403 ) );
        }

        $success = $availability->delete();
        return rest_ensure_response(['success' => $success]);
    }

    protected function formatAvailability($availability) {
        $dateStart =  \DateTime::createFromFormat('Y-m-d H:i', $availability['from_date'] . ' ' . $availability['from_range']);
        $dateStop = \DateTime::createFromFormat('Y-m-d H:i', $availability['to_date'] . ' ' . $availability['to_range']);

        return array(
            "availabilityId" => (int)$availability["ID"],
            "name" => $availability["title"],
            "timeStart" => $dateStart->format("Y-m-d\TH:i:s"),
            "timeEnd" => $dateStop->format("Y-m-d\TH:i:s"),
            "allDay" => ($dateStop->diff($dateStart)->d >= 1) // true, if the availability is spread over multiple days
        );
    }
}
