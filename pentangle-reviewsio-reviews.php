<?php
/**
 * Plugin Name: ReviewsIO Reviews Fetcher
 * Description: A plugin to fetch and display ReviewsIO reviews using ReviewsIO API, with settings in the WordPress dashboard and caching for better performance.
 * Version: 1.3
 * Author: Pentangle Technology Limited
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Register a menu item in the WordPress dashboard
function prr_add_admin_menu()
{
    add_menu_page(
        'ReviewsIO Reviews Settings',       // Page Title
        'ReviewsIO Reviews',                // Menu Title
        'manage_options',                // Capability
        'prr-settings',                  // Menu Slug
        'prr_settings_page',             // Callback function
        'dashicons-admin-site',          // Icon
        100                              // Position
    );
}

add_action('admin_menu', 'prr_add_admin_menu');

// Create the settings page
function prr_settings_page()
{

    // Check if settings have been saved
    if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
        echo '<div class="updated notice is-dismissible"><p>Settings saved successfully!</p></div>';
    }

    // Handle cache clear request
    if (isset($_POST['prr_clear_cache'])) {
        prr_clear_cache();
        echo '<div class="updated"><p>ReviewsIO cache cleared successfully!</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>ReviewsIO Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('prr_settings_group');
            do_settings_sections('prr-settings');
            submit_button();
            ?>
        </form>

        <?php prr_options_section_callback(); ?>

        <!-- Add cache clearing button -->
        <h2>Clear Cache</h2>
        <form method="post">
            <input type="hidden" name="prr_clear_cache" value="1">
            <input type="submit" class="button-primary" value="Clear ReviewsIO Cache">
        </form>
    </div>
    <?php
}

// Register settings, sections, and fields
function prr_settings_init()
{
    register_setting('prr_settings_group', 'prr_api_key');

    add_settings_section(
        'prr_settings_section',
        'ReviewsIO API Settings',
        'prr_settings_section_callback',
        'prr-settings'
    );


    add_settings_field(
        'prr_api_key',
        'ReviewsIO identifier Key',
        'prr_api_key_render',
        'prr-settings',
        'prr_settings_section'
    );

}

add_action('admin_init', 'prr_settings_init');

function prr_settings_section_callback()
{
    echo 'To get your ReviewsIO Key, visit the <a href="https://developers.google.com/places/web-service/get-api-key" target="_blank">Google Developers Console</a>.<br><br>';
    echo 'As this plugin uses a background request to get the data you must set your restrictions based on the IP address of your server rather than the domain.<br><br>';
    echo 'Enter your ReviewsIO below:';
}

function prr_options_section_callback()
{
    echo '<h2>Displaying Reviews</h2>';
    echo 'To display the reviews, use the following code in the file:<br>';
    echo '<pre>[reviewsio_reviews number="5" min_rating="3"]</pre><br>';
    echo 'If you would like to override the default output, create a file called <code>pentangle-reviewsio-reviews.php</code> in your theme folder.<br><br>';
    echo 'The data is cached for 5 minutes to reduce the number of requests to the Google Places API.<br><br>';
    echo 'The data is available to your template file in the variable <code>$prr_reviews</code>.<br><br>';
    echo 'The average rating and total number of reviews are available in the variable <code>$prr_review_data</code>.<br><br>';
    echo 'The Google logo is available in the plugin folder as <code>google_g_icon_download.png</code> using plugin_dir_url(__FILE__).\'google_g_icon_download.png\'<br><br>';
}

function prr_api_key_render()
{
    $api_key = get_option('prr_api_key');
    ?>
    <input type="text" name="prr_api_key" value="<?php echo esc_attr($api_key); ?>" style="width: 400px;" />
    <?php
}

function prr_place_id_render()
{
    $place_id = get_option('prr_place_id');
    ?>
    <input type="text" name="prr_place_id" value="<?php echo esc_attr($place_id); ?>" style="width: 400px;" />
    <?php
}

// Clear transient cache function
function prr_clear_cache()
{
    global $wpdb;
    $sql = "DELETE
            FROM  $wpdb->options
            WHERE `option_name` LIKE '%prr_reviewsio_reviews_data%'
            ORDER BY `option_name`";

    $results = $wpdb->query($sql);
}


// Shortcode to display Google Reviews
function prr_display_reviewsio_reviews($atts)
{
    // Get API Key and Place ID from the options saved in the dashboard
    $api_key = get_option('prr_api_key');

    //check if there is a file called pentangle-google-reviews.php in the theme folder

    $template = 'pentangle-reviewsio-reviews';

    if (isset($atts['template'])) {
        $template = $atts['template'];
    }


    // If API Key or Place ID is missing, return an error message
    if (empty($api_key)) {
        return '<p>Error: API key is not set in the settings page.</p>';
    }

    // Shortcode attributes to override settings if provided
    $atts = shortcode_atts(
        array(
            'number' => 5, // Number of reviews to display
            'min_rating' => 0    // Minimum rating to display reviews
        ),
        $atts
    );

    // Cache key to store/retrieve the reviews JSON
    $cache_key = 'prr_reviewsio_reviews_data';

    // Check if cached JSON data exists and is not expired (5-minute expiration)
    $cached_data = get_transient($cache_key);

    // If cached data exists, decode it
    if ($cached_data) {
        $data = json_decode($cached_data, true);
    } else {
        // Call the ReviewsIO API to fetch reviews
        $url = "https://api.reviews.co.uk/timeline/data?type=store_review&store={$api_key}&sort=date_desc&page=1&per_page=100&enable_avatars=false&include_subrating_breakdown=1&branch=&tag=&include_product_reviews=1&sku=&include_local_reviews=1&lang=en";

        $response = wp_remote_get($url);

        // Check for errors in the API response
        if (is_wp_error($response)) {
            return '<p>Error fetching reviews.</p>';
        }

        // Only proceed if we get a 200 OK response
        if (wp_remote_retrieve_response_code($response) === 200) {
            // Decode the JSON response
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // Check if there are reviews in the response
            if (empty($data['timeline'])) {

                //write the response to the wp error log
                error_log($url);
                error_log(print_r($data, 1));
                return '<p>No reviews found for this location.</p>';
            }

            // Cache the JSON response for 5 minutes (300 seconds)
            set_transient($cache_key, $body, 5 * MINUTE_IN_SECONDS);
        } else {
            return '<p>Error: Could not retrieve valid reviews data from the API.</p>';
        }
    }

    foreach ($data['timeline'] as $key => $review) {
        $data['timeline'][$key]['_source']['stars'] = prr_generate_stars($review['_source']['rating']);
    }

    // Limit the number of reviews to display after filtering
    $prr_reviews = array_slice($data['timeline'], 0, $atts['number']);
    $prr_review_data = ['rating' => $data['timeline'][$key]['_source']['rating'], 'user_ratings_total' => $data['stats']['average_rating']];

    // Start outputting the reviews in HTML
    ob_start();

    if (file_exists(get_template_directory() . '/' . $template . '.php')) {
        include get_template_directory() . '/' . $template . '.php';
    } else {
        pentangle_reviewsio_review_css();
        echo '<div class="reviewsio-reviews">';
        foreach ($prr_reviews as $review) {
            ?>
            <div class="reviewsio-review">
                <p><strong><?= esc_html($review['_source']['author']); ?></strong> <?= (isset($review['_source']['reviewer_desc']) && $review['_source']['reviewer_desc']) ? " - ".esc_html($review['_source']['reviewer_desc']) : ""; ?></p>
                <p>Rating: <?= $review['_source']['stars']; ?></p>
                <p><?= esc_html($review['_source']['comments']); ?></p>
                <p><em><?= esc_html($review['_source']['human_date']) ?></em></p>
            </div>
            <hr />
            <?php
        }

        //create a link to the google_g_icon_download.png in the plugin folder
        echo '<div class="reviewsio-overall-rating">';
        echo '<img src="https://assets.reviews.io/img/all-global-assets/logo/reviewsio-logo.svg" alt="ReviewsIO Reviews" style="width: 100px; height: 100px;">';
        echo '<p>Average Rating: ' . $prr_review_data['rating'] . ' out of 5 based on ' . $prr_review_data['user_ratings_total'] . ' reviews</p>';
        echo '<p><a href="https://www.reviews.co.uk/company-reviews/store/'. $api_key .'" target="_blank">Read more reviews on ReviewsIO</a></p>';
        echo '</div>';
        echo '</div>';

    }


    return ob_get_clean();
}

function pentangle_reviewsio_review_css()
{
    $plugin_url = plugin_dir_url(__FILE__);
    wp_enqueue_style('gr_styles', $plugin_url . "/css/plugin-style.css");
}

function prr_generate_stars($rating)
{

    //load the star.svg file and repeat it 5 times changing the colour from yellow to gray

    $stars = '<div class="reviewsio-star-rating">';
    for ($i = 0; $i <= 4; $i++) {

        //check if the rating is less than the current star and change the file to star-empty or star-half for any over 0.5

        if ($rating - $i >= 1) {
            $file = 'star-full';
        } elseif ($rating - $i > 0.5) {
            $file = 'star-half';
        } else {
            $file = 'star-empty';
        }

        //$file = ($i <= $rating) ? 'star-full' : 'star-empty';
//        $stars .= '<img src="' . plugin_dir_url(__FILE__) . $file . '.svg" class="review-star">';

        $stars .= file_get_contents(plugin_dir_path(__FILE__) . $file . '.svg');

    }
    $stars .= '</div>';
    return $stars;
}

add_action('init', 'pentangle_activate_wp');
function pentangle_activate_wp()
{
    require_once('wp_autoupdate.php');      // File which contains the Class below
    $pentangle_plugin_current_version = '1.3';
    $pentangle_plugin_remote_path = 'https://scripts.pentangle.co.uk/pentangle-reviewsio-reviews/update.php';
    $pentangle_plugin_slug = plugin_basename(__FILE__);
    new wp_auto_update($pentangle_plugin_current_version, $pentangle_plugin_remote_path, $pentangle_plugin_slug);
}

// Register the shortcode [google_reviews number=""]
add_shortcode('reviewsio_reviews', 'prr_display_reviewsio_reviews');
