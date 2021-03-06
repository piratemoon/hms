<?php

	// It is now impossible for a current member to not be in the 'current members' group but previously this was no the case
	// so make it the case now

	
	// First we should get all the members
	$conn = $this->_getDbConnection('default', true);
	$allMembers = $this->_runQuery($conn, "SELECT * FROM `members` ORDER BY `members`.`member_status` ASC");

	$this->_logMessage('Fixing groups for ' . count($allMembers) . ' members');

	$okCount = 0;
	$addedCount = 0;
	$removedCount = 0;

	$massQuery = '';

	foreach ($allMembers as $member) 
	{
		$groups = $this->_runQuery($conn, "SELECT * FROM `member_group` WHERE `member_group`.`member_id` = {$member['member_id']}");

		switch($member['member_status'])
		{
			case Status::CURRENT_MEMBER:

				$isInCurrentMembers = false;
				if(is_array($groups))
				{
					foreach ($groups as $group) 
					{
						if($group['grp_id'] == Group::CURRENT_MEMBERS)
						{
							$isInCurrentMembers = true;
						}
					}
				}

				if(!$isInCurrentMembers)
				{
					$insertQuery = sprintf("INSERT INTO `member_group` (`member_id`, `grp_id`) VALUES ('%s', '%s')", $member['member_id'], Group::CURRENT_MEMBERS);
					$massQuery .= $insertQuery . ';';
					$addedCount++;
				}
				else
				{
					$okCount++;
				}
				break;

			default:
				// These members shouldn't be in any groups
				if(is_array($groups) && count($groups) > 0)
				{
					//$this->_runQuery($conn, );
					$deleteQuery = "DELETE FROM `member_group` WHERE `member_group`.`member_id` = {$member['member_id']}";
					$massQuery .= $deleteQuery . ';';
					$removedCount++;
				}
				else
				{
					$okCount++;
				}
				break;
		}
	}

	$this->_runQuery($conn, $massQuery);

	$this->_logMessage("$okCount unchanged");
	$this->_logMessage("$addedCount added to current members");
	$this->_logMessage("$removedCount removed from various groups");
?>