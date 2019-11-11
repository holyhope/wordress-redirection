<?php

/**
 * @api {get} /redirection/v1/redirect Get list of redirects
 * @apiDescription Get list of redirects
 * @apiGroup Redirect
 *
 * @apiParam {string} orderby
 * @apiParam {string} direction
 * @apiParam {string} filterBy
 * @apiParam {string} per_page
 * @apiParam {string} page
 *
 * @apiSuccess {Redirect[]} items Array of redirects
 * @apiSuccess {Integer} total Number of items
 *
 * @apiUse 400Error
 */

/**
 * @api {post} /redirection/v1/redirect Create a new redirect
 * @apiDescription Create a new redirect
 * @apiGroup Redirect
 *
 * @apiUse Redirect
 *
 * @apiSuccess {Redirect} item Created redirect
 *
 * @apiUse 400Error
 */

/**
 * @api {post} /redirection/v1/redirect/:id Update an existing redirect
 * @apiDescription Update an existing redirect
 * @apiGroup Redirect
 *
 * @apiParam {Number} id Redirect ID
 *
 * @apiSuccess {Redirect} item Created redirect
 *
 * @apiUse 400Error
 */

/**
 * @api {post} /redirection/v1/bulkd/redirect/:type Bulk change redirects
 * @apiDescription Enable, disable, reset, and delete a set of redirects
 * @apiGroup Redirect
 *
 * @apiParam {string} orderby
 * @apiParam {string} direction
 * @apiParam {string} filterBy
 * @apiParam {string} per_page
 * @apiParam {string} page
 *
 * @apiSuccess {Redirect[]} items Fresh page of redirects after applying the bulk action
 * @apiSuccess {Integer} total Number of items
 *
 * @apiUse 400Error
 */

/**
 * @apiDefine Redirect A redirect
 * All data associated with a redirect
 */
class Redirection_Api_Redirect extends Redirection_Api_Filter_Route {
	public function __construct( $namespace ) {
		$orders = [ 'url', 'last_count', 'last_access', 'position', 'id' ];
		$filters = [ 'status', 'url-match', 'match', 'action', 'http', 'access', 'url', 'target', 'title', 'group' ];

		register_rest_route( $namespace, '/redirect', array(
			'args' => $this->get_filter_args( $orders, $filters ),
			$this->get_route( WP_REST_Server::READABLE, 'route_list' ),
			$this->get_route( WP_REST_Server::EDITABLE, 'route_create' ),
		) );

		register_rest_route( $namespace, '/redirect/(?P<id>[\d]+)', array(
			$this->get_route( WP_REST_Server::EDITABLE, 'route_update' ),
		) );

		$this->register_bulk( $namespace, '/bulk/redirect/(?P<bulk>delete|enable|disable|reset)', $orders, 'route_bulk' );
	}

	public function route_list( WP_REST_Request $request ) {
		return Red_Item::get_filtered( $request->get_params() );
	}

	public function route_create( WP_REST_Request $request ) {
		$params = $request->get_params();
		$urls = array();

		if ( isset( $params['url'] ) ) {
			$urls = array( $params['url'] );

			if ( is_array( $params['url'] ) ) {
				$urls = $params['url'];
			}

			foreach ( $urls as $url ) {
				$params['url'] = $url;
				$redirect = Red_Item::create( $params );

				if ( is_wp_error( $redirect ) ) {
					return $this->add_error_details( $redirect, __LINE__ );
				}
			}
		}

		return $this->route_list( $request );
	}

	public function route_update( WP_REST_Request $request ) {
		$params = $request->get_params();
		$redirect = Red_Item::get_by_id( intval( $params['id'], 10 ) );

		if ( $redirect ) {
			$result = $redirect->update( $params );

			if ( is_wp_error( $result ) ) {
				return $this->add_error_details( $result, __LINE__ );
			}

			return array( 'item' => $redirect->to_json() );
		}

		return $this->add_error_details( new WP_Error( 'redirect', 'Invalid redirect details' ), __LINE__ );
	}

	public function route_bulk( WP_REST_Request $request ) {
		$action = $request['bulk'];
		$items = explode( ',', $request['items'] );

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$redirect = Red_Item::get_by_id( intval( $item, 10 ) );

				if ( $redirect ) {
					if ( $action === 'delete' ) {
						$redirect->delete();
					} elseif ( $action === 'disable' ) {
						$redirect->disable();
					} elseif ( $action === 'enable' ) {
						$redirect->enable();
					} elseif ( $action === 'reset' ) {
						$redirect->reset();
					}
				}
			}

			return $this->route_list( $request );
		}

		return $this->add_error_details( new WP_Error( 'redirect', 'Invalid array of items' ), __LINE__ );
	}
}
