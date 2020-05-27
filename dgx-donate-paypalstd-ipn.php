<?php
/**
 * Seamless Donations (Dgx-Donate) IPN Handler class
 * Copyright 2013 Allen Snook (email: allendav@allendav.com)
 * GPL2
 */

// Load WordPress
include "../../../wp-config.php";

// Load Seamless Donations Core
include_once "./dgx-donate.php";

class Dgx_Donate_IPN_Handler
{

    public $chat_back_url = "https://www.paypal.com/cgi-bin/webscr";
    public $host_header = "Host: www.paypal.com\r\n";
    public $post_data = array();
    public $session_id = '';
    public $transaction_id = '';

    public function __construct()
    {
        dgx_donate_debug_log('----------------------------------------');
        dgx_donate_debug_log('IPN processing start');

        // Grab all the post data
        // // $this->post_data = $_POST;
				//dgx_donate_debug_log('post'.print_r($_POST, true));
        $post = file_get_contents('php://input');
        parse_str($post, $data);
        $this->post_data = $data;
				//dgx_donate_debug_log('post data'.print_r($data, true));
        // Set up for production or test
        $this->configure_for_production_or_test();

        // Extract the session and transaction IDs from the POST
        $this->get_ids_from_post();

        if (!empty($this->session_id)) {
            $response = $this->reply_to_paypal();

            if ("VERIFIED" == $response) {
                $this->handle_verified_ipn();
            } else if ("INVALID" == $response) {
                $this->handle_invalid_ipn();
            } else {
                $this->handle_unrecognized_ipn($response);
            }
        } else {
            dgx_donate_debug_log('Null IPN (Empty session id).  Nothing to do.');
        }

        dgx_donate_debug_log('IPN processing complete');
    }

    public function configure_for_production_or_test()
    {
        if ("SANDBOX" == get_option('dgx_donate_paypal_server')) {
            //$this->chat_back_url = "ssl://www.sandbox.paypal.com";
            $this->chat_back_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";
            $this->host_header = "Host: www.sandbox.paypal.com\r\n";
        }
    }

    public function get_ids_from_post()
    {
        $this->session_id = isset($_POST["custom"]) ? $_POST["custom"] : '';
        $this->transaction_id = isset($_POST["txn_id"]) ? $_POST["txn_id"] : '';
    }

    public function reply_to_paypal()
    {
        // // $req = 'cmd=_notify-validate';
        $request_data = $this->post_data;
        $request_data['cmd'] = '_notify-validate';
        $request = http_build_query($request_data);

        // $get_magic_quotes_exists = function_exists( 'get_magic_quotes_gpc' );

        // foreach ($_POST as $key => $value) {
        //     if( $get_magic_quotes_exists && get_magic_quotes_gpc() == 1 ) {
        //         $value = urlencode( stripslashes( $value ) );
        //     } else {
        //         $value = urlencode( $value );
        //     }
        //     $req .= "&$key=$value";
        // }

        // $header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
        // $header .= $this->host_header;
        // $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        // $header .= "Content-Length: " . strlen( $req ) . "\r\n\r\n";

        $header = "POST /cgi-bin/webscr HTTP/1.0\r\n";
        $header .= $this->host_header;
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $header .= "Content-Length: " . strlen($request) . "\r\n\r\n";

        $required_curl_version = '7.34.0';
        $response = '';

        if (function_exists('curl_init')) {
						$ch = curl_init($this->chat_back_url);
						if (curl_errno($ch) != 0) {
							dgx_donate_debug_log("IPN cURL error: " . curl_error($ch));
						}
						$version = curl_version();
						dgx_donate_debug_log('curl version :'.print_r($version, true));
            if ($ch != false) {
							dgx_donate_debug_log('curl start');
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
                curl_setopt($ch, CURLOPT_SSLVERSION, 6); //Integer NOT string TLS v1.2

                // set TCP timeout to 30 seconds
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

                // CONFIG: Please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html"
                // and set the directory path of the certificate as shown below.
                // Ensure the file is readable by the webserver.
                // This is mandatory for some environments.
                // $cert = __DIR__ . "/ssl/cacert.pem";
                // dgx_donate_debug_log( "Loading certificate from $cert" );
                // curl_setopt( $ch, CURLOPT_CAINFO, $cert );

                $response = curl_exec($ch);
                if (curl_errno($ch) != 0) { // cURL error
                    dgx_donate_debug_log(
                        "IPN failed: unable to establish network chatback connection to PayPal via cURL");
                    dgx_donate_debug_log("IPN cURL error: " . curl_error($ch));
                    $version = curl_version();
                    dgx_donate_debug_log("cURL version: " . $version['version'] . " OpenSSL version: " .
                        $version['ssl_version']);
                    // https://curl.haxx.se/docs/manpage.html#--tlsv12
                    // https://en.wikipedia.org/wiki/Comparison_of_TLS_implementations
                    dgx_donate_debug_log("PayPal requires TLSv1.2, which requires cURL 7.34.0 and OpenSSL 1.0.1.");
                    dgx_donate_debug_log("See https://en.wikipedia.org/wiki/Comparison_of_TLS_implementations");
                    dgx_donate_debug_log("for minimum versions for other implementations.");
                } else {
                    // Split response headers and payload, a better way for strcmp
                    dgx_donate_debug_log("IPN chatback attempt via cURL completed. Checking response...");
                    $tokens = explode("\r\n\r\n", trim($response));
                    $response = trim(end($tokens));
                }
                curl_close($ch);
						}
					} else {
						dgx_donate_debug_log(
							"Unable to complete chatback attempt. SSL incompatible. Consider enabling cURL library." );
						dgx_donate_debug_log( "See https://en.wikipedia.org/wiki/Comparison_of_TLS_implementations" );
						dgx_donate_debug_log( "for minimum versions for other implementations." );
					}

        // $fp = fsockopen( $this->chat_back_url, 443, $errno, $errstr, 30 );
        // if ( $fp ) {
        //     fputs( $fp, $header . $req );

        //     $done = false;
        //     do {
        //         if ( feof( $fp ) ) {
        //             $done = true;
        //         } else {
        //             $response = fgets( $fp, 1024 );
        //             dgx_donate_debug_log($this->$response);
        //             $done = in_array( $response, array( "VERIFIED", "INVALID" ) );
        //         }
        //     } while ( ! $done );
        // } else {
        //     dgx_donate_debug_log( "IPN failed ( unable to open chatbackurl, url = {$this->chat_back_url}, errno = $errno, errstr = $errstr )" );
        // }
        // fclose ($fp);

        return $response;
    }

