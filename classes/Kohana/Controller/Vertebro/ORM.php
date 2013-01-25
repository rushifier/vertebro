<?php defined('SYSPATH') or die('No direct script access.');
/**
 * ORM Models to Backbone.js
 *
 * Backbone.js uses JSON resources for Models and Collections. This controller
 * will find and return JSON encoded data, coupled with existing Kohana ORM Models.
 * Using existing has_many, has_one and belongs_to relationships it will
 * find and return the correct model data based on the URL provided.
 *
 * Default GET/POST/PUT/DELETE operations are implemented.
 */
class Kohana_Controller_Vertebro_ORM extends Controller_Vertebro {

	/**
	 * Specify which columns you only want to use in any of the POST/GET/PUT
	 * operations. Implemented this in your extended class
	 *
	 *    protected $_column_filters = array(
	 *		   'get' => array(
	 *          ...
	 *		   ),
	 *		   'put' => array(
	 *          ...
	 *		   ),
	 *		   'post' => array(
	 *          ...
	 *		   ),
	 *		);
	 *
	 * @var  array
	 */
	protected $_column_filters;

	/**
	 * Columns by action type that are ignored
	 * @var  array
	 */
	protected $_load_with = array();

	/**
	 * An array of columns to filter and the type of operator
	 * @var  array
	 */
	protected $_query = array();

	/**
	 * Model Singular Name
	 * @var  array
	 */
	protected $_model_name;

	/**
	 * ID of model if specified
	 * @var  int
	 */
	protected $_model_id;

	/**
	 * Model object
	 * @var  mixed
	 */
	protected $_model;

	/**
	 * Data sent with the request
	 * @var  array
	 */
	protected $form_data;

	public function before()
	{
		parent::before();

		// Get the model name
		$this->_model_name = Inflector::singular(ucfirst($this->request->param('model')));

		// Generate an instance of the model
		try
		{
			$this->_model = ORM::factory(ucfirst(strtolower($this->_model_name)));
		}
		catch (ErrorException $e)
		{
			throw new HTTP_Exception_404;
		}

		// Load conditions
		if ($relation_str = $this->request->param('relations'))
		{
			// Split
			preg_match_all('/([a-zA-Z\-]+\/[0-9]+)/', $relation_str, $relations, PREG_SET_ORDER);
			$relations = Arr::pluck(array_reverse($relations), 0);

			// Setup the first child object
			$child_model = $this->_model;

			// Loop through relations and add them to our model query
			foreach ($relations as $relation)
			{
				$child_model = $this->_add_relation($child_model, $relation);
			}
		}

		// Check if a model ID was provided
		if ($this->_model_id = $this->request->param('model_id'))
		{
			// Throw error for POST on a single object
			if (Request::POST === $this->request->method())
				throw HTTP_Exception::factory(405)->allowed(array());

			$this->_model = $this->_model
				->where($this->_model_name.'.id', '=', $this->_model_id);

			// Check load with and load any has_one relationships
			if ($this->_load_with)
			{
				foreach ($this->_load_with as $load_obj)
				{
					if (Arr::get($this->_model->has_one(), $load_obj))
					{
						$this->_model = $this->_model
							->with($load_obj);
					}
				}
			}

			// Load the model
			$this->_model = $this->_model
				->find();

			// Throw a 404 error if the model could not be loaded
			if ( ! $this->_model->loaded())
				throw HTTP_Exception::factory(404, Response::$messages[404]);
		}

		// Retrieve any data send with the request
		$this->_form_data = ($form_data = $this->request->post()) ? $form_data : json_decode($this->request->body(), TRUE);

		// Initialise the body to an empty element
		$this->body = new StdClass;
	}

	/**
	 * Handle GET requests
	 */
	public function action_get()
	{
		// Return single object
		if ($this->_model_id)
		{
			$data = $this->_run_column_filter($this->_model->as_array());
			return $this->body = $data;
		}

		// Multiple objects
		$data = array();

		// Loop through the models and extract the data
		foreach ($this->_run_query_filter()->_model->find_all() as $item)
		{
			// Run column filter on object
			$item_data = $this->_run_column_filter($item->as_array());
			$data[] = $item_data;
		}

		return $this->body = $data;
	}

