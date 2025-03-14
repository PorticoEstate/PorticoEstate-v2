<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<xsl:variable name="date_format">
		<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />
	</xsl:variable>
	<xsl:variable name="datetime_format">
		<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />
		<xsl:text> H:i</xsl:text>
	</xsl:variable>

	<xsl:call-template name="msgbox"/>
	<script type="text/javascript">
		var resource_id = "<xsl:value-of select="resource/id"/>";
		var default_schema = "<xsl:value-of select="resource/activity_name"/>";
		var schema_type = "form";
	</script>

	<form action="" method="POST" id="form" class="pure-form pure-form-aligned" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="resource/tabs"/>
			<div id="resource" class="booking-container">
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Name')" />
					</label>
					<input name="name" id="field_name" type="text" value="{resource/name}" class="pure-input-3-4">
						<xsl:attribute name="data-validation">
							<xsl:text>required</xsl:text>
						</xsl:attribute>
						<xsl:attribute name="data-validation-error-msg">
							<xsl:value-of select="php:function('lang', 'Please enter a name')" />
						</xsl:attribute>
					</input>
				</div>
				<!--<xsl:if test="not(new_form)">-->
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Main activity')" />
					</label>
					<input id="field_schema_activity_id" type="hidden" name="schema_activity_id" value=""/>
					<select id="field_activity_id" name="activity_id" class="pure-input-3-4">
						<option value=''>
							<xsl:value-of select="php:function('lang', 'Select activity...')" />
						</option>
						<xsl:for-each select="activitydata/results">
							<option value="{id}">
								<xsl:if test="resource_id=id">
									<xsl:attribute name="selected">selected</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="name" />
							</option>
						</xsl:for-each>
					</select>
				</div>
				<!--</xsl:if>-->
				<!--<xsl:if test="not(new_form)">-->
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Resource category')" />
					</label>
					<input id="field_schema_rescategory_id" type="hidden" name="schema_rescategory_id" value=""/>
					<select id="field_rescategory_id" name="rescategory_id" class="pure-input-3-4">
						<xsl:attribute name="data-validation">
							<xsl:text>required</xsl:text>
						</xsl:attribute>
						<xsl:attribute name="data-validation-error-msg">
							<xsl:value-of select="php:function('lang', 'Please select a resource category')" />
						</xsl:attribute>
						<option value=''>
							<xsl:value-of select="php:function('lang', 'Select category...')" />
						</option>
						<xsl:for-each select="rescategorydata">
							<option value="{id}">
								<xsl:if test="disabled=1">
									<xsl:attribute name="disabled">disabled</xsl:attribute>
								</xsl:if>
								<xsl:if test="id=../resource/rescategory_id">
									<xsl:attribute name="selected">selected</xsl:attribute>
								</xsl:if>
								<xsl:attribute name="data-capacity">
									<xsl:value-of select="capacity" />
								</xsl:attribute>
								<xsl:attribute name="data-e_lock">
									<xsl:value-of select="e_lock" />
								</xsl:attribute>
								<xsl:value-of disable-output-escaping="yes" select="name" />
							</option>
						</xsl:for-each>
					</select>
				</div>
				<!--</xsl:if>-->
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Sort order')" />
					</label>
					<input name="sort" id="field_sort" type="text" value="{resource/sort}"/>
				</div>
				<xsl:if test="not(new_form)">
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Building')"/>
						</label>
						<div class = 'pure-u-md-1-2'>
							<xsl:for-each select="datatable_def">
								<xsl:if test="container = 'datatable-container_0'">
									<xsl:call-template name="table_setup">
										<xsl:with-param name="container" select ='container'/>
										<xsl:with-param name="requestUrl" select ='requestUrl'/>
										<xsl:with-param name="ColumnDefs" select ='ColumnDefs'/>
										<xsl:with-param name="data" select ='data'/>
										<xsl:with-param name="config" select ='config'/>
									</xsl:call-template>
								</xsl:if>
							</xsl:for-each>
						</div>
					</div>
				</xsl:if>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Building')" />
					</label>
					<input id="field_building_id" name="building_id" type="hidden" value=""/>
					<input id="field_building_name" name="building_name" type="text" value="" class="pure-input-1-2">
						<xsl:if test="new_form">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter a building name')" />
							</xsl:attribute>
						</xsl:if>
					</input>
					<div id="building_container" class="custom-container"></div>
					<xsl:if test="resource/permission/write">
						<a class='button'>
							<xsl:attribute name="onClick">
								<xsl:text>addBuilding()</xsl:text>
							</xsl:attribute>
							<xsl:value-of select="php:function('lang', 'Add')" />
						</a>
						<xsl:text> | </xsl:text>
						<a class='button'>
							<xsl:attribute name="onClick">
								<xsl:text>removeBuilding()</xsl:text>
							</xsl:attribute>
							<xsl:value-of select="php:function('lang', 'Delete')" />
						</a>
					</xsl:if>

				</div>
				<div id="custom_data" style="display: none;">
					<div class="pure-control-group">
						<label>
							<div id="schema_name"></div>
						</label>
					</div>
					<div id="custom_fields"></div>
				</div>
				<xsl:if test="not(new_form)">
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Active')"/>
						</label>
						<select id="field_active" name="active" class="pure-input-3-4">
							<option value="1">
								<xsl:if test="resource/active=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Active')"/>
							</option>
							<option value="0">
								<xsl:if test="resource/active=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Inactive')"/>
							</option>
						</select>
					</div>
				</xsl:if>
				<xsl:if test="not(new_form)">
					<div class="pure-control-group">
						<label for="for_field_deactivate_application">
							<xsl:value-of select="php:function('lang', 'Deactivate application')"/>
						</label>
						<select id="for_field_deactivate_application" name="deactivate_application" class="pure-input-3-4" >
							<option value="1">
								<xsl:if test="resource/deactivate_application=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Yes')"/>
							</option>
							<option value="0">
								<xsl:if test="resource/deactivate_application=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'No')"/>
							</option>
						</select>
					</div>

					<div class="pure-control-group">
						<label for="for_field_hidden_in_frontend">
							<xsl:value-of select="php:function('lang', 'hidden in frontend')"/>
						</label>
						<select id="for_field_hidden_in_frontend" name="hidden_in_frontend" class="pure-input-3-4" >
							<option value="1">
								<xsl:if test="resource/hidden_in_frontend=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Yes')"/>
							</option>
							<option value="0">
								<xsl:if test="resource/hidden_in_frontend=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'No')"/>
							</option>
						</select>
					</div>
					<div class="pure-control-group">
						<label for="for_field_activate_prepayment">
							<xsl:value-of select="php:function('lang', 'activate prepayment')"/>
						</label>
						<select id="for_field_activate_prepayment" name="activate_prepayment" class="pure-input-3-4" >
							<option value="1">
								<xsl:if test="resource/activate_prepayment=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Yes')"/>
							</option>
							<option value="0">
								<xsl:if test="resource/activate_prepayment=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'No')"/>
							</option>
						</select>
					</div>

					<div class="pure-control-group">
						<label for="for_field_deny_application_if_booked">
							<xsl:value-of select="php:function('lang', 'deny application if booked')"/>
						</label>
						<select id="for_field_deny_application_if_booked" name="deny_application_if_booked" class="pure-input-3-4" >
							<option value="1">
								<xsl:if test="resource/deny_application_if_booked=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Yes')"/>
							</option>
							<option value="0">
								<xsl:if test="resource/deny_application_if_booked=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'No')"/>
							</option>
						</select>
					</div>

					<div class="pure-control-group custom-container">
						<label>
							<xsl:value-of select="php:function('lang', 'seasons')"/>
						</label>

						<div class="pure-u-md-1-2">
							<table class="table table-striped table-bordered dataTable" style="white-space: nowrap;">
								<thead>
									<tr>
										<th>
											<xsl:value-of select="php:function('lang', 'id')"/>
										</th>
										<th>
											<xsl:value-of select="php:function('lang', 'name')"/>
										</th>
									</tr>
								</thead>
								<xsl:for-each select="seasons">
									<tr>
										<td>
											<xsl:value-of select="id" />
										</td>
										<td>
											<xsl:value-of select="name" />
										</td>
									</tr>
								</xsl:for-each>
							</table>
						</div>
					</div>

				</xsl:if>

				<div id="capacity_form">
					<xsl:if test="new_form or resource/rescategory_capacity != 1">
						<xsl:attribute name="style">
							<xsl:text>display:none;</xsl:text>
						</xsl:attribute>
					</xsl:if>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'capacity')"/>
						</label>
						<input type="number" min="0" id="field_capacity" name="capacity" value="{resource/capacity}" class="pure-input-3-4">
						</input>
					</div>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Direct booking')"/>
					</label>
					<input type="text" id="direct_booking" name="direct_booking" size="10" readonly="readonly">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'Direct booking')"/>
						</xsl:attribute>
						<xsl:if test="resource/direct_booking != ''">
							<xsl:attribute name="value">
								<xsl:value-of select="php:function('date', $date_format, number(resource/direct_booking))"/>
							</xsl:attribute>
						</xsl:if>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'simple booking')"/>
					</label>
					<input type="checkbox" id="simple_booking" name="simple_booking" value="1">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'Simple booking')"/>
						</xsl:attribute>
						<xsl:if test="resource/simple_booking = '1'">
							<xsl:attribute name="checked">
								<xsl:text>checked</xsl:text>
							</xsl:attribute>
						</xsl:if>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'start date')"/>
					</label>
					<input type="text" id="simple_booking_start_date" name="simple_booking_start_date" size="16" readonly="readonly">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'start date')"/>
						</xsl:attribute>
						<xsl:if test="resource/simple_booking_start_date != ''">
							<xsl:attribute name="value">
								<xsl:value-of select="php:function('date', $datetime_format, number(resource/simple_booking_start_date))"/>
							</xsl:attribute>
						</xsl:if>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'end date')"/>
					</label>
					<input type="text" id="simple_booking_end_date" name="simple_booking_end_date" size="10" readonly="readonly">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'end date')"/>
						</xsl:attribute>
						<xsl:if test="resource/simple_booking_end_date != ''">
							<xsl:attribute name="value">
								<xsl:value-of select="php:function('date', $date_format, number(resource/simple_booking_end_date))"/>
							</xsl:attribute>
						</xsl:if>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'day horizon')"/>
					</label>
					<input type="number" min="0" id="booking_day_horizon" name="booking_day_horizon" value="{resource/booking_day_horizon}">
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'month horizon')"/>
					</label>
					<input type="number" min="0" id="booking_month_horizon" name="booking_month_horizon" value="{resource/booking_month_horizon}">
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'day default lenght')"/>
					</label>
					<input type="number" min="-1" id="booking_day_default_lenght" name="booking_day_default_lenght" value="{resource/booking_day_default_lenght}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'dow default start')"/>
					</label>
					<input type="number" min="-1" id="booking_dow_default_start" name="booking_dow_default_start" value="{resource/booking_dow_default_start}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'day of week')"/>
							&nbsp;
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>

				<!--
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'dow default end')"/>
					</label>
					<input type="number" min="-1" id="booking_dow_default_end" name="booking_dow_default_end" value="{resource/booking_dow_default_end}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'day of week')"/>
							<br/>
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>
				-->

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'time default start')"/>
					</label>
					<input type="number" min="-1" id="booking_time_default_start" name="booking_time_default_start" value="{resource/booking_time_default_start}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'time default start')"/>
							&nbsp;
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'time default end')"/>
					</label>
					<input type="number" min="-1" id="booking_time_default_end" name="booking_time_default_end" value="{resource/booking_time_default_end}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'time default end')"/>
							&nbsp;
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'minutes')"/>
					</label>
					<input type="number" min="-1" id="booking_time_minutes" name="booking_time_minutes" value="{resource/booking_time_minutes}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'minutes')"/>
							&nbsp;
							<xsl:value-of select="php:function('lang', 'value is ignored for -1')"/>
						</xsl:attribute>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'buffer deadline')"/>
							&nbsp;
						(<xsl:value-of select="php:function('lang', 'minutes')"/>)
					</label>
					<input type="number" min="0" id="booking_buffer_deadline" name="booking_buffer_deadline" value="{resource/booking_buffer_deadline}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'minutes')"/>
						</xsl:attribute>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'limit number')"/>
					</label>
					<input type="number" min="-1" id="booking_limit_number" name="booking_limit_number" value="{resource/booking_limit_number}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'limit number')"/>
						</xsl:attribute>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'limit number horizont')"/>
					</label>
					<input type="number" min="-1" id="booking_limit_number_horizont" name="booking_limit_number_horizont" value="{resource/booking_limit_number_horizont}">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'limit number horizont')"/>
						</xsl:attribute>
					</input>
				</div>

				<div id="e_lock_form">
					<xsl:if test="new_form or resource/rescategory_e_lock != 1">
						<xsl:attribute name="style">
							<xsl:text>display:none;</xsl:text>
						</xsl:attribute>
					</xsl:if>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Electronic lock')"/>
						</label>
						<div class = 'pure-u-md-1-2'>
							<xsl:for-each select="datatable_def">
								<xsl:if test="container = 'datatable-container_1'">
									<xsl:call-template name="table_setup">
										<xsl:with-param name="container" select ='container'/>
										<xsl:with-param name="requestUrl" select ='requestUrl'/>
										<xsl:with-param name="ColumnDefs" select ='ColumnDefs'/>
										<xsl:with-param name="data" select ='data'/>
										<xsl:with-param name="config" select ='config'/>
									</xsl:call-template>
								</xsl:if>
							</xsl:for-each>
						</div>
					</div>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Add Electronic lock')"/>
						</label>

						<xsl:variable name="lang_system_id">
							<xsl:value-of select="php:function('lang', 'System id')"/>
						</xsl:variable>
						<xsl:variable name="lang_resource_id">
							<xsl:value-of select="php:function('lang', 'resource id')"/>
						</xsl:variable>
						<xsl:variable name="lang_access_code_format">
							<xsl:value-of select="php:function('lang', 'access code format')"/>
						</xsl:variable>
						<xsl:variable name="lang_access_instruction">
							<xsl:value-of select="php:function('lang', 'access instruction')"/>
						</xsl:variable>

						<table class = 'pure-u-md-1-2'>
							<tr>
								<td>
									<input type="text" id="e_lock_system_id" name="e_lock_system_id">
										<xsl:attribute name="placeholder">
											<xsl:value-of select="$lang_system_id"/>
										</xsl:attribute>
										<xsl:attribute name="title">
											<xsl:value-of select="$lang_system_id"/>
										</xsl:attribute>
									</input>
								</td>
								<td>
									<input type="text" id="e_lock_resource_id" name="e_lock_resource_id">
										<xsl:attribute name="title">
											<xsl:value-of select="$lang_resource_id"/>
										</xsl:attribute>
										<xsl:attribute name="placeholder">
											<xsl:value-of select="$lang_resource_id"/>
										</xsl:attribute>
									</input>
								</td>
							</tr>
							<tr>
								<td>
									<input type="text" id="e_lock_name" name="e_lock_name">
										<xsl:attribute name="title">
											<xsl:value-of select="php:function('lang', 'name')"/>
										</xsl:attribute>
										<xsl:attribute name="placeholder">
											<xsl:value-of select="php:function('lang', 'name')"/>
										</xsl:attribute>
									</input>
								</td>
								<td>

									<input type="text" id="access_code_format" name="access_code_format">
										<xsl:attribute name="title">
											<xsl:value-of select="$lang_access_code_format"/>
										</xsl:attribute>
										<xsl:attribute name="placeholder">
											<xsl:value-of select="$lang_access_code_format"/>
										</xsl:attribute>
									</input>
								</td>
							</tr>
							<tr>
								<td colspan="2">
									<input type="text" id="access_instruction" name="access_instruction" class="pure-input-1">
										<xsl:attribute name="title">
											<xsl:value-of select="$lang_access_instruction"/>
										</xsl:attribute>
										<xsl:attribute name="placeholder">
											<xsl:value-of select="$lang_access_instruction"/>
										</xsl:attribute>
									</input>
								</td>
							</tr>
							<xsl:if test="resource/permission/write">
								<tr>
									<td>
										<a class='btn btn-info' role="button">
											<xsl:attribute name="onClick">
												<xsl:text>addELock()</xsl:text>
											</xsl:attribute>
											<xsl:value-of select="php:function('lang', 'Add')" />
										</a>
										<xsl:text> | </xsl:text>
										<a class='btn btn-info' role="button">
											<xsl:attribute name="onClick">
												<xsl:text>removeELock()</xsl:text>
											</xsl:attribute>
											<xsl:value-of select="php:function('lang', 'Delete')" />
										</a>
									</td>
								</tr>
							</xsl:if>
						</table>
					</div>
				</div>


