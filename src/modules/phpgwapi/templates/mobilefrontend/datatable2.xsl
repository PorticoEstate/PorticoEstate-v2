<func:function name="phpgw:conditional">
	<xsl:param name="test"/>
	<xsl:param name="true"/>
	<xsl:param name="false"/>

	<func:result>
		<xsl:choose>
			<xsl:when test="$test">
				<xsl:value-of select="$true"/>
			</xsl:when>
			<xsl:otherwise>
				<xsl:value-of select="$false"/>
			</xsl:otherwise>
		</xsl:choose>
	</func:result>
</func:function>

<xsl:template match="data">
<!--	<xsl:choose>
		<xsl:when test="datatable_name">
			<h3>
				<xsl:value-of select="datatable_name"/>
			</h3>
		</xsl:when>
	</xsl:choose>-->
	<xsl:call-template name="datatable" />
</xsl:template>


<xsl:template name="datatable">
	<script>
		var show_filter_group = false;
		<xsl:if test="form/toolbar/show_filter_group = '1'">
			show_filter_group = true;
		</xsl:if>

		var number_of_toolbar_items = 0;
		var filter_selects = {};
		var lang = <xsl:value-of select="php:function('js_lang', 'Search')"/>;
	</script>
	<xsl:call-template name="jquery_phpgw_i18n"/>
	<xsl:apply-templates select="form" />
	<div id="list_flash">
		<xsl:call-template name="msgbox"/>
	</div>
	<div id="message" class='message'/>
	<xsl:apply-templates select="datatable"/>
	<xsl:apply-templates select="form/list_actions"/>
</xsl:template>


