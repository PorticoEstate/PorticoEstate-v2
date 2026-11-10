/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
var intVal = function (i)
{
	return typeof i === 'string' ?
		i.replace(/[\$,]/g, '') * 1 :
		typeof i === 'number' ?
		i : 0;
};

this.local_DrawCallback1 = function (container)
{
	var api = new DataTable("#" + container);
	var order = api.order();
	if (order.length !== 1 || order[0][0] !== 2 || order[0][1] !== 'asc')
	{
		api.order([[2, 'asc']]).draw();
	}
};
