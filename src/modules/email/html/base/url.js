window.emailLink = function (url, changes = {})
{
	const destination = new URL(url, window.location.href);
	const base = new URL(strBaseURL, window.location.href);
	if (destination.origin !== base.origin) return destination.href;
	const parameters = destination.searchParams;
	Object.entries(changes).forEach(([key, value]) =>
	{
		parameters.delete(key);
		if (value !== null && value !== undefined) parameters.set(key, String(value));
	});
	const history = parameters.get('click_history') ?? base.searchParams.get('click_history');
	parameters.delete('click_history');
	const argumentsByName = {};
	parameters.forEach((value, key) =>
	{
		if (base.searchParams.has(key) && base.searchParams.get(key) === value) return;
		argumentsByName[encodeURIComponent(key)] = encodeURIComponent(value);
	});
	const prefix = base.pathname.replace(/\/+$/, '');
	const path = prefix && destination.pathname.startsWith(prefix + '/')
		? destination.pathname.slice(prefix.length + 1)
		: destination.pathname.replace(/^\//, '');
	const result = new URL(phpGWLink(path, argumentsByName), window.location.href);
	result.searchParams.delete('click_history');
	if (history !== null) result.searchParams.append('click_history', history);
	result.hash = destination.hash;
	return result.href;
};