<xsl:template match="toolbar" xmlns:php="http://php.net/xsl">
	<div class="row ms-1">
		<div id="active_filters">
		</div>
	</div>
	<div class="mt-2 mb-2 ms-1">
		<button class="btn btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#democollapseBtn" aria-expanded="false" aria-controls="democollapseBtn">
			<xsl:value-of select="php:function('lang', 'filter')"/>
		</button>
		<button id="reset_filter" class="ms-2 btn btn-secondary" type="button" onclick="reset_filter();" style="display: none;">
			<xsl:value-of select="php:function('lang', 'reset filter')"/>
		</button>
	</div>

	<div class="row mt-2 collapse" id="democollapseBtn">
		<xsl:if test="item">
			<xsl:variable name="count_items" select="count(item)"/>
			<div id="toolbar" class='dtable_custom_controls'>
				<form>
					<fieldset>
						<div class="row mb-2">
							<xsl:for-each select="item">
								<script>
									number_of_toolbar_items += 1;
								</script>
								<div>
									<xsl:attribute name="class">
										<xsl:choose>
											<xsl:when test="//browser_support = 'legacy'">
												<xsl:text>pure-u-1 pure-u-md-1-3</xsl:text>
											</xsl:when>
											<xsl:otherwise>
												<xsl:choose>
													<xsl:when test="$count_items > 4 or $count_items = 3">
														<xsl:text>col-4</xsl:text>
													</xsl:when>
													<xsl:otherwise>
														<xsl:text>col-6</xsl:text>
													</xsl:otherwise>
												</xsl:choose>
											</xsl:otherwise>
										</xsl:choose>
									</xsl:attribute>

									<xsl:variable name="filter_key" select="concat('filter_', name)"/>
									<xsl:variable name="filter_key_name" select="concat(concat('filter_', name), '_name')"/>
									<xsl:variable name="filter_key_id" select="concat(concat('filter_', name), '_id')"/>
									<xsl:if test="name">
										<label class="form-label">
											<xsl:attribute name="for">
												<xsl:value-of select="phpgw:conditional(not(name), '', name)"/>
											</xsl:attribute>
											<xsl:value-of select="phpgw:conditional(not(text), '', text)"/>
										</label>
									</xsl:if>
									<xsl:choose>
										<xsl:when test="type = 'date-picker'">
											<input class="form-control" id="filter_{name}" name="filter_{name}" value="{value}" type="text">
												<xsl:attribute name="title">
													<xsl:value-of select="phpgw:conditional(not(text), '', text)"/>
												</xsl:attribute>
											</input>
										</xsl:when>
										<xsl:when test="type = 'autocomplete'">
											<input id="filter_{name}_name" name="{name}_name" type="text" class="form-control">
												<xsl:attribute name="value">
													<xsl:value-of select="../../../filters/*[local-name() = $filter_key_name]"/>
												</xsl:attribute>
												<xsl:attribute name="title">
													<xsl:value-of select="phpgw:conditional(not(text), '', text)"/>
												</xsl:attribute>
											</input>
											<input id="filter_{name}_id" name="filter_{name}_id" type="hidden">
												<xsl:attribute name="value">
													<xsl:value-of select="../../../filters/*[local-name() = $filter_key_id]"/>
												</xsl:attribute>
											</input>
											<div id="filter_{name}_container"/>
											<!--/div-->
											<script>
												$(document).ready(function() {
												var app = "<xsl:value-of select="app"/>";
												app = app || 'booking';
												var FunctionName = "<xsl:value-of select="function"/>";
												FunctionName = FunctionName || 'index';
												var label_attr = "<xsl:value-of select="label_attr"/>";
												label_attr = label_attr || 'name';
												var show_id =  false;
												<xsl:if test="show_id = 1">
													show_id = true;
												</xsl:if>

												var name = "<xsl:value-of select="name"/>";
												var ui = "<xsl:value-of select="ui"/>";
												var requestGenerator = false;
												<xsl:if test="requestGenerator">
													requestGenerator = '<xsl:value-of select="requestGenerator"/>';
												</xsl:if>
												var depends = false;
												var filter_depends = "";
												var filter_selected = "";
												<xsl:if test="depends">
													depends = "<xsl:value-of select="depends"/>";
													//filter_depends = $('#filer_'+depends+'_id').val();
													$("#filter_"+depends+"_name").on("autocompleteselect", function(event, i){
													var filter_select = i.item.value;
													filter_depends = i.item.value;
													if (filter_select != filter_selected){
													if (filter_depends) {
														<![CDATA[
																JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction:app + '.ui'+ui+'.' + FunctionName}) + '&filter_'+depends+'_id='+filter_depends+'&',
																										'filter_'+name+'_name', 'filter_'+name+'_id', 'filter_'+name+'_container', label_attr, show_id, requestGenerator);
														]]>
													}
													oTable.dataTableSettings[0]['ajax']['data']['filter_'+name+'_id'] = "";
													$('#filter_'+name+'_name').val('');
													$('#filter_'+name+'_id').val('');
													filter_selected = filter_select;
													}
													});
													$("#filter_"+depends+"_name").on("keyup", function(){
													if ($(this).val() == ''){
													filter_depends = false;
													if (!filter_depends) {
																<![CDATA[
																	JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction:app + '.ui'+ui+'.' + FunctionName}) +'&',
																										'filter_'+name+'_name', 'filter_'+name+'_id', 'filter_'+name+'_container', label_attr, show_id, requestGenerator);
																]]>
													}
													filter_selected = "";
													oTable.dataTableSettings[0]['ajax']['data']['filter_'+name+'_id'] = "";
													$('#filter_'+name+'_name').val('');
													$('#filter_'+name+'_id').val('');
													}
													});
												</xsl:if>
												if (filter_depends) {
														<![CDATA[
															JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction:app + '.ui'+ui+'.' + FunctionName}) + '&filter_'+depends+'_id='+filter_depends+'&',
																								'filter_'+name+'_name', 'filter_'+name+'_id', 'filter_'+name+'_container', label_attr, show_id, requestGenerator);
														]]>
												}else{
														<![CDATA[
															JqueryPortico.autocompleteHelper(phpGWLink('index.php', {menuaction:app + '.ui'+ui+'.' + FunctionName}) + '&',
																								'filter_'+name+'_name', 'filter_'+name+'_id', 'filter_'+name+'_container', label_attr, show_id, requestGenerator);
														]]>
												}
												});
											</script>
										</xsl:when>
										<xsl:when test="type = 'filter'">
											<xsl:variable name="name">
												<xsl:value-of select="name"/>
											</xsl:variable>
											<script>
												filter_selects['<xsl:value-of select="text"/>'] = '<xsl:value-of select="$name"/>';
											</script>
											<select id="{$name}" name="{$name}" class="form-select">
												<xsl:if test="multiple">
													<xsl:attribute name="multiple">
														<xsl:text>true</xsl:text>
													</xsl:attribute>
												</xsl:if>
												<xsl:attribute name="title">
													<xsl:value-of select="phpgw:conditional(not(text), '', text)"/>
												</xsl:attribute>
												<xsl:for-each select="list">
													<xsl:variable name="id">
														<xsl:value-of select="id"/>
													</xsl:variable>
													<xsl:choose>
														<xsl:when test="selected = 'selected'">
															<option value="{$id}" selected="selected">
																<xsl:value-of select="name"/>
															</option>
														</xsl:when>
														<xsl:when test="selected = '1'">
															<option value="{$id}" selected="selected">
																<xsl:value-of select="name"/>
															</option>
														</xsl:when>
														<xsl:otherwise>
															<option value="{$id}">
																<xsl:value-of select="name"/>
															</option>
														</xsl:otherwise>
													</xsl:choose>
												</xsl:for-each>
											</select>
										</xsl:when>
										<xsl:when test="type = 'link'">
											<!--<div class="form-row ms-1">-->
												<label>
														<xsl:attribute name="class">
														<xsl:text>invisible</xsl:text>
														</xsl:attribute>
													<xsl:value-of select="value"/>
												</label>
												<br/>
												<input type="button" class= "btn btn btn-primary ms-2">
													<xsl:choose>
														<xsl:when test="onclick">
															<xsl:attribute name="onclick">
																<xsl:value-of select="onclick"/>
															</xsl:attribute>
														</xsl:when>
														<xsl:otherwise>
															<xsl:attribute name="onclick">javascript:window.open('<xsl:value-of select="href"/>', "_self");</xsl:attribute>
														</xsl:otherwise>
													</xsl:choose>
													<xsl:attribute name="value">
														<xsl:value-of select="value"/>
													</xsl:attribute>
												</input>
											<!--</div>-->
										</xsl:when>
										<xsl:when test="type = 'hidden'">
											<input>
												<xsl:attribute name="type">
													<xsl:value-of select="phpgw:conditional(not(type), '', type)"/>
												</xsl:attribute>
												<xsl:attribute name="id">
													<xsl:value-of select="phpgw:conditional(not(id), '', id)"/>
												</xsl:attribute>
												<xsl:attribute name="name">
													<xsl:value-of select="phpgw:conditional(not(name), '', name)"/>
												</xsl:attribute>
												<xsl:attribute name="value">
													<xsl:value-of select="phpgw:conditional(not(value), '', value)"/>
												</xsl:attribute>
											</input>
										</xsl:when>
										<xsl:when test="type = 'label'">
											<label>
												<xsl:attribute name="id">
													<xsl:value-of select="phpgw:conditional(not(id), '', id)"/>
												</xsl:attribute>
											</label>
										</xsl:when>
										<xsl:otherwise>
											<input id="innertoolbar_{name}" class="form-control">
												<xsl:attribute name="type">
													<xsl:value-of select="phpgw:conditional(not(type), '', type)"/>
												</xsl:attribute>
												<xsl:attribute name="name">
													<xsl:value-of select="phpgw:conditional(not(name), '', name)"/>
												</xsl:attribute>
												<xsl:attribute name="onclick">
													<xsl:value-of select="phpgw:conditional(not(onClick), '', onClick)"/>
												</xsl:attribute>
												<xsl:attribute name="value">
													<xsl:value-of select="phpgw:conditional(not(value), '', value)"/>
												</xsl:attribute>
												<xsl:attribute name="href">
													<xsl:value-of select="phpgw:conditional(not(href), '', href)"/>
												</xsl:attribute>
												<xsl:attribute name="class">
													<xsl:value-of select="phpgw:conditional(not(class), '', class)"/>
												</xsl:attribute>
												<xsl:attribute name="checked">
													<xsl:value-of select="phpgw:conditional(not(checked), '', checked)"/>
												</xsl:attribute>
											</input>
										</xsl:otherwise>
									</xsl:choose>
								</div>
							</xsl:for-each>
						</div>
					</fieldset>
				</form>
			</div>
		</xsl:if>
	</div>

</xsl:template>

<xsl:template match="form/list_actions">
	<form id="list_actions_form" method="POST">
		<!-- Form action is set by javascript listener -->
		<div id="list_actions">
			<table cellpadding="0" cellspacing="0">
				<tr>
					<xsl:for-each select="item">
						<td valign="top">
							<input id="innertoolbar">
								<xsl:attribute name="type">
									<xsl:value-of select="phpgw:conditional(not(type), '', type)"/>
								</xsl:attribute>
								<xsl:attribute name="name">
									<xsl:value-of select="phpgw:conditional(not(name), '', name)"/>
								</xsl:attribute>
								<xsl:attribute name="onclick">
									<xsl:value-of select="phpgw:conditional(not(onClick), '', onClick)"/>
								</xsl:attribute>
								<xsl:attribute name="value">
									<xsl:value-of select="phpgw:conditional(not(value), '', value)"/>
								</xsl:attribute>
								<xsl:attribute name="href">
									<xsl:value-of select="phpgw:conditional(not(href), '', href)"/>
								</xsl:attribute>
							</input>
						</td>
					</xsl:for-each>
				</tr>
			</table>
		</div>
	</form>
</xsl:template>

