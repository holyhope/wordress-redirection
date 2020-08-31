<?php

/**
 * WordPress redirect module.
 *
 * Provides PHP controlled redirects and monitoring and is the core of the front-end redirection.
 */
class WordPress_Module extends Red_Module {
	/**
	 * @var integer
	 */
	const MODULE_ID = 1;

	/**
	 * Can we log?
	 *
	 * @var boolean
	 */
	private $can_log = true;

	/**
	 * The target redirect URL
	 *
	 * @var string|false
	 */
	private $redirect_url = false;

	/**
	 * The target redirect code
	 *
	 * @var integer
	 */
	private $redirect_code = 0;

	/**
	 * Copy of redirects that match the requested URL
	 *
	 * @var Red_Item[]
	 */
	private $redirects = [];

	/**
	 * Matched redirect
	 *
	 * @var Red_Item|null
	 */
	private $matched = null;

	/**
	 * Return the module ID
	 *
	 * @return integer
	 */
	public function get_id() {
		return self::MODULE_ID;
	}

	/**
	 * Return the module name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'WordPress';
	}

	/**
	 * Start the module. Hooks any filters and actions
	 *
	 * @return void
	 */
	public function start() {
		// Only run redirect rules if we're not disabled
		if ( ! red_is_disabled() ) {
			// Canonical site settings - https, www, relocate, and aliases
			add_action( 'init', [ $this, 'canonical_domain' ] );

			// The main redirect loop
			add_action( 'init', [ $this, 'init' ] );

			// Send site HTTP headers as well as 410 error codes
			add_action( 'send_headers', [ $this, 'send_headers' ] );

			// Redirect HTTP headers and server-specific overrides
			add_filter( 'wp_redirect', [ $this, 'wp_redirect' ], 1, 2 );
		}

		// Setup the various filters and actions that allow Redirection to happen
		add_action( 'redirection_visit', [ $this, 'redirection_visit' ], 10, 3 );
		add_action( 'redirection_do_nothing', [ $this, 'redirection_do_nothing' ] );

		// Prevent WordPress overriding a canonical redirect
		add_filter( 'redirect_canonical', [ $this, 'redirect_canonical' ], 10, 2 );

		// Log 404s and perform 'URL and WordPress page type' redirects
		add_action( 'template_redirect', [ $this, 'template_redirect' ] );

		// Back-compat for < database 4.2
		add_filter( 'redirection_404_data', [ $this, 'log_back_compat' ] );
		add_filter( 'redirection_log_data', [ $this, 'log_back_compat' ] );

		// Record the redirect agent
		add_filter( 'x_redirect_by', [ $this, 'record_redirect_by' ], 90 );
	}

	/**
	 * Back-compatability for Redirection databases older than 4.2. Prevents errors from storing data that has no DB column
	 *
	 * @param array $insert Data to log.
	 * @return array
	 */
	public function log_back_compat( $insert ) {
		// Remove columns not supported in older versions
		$status = new Red_Database_Status();
		if ( ! $status->does_support( '4.2' ) ) {
			foreach ( [ 'request_data', 'request_method', 'http_code', 'domain' ] as $ignore ) {
				unset( $insert[ $ignore ] );
			}
		}

		return $insert;
	}

