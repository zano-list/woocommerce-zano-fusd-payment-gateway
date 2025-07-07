<?php
/**
 * Zano Payment Gateway QR Code Generator
 *
 * @package ZanoPaymentGateway
 */

defined('ABSPATH') || exit;

/**
 * QR code generator class
 */
class Zano_QR_Generator {

    /**
     * Generate and output QR code
     */
    public function generate_and_output() {
        // Check if data parameter exists
        if (!isset($_GET['data'])) {
            wp_die('Missing data parameter');
        }
        
        // Get and sanitize data
        $data = sanitize_text_field(urldecode($_GET['data']));
        $logo_asset = isset($_GET['logo']) ? sanitize_text_field($_GET['logo']) : '';
        
        // Set content type
        header('Content-Type: image/png');
        
        // Check if GD library is available
        if (!function_exists('imagecreate')) {
            $this->output_fallback_image();
            return;
        }
        
        // Use a QR library if available, or include one
        if (!class_exists('QRcode')) {
            $this->include_phpqrcode_library();
        }
        
        // Check if library is available after inclusion attempt
        if (!class_exists('QRcode')) {
            $this->output_fallback_image();
            return;
        }
        
        // Generate QR code with logo if requested
        if (!empty($logo_asset)) {
            $this->generate_qr_with_logo($data, $logo_asset);
        } else {
            // Generate and output QR code directly
            QRcode::png($data, null, QR_ECLEVEL_L, 5, 2);
        }
        exit;
    }

    /**
     * Include PHPQRCode library
     */
    private function include_phpqrcode_library() {
        $phpqrcode_path = ZANO_PAYMENT_PLUGIN_DIR . 'includes/phpqrcode/qrlib.php';
        
        // If the library doesn't exist, automatically download it
        if (!file_exists($phpqrcode_path)) {
            $this->download_phpqrcode_library();
        }
        
        // Check if library file exists now
        if (file_exists($phpqrcode_path)) {
            require_once $phpqrcode_path;
        }
    }

    /**
     * Download PHPQRCode library
     */
    private function download_phpqrcode_library() {
        $phpqrcode_dir = ZANO_PAYMENT_PLUGIN_DIR . 'includes/phpqrcode';
        
        // Create directory if it doesn't exist
        if (!file_exists($phpqrcode_dir)) {
            mkdir($phpqrcode_dir, 0755, true);
        }
        
        // Download the phpqrcode library
        $zipfile = download_url('https://sourceforge.net/projects/phpqrcode/files/phpqrcode.zip/download');
        
        if (!is_wp_error($zipfile)) {
            // Extract the zip file
            $zip = new ZipArchive;
            if ($zip->open($zipfile) === TRUE) {
                $zip->extractTo($phpqrcode_dir);
                $zip->close();
            }
            
            // Remove the zip file
            @unlink($zipfile);
        }
    }

    /**
     * Output fallback image when QR library is not available
     */
    private function output_fallback_image() {
        $img = imagecreate(300, 100);
        $bg = imagecolorallocate($img, 255, 255, 255);
        $textcolor = imagecolorallocate($img, 0, 0, 0);
        imagestring($img, 5, 10, 40, 'QR Library not available', $textcolor);
        imagepng($img);
        imagedestroy($img);
        exit;
    }

    /**
     * Generate QR code with logo embedded in center
     */
    private function generate_qr_with_logo($data, $logo_asset) {
        // Create temporary file for QR code
        $temp_qr = tempnam(sys_get_temp_dir(), 'qr_temp');
        
        // Generate QR code to temporary file with higher error correction for logo embedding
        QRcode::png($data, $temp_qr, QR_ECLEVEL_H, 8, 2);
        
        // Load QR code image
        $qr_image = imagecreatefrompng($temp_qr);
        if (!$qr_image) {
            // Fallback if QR generation fails
            QRcode::png($data, null, QR_ECLEVEL_L, 5, 2);
            return;
        }
        
        // Get QR code dimensions
        $qr_width = imagesx($qr_image);
        $qr_height = imagesy($qr_image);
        
        // Determine logo path based on asset
        $logo_path = $this->get_logo_path($logo_asset);
        
        // If logo doesn't exist, output QR without logo
        if (empty($logo_path) || !file_exists($logo_path)) {
            imagepng($qr_image);
            imagedestroy($qr_image);
            unlink($temp_qr);
            return;
        }
        
        // Load logo image
        $logo_image = $this->load_logo_image($logo_path);
        
        if (!$logo_image) {
            // If logo loading fails, output QR without logo
            imagepng($qr_image);
            imagedestroy($qr_image);
            unlink($temp_qr);
            return;
        }
        
        // Apply logo to QR code
        $this->apply_logo_to_qr($qr_image, $logo_image, $qr_width, $qr_height);
        
        // Output the final image
        imagepng($qr_image);
        
        // Cleanup
        imagedestroy($qr_image);
        imagedestroy($logo_image);
        unlink($temp_qr);
    }

    /**
     * Get logo path based on asset
     */
    private function get_logo_path($logo_asset) {
        $logo_path = '';
        if ($logo_asset === 'zano') {
            $logo_path = ZANO_PAYMENT_PLUGIN_DIR . 'assets/images/zano-icon.png';
        } elseif ($logo_asset === 'fusd') {
            $logo_path = ZANO_PAYMENT_PLUGIN_DIR . 'assets/images/fusd-icon.png';
        }
        return $logo_path;
    }

    /**
     * Load logo image
     */
    private function load_logo_image($logo_path) {
        $logo_image = null;
        $logo_ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
        
        switch ($logo_ext) {
            case 'png':
                $logo_image = imagecreatefrompng($logo_path);
                break;
            case 'jpg':
            case 'jpeg':
                $logo_image = imagecreatefromjpeg($logo_path);
                break;
            case 'ico':
                // For ICO files, try to convert or use PNG fallback
                $png_logo = str_replace('.ico', '.png', $logo_path);
                if (file_exists($png_logo)) {
                    $logo_image = imagecreatefrompng($png_logo);
                }
                break;
        }
        
        return $logo_image;
    }

    /**
     * Apply logo to QR code
     */
    private function apply_logo_to_qr($qr_image, $logo_image, $qr_width, $qr_height) {
        // Calculate logo size (about 20% of QR code size)
        $logo_size = min($qr_width, $qr_height) * 0.2;
        $logo_x = ($qr_width - $logo_size) / 2;
        $logo_y = ($qr_height - $logo_size) / 2;
        
        // Create white background circle for logo (to ensure readability)
        $white_bg_size = $logo_size * 1.2;
        
        // Draw white circular background
        $white = imagecolorallocate($qr_image, 255, 255, 255);
        imagefilledellipse($qr_image, $qr_width/2, $qr_height/2, $white_bg_size, $white_bg_size, $white);
        
        // Resize and copy logo to center of QR code
        imagecopyresampled(
            $qr_image, $logo_image,
            $logo_x, $logo_y, 0, 0,
            $logo_size, $logo_size,
            imagesx($logo_image), imagesy($logo_image)
        );
    }
} 