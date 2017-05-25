<?php

/**
 * @package populate
 */
class Populate extends Object {

	/**
	 * @config
	 *
	 * An array for storing fixture file paths
	 *
	 * @var array
	 */
	private static $include_yaml_fixtures = array();

	/**
	 * @config
	 *
	 * An array of classes to clear from the database before importing. While
	 * populating sitetree it may be worth clearing the 'SiteTree' table.
	 *
	 * @var array
	 */
	private static $truncate_objects = array();

	/**
	 * Flag to determine if we're already run for this session (i.e to prevent
	 * parent calls invoking {@link requireRecords} twice).
	 *
	 * @var bool
	 */
	private static $ran = false;

	/**
	 * @var bool
	 *
	 * @throws Exception
	 */
	public static function requireRecords($force = false) {
		// If we have already run this method and force is not true
		// return true
		if(self::$ran && !$force) {
			return true;
		}

		self::$ran = true;
		// If the mode is not set to dev or test then throw an exception
		if(!(Director::isDev() || Director::isTest())) {
			throw new Exception('requireRecords can only be run in development or test environments');
		}
		// Create a new instance of the PopulateFactory class and store it in a variable
		$factory = Injector::inst()->create('PopulateFactory');

		// Loop over each item in the truncate_objects list (found in config.yml)
		foreach(self::config()->get('truncate_objects') as $objName) {
			$versions = array();
			// If the item is the name of a class that exists
			if(class_exists($objName)) {
				foreach(DataList::create($objName) as $obj) {
					// if the object has the versioned extension, make sure we delete
					// that as well
					if($obj->hasExtension('Versioned')) {
						foreach($obj->getVersionedStages() as $stage) {
							$versions[$stage] = true;

							$obj->deleteFromStage($stage);
						}
					}
					// Try to delete objects
					try {
						@$obj->delete();
					} catch(Exception $e) {
						// notice
					}
				}
			}
			// If the Object is versioned then go ahead and truncate its version tables
			if($versions) {
				self::truncate_versions($objName, $versions);
			}
			// If the object has subclasses, truncate their tables as well
			foreach((array)ClassInfo::getValidSubClasses($objName) as $table) {
				self::truncate_table($table);
				self::truncate_versions($table, $versions);
			}
			// Truncate the objects table
			self::truncate_table($objName);
		}
		// For each file path in the include_yaml_fixtures list (found in config.yml)
		// Create a new YamlFixture and then write it into the PopulateFactory object
		// that was created above.
		foreach(self::config()->get('include_yaml_fixtures') as $fixtureFile) {
			$fixture = new YamlFixture($fixtureFile);
			$fixture->writeInto($factory);

			$fixture = null;
		}

		// hook allowing extensions to clean up records, modify the result or
		// export the data to a SQL file (for importing performance).
		$static = !(isset($this) && get_class($this) == __CLASS__);

		if($static) {
			$populate = Injector::inst()->create('Populate');
		} else {
			$populate = $this;
		}

		$populate->extend('onAfterPopulateRecords');

		return true;
	}
	/**
	 * Confirms that a table exists with the name that was passed in.
	 * Then deletes all records of that table.
	 *
	 */
	private static function truncate_table($table) {
		DB::alteration_message("Truncating Table $table", "deleted");
		// Check to make sure that a table exists with the name that was passed in
		if(ClassInfo::hasTable($table)) {
			// Check to see if a clearTable method exists on DB:getConn
			// If it does then use it to clear the table
			// Otherwise use a SQL TRUNCATE query
			if(method_exists(DB::getConn(), 'clearTable')) {
				DB::getConn()->clearTable($table);
			} else {
				DB::query("TRUNCATE \"$table\"");
			}
		}
	}
	/**
	 * Utilizes the truncate_table method to delete version tables.
	 *
	 */
	private static function truncate_versions($table, $versions) {
		self::truncate_table($table .'_versions');

		foreach($versions as $stage => $v) {
			self::truncate_table($table . '_'. $stage);
		}
	}
}
