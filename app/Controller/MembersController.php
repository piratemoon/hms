<?php
	
	App::uses('HmsAuthenticate', 'Controller/Component/Auth');
	App::uses('Member', 'Model');
	
	class MembersController extends AppController {
	    
	    public $helpers = array('Html', 'Form', 'Tinymce');

	    public $components = array('MailChimp');

	    public function isAuthorized($user, $request)
	    {
	    	if(parent::isAuthorized($user, $request))
	    	{
	    		return true;
	    	}

	    	$userIsMemberAdmin = Member::isInGroupMemberAdmin( $user );
	    	$userIsTourGuide = Member::isInGroupTourGuide( $user );
	    	$actionHasParams = isset( $request->params ) && isset($request->params['pass']) && count( $request->params['pass'] ) > 0;
	    	$userIdIsSet = isset( $user['Member'] ) && isset( $user['Member']['member_id'] );
	    	$userId = $userIdIsSet ? $user['Member']['member_id'] : null;

	    	/*
	    	print_r($userIsMemberAdmin);
	    	print_r($actionHasParams);
	    	print_r($userIdIsSet);
	    	print_r($userId);
	    	*/

	    	switch ($request->action) {
	    		case 'index':
	    		case 'list_members':
	    		case 'list_members_with_status':
	    		case 'email_members_with_status':
	    		case 'search':
	    		case 'set_member_status':
	    			return $userIsMemberAdmin; 

	    		case 'change_password':
	    		case 'view':
	    		case 'edit':
	    			if( $userIsMemberAdmin || 
	    				( $actionHasParams && $userIdIsSet && $request->params['pass'][0] == $userId ) )
	    			{
	    				return true;
	    			}
	    			break;

	    		case 'login':
	    		case 'logout':
	    			return true;
	    	}

	    	return false;
	    }

	    public function beforeFilter() {
	        parent::beforeFilter();
	        $this->Auth->allow('logout', 'login', 'register', 'forgot_password');
	    }

	    # Show some basic info, and link to other things
	    public function index() {

	    	# Need the Status model here
	    	$statusList = $this->Member->Status->find('all');

	    	# Come up with a count of all members
	    	# And a count for each status
	    	
	    	$memberStatusCount = array();
	    	# Init the array
	    	foreach ($statusList as $current) {
	    		$memberStatusCount[$current['Status']['title']] = 
	    			array( 	'id' => $current['Status']['status_id'],
	    					'desc' => $current['Status']['description'],
	    					'count' => 0
	    			);
	    	}

	    	$memberTotalCount = 0;
	    	foreach ($this->Member->find('all') as $member) {
	    		$memberTotalCount++;
	    		$memberStatus = $member["Status"]['title'];
	    		if(isset($memberStatusCount[$memberStatus]))
	    		{
	    			$memberStatusCount[$memberStatus]['count']++;	
	    		}
	    	}

	    	$this->set('memberStatusCount', $memberStatusCount);
	    	$this->set('memberTotalCount', $memberTotalCount);

	    	$this->Nav->add('Register Member', 'members', 'register');
    		$this->Nav->add('E-mail all current members', 'members', 'email_members_with_status', array( 2 ) );
	    }

		# List info about all members
		public function list_members() {
	        $this->set('members', $this->Member->find('all'));
	    }

		# List info about all members with a certain status
		public function list_members_with_status($statusId) {
			# Uses the default list view
			$this->view = 'list_members';

			if(isset($statusId))
			{
		        $this->set('members', $this->Member->find('all', array( 'conditions' => array( 'Member.member_status' => $statusId ) )));
		        $statusData = $this->Member->Status->find('all', array( 'conditions' => array( 'Status.status_id' => $statusId ) ));
				$this->set('statusData', $statusData[0]['Status']);
			}
			else
			{
				$this->redirect( array( 'controller' => 'members', 'action' => 'list_members' ) );
			}
	    }

	    # List info about all members who's email or name is like $query
		public function search() {

			# Uses the default list view
			$this->view = 'list_members';
			if(isset($this->request->data['Member']))
			{
				$keyword = $this->request->data['Member']['query'];
				$this->set('members', $this->Member->find('all', array( 'conditions' => array( 'OR' => array("Member.name Like'%$keyword%'", "Member.email Like'%$keyword%'" )))));
			}
			else
			{
				$this->redirect( array( 'controller' => 'members', 'action' => 'list_members' ) );
			}
	    }

	    # Add a new member
	    public function register() {

	    	$mailingLists = $this->_get_mailing_lists_and_subscruibed_status(null);
			$this->set('mailingLists', $mailingLists);

	    	if ($this->request->is('post')) {

	    		$this->request->data['Member']['member_status'] = 1;

	            if ($this->Member->saveAll($this->request->data)) {

	            	$this->request->data['Member']['member_id'] = $this->Member->getLastInsertId();

	                $this->Session->setFlash('New member added.');

	                $memberInfo = $this->set_account($this->request->data);

	                $paymentRef = $memberInfo['Account']['payment_ref'];
	                if( isset($paymentRef) == false ||
	                	$paymentRef == null )
	                {
	                	$paymentRef = $this->Member->Account->generate_payment_ref($memberInfo);
	                }
	               
	                # Email the new member, and notify the admins
	                $adminEmail = $this->prepare_email_for_members_in_group(5);
					$adminEmail->subject('New Prospective Member Notification');
					$adminEmail->template('notify_admins_member_added', 'default');
					$adminEmail->viewVars( array( 
						'member' => $this->request->data['Member'],
						'memberAdmin' => AuthComponent::user('Member.name'),
						'paymentRef' => $paymentRef,
						 )
					);
					$adminEmail->send();

					$memberEmail = $this->prepare_email();
					$memberEmail->to( $this->request->data['Member']['email'] );
					$memberEmail->subject('Welcome to Nottingham Hackspace');
					$memberEmail->template('to_prospective_member', 'default');
					$memberEmail->viewVars( array(
						'memberName' => $this->request->data['Member']['name'],
						'guideName' => $this->request->data['Other']['guide'],
						'paymentRef' => $paymentRef,
						) 
					);
					$memberEmail->send();

					$this->redirect(array('action' => 'index'));
	            } else {
	                $this->Session->setFlash('Unable to add member.');
	            }
	        }

	        # Generate the Pin data
			$this->request->data['Pin']['pin'] = $this->Member->Pin->generate_unique_pin();
			$this->request->data['Member']['account_id'] = -1;
	    }

	    public function change_password($id = null) {

	    	Controller::loadModel('ChangePassword');

			$this->Member->id = $id;
			$memberInfo = $this->Member->read();
			$memberIsMemberAdmin = $this->Member->memberInGroup(AuthComponent::user('Member.member_id'), 5);
			$this->request->data['Member']['member_id'] = $id;
			$this->set('memberInfo', $memberInfo);
			$this->set('memberIsMemberAdmin', $memberInfo);
			$this->set('memberEditingOwnProfile', AuthComponent::user('Member.member_id') == $id);

			if ($this->request->is('get')) {
			}
			else
			{
				# Validate the input using the dummy model
				$this->ChangePassword->set($this->data);
				if($this->ChangePassword->validates())
				{
					# Only member admins (group 5) and the member themselves can do this
					if( $this->request->data['Member']['member_id'] == AuthComponent::user('Member.member_id') ||
						$memberIsMemberAdmin ) 
					{
						# Check the current the user submitted
						if( isset($memberInfo['MemberAuth']) &&
							$memberInfo['MemberAuth'] != null ) 
						{
							$saltToUse = $memberInfo['MemberAuth']['salt'];
							$passwordToCheck = $memberInfo['MemberAuth']['passwd'];

							if( $memberIsMemberAdmin )
							{
								# Member admins need to enter their own password
								$memberAdminMemberInfo = $this->Member->find('first', array( 'conditions' => array( 'Member.member_id' => AuthComponent::user('Member.member_id') ) ) );
								$saltToUse = $memberAdminMemberInfo['MemberAuth']['salt'];
								$passwordToCheck = $memberAdminMemberInfo['MemberAuth']['passwd'];
							}

							$currentPasswordHash = HmsAuthenticate::make_hash($saltToUse, $this->request->data['ChangePassword']['current_password']);

							if( $currentPasswordHash === $passwordToCheck ) # MemberAdmins don't need to know the old password
							{
								# User submitted current password is ok, check the new one
								if( $this->request->data['ChangePassword']['new_password'] === $this->request->data['ChangePassword']['new_password_confirm'] )
								{
									if($this->_set_member_password($memberInfo, $this->request->data['ChangePassword']['new_password']))
									{
										$this->Session->setFlash('Password updated.');
										$this->redirect(array('action' => 'view', $id));
									}
									else
									{
										$this->Session->setFlash('Unable to update password.');
									}
								}
								else
								{
									$this->Session->setFlash('New password doesn\'t match new password confirm');
								}
							}
							else
							{
								$this->Session->setFlash('Current password incorrect');
							}
						}
					}
					else
					{
						$this->Session->setFlash('You are not authorised to do this');
					}
				}
			}
	    }

	    public function forgot_password($guid = null)
	    {
	    	if($guid != null)
	    	{
	    		# Check it's a valid UUID v4
	    		# With this handy regex
	    		if(preg_match('/^\{?[a-f\d]{8}-(?:[a-f\d]{4}-){3}[a-f\d]{12}\}?$/i', $guid) == false)
	    		{
	    			$guid = null;
	    		}
	    	}

	    	$this->set('guid', $guid);

	    	Controller::loadModel('ForgotPassword');

			if ($this->request->is('get')) 
			{
			}
			else
			{
				# Validate the input using the dummy model
				$this->ForgotPassword->set($this->data);
				if($this->ForgotPassword->validates())
				{
					# If guid is not set then we should be sending out the e-mail
					if($guid == null)
					{
						# Grab the member
						$memberInfo = $this->Member->find('all', array( 'conditions' => array('Member.email' => $this->request->data['ForgotPassword']['email']) ));
						if($memberInfo && count($memberInfo) > 0)
						{
							$entry = $this->ForgotPassword->generate_entry($memberInfo[0]);
							if($entry != null)
							{
								# E-mail the user...
								$email = $this->prepare_email();
								$email->to( $memberInfo[0]['Member']['email'] );
								$email->subject('Password Reset Request');
								$email->template('forgot_password', 'default');
								$email->viewVars( array( 
									'id' => $entry['ForgotPassword']['request_guid'],
									 )
								);
								$email->send();
								
								$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_sent'));
							}
						}
					}
					else
					{
						# Check all is well and then proceed with the password reset!

						# Grab the record 
						$record = $this->ForgotPassword->find('first', array('conditions' => array( 'ForgotPassword.request_guid' => $guid )));
						if(isset($record) == false || $record == null)
						{
							# FAIL INVALID GUID
							$this->Session->setFlash('Invalid request id');
							$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
						}
						else
						{
							$memberInfo = $this->Member->find('first', array( 'conditions' => array( 'Member.member_id' => $record['ForgotPassword']['member_id'] )));
							if(isset($memberInfo) == false || $memberInfo == null)
							{
								# FAIL INVALID RECORD
								$this->Session->setFlash('Invalid request id');
								$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
							}
							else
							{
								# CHECK FOR E-MAIL MATCH
								if($this->request->data['ForgotPassword']['email'] != $memberInfo['Member']['email'])
								{
									# FAIL INCORRECT E-MAIL
									# Don't tell them the actual reason
									$this->Session->setFlash('Invalid request id');
									$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
								}
								else
								{
									# AT [01/10/2012] Has this link already been used?
									# AT [01/10/2012] Or has it expired due to time passing?
									if($record['ForgotPassword']['expired'] != 0 || ( (time() - strtotime($record['ForgotPassword']['timestamp'])) > (60 * 60 * 2) ) )
									{
										# FAIL EXPIRED LINK
										$this->Session->setFlash('Invalid request id');
										$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
									}
									else
									{
										# AT [01/10/2012] Looks like we're all good to go
										# Need to do this next bit in a transaction so we can roll it back if needed

										$datasource = $this->Member->getDataSource();
										$datasource->begin();

										$succeeded = false;
										# First we set the password
										if($this->_set_member_password($memberInfo, $this->request->data['ForgotPassword']['new_password']))
										{
											# Then we expire the forgot password request
											$record['ForgotPassword']['expired'] = 1;
											$this->ForgotPassword->id = $record['ForgotPassword']['request_guid'];
											if($this->ForgotPassword->save($record))
											{
												if($datasource->commit())
												{
													# Success!
													$succeeded = true;
												}
											}
										}

										if($succeeded === true)
										{
											$this->Session->setFlash('Password successfully set.');
											$this->redirect(array('controller' => 'members', 'action' => 'login'));
										}
										else
										{
											$this->Session->setFlash('Unable to set password');
											$datasource->rollback();
											$this->redirect(array('controller' => 'pages', 'action' => 'forgot_password_error'));
										}
									}
								}
							}
						}
					}
				}
			}
	    }

	    private function _set_member_password($memberInfo, $newPassword)
	    {
	    	$memberInfo['MemberAuth']['passwd'] = HmsAuthenticate::make_hash($memberInfo['MemberAuth']['salt'], $newPassword);
			$memberInfo['MemberAuth']['member_id'] = $memberInfo['Member']['member_id'];
			if( isset( $memberInfo['MemberAuth']['salt'] ) === false )
			{
				$memberInfo['MemberAuth']['salt'] = HmsAuthenticate::make_salt();
			}
			if( $this->Member->MemberAuth->save($memberInfo) )
			{
				return true;
			}
			return false;
	    }

	    public function view($id = null) {
	        $this->Member->id = $id;
	        $memberInfo = $this->Member->read();

	        # Sanitise data
		    $user = AuthComponent::user();
		    $canSeeAll = Member::isInGroupMemberAdmin($user) || Member::isInGroupFullAccess($user);
		    if(!$canSeeAll)
		    {
		    	unset($memberInfo['Pin']);
		    }

	        $this->set('member', $memberInfo);

	        $this->Nav->add('Edit', 'members', 'edit', array( $id ) );
			switch ($memberInfo['Member']['member_status']) {
		        case 1: # Prospective member
		            $this->Nav->add('Approve Membership', 'members', 'set_member_status', array( $id, 2 ) );
		            break;

		        case 2: # Current member
		            $this->Nav->add('Revoke Membership', 'members', 'set_member_status', array( $id, 3 ) );
		            break;

		        case 3: # Ex-member
		            $this->Nav->add('Reinstate Membership', 'members', 'set_member_status', array( $id, 2 ) );
		            break;
		    }
		    $this->Nav->add('Change Password', 'members', 'change_password', array( $id ) );


		    $this->set('mailingLists', $this->_get_mailing_lists_and_subscruibed_status($memberInfo));
	    }

	    public function edit($id = null) {

	    	$this->set('groups', $this->Member->Group->find('list',array('fields'=>array('grp_id','grp_description'))));
	    	$this->set('statuses', $this->Member->Status->find('list',array('fields'=>array('status_id','title'))));
	    	# Add a value for using the existing account
	    	$accountsList =	$this->get_readable_account_list( array( -1 => 'Use Default' ) );

	    	$this->set('accounts', $accountsList);
			$this->Member->id = $id;
			$data = $this->Member->read(null, $id);
			$mailingLists = $this->_get_mailing_lists_and_subscruibed_status($data);
			$this->set('mailingLists', $mailingLists);

			if ($this->request->is('get')) {
			    $this->request->data = $this->sanitise_edit_data($data);
			} else {
				# Need to set some more info about the pin
				$this->request->data['Pin']['pin_id'] = $data['Pin']['pin_id'];

				# Clear the actual pin number though, so that won't get updated
				unset($this->request->data['Pin']['pin']);

				$this->request->data = $this->sanitise_edit_data($this->request->data);


			    if ($this->Member->saveAll($this->request->data)) {

			    	$flashMessage = 'Member details updated.';

			    	if(isset($this->request->data['MailingLists']))
			    	{
			    		if(!isset($this->request->data['MailingLists']['MailingLists']) ||
			    			!is_array($this->request->data['MailingLists']['MailingLists']))
			    		{
			    			$this->request->data['MailingLists']['MailingLists'] = array();
			    		}
			    		$firstSubscribtionChange = true;
			    		# Update list subscriptions if needed
			    		for($i = 0; $i < count($mailingLists); $i++)
			    		{
			    			$mailingLists[$i]['userWantsToBeSubscribed'] = in_array($i, $this->request->data['MailingLists']['MailingLists']);
			    			if($mailingLists[$i]['subscribed'] != $mailingLists[$i]['userWantsToBeSubscribed'])
			    			{

			    				if($firstSubscribtionChange)
			    				{
			    					$flashMessage .= '</br>';
			    					$firstSubscribtionChange = false;
			    				}
			    				if($mailingLists[$i]['userWantsToBeSubscribed'])
			    				{
			    					$this->MailChimp->subscribe($mailingLists[$i]['id'], $this->request->data['Member']['email']);
			    					if($this->MailChimp->error_code())
			    					{
			    						$flashMessage .= 'Unable to subscribe to: ' . $mailingLists[$i]['name'] . ' because ' . $this->MailChimp->error_msg() . '</br>';
			    					}
			    					else
			    					{
			    						$flashMessage .= 'E-mail confirmation of mailing list subscription for: ' . $mailingLists[$i]['name'] . ' has been sent.' . '</br>';	
			    					}
			    				}
			    				else
			    				{
			    					$this->MailChimp->unsubscribe($mailingLists[$i]['id'], $this->request->data['Member']['email']);
			    					if($this->MailChimp->error_code())
			    					{
			    						$flashMessage .= 'Unable to un-subscribe from: ' . $mailingLists[$i]['name'] . ' because ' . $this->MailChimp->error_msg() . '</br>';
			    						echo $this->MailChimp->error_msg();
			    					}
			    					else
			    					{
			    						$flashMessage .= 'Un-Subscribed from: ' . $mailingLists[$i]['name'] . '</br>';
			    					}
			    				}
			    			}
			    		}
			    	}


			    	$memberInfo = $this->set_account($this->request->data);
			    	$this->set_member_status_impl($data, $memberInfo);
					$this->update_status_on_joint_accounts($data, $memberInfo);

			        $this->Session->setFlash($flashMessage);
			        $this->redirect(array('action' => 'view', $id));
			    } else {
			        $this->Session->setFlash('Unable to update member details.');
			    }
			}
		}

		private function _get_mailing_lists_and_subscruibed_status($memberInfo)
		{
			$mailingListsRet = $this->MailChimp->list_mailinglists();
		    if(!$this->MailChimp->error_code())
		    {
		    	$mailingLists = $mailingListsRet['data'];

		    	if($memberInfo != null)
		    	{
			    	$numMailingLists = count($mailingLists);
			    	for($i = 0; $i < $numMailingLists; $i++)
			    	{
			    		// Grab the list of subscribed members
			    		$subscribedMembers = $this->MailChimp->list_subscribed_members($mailingLists[$i]['id']);
			    		if(!$this->MailChimp->error_code())
			    		{
			    			// Extract the emails
			    			$emails = Hash::extract($subscribedMembers['data'], '{n}.email');
			    			// Are we subscribed to this list?
			    			$mailingLists[$i]['subscribed'] = (in_array($memberInfo['Member']['email'], $emails));
			    			if($i > 0)
			    			{
			    				$mailingLists[$i]['subscribed'] = rand() % 2 == 0;
			    			}
			    			// Can we view it?
			    			$mailingLists[$i]['canView'] = $this->AuthUtil->is_authorized('mailinglists', 'view', array( $mailingLists[$i]['id'] ));
			    		}
			    	}
			    }
		    	return $mailingLists;
		    }
		    return null;
		}

		private function sanitise_edit_data($data)
		{
			$user = AuthComponent::user();
		    $canSeeAll = Member::isInGroupMemberAdmin($user) || Member::isInGroupFullAccess($user);
		    if(!$canSeeAll)
		    {
		    	unset($data['Pin']);
		    	unset($data['Group']);
		    	unset($data['Member']['account_id']);
		    	unset($data['Member']['status_id']);
		    }

		    $isEditingSelf = $data['Member']['member_id'] == $user['Member']['member_id'];
		    if(!$isEditingSelf)
		    {
		    	unset($data['MailingLists']);
		    }

			return $data;
		}

		public function set_member_status($id, $newStatus)
		{
			$oldData = $this->Member->read(null, $id);
			$data = $oldData;
			$newData = $this->Member->set('member_status', $newStatus);

			$data['Member']['member_status'] = $newStatus;
			# Need to unset the group here, as it's not properly filled in
			# So it adds partial data to the database
			unset($data['Group']);

			if($this->Member->save($data))
			{
				$this->Session->setFlash('Member status updated.');

				$this->set_member_status_impl($oldData, $newData);
				$this->update_status_on_joint_accounts($data, $newData);
			}
			else
			{
				$this->Session->setFlash('Unable to update member status');
			}

			$this->redirect($this->referer());
		}

		private function set_member_status_impl($oldData, $newData)
		{
			$this->Member->clearGroupsIfMembershipRevoked($oldData['Member']['member_id'], $newData);
			$this->Member->addToCurrentMemberGroupIfStatusIsCurrentMember($oldData['Member']['member_id'], $newData);
			$this->notify_status_update($oldData, $newData);
		}

		private function update_status_on_joint_accounts($oldData, $newData)
		{
			# Find any members using the same account as this one, and set their status too
			foreach ($this->Member->find( 'all', array( 'conditions' => array( 'Member.account_id' => $oldData['Member']['account_id'] ) ) ) as $memberInfo) 
			{
				if($memberInfo['Member']['member_id'] != $oldData['Member']['member_id'])
				{
					$oldMemberInfo = $memberInfo;
					$memberInfo['Member']['member_status'] = $newData['Member']['member_status'];
					$this->data = $memberInfo;
					$newMemberInfo = $this->Member->save($memberInfo);
					if($newMemberInfo)
					{
						$this->set_member_status_impl($oldMemberInfo, $newMemberInfo);
					}
				}
			}
		}

		private function notify_status_update($oldData, $newData)
		{
			if( isset($oldData['Member']['member_status']) && isset($newData['Member']['member_status']) )
			{
				if($oldData['Member']['member_status'] != $newData['Member']['member_status'])
				{
					# Notify all the member admins about the status change
					$email = $this->prepare_email_for_members_in_group(5);
					$email->subject('Member Status Change Notification');
					$email->template('notify_admins_member_status_change', 'default');
					
					$newStatusData = $this->Member->Status->find( 'all', array( 'conditions' => array( 'Status.status_id' => $newData['Member']['member_status'] ) ) );

					$email->viewVars( array( 
						'member' => $oldData['Member'],
						'oldStatus' => $oldData['Status']['title'],
						'newStatus' => $newStatusData[0]['Status']['title'],
						'memberAdmin' => AuthComponent::user('Member.name'),
						 )
					);

					$email->send();

					# Is this member being approved for the first time? If so we need to send out a message to the member admins
					# To tell them to e-mail the PIN etc to the new member
					$oldStatus = $oldData['Member']['member_status'];
					$newStatus = $newData['Member']['member_status'];
					if(	$newStatus == 2 &&
						$oldStatus == 1)
					{
						$approvedEmail = $this->prepare_email_for_members_in_group(5);
						$approvedEmail->subject('Member Approved!');
						$approvedEmail->template('notify_admins_member_approved', 'default');

						$approvedEmail->viewVars( array( 
							'member' => $oldData['Member'],
							'pin' => $oldData['Pin']['pin']
							)
						);

						$approvedEmail->send();
					}
				}
			}
		}

		public function email_members_with_status($status) {

			Controller::loadModel('MemberEmail');

			$members = $this->Member->find('all', array('conditions' => array( 'Member.member_status' => $status )));
			$memberEmails = Hash::extract( $members, '{n}.Member.email' );

			$statusName = "Unknown";
			$statusId = $status;
			$statusList = $this->Member->Status->find( 'all', array( 'conditions' => array( 'status_id' => $status ) ) );
			if(count($statusList) > 0)
			{
				$statusName = $statusList[0]['Status']['title'];
			}

			$this->set('members', $members);
			$this->set('statusName', $statusName);
			$this->set('statusId', $status);

			if ($this->request->is('get')) {
			} else {
				$this->MemberEmail->set($this->data);
				if($this->MemberEmail->validates())
				{
					$subject = $this->request->data['MemberEmail']['subject'];
					$message = $this->request->data['MemberEmail']['message'];
					if( isset($subject) &&
						$subject != null &&
						strlen(trim($subject)) > 0 &&

						isset($message) &&
						$message != null &&
						strlen(trim($message)) > 0 )
					{
						# Send the message out
						$email = $this->prepare_email();
						$email->to($memberEmails);
						$email->subject($subject);
						$email->template('default', 'default');
						$email->send($message);

						$this->Session->setFlash('E-mail sent');
						$this->redirect(array('action' => 'index'));
					}
					else
					{
						$this->Session->setFlash('Unable to send e-mail');
					}
				}
			}
		}

		private function get_emails_for_members_in_group($groupId)
		{
			# First grab all the members in the group
			$members = $this->Member->Group->find('all', array( 'conditions' => array( 'Group.grp_id' => $groupId ) ) );

			#Then spilt out the e-mails
			#return Hash::extract( $members, '{n}.Member.{n}.email' );
			return array( 'pyroka@gmail.com' );
		}


		private function prepare_email_for_members_in_group($groupId)
		{
			$email = $this->prepare_email();
			$email->to( $this->get_emails_for_members_in_group( $groupId ) );

			return $email;
		}

		private function prepare_email()
		{
			App::uses('CakeEmail', 'Network/Email');

			$email = new CakeEmail();
			$email->config('smtp');
			$email->from(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->sender(array('membership@nottinghack.org.uk' => 'Nottinghack Membership'));
			$email->emailFormat('html');

			return $email;
		}

		public function login() {
		    if ($this->request->is('post')) {
		        if ($this->Auth->login()) {
		        	$memberInfo = AuthComponent::user();
		        	# Set the last login time
		        	unset($memberInfo['MemberAuth']);
		        	$memberInfo['MemberAuth']['member_id'] = $memberInfo['Member']['member_id'];
		        	$memberInfo['MemberAuth']['last_login'] = date( 'Y-m-d H:m:s' );
		        	$this->Member->MemberAuth->save($memberInfo);
		            $this->redirect($this->Auth->redirect());
		        } else {
		            $this->Session->setFlash(__('Invalid username or password, try again'));
		        }
		    }
		}

		public function logout() {
		    $this->redirect($this->Auth->logout());
		}

		private function get_readable_account_list($initialElement = null) {
			# Grab a list of member names and ID's and account id's
			$memberList = $this->Member->find('all', array( 'fields' => array( 'member_id', 'name', 'account_id' )));

			# Create a list with account_id and member_names
			foreach ($memberList as $memberInfo) {
				if( isset($accountList[$memberInfo['Member']['account_id']]) == false )
				{
					$accountList[$memberInfo['Member']['account_id']] = array( );
				}
				array_push($accountList[$memberInfo['Member']['account_id']], $memberInfo['Member']['name']);
				natcasesort($accountList[$memberInfo['Member']['account_id']]);
			}

			$accountNameList = $this->Member->Account->find('list', array( 'fields' => array( 'account_id', 'payment_ref' )));

			foreach ($accountList as $accountId => $members) {
				$formattedMemberList = $members[0];
				$numMembers = count($members);
				for($i = 1; $i < $numMembers; $i++)
				{
					$prefix = ', ';
					if($i = $numMembers - 1)
					{
						$prefix = ' & ';
					}
					$formattedMemberList .= $prefix . $members[$i];
				}

				$readableAccountList[$accountId] = $formattedMemberList;
				# Append the payment ref if any
				if(isset($accountNameList[$accountId]))
				{
					$readableAccountList[$accountId] .= ' [ ' . $accountNameList[$accountId] . ' ]';
				}
			}

			# Sort it alphabetically
			natcasesort($readableAccountList);

			# If the initial item is set, we need to make a new list starting with that
			if(	isset($initialElement) &&
				$initialElement != null)
			{
				$tempArray = $initialElement;
				foreach ($readableAccountList as $key => $value) {
					if($key >= 0)
					{
						$tempArray[$key] = $value;
					}
				}

				$readableAccountList = $tempArray;
			}

			return $readableAccountList;
		}

		private function set_account($memberInfo)
		{
			if( isset($memberInfo['Member']['account_id']) )
			{
				# Do we need to create a new account?
	            if($memberInfo['Member']['account_id'] < 0)
	            {
	            	# Check if there's already an account for this member
	            	# This could happen if they started off on their own account, moved to a joint one and then they wanted to move back

	            	$existingAccountInfo = $this->Member->Account->find('first', array( 'conditions' => array( 'Account.member_id' => $memberInfo['Member']['member_id'] ) ));
	            	if(	isset($existingAccountInfo) &&
	            		count($existingAccountInfo) > 0)
	            	{
	            		# Already an account, just use that
	            		$memberInfo['Member']['account_id'] = $existingAccountInfo['Account']['account_id'];
	            		$memberInfo['Account'] = $existingAccountInfo['Account'];
	            		$this->Member->Account->save($memberInfo);
	            	}
	            	else
	            	{
	            		# Need to create one
	            		$memberInfo['Account']['member_id'] = $memberInfo['Member']['member_id'];
	            		$memberInfo['Account']['payment_ref'] = $this->Member->Account->generate_payment_ref($memberInfo);
	            		
	            		$accountInfo = $this->Member->Account->save($memberInfo);

	            		$memberInfo['Member']['account_id'] = $accountInfo['Account']['account_id'];
	            	}
	            }

	           	$this->Member->save($memberInfo);
	        }
            return $memberInfo;
		}
	}
?>