<!--				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Description')" />
					</label>
					<div class="custom-container pure-input-3-4">
						<textarea id="field_description" name="description" type="text">
							<xsl:value-of select="resource/description"/>
						</textarea>
					</div>
				</div>-->
				<xsl:for-each select="langs">
					<xsl:variable name="lang">
						<xsl:value-of select="lang"/>
					</xsl:variable>
					<div class="pure-control-group">
						<label for="field_description_json_{$lang}">
							<xsl:value-of select="php:function('lang', 'Description')" />
							<xsl:text> </xsl:text>
							<xsl:value-of select="name"/>
						</label>
						<div class="custom-container pure-input-3-4">
							<textarea id="field_description_json_no" name="description_json[{$lang}]" type="text" class="pure-input-1" >
								<xsl:value-of disable-output-escaping="yes" select="description"/>
							</textarea>
						</div>
					</div>
				</xsl:for-each>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Opening hours')" />
					</label>
					<div class="custom-container pure-input-3-4">
						<textarea id="field_opening_hours" name="opening_hours" type="text">
							<xsl:value-of select="resource/opening_hours"/>
						</textarea>
					</div>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Contact information')" />
					</label>
					<div class="custom-container pure-input-3-4">
						<textarea id="field_contact_info" name="contact_info" type="text">
							<xsl:value-of select="resource/contact_info"/>
						</textarea>
					</div>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'organzations_ids')" />
					</label>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'organzations_ids_description')" />
					</label>
					<input name="organizations_ids" id="field_organizations_ids" type="text" value="{resource/organizations_ids}"/>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'register participants')"/>
						<br/>
						<xsl:value-of select="php:function('lang', 'participant limit')"/>
					</label>
					<div class = 'pure-u-md-1-2'>
						<xsl:for-each select="datatable_def">
							<xsl:if test="container = 'datatable-container_2'">
								<xsl:call-template name="table_setup">
									<xsl:with-param name="container" select ='container'/>
									<xsl:with-param name="requestUrl" select ='requestUrl'/>
									<xsl:with-param name="ColumnDefs" select ='ColumnDefs'/>
									<xsl:with-param name="data" select ='data'/>
									<xsl:with-param name="config" select ='config'/>
									<xsl:with-param name="class" select="'table table-striped table-bordered'" />
								</xsl:call-template>
							</xsl:if>
						</xsl:for-each>
					</div>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Add participant limit')"/>
					</label>

					<xsl:variable name="lang_date">
						<xsl:value-of select="php:function('lang', 'date')"/>
					</xsl:variable>
					<xsl:variable name="lang_quantity">
						<xsl:value-of select="php:function('lang', 'quantity')"/>
					</xsl:variable>

					<input type="text" id="participant_limit_from" name="participant_limit_from">
						<xsl:attribute name="title">
							<xsl:value-of select="$lang_date"/>
						</xsl:attribute>
						<xsl:attribute name="placeholder">
							<xsl:value-of select="$lang_date"/>
						</xsl:attribute>
					</input>
					<input type="number" id="participant_limit_quantity" min="-1" name="participant_limit_quantity">
						<xsl:attribute name="title">
							<xsl:value-of select="$lang_quantity"/>
						</xsl:attribute>
						<xsl:attribute name="placeholder">
							<xsl:value-of select="$lang_quantity"/>
						</xsl:attribute>
					</input>
					<xsl:if test="resource/permission/write">
						<a class='btn btn-info'>
							<xsl:attribute name="onClick">
								<xsl:text>add_participant_limit()</xsl:text>
							</xsl:attribute>
							<xsl:value-of select="php:function('lang', 'Add')" />/
							<xsl:value-of select="php:function('lang', 'Edit')" />
						</a>
					</xsl:if>

				</div>
			</div>
		</div>
		<div class="form-buttons">
			<input type="submit" id="button" class="pure-button pure-button-primary">
				<xsl:attribute name="value">
					<xsl:choose>
						<xsl:when test="new_form">
							<xsl:value-of select="php:function('lang', 'Create')"/>
						</xsl:when>
						<xsl:otherwise>
							<xsl:value-of select="php:function('lang', 'Update')"/>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
			</input>
			<input type="button" class="pure-button pure-button-primary" name="cancel">
				<xsl:attribute name="onclick">window.location="<xsl:value-of select="resource/cancel_link"/>"</xsl:attribute>
				<xsl:attribute name="value">
					<xsl:value-of select="php:function('lang', 'Cancel')" />
				</xsl:attribute>
			</input>
		</div>
	</form>
	<script type="text/javascript">
		var lang = <xsl:value-of select="php:function('js_lang', 'Select category...')"/>;
	</script>
</xsl:template>
