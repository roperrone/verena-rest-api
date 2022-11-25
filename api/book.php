<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;
use Ramsey\Uuid\Uuid;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Book_Controller {
	/**
	 * Abbreviations constants.
	 */
	const AVAILABLE     = 'a';
	const DATE          = 'd';

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/book';
    }

   /**
   * Check permissions
   * -- Allow anonymous access
   *
   * @param WP_REST_Request $request Current request.
   */
    public function permission_callback(\WP_REST_Request $request ) {
        return true;
    }

    public function register_routes() {  
        register_rest_route( $this->namespace, $this->endpoint . '/availabilities' , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_availabilities' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/praticien' , array(
            array(
                'methods'   => 'GET',
                'callback'  => array( $this, 'get_info_praticien' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/login' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_book_login' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/signup' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_book_signup' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_availabilities(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'memberId' => 'required|numeric',
            'minDate' => 'required',
            'maxDate' => 'required'
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        // Check if the client belongs to our user
        $member = get_user_by('id', $data['memberId']);

        if( !$member ) {
            return new \WP_Error( '404', 'Invalid member id', array( 'status' => 404 ) );
        }

        $query = array(
            'posts_per_page'   => -1,
            'post_type'        => 'product',
            'post_status'      => 'publish',
            'meta_key'         => '_wcfm_product_author',
            'meta_value'       =>  $member->ID,
        );
        $results = new \WP_Query( $query );
        $products_ids = array_map(fn($post) => $post->ID, $results->posts);

        $products = array_filter(
			array_map(
				function( $product_id ) {
					return wc_get_product( $product_id );
				},
				$products_ids
			), 
		);

        $min_date = \DateTime::createFromFormat('Y-m-d', $data['minDate'])->settime(0,0)->getTimestamp();
        $max_date = \DateTime::createFromFormat('Y-m-d', $data['maxDate'])->settime(0,0)->getTimestamp();

        // Calculate partially scheduled/fully scheduled/unavailable days for each product.
        $scheduled_data = self::get_available_dates($min_date, $max_date, $member, $products);

        return rest_ensure_response( $scheduled_data );
    }

    public function get_info_praticien(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'memberId' => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $member = get_user_by('id', $data['memberId']);

        if( !$member ) {
            return new \WP_Error( '404', 'Invalid member id', array( 'status' => 404 ) );
        }

        $metadata = array_map(fn($item) => $item[0], get_user_meta($member->ID));

        // Get the post
        $author_post = new \WP_Query([
            'posts_per_page' => '1',
            'author' => $member->ID,
            'post_status' => ['publish', 'pending'],
        ]);

        $pageTitle = null;
        $longDescription = null;
        $specialty = null;
        
        if($author_post->have_posts()){
            $post = $author_post->posts[0];

            $pageTitle = $post->post_title;
            $longDescription = $post->post_content;

            $post_categories = wp_get_post_categories($post->ID);
            
            $specialty = [];
            foreach($post_categories as $category_id) {
                $specialty[] = get_cat_name($category_id);
            }
            $specialty = implode(', ', $specialty);
            $post_meta = array_map(fn($item) => $item[0], get_post_meta($post->ID));
        }

        $profilePicture = get_post($post_meta['_thumbnail_id']);
        
        $json = array(
            "firstname" => $metadata['first_name'] ?? '',
            "lastname" => $metadata['last_name'] ?? '',
            "picture" => $profilePicture->guid ?? null,
            "profession" => $post_meta['profession'] ?? '',
            "pageTitle" => $pageTitle,
            "seoTitle" => $post_meta['seo_title'] ?? '',
            "shortDescription" => $post_meta['short_description'] ?? '',
            "longDescription" => $longDescription,
            "specialty" => $specialty ?? '',
            "location" => $post_meta['profile_location'] ? json_decode($post_meta['profile_location']) : array(array("address" => '')),
            "cvText" =>  $post_meta['cv_text'],
        );

        return rest_ensure_response( $json );
    }

    public function post_book_login(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'consultationId' => 'required|numeric',
            'email' => 'required|email',
            'password' => 'required',
            'date' => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $user = wp_authenticate($data['email'], $data['password']);

        if (is_wp_error($user)) {
            return new \WP_Error( '403', 'Invalid username or password', array( 'status' => 403 ) );
        }

        $product = wc_get_product( $data['consultationId'] );

        if (!$product) {
            return new \WP_Error( '404', 'Consultation not found', array( 'status' => 404 ) );
        }

        // Let's find the user copy that belongs to the product owner
        $product_metadata = array_map(fn($item) => $item[0], get_post_meta($product->get_id()));
        $user_query = new \WP_User_Query( array( 
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key'     => 'wcfm_vendor_id',
                        'value'   => $product_metadata['_wcfm_product_author'],
                        'compare' => '='
                    ),
                    array(
                        'key'     => 'billing_email',
                        'value'   => $data['email'],
                        'compare' => '=',
                    )
                ) 
            )
        );

        $results = $user_query->get_results();

        if ( empty($results) ) {
            // Create user copy (this copy belongs to the product owner: i.e _wcfm_product_author)
            $product_metadata = array_map(fn($item) => $item[0], get_post_meta($product->get_id()));
            $user_id = $this->createUserCopy($data['firstname'], $data['lastname'], $data['email'], $product_metadata['_wcfm_product_author']);
            $user = get_user_by('id', $user_id);
        } else {
            $user = $results[0];
        }

        $success = $this->create_appointment($product, $user, $data['date']);

        if (is_wp_error($success)) {
            return $success;
        }

        return rest_ensure_response( ['success' => $success] );
    }

    public function post_book_signup(\WP_REST_Request $request) {
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'firstname' => 'required',
            'lastname' => 'required',
            'email' => 'required|email',
            'password' => 'required',
            'consultationId' => 'required|numeric',
            'date' => 'required',
        ]);


        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $product = wc_get_product( $data['consultationId'] );

        if (!$product) {
            return new \WP_Error( '404', 'Consultation not found', array( 'status' => 404 ) );
        }

        // Check if user doesn't already exist
        $users = new \WP_User_Query( array(
            'search'         => $data['email'],
            'search_columns' => array(
                'user_email',
            ),
        ) );

        if ( !empty( $users->get_results() ) ) {
            return new \WP_Error( '403', 'User already exists', array( 'status' => 403 ) );
        }

        // Create user
        $username = Uuid::uuid4()->toString();
        $user_id = wp_insert_user(
            array(
                'user_login' => $username, 
                'user_email' => $data['email'],
                'user_pass' => $data['password'],
                'display_name' => $data['firstname'].' '.$data['lastname'],
                'nickname' => $data['firstname'].' '.$data['lastname'],
            )
        );

        update_user_meta( $user_id, 'first_name', $data['firstname']);
        update_user_meta( $user_id, 'last_name', $data['lastname']);
        update_user_meta( $user_id, 'billing_email', $data['email']);
        update_user_meta( $user_id, 'hide_admin_area', 1);
        update_user_meta( $user_id, 'wp_capabilities', ['customer' => true]);

        // Create user copy (this copy belongs to the product owner: i.e _wcfm_product_author)
        $product_metadata = array_map(fn($item) => $item[0], get_post_meta($product->get_id()));
        $user_id = $this->createUserCopy($data['firstname'], $data['lastname'], $data['email'], $product_metadata['_wcfm_product_author']);

        $user = get_user_by('id', $user_id);

        $success = $this->create_appointment($product, $user, $data['date']);

        if (is_wp_error($success)) {
            return $success;
        }

        return rest_ensure_response( ['success' => $success] );
    }

    /**
     * Create an appointment 
     * 
     * @param WC_Product $product woocommerce product
     * @param WP_User $client client booking the appointment
     * @param string $date start date for the appointment 
     * 
     * @return string|WP_Error
     */
    function create_appointment($product, $user, $date) {
        $client_meta = array_map(fn($element) => $element[0], get_user_meta($user->ID));
        $product_metadata = array_map(fn($item) => $item[0], get_post_meta($product->get_id()));
        
        $duration = $product_metadata['_wc_appointment_duration'];

        $vendor = get_user_by('id', $product_metadata['_wcfm_product_author']);

        // To begin with, check if the timeslot is available
        $min_date =  \DateTime::createFromFormat('Y-m-d H:i', $date)->getTimestamp();
        $max_date =  \DateTime::createFromFormat('Y-m-d H:i', $date)->modify('+ '. $duration .' minutes')->getTimestamp();

        $scheduled_data = self::get_available_dates($min_date, $max_date, $vendor, [$product]);

        if ( empty($scheduled_data[0]['availability']) ) {
            return new \WP_Error( '404', 'The appointment cannot be booked at this date/time', array( 'status' => 404 ) );
        } else if ( !$scheduled_data[0]['availability'][0]['a'] ) { // appointment has already been booked
            return new \WP_Error( '404', 'The appointment has already been booked. Please try with another timeslot.', array( 'status' => 404 ) );
        }

        $address = array(
            'first_name' => $client_meta['first_name'],
            'last_name'  => $client_meta['last_name'],
            'email'      => $client_meta['billing_email'],
        );
      
        // Let's create a Woocommerce order
        $order = wc_create_order();
        $order->add_product( $product, 1);
        $order->set_address( $address, 'billing' );
        $order->set_payment_method('check');
        $order->calculate_totals();
        $order->update_status('Pending payment', 'Online order', TRUE);
        $order_id = $order->save();

        $timeStart = new \DateTime($date);

        $timeEnd = new \DateTime($date);
        $timeEnd->modify('+'.$duration.' minutes');

        // We can now create a Woocommerce appointment
        $appointment = new \WC_Appointment();
        $appointment->set_order_id($order_id);
        $appointment->set_customer_id($user->ID);
        $appointment->set_timezone('Europe/Paris');
        $appointment->set_start($timeStart->getTimestamp());
        $appointment->set_end($timeEnd->getTimestamp());
        $appointment->set_product_id($product->get_id());
        $appointment->set_status('confirmed');
        $appointment->set_staff_id($product_metadata['_wcfm_product_author']);
        $appointmentId = $appointment->save();

        $success = ($order_id > 0 && $appointmentId > 0);

        // Sync to GCal
        $wc_appointment = wc_appointments_integration_gcal();
        $wc_appointment->sync_to_gcal( $appointmentId );

        if ($success) {
            \Verena_Notifications_Helper::add_notification(
                sprintf("Un nouveau RDV (#%s) vient d'être créé", $appointmentId), 0,
                (int)$product_metadata['_wcfm_product_author']
            );
        }

        return $success;
    }

    /**
     * Create a user copy that will belong to the product author
     * 
     * @param string $firstname user firstname
     * @param string $lastname user lastname
     * @param string $email user email
     * @param int $wcfm_product_author product author id
     * 
     * @return int
     */
    function createUserCopy($firstname, $lastname, $email, $wcfm_product_author): int {
        $username = Uuid::uuid4()->toString();
        $password = bin2hex(openssl_random_pseudo_bytes(10)); 

        $user_id = wp_insert_user(
            array(
                'user_login' => $username, 
                'user_pass' => $password,
                'display_name' => $firstname.' '.$lastname,
                'nickname' => $firstname.' '.$lastname,
            )
        );

        update_user_meta( $user_id, 'first_name', $firstname);
        update_user_meta( $user_id, 'last_name', $lastname);
        update_user_meta( $user_id, 'billing_email', $email);
        update_user_meta( $user_id, 'hide_admin_area', 1);
        update_user_meta( $user_id, 'wp_capabilities', ['customer' => true]);

        // Add the wcfm_vendor_id metadata to the user's profile
        update_user_meta( $user_id, 'wcfm_vendor_id', $wcfm_product_author);

        return $user_id;
    }

    /**
     * Get available time slots for a given list of consultations (products)
     * 
     * @param $min_date min date
     * @param $max_date max date
     * @param $vendor   staff member
     * @param $products list of products
     * 
     * @return array $scheduled_data
     */
    static function get_available_dates($min_date, $max_date, $vendor, $products) {
        $scheduled_data = array_values(
			array_map(
				function( $appointable_product ) use ( $min_date, $max_date, $staff_ids, $get_past_times, $timezone, $vendor ) {
					if ( empty( $min_date ) ) {
						// Determine a min and max date
						$min_date = strtotime( 'today' );
					}

					if ( empty( $max_date ) ) {
						$max_date = strtotime( 'tomorrow' );
					}

					$product_staff = $appointable_product->get_staff_ids() ?: [$vendor->ID];
					$duration      = $appointable_product->get_duration();
					$duration_unit = $appointable_product->get_duration_unit();
                    $metadata = array_map(fn($item) => $item[0], get_post_meta($appointable_product->get_id()));

					$availability  = [];

					$staff = empty( $product_staff ) ? [ 0 ] : $product_staff;
					if ( ! empty( $staff_ids ) ) {
						$staff = array_intersect( $staff, $staff_ids );
					}

					// Get slots for days before and after, which accounts for timezone differences.
					$start_date = strtotime( '-1 day', $min_date );
					$end_date   = strtotime( '+1 day', $max_date );

					foreach ( $staff as $staff_id ) {
						// Get appointments.
						$appointments          = [];
						$existing_appointments = \WC_Appointment_Data_Store::get_all_existing_appointments( $appointable_product, $start_date, $end_date, $staff_id );
						if ( ! empty( $existing_appointments ) ) {
							foreach ( $existing_appointments as $existing_appointment ) {
								#print '<pre>'; print_r( $existing_appointment->get_id() ); print '</pre>';
								$appointments[] = [
									'get_staff_ids'  => $existing_appointment->get_staff_ids(),
									'get_start'      => $existing_appointment->get_start(),
									'get_end'        => $existing_appointment->get_end(),
									'get_qty'        => $existing_appointment->get_qty(),
									'get_id'         => $existing_appointment->get_id(),
									'get_product_id' => $existing_appointment->get_product_id(),
									'is_all_day'     => $existing_appointment->is_all_day(),
								];
							}
						}
						$slots           = $appointable_product->get_slots_in_range( $start_date, $end_date, [], $staff_id, [], $get_past_times );
						$available_slots = $appointable_product->get_time_slots(
							[
								'slots'            => $slots,
								'staff_id'         => $staff_id,
								'from'             => $start_date,
								'to'               => $end_date,
								'appointments'     => $appointments,
								'include_sold_out' => true,
							]
						);

						foreach ( $available_slots as $timestamp => $data ) {
							// Filter slots outside of timerange.
							if ( $timestamp < $min_date || $timestamp >= $max_date ) {
								continue;
							}

							unset( $data['staff'] );

							$availability[] = [
								self::DATE          => self::get_time( $timestamp, $timezone ),
								self::AVAILABLE     => (bool)$data['available']							
                            ];
						}
					}

					$data = [
						'product_id'    => $appointable_product->get_id(),
						'product_title' => $appointable_product->get_title(),
                        'location'      => $metadata['product_address'],
                        'online'        => (bool)$metadata['_online'] ?? false,
                        'product_duration' => $duration,
                        'product_duration_unit' => $duration_unit,
                        'price'         => (int)$metadata['_price'],
						'availability'  => $availability,
					];

					return $data;
				},
				$products
			)
		);

        return $scheduled_data;
    }

    /**
	 * Format timestamp to the shortest reasonable format usable in API.
	 *
	 * @param $timestamp
	 * @param $timezone DateTimeZone
	 *
	 * @return string
	 */
	public static function get_time( $timestamp, $timezone ): string {
		$server_time = new \DateTime( date( 'Y-m-d\TH:i:s', $timestamp ), $timezone );

		return $server_time->format( "Y-m-d\TH:i" );
	}
}
