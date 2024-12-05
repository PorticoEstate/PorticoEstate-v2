
$(document).ready(function ()
{

	$("#status_id").change(function ()
	{
		check_group_assignment();
	});

});

function check_group_assignment()
{
	var status_id = $("#status_id").val();

	if(status_id == 'C4')
	{
		// set the selected option of the group_id dropdown to the one that has the value of '1067'
		// also, disable the dropdown so the user can't change it
		// and show the selected option in the select box

		$("#group_id").val('1067').attr('readonly', 'readonly').trigger('change');
		//also reset the user_id dropdown, which is a select2.js dropdown to the first option
		$("#user_id").val('').trigger('change');
	}
	else
	{
		// enable the group_id dropdown
		$("#group_id").removeAttr('readonly');
	}
	

}

