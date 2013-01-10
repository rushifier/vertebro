<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Automatic Routing for ORM Objects
 *
 * This route will handle automatic ORM Routes with relationships, using the
 * .json extension.
 *
 * eg. /users/1/foos/4/bars.json
 */
Route::set('vertebro-orm', '(<relations>)<model>(/<model_id>)(/<action>).json',
	array(
		'relations' => '([a-zA-Z]+/[0-9]+/)+',
		'model_id'  => '[0-9]+',
	))
	->filter(function($route, $params, $request)
	{
		$model_singular = ucwords(Inflector::singular(Arr::get($params, 'model')));

		// Check if a controller has been defined for this ORM object
		if ( ! class_exists('Model_'.$model_singular))
			return FALSE;

		// Force object plural in route
		if (strtolower($params['model']) == strtolower($model_singular))
			return FALSE;

		// Set controller to use the singular object name by default
		$params['controller'] = $model_singular;

		return $params;
	})
	->defaults(array(
		'directory' => 'JSON',
		'action'    => 'index',
	));

/**
 * Default Vertebro Route
 *
 * This route handles any custom Backbone resources
 * you want to define.
 */
Route::set('vertebro-default', '<controller>(/<action>).json')
	->defaults(array(
		'directory' => 'Vertebro',
		'action'    => 'index',
	));
