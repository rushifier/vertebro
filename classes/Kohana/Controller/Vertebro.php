<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Simple REST controller that maps the different request methods to the
 * correct action. Before doing this the controller checks if the requested
 * method / action exists.
 */
class Kohana_Controller_Vertebro extends Controller {

	/**
	 * @var  Response body to JSON encoded and returned
	 */
	protected $body;

	/**
	 * @var  array  map of methods
	 */
	protected $_method_map = array
	(
		Request::POST   => 'post',
		Request::GET    => 'get',
		Request::PUT    => 'put',
		Request::DELETE => 'delete',
	);

	/**
	 * Checks if the requested method is in the method map and if the mapped
	 * action is also declared in the controller. Throws a HTTP Exception 405
	 * if not so.
	 *
	 * @throws  HTTP_Exception_405
	 */
	public function before()
	{
		// Execute parent's before method
		parent::before();

		// Generate the action name based on the HTTP Method of the request, and a supplied action
		$action_name = ($this->request->action() === 'index')
			? Arr::get($this->_method_map, $this->request->method())
			: Arr::get($this->_method_map, $this->request->method()).'_'.$this->request->action();

		// Execute the correct CRUD action based on the requested method
		$this->request->action($action_name);
	}

	/**
	 * Set the cache-control header, so the response will not be cached.
	 */
	public function after()
	{
		// Set headers to not cache anything
		$this->response->headers('cache-control', 'no-cache, no-store, max-age=0, must-revalidate');

		// Set the content-type header
		$this->response->headers('content-type', 'application/json');

		// Set and encode the body data
		$this->response->body(json_encode($this->body));

		// Execute parent's after method
		parent::after();
	}

} // End Kohana_Controller_Vertebro
