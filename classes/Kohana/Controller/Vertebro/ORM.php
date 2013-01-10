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
	 * Columns by action type that are ignored
	 * @var  array
	 */
	protected $_ignore_columns = array();

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
			preg_match_all('/([a-zA-Z\-]+\/[0-9]+)/', $relation_str, $relations, PREG_SET_ORDER);

			$relations = array_reverse($relations);

			// Loop through condition sets, check $_belongs_to relationships and add to our model
			foreach ($relations as $relation)
			{
				$condition = explode('/', Arr::get($relation, 0));

				$relation_name      = ucfirst(Arr::get($condition, 0));
				$relation_singular  = Inflector::singular($relation_name);
				$relation_id        = Arr::get($condition, 1);

				$join_model       = ORM::factory(Inflector::singular(Arr::get($condition, 0)));
				$join_model_id    = Arr::get($condition, 1);
				$join_model_table = $join_model->table_name();
				$join_model_pk    = $join_model->primary_key();

				if ( ! isset($previous_model))
				{
					$belongs_to = FALSE;
					foreach ($this->_model->belongs_to() as $belongs)
					{
						if (strtolower(Arr::get($belongs, 'model')) === strtolower($relation_singular))
						{
							$belongs_to = $belongs;
							break;
						}
					}

					if ( ! $belongs_to)
						throw new HTTP_Exception_404;

					$join_model_fk = Arr::get($belongs_to, 'foreign_key');

					$this->_model = $this->_model
						->join($join_model_table)
						->on($join_model_table.'.'.$join_model_pk, '=', $this->_model_name.'.'.$join_model_fk)
						->where($join_model_table.'.'.$join_model_pk, '=', $join_model_id);
				}
				else
				{
					$belongs_to = FALSE;
					foreach ($previous_model->belongs_to() as $belongs)
					{
						if (strtolower(Arr::get($belongs, 'model')) === strtolower($relation_singular))
						{
							$belongs_to = $belongs;
							break;
						}
					}

					if ( ! $belongs_to)
						throw new HTTP_Exception_404;

					$join_model_fk = Arr::get($belongs_to, 'foreign_key');

					$this->_model = $this->_model
						->join($join_model_table)
						->on($join_model_table.'.'.$join_model_pk, '=', $previous_model->table_name().'.'.$join_model_fk)
						->where($join_model_table.'.'.$join_model_pk, '=', $join_model_id);
				}

				$previous_model = $join_model;
				$join_model->clear();
			}
		}

		// Check if a model ID was provided
		if ($this->_model_id = $this->request->param('model_id'))
		{
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
				throw new HTTP_Exception_404;
		}

		// Retrieve any data send with the request
		$this->_form_data = ($form_data = $this->request->post()) ? $form_data : json_decode($this->request->body(), TRUE);

		// Initialise the body to an empty element
		$this->body = new StdClass;
	}

	// Default GET
	public function action_get()
	{
		// Return single object
		if ($this->_model_id)
		{
			$data = $this->_model->as_array();
			if ($ignore_columns = Arr::get($this->_ignore_columns, $this->request->action()))
			{
				$data = Arr::extract($this->_model->as_array(), array_diff(array_keys($this->_model->table_columns()), $ignore_columns));
			}
			return $this->body = $data;
		}

		// Multiple objects
		$data = array();

		// Loop through the models and extract the data
		foreach ($this->apply_query_filter()->_model->find_all() as $item)
		{
			$item_data = $item->as_array();

			if ($ignore_columns = Arr::get($this->_ignore_columns, $this->request->action()))
			{
				$item_data = Arr::extract($item_data, array_diff(array_keys($this->_model->table_columns()), $ignore_columns));
			}

			$data[] = $item_data;
		}

		return $this->body = $data;
	}

	// Default POST
	public function action_post()
	{
		// Check that data was sent
		if ( ! $this->_form_data)
			return $this->body = array('error' => 'No data provided');

		try
		{
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

	// Default PUT
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

	// Default Delete
	public function action_delete()
	{
		if ( ! $this->_model_id)
			throw new HTTP_Exception_405;

		$this->_model->delete();
	}

	// Return the count on a resources
	public function action_get_count()
	{
		if ($this->_model_id)
			throw new HTTP_Exception_405;

		$this->body = array('count' => $this->apply_query_filter()->_model->count_all());
	}

	/**
	 * Filter the current model object using the querystring
	 *
	 * @chainable
	 * @return $this
	 */
	protected function apply_query_filter()
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

} // End Vertebro ORM
