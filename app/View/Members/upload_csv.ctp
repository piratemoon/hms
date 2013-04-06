<!-- File: /app/View/Member/upload_csv.ctp -->

<?php
	$this->Html->addCrumb('Members', '/members');
	$this->Html->addCrumb('Upload CSV', '/members/uploadCsb');
?>

<?php if($memberList == null): ?>
	<?php
		echo $this->Form->create('FileUpload', array('type' => 'file'));
		echo $this->Form->input('filename', array('type' => 'file'));
		echo $this->Form->end('Upload');
	?>
<?php else: ?>

	<table>
        <tr>
	        <th>Name</th>
	        <th>Payment Ref</th>
	    </tr>

	    <?php foreach ($memberList as $member): ?>

	    	<tr>
	    		<td> <?php echo $this->Html->link($member['name'], array('controller' => 'members', 'action' => 'view', $member['id'])); ?> </td>
	    		<td> <?php echo $member['paymentRef']; ?> </td>
	    	</tr>

	    <?php endforeach; ?>

	</table>

<?php endif; ?>