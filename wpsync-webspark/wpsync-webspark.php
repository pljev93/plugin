<?php
/*
Plugin Name: wpsync-webspark
Description: Sync products
Version: 1.0
Author: Yevhenii Yaroshchuk
Author URI: https://www.linkedin.com/in/yevhenii-yaroshchuk-42b778157/
*/

add_filter('cron_schedules', 'cron_add_one_min');
function cron_add_one_min($schedules)
{
    $schedules['one_min'] = array(
        'interval' => 60 * 1,
        'display' => '1 time in 1 minutes'
    );
    return $schedules;
}

register_activation_hook(__FILE__, 'woocommerce_product_sync_activate');
register_deactivation_hook(__FILE__, 'woocommerce_product_sync_deactivate');

add_action('woocommerce_product_sync_update_products', 'woocommerce_product_sync_update_products');

register_activation_hook(__FILE__, 'woocommerce_product_sync_activate');
register_deactivation_hook(__FILE__, 'woocommerce_product_sync_deactivate');

function woocommerce_product_sync_activate()
{
    if (!wp_next_scheduled('woocommerce_product_sync_update_products')) {
        wp_schedule_event(time(), 'hourly', 'wpsync_webspark_activate');
        wp_schedule_event(time(), 'one_min', 'woocommerce_product_sync_update_products');
    }

    wpsync_webspark_activate();
    woocommerce_product_sync_update_products();
}

function woocommerce_product_sync_deactivate()
{
    wp_clear_scheduled_hook('woocommerce_product_sync_update_products');
}

function wpsync_webspark_activate()
{
    $api_url = 'https://wp.webspark.dev/wp-api/products';
    $response = wp_remote_get($api_url);

    if (!is_wp_error($response) && $response['response']['code'] === 200) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($data && isset($data['error']) && $data['error'] === false) {
            $api_products = $data['data'];
            $file_content = json_encode($api_products, JSON_PRETTY_PRINT);

            $file_path = plugin_dir_path(__FILE__) . 'data.txt';
            $file_path_all = plugin_dir_path(__FILE__) . 'data-all.txt';
            file_put_contents($file_path, $file_content);
            file_put_contents($file_path_all, $file_content);

        } else {
            wpsync_webspark_activate();
        }
    } else {
        wpsync_webspark_activate();
    }
}

