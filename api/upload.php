<?php

namespace VerenaRestApi;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../index.php';

use Rakit\Validation\Validator;
use Ramsey\Uuid\Uuid;
use Aws\S3\S3Client;  
use Aws\Exception\AwsException;

if (!defined("ABSPATH")) {
  exit; 
}

class Verena_REST_Upload_Controller {

    public function __construct() {
        $this->namespace     = \Verena_REST_API_Controller::REST_API_NAMESPACE;
        $this->s3Client      = 	new S3Client([
            'region' => 'eu-west-3',
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => AWS_ACCESS_KEY_ID,
                'secret' => AWS_SECRET_ACCESS_KEY,
            ],	
        ]);
        $this->endpoint      = '/upload';
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
                'callback'  => array( $this, 'get_upload' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint , array(
            array(
                'methods'   => 'DELETE',
                'callback'  => array( $this, 'delete_upload' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );

        register_rest_route( $this->namespace, $this->endpoint . '/new' , array(
            array(
                'methods'   => 'POST',
                'callback'  => array( $this, 'get_upload_uri' ),
                'permission_callback' => array( $this, 'permission_callback' ),
            ),
        ) );
    }

    public function get_upload(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'clientId' => 'required|numeric',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;

        // Retrieve the list of filenames
        $results = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT client_id, `filename`, `upload_date` FROM wp_verena_uploads 
                WHERE member_id = %d AND client_id = %d AND deleted = 0",
                $user->ID, $data['clientId']
            )
        );

        $display_results = [];
        foreach($results as $result){
            $cmd = $this->s3Client->getCommand('GetObject', [
                'Bucket' => 'verena-consult-praticien-prod',
                'Key' => $user->ID . '/' . $result->client_id . '/' . $result->filename,
            ]);
            
            $request = $this->s3Client->createPresignedRequest($cmd, '+60 minutes');
    
            $display_results[] = [
                'clientId' => (int)$result->client_id,
                'fileName' => $result->filename,
                's3Url' => (string) $request->getUri(),
                'uploadDate' => $result->upload_date,
            ];
        }

        $json = array(
            'data' => $display_results
        );

        return rest_ensure_response( $json );
    }

    public function delete_upload(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'clientId' => 'required|numeric',
            'filename' => 'required'
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        global $wpdb;

        // Retrieve the list of filenames
        $wpdb->query( 
            $wpdb->prepare(
                "DELETE FROM wp_verena_uploads WHERE member_id = %d AND client_id = %d AND `filename` = %s",
                $user->ID, $data['clientId'], $data['filename']
            )
        );

        return rest_ensure_response( ['success' => true] );
    }

    public function get_upload_uri(\WP_REST_Request $request) {
        $data = $request->get_params();
        $user = wp_get_current_user();

        $validator = new Validator();
        $validation = $validator->make($data, [
            'filename' => 'required',
            'clientId' => 'required|numeric',
            'contentType' => 'required',
        ]);

        $validation->validate();

        if ($validation->fails()) {
            $errors = $validation->errors();
            return new \WP_Error( '400', $errors->firstOfAll(), array( 'status' => 400 ) );
        }

        $user = wp_get_current_user();

        // Check if file doesn't exist
        global $wpdb;

        $results = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT `filename` FROM wp_verena_uploads WHERE member_id = %d AND client_id = %d AND `filename` = %s",
                $user->ID, $data['clientId'], $data['filename']
            )
        );

        if(sizeof($results) > 0) {
            return new \WP_Error( '400', 'A file with the same name already exists', array( 'status' => 400 ) );
        }

        $filename = $user->id . '/' . $data['clientId'] . '/' . $data['filename'];

        $cmd = $this->s3Client->getCommand('PutObject', [
            'Bucket' => 'verena-consult-praticien-prod',
            'Key' => $filename,
            'ContentType' => $data['contentType'],
            'Metadata' => array(
                'owner' => $user->ID
            )
        ]);
        
        $request = $this->s3Client->createPresignedRequest($cmd, '+60 minutes');
        $presignedUrl = (string)$request->getUri();

        global $wpdb;

        // Save the link in our database
        $wpdb->insert("wp_verena_uploads", array(
            "member_id" => $user->ID,
            "client_id" => $data['clientId'],
            "filename" => $data['filename'],
            "deleted" => false,
        )); 

        $json = array(
            'uploadUri' => $presignedUrl
        );
        
        return rest_ensure_response( $json );
    }
}
