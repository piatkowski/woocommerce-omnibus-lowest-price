<?php
/**
 * Plugin Name: WooCommerce Omnibus Lowest Price
 * Description: Plugin automatically remembers and displays the lowest price of the product before the discount from the last 30 days.
 * Version: 1.2.0
 * Author: Krzysztof Piątkowski
 * Author URI: https://github.com/piatkowski/
 * License: GPL2
 */

defined( 'ABSPATH' ) or die( 'No direct script access' );

if ( ! class_exists( 'WCOLP_Plugin' ) ) {
	class WCOLP_Plugin {

		const VERSION = '1.2.0.0';

		const MONTH_IN_SECONDS = 2678400;

		const META_KEY = 'wcolp_data';

		/**
		 * @var WCOLP_Plugin|null singleton instance
		 */
		private static ?WCOLP_Plugin $instance = null;

		/**
		 * @var array product updated cache
		 */
		private $updated_products = [];

		/**
		 * Get singleton instance
		 *
		 * @return WCOLP_Plugin
		 */
		public static function instance(): WCOLP_Plugin {
			if ( ! self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Registers hooks on class creation
		 */
		private function __construct() {

			/* Assets */
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

			/* Frontend */
			add_action( 'woocommerce_product_meta_start', [ $this, 'render_product_page' ], 10 );
			add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_product_list' ], 99 );

			/* Admin - views */
			add_action( 'woocommerce_variation_options', [ $this, 'variation_options' ], 10, 3 );
			add_action( 'woocommerce_product_options_pricing', [ $this, 'product_options' ] );

			/* Admin - events */
			add_action( 'woocommerce_product_object_updated_props', [ $this, 'product_updated' ], 10, 2 );

		}

		/**
		 * Update product or variation WCOLP metadata (lowest price and timestamp)
		 *
		 * @param $product
		 * @param $price_before
		 *
		 * @return void
		 */
		private function maybe_update_lowest_price( $product, $price_before ) {

			$can_update = false;
			if ( ! $product->meta_exists( self::META_KEY ) ) {
				$can_update = true;
			} else {
				$data = $product->get_meta( self::META_KEY );

				if ( empty( $data ) || empty( $data['price'] ) || floatval( str_replace( ',', '.', $price_before ) ) < floatval( $data['price'] ) || time() - $data['time'] > MONTH_IN_SECONDS ) {
					$can_update = true;
				}
			}
			if ( $can_update ) {
				$product->update_meta_data( self::META_KEY, [
					'time'  => time(),
					'price' => floatval( $price_before )
				] );
				$product->save_meta_data();
			}
		}

		/**
		 * Update WCOLP metadata on product save (on props updated)
		 *
		 * @param $product
		 * @param $updated_props
		 *
		 * @return void
		 */
		public function product_updated( $product, $updated_props ) {

			$product_id = $product->get_id();

			$already_updated = isset( $this->updated_products[ $product_id ] ) && $this->updated_products[ $product_id ] === true;

			if ( $already_updated ) {
				return;
			}

			$is_override_mode = isset( $_POST['_wcolp_price_override'] ) && isset( $_POST['_wcolp_price_override'][ $product_id ] );

			if ( $is_override_mode ) {
				$product->update_meta_data( self::META_KEY, [
					'time'  => time(),
					'price' => floatval( str_replace( ',', '.', $_POST['_wcolp_price_override'][ $product_id ] ) )
				] );
				$product->save_meta_data();
				$this->updated_products[ $product_id ] = true;

				return;
			}

			$is_price_prop_updated = in_array( 'regular_price', $updated_props ) || in_array( 'sale_price', $updated_props );
			$is_price_changed      = isset( $_POST['_wcolp_price_before'] ) && isset( $_POST['_wcolp_price_before'][ $product_id ] );

			if ( $is_price_prop_updated && $is_price_changed ) {
				$this->maybe_update_lowest_price( $product, $_POST['_wcolp_price_before'][ $product_id ] );
				$this->updated_products[ $product_id ] = true;
			}

		}

		/**
		 * Prepare 'lowest price' text for clients
		 *
		 * @param $product_id
		 *
		 * @return string
		 */
		private function get_lowest_price_text( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product->meta_exists( self::META_KEY ) && $product->is_on_sale() ) {
				$data  = $product->get_meta( self::META_KEY );
				$time  = $data['time'] ?? null;
				$price = floatval( $data['price'] ) ?? null;
				if ( $time && $price ) {
					if ( time() - $time > self::MONTH_IN_SECONDS ) {
						$price = $product->get_regular_price();
					}
					$message = __( 'Najniższa cena z 30 dni przed obniżką: ', 'wcolp' ) . strip_tags( wc_price( $price ) );

					return apply_filters( 'wcolp_message', $message, $price );
				}
			}

			return '';
		}

		/**
		 * Get product and variations WCOLP metadata (price and timestamp)
		 *
		 * @param $product_id
		 *
		 * @return array
		 */
		private function get_lowest_price_meta( $product_id ) {
			$product             = wc_get_product( $product_id );
			$data[ $product_id ] = $this->get_lowest_price_text( $product_id );
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$data[ $variation_id ] = $this->get_lowest_price_text( $variation_id );
				}
			}

			return $data;
		}

		/**
		 * Render HTML for product page (both simple and variable product)
		 *
		 * @return void
		 * @todo support for grouped products
		 *
		 */
		public function render_product_page() {
			if ( is_product() ) {
				global $product;

				echo apply_filters( 'wcolp_before_product_page', '<div id="wcolp-container">' );
				if ( $product->is_type( 'variable' ) === false ) {
					echo wp_kses_post( $this->get_lowest_price_text( $product->get_id() ) );
				}
				echo apply_filters( 'wcolp_after_product_page', '</div>' );

			}
		}

		/**
		 * Render HTML code for product archives and listings
		 *
		 * @return void
		 * @todo support for grouped products
		 *
		 */
		public function render_product_list() {
			if ( ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) && ! is_product() ) {
				global $product;
				$variation_id = null;
				if ( $product->is_type( 'variable' ) ) {
					if ( ! $product->is_on_sale() ) {
						$prices = $product->get_variation_prices(); // called on WC_Product_Variable
						//$price        = floatval( current( $prices['price'] ) );
						$variation_id = key( $prices['price'] ); //get first ID
					} else {
						$spri = 999999; //sale price
						$rpri = 888888; //regular price
						foreach ( $product->get_children() as $child_id ) {
							$variation = wc_get_product( $child_id );
							$price     = $variation->get_regular_price();
							$sale      = $variation->get_sale_price();
							if ( $price != 0 && ! empty( $sale ) ) {
								if ( $price < $rpri ) {
									$rpri         = $price;
									$variation_id = $child_id;
								}
								if ( $sale < $spri ) {
									$spri         = $sale;
									$variation_id = $child_id;
								}
							}
						}
					}
				} else {
					$variation_id = $product->get_id();
				}
				echo apply_filters( 'wcolp_before_product_list', '<div class="wcolp-container">' );
				echo wp_kses_post( $this->get_lowest_price_text( $variation_id ) );
				echo apply_filters( 'wcolp_after_product_list', '</div>' );
			}
		}

		/**
		 * Render HTML code of custom fields
		 *
		 * @param $product_object
		 * @param $_id
		 *
		 * @return void
		 */
		public function render_price_override_block( $product_object, $_id = '_single' ) {
			woocommerce_wp_checkbox( array(
				'id'                => '_wcolp_price_override_enabled' . $_id,
				'label'             => 'Nadpisuję cenę',
				'wrapper_class'     => $_id !== '_single' ? 'wcolp_form-field form-row form-row-first' : '',
				'description'       => __( 'Zaznacz, aby nadpisać najniższą cenę z 30 dni', 'woocommerce' ),
				'custom_attributes' => array(
					'onchange'     => "jQuery('#_wcolp_price_override" . $_id . "').prop('disabled', ! jQuery(this).is(':checked'))",
					'autocomplete' => 'off'
				)
			) );

			$meta_exists = $product_object->meta_exists( self::META_KEY );
			$meta_data   = $meta_exists ? $product_object->get_meta( self::META_KEY ) : null;

			woocommerce_wp_text_input(
				array(
					'id'                => '_wcolp_price_override' . $_id,
					'name'              => '_wcolp_price_override[' . $product_object->get_id() . ']',
					'value'             => $meta_exists ? $meta_data['price'] : 0,
					'wrapper_class'     => $_id !== '_single' ? 'wcolp_form-field form-row form-row-last' : '',
					'label'             => 'Najniższa cena z 30 dni (' . get_woocommerce_currency_symbol() . ')',
					'data_type'         => 'price',
					'custom_attributes' => array(
						'disabled'     => 'disabled',
						'autocomplete' => 'off'
					),
					'description'       => $meta_exists ? ( 'Cena z dnia ' . date( "d-m-Y H:i:s", $meta_data['time'] ) ) : 0
				)
			);

			woocommerce_wp_hidden_input(
				array(
					'id'    => '_wcolp_price_before[' . $product_object->get_id() . ']',
					'value' => $product_object->get_price()
				)
			);
		}

		/**
		 * Render fields in simple product options section
		 *
		 * @return void
		 */
		public function product_options() {
			global $product_object;
			$this->render_price_override_block( $product_object );
		}

		/**
		 * Render fields in variation options section
		 *
		 * @param $loop
		 * @param $variation_data
		 * @param $variation
		 *
		 * @return void
		 */
		public function variation_options( $loop, $variation_data, $variation ) {
			echo '<p></p>';
			$this->render_price_override_block( wc_get_product( $variation->ID ), '_variation_' . $variation->ID );
		}

		/**
		 * Enqueue styles and scripts
		 *
		 * @return void
		 */
		public function enqueue_assets() {
			if ( is_product() ) {
				wp_enqueue_script(
					'wcolp-js',
					plugins_url( '/', __FILE__ ) . 'script.min.js',
					array( 'jquery' ),
					self::VERSION
				);
				global $post;
				wp_add_inline_script( 'wcolp-js', 'const WCOLP = ' . json_encode( $this->get_lowest_price_meta( $post->ID ) ), 'before' );
			}
			wp_register_style(
				'wcolp-css',
				plugins_url( '/', __FILE__ ) . 'style.min.css',
				array(),
				self::VERSION
			);
			wp_enqueue_style( 'wcolp-css' );
		}


	}

	WCOLP_Plugin::instance();
}
