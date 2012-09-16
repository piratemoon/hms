<?php

	class StatusController extends AppController {
	    
	    public $helpers = array('Html', 'Form');

	    public function isAuthorized($user, $request)
	    {
	    	return true;
	    }

	    # Show a list of the different statuses
	    public function index() {
	    	$this->set('statuses', $this->Status->find('all'));
	    }

	}
?>