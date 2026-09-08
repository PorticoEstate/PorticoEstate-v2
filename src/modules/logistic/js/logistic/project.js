$(function ()
{

	$("#project_details input").on('focus', function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeIn(200);
	});

	$("#project_details textarea").on('focus', function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeIn(200);
	});

	$("#project_details select").on('focus', function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeIn(200);
	});

	$("#project_details textarea").focusout(function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeOut();
	});

	$("#project_details input").focusout(function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeOut();
	});

	$("#project_details select").focusout(function (e)
	{
		var wrpElem = $(this).parents("dd");
		$(wrpElem).find(".help_text").fadeOut();
	});
});