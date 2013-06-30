<?php

	define('CAKE_PATH', '../../../lib/Cake/');
	define('APP_PATH', '../../../app/');
	
	require_once(CAKE_PATH . 'Core/App.php');
	require_once(CAKE_PATH . 'Core/Object.php');
	require_once(CAKE_PATH . 'Event/CakeEventListener.php');
	require_once(CAKE_PATH . 'Model/Model.php');
	require_once(CAKE_PATH . 'Utility/String.php');
	require_once(APP_PATH . 'Model/AppModel.php');
	require_once(APP_PATH . 'Model/Status.php');
	require_once(APP_PATH . 'Model/Account.php');
	require_once(APP_PATH . 'Model/Pin.php');
	require_once(APP_PATH . 'Model/Group.php');
	require_once(APP_PATH . 'Lib/CsvReader/CsvReader.php');

	require_once('utils.php');

	/*
		This script is used to generate realistic(ish) data for use when manually testing HMS.
		It is mainly powered by data generated by http://www.fakenamegenerator.com/
		Data provided by http://www.fakenamegenerator.com/ is used under the
		Creative Commons Attribution-Share Alike 3.0 United States license.
	*/

	class DataGenerator
	{
		private $stockData = array(); //!< The stock data used to populate other fields.

		private $members = array(); //!< Array of members.
		private $membersGroup = array(); //!< Array of which members are in which groups
		private $accounts = array(); //!< Array of accounts.
		private $pins = array(); //!< Array of pins.
		private $rfidTags = array(); //!< Array of rfid tags.
		private $statusUpdates = array(); //!< Array of status updates

		//! Constructor
		function __construct()
		{
			$this->_parseCsv('./FakeNameGeneratorData.csv');
		}

		//! Given a relative path from this file, get an absolute path.
		/*!
			@param string $path The relative path to convert.
			@retval string The absolute path.
		*/
		private function _makeAbsolutePath($path)
		{
			return dirname(__FILE__) . '/' . basename($path);
		}

		//! Parse a CSV file, adding the data to the stockData array.
		/*!
			@param $filepath string Path to the .csv file to try and parse.
		*/
		private function _parseCsv($filepath)
		{
			$csvReader = new CsvReader();

			if($csvReader->readFile(makeAbsolutePath($filepath)))
			{
				$numLines = $csvReader->getNumLines();

				// If the .csv is sane, the first line is the headers
				$headers = $csvReader->getLine(0);

				if($headers != null)
				{
					for($i = 1; $i < $numLines; $i++)
					{
						// For every line, convert the indexed array to an associated array
						// using the headers as keys

						$line = $csvReader->getLine($i);
						if( $line != null &&
							count($line) == count($headers) )
						{
							$assocLine = array();
							for($j = 0; $j < count($line); $j++)
							{
								$assocLine[$headers[$j]] = $line[$j];
							}
							array_push($this->stockData, $assocLine);
						}
					}
				}
			}
		}

		//! Get the SQL version of a value.
		/*!
			@param mixed $value The value to transform.
			@retval string SQL version of the value.
		*/
		private function _sqlize($value)
		{
			if(is_string($value))
			{
				return "'" . mysql_real_escape_string($value) . "'";
			}

			if(is_numeric($value))
			{
				return (string)$value;
			}

			if($value == null)
			{
				return 'NULL';
			}
		}

		//! Get the MYSql of an array
		/*!
			@param array $array The array to use.
			@param string $title The name of the table
			@retval string MYSql string of array data.
		*/
		private function _getSql($array, $title)
		{
			$headers = array_keys($array[0]);
			$formattedHeaders = array_map( function ($val) { return "`$val`"; }, $headers );

			$sql = "INSERT INTO `$title` (";
			$sql .= implode(', ', $formattedHeaders);
			$sql .= ") VALUES" . PHP_EOL;

			for($i = 0; $i < count($array); $i++)
			{
				$values = $array[$i];

				$formattedValues = array_map( function ($val) { return $this->_sqlize($val); }, $values );
				$sql .= "(" . implode(', ', $formattedValues) . ")";

				if($i < count($array) - 1)
				{
					$sql .= ',';
				}
				else
				{
					$sql .= ';';
				}
				$sql .= PHP_EOL;
			}

			return $sql;
		}

		//! Get an SQL string of the members data.
		/*!
			@retval string SQL string for the members data.
		*/
		public function getMembersSql()
		{
			return $this->_getSql($this->members, 'members');
		}

		//! Get an SQL string of the membersGroup data.
		/*!
			@retval string SQL string for the membersGroup data.
		*/
		public function getMembersGroupSql()
		{
			return $this->_getSql($this->membersGroup, 'member_group');
		}

		//! Get an SQL string of the accounts data.
		/*!
			@retval string SQL string for the accounts data.
		*/
		public function getAccountsSql()
		{
			return $this->_getSql($this->accounts, 'accounts');
		}

		//! Get an SQL string of the pins data.
		/*!
			@retval string SQL string for the pins data.
		*/
		public function getPinsSql()
		{
			return $this->_getSql($this->pins, 'pins');
		}

		//! Get an SQL string of the RFID tags data.
		/*!
			@retval string SQL string for the RFID tags data.
		*/
		public function getRfidTagsSql()
		{
			return $this->_getSql($this->rfidTags, 'rfid_tags');
		}

		//! Get an SQL string of the status updates data.
		/*!
			@retval string SQL string for the status updates data.
		*/
		public function getStatusUpdatesSql()
		{
			return $this->_getSql($this->statusUpdates, 'status_updates');
		}

		//! Generate a new member record
		/*!
			@param int $membershipStage The stage of membership this member should be at, see Status model for details.
			@param array $details Optional array of details that will be forced on the member being generated.
		*/
		public function generateMember($membershipStage, $details = array())
		{
			$memberId = count($this->members) + 1;

			$creditLimit = 0;
			$balance = 0;
			$joinDate = '';
			$accountId = null;

			// Make it so they registered some time in the last year
			$now = time();
			$lastYear = strtotime('last year');
			$registerTimestamp = rand($lastYear, $now);

			if((int)$membershipStage >= Status::CURRENT_MEMBER)
			{
				$creditLimit = 5000;
				$balance = rand(-$creditLimit, 0);

				
				$joinDate = date('Y-m-d', $registerTimestamp);

				$accountId = $this->_generateAccount();
				$this->_generatePin($memberId, $registerTimestamp);

				// Has this member set up access yet?
				// Pick a date within a week of the join date
				// and if that date has passed then member has set up a card
				$weekAfterJoin = strtotime('+1 week', $registerTimestamp);
				$registerTime = rand($registerTimestamp, $weekAfterJoin);
				if($registerTime <= $now)
				{
					$this->_registerCard($memberId, $registerTime);
				}

				// Add the member to some random groups if they're a current member
				if($membershipStage == Status::CURRENT_MEMBER)
				{
					// Will always be in current members group
					$groupList = array( Group::CURRENT_MEMBERS );

					$possibleGroups = array(
						Group::FULL_ACCESS,
						Group::GATEKEEPER_ADMIN,
						Group::SNACKSPACE_ADMIN,
						Group::MEMBER_ADMIN,
					);

					$numGroupsToAdd = rand(0, 2);
					for($i = 0; $i < $numGroupsToAdd; $i++)
					{
						$index = array_rand($possibleGroups);
						$groupId = $possibleGroups[$index];
						array_splice($possibleGroups, $index, 1);

						array_push($groupList, $groupId);
					}

					// Groups can be overriden by the details array
					if( isset($details['groups']) )
					{
						$groupList = $details['groups'];
					}

					$this->_setMemberGroups($memberId, $groupList);
				}
			}

			// Need to generate status updates for all levels of membership
			// Spread the updates over some time
			$firstStatusUpdateTime = strtotime('-2 weeks', $registerTimestamp);
			$currentStatusUpdateTime = $firstStatusUpdateTime;
			for($i = 0; $i < $membershipStage; $i++)
			{
				// The 'admin' making the change is the member
				// until the later membership stages
				$adminId = $memberId;
				if(	$i >= Status::PRE_MEMBER_2 && 
					count($this->members) > 0)
				{
					$adminDataIdx = array_rand($this->members);
					$adminId = $this->members[$adminDataIdx]['member_id'];
				}

				$this->_generateStatusUpdate($memberId, $adminId, $i, $i + 1, $currentStatusUpdateTime);

				// Advance the status update time
				// may produce weirdness if it picks a time close to registerTimestamp with a few
				// status updates left to go but it shouldn't matter
				$currentStatusUpdateTime = rand($currentStatusUpdateTime, $registerTimestamp);
			}

			$stockData = $this->_getStockData();

			$firstname = $this->_useValOrDefault($details, 'firstname', $stockData['GivenName']);
			$surname = $this->_useValOrDefault($details, 'surname', $stockData['Surname']);
			$email = $this->_useValOrDefault($details, 'email', $stockData['EmailAddress']);
			$handle = $this->_useValOrDefault($details, 'username', $stockData['Username']);

			$username = $handle;

			$address = array(
				$stockData['StreetAddress'],
				'',
				$stockData['City'],
				$stockData['ZipCode']
			);

			$contactNumber = $stockData['TelephoneNumber'];

			$record = array(
				'member_id' => $memberId,
				'firstname' => $firstname,
				'surname' => $surname,
				'email' => $email,
				'join_date' => $joinDate,
				'handle' => $handle,
				'unlock_text' => 'Welcome ' . $firstname,
				'balance' => $balance,
				'credit_limit' => $creditLimit,
				'member_status' => $membershipStage,
				'username' => $username,
				'account_id' => $accountId,
				'address_1' => $address[0],
				'address_2' => $address[1],
				'address_city' => $address[2],
				'address_postcode' => $address[3],
				'contact_number' => $contactNumber
			);

			array_push($this->members, $record);
		}

		//! Return $array[$key] if it is set, otherwise return $default.
		/*!
			@param array $array The array to address.
			@param mixed $key The index to use.
			@param mixed $default The value to use if $val us not set.
			@retval mixed $array[$key]  if it is set, otherwise return $default.
		*/
		private function _useValOrDefault($array, $key, $default)
		{
			if(array_key_exists($key, $array))
			{
				return $array[$key];
			}

			return $default;
		}

		//! Set the groups which a member will belong to.
		/*!
			@param int $memberId The id of the member who's groups we're setting.
			@param array $groupList List of group id's.
		*/
		private function _setMemberGroups($memberId, $groupList)
		{
			// Firstly remove all the current groups assigned to the member
			for($i = 0; $i < count($this->membersGroup); )
			{
				if($this->membersGroup[$i]['member_id'] == $memberId)
				{
					array_splice($this->membersGroup, $i, 1);
					// Don't increment since we've altered the array
					continue;
				}
				$i++;
			}

			// Now add a record for each of the items in $groupList
			foreach ($groupList as $groupId) 
			{
				$record = array(
					'member_id' => $memberId,
					'grp_id' => $groupId,
				);

				array_push($this->membersGroup, $record);
			}
		}

		//! Generate a status update record
		/*!
			@param int $memberId The id of the member.
			@param int $adminId The id of the admin making the change.
			@param int $oldStatus The previous status of the member.
			@param int $newStatus The new status of the member.
			@param int $timestamp The timestamp of the update.
		*/
		private function _generateStatusUpdate($memberId, $adminId, $oldStatus, $newStatus, $timestamp)
		{
			$recordId = count($this->statusUpdates) + 1;

			$record = array(
				'id' => $recordId,
				'member_id' => $memberId,
				'admin_id' => $adminId,
				'old_status' => $oldStatus,
				'new_status' => $newStatus,
				'timestamp' => $timestamp,
			);

			array_push($this->statusUpdates, $record);
		}

		//! Register an rfid card using the members pin.
		/*!
			@param int $memberId The id of the member to register the card to.
			@param int $registerTime The time the card was registered.
		*/
		private function _registerCard($memberId, $registerTime)
		{
			// Registering a card effects the PIN
			for($i = 0; $i < count($this->pins); $i++)
			{
				if($this->pins[$i]['member_id'] == $memberId)
				{
					$this->pins[$i]['state'] = 40;
					break;
				}
			}

			// The RFID serial seems to be between 9 and 10 numbers starting with 1
			$serialLenRemaining = 9;
			$serial = '1';
			if(rand() % 2 == 0)
			{
				$serialLenRemaining = 8;
			}
			for($i = 0; $i < $serialLenRemaining; $i++)
			{
				$serial .= rand(0, 9);
			}

			// Make it so the card was used within the last month
			// (all members turn up at-least once a month right? ;) )
			$aMonthAgo = time('-1 month');

			$lastUsedMin = $aMonthAgo;
			// Can't have used it before it was registered...
			if($aMonthAgo < $registerTime)
			{
				$lastUsedMin = $registerTime;
			}

			//  Pick a time between then and now
			$lastUsed = rand($lastUsedMin, time());


			// Now add an rfid record
			$record = array(
				'member_id' => $memberId,
				'rfid_serial' => $serial,
				'state' => 10,
				'last_used' => $lastUsed,
			);

			array_push($this->rfidTags, $record);
		}

		//! Generate a new account record.
		/*!
			@retval int The id of the record.
		*/
		private function _generateAccount()
		{
			$accountId = count($this->accounts) + 1;

			$accountRef = $this->_generateUniqueEntry($this->accounts, 'payment_ref', function() { return Account::generatePaymentRef(); } );

			$record = array(
				'account_id' => $accountId,
				'payment_ref' => $accountRef,
			);

			array_push($this->accounts, $record);

			return $accountId;
		}

		//! Generate a new pin record
		/*!
			@param int $memberId The id of the member the pin belongs to.
			@param int $joinTimestamp The time the pin was generated.
		*/
		private function _generatePin($memberId, $joinTimestamp)
		{
			$pinId = count($this->pins) + 1;

			$pin = $this->_generateUniqueEntry($this->pins, 'pin', function () { return Pin::generatePin(); } );

			$record = array(
				'pin_id' => $pinId,
				'pin' => $pin,
				'unlock_text' => 'NOT USED',
				'date_added' => date("Y-m-d H:i:s", $joinTimestamp),
				'expiry' => null,
				'state' => 30,
				'member_id' => $memberId
			);

			array_push($this->pins, $record);
		}

		//! Given an array of array data, a key to the inner array, and a function to generate data, keep generating until the data is unique.
		/*!
			@param array $records Array of existing records.
			@param string $key Index in existing records to check.
			@param function $genFunc The function used to generate new data.
			@retval mixed A unique value returned from $genFunc.
		*/
		private function _generateUniqueEntry($records, $key, $genFunc)
		{
			$item = '';
			$isUnique = false;
			do
			{
				$item = $genFunc();
				$isUnique = true;

				foreach ($records as $record) 
				{
					if($record[$key] == $item)
					{
						$isUnique = false;
						break;
					}
				}

			} while( !$isUnique );

			return $item;
		}

		//! Pop a single element of the stock data and return it.
		/*!
			@retval array The data requested.
		*/
		private function _getStockData()
		{
			$index = rand(0, count($this->stockData) - 1);
			$data = $this->stockData[$index];
			array_splice($this->stockData, $index, 1);
			return $data;
		}
	}
?>