	/**
	 * Handle POST requests
	 */
	public function action_post()
	{
		// Check that data was sent
		if ( ! $this->_form_data)
			return $this->body = array('error' => 'No data provided');

		try
		{
			// Load the data and save the object
			$this->_model->values($this->_form_data);
			$this->_model->save();

			// Set response header to HTTP 201 Created and return the created object
			$this->body = $this->_model->as_array();
			$this->response->status(201);
		}
		catch (ORM_Validation_Exception $e)
		{
			$this->body = array('errors' => $e->errors('models'));
		}
	}

	/**
	 * Handle PUT requests
	 */
	public function action_put()
	{
		// Check that data was sent
		if ( ! $this->_form_data)
			return $this->body = array('error' => 'No data provided');

		$this->_model->values($values);

		try
		{
			$this->_model->save();
			$this->body = $this->_model->as_array();
		}
		catch (ORM_Validation_Exception $e)
		{
			$this->body = array('errors' => $e->errors('models'));
		}
	}

	/**
	 * Handle DELETE requests
	 */
	public function action_delete()
	{
		if ( ! $this->_model_id)
			throw new HTTP_Exception_405;

		$this->_model->delete();
	}

	/**
	 * Show count of objects with the current filters applied
	 */
	public function action_get_count()
	{
		if ($this->_model_id)
			throw HTTP_Exception::factory(405)->allowed(array());

		$this->body = array('count' => $this->_run_query_filter()->_model->count_all());
	}

	/**
	 * Filter the current model object using the querystring
	 *
	 * @chainable
	 * @return $this
	 */
	protected function _run_query_filter()
	{
		// Handle support for URI filtering
		foreach ($this->request->query() as $column => $query)
		{
			// Check that a query value was passed in
			if ($query === '' OR $query === NULL)
				continue;

			// Check that a query filter has been defined this name
			if ( ! array_key_exists($column, $this->_query))
				continue;

			$filter = Arr::get($this->_query, $column);

			// Set defaults for the rule
			$filter['column']   = ($_column   = Arr::get($filter, 'column'))   ? $_column   : $column;
			$filter['operator'] = ($_operator = Arr::get($filter, 'operator')) ? $_operator : '=';

			// Check that the referenced column exists
			if ( ! Arr::get($this->_model->table_columns(), $filter['column']))
				continue;

			$this->_model = $this->_model
				->where($filter['column'], $filter['operator'], $query);
		}

		return $this;
	}

	/**
	 * Filter an array using the current filter
	 *
	 * @param   array  Data to filter
	 * @return  array
	 */
	protected function _run_column_filter($data)
	{
		if (Arr::is_array($data) AND $key_filters = Arr::get($this->_column_filters, strtolower($this->request->method())))
		{
			return Arr::extract($data, $key_filters);
		}
		return $data;
	}

	/**
	 * Find the belongs_to relationship between to models
	 *
	 * @param   ORM    Child Model
	 * @param   ORM    Parent Model
	 * @return  array
	 */
	protected function _parent_relation($child, $parent)
	{
		foreach ($child->belongs_to() as $belongs)
		{
			if (strtolower(Arr::get($belongs, 'model')) === strtolower($parent->object_name()))
			{
				return $belongs;
			}
		}
		return $child->has($parent->object_name());
	}

	/**
	 * Add a relation to our query
	 *
	 * @param  ORM     Child model
	 * @param  string  Name of the parent model
	 * @param  int     Primary key id of the parent model
	 * @return ORM
	 */
	protected function _add_relation($child_model, $parent_string)
	{
		list($parent_name, $parent_id) = explode('/', $parent_string);

		// Check the model exists
		if ( ! class_exists('Model_'.Inflector::singular($parent_name)))
			throw new HTTP_Exception_404('Unable to map model');

		// Load the related model
		$parent_model = ORM::factory(Inflector::singular($parent_name), $parent_id);

		// Lookup the belongs_to relation
		if ( ! $belongs_to = $this->_parent_relation($child_model, $parent_model))
			throw new HTTP_Exception_404('Unable to map model');

		// Check if the child model is the current model
		$child_table_name = ($this->_model->object_name() === $child_model->object_name())
			? $child_model->object_name()
			: $child_model->table_name();

		// Join the relationship to our model
		$this->_model = $this->_model
			->join($parent_model->table_name())
			->on($parent_model->table_name().'.'.$parent_model->primary_key(), '=', $child_table_name.'.'.Arr::get($belongs_to, 'foreign_key'))
			->where($parent_model->table_name().'.'.$parent_model->primary_key(), '=', $parent_id);

		// Return the joined model
		return $parent_model;
	}

} // End Vertebro_ORM
