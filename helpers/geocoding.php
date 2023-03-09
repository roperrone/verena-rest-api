<?php

require_once __DIR__ . '/../vendor/autoload.php';

class Verena_Geocoding_API {

    static function get_lat_lng($address) {
        $client = new \GuzzleHttp\Client();
        $data = array(
            'address' => $address,
            'key' => 'AIzaSyCJBDpfyyy0BQ0976yzwT_WhdsiyRqaD7w' //PROD: 'AIzaSyCMokwBEd4k_ue9IBEgS1geoMhnrmHKKIM'
        );        

        $res = $client->request('GET', 'https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query($data));

        try {
            $res = json_decode((string)$res->getBody(), TRUE);

            if ($res['status'] == 'ZERO_RESULTS') {
                return null;
            }

            $locality = null;

            foreach($res['results'][0]['address_components'] as $component){
                if( in_array('locality', $component['types']) ) {
                    $locality = $component['long_name'];
                    break;
                }
            }

            return array('lat' => $res['results'][0]['geometry']['location']['lat'], 'lng' => $res['results'][0]['geometry']['location']['lng'], 'locality' => $locality);
        } catch(Exception $e) {
            // Do nothing
            print_r($e);
            return null;
        }
    }
}

?>