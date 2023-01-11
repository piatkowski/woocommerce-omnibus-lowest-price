<?php
/**
 * Plugin Name: WooCommerce Omnibus Lowest Price
 * Description: Plugin automatically remembers and displays the lowest price of the product before the discount from the last 30 days.
 * Version: 1.0.0
 * Author: Krzysztof Piątkowski
 * Author URI: https://github.com/piatkowski/
 * License: GPL2
 */

defined( 'ABSPATH' ) or die( 'No direct script access' );

if ( ! class_exists( 'WCOLP_Plugin' ) ) {
	class WCOLP_Plugin {

		const VERSION = '1.0.0.1';
		const MONTH_IN_SECONDS = 2678400;
		const META_KET = 'wcolp_data';

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
			add_action( 'save_post_product', [ $this, 'before_product_save' ] );
			add_action( 'woocommerce_before_single_variation', [ $this, 'render_variable_product_page' ], 99 );
			add_action( 'woocommerce_single_product_summary', [ $this, 'render_product_page' ], 25 );
			add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_product_list' ], 99 );
			add_action( 'woocommerce_variation_options', [ $this, 'admin_variations_info' ], 10, 3 );
		}

		/**
		 * Enqueue styles and scripts
		 * @return void
		 */
		public function enqueue_assets() {
			if ( is_product() ) {
				wp_enqueue_script(
					'wcolp-js',
					plugins_url( '/', __FILE__ ) . 'script.js',
					array( 'jquery' ),
					self::VERSION
				);
				global $post;
				wp_add_inline_script( 'wcolp-js', 'const WCOLP = ' . json_encode( $this->get_price_data( $post->ID ) ), 'before' );
			}
			wp_register_style(
				'wcolp-css',
				plugins_url( '/', __FILE__ ) . 'style.css',
				array(),
				self::VERSION
			);
			wp_enqueue_style( 'wcolp-css' );
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
			if ( ! $is_variation && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$this->before_product_save( $variation_id, true );
				}
			}
			$can_update = false;
			if ( ! $product->meta_exists( self::META_KET ) ) {
				$can_update = true;
			} else {
				$data = $product->get_meta( self::META_KET );
				if ( $product->get_price() < $data['price'] || time() - $data['time'] > MONTH_IN_SECONDS ) {
					$can_update = true;
				}
			}
			if ( $can_update ) {
				$product->update_meta_data( self::META_KET, [
					'time'  => time(),
					'price' => $product->get_price()
				] );
				$product->save_meta_data();
			}
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
			if ( $product->meta_exists( self::META_KET ) && $product->is_on_sale() ) {
				$data  = $product->get_meta( self::META_KET );
				$time  = $data['time'] ?? null;
				$price = $data['price'] ?? null;
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
		 * @return void
		 */
		public function render_product_list() {
			if ( ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) && ! is_product() ) {
				global $product;
				$variation_id = null;
				if ( $product->is_type( 'variable' ) ) {
					if ( ! $product->is_on_sale() ) {
						$prices       = $product->get_variation_prices();
						$price        = current( $prices['price'] ); //point to first element
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

		public function render_variable_product_page() {
			if ( is_product() ) {
				global $product;
				if ( $product->is_type( 'variable' ) ) {
					echo apply_filters( 'wcolp_before_product_page', '<div id="wcolp-container">' );
					echo apply_filters( 'wcolp_after_product_page', '</div>' );
				}
			}
		}

		public function admin_variations_info( $loop, $variation_data, $variation ) {
			echo '<div>' . $this->get_frontent_message( $variation ) . '</div>';
		}

	}

	WCOLP_Plugin::instance();
}
