<?php
/**
 * Plugin Name: WooCommerce Omnibus Lowest Price
 * Description: Plugin automatically remembers and displays the lowest price of the product before the discount from the last 30 days.
 * Version: 1.1.0
 * Author: Krzysztof Piątkowski
 * Author URI: https://github.com/piatkowski/
 * License: GPL2
 */

defined( 'ABSPATH' ) or die( 'No direct script access' );

if ( ! class_exists( 'WCOLP_Plugin' ) ) {
	class WCOLP_Plugin {

		const VERSION = '1.1.0.0';
		const MONTH_IN_SECONDS = 2678400;
		const META_KEY = 'wcolp_data';

		private static ?WCOLP_Plugin $instance = null;

		/**
		 * Get singleton instance
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
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
			add_action( 'save_post_product', [ $this, 'before_product_save' ] );
			add_action( 'woocommerce_save_product_variation', [ $this, 'after_variation_save' ], 10 );
			//add_action( 'woocommerce_before_single_variation', [ $this, 'render_variable_product_page' ], 99 );
			//add_action( 'woocommerce_single_product_summary', [ $this, 'render_product_page' ], 25 );
			add_action( 'woocommerce_product_meta_start', [ $this, 'render_variable_product_page' ], 10 );
			add_action( 'woocommerce_product_meta_start', [ $this, 'render_product_page' ], 10 );

			add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_product_list' ], 99 );
			add_action( 'woocommerce_variation_options', [ $this, 'admin_variations_info' ], 10, 3 );

			add_action( 'woocommerce_product_options_pricing', [ $this, 'simple_product_options' ] );
		}

		/**
		 * Enqueue styles and scripts
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
				wp_add_inline_script( 'wcolp-js', 'const WCOLP = ' . json_encode( $this->get_price_data( $post->ID ) ), 'before' );
			}
			wp_register_style(
				'wcolp-css',
				plugins_url( '/', __FILE__ ) . 'style.min.css',
				array(),
				self::VERSION
			);
			wp_enqueue_style( 'wcolp-css' );
		}

		/**
		 * Update product/variation WCOLP meta data (lowest price and timestamp)
		 *
		 * @param $product
		 *
		 * @return void
		 */
		private function maybe_auto_update_product_meta( $product ) {
			$can_update = false;
			if ( ! $product->meta_exists( self::META_KEY ) ) {
				$can_update = true;
			} else {
				$data = $product->get_meta( self::META_KEY );
				if ( empty( $data ) || empty( $data['price'] ) || floatval( $product->get_price() ) < floatval( $data['price'] ) || time() - $data['time'] > MONTH_IN_SECONDS ) {
					$can_update = true;
				}
			}
			if ( $can_update ) {
				$product->update_meta_data( self::META_KEY, [
					'time'  => time(),
					'price' => floatval( $product->get_price() )
				] );
				$product->save_meta_data();
			}
		}

		/**
		 * Get product and variations metadata saved by this plugin (price and timestamp)
		 *
		 * @param $product_id
		 *
		 * @return array
		 */
		private function get_price_data( $product_id ) {
			$product             = wc_get_product( $product_id );
			$data[ $product_id ] = $this->get_frontent_message( $product_id );
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$data[ $variation_id ] = $this->get_frontent_message( $variation_id );
				}
			}

			return $data;
		}

		/**
		 * Save meta data (price and timestamp) before product save
		 *
		 * @param $product_id
		 * @param $is_variation
		 *
		 * @return void
		 */
		public function before_product_save( $product_id, $is_variation = false ) {
			$product = wc_get_product( $product_id );

			if ( $product->is_type( 'variable' ) ) {
				return;
			}

			if ( isset( $_POST['_wcolp_price_override_single'] ) ) {
				$product->update_meta_data( self::META_KEY, [
					'time'  => time(),
					'price' => floatval( str_replace( ',', '.', $_POST['_wcolp_price_override_single'] ) )
				] );
				$product->save_meta_data();

				return;
			}

			$this->maybe_auto_update_product_meta( $product );

		}

		/**
		 * Prepare message to display on frontend
		 *
		 * @param $product_id
		 *
		 * @return string
		 */
		private function get_frontent_message( $product_id ) {
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
		 * Render HTML to use on product page
		 *
		 * @return void
		 */
		public function render_product_page() {
			if ( is_product() ) {
				global $product;
				if ( $product->is_type( 'variable' ) === false ) {
					echo apply_filters( 'wcolp_before_product_page', '<div id="wcolp-container">' );
					echo wp_kses_post( $this->get_frontent_message( $product->get_id() ) );
					echo apply_filters( 'wcolp_after_product_page', '</div>' );
				}
			}
		}

		/**
		 * Render HTML to use on product listings
		 *
		 * @return void
		 */
		public function render_product_list() {
			if ( ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) && ! is_product() ) {
				global $product;
				$variation_id = null;
				if ( $product->is_type( 'variable' ) ) {
					if ( ! $product->is_on_sale() ) {
						$prices       = $product->get_variation_prices();
						$price        = floatval( current( $prices['price'] ) ); //point to first element
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
				echo wp_kses_post( $this->get_frontent_message( $variation_id ) );
				echo apply_filters( 'wcolp_after_product_list', '</div>' );
			}
		}

		/**
		 * @return void
		 */
		public function render_variable_product_page() {
			if ( is_product() ) {
				global $product;
				if ( $product->is_type( 'variable' ) ) {
					echo apply_filters( 'wcolp_before_product_page', '<div id="wcolp-container">' );
					echo apply_filters( 'wcolp_after_product_page', '</div>' );
				}
			}
		}

		/**
		 * Render override fields on variations admin page
		 *
		 * @param $loop
		 * @param $variation_data
		 * @param $variation
		 *
		 * @return void
		 */
		public function admin_variations_info( $loop, $variation_data, $variation ) {
			echo '<p></p>';
			$this->render_price_override_block( wc_get_product( $variation->ID ), '_variation_' . $variation->ID );
		}

		/**
		 * Render override fields HTML block
		 *
		 * @param $product_object
		 * @param $name_suffix
		 *
		 * @return void
		 */
		public function render_price_override_block( $product_object, $name_suffix = '_single' ) {
			woocommerce_wp_checkbox( array(
				'id'                => '_wcolp_price_override_enabled' . $name_suffix,
				'label'             => 'Nadpisuję cenę',
				'wrapper_class'     => $name_suffix !== '_single' ? 'wcolp_form-field form-row form-row-first' : '',
				'description'       => __( 'Zaznacz, aby nadpisać najniższą cenę z 30 dni', 'woocommerce' ),
				'custom_attributes' => array(
					'onchange' => "jQuery('#_wcolp_price_override" . $name_suffix . "').prop('disabled', ! jQuery(this).is(':checked'))",
					'autocomplete' => 'off'
				)
			) );

			$meta_exists = $product_object->meta_exists( self::META_KEY );
			$meta_data   = $meta_exists ? $product_object->get_meta( self::META_KEY ) : null;

			woocommerce_wp_text_input(
				array(
					'id'                => '_wcolp_price_override' . $name_suffix,
					'value'             => $meta_exists ? $meta_data['price'] : 0,
					'wrapper_class'     => $name_suffix !== '_single' ? 'wcolp_form-field form-row form-row-last' : '',
					'label'             => 'Najniższa cena z 30 dni (' . get_woocommerce_currency_symbol() . ')',
					'data_type'         => 'price',
					'custom_attributes' => array(
						'disabled'     => 'disabled',
						'autocomplete' => 'off'
					),
					'description'       => $meta_exists ? ( 'Cena z dnia ' . date( "d-m-Y H:i:s", $meta_data['time'] ) ) : 0
				)
			);
		}

		/**
		 * Render override fields on simple product admin page
		 * @return void
		 */
		public function simple_product_options() {
			global $product_object;
			$this->render_price_override_block( $product_object );
		}

		/**
		 * Save variation's meta data
		 *
		 * @param $variation_id
		 *
		 * @return void
		 */
		public function after_variation_save( $variation_id ) {
			$product = wc_get_product( $variation_id );

			if ( isset( $_POST[ '_wcolp_price_override_variation_' . $variation_id ] ) ) {
				$product->update_meta_data( self::META_KEY, [
					'time'  => time(),
					'price' => floatval( str_replace( ',', '.', $_POST[ '_wcolp_price_override_variation_' . $variation_id ] ) )
				] );
				$product->save_meta_data();

				return;
			}

			$this->maybe_auto_update_product_meta( $product );
		}

	}

	WCOLP_Plugin::instance();
}