function woocommerce_product_sync_update_products()
{
    $file_path = plugin_dir_path(__FILE__) . 'data.txt';
    $file_path_all = plugin_dir_path(__FILE__) . 'data-all.txt';
    if (file_exists($file_path)) {
        $file_content = file_get_contents($file_path);
        $api_products =
        $api_products_for_file = json_decode($file_content, true);
        $file_content_all = file_get_contents($file_path_all);
        $api_products_all = json_decode($file_content_all, true);
    } else {
        woocommerce_product_sync_update_products();
    }
    if (empty($api_products)) {
        return;
    }
    global $wpdb;
    $api_products = array_slice($api_products, 0, 50);
    $api_products_for_file = array_slice($api_products_for_file, 50);
    $file_content = json_encode($api_products_for_file, JSON_PRETTY_PRINT);
    file_put_contents($file_path, $file_content);
    $existing_skus = array();
    $existing_products = get_posts(array(
        'post_type' => 'product',
        'numberposts' => -1,
        'post_status' => 'any',
    ));

    foreach ($existing_products as $existing_product) {
        $product = wc_get_product($existing_product->ID);
        $existing_skus[] = $product->get_sku();
    }

    foreach ($existing_skus as $existing_sku) {
        $found = false;

        foreach ($api_products_all as $api_product) {
            if ($api_product['sku'] === $existing_sku) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $existing_product_id = wc_get_product_id_by_sku($existing_sku);
            if ($existing_product_id) {
                wp_delete_post($existing_product_id, true);
            }
        }
    }

    foreach ($api_products as $api_product) {
        $existing_product_id = wc_get_product_id_by_sku($api_product['sku']);

        if ($existing_product_id) {
            $existing_product = wc_get_product($existing_product_id);
            $needs_update = false;

            if ($existing_product->get_name() !== $api_product['name']) {
                $existing_product->set_name($api_product['name']);
                $needs_update = true;
            }
            if ($existing_product->get_description() !== $api_product['description']) {
                $existing_product->set_description($api_product['description']);
                $needs_update = true;
            }
            if ($existing_product->get_regular_price() !== $api_product['price']) {
                $existing_product->set_regular_price($api_product['price']);
                $needs_update = true;
            }
            if ($existing_product->get_sku() !== $api_product['sku']) {
                $existing_product->set_sku($api_product['sku']);
                $needs_update = true;
            }
            if ($existing_product->get_stock_quantity() !== $api_product['in_stock']) {
                $existing_product->set_stock_quantity($api_product['in_stock']);
                $needs_update = true;
            }
            if (isset($api_product['picture']) && $existing_product_id) {
                $image_url = $api_product['picture'];
                $upload_dir = wp_upload_dir();

                $filename = basename($image_url);
                $png_filename = pathinfo($filename, PATHINFO_FILENAME) . $api_product['sku'] . '.png';

                $existing_attachment = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM $wpdb->posts WHERE post_title = %s AND post_type = 'attachment'",
                        $png_filename
                    )
                );

                if ($existing_attachment) {
                    set_post_thumbnail($existing_product_id, $existing_attachment->ID);
                } else {
                    $image_data = file_get_contents($image_url);
                    $image = imagecreatefromstring($image_data);

                    $png_file = $upload_dir['path'] . '/' . $png_filename;
                    imagepng($image, $png_file);

                    $wp_filetype = wp_check_filetype($png_filename, null);
                    $attachment = array(
                        'post_mime_type' => $wp_filetype['type'],
                        'post_title' => sanitize_file_name($png_filename),
                        'post_content' => '',
                        'post_status' => 'inherit',
                        'width' => '200px',
                        'height' => '200px',
                    );

                    $attach_id = wp_insert_attachment($attachment, $png_file, $existing_product_id);
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    $attach_data = wp_generate_attachment_metadata($attach_id, $png_file);
                    wp_update_attachment_metadata($attach_id, $attach_data);

                    set_post_thumbnail($existing_product_id, $attach_id);
                }
            }
            if ($needs_update) {
                $existing_product->save();
            }
        } else {
            if (count($existing_products) >= 2000) {
                return;
            }
            $new_product = new WC_Product();
            $new_product->set_name($api_product['name']);
            $new_product->set_description($api_product['description']);
            $new_product->set_regular_price($api_product['price']);
            $new_product->set_sku($api_product['sku']);
            $new_product->set_stock_quantity($api_product['in_stock']);
            $new_product->set_manage_stock(true);
            $new_product->set_stock_status('instock');

            $new_product_id = $new_product->save();

            if ($new_product_id) {

                if (isset($api_product['picture'])) {
                    $image_url = $api_product['picture'];
                    $upload_dir = wp_upload_dir();
                    $filename = basename($image_url);
                    $png_filename = pathinfo($filename, PATHINFO_FILENAME) . $api_product['sku'] . '.png';
                    $existing_attachment = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT * FROM $wpdb->posts WHERE post_title = %s AND post_type = 'attachment'",
                            $png_filename
                        )
                    );

                    if ($existing_attachment) {
                        set_post_thumbnail($new_product_id, $existing_attachment->ID);
                    } else {
                        $image_data = file_get_contents($image_url);
                        $image = imagecreatefromstring($image_data);

                        $png_file = $upload_dir['path'] . '/' . $png_filename;
                        imagepng($image, $png_file);

                        $wp_filetype = wp_check_filetype($png_filename, null);
                        $attachment = array(
                            'post_mime_type' => $wp_filetype['type'],
                            'post_title' => sanitize_file_name($png_filename),
                            'post_content' => '',
                            'post_status' => 'inherit',
                            'width' => '200px',
                            'height' => '200px',
                        );

                        $attach_id = wp_insert_attachment($attachment, $png_file, $new_product_id);
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        $attach_data = wp_generate_attachment_metadata($attach_id, $png_file);
                        wp_update_attachment_metadata($attach_id, $attach_data);

                        set_post_thumbnail($new_product_id, $attach_id);
                    }
                }
            }
        }
    }

}
