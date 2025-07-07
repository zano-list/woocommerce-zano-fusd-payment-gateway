<?php
/**
 * Simple QR Code Library
 * 
 * Fallback implementation using Google Chart API
 * For WordPress/Zano payment gateway plugin
 */

defined('ABSPATH') || exit;

class QRcode {
    /**
     * Output QR code as PNG
     * 
     * @param string $text Text to encode
     * @param string $outfile File to output, or null for direct output
     * @param string $level Error correction level (not used in this implementation)
     * @param int $size Size of the QR code
     * @param int $margin Margin around the QR code
     * @return void
     */
    public static function png($text, $outfile = null, $level = 'L', $size = 5, $margin = 2) {
        // Sanitize and encode the text
        $encodedText = rawurlencode($text);
        
        // Use the more reliable QR code API
        $api_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encodedText}";
        
        // Set headers for PNG output
        header('Content-Type: image/png');
        
        if ($outfile === null) {
            // Direct output to browser
            $image_data = wp_remote_get($api_url);
            
            if (!is_wp_error($image_data) && wp_remote_retrieve_response_code($image_data) == 200) {
                echo wp_remote_retrieve_body($image_data);
            } else {
                // Fallback to a simple image
                self::generate_fallback_image();
            }
        } else {
            // Output to file
            $image_data = wp_remote_get($api_url);
            
            if (!is_wp_error($image_data) && wp_remote_retrieve_response_code($image_data) == 200) {
                file_put_contents($outfile, wp_remote_retrieve_body($image_data));
            }
        }
    }
    
    /**
     * Generate a fallback image when QR code generation fails
     */
    private static function generate_fallback_image() {
        if (function_exists('imagecreate')) {
            $im = imagecreate(300, 300);
            $bg = imagecolorallocate($im, 255, 255, 255);
            $textcolor = imagecolorallocate($im, 0, 0, 0);
            imagestring($im, 5, 60, 140, 'QR Code Generation Failed', $textcolor);
            imagestring($im, 3, 50, 160, 'Please copy address and amount manually', $textcolor);
            imagepng($im);
            imagedestroy($im);
        } else {
            // If GD is not available, output a simple message
            echo 'QR Code Generation Failed - Please copy address and amount manually';
        }
    }
}

// Define constants for compatibility
if (!defined('QR_ECLEVEL_L')) define('QR_ECLEVEL_L', 'L');
if (!defined('QR_ECLEVEL_M')) define('QR_ECLEVEL_M', 'M');
if (!defined('QR_ECLEVEL_Q')) define('QR_ECLEVEL_Q', 'Q');
if (!defined('QR_ECLEVEL_H')) define('QR_ECLEVEL_H', 'H'); 