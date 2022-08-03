<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Profile_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->endpoint      = '/profile';
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
                'callback'  => array( $this, 'get_profile' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'post_profile' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_profile() {
        $user = wp_get_current_user();
        $metadata = array_map(fn($item) => $item[0], get_user_meta($user->ID));
        
        // Get the post
        $author_post = new \WP_Query([
            'posts_per_page' => '1',
            'author' => $user->ID,
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
            "picture" => array(
                "id" => $profilePicture->ID,  
                "uri" => $profilePicture->guid ?? null,
            ),
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

    public function post_profile(\WP_REST_Request $request) {
        $user = wp_get_current_user();
        $data = $request->get_params();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'firstname' => 'required',
            'lastname' => 'required',
            'profession' => 'required',
            'pageTitle' => 'required',
            'seoTitle' => 'required',
            'shortDescription' => 'required',
            'longDescription' => 'required',
            'specialty' => 'required',
            'location' => 'required|json',
            'cvText' => 'required'
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }
        
        // update the account
        update_user_meta( $user->ID, 'first_name', $data['firstname']);
        update_user_meta( $user->ID, 'last_name', $data['lastname']);
        
        // Get the post
        $author_post = new \WP_Query([
            'posts_per_page' => '1',
            'author' => $user->ID,
            'post_status' => ['publish', 'pending'],
        ]);
 
        if($author_post->have_posts()){
            $post = $author_post->posts[0];

            // update the post
            $my_post = array(
                'ID'           => $post->ID,
                'post_title'   => $data['pageTitle'],
                'post_content' => $data['longDescription'],
            );

            wp_update_post( $my_post );
        } else {
            $my_post = array(
                'post_title'   => $data['pageTitle'],
                'post_content' => $data['longDescription'],
                'post_status'   => 'pending',
              );

            $post_id = wp_insert_post( $my_post );

            if($post_id > 0) {
                $post = get_post($post_id);
            } else {
                return new \WP_Error( '400', "Can't create post", array( 'status' => 400 ) );
            }
        }

        $post_categories = [];
        $specialties = explode(', ', $data['specialty']);

        foreach($specialties as $specialty) {
            $specialty = ucfirst(mb_convert_case($specialty, MB_CASE_LOWER, "UTF-8"));
            $cat_id = get_cat_ID($specialty);

            if($cat_id > 0) {
                $post_categories[] = $cat_id;
            } else {
                $cat_id = wp_insert_category(['cat_name' => $specialty]);
                $post_categories[] = $cat_id;
            }
        }

        wp_set_post_categories($post->ID, $post_categories);

        update_post_meta( $post->ID, 'profession', $data['profession']);
        update_post_meta( $post->ID, 'seo_title', $data['seoTitle']);
        update_post_meta( $post->ID, 'short_description', $data['shortDescription']);
        update_post_meta( $post->ID, 'profile_location', $data['location']);
        update_post_meta( $post->ID, 'cv_text', $data['cvText']);

        // change the featured image
        if ($data['thumbnailId']) {
            $attachment = get_post($data['thumbnailId']);

            if (!$attachment) {
                return new \WP_Error( '400', "Attachment not found", array( 'status' => 400 ) );
            }

            if ($attachment->post_type != 'attachment') {
                return new \WP_Error( '400', "Not an attachment", array( 'status' => 400 ) );
            }

            // check that the thumbnail id belongs to the user
            if( $attachment->post_author != $user->ID){
                return new \WP_Error( '403', "This attachment wasn't uploaded by this member", array( 'status' => 403 ) );
            }

            update_post_meta( $post->ID, '_thumbnail_id', (int)$data['thumbnailId']);
        }

        return rest_ensure_response( ['success' => true] );
    }
}
