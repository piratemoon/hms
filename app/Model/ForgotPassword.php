<?php

	App::uses('AppModel', 'Model');

	/**
	 * Model to handle data and requests for the forgot password process.
	 *
	 *
	 * @package       app.Model
	 */
	class ForgotPassword extends AppModel 
	{
		const EXPIRE_TIME = 7200; //!< Time in seconds that it takes for a forgot password request to expire (2 hours).

		public $useTable = 'forgotpassword'; //!< Specify the table to use.

		public $primaryKey = 'request_guid'; //!< Specify the primary key.

		//! Validation rules.
		/*!
			Email must not be empty, and must match an e-mail belonging to a Member in the database.
			New Password must not be empty, and must be at-least Member::MIN_PASSWORD_LENGTH characters long.
			New Password Confirm must not be empty, must match New Password and must be at-least Member::MIN_PASSWORD_LENGTH characters long.
		*/
	    public $validate = array(
	        'email' => array(
	            'noEmpty' => array(
	            	'rule' => 'notEmpty',
	            	'message' => 'This field cannot be left blank'
	            ),
	            'matchMemberEmail' => array(
	            	'rule' => array( 'findMemberWithEmail' ),
	            	'message' => 'Cannot find a member with that e-mail',
	            )
	        ),
	        'new_password' => array(
	        	'noEmpty' => array(
	            	'rule' => 'notEmpty',
	            	'message' => 'This field cannot be left blank'
	            ),
	        	'minLen' => array(
	        		'rule' => array('minLength', Member::MIN_PASSWORD_LENGTH),
            		'message' => 'Password too short',
            	),
	        ),
	        'new_password_confirm' => array(
	        	'noEmpty' => array(
	            	'rule' => 'notEmpty',
	            	'message' => 'This field cannot be left blank'
	            ),
	        	'matchNewPassword' => array(
	            	'rule' => array( 'newPasswordConfirmMatchesNewPassword' ),
	            	'message' => 'Passwords don\'t match',
	            ),
	            'minLen' => array(
	        		'rule' => array('minLength', Member::MIN_PASSWORD_LENGTH),
            		'message' => 'Password too short',
            	),
	        )
	    );
	
		//! Test to see if the user-supplied New Password Confirm matches the user-supplied New Password.
		/*!
			@param string $check User-supplied New Password Confirm.
			@retval bool True if the passwords match, false otherwise.
		*/
	    public function newPasswordConfirmMatchesNewPassword($check)
		{
			return $this->data['ForgotPassword']['new_password'] === $check['new_password_confirm'];
		}

		//! Test to see if we have a record of a Member with the e-mail the user is asking for.
		/*!
			@param string $check The e-mail address to check.
			@retval bool True if we have record of a Member with that e-mail, otherwise false.
		*/
		public function findMemberWithEmail($check)
		{
			$member = ClassRegistry::init('Member');
			return $member->doesMemberExistWithEmail($check['email']);
		}

		//! Create a new entry in the forgot password database for a Member.
		/*
			@param int $memberId Id of the Member to create the forgot password record for.
			@retval mixed The guid for the newly created entry or null if it failed.
		*/
		public function createNewEntry($memberId)
		{
			$data['ForgotPassword']['member_id'] = $memberId;
			$data['ForgotPassword']['request_guid'] = String::UUID();
			$data['ForgotPassword']['expired'] = 0;
			// Timestamp is generated automatically
			if($this->save($data))
			{
				return $data['ForgotPassword']['request_guid'];
			}
			return null;
		}

		//! Check to see if there is a valid, non-expired record for a given guid and member id.
		/*!
			@param string $guid The guid to check.
			@param string $memberId The member id to check.
			@retval bool True if the entry exists and has not expired, false otherwise.
		*/
		public function isEntryValid($guid, $memberId)
		{
			$record = $this->find('first', array('conditions' => array('ForgotPassword.request_guid' => $guid, 'ForgotPassword.member_id' => $memberId)));
			if($record)
			{
				$expired = Hash::get($record, 'ForgotPassword.expired');
				$timestamp = Hash::get($record, 'ForgotPassword.timestamp');

				if(isset($expired) && isset($timestamp))
				{
					if($expired == 0)
					{
						if((time() - strtotime($timestamp)) < self::EXPIRE_TIME)
						{
							return true;
						}
					}
				}
			}
			return false;
		}

		//! Mark an entry as expired.
		/*
			@param string $guid The id of the record to expire.
			@retval bool True if record was updated to be expired, false otherwise.
		*/
		public function expireEntry($guid)
		{
			$record = $this->find('first', array('conditions' => array('ForgotPassword.request_guid' => $guid)));
			if($record)
			{
				$record['ForgotPassword']['expired'] = 1;

				return $this->save($record) != false;
			}
			return false;
		}

		//! Check to see if a GUID is valid.
		/*!
			@param string $guid The GUID to check.
			@retval bool True if $guid is a valid v4 GUID, false otherwise.
		*/
		public static function isValidGuid($guid)
		{
			if(is_string($guid))
			{
				return preg_match('/^\{?[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}\}?$/i', $guid) != false;
			}
			return false;
		}
	}
?>