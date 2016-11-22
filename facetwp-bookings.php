<?php
/*
Plugin Name: FacetWP - Bookings Integration
Description: WooCommerce Bookings support
Version: 0.4.1
Author: FacetWP, LLC
Author URI: https://facetwp.com/
GitHub URI: facetwp/facetwp-bookings
*/

defined( 'ABSPATH' ) or exit;

/**
 * Register facet type
 */
add_filter( 'facetwp_facet_types', function( $facet_types ) {
    $facet_types['availability'] = new FacetWP_Facet_Availability();
    return $facet_types;
});


/**
 * Availability facet
 */
class FacetWP_Facet_Availability
{
    public $product_ids;
    public $product_to_job_listings; // key = product ID, value = array of job_listing IDs


    function __construct() {
        $this->label = __( 'Availability', 'fwp' );

        add_filter( 'facetwp_store_unfiltered_post_ids', '__return_true' );
        add_filter( 'facetwp_bookings_filter_posts', array( $this, 'wpjm_products_integration' ) );
    }


    /**
     * Generate the facet HTML
     */
    function render( $params ) {
        $value = $params['selected_values'];
        $value = empty( $value ) ? array( '', '', 1 ) : $value;

        $output = '';
        $output .= '<input type="text" class="facetwp-date facetwp-date-min" value="' . $value[0] . '" placeholder="' . __( 'Start Date', 'fwp' ) . '" />';
        $output .= '<input type="text" class="facetwp-date facetwp-date-max" value="' . $value[1] . '" placeholder="' . __( 'End Date', 'fwp' ) . '" />';
        $output .= '<input type="number" class="facetwp-quantity" value="1" min="' . $value[2] . '" max="20" placeholder="' . __( 'Quantity', 'fwp' ) . '" />';
        $output .= '<input type="submit" class="facetwp-availability-update" value="' . __( 'Update', 'fwp' ) . '" />';
        return $output;
    }


    /**
     * Filter the query based on selected values
     */
    function filter_posts( $params ) {
        global $wpdb;

        $output = array();
        $facet = $params['facet'];
        $values = $params['selected_values'];

        $start_date = empty( $values[0] ) ? '' : $values[0];
        $end_date = empty( $values[1] ) ? '' : $values[1];
        $quantity = empty( $values[2] ) ? 1 : (int) $values[2];

        // WPJM Products integration
        if ( function_exists( 'wpjmp' ) ) {
            $temp = array();
            foreach ( FWP()->unfiltered_post_ids as $post_id ) {
                if ( 'job_listing' == get_post_type( $post_id ) ) {
                    $related_product_ids = (array) get_post_meta( $post_id, '_products', true );
                    foreach ( $related_product_ids as $id ) {
                        $this->product_to_job_listings[ $id ][] = $post_id;
                        $temp[ $id ] = true;
                    }
                }
            }
            $this->product_ids = array_keys( $temp );
        }
        else {
            $this->product_ids = FWP()->unfiltered_post_ids;
        }

        // Get available bookings
        if ( $this->is_valid_date( $start_date ) && $this->is_valid_date( $end_date ) ) {
            $output = $this->get_available_bookings( $start_date, $end_date, $quantity );
        }

        return apply_filters( 'facetwp_bookings_filter_posts', $output );
    }


    /**
     * Get all available booking products
     *
     * @param string $start_date YYYY-MM-DD format
     * @param string $end_date YYYY-MM-DD format
     * @param int $quantity Number of people to book
     * @return array Available post IDs
     */
    function get_available_bookings( $start_date, $end_date, $quantity = 1 ) {
        $matches = array();

        $start_date = explode( ' ', $start_date );
        $end_date = explode( ' ', $end_date );
        $start = explode( '-', $start_date[0] );
        $end = explode( '-', $end_date[0] );

        $args = array(
            'wc_bookings_field_persons' => $quantity,
            'wc_bookings_field_duration' => 1,
            'wc_bookings_field_start_date_year' => $start[0],
            'wc_bookings_field_start_date_month' => $start[1],
            'wc_bookings_field_start_date_day' => $start[2],
            'wc_bookings_field_start_date_to_year' => $end[0],
            'wc_bookings_field_start_date_to_month' => $end[1],
            'wc_bookings_field_start_date_to_day' => $end[2],
        );

        // Loop through all posts
        foreach ( $this->product_ids as $post_id ) {
            if ( 'product' == get_post_type( $post_id ) ) {
                $product = wc_get_product( $post_id );
                if ( is_wc_booking_product( $product ) ) {

                    // Support time
                    if ( 'hour' == $product->get_duration_unit() ) {
                        if ( ! empty( $start_date[1] ) ) {
                            $args['wc_bookings_field_start_date_time'] = $start_date[1];
                        }
                    }

                    // Support WooCommerce Accomodation Bookings plugin
                    // @src woocommerce-bookings/includes/booking-form/class-wc-booking-form.php
                    $unit = ( 'accommodation-booking' == $product->product_type ) ? 'night' : 'day';
                    $duration = $this->calculate_duration( $start_date[0], $end_date[0], $unit );
                    $args['wc_bookings_field_duration'] = $duration;

                    $booking_form = new WC_Booking_Form( $product );
                    $posted_data = $booking_form->get_posted_data( $args );

                    // Returns WP_Error on fail
                    if ( true === $booking_form->is_bookable( $posted_data ) ) {
                        $matches[] = $post_id;
                    }
                }
            }
        }

        return $matches;
    }