    public function handle_verified_ipn()
    {
			$payment_status = $this->post_data["payment_status"];

        dgx_donate_debug_log("IPN VERIFIED for session ID {$this->session_id}");
        dgx_donate_debug_log("Payment status = {$payment_status}");
        //dgx_donate_debug_log( print_r( $this->post_data, true ) ); // @todo don't commit

        if ("Completed" == $payment_status) {
            // Check if we've already logged a transaction with this same transaction id
            $donation_id = get_donations_by_meta('_dgx_donate_transaction_id', $this->transaction_id, 1);

            if (0 == count($donation_id)) {
                // We haven't seen this transaction ID already

                // See if a donation for this session ID already exists
                $donation_id = get_donations_by_meta('_dgx_donate_session_id', $this->session_id, 1);

                if (0 == count($donation_id)) {
                    // We haven't seen this session ID already

                    // Retrieve the data from transient
                    $donation_form_data = get_transient($this->session_id);

                    if (!empty($donation_form_data)) {
                        // Create a donation record
                        $donation_id = dgx_donate_create_donation_from_transient_data($donation_form_data);
                        dgx_donate_debug_log("Created donation {$donation_id} from form data in transient for sessionID {$this->session_id}");

                        // Clear the transient
                        delete_transient($this->session_id);
                    } else {
                        // We have a session_id but no transient (the admin might have
                        // deleted all previous donations in a recurring donation for
                        // some reason) - so we will have to create a donation record
                        // from the data supplied by PayPal

                        $donation_id = dgx_donate_create_donation_from_paypal_data($this->post_data);
                        dgx_donate_debug_log("Created donation {$donation_id} from PayPal data (no transient data found)");
                    }
                } else {
                    // We have seen this session ID already, create a new donation record for this new transaction

                    // But first, flatten the array returned by get_donations_by_meta for _dgx_donate_session_id
                    $donation_id = $donation_id[0];

                    $old_donation_id = $donation_id;
                    $donation_id = dgx_donate_create_donation_from_donation($old_donation_id);
                    dgx_donate_debug_log("Created donation {$donation_id} (recurring donation, donor data copied from donation {$old_donation_id}");
                }
            } else {
                // We've seen this transaction ID already - ignore it
                $donation_id = '';
                dgx_donate_debug_log("Transaction ID {$this->transaction_id} already handled - ignoring");
            }

            if (!empty($donation_id)) {
                // Update the raw paypal data
                update_post_meta($donation_id, '_dgx_donate_transaction_id', $this->transaction_id);
                update_post_meta($donation_id, '_dgx_donate_payment_processor', 'PAYPALSTD');
                update_post_meta($donation_id, '_dgx_donate_payment_processor_data', $this->post_data);
                // save the currency of the transaction
                $currency_code = $this->post_data['mc_currency'];
                dgx_donate_debug_log("Payment currency = {$currency_code}");
                update_post_meta($donation_id, '_dgx_donate_donation_currency', $currency_code);
            }

            // @todo - send different notification for recurring?

            // Send admin notification
            dgx_donate_send_donation_notification($donation_id);
            // Send donor notification
            dgx_donate_send_thank_you_email($donation_id);
        }
    }

    public function handle_invalid_ipn()
    {
        dgx_donate_debug_log("IPN failed (INVALID) for sessionID {$this->session_id}");
    }

    public function handle_unrecognized_ipn($paypal_response)
    {
        dgx_donate_debug_log("IPN failed (unrecognized response) for sessionID {$this->session_id}");
        dgx_donate_debug_log($paypal_response);
    }
}

$dgx_donate_ipn_responder = new Dgx_Donate_IPN_Handler();

/**
 * We cannot send nothing, so send back just a simple content-type message
 */

echo "content-type: text/plain\n\n";