	/**
	 * This ensures that a matched URL is not overriddden by WordPress, if the URL happens to be a WordPress URL of some kind
	 * For example: /?author=1 will be redirected to /author/name unless this returns false
	 *
	 * @param String $redirect_url The redirected URL.
	 * @param String $requested_url The requested URL.
	 * @return String|false
	 */
	public function redirect_canonical( $redirect_url, $requested_url ) {
		if ( $this->matched ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * WordPress 'template_redirect' hook. Used to check for 404s
	 *
	 * @return void
	 */
	public function template_redirect() {
		if ( ! is_404() || $this->matched ) {
			return;
		}

		// We are on a 404. Check if we have a 'URL and page type' match in any of the matched redirects.
		if ( $this->is_url_and_page_type() ) {
			// Don't log an intentionally redirected 404 as part of the 'url and page type'
			return;
		}

		$options = red_get_options();

		if ( isset( $options['expire_404'] ) && $options['expire_404'] >= 0 && apply_filters( 'redirection_log_404', $this->can_log ) ) {
			$details = [
				'agent' => Redirection_Request::get_user_agent(),
				'referrer' => Redirection_Request::get_referrer(),
				'request_method' => Redirection_Request::get_request_method(),
				'http_code' => 404,
			];

			if ( $options['log_header'] ) {
				$details['request_data'] = [
					'headers' => Redirection_Request::get_request_headers(),
				];
			}

			Red_404_Log::create( Redirection_Request::get_server(), Redirection_Request::get_request_url(), Redirection_Request::get_ip(), $details );
		}
	}

	/**
	 * Return `true` if any of the matched redirects is a 'url and page type', `false` otherwise
	 *
	 * @return boolean
	 */
	private function is_url_and_page_type() {
		$page_types = array_values( array_filter( $this->redirects, function( Red_Item $redirect ) {
			return $redirect->match->get_type() === 'page';
		} ) );

		if ( count( $page_types ) > 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Called by a 'do nothing' action. Return true to stop further processing of the 'do nothing'
	 *
	 * @return boolean
	 */
	public function redirection_do_nothing() {
		$this->can_log = false;
		return true;
	}

	/**
	 * Action fired when a redirect is performed, and used to log the data
	 *
	 * @param Red_Item $redirect The redirect.
	 * @param String   $url The source URL.
	 * @param String   $target The target URL.
	 * @return void
	 */
	public function redirection_visit( $redirect, $url, $target ) {
		$redirect->visit( $url, $target );
	}

	public function canonical_domain() {
		$options = red_get_options();
		$canonical = new Redirection_Canonical( $options['https'], $options['preferred_domain'], $options['aliases'], get_home_url() );

		// Relocate domain?
		$target = false;
		if ( $options['relocate'] ) {
			$target = $canonical->relocate_request( $options['relocate'], Redirection_Request::get_server_name(), Redirection_Request::get_request_url() );
		}

		// Force HTTPS or www
		if ( ! $target ) {
			$target = $canonical->get_redirect( Redirection_Request::get_server_name(), Redirection_Request::get_request_url() );
		}

		if ( $target ) {
			add_filter( 'x_redirect_by', [ $this, 'x_redirect_by' ] );
			// phpcs:ignore
			wp_redirect( $target, 301 );
			die();
		}
	}

	public function x_redirect_by() {
		return 'redirection';
	}

	/**
	 * Redirection 'main loop'. Checks the currently requested URL against the database and perform a redirect, if necessary.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->matched ) {
			return;
		}

		$request = new Red_Url_Request( Redirection_Request::get_request_url() );

		// Make sure we don't try and redirect something essential
		if ( $request->is_valid() && ! $request->is_protected_url() ) {
			do_action( 'redirection_first', $request->get_decoded_url(), $this );

			// Get all redirects that match the URL
			$redirects = Red_Item::get_for_url( $request->get_decoded_url() );

			// Redirects will be ordered by position. Run through the list until one fires
			foreach ( (array) $redirects as $item ) {
				if ( $item->is_match( $request->get_decoded_url(), $request->get_original_url() ) ) {
					$this->matched = $item;
					break;
				}
			}

			do_action( 'redirection_last', $request->get_decoded_url(), $this );

			if ( ! $this->matched ) {
				// Keep them for later
				$this->redirects = $redirects;
			}
		}
	}

	/**
	 * Fix for incorrect headers sent when using FastCGI/IIS
	 *
	 * @param String $status HTTP status line.
	 * @return String
	 */
	public function status_header( $status ) {
		// Fix for incorrect headers sent when using FastCGI/IIS
		if ( substr( php_sapi_name(), 0, 3 ) === 'cgi' ) {
			return str_replace( 'HTTP/1.1', 'Status:', $status );
		}

		return $status;
	}

	/**
	 * Add any custom HTTP headers to the response.
	 *
	 * @param array $obj Some object.
	 * @return void
	 */
	public function send_headers( $obj ) {
		if ( ! empty( $this->matched ) && $this->matched->action->get_code() === 410 ) {
			add_filter( 'status_header', [ $this, 'set_header_410' ] );
		}

		// Add any custom headers
		$options = red_get_options();
		$headers = new Red_Http_Headers( $options['headers'] );
		$headers->run( $headers->get_site_headers() );
	}

	/**
	 * Add support for a 410 response.
	 *
	 * @return String
	 */
	public function set_header_410() {
		return 'HTTP/1.1 410 Gone';
	}

	/**
	 * IIS fix. Don't know if this is still needed
	 *
	 * @param String $url URL.
	 * @return void
	 */
	private function iis_fix( $url ) {
		global $is_IIS;

		if ( $is_IIS ) {
			header( "Refresh: 0;url=$url" );
			return $url;
		}
	}

	/**
	 * Don't know if this is still needed
	 *
	 * @param String  $url URL.
	 * @param integer $status HTTP status code.
	 * @return void
	 */
	private function cgi_fix( $url, $status ) {
		if ( $status === 301 && php_sapi_name() === 'cgi-fcgi' ) {
			$servers_to_check = [ 'lighttpd', 'nginx' ];

			foreach ( $servers_to_check as $name ) {
				if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && stripos( $_SERVER['SERVER_SOFTWARE'], $name ) !== false ) {
					status_header( $status );
					header( "Location: $url" );
					exit( 0 );
				}
			}
		}
	}

	/**
	 * Get a 'source' for a redirect by digging through the backtrace.
	 *
	 * @return string[]
	 */
	private function get_redirect_source() {
		$ignore = [
			'WP_Hook',
			'template-loader.php',
			'wp-blog-header.php',
		];

		// phpcs:ignore
		$source = wp_debug_backtrace_summary( null, 5, false );

		return array_filter( $source, function( $item ) use ( $ignore ) {
			foreach ( $ignore as $ignore_item ) {
				if ( strpos( $item, $ignore_item ) !== false ) {
					return false;
				}
			}

			return true;
		} );
	}

	/**
	 * Record a redirect.
	 *
	 * @param String $agent Redirect agent.
	 * @return string
	 */
	public function record_redirect_by( $agent ) {
		// Have we already redirected with Redirection?
		if ( $this->matched || $agent === 'redirection' ) {
			return $agent;
		}

		$options = red_get_options();

		if ( ! $options['log_external'] ) {
			return $agent;
		}

		$details = [
			'target' => $this->redirect_url,
			'agent' => Redirection_Request::get_user_agent(),
			'referrer' => Redirection_Request::get_referrer(),
			'request_method' => Redirection_Request::get_request_method(),
			'redirect_by' => $agent ? $agent : 'wordpress',
			'http_code' => $this->redirect_code,
			'request_data' => [
				'source' => array_values( $this->get_redirect_source() ),
			],
		];

		if ( $options['log_header'] ) {
			$headers = new Red_Http_Headers( $options['headers'] );
			$headers->run( $headers->get_redirect_headers() );

			$details['request_data']['headers'] = Redirection_Request::get_request_headers();
		}

		Red_Redirect_Log::create( Redirection_Request::get_server(), Redirection_Request::get_request_url(), Redirection_Request::get_ip(), $details );

		return $agent;
	}

	public function wp_redirect( $url, $status = 302 ) {
		global $wp_version;

		$this->redirect_url = $url;
		$this->redirect_code = $status;

		$options = red_get_options();

		$this->iis_fix( $url );
		$this->cgi_fix( $url, $status );

		if ( intval( $status, 10 ) === 307 ) {
			status_header( $status );
			nocache_headers();
			return $url;
		}

		// Do we need to set the cache header?
		if ( ! headers_sent() && isset( $options['redirect_cache'] ) && $options['redirect_cache'] !== 0 && intval( $status, 10 ) === 301 ) {
			if ( $options['redirect_cache'] === -1 ) {
				// No cache - just use WP function
				nocache_headers();
			} else {
				// Custom cache
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s T', time() + $options['redirect_cache'] * 60 * 60 ) );
				header( 'Cache-Control: max-age=' . $options['redirect_cache'] * 60 * 60 );
			}
		}

		status_header( $status );
		return $url;
	}

	public function update( array $data ) {
		return false;
	}

	protected function load( $options ) {
	}

	protected function flush_module() {
	}

	public function reset() {
		$this->can_log = true;
	}
}