    /**
     * WPJM - Products plugin integration
     * Use $this->product_to_job_listings to include related job_listing IDs
     */
    function wpjm_products_integration( $product_ids ) {
        if ( function_exists( 'wpjmp' ) ) {
            $job_listing_ids = array();
            foreach ( $product_ids as $pid ) {
                if ( isset( $this->product_to_job_listings[ $pid ] ) ) {
                    foreach ( $this->product_to_job_listings[ $pid ] as $job_listing_id ) {
                        $job_listing_ids[ $job_listing_id ] = true; // prevents duplicate IDs!
                    }
                }
            }

            foreach ( array_keys( $job_listing_ids ) as $id ) {
                $product_ids[] = $id;
            }
        }

        return $product_ids;
    }


    /**
     * Calculate days between 2 date intervals
     *
     * @requires PHP 5.3+
     */
    function calculate_duration( $start_date, $end_date, $unit = 'day' ) {
        if ( $start_date > $end_date ) {
            return 0;
        }
        if ( $start_date == $end_date ) {
            return 1;
        }

        $start = new DateTime( $start_date );
        $end = new DateTime( $end_date );
        $diff = (int) $end->diff( $start )->format( '%a' );
        return ( 'day' == $unit ) ? $diff + 1 : $diff;
    }


    /**
     * Validate date input
     *
     * @requires PHP 5.3+
     */
    function is_valid_date( $date ) {
        if ( empty( $date ) ) {
            return false;
        }
        elseif ( 10 === strlen( $date ) ) {
            $d = DateTime::createFromFormat( 'Y-m-d', $date );
            return $d && $d->format( 'Y-m-d' ) === $date;
        }
        elseif ( 16 === strlen( $date ) ) {
            $d = DateTime::createFromFormat( 'Y-m-d H:i', $date );
            return $d && $d->format( 'Y-m-d H:i' ) === $date;
        }

        return false;
    }


    /**
     * Output any admin scripts
     */
    function admin_scripts() {
?>
<script>
(function($) {
    wp.hooks.addAction('facetwp/change/availability', function($this) {
        $this.closest('.facetwp-row').find('.name-source').hide();
    });

    wp.hooks.addFilter('facetwp/save/availability', function($this, obj) {
        return obj;
    });
})(jQuery);
</script>
<?php
    }


    /**
     * Output any front-end scripts
     */
    function front_scripts() {
        FWP()->display->assets['flatpickr.css'] = FACETWP_URL . '/assets/js/flatpickr/flatpickr.css';
        FWP()->display->assets['flatpickr.js'] = FACETWP_URL . '/assets/js/flatpickr/flatpickr.min.js';
?>
<script>
(function($) {
    wp.hooks.addAction('facetwp/refresh/availability', function($this, facet_name) {
        var min = $this.find('.facetwp-date-min').val() || '';
        var max = $this.find('.facetwp-date-max').val() || '';
        var quantity = $this.find('.facetwp-quantity').val() || 1;
        FWP.facets[facet_name] = ('' != min && '' != max) ? [min, max, quantity] : [];
    });

    wp.hooks.addFilter('facetwp/selections/availability', function(output, params) {
        return params.selected_values[0] + ' - ' + params.selected_values[1];
    });

    $(document).on('facetwp-loaded', function() {
        var $dates = $('.facetwp-type-availability .facetwp-date:not(.ready)');
        if (0 === $dates.length) {
            return;
        }

        var flatpickr_opts = {
            //enableTime: true,
            minDate: new Date().toISOString().slice(0, 10),
            onReady: function(dateObj, dateStr, instance) {
                var $cal = $(instance.calendarContainer);
                if ($cal.find('.flatpickr-clear').length < 1) {
                    $cal.append('<div class="flatpickr-clear">Clear</div>');
                    $cal.find('.flatpickr-clear').on('click', function() {
                        instance.clear();
                        instance.close();
                    });
                }
            }
        };

        $dates.each(function() {
            var facet_name = $(this).closest('.facetwp-facet').attr('data-name');
            var opts = wp.hooks.applyFilters('facetwp/set_options/availability', flatpickr_opts, {
                'facet_name': facet_name
            });
            new Flatpickr(this, opts);
            $(this).addClass('ready');
        });
    });

    $(document).on('click', '.facetwp-availability-update', function() {
        FWP.autoload();
    });
})(jQuery);
</script>
<?php
    }
}