<xsl:template match="form">
	<div id="queryForm">
		<!--xsl:attribute name="method">
			<xsl:value-of select="phpgw:conditional(not(method), 'GET', method)"/>
		</xsl:attribute>
		<xsl:attribute name="action">
			<xsl:value-of select="phpgw:conditional(not(action), '', action)"/>
		</xsl:attribute-->

		<xsl:if test="count(//item) > 0">
			<xsl:apply-templates select="toolbar"/>
		</xsl:if>
	</div>
	<!--form id="update_table_dummy" method='POST' action='' >
	</form-->
</xsl:template>

<xsl:template match="datatable">
	<xsl:if test="count(//top-toolbar/fields/field) > 0">
		<xsl:call-template name="top-toolbar" />
	</xsl:if>
	<xsl:call-template name="datasource-definition" />
	<xsl:if test="count(//end-toolbar/fields/field) > 0">
		<xsl:call-template name="end-toolbar" />
	</xsl:if>
</xsl:template>

<xsl:template name="top-toolbar">
	<div class="toolbar-container">
		<div class="toolbar" >
			<form>
				<div class="form-row">
					<div class="form-group col-md-2">
						<xsl:apply-templates select="//datatable/workorder_data" />
					</div>
					<div class="form-group col-md-4">
						<xsl:for-each select="//top-toolbar/fields/field">
							<xsl:choose>
								<xsl:when test="type='button'">
									<button id="{id}" type="{type}" class="btn btn btn-primary">
										<xsl:value-of select="value"/>
									</button>
								</xsl:when>
							</xsl:choose>
						</xsl:for-each>
					</div>
				</div>
			</form>
		</div>
	</div>
</xsl:template>

<xsl:template name="end-toolbar">
	<div class="toolbar-container">
		<div class="toolbar">
			<form>
				<div class="form-row">
					<xsl:for-each select="//end-toolbar/fields/field">
						<div class="form-group col-md-2">
							<xsl:choose>
								<xsl:when test="type = 'date-picker'">
									<input class="form-control" id="filter_{name}" name="filter_{name}" value="{value}" type="text">
										<xsl:attribute name="title">
											<xsl:value-of select="phpgw:conditional(not(text), '', text)"/>
										</xsl:attribute>
									</input>
								</xsl:when>
								<xsl:when test="type='button'">
									<button id="{id}" type="{type}" class="btn btn btn-primary" onclick="{action}">
										<xsl:value-of select="value"/>
									</button>
								</xsl:when>
								<xsl:when test="type='label'">
									<xsl:value-of select="value"/>
								</xsl:when>
								<xsl:otherwise>
									<input id="{id}" type="{type}" name="{name}" value="{value}" class="form-control">
										<xsl:if test="type = 'checkbox' and checked = '1'">
											<xsl:attribute name="checked">checked</xsl:attribute>
										</xsl:if>
									</input>
								</xsl:otherwise>
							</xsl:choose>
						</div>
					</xsl:for-each>
				</div>
			</form>
		</div>
	</div>
</xsl:template>

