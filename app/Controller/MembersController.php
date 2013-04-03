<?php
	
	App::uses('HmsAuthenticate', 'Controller/Component/Auth');
	App::uses('Member', 'Model');
	App::uses('ForgotPassword', 'Model');
	App::uses('CakeEmail', 'Network/Email');

	App::uses('PhpReader', 'Configure');
	Configure::config('default', new PhpReader());
	Configure::load('hms', 'default');

	/**
	 * Controller for Member functions.
	 *
	 *
	 * @package       app.Controller
	 */
	class MembersController extends AppController 
	{
	    
	    //! We need the Html, Form, Tinymce and Currency helpers.
	    /*!
	    	@sa http://api20.cakephp.org/class/html-helper
	    	@sa http://api20.cakephp.org/class/form-helper
	    	@sa http://api20.cakephp.org/class/paginator-helper
	    	@sa TinymceHelper
	    	@sa CurrencyHelper
	    */
	    public $helpers = array('Html', 'Form', 'Paginator', 'Tinymce', 'Currency', 'Mailinglist');

	    //! We need the MailChimp and Krb components.
	    /*!
	    	@sa MailChimpComponent
	    	@sa KrbComponent
	    */
	    public $components = array('MailChimp');

	    //! Test to see if a user is authorized to make a request.
	    /*!
	    	@param array $user Member record for the user.
	    	@param CakeRequest $request The request the user is attempting to make.
	    	@retval bool True if the user is authorized to make the request, otherwise false.
	    	@sa http://api20.cakephp.org/class/cake-request
	    */
	    public function isAuthorized($user, $request)
	    {
	    	if(parent::isAuthorized($user, $request))
	    	{
	    		return true;
	    	}

	    	$userId = $this->Member->getIdForMember($user);
	    	$userIsMemberAdmin = $this->Member->GroupsMember->isMemberInGroup( $userId, Group::MEMBER_ADMIN );
	    	$actionHasParams = isset( $request->params ) && isset($request->params['pass']) && count( $request->params['pass'] ) > 0;
	    	$userIdIsSet = is_numeric($userId);

	    	switch ($request->action) 
	    	{
	    		case 'index':
	    		case 'listMembers':
	    		case 'listMembersWithStatus':
	    		case 'emailMembersWithStatus':
	    		case 'search':
	    		case 'revokeMembership':
	    		case 'reinstateMembership':
	    		case 'acceptDetails':
	    		case 'rejectDetails':
	    		case 'approveMember':
	    		case 'sendMembershipReminder':
	    		case 'sendContactDetailsReminder':
	    		case 'sendSoDetailsReminder':
	    		case 'addExistingMember':
	    			return $userIsMemberAdmin; 

	    		case 'changePassword':
	    		case 'view':
	    		case 'edit':
	    		case 'setupDetails':
	    			if( $userIsMemberAdmin || 
	    				( $actionHasParams && $userIdIsSet && $request->params['pass'][0] == $userId ) )
	    			{
	    				return true;
	    			}
	    			break;
	    	}

	    	return false;
	    }

	    //! Email object, for easy mocking.
	    public $email = null;

	    //! Perform any actions that should be performed before any controller action
	    /*!
	    	@sa http://api20.cakephp.org/class/controller#method-ControllerbeforeFilter
	    */
	    public function beforeFilter() 
	    {
	        parent::beforeFilter();

	        $allowedActionsArray = array(
	        	'logout', 
	        	'login', 
	        	'forgotPassword', 
	        	'setupLogin', 
	        	'setupDetails'
	        );

	    	$userIsMemberAdmin = $this->Member->GroupsMember->isMemberInGroup( $this->_getLoggedInMemberId(), Group::MEMBER_ADMIN );
	    	$isLocal = $this->isRequestLocal();

	    	// We have to put register here, as is isAuthorized()
	        // cannot be used to check access to functions if they can
	        // ever be accessed by a user that is not logged in
	    	if( $isLocal ||
	    		$userIsMemberAdmin )
	    	{
	    		array_push($allowedActionsArray, 'register');
	    	}

	        $this->Auth->allow($allowedActionsArray);
	    }

	    //! Show a list of all Status and a count of how many members are in each status.
	    public function index() 
	    {
	    	$this->set('memberStatusInfo', $this->Member->Status->getStatusSummaryAll());
	    	$this->set('memberTotalCount', $this->Member->getCount());

	    	$this->Nav->add('Register Member', 'members', 'register');
    		$this->Nav->add('E-mail all current members', 'members', 'emailMembersWithStatus', array( Status::CURRENT_MEMBER ) );
	    }

		//! Show a list of all members, their e-mail address, status and the groups they're in.
		public function listMembers() 
		{

			/*
	    	    Actions should be added to the array like so:
	    	    	[actions] =>
	    					[n]
	    						[title] => action title
	    						[controller] => action controller
	    						[action] => action name
	    						[params] => array of params
	    	*/
	        $this->_paginateMemberList($this->Member->getMemberSummaryAll(true));
	    }

		//! List all members with a particular status.
		/*!
			@param int $statusId The status to list all members for.
		*/
		public function listMembersWithStatus($statusId) 
		{
			// Use the list members view
			$this->view = 'list_members';

			// If statusId is not set, list all the members
			if(!isset($statusId))
			{
				return $this->redirect( array('controller' => 'members', 'action' => 'listMembers') );
			}

			$this->_paginateMemberList($this->Member->getMemberSummaryForStatus(true, $statusId));
	        $this->set('statusInfo', $this->Member->Status->getStatusSummaryForId($statusId));
	    }

	    //! List all members who's name, email, username or handle is similar to the search term.
		public function search() 
		{
			// Use the list members view
			$this->view = 'list_members';

			// If search term is not set, list all the members
			if(	!isset($this->params['url']['query']))
			{
				return $this->redirect( array('controller' => 'members', 'action' => 'listMembers') );
			}

			$keyword = $this->params['url']['query'];

	        $this->_paginateMemberList($this->Member->getMemberSummaryForSearchQuery(true, $keyword));
	    }

	    //! Perform all the actions needed to get a paginated member list with actions applied.
	    /*!
	    	@param array $queryResult The query to pass to paginate(), usually obtained from a Member::getMemberSummary**** method.
	    */
	    private function _paginateMemberList($queryResult)
	    {
	    	$this->paginate = $queryResult;
	        $memberList = $this->paginate('Member');
	        $memberList = $this->Member->formatMemberInfoList($memberList, false);
	    	$memberList = $this->_addMemberActions($memberList);
	        $this->set('memberList', $memberList);
	    }

	    //! Get a MailingList model.
	    /*!
	    	@retval MailingListModel The MailingListModel.
	    */
	    public function getMailingList()
	    {
	    	App::uses('MailingList', 'Model');
	    	return new MailingList();
	    }

	    //! Grab a users e-mail address and start the membership procedure.
	    public function register() 
	    {
	    	$this->MailingList = $this->getMailingList();
	    	// Need a list of mailing-lists that the user can opt-in to
	    	$mailingLists = $this->MailingList->listMailinglists(false);
			$this->set('mailingLists', $mailingLists);

			if($this->request->is('post'))
			{
				$result = $this->Member->registerMember( $this->request->data );

				if( $result )
				{
					$status = $result['status'];

					if( $status != Status::PROSPECTIVE_MEMBER )
					{
						// User is already way past this membership stage, send them to the login page
						$this->Session->setFlash( 'User with that e-mail already exists.' );
						return $this->redirect( array('controller' => 'members', 'action' => 'login') );
					}

					$email = $result['email'];

					// E-mail the member admins for a created record
					if( $result['createdRecord'] === true )
					{
						$this->_sendEmail(
	                		$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
	                		'New Prospective Member Notification',
	                		'notify_admins_member_added',
	                		array( 
								'email' => $email,
							)
	                	);
					}

					$memberId = $result['memberId'];

					// But e-mail the member either-way
					$this->_sendProspectiveMemberEmail($memberId);

					$this->_setFlashFromMailingListInfo('Registration successful, please check your inbox.' , Hash::get($result, 'mailingLists'));
					return $this->redirect(array( 'controller' => 'pages', 'action' => 'home'));
				}
				else
				{
					$this->Session->setFlash( 'Unable to register.' );
				}
			}
		}

		//! Set the session flash message based on the data returned from a function that updates the mailing list settings.
		/*!
			@param string $initialMessage The message to show at the start of the flash, before any mailing list messages.
			@param array $result The results from the function that updated the mailing list settings.
		*/
		private function _setFlashFromMailingListInfo($initialMessage, $results)
		{
			$message = $initialMessage;
			if(	is_array($results) && 
				!empty($results))
			{
				$message .= '\n';
				foreach ($results as $resultData) 
				{
					$resultStr = '';

					if($resultData['successful'])
					{
						$resultStr .= 'Successfully ';
					}
					else
					{
						$resultStr .= 'Unable to ';
					}

					if($resultData['action'] == 'subscribe')
					{
						$resultStr .= 'subscribed to ';
					}
					else
					{
						$resultStr .= 'unsubscribed from ';
					}

					$resultStr .= $resultData['name'];

					$message .= $resultStr . '\n';
				}
			}

			$this->Session->setFlash( $message );
		}

	    //! Allow a member to set-up their initial login details.
	    /*
	    	@param int $id The id of the member whose details we want to set-up.
	    */
	    public function setupLogin($id = null)
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	if($this->request->is('post'))
	    	{
	    		try
	    		{
	    			if( $this->Member->setupLogin($id, $this->request->data) )
		    		{
		    			$this->Session->setFlash('Username and Password set, please login.');
		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'login'));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to set username and password.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));		
	    		}
	    	}
	    }

	    //! Allow a member who is logged in to set-up their contact details.
	    /*
	    	@param int $id The id of the member whose contact details we want to set-up.
	    */
	    public function setupDetails($id = null)
	    {
	    	// Can't do this if id isn't the same as that of the logged in user.
	    	if( $id == null ||
	    		$id != $this->_getLoggedInMemberId() )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	if( $this->request->is('post') )
	    	{
	    		try
	    		{
	    			if( $this->Member->setupDetails($id, $this->request->data) )
		    		{
		    			$memberEmail = $this->Member->getEmailForMember($id);

		    			$this->Session->setFlash('Contact details saved.');

						$this->_sendEmail(
							$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
							'New Member Contact Details',
							'notify_admins_check_contact_details',
							array( 
								'email' => $memberEmail,
								'id' => $id,
							)
						);

						$this->_sendEmail(
							$memberEmail,
							'Contact Information Completed',
							'to_member_post_contact_update'
						);

						return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to save contact details.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Reject the contact details a member has supplied, with a message to say why.
	    /*!
	    	@param int $id The id of the member whose contact details we're rejecting.
	    */
	    public function rejectDetails($id = null) 
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	$this->set('name', $this->Member->getUsernameForMember($id));

	    	if($this->request->is('post'))
	    	{
	    		try
	    		{
		    		if($this->Member->rejectDetails($id, $this->request->data, $this->_getLoggedInMemberId()))
		    		{
		    			$this->Session->setFlash('Member has been contacted.');

		    			$memberEmail = $this->Member->getEmailForMember($id);

		    			Controller::loadModel('MemberEmail');
		    			
		    			$this->_sendEmail(
		    				$memberEmail,
		    				'Issue With Contact Information',
		    				'to_member_contact_details_rejected',
		    				array(
		    					'reason' => $this->MemberEmail->getMessage( $this->request->data )
		    				)
		    			);

		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to set member status. Failed to reject details.');
		    		}
	    		}
	    		catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}	    	
	    }

	    //! Accept the contact details a member has supplied.
	    /*!
	    	@param int $id The id of the member whose contact details we're accepting.
	    */
	    public function acceptDetails($id = null)
	    {
	    	if( $id == null )
	    	{
	    		return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    	}

	    	$this->set('accounts', $this->Member->getReadableAccountList());
	    	$this->set('name', $this->Member->getUsernameForMember($id));

	    	if(	$this->request->is('post') ||
	    		$this->request->is('put'))
	    	{
	    		try
	    		{
	    			$memberDetails = $this->Member->acceptDetails($id, $this->request->data, $this->_getLoggedInMemberId());
		    		if($memberDetails)
		    		{
		    			$this->Session->setFlash('Member details accepted.');

		    			$this->_sendSoDetailsToMember($id);

						$this->_sendEmail(
							$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
							'Impending Payment',
							'notify_admins_payment_incoming',
							array(
								'memberId' => $id,
								'memberName' => $memberDetails['name'],
								'memberEmail' => $memberDetails['email'],
								'memberPayRef' => $memberDetails['paymentRef'],
							)
						);

						return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to update member details');
		    		}
		    	}
		    	catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Approve a membership
	    /*!
	    	@param int $id The id of the member who we are approving.
	    */
	    public function approveMember($id = null) 
	    {
	    	try
	    	{
	    		$memberDetails = $this->Member->approveMember($id, $this->_getLoggedInMemberId());
	    		if($memberDetails)
		    	{
		    		$this->Session->setFlash('Member has been approved.');

		    		$adminDetails = $this->Member->getMemberSummaryForMember($this->_getLoggedInMemberId());

		    		// Notify all the member admins
		    		$this->_sendEmail(
		    			$this->Member->getEmailsForMembersInGroup(Group::MEMBER_ADMIN),
		    			'Member Approved',
		    			'notify_admins_member_approved',
		    			array(
		    				'memberName' => $memberDetails['name'],
		    				'memberEmail' => $memberDetails['email'],
		    				'memberId' => $id,
		    				'memberPin' => $memberDetails['pin'],
		    			)
		    		);

		    		// E-mail the member
		    		$this->_sendEmail(
		    			$memberDetails['email'],
		    			'Membership Complete',
		    			'to_member_access_details',
		    			array( 
							'adminName' => $adminDetails['name'],
							'adminEmail' => $adminDetails['email'],
							'manLink' => Configure::read('hms_help_manual_url'),
							'outerDoorCode' => Configure::read('hms_access_street_door'),
							'innerDoorCode' => Configure::read('hms_access_inner_door'),
							'wifiSsid' => Configure::read('hms_access_wifi_ssid'),
							'wifiPass' => Configure::read('hms_access_wifi_password'),
						)
		    		);
		    	}
		    	else
		    	{
		    		$this->Session->setFlash('Member details could not be updated.');
		    	}
	    	}
	    	catch(InvalidStatusException $e)
    		{
    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
    		}
	    	
	    	return $this->redirect($this->referer());
	    }

	    //! Change a members password
	    /*!
	    	@param int $id The id of the member whose password we are changing.
	    */
	    public function changePassword($id) 
	    {
	    	$memberInfo = $this->Member->getMemberSummaryForMember($id);
	    	if(!$memberInfo)
	    	{
	    		return $this->redirect($this->referer());
	    	}

	    	$adminId = $this->_getLoggedInMemberId();
	    	$this->set('id', $id);
	    	$this->set('name', $memberInfo['username']);
	    	$this->set('ownAccount', $adminId == $id);

	    	if($this->request->is('post'))
	    	{
		    	try
		    	{
		    		if($this->Member->changePassword($id, $adminId, $this->request->data))
		    		{
		    			$this->Session->setFlash('Password updated.');
		    			return $this->redirect(array( 'controller' => 'members', 'action' => 'view', $id));
		    		}
		    		else
		    		{
		    			$this->Session->setFlash('Unable to update password.');
		    		}
		    	}
		    	catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    		catch(NotAuthorizedException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
	    	}
	    }

	    //! Generate or complete a forgot password request.
	    /*
	    	@param string $guid The id of the request, may be null.
	    */
	    public function forgotPassword($guid = null)
	    {
	    	if($guid != null)
	    	{
	    		if(!ForgotPassword::isValidGuid($guid))
	    		{
	    			$guid = null;
	    		}
	    	}

	    	$this->set('createRequest', $guid == null);

	    	if($this->request->is('post'))
    		{
    			try
		    	{
		    		if($guid == null)
			    	{
			    		$data = $this->Member->createForgotPassword($this->request->data);
			    		if($data != false)
			    		{
			    			$this->_sendEmail(
			    				$data['email'],
			    				'Password Reset Request',
			    				'forgot_password',
			    				array(
			    					'id' => $data['id'],
			    				)
			    			);

			    			return $this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_sent'));
			    		}
			    		else
			    		{
			    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
			    		}
			    	}
			    	else
			    	{
			    		if($this->Member->completeForgotPassword($guid, $this->request->data))
			    		{
			    			$this->Session->setFlash('Password successfully set.');
							return $this->redirect(array('controller' => 'members', 'action' => 'login'));
			    		}
			    		else
			    		{
			    			$this->Session->setFlash('Unable to set password');
			    			return $this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
			    		}
			    	}
			    }
			    catch(InvalidStatusException $e)
	    		{
	    			return $this->redirect(array('controller' => 'pages', 'action' => 'home'));
	    		}
    		}
	    }

	    //! Send the 'prospective member' email to a member.
	    /*!
	    	@param int $id The id of the member to send the message to.
	    */
	    public function sendMembershipReminder($id = null)
	    {
	    	if($id != null)
	    	{
	    		if($this->_sendProspectiveMemberEmail($id))
    			{
					$this->Session->setFlash('Member has been contacted');
    			}
    			else
    			{
    				$this->Session->setFlash('Unable to contact member');
    			}
	    	}

	    	return $this->redirect($this->referer());
	    }

	    //! Send the 'prospective member' email to a member.
	    /*!
	    	@param int $memberId The id of the member to send the message to.
	    	@retval bool True if e-mail was sent.
	    */
	    private function _sendProspectiveMemberEmail($memberId)
	    {
	    	$email = $this->Member->getEmailForMember($memberId);
	    	if($email)
	    	{
	    		return $this->_sendEmail(
					$email,
					'Welcome to Nottingham Hackspace',
					'to_prospective_member',
					array(
						'memberId' => $memberId,
					)
				);
	    	}
	    	return false;
	    }

	    //! Send the 'contact details reminder' email to a member.
	    /*!
	    	@param int $id The id of the member to contact.
	    */
	    public function sendContactDetailsReminder($id = null)
	    {
	    	$emailSent = false;

	    	$email = $this->Member->getEmailForMember($id);
	    	if($email)
	    	{
	    		$emailSent = $this->_sendEmail(
					$email,
					'Membership Info',
					'to_member_contact_details_reminder',
					array(
						'memberId' => $id,
					)
				);
	    	}

	    	if($emailSent)
			{
				$this->Session->setFlash('Member has been contacted');
			}
			else
			{
				$this->Session->setFlash('Unable to contact member');
			}

			return $this->redirect($this->referer());
	    }

	    //! Send the 'so details reminder' email to a member.
	    /*!
	    	@param int $id The id of the member to contact.
	    */
	    public function sendSoDetailsReminder($id = null)
	    {
	    	if($this->_sendSoDetailsToMember($id))
	    	{
	    		$this->Session->setFlash('Member has been contacted');
	    	}
	    	else
	    	{
	    		$this->Session->setFlash('Unable to contact member');
	    	}
	    	return $this->redirect($this->referer());
	    }

	    //! Send the e-mail containing standing order info to a member.
	    /*!
	    	@param int $memberId The id of the member to send the reminder to.
	    	@return bool True if mail was sent, false otherwise.
	    */
	    private function _sendSoDetailsToMember($memberId)
	    {
	    	$memberSoDetails = $this->Member->getSoDetails($memberId);
	    	if($memberSoDetails != null)
	    	{
	    		return $this->_sendEmail(
	    			$memberSoDetails['email'],
	    			'Bank Details',
	    			'to_member_so_details',
	    			array( 
						'name' => $memberSoDetails['name'],
						'paymentRef' => $memberSoDetails['paymentRef'],
						'accountNum' => Configure::read('hms_so_accountNumber'),
						'sortCode' => Configure::read('hms_so_sortCode'),
						'accountName' => Configure::read('hms_so_accountName'),
					)
	    		);
	    	}

			return false;
	    }

	    //! View the full member profile.
	    /*!	
	   		@param int $id The id of the member to view.
	   	*/
	    public function view($id) 
	    {
	    	$showAdminFeatures = false;
	    	$showFinances = false;
	    	$hasJoined = false;
	    	$canView = $this->_getViewPermissions($id, $showAdminFeatures, $showFinances, $hasJoined);

	    	if($canView)
	    	{
	    		$rawMemberInfo = $this->Member->getMemberSummaryForMember($id, false);

	    		if($rawMemberInfo)
	    		{
	    			$memberEmail = $this->Member->getEmailForMember($rawMemberInfo);
	    			$this->MailingList = $this->getMailingList();
			    	// Need a list of mailing-lists that the user can opt-in to
			    	$mailingLists = $this->MailingList->getListsAndSubscribeStatus($memberEmail);
					$this->set('mailingLists', $mailingLists);

	    			$sanitisedMemberInfo = $this->Member->sanitiseMemberInfo($rawMemberInfo, $showAdminFeatures, $showFinances, $hasJoined, true, true);
	    			if($sanitisedMemberInfo)
	    			{
	    				$formattedInfo = $this->Member->formatMemberInfo($sanitisedMemberInfo, true);
	    				if($formattedInfo)
	    				{
	    					$this->set('member', $formattedInfo);

					    	$this->Nav->add('Edit', 'members', 'edit', array( $id ) );
					        $this->Nav->add('Change Password', 'members', 'changePassword', array( $id ) );

					        foreach ($this->_getActionsForMember($id) as $action) 
					        {
					        	$class = '';
					        	if(isset($action['class']))
					        	{
					        		$class = $action['class'];
					        	}
					        	$this->Nav->add($action['title'], $action['controller'], $action['action'], $action['params'], $class);
					        }

					        return; // Don't hit that redirect
	    				}
	    			}
	    		}
	    	}
	    	
	    	// Couldn't find a record with that id...
	    	return $this->redirect($this->referer());
	    }

	    //! Allow editing of a member profile.
	    /*!	
	   		@param int $id The id of the member to view.
	   	*/
	    public function edit($id) 
	    {
	    	$showAdminFeatures = false;
	    	$showFinances = false;
	    	$hasJoined = false;
	    	$canEdit = $this->_getViewPermissions($id, $showAdminFeatures, $showFinances, $hasJoined);
	    	if($canEdit)
	    	{
	    		$rawMemberInfo = $this->Member->getMemberSummaryForMember($id, false);
		    	if($rawMemberInfo)
		    	{
		    		$memberEmail = $this->Member->getEmailForMember($rawMemberInfo);
	    			$this->MailingList = $this->getMailingList();
			    	// Need a list of mailing-lists that the user can opt-in to
			    	$mailingLists = $this->MailingList->getListsAndSubscribeStatus($memberEmail);
					$this->set('mailingLists', $mailingLists);

		    		$sanitisedMemberInfo = $this->Member->sanitiseMemberInfo($rawMemberInfo, $showAdminFeatures, $showFinances, $hasJoined, true, true);
		    		$formattedMemberInfo = $this->Member->formatMemberInfo($sanitisedMemberInfo, true);
			    	if($formattedMemberInfo)
			    	{
			    		$this->set('member', $formattedMemberInfo);
			    		$this->set('accounts', $this->Member->getReadableAccountList());
			    		$this->set('groups', $this->Member->Group->getGroupList());

			    		if(	$this->request->is('post') ||
			    			$this->request->is('put'))
			    		{
			    			$sanitisedData = $this->Member->sanitiseMemberInfo($this->request->data, $showAdminFeatures, $showFinances, $hasJoined, $showAdminFeatures, false);
			    			if($sanitisedData)
			    			{
			    				$updateResult = $this->Member->updateDetails($id, $sanitisedData, $this->_getLoggedInMemberId());
			    				if(is_array($updateResult))
				    			{
				    				$this->_setFlashFromMailingListInfo('Details updated.', $updateResult['mailingLists']);
							        return $this->redirect(array('action' => 'view', $id));
				    			}
				    		}
				    		$this->Session->setFlash('Unable to update details.');
			    		}

			    		if (!$this->request->data) 
			    		{
					        $this->request->data = $rawMemberInfo;
					    }
			    	}
		    	}
	    	}
	    	else
	    	{
	    		// Couldn't find a record with that id...
	    		return $this->redirect($this->referer());
	    	}
		}

		//! Revoke a members membership
		/*
			@param int $memberId The id of the member to revoke.
		*/
		public function revokeMembership($id = null)
		{
			try
			{
				if($this->Member->revokeMembership($id, $this->_getLoggedInMemberId()))
				{
					$this->Session->setFlash('Membership revoked.');
				}
				else
				{
					$this->Session->setFlash('Unable to revoke membership.');
				}
			}
			catch(InvalidStatusException $ex)
			{
				$this->Session->setFlash('Only current members can have their membership revoked.');
			}
			catch(NotAuthorizedException $ex)
			{
				$this->Session->setFlash('You are not authorized to do that.');
			}

			return $this->redirect($this->referer());
		}

		//! Reinstate an ex-members membership
		/*
			@param int $memberId The id of the member to reinstate.
		*/
		public function reinstateMembership($id = null)
		{
			try
			{
				if($this->Member->reinstateMembership($id, $this->_getLoggedInMemberId()))
				{
					$this->Session->setFlash('Membership reinstated.');
				}
				else
				{
					$this->Session->setFlash('Unable to reinstate membership.');
				}
			}
			catch(InvalidStatusException $ex)
			{
				$this->Session->setFlash('Only ex members can have their membership reinstated.');
			}
			catch(NotAuthorizedException $ex)
			{
				$this->Session->setFlash('You are not authorized to do that.');
			}

			return $this->redirect($this->referer());
		}

		//! Check to see if certain view/edit params should be shown to the logged in member.
		/*!
			@param int $memberId The id of the member being viewed.
			@param ref bool $showAdminFeatures If this is set to true then admin features should be shown.
			@param ref bool $showFinances If this is set to true then financial information should be shown.
			@param ref bool $hasJoined If this is set to true then the member has joined.
		*/
		private function _getViewPermissions($memberId, &$showAdminFeatures, &$showFinances, &$hasJoined)
		{
			if(is_numeric($memberId))
			{
				$memberStatus = $this->Member->getStatusForMember($memberId);
				if($memberStatus)
				{
					$viewerId = $this->_getLoggedInMemberId();

	    			$showAdminFeatures = 
			    		(	$this->Member->GroupsMember->isMemberInGroup($viewerId, Group::MEMBER_ADMIN) || 
			    			$this->Member->GroupsMember->isMemberInGroup($viewerId, Group::FULL_ACCESS) );

		    		// Only show the finance stuff to admins, current or ex members
		    		$hasJoined = in_array($memberStatus, array(Status::CURRENT_MEMBER, Status::EX_MEMBER));

		    		$viewingOwnProfile = $viewerId == $memberId;

			    	$showFinances = ($showAdminFeatures || $viewingOwnProfile)  && $hasJoined;

			    	return ($viewingOwnProfile || $showAdminFeatures);
				}
			}
			return false;
		}

		//! Send an e-mail to every member with a certain status.
		/*!
			@param int $statusId Attempt to e-mail members with this status.
		*/
		public function emailMembersWithStatus($statusId) 
		{
			$memberList = $this->Member->getMemberSummaryForStatus(false, $statusId);
			$statusInfo = $this->Member->Status->getStatusSummaryForId($statusId);

			$this->set('members', $memberList);
			$this->set('status', $statusInfo);

			if(	$memberList &&
				$statusInfo)
			{
				if($this->request->is('post'))
				{
					$messageDetails = $this->Member->validateEmail($this->request->data);

					if($messageDetails)
					{
						$failedMembers = array();
						foreach ($memberList as $member) 
						{
							$emailOk = $this->_sendEmail(
								$member['email'], 
								$messageDetails['subject'], 
								'default', 
								array(
									'content' => $messageDetails['message']
								)
							);
							if(!$emailOk)
							{
								array_push($failedMembers, $member);
							}
						}

						$numFailed = count($failedMembers);
						if($numFailed > 0)
						{
							if($numFailed == count($memberList))
							{
								$this->Session->setFlash('Failed to send e-mail to any member.');
							}
							else
							{
								$flashMessage = 'E-mail sent to all but the following members:';
								foreach ($failedMembers as $failedMember) 
								{
									$flashMessage .= '\n' . $failedMember['name'];
								}
								$this->Session->setFlash($flashMessage);
							}
						}
						else
						{
							$this->Session->setFlash('E-mail sent to all listed members.');
						}
						return $this->redirect(array('controller' => 'members', 'action' => 'index'));
					}
				}
			}
			else
			{
				return $this->redirect($this->referer());
			}
		}

		//! Send an e-mail to one or more email addresses.
		/*
			@param mixed $to Either an array of email address strings, or a string containing a singular e-mail address.
			@param string $subject The subject of the email.
			@param string $template Which email view template to use.
			@param array $viewVars Array containing all the variables to pass to the view.
			@retval bool True if e-mail was sent, false otherwise.
		*/
		private function _sendEmail($to, $subject, $template, $viewVars = array())
		{
			if($this->email == null)
			{
				$this->email = new CakeEmail();
			}

			$email = $this->email;
			$email->config('smtp');
			$email->from(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->sender(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->emailFormat('html');
			$email->to($to);
			$email->subject($subject);
			$email->template($template);
			$email->viewVars($viewVars);
			return $email->send();
		}

		//! Attempt to login as a member.
		public function login() 
		{
		    if ($this->request->is('post')) 
		    {
		        if ($this->Auth->login()) 
		        {
		            return $this->redirect($this->Auth->redirect());
		        } 
		        else 
		        {
		            $this->Session->setFlash(__('Invalid username or password, try again'));
		        }
		    }
		}

		//! Logout.
		public function logout() 
		{
		    return $this->redirect($this->Auth->logout());
		}

		//! Adds the appropriate actions to each member in the member list.
		/*!
			@param array $memberList A list of member summaries to add the actions to.
			@retval array The original memberList, with the actions added for each member.
		*/
		private function _addMemberActions($memberList)
		{
			// Have to add the actions ourselves
	    	for($i = 0; $i < count($memberList); $i++)
	    	{
	    		$id = $memberList[$i]['id'];
	    		$memberList[$i]['actions'] = $this->_getActionsForMember($id);
	    	}

	    	return $memberList;
		}

		//! Get an array of possible actions for a member
		/*
			@param int @memberId The id of the member to work with.
			@retval array An array of actions.
		*/
		private function _getActionsForMember($memberId)
		{
			$statusId = $this->Member->getStatusForMember($memberId);

			$actions = array();
    		switch($statusId)
    		{
    			case Status::PROSPECTIVE_MEMBER:
    				array_push($actions, 
    					array(
    						'title' => 'Send Membership Reminder',
    						'controller' => 'members',
    						'action' => 'sendMembershipReminder',
    						'params' => array(
    							$memberId,
    						),
    					)
    				);
    			break;

    			case Status::PRE_MEMBER_1:
    				array_push($actions, 
    					array(
    						'title' => 'Send Contact Details Reminder',
    						'controller' => 'members',
    						'action' => 'sendContactDetailsReminder',
    						'params' => array(
    							$memberId,
    						),
    					)
    				);
    			break;

    			case Status::PRE_MEMBER_2:
    				array_push($actions, 
    					array(
    						'title' => 'Accept Details',
    						'controller' => 'members',
    						'action' => 'acceptDetails',
    						'params' => array(
    							$memberId,
    						),
    						'class' => 'positive',
    					),

    					array(
    						'title' => 'Reject Details',
    						'controller' => 'members',
    						'action' => 'rejectDetails',
    						'params' => array(
    							$memberId,
    						),
    						'class' => 'negative',
    					)
    				);
    			break;

    			case Status::PRE_MEMBER_3:

    				array_push($actions, 
    					array(
    						'title' => 'Send SO Details Reminder',
    						'controller' => 'members',
    						'action' => 'sendSoDetailsReminder',
    						'params' => array(
    							$memberId,
    						),
    					)
    				);

    				array_push($actions, 
    					array(
    						'title' => 'Approve Member',
    						'controller' => 'members',
    						'action' => 'approveMember',
    						'params' => array(
    							$memberId,
    						),
    						'class' => 'positive',
    					)
    				);

    			break;

    			case Status::CURRENT_MEMBER:
    				array_push($actions, 
    					array(
    						'title' => 'Revoke Membership',
    						'controller' => 'members',
    						'action' => 'revokeMembership',
    						'params' => array(
    							$memberId,
    						),
    						'class' => 'negative',
    					)
    				);
    			break;

    			case Status::EX_MEMBER:
    				array_push($actions, 
    					array(
    						'title' => 'Reinstate Membership',
    						'controller' => 'members',
    						'action' => 'reinstateMembership',
    						'params' => array(
    							$memberId,
    						),
    						'class' => 'positive',
    					)
    				);
    			break;
    		}

    		return $actions;
		}

		//! Get the id of the currently logged in Member.
		/*!
			@retval int The id of the currently logged in Member, or 0 if not found.
		*/
		private function _getLoggedInMemberId()
		{
			return $this->Member->getIdForMember($this->Auth->user());
		}

		//! Test to see if a request is coming from within the hackspace.
		/*!
			@retval bool True if the request is coming from with in the hackspace, false otherwise.
		*/
		public function isRequestLocal()
		{
			return preg_match('/10\.0\.0\.\d+/', $this->getRequestIpAddress());
		}

		//! Get the ip address of the request
		/*
			@retval string The IP address of the request.
		*/
		public function getRequestIpAddress()
		{
			// We might have a debug config here to force requests to be local
			App::uses('PhpReader', 'Configure');
			Configure::config('default', new PhpReader());
			Configure::load('debug', 'default');

			try
			{
				$ip = Configure::read('forceRequestIp');
				return $ip;
			}
			catch(ConfigureException $ex)
			{
				// We don't care.
			}

			return $this->request->clientIp();
		}
	}
?>