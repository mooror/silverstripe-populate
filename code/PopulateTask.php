<?php

/**
 * @package populate
 */
class PopulateTask extends BuildTask {
	// When task is run, call Populate's requireRecords method
	public function run($request) {
		Populate::requireRecords();
	}
}