<xsl:template name="datasource-definition">
	<table id="datatable-container" class="cell-border compact stripe" style="width:100%">
		<thead>
			<tr>
				<xsl:for-each select="//datatable/field">
					<xsl:choose>
						<xsl:when test="hidden">
							<xsl:if test="hidden =0">
								<th>
									<xsl:value-of select="label"/>
								</th>
							</xsl:if>
						</xsl:when>
						<xsl:otherwise>
							<th>
								<xsl:value-of select="label"/>
							</th>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:for-each>
			</tr>
		</thead>
		<tfoot>
			<tr>
				<xsl:for-each select="//datatable/field">
					<xsl:choose>
						<xsl:when test="hidden">
							<xsl:if test="hidden =0">
								<th>
									<xsl:value-of select="value_footer"/>
								</th>
							</xsl:if>
						</xsl:when>
						<xsl:otherwise>
							<th>
								<xsl:value-of select="value_footer"/>
							</th>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:for-each>
			</tr>
		</tfoot>
	</table>
	<form id="custom_values_form" name="custom_values_form"></form>
	<script>
		var columns = [
		<xsl:for-each select="//datatable/field">
			{
			data: "<xsl:value-of select="key"/>",
			<xsl:if test="className">
				<xsl:choose>
					<xsl:when test="className='right' or className='center'">
						<xsl:if test="className ='right'">
							class:	'dt-right',
						</xsl:if>
						<xsl:if test="className ='center'">
							class:	'dt-center',
						</xsl:if>
					</xsl:when>
					<xsl:otherwise>
						class:	"<xsl:value-of select="className"/>",
					</xsl:otherwise>
				</xsl:choose>
			</xsl:if>
			orderable:	<xsl:value-of select="phpgw:conditional(not(sortable = 0), 'true', 'false')"/>,
			<xsl:choose>
				<xsl:when test="searchable">
					<xsl:if test="searchable = 0">
						searchable:	false,
					</xsl:if>
					<xsl:if test="searchable = 1">
						searchable:	true,
					</xsl:if>
				</xsl:when>
				<xsl:otherwise>
					searchable:	false,
				</xsl:otherwise>
			</xsl:choose>
			<xsl:choose>
				<xsl:when test="hidden">
					<xsl:if test="hidden =0">
						visible: true,
					</xsl:if>
					<xsl:if test="hidden =1">
						class:			'none', //FIXME - virker ikke...'responsive' plukker den fram igjen
						visible: false,
					</xsl:if>
				</xsl:when>
				<xsl:otherwise>
					visible: true,
				</xsl:otherwise>
			</xsl:choose>
			<xsl:if test="formatter">
				render: function (dummy1, dummy2, oData) {
				try {
				var ret = <xsl:value-of select="formatter"/>("<xsl:value-of select="key"/>", oData);
				}
				catch(err) {
				return err.message;
				}
				return ret;
				},
			</xsl:if>
			<xsl:choose>
				<xsl:when test="dir !=''">
					dir: "<xsl:value-of select="dir"/>",
				</xsl:when>
			</xsl:choose>
			<xsl:choose>
				<xsl:when test="editor">
					<xsl:if test="editor =0">
						editor:	false,
					</xsl:if>
					<xsl:if test="editor =1">
						editor:	true,
					</xsl:if>
				</xsl:when>
				<xsl:otherwise>
					editor:	false,
				</xsl:otherwise>
			</xsl:choose>
			defaultContent:	"<xsl:value-of select="defaultContent"/>"
			}<xsl:value-of select="phpgw:conditional(not(position() = last()), ',', '')"/>
		</xsl:for-each>
		];
		<![CDATA[
		JqueryPortico.columns = [];
		for(i=0;i < columns.length;i++)
		{
			if ( columns[i]['visible'] == true )
			{
				JqueryPortico.columns.push(columns[i]);
			}
		}
			// console.log(JqueryPortico.columns);
		]]>
	</script>


	<script class="init">
		var lang_ButtonText_columns = "<xsl:value-of select="php:function('lang', 'columns')"/>";

		var oTable = null;
		$(document).ready(function() {
		var ajax_url = '<xsl:value-of select="source"/>';
		var order_def = [];
		<xsl:if test="sorted_by/key">
			order_def.push([<xsl:value-of select="sorted_by/key"/>, '<xsl:value-of select="sorted_by/dir"/>']);
		</xsl:if>
		var responsive = true;
		<xsl:if test="responsive_show_details = 1">
			responsive =	{
								details: {
										display: $.fn.dataTable.Responsive.display.childRowImmediate,
										type: ''
									}
							};
		</xsl:if>

		var download_url = '<xsl:value-of select="download"/>';
		var editor_cols = [];
		var editor_action = '<xsl:value-of select="editor_action"/>';
		var disablePagination = '<xsl:value-of select="disablePagination"/>';
		var select_all = '<xsl:value-of select="select_all"/>';
		var initial_search = {"search": "<xsl:value-of select="query"/>" };
		var action_def = [];
		var contextMenuItems={};
		var InitContextMenu=false
		var button_def = [];
		var group_buttons = false;

		<xsl:choose>
			<xsl:when test="string-length(new_item) > 0">
				<xsl:choose>
					<xsl:when test="new_item/onclick">
						button_def.push({
						text: "<xsl:value-of select="php:function('lang', 'new')"/>",
						action: function (e, dt, node, config) {
						<xsl:value-of select="new_item/onclick"/>;
						}
						});
					</xsl:when>
					<xsl:otherwise>
						button_def.push({
						text: "<xsl:value-of select="php:function('lang', 'new')"/>",
						<xsl:choose>
							<xsl:when test="bigmenubutton">
								className: 'bigmenubutton',
							</xsl:when>
						</xsl:choose>
						sUrl: '<xsl:value-of select="new_item"/>',

						action: function (e, dt, node, config) {
						var sUrl = config.sUrl;
						window.open(sUrl, '_self');
						}
						});
					</xsl:otherwise>
				</xsl:choose>
			</xsl:when>
		</xsl:choose>
		<xsl:choose>
			<xsl:when test="select_all = '1'">
				button_def.push({
				text: "<xsl:value-of select="php:function('lang', 'select all')"/>",
				action: function () {
				var api = oTable.api();
				api.rows().select();
				$(".mychecks").each(function()
				{
				$(this).prop("checked", true);
				});
				var selectedRows = api.rows( { selected: true } ).count();
				api.buttons( '.record' ).enable( selectedRows > 0 );

				}
				});

				button_def.push({
				text: "<xsl:value-of select="php:function('lang', 'select none')"/>",
				action: function () {
				var api = oTable.api();
				api.rows().deselect();
				$(".mychecks").each(function()
				{
				$(this).prop("checked", false);
				});
				api.buttons( '.record' ).enable( false );
				}
				});
			</xsl:when>
		</xsl:choose>
		var csv_download = JqueryPortico.i18n.csv_download();
		if(csv_download.show_button == 1)
		{
		button_def.push({
		extend:    'csvHtml5',
		titleAttr: csv_download.title,
		fieldSeparator: ';',
		bom:true
		});
		}
		<xsl:choose>
			<xsl:when test="download">
				button_def.push({
				text: "<xsl:value-of select="php:function('lang', 'download')"/>",
				titleAttr: "<xsl:value-of select="php:function('lang', 'download data')"/>",
				className: 'download',
				sUrl: '<xsl:value-of select="download"/>',
				action: function (e, dt, node, config) {
				var sUrl = config.sUrl;

					<![CDATA[
						var oParams = {};
						oParams.length = -1;
						oParams.columns = null;
						oParams.start = null;
						oParams.draw = null;
						var addtional_filterdata = oTable.dataTableSettings[0]['oAjaxData'];

						for (var attrname in addtional_filterdata)
						{
							oParams[attrname] = addtional_filterdata[attrname];
						}
						var iframe = document.createElement('iframe');
						iframe.style.height = "0px";
						iframe.style.width = "0px";

						if(typeof(oParams.order[0]) != 'undefined')
						{
							var column = oParams.order[0].column;
							var dir = oParams.order[0].dir;
							var column_to_keep = oParams.columns[column];
							delete oParams.columns;
							oParams.columns = {};
							if(JqueryPortico.columns[column]['orderable'] == true)
							{
								oParams.columns[column] = column_to_keep;
							}
						}
						else
						{
								delete oParams.columns;
						}

						iframe.src = sUrl+"&"+$.param(oParams) + "&export=1" + "&query=" + $('.dt-search input[aria-controls="datatable-container"]').val();
						if(confirm("This will take some time..."))
						{
							document.body.appendChild( iframe );
						}
						]]>
				}

				});
			</xsl:when>
		</xsl:choose>
		<xsl:choose>
			<xsl:when test="columns">
				button_def.push({
				<xsl:choose>
					<xsl:when test="columns/name">
						text: "<xsl:value-of select="columns/name"/>",
						titleAttr: "<xsl:value-of select="columns/name"/>",
					</xsl:when>
					<xsl:otherwise>
						text: "<xsl:value-of select="php:function('lang', 'columns')"/>",
						titleAttr: "<xsl:value-of select="php:function('lang', 'columns')"/>",
					</xsl:otherwise>
				</xsl:choose>
				className: 'download',
				action: function (e, dt, node, config) {
				<xsl:value-of select="columns/onclick"/>;
				}
				});
			</xsl:when>
		</xsl:choose>
		<xsl:choose>
			<xsl:when test="column_search">
				button_def.push({
				<xsl:choose>
					<xsl:when test="column_search/name">
						text: "<xsl:value-of select="column_search/name"/>",
						titleAttr: "<xsl:value-of select="column_search/name"/>",
					</xsl:when>
					<xsl:otherwise>
						text: "<xsl:value-of select="php:function('lang', 'column search')"/>",
						titleAttr: "<xsl:value-of select="php:function('lang', 'column search')"/>",
					</xsl:otherwise>
				</xsl:choose>
				className: 'download',
				action: function (e, dt, node, config) {
				<xsl:value-of select="column_search/onclick"/>;
				}
				});
			</xsl:when>
		</xsl:choose>
		<xsl:choose>
			<xsl:when test="//datatable/actions">
				<xsl:choose>
					<xsl:when test="//datatable/actions != ''">
						<xsl:for-each select="//datatable/actions">
							<xsl:choose>
								<xsl:when test="type = 'custom'">
									action_def.push({
									text: "<xsl:value-of select="text"/>",
									<xsl:choose>
										<xsl:when test="className">
											className: "<xsl:value-of select="className"/>",
										</xsl:when>
										<xsl:otherwise>
											enabled: false,
											className: 'record',
										</xsl:otherwise>
									</xsl:choose>
									action: function (e, dt, node, config)
									{
									<xsl:if test="confirm_msg">
										var confirm_msg = "<xsl:value-of select="confirm_msg"/>";
										var r = confirm(confirm_msg);
										if (r != true) {
										return false;
										}
									</xsl:if>
									fnSetSelected(this, dt);
									<xsl:value-of select="custom_code"/>
									}
									});
								</xsl:when>
								<xsl:otherwise>
									action_def.push({
									text: "<xsl:value-of select="text"/>",
									<xsl:choose>
										<xsl:when test="className">
											className: "<xsl:value-of select="className"/>",
										</xsl:when>
										<xsl:otherwise>
											enabled: false,
											className: 'record',
										</xsl:otherwise>
									</xsl:choose>
									action: function (e, dt, node, config) {
									var receiptmsg = [];
									fnSetSelected(this, dt);

									var selected = fnGetSelected();
									var numSelected = 	selected.length;

									if (numSelected ==0){
									alert('None selected');
									return false;
									}

									<xsl:if test="confirm_msg">
										var confirm_msg = "<xsl:value-of select="confirm_msg"/>";
										var r = confirm(confirm_msg);
										if (r != true) {
										return false;
										}
									</xsl:if>

									var target = "<xsl:value-of select="target"/>";
									if(!target)
									{
									target = '_self';
									}

									if (numSelected &gt; 1){
									target = '_blank';
									}

									var n = 0;
									for (; n &lt; numSelected; ) {
									//				console.log(selected[n]);
									var aData = oTable.api().rows(selected[n]).data()[0]; //complete dataset from json returned from server
									//console.log(aData);

									//delete stuff comes here
									var action = "<xsl:value-of select="action"/>";
									var my_name = "<xsl:value-of select="my_name"/>";

									<xsl:if test="parameters">
										var parameters = <xsl:value-of select="parameters"/>;
										//						console.log(parameters.parameter);
										var i = 0;
										len = parameters.parameter.length;
										for (; i &lt; len; ) {
										action += '&amp;' + parameters.parameter[i]['name'] + '=' + aData[parameters.parameter[i]['source']];
										i++;
										}
									</xsl:if>

									// look for the word "DELETE" in URL and my_name
									if(substr_count(action,'delete')>0 || substr_count(my_name,'delete')>0)
									{
										action += "&amp;confirm=yes&amp;phpgw_return_as=json";
										execute_ajax(action, function(result){
											document.getElementById("message").innerHTML += '<br/>' + result;
										
											oTable.api().draw('page');

										});
									}
									else if (target == 'ajax')
									{
										action += "&amp;phpgw_return_as=json";
										execute_ajax(action, function(result){
											document.getElementById("message").innerHTML += '<br/>' + result;
											oTable.api().draw('page');
										});
									}
									else
									{
										window.open(action,target);
									}
									n++;
									}
									}
									});
								</xsl:otherwise>
							</xsl:choose>
						</xsl:for-each>
					</xsl:when>
				</xsl:choose>
				<xsl:choose>
					<xsl:when test="group_buttons = '1'">
						group_buttons = true;
					</xsl:when>
					<xsl:otherwise>
						group_buttons = false;
					</xsl:otherwise>
				</xsl:choose>
	<![CDATA[

			var item_name = '';
			var contextMenuItem = {};
			for(i=0;i < action_def.length;i++)
			{
				button_def.push(action_def[i]);
				if( typeof(action_def[i]['className']) != 'undefined' && action_def[i]['className'] == 'record')
				{
					contextMenuItems[i] = {name:action_def[i]['text'], callback:action_def[i]['action']};
					InitContextMenu = true;
				}
			}

				if(button_def.length > 10)
				{
					group_buttons = true;
				}

				var isChrome = /Chrome/.test(navigator.userAgent) && /Google Inc/.test(navigator.vendor);
	]]>
				if($(document).width() &lt; 1000)
				{
					group_buttons = true;
				}


				if(isChrome == true)
				{
					group_buttons = false;
				}
				//disable grouping for now
				group_buttons = false;

			</xsl:when>
		</xsl:choose>
			if(button_def.length > 0)
			{
				if(group_buttons === true)
				{
				//					button_def.push({text: 'Esc',
				//                       action: function ( e, dt, node, config ) {
				//                        }});
					JqueryPortico.buttons = [
						{
							extend: 'collection',
							autoClose: true,
							text: "<xsl:value-of select="php:function('lang', 'toolbar')"/>",
							collectionLayout: 'three-column',
							buttons: button_def
						}
					];
				}
				else
				{
					JqueryPortico.buttons = button_def;
				}
			}
			else
			{
				JqueryPortico.buttons = null;
			}

		<![CDATA[

			for(i=0;i < JqueryPortico.columns.length;i++)
			{
				if (JqueryPortico.columns[i]['editor'] === true)
				{
					editor_cols.push({sUpdateURL:editor_action + '&field_name=' + JqueryPortico.columns[i]['data']});
				}
				else
				{
					editor_cols.push(null);
				}
			}

			if(order_def.length == 0)
			{
				for(i=0;i < JqueryPortico.columns.length;i++)
				{
					if (JqueryPortico.columns[i]['orderable'] === true && typeof(JqueryPortico.columns[i]['dir']) != 'undefined')
					{
						var dir = JqueryPortico.columns[i]['dir'] || "desc";
						order_def.push([i, dir]);
						break;
					}
				}
			}

			init_multiselect = function(oControl)
			{
				try
				{
					oControl.multiselect({
						buttonClass: 'form-select',
						templates: {
						button: '<button type="button" class="multiselect dropdown-toggle" data-bs-toggle="dropdown"><span class="multiselect-selected-text"></span></button>',
						},
						buttonWidth: 250,
						includeSelectAllOption: true,
						enableFiltering: true,
						enableCaseInsensitiveFiltering: true,

						onDropdownShown : function(event) {
							setTimeout(function(){
								oControl.parent().find("button.multiselect-clear-filter").click();
								oControl.parent().find("input[type='search'].multiselect-search").focus();
							}, 100);
						}
					});

				}
				catch(err)
				{
				}
			}

			/*
			 * Find and assign actions to filters
			 */
			var oControls = $('.dtable_custom_controls:first').find(':input[name]');

		$(document).ready(function() {

			/*
			* For namespacing the state
			*/
			var table_url = JqueryPortico.parseURL(window.location.href);
			var menuaction = 'dummy';
			var	column_search_is_initated = false;

			try
			{
				menuaction = table_url.searchObject.menuaction.replace(/\./g, '_');
			}
			catch (e)
			{
			}

			//clear state
			var clear_state = false;
			if(typeof(table_url.searchObject.clear_state) != 'undefined' && table_url.searchObject.clear_state == 1)
			{
				clear_state = true;
			}
			//uiocation
			if(typeof(table_url.searchObject.type_id) != 'undefined')
			{
				menuaction += '_type_id' + table_url.searchObject.type_id;
			}

			//uientity
			if(typeof(table_url.searchObject.entity_id) != 'undefined' && typeof(table_url.searchObject.cat_id) != 'undefined')
			{
				menuaction += '_entity_id' + table_url.searchObject.entity_id + '_cat_id' + table_url.searchObject.cat_id;
			}

			//uigeneric
			if(typeof(table_url.searchObject.type) != 'undefined' && menuaction.includes("uigeneric"))
			{
				menuaction += '_type_' + table_url.searchObject.type;
			}

			var select = false;

			if(select_all)
			{
				select = {style: 'multi'};
			}
			else
			{
				select = true;
			}

			if(button_def.length > 8)
			{
				var layout = {
							top2Start: 'buttons',
							topStart: null,
							topEnd: 'search',
							bottomStart: ['pageLength'],
							bottomEnd: ['inputPaging'],
							bottom2Start: 'info'
					}
			}
			else
			{
				var layout = {
							topStart: 'buttons',
							topEnd: 'search',
							bottomStart: ['pageLength'],
							bottomEnd: ['inputPaging'],
							bottom2Start: 'info'
					}
			}

			init_table = function()
			{
				oTable = $('#datatable-container').dataTable({
				paginate:		disablePagination ? false : true,
				searchDelay: 	1200,
				processing:		true,
				serverSide:		true,
				responsive:		responsive,
				select: select,
				deferRender:	true,
				layout: layout,
				ajax:{
					url: ajax_url,
					data:function ( aoData ) {
						var _columns = aoData.columns;
						delete aoData.columns;
						aoData.columns = {};

						if(typeof(aoData.order[0]) != 'undefined')
						{
							var column = aoData.order[0].column;
							var dir = aoData.order[0].dir;
							var column_to_keep = _columns[column];

							if(JqueryPortico.columns[column]['orderable'] == true)
							{
								aoData.columns[column] = column_to_keep;
							}
						}
						
						for ( var i=0 ; i< _columns.length ; i++ )
						{
						if(_columns[i].searchable && _columns[i].search.value !=="")
						{
							aoData.columns[i] = _columns[i];
						}
						}

						active_filters_html = [];
						var select = null;
						for (var i in filter_selects)
						{
							select = $("#" + filter_selects[i]);
							var select_name = select.prop("name");
							var select_value = select.val();
							aoData[select_name] = select_value;
						}

						oControls.each(function()
						{
							oControl = $(this);
							var test = $(this).val();
						//	console.log(test.constructor);
							if ( $(this).attr('name') && test != null && test.constructor !== Array)
							{
								value = $(this).val().replace('"', '"');
								aoData[ $(this).attr('name') ] = value;
								if(value && value !=0 )
								{
									active_filters_html.push($(this).attr('title'));
								}
							}
							if ( $(this).attr('name') && test != null && test.constructor === Array)
							{
								value = $(this).val();
								aoData[ $(this).attr('name') ] = value;

								if(value.length > 0 )
								{
									active_filters_html.push($(this).attr('title'));
								}
								init_multiselect(oControl);
							}

						});

						if(active_filters_html.length > 0 )
						{
							$('#active_filters').html("Aktive filter: " + active_filters_html.join(', '));
						}
						var search_value = $('.dt-search input[aria-controls="datatable-container"]').val();

						if(active_filters_html.length > 0 || search_value || column_search_is_initated)
						{
							$('#reset_filter').show();
						}
						else
						{
							$('#reset_filter').hide();
						}

					},
					dataSrc: function ( json ) {
						if (typeof(json.sessionExpired) != 'undefined' && json.sessionExpired == true)
						{
							window.alert('sessionExpired - please log in');
							JqueryPortico.lightboxlogin();//defined in common.js
		//					oTable.api().ajax.reload( null, false ); // user paging is not reset on reload
						}
						else
						{
							return json.data;
						}
					  },
					type: 'POST'
				},
				fnStateSaveParams: 	function ( oSettings, sValue ) {
					//Save custom filters
					var temp = {};
					temp[menuaction] = {}
					oControls.each(function() {
						if ( $(this).attr('name') && $(this).val() != null && $(this).val().constructor != Array)
						{
							sValue[ $(this).attr('name') ] = $(this).val().replace('"', '"');
							temp[ $(this).attr('name') ] = $(this).val().replace('"', '"');
						}
						if ( $(this).attr('name') && $(this).val() != null && $(this).val().constructor == Array)
						{
							sValue[ $(this).attr('name') ] = $(this).val();
							temp[ $(this).attr('name') ] = $(this).val();
						}

					});
					for (var attrname in sValue)
					{
						temp[attrname] = sValue[attrname];
					}
					localStorage.setItem('state_' + menuaction, JSON.stringify(temp));
					return sValue;
				},
				fnStateLoadParams: function ( oSettings, oData ) {
					//Load custom filters
					var retrievedObject = localStorage.getItem('state_' + menuaction);
					if(typeof(retrievedObject) != 'undefined')
					{
						var	params = {};

						try
						{
							params = JSON.parse(retrievedObject);
						}
						catch(err)
						{
						}
					}
//					console.log(oData);
					//traverse oData.columns and remove search value
				//	if (clear_state == true)
					{
						for (var attrname in oData.columns)
						{
							if(typeof(oData.columns[attrname].search) != 'undefined')
							{
								delete oData.columns[attrname].search;
							}
						}
					}
					//	console.log(params);
					if(params !== null)
					{
						oControls.each(function() {
							var oControl = $(this);
							$.each(params, function(index, value) {
								if ( index == oControl.attr('name') )
								{
									if (clear_state !== true)
									{
										if(value.constructor == Array)
										{
											$(oControl).find("option").removeAttr('selected');

											$.each(value, function(i,e){
												 oControl.find("option[value="+e+"]").prop("selected", "selected");
											});

//											init_multiselect(oControl);
										}
										else
										{
											oControl.val( value );
											try
											{
												$(oControl).removeAttr('selected').find("option[value='"+value+"']").attr('selected', 'selected');

												if($(oControl).find("option").length > 0)
												{
											//		$(oControl).formSelect();
												}
											}
											catch(err)
											{
											}

										}
									}
								}
							});
						});
					}
					return true;
				},
				fnCreatedRow  : function( nRow, aData, iDataIndex ){
 				},
				fnRowCallback: function(nRow, aData, iDisplayIndex, iDisplayIndexFull) {
							if(typeof(aData['priority'])!= undefined && aData['priority'] > 0)
							{
								$(nRow).addClass('priority' + aData['priority']);
							}
							//In case the row is folded as result of responsive behaviour
							$('td', nRow).parents('tr').addClass('context-menu');
                },
				fnDrawCallback: function () {
					oTable.makeEditable({
							sUpdateURL: editor_action,
							fnOnEditing: function(input){
								cell = input.parents("td");

								//it is a hack...but it works
								var rowIndex = cell.parents("tr")[0]._DT_RowIndex;

							//	console.log(rowIndex);
							//	console.log(oTable);
								var aData = oTable.api().rows( rowIndex ).data()[0];
							//	console.log(aData);
								id = aData.id;
							//	console.log(id);
								return true;
							},
							fnOnEdited: function(status, sOldValue, sNewCellDisplayValue, aPos0, aPos1, aPos2)
							{
								document.getElementById("message").innerHTML += '<br/>' + status;
								setTimeout(function(){
									document.getElementById("message").innerHTML = '';
								}, 1000);
							},
							oUpdateParameters: {
								"id": function(){ return id; }
							},
							aoColumns: editor_cols,
						    sSuccessResponse: "IGNORE",
							fnShowError: function(){ return; }
					});
					if(typeof(addFooterDatatable) == 'function')
					{
						addFooterDatatable(oTable);
					}
				},
				fnFooterCallback: function ( nRow, aaData, iStart, iEnd, aiDisplay ) {
					if(typeof(addFooterDatatable2) == 'function')
					{
						addFooterDatatable2(nRow, aaData, iStart, iEnd, aiDisplay,oTable);
					}
				},//alternative
				fnInitComplete: function (oSettings, json)
				{
					$(".btn-group").addClass('w-100');
					$(".dropdown-menu").addClass('w-100');
					$(".multiselect ").addClass('form-control');
					$(".multiselect").removeClass('btn');
					$(".multiselect").removeClass('btn-default');

					if(typeof(initCompleteDatatable) == 'function')
					{
						initCompleteDatatable(oSettings, json, oTable);
					}

				},
				lengthMenu:		JqueryPortico.i18n.lengthmenu(),
				language:		JqueryPortico.i18n.datatable(),
				columns:		JqueryPortico.columns,
				stateSave:		true,
				stateDuration: -1, //sessionstorage
				tabIndex:		1,
				"search": initial_search,
				"order": order_def,
				autoWidth: true,
				buttons: JqueryPortico.buttons
			});
			};

			init_table();

			restore_temporary_hidden_columns = function()
			{
				$('#datatable-container thead th').each(function(colIdx)
				{
					oTable.api().column(colIdx).visible(true);
				});
			};

			restore_temporary_hidden_columns();


			$('#datatable-container tbody').on( 'click', 'tr', function () {
					if($(this).hasClass('child'))
					{
						return;
					}
					$(this).toggleClass('selected');
					var api = oTable.api();
//					alert( api.rows('.selected').data().length +' row(s) selected' );
//					var selectedRows = api.rows( { selected: true } ).count();
					var selectedRows = api.rows('.selected').data().length;
					api.buttons( '.record' ).enable( selectedRows > 0 );
					var row = $(this);
					var checkbox = row.find('input[type="checkbox"]');

					if(checkbox && checkbox.hasClass('mychecks'))
					{
						if($(this).hasClass('selected'))
						{
							checkbox.prop("checked", true);
						}
						else
						{
							checkbox.prop("checked", false);
						}
					}
			   } );

			
			var colunm_search = false;
			var hidden_columns = [];

			remove_column_search = function()
			{
				//show hidden columns
				for (var i = 0; i < hidden_columns.length; i++)
				{
					oTable.api().column(hidden_columns[i]).visible(true);
				}
				hidden_columns = [];

				//remove search input from header
				$('#datatable-container thead th').each(function(colIdx)
				{
					if(oTable.api().settings()[0].aoColumns[colIdx].bSearchable)
					{
						if($(this).find('input.column_search').length > 0)
						{
							var placeholder = $(this).find('input.column_search').attr('placeholder');
							$(this).html(placeholder.split(lang['Search'] + ' ')[1]);
							//remove text input from header by classname
							$(this).find('input.column_search').remove();
						}
					}

				});

				colunm_search = false;
				oTable.api().responsive.recalc();
			};

			//---- START column search ----
			init_column_search = function()
			{
				if(colunm_search == true)
				{
					remove_column_search();
					return false;
				}
				
				colunm_search = true;
				let reset_filter_is_visible = false;
				if ($('#reset_filter').is(':visible'))
				{
					// The #reset_filter button is visible
					reset_filter_is_visible = true;
				}

				oTable.api().responsive.recalc();
				if(reset_filter_is_visible == true)
				{
					$('#reset_filter').show();
				}
				
				// Setup - add a text input to each header cell
				$('#datatable-container thead th').each(function(colIdx)
				{
					if(oTable.api().settings()[0].aoColumns[colIdx].bSearchable)
					{
						var title = $(this).text();
						var search_value = oTable.api().column(colIdx).search();
						$(this).html('<input class="column_search" type="text" placeholder="' + lang['Search'] + ' ' + title + '" value="' + search_value + '" title="' + title + '"/>');
					}
					else //hide the column from table
					{
						oTable.api().column(colIdx).visible(false);
						hidden_columns.push(colIdx);
					}

				});

				// Apply the search
				oTable.api().columns().eq(0).each(function(colIdx)
				{
					var lastSearcCallback = 0;
					var delay = 200;
					$('input', oTable.api().column(colIdx).header()).on('keyup change', function()
					{
						column_search_is_initated = true;

						if (lastSearcCallback >= (Date.now() - delay))
						{
							return;
						}
						lastSearcCallback = Date.now();

						oTable.api()
							.column(colIdx)
							.search(this.value)
							.draw();

						$('#reset_filter').show();

					});

					$('input', oTable.api().column(colIdx).header()).on('click', function(e) {
						e.stopPropagation();
					});
				});
				//---- END column search ----
			};

			if(InitContextMenu === true)
			{
				$('#datatable-container').contextMenu({
					selector: '.context-menu',
					items: contextMenuItems,
				});
			}

			if(number_of_toolbar_items < 5 || show_filter_group == true)
			{
				$('#democollapseBtn').addClass("show");
			}

			$('.dt-search input[aria-controls="datatable-container"]').focus();	
		});

	]]>

		/**
		* Add left click action..
		*/
		<xsl:if test="//left_click_action != ''">
			$("#datatable-container").on("click", "tbody tr", function() {
		//	var iPos = oTable.fnGetPosition( this );
			var iPos =oTable.api().row(this).index();
		//	var aData = oTable.fnGetData( iPos ); //complete dataset from json returned from server
			var aData = oTable.api().rows( iPos ).data()[0];
			try {
			<xsl:value-of select="//left_click_action"/>
			}
			catch(err) {
			document.getElementById("message").innerHTML = err.message;
			}
			});
		</xsl:if>

		/**
		* Add dbl click action..
		*/
		<xsl:if test="dbl_click_action != ''">
			$("#datatable-container").on("dblclick", "tr", function() {
	//		var iPos = oTable.fnGetPosition( this );
			var iPos =oTable.api().row(this).index();
		//	var aData = oTable.fnGetData( iPos ); //complete dataset from json returned from server
			var aData = oTable.api().rows( iPos ).data()[0];
			try {
			<xsl:value-of select="dbl_click_action"/>(aData);
			}
			catch(err) {
			document.getElementById("message").innerHTML = err.message;
			}
			});
		</xsl:if>

		<xsl:for-each select="//form/toolbar/item">
			<xsl:if test="type = 'filter'">
				<xsl:choose>
					<xsl:when test="multiple">
						$('select#<xsl:value-of select="name"/>').change( function()
						{
						var search = [];
						$.each($('select#<xsl:value-of select="name"/> option:selected'), function(){
						search.push($(this).val());
						});
						<xsl:value-of select="extra"/>
						filterData('<xsl:value-of select="name"/>', search);
						});
					</xsl:when>
					<xsl:otherwise>
						$('select#<xsl:value-of select="name"/>').change( function()
						{
						<xsl:value-of select="extra"/>
						filterData('<xsl:value-of select="name"/>', $(this).val());
						});
					</xsl:otherwise>
				</xsl:choose>
			</xsl:if>
			<xsl:if test="type = 'date-picker'">
				var previous_<xsl:value-of select="id"/>;
				$("#filter_<xsl:value-of select="id"/>").on('keyup change', function ()
				{
				if ( $.trim($(this).val()) != $.trim(previous_<xsl:value-of select="id"/>) )
				{
				filterData('<xsl:value-of select="id"/>', $(this).val());
				previous_<xsl:value-of select="id"/> = $(this).val();
				}
				});
			</xsl:if>
			<xsl:if test="type = 'checkbox'">
				$("#innertoolbar_<xsl:value-of select="name"/>").change( function()
				{
				if($(this).prop("checked"))
				{
				filterData('<xsl:value-of select="name"/>', 1);
				}
				else
				{
				filterData('<xsl:value-of select="name"/>', 0);
				}
				});
			</xsl:if>
			<xsl:if test="type = 'autocomplete'">
				$(document).ready(function() {
				$('input.ui-autocomplete-input#filter_<xsl:value-of select="name"/>_name').on('autocompleteselect', function(event, ui){
				filterData('filter_<xsl:value-of select="name"/>_id', ui.item.value);
				});
				$('input.ui-autocomplete-input#filter_<xsl:value-of select="name"/>_name').on('keyup', function(){
				if ($(this).val() == '')
				{
				$('#filter_<xsl:value-of select="name"/>_id').val('');
				filterData('filter_<xsl:value-of select="name"/>_id', $(this).val());
				}
				});
				});
			</xsl:if>
		</xsl:for-each>
	<![CDATA[
			function fnGetSelected( )
			{
				var aReturn = new Array();
				 var aTrs = oTable.api().rows().nodes();
				 for ( var i=0 ; i < aTrs.length ; i++ )
				 {
					if ( $(aTrs[i]).hasClass('context-menu-active'))
					 {
							aReturn.push( i );
							return aReturn;
					 }
					if ( $(aTrs[i]).hasClass('selected') )
					 {
						 aReturn.push( i );
					 }
				 }
				 return aReturn;
			}

			function fnSetSelected( row , dt)
			{
				var table = oTable.DataTable();
				if(typeof(dt.trigger) != 'undefined' && dt.trigger == 'right')
				{
					var aTrs = oTable.api().rows().nodes();

					for ( var i=0 ; i < aTrs.length ; i++ )
					{
						if ( $(aTrs[i]).hasClass('selected') )
						{
							table.row( i ).deselect();
						}
					}
				}

				if(typeof(row[0]) == 'undefined')
				{
					return false;
				}

				var sectionRowIndex = row[0].sectionRowIndex;
				if(typeof(sectionRowIndex) != 'undefined')
				{
					var selected = table.row( sectionRowIndex ).select();
				}
			}

			function execute_ajax(requestUrl, callback, data,type, dataType)
			{
				type = typeof type !== 'undefined' ? type : 'POST';
				dataType = typeof dataType !== 'undefined' ? dataType : 'html';
				data = typeof data !== 'undefined' ? data : {};

				$.ajax({
					type: type,
					dataType: dataType,
					data: data,
					url: requestUrl,
					success: function(result) {
						callback(result);
					}
				});
			}

			function substr_count( haystack, needle, offset, length )
			{
				var pos = 0, cnt = 0;

				haystack += '';
				needle += '';
				if(isNaN(offset)) offset = 0;
				if(isNaN(length)) length = 0;
				offset--;

				while( (offset = haystack.indexOf(needle, offset+1)) != -1 )
				{
					if(length > 0 && (offset+needle.length) > length)
					{
						return false;
					}
					else
					{
						cnt++;
					}
				}
				return cnt;
			}
		});

		reset_filter = function()
		{
			var api = oTable.api();
			for (var i in filter_selects)
			{
				select = $("#" + filter_selects[i]);
				select.prop('selectedIndex',0);
				try
				{
					if($("#" + filter_selects[i]).attr('multiple'))
					{
						$("#" + filter_selects[i]).multiselect('deselectAll', false);
			//			$("#" + filter_selects[i]).multiselect({ buttonContainer: '' });
						$("#" + filter_selects[i]).multiselect('refresh');
					}

					column_search_is_initated = false;
				}
				catch(e)
				{}
			}

			var oControls = $('.dtable_custom_controls:first').find(':input[name]');

			oControls.each(function()
			{
				var test = $(this).val();
				if ( !$(this).is('select') && $(this).attr('name') && test != null && test.constructor !== Array)
				{
//					value = $(this).val('');
				}
			});

			api.state.clear();
			api.destroy();
			clear_state = true;
			init_table();
			restore_temporary_hidden_columns();
			remove_column_search();
			$('#reset_filter').hide();
			$('#active_filters').html("");
			// Deselect all selected options in Select2 and select the first option
			$('.select2').each(function() {
				var $select = $(this);
				$select.val('0');
				$select.trigger('change');
			// Update the displayed text in Select2
				var $selection = $select.find('.select2-selection__rendered');
				$selection.text('');
			});
		}


		function searchData(query)
		{
			var api = oTable.api();
			api.search( query ).draw();
		}

		function filterData(param, value)
		{
			oTable.dataTableSettings[0]['ajax']['data'][param] = value;
			oTable.api().draw();
		}

		function clearFilterParam(param)
		{
			oTable.dataTableSettings[0]['ajax']['data'][param] = '';
		}

		function reloadData()
		{
			var api = oTable.api();
			api.ajax.reload();
		}
	]]>
	</script>

	<script>
		<xsl:choose>
			<xsl:when test="//js_lang != ''">
				var lang = <xsl:value-of select="//js_lang"/>;
			</xsl:when>
		</xsl:choose>
	</script>
</xsl:template>
