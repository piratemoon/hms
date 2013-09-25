<?php

	App::uses('ConsumableRequestController', 'Controller');

	App::build(array('TestController' => array('%s' . 'Test' . DS . 'Lib' . DS)), App::REGISTER);
	App::uses('HmsControllerTestBase', 'TestController');

	class ConsumableRequestControllerTest extends HmsControllerTestBase
	{
		public $fixtures = array( 
            'app.ConsumableRequest',
            'app.ConsumableRequestStatus',
            'app.ConsumableSupplier',
            'app.ConsumableArea',
            'app.ConsumableRepeatPurchase',
            'app.ConsumableRequestComment',
            'app.ConsumableRequestStatusUpdate',
            'app.Member',
            'app.Group',
        );

		public function setUp() 
        {
        	parent::setUp();

        	$this->ConsumableRequestController = new ConsumableRequestController();
        	$this->ConsumableRequestController->constructClasses();
        }

		public function test_Index_SetsRequestCountsInView()
		{
			$this->testAction('/consumableRequest/index');

			$expectedResult = array(
                array(
                    'id' => 1,
                    'name' => 'Pending',
                    'count' => 0,
                ),
                array(
                    'id' => 2,
                    'name' => 'Approved',
                    'count' => 0,
                ),
                array(
                    'id' => 3,
                    'name' => 'Rejected',
                    'count' => 1,
                ),
                array(
                    'id' => 4,
                    'name' => 'Fulfilled',
                    'count' => 1,
                ),
            );

			$this->assertEquals( $expectedResult, $this->vars['requests'] );
		}

		public function test_View_WithNonNumericId_Redirects()
		{
			$this->testAction('/consumableRequest/view/a');

			$this->assertArrayHasKey('Location', $this->headers);
		}

		public function test_View_WithNegativeId_Redirects()
		{
			$this->testAction('/consumableRequest/view/-1');
			
			$this->assertArrayHasKey('Location', $this->headers);
		}

		public function test_View_WithZeroId_Redirects()
		{
			$this->testAction('/consumableRequest/view/0');
			
			$this->assertArrayHasKey('Location', $this->headers);	
		}

		public function test_View_WithNonExistantId_SetsViewVars()
		{
			$this->testAction('/consumableRequest/view/100');
			
			$this->assertEquals( array(), $this->vars['request'] );
		}

		public function test_View_WithValidId_SetsViewVars()
		{
			$this->testAction('/consumableRequest/view/1');

			$expectedResult = array(
                'request_id' => 1,
                'title' => 'a',
                'detail' => 'a',
                'url' => 'a',
                'supplier_id' => null,
                'area_id' => null,
                'repeat_purchase_id' => null,
                'supplier' => array(
                    'supplier_id' => null,
                    'name' => null,
                    'description' => null,
                    'address' => null,
                    'url' => null,
                ),
                'area' => array(
                    'area_id' => null,
                    'name' => null,
                    'description' => null,
                ),
                'repeatPurchase' => array(
                    'repeat_purchase_id' => null,
                    'name' => null,
                    'description' => null,
                    'min' => null,
                    'max' => null,
                    'area_id' => null,
                ),
                'comments' => array(
                    array(
                        'request_comment_id' => 1,
                        'text' => 'a',
                        'member_id' => null,
                        'timestamp' => '2013-08-31 09:00:00',
                        'request_id' => 1,
                        'member_username' => null,
                    ),
                    array(
                        'request_comment_id' => 2,
                        'text' => 'b',
                        'member_id' => 1,
                        'timestamp' => '2013-08-31 10:00:00',
                        'request_id' => 1,
                        'member_username' => 'strippingdemonic',
                    ),
                ),
                'firstStatus' => array(
                    'request_status_update_id' => 1,
                    'request_id' => 1,
                    'request_status_id' => 1,
                    'member_id' => 1,
                    'timestamp' => '2013-08-31 09:00:00',
                    'request_status_name' => 'Pending',
                    'member_username' => 'strippingdemonic',
                ),
                'currentStatus' => array(
                    'request_status_update_id' => 3,
                    'request_id' => 1,
                    'request_status_id' => 3,
                    'member_id' => 2,
                    'timestamp' => '2013-08-31 11:00:00',
                    'request_status_name' => 'Rejected',
                    'member_username' => 'pecanpaella',
                ),
                'statuses' => array(
                    array(
                        'request_status_update_id' => 3,
                        'request_id' => 1,
                        'request_status_id' => 3,
                        'member_id' => 2,
                        'timestamp' => '2013-08-31 11:00:00',
                        'request_status_name' => 'Rejected',
                        'member_username' => 'pecanpaella',
                    ),
                    array(
                        'request_status_update_id' => 2,
                        'request_id' => 1,
                        'request_status_id' => 2,
                        'member_id' => 2,
                        'timestamp' => '2013-08-31 10:00:00',
                        'request_status_name' => 'Approved',
                        'member_username' => 'pecanpaella',
                    ),
                    array(
                        'request_status_update_id' => 1,
                        'request_id' => 1,
                        'request_status_id' => 1,
                        'member_id' => 1,
                        'timestamp' => '2013-08-31 09:00:00',
                        'request_status_name' => 'Pending',
                        'member_username' => 'strippingdemonic',
                    ),
                ),
            );

			$this->assertEquals( $expectedResult, $this->vars['request'] );
		}

        public function test_Add_WithPostDataButNoLoggedInMember_CallsModelAddWithSameData()
        {
            $this->ConsumableRequestController = $this->generate('ConsumableRequest', array(
                'components' => array(
                    'Auth' => array(
                        'user',
                    )
                )
            ));

            // First mock the model
            $modelMock = $this->getMockForModel('ConsumableRequest');

            $postData = array(
                'ConsumableRequest' => array(
                    'title' => 'a',
                    'detail' => 'a',
                    'url' => 'a',
                    'supplier_id' => null,
                    'area_id' => null,
                    'repeat_purchase_id' => null,
                ),
            );
            $modelMock->expects($this->once())
                      ->method('Add')
                      ->with($postData, null);

            $this->ConsumableRequestController->ConsumableRequest = $modelMock;

            // Now we need to mock the check for logged in member
            $this->ConsumableRequestController->Auth->staticExpects($this->any())
                                              ->method('user')
                                              ->will($this->returnValue(null));

            $this->testAction('consumableRequest/add', array('data' => $postData, 'method' => 'post'));
        }

        public function test_Add_WithPostDataAndLoggedInMember_CallsModelAddWithSameData()
        {
            $this->ConsumableRequestController = $this->generate('ConsumableRequest', array(
                'components' => array(
                    'Auth' => array(
                        'user',
                    )
                )
            ));

            // First mock the model
            $modelMock = $this->getMockForModel('ConsumableRequest');

            $postData = array(
                'ConsumableRequest' => array(
                    'title' => 'a',
                    'detail' => 'a',
                    'url' => 'a',
                    'supplier_id' => null,
                    'area_id' => null,
                    'repeat_purchase_id' => null,
                ),
            );
            $modelMock->expects($this->once())
                      ->method('Add')
                      ->with($postData, 1);

            $this->ConsumableRequestController->ConsumableRequest = $modelMock;

            // Now we need to mock the check for logged in member
            $this->ConsumableRequestController->Auth->staticExpects($this->any())
                                              ->method('user')
                                              ->will($this->returnValue(array('Member' => array('member_id' => 1))));

            $this->testAction('consumableRequest/add', array('data' => $postData, 'method' => 'post'));
        }

        public function test_Add_WhenModelAddThrowsException_SetsFlashMessage()
        {
            $this->ConsumableRequestController = $this->generate('ConsumableRequest', array(
                'components' => array(
                    'Session' => array(
                        'setFlash',
                    )
                )
            ));

            $modelMock = $this->getMockForModel('ConsumableRequest');
            $modelMock->expects($this->once())
                      ->method('Add')
                      ->will($this->throwException(new InvalidArgumentException()));

            $this->ConsumableRequestController->ConsumableRequest = $modelMock;

            $this->ConsumableRequestController->Session
                                            ->expects($this->once())
                                            ->method('setFlash')
                                            ->with('Unable to create request');

            $this->testAction('consumableRequest/add', array('method' => 'post'));
        }

        public function test_Add_WhenModelAddReturnsTrue_SetsFlashMessage()
        {
            $this->ConsumableRequestController = $this->generate('ConsumableRequest', array(
                'components' => array(
                    'Session' => array(
                        'setFlash',
                    )
                )
            ));

            $modelMock = $this->getMockForModel('ConsumableRequest');
            $modelMock->expects($this->once())
                      ->method('Add')
                      ->will($this->returnValue(true));
            $this->ConsumableRequestController->ConsumableRequest = $modelMock;

            $this->ConsumableRequestController->Session
                                            ->expects($this->once())
                                            ->method('setFlash')
                                            ->with('Created request');

            $postData = array(
                'ConsumableRequest' => array(
                    'title' => 'a',
                    'detail' => 'a',
                    'url' => 'a',
                    'supplier_id' => null,
                    'area_id' => null,
                    'repeat_purchase_id' => null,
                ),
            );

            $this->testAction('consumableRequest/add', array('data' => $postData, 'method' => 'post'));
        }

        public function test_Add_WhenModelAddReturnsFalse_SetsFlashMessage()
        {
            $this->ConsumableRequestController = $this->generate('ConsumableRequest', array(
                'components' => array(
                    'Session' => array(
                        'setFlash',
                    )
                )
            ));

            $modelMock = $this->getMockForModel('ConsumableRequest');
            $modelMock->expects($this->once())
                      ->method('Add')
                      ->will($this->returnValue(false));
            $this->ConsumableRequestController->ConsumableRequest = $modelMock;

            $this->ConsumableRequestController->Session
                                            ->expects($this->once())
                                            ->method('setFlash')
                                            ->with('Unable to create request');

            $postData = array(
                'ConsumableRequest' => array(
                    'title' => 'a',
                    'detail' => 'a',
                    'url' => 'a',
                    'supplier_id' => null,
                    'area_id' => null,
                    'repeat_purchase_id' => null,
                ),
            );

            $this->testAction('consumableRequest/add', array('data' => $postData, 'method' => 'post'));
        }
	}

?>