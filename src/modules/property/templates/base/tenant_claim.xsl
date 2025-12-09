
<!-- $Id$ -->
<xsl:template match="data">
	<xsl:call-template name="jquery_phpgw_i18n"/>
	<xsl:choose>
		<xsl:when test="edit">
			<xsl:apply-templates select="edit"/>
		</xsl:when>
		<xsl:when test="new">
			<xsl:apply-templates select="new"/>
		</xsl:when>
	</xsl:choose>
</xsl:template>

<!-- new -->
<xsl:template xmlns:php="http://php.net/xsl" match="new">


<style type="text/css">
	label.required:after {
		content: " *";
		color: red;
	}
</style>
	<xsl:variable name="form_url">
		<xsl:value-of select="form_url"/>
	</xsl:variable>
	<form ENCTYPE="multipart/form-data" class="pure-form pure-form-aligned" name="form" id="form" method="post" action="{$form_url}">

		<div id="location_selector" class="pure-control-group">
			<label for='location_name'>
				<xsl:value-of select="php:function('lang', 'location')"/>
			</label>
			<input type="hidden" id="location_code" name="values[location_code]" />
			<input type="text" id="location_name" name="values[location_name]" required="required" class="pure-input-3-4">
			<xsl:attribute name="placeholder">
				<xsl:value-of select="php:function('lang', 'search')"/>
			</xsl:attribute>
			<xsl:attribute name="data-validation">
				<xsl:text>required</xsl:text>
			</xsl:attribute>
			<xsl:attribute name="data-validation-error-msg">
				<xsl:value-of select="php:function('lang', 'location')"/>
			</xsl:attribute>
			</input>

			<div id="location_container"/>
		</div>

		<div class="pure-control-group">
			<label for='reskontro'>
				<xsl:text>Reskontro</xsl:text>
			</label>
			<select id="reskontro" name="values[reskontro]" class="pure-input-3-4">
				<xsl:attribute name="required">
					<xsl:text>required</xsl:text>
				</xsl:attribute>
			<xsl:attribute name="data-validation">
				<xsl:text>required</xsl:text>
			</xsl:attribute>
			<xsl:attribute name="data-validation-error-msg">
				<xsl:text>Reskontro</xsl:text>
			</xsl:attribute>

			</select>
		</div>		

		<div class="pure-control-group">
			<label for='claim_type'>
				<xsl:value-of select="php:function('lang', 'claim type')"/>
			</label>
			<select id="claim_type" name="values[claim_type]"  required="required" class="pure-input-3-4">
			<xsl:attribute name="data-validation">
				<xsl:text>required</xsl:text>
			</xsl:attribute>
			<xsl:attribute name="data-validation-error-msg">
				<xsl:value-of select="php:function('lang', 'claim type')"/>
			</xsl:attribute>
			<option value="">-- Select --</option>
			<xsl:apply-templates select="claim_types/options"/>
			</select>
		</div>


		<div class="pure-control-group">
			<label for='claim_date'>
				<xsl:value-of select="php:function('lang', 'Date')"/>
			</label>
			<input type="text" id="claim_date" name="values[claim_date]" value="" required="required" class="date pure-input-3-4">
			<xsl:attribute name="data-validation">
				<xsl:text>required</xsl:text>
			</xsl:attribute>
			<xsl:attribute name="data-validation-error-msg">
				<xsl:value-of select="php:function('lang', 'Date')"/>
			</xsl:attribute>
				<xsl:attribute name="title">
					<xsl:value-of select="php:function('lang', 'date_statustext')"/>
				</xsl:attribute>
			</input>
			
		</div>

		<div class="pure-control-group">
			<label for="category">
					<xsl:value-of select="php:function('lang', 'category')"/>
			</label>
			<xsl:call-template name="cat_select">
				<xsl:with-param name="class">pure-input-3-4</xsl:with-param>
				<xsl:with-param name="required">required</xsl:with-param>
				<xsl:with-param name="id">category</xsl:with-param>
			</xsl:call-template>
		</div>

		<div class="pure-control-group">
			<label for='amount'>
				<xsl:value-of select="php:function('lang', 'amount')"/>
			</label>
			<input type="text" id="amount" name="values[amount]" value="" required="required" class="amount pure-input-3-4">
			<xsl:attribute name="data-validation">
				<xsl:text>required</xsl:text>
			</xsl:attribute>
			<xsl:attribute name="data-validation-error-msg">
				<xsl:value-of select="php:function('lang', 'amount')"/>
			</xsl:attribute>
				<xsl:attribute name="title">
					<xsl:value-of select="php:function('lang', 'amount_statustext')"/>
				</xsl:attribute>
			</input>
			<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
		</div>
	
		<div class="pure-control-group">
			<label for='remark'>
				<xsl:value-of select="php:function('lang', 'remark')"/>
			</label>
			<textarea cols="60" rows="6" id="remark" name="values[remark]" class="pure-input-3-4">
				<xsl:attribute name="title">
					<xsl:value-of select="php:function('lang', 'remark_statustext')"/>
				</xsl:attribute>
			</textarea>
		</div>

		<!--div class="pure-control-group">
			<label for='attachments'>
				<xsl:value-of select="php:function('lang', 'attachments')"/>
			</label>
			<input type="file"  id="attachments" name="file" class="pure-input-3-4">
				<xsl:attribute name="required">
					<xsl:text>required</xsl:text>
				</xsl:attribute>
			</input>
		</div-->
		<div class="pure-control-group">
			<label for="fileupload">
				<xsl:value-of select="php:function('lang', 'Upload file')"/>
			</label>
			<div id="drop-area" class="pure-input-3-4 pure-custom">
				<div style="border: 2px dashed #ccc; padding: 20px;">
					<p>
						<xsl:value-of select="php:function('lang', 'Upload multiple files with the file dialog, or by dragging and dropping images onto the dashed region')"/>
					</p>
					<div class="fileupload-buttonbar">
						<div class="fileupload-buttons">
							<!-- The fileinput-button span is used to style the file input field as button -->
							<span class="fileinput-button pure-button">
								<span>
									<xsl:value-of select="php:function('lang', 'Add files')"/>
									<xsl:text>...</xsl:text>
								</span>
								<input id="fileupload" type="file" name="files[]" multiple="multiple">
									<xsl:attribute name="data-url">
										<xsl:value-of select="multi_upload_action"/>
									</xsl:attribute>
									<xsl:attribute name="required">
										<xsl:text>required</xsl:text>
									</xsl:attribute>
									<!--xsl:attribute name="capture">camera</xsl:attribute-->
								</input>
							</span>
							<!-- The global file processing state -->
							<span class="fileupload-process"></span>
						</div>
						<div class="fileupload-count">
							<xsl:value-of select="php:function('lang', 'Number files')"/>: 
							<span id="files-count"></span>
						</div>
						<div class="fileupload-progress" style="display:none">
							<!-- The global progress bar -->
							<div id = 'progress' class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"></div>
							<!-- The extended global progress state -->
							<div class="progress-extended">&nbsp;</div>
						</div>
					</div>
					<!-- The table listing the files available for upload/download -->
					<div class="content_upload_download">
						<div class="presentation files" style="display: inline-table;"></div>
					</div>
				</div>
			</div>
		</div>
		<input type="hidden" id="save" name="save" value=""/>
		<input type="hidden" id="apply" name="apply" value=""/>
		<input type="hidden" id="cancel" name="cancel" value=""/>
		<div class="pure-control-group">
			<xsl:variable name="lang_create_new_claim">
				<xsl:value-of select="php:function('lang', 'save')"/>
			</xsl:variable>
			<input type="button" class="pure-button pure-button-primary" name="create" value="{$lang_create_new_claim}" onClick="confirm_session('save');">
				<xsl:attribute name="title">
					<xsl:value-of select="php:function('lang', 'create new claim')"/>
				</xsl:attribute>
			</input>

			<xsl:variable name="lang_cancel">
				<xsl:value-of select="php:function('lang', 'cancel')"/>
			</xsl:variable>
			<input type="button" class="pure-button pure-button-primary" name="cancel" value="{$lang_cancel}" onclick="window.location.href='{cancel_url}'">
				<xsl:attribute name="title">
					<xsl:value-of select="php:function('lang', 'cancel')"/>
				</xsl:attribute>
			</input>
		</div>
	</form>
</xsl:template>

<!-- add / edit -->
<xsl:template xmlns:php="http://php.net/xsl" match="edit">
	<script type="text/javascript">
		function tenant_lookup()
		{
		TINY.box.show({iframe:'<xsl:value-of select="tenant_link"/>', boxid:"frameless",width:Math.round($(window).width()*0.9),height:Math.round($(window).height()*0.9),fixed:false,maskid:"darkmask",maskopacity:40, mask:true, animate:true, close: true});
		}
	</script>
	<dl>
		<xsl:choose>
			<xsl:when test="msgbox_data != ''">
				<dt>
					<xsl:call-template name="msgbox"/>
				</dt>
			</xsl:when>
		</xsl:choose>
	</dl>
	<xsl:variable name="edit_url">
		<xsl:value-of select="edit_url"/>
	</xsl:variable>
	<form ENCTYPE="multipart/form-data" class="pure-form pure-form-aligned" name="form" id="form" method="post" action="{$edit_url}">
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="tabs"/>
			<div id="general">
				<xsl:choose>
					<xsl:when test="value_claim_id!=''">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_claim_id"/>
							</label>
							<xsl:value-of select="value_claim_id"/>
						</div>
					</xsl:when>
				</xsl:choose>
				<xsl:call-template name="location_view"/>
				<xsl:choose>
					<xsl:when test="contact_phone !=''">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_contact_phone"/>
							</label>
							<xsl:value-of select="contact_phone"/>
						</div>
					</xsl:when>
				</xsl:choose>
				<xsl:choose>
					<xsl:when test="value_parent_id!=''">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_parent"/>
							</label>
							<xsl:value-of select="value_parent_id"/>
						</div>
						<xsl:for-each select="value_origin">
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="descr"/>
								</label>
								<xsl:for-each select="data">
									<a href="{link}" title="{//lang_origin_statustext}">
										<xsl:value-of select="id"/>
									</a>
									<xsl:text> </xsl:text>
								</xsl:for-each>
							</div>
						</xsl:for-each>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_name"/>
							</label>
							<xsl:value-of select="value_name"/>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_descr"/>
							</label>
							<div class="pure-custom">
							<xsl:value-of disable-output-escaping="yes" select="value_descr"/>
							</div>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_category"/>
							</label>
							<xsl:for-each select="cat_list_project" data-validation="required">
								<xsl:choose>
									<xsl:when test="selected='selected' or selected = 1">
										<xsl:value-of select="name"/>
									</xsl:when>
								</xsl:choose>
							</xsl:for-each>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_power_meter"/>
							</label>
							<xsl:value-of select="value_power_meter"/>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_charge_tenant"/>
							</label>
							<xsl:choose>
								<xsl:when test="charge_tenant='1'">
									<b>X</b>
								</xsl:when>
							</xsl:choose>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_budget"/>
							</label>
							<xsl:value-of select="value_budget"/>
							<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_reserve"/>
							</label>
							<xsl:value-of select="value_reserve"/>
							<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_reserve_remainder"/>
							</label>
							<xsl:value-of select="value_reserve_remainder"/>
							<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
							<xsl:text> </xsl:text> ( <xsl:value-of select="value_reserve_remainder_percent"/>
							<xsl:text> % )</xsl:text>
						</div>
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_actual_cost"/>
							</label>
							<xsl:value-of select="sum_workorder_actual_cost"/>
							<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
						</div>
						<div class="pure-control-group">
							<!--div id="datatable-container_0"/-->
							<div class="pure-custom" style="width: 100%;">
								<xsl:for-each select="datatable_def">
									<xsl:if test="container = 'datatable-container_0'">
										<xsl:call-template name="table_setup">
											<xsl:with-param name="container" select ='container'/>
											<xsl:with-param name="requestUrl" select ='requestUrl' />
											<xsl:with-param name="ColumnDefs" select ='ColumnDefs' />
											<xsl:with-param name="tabletools" select ='tabletools' />
											<xsl:with-param name="data" select ='data' />
											<xsl:with-param name="config" select ='config' />
										</xsl:call-template>
									</xsl:if>
								</xsl:for-each>
							</div>
						</div>
					</xsl:when>
				</xsl:choose>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_coordinator"/>
					</label>
					<xsl:for-each select="user_list">
						<xsl:choose>
							<xsl:when test="selected">
								<xsl:value-of select="name"/>
							</xsl:when>
						</xsl:choose>
					</xsl:for-each>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'take over')"/>
					</label>
					<input type="checkbox" name="values[takeover]" value="1">
						<xsl:attribute name="title">
							<xsl:value-of select="php:function('lang', 'Take over the assignment for this ticket')"/>
						</xsl:attribute>
					</input>
				</div>

				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_status"/>
					</label>
					<xsl:for-each select="status_list">
						<xsl:choose>
							<xsl:when test="selected">
								<xsl:value-of select="name"/>
							</xsl:when>
						</xsl:choose>
					</xsl:for-each>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'entry date')" />
					</label>
					<xsl:value-of select="value_entry_date"/>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'start date')" />
					</label>
					<xsl:value-of select="value_start_date"/>
				</div>

				<xsl:choose>
					<xsl:when test="value_end_date!=''">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="lang_end_date"/>
							</label>
							<xsl:value-of select="value_end_date"/>
						</div>
					</xsl:when>
				</xsl:choose>
				<div class="pure-control-group">
					<label for='claim_type'>
						<xsl:value-of select="php:function('lang', 'claim type')"/>
					</label>
					<select id="claim_type" name="values[claim_type]" required="required" class="pure-input-3-4">
						<xsl:apply-templates select="claim_types/options"/>
					</select>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_status"/>
					</label>
					<xsl:call-template name="status_select">
						<xsl:with-param name="class">pure-input-3-4</xsl:with-param>
					</xsl:call-template>
				</div>
				<div class="pure-control-group">
					<label>
						<a href="javascript:tenant_lookup()">
							<xsl:value-of select="lang_tenant"/>
						</a>
					</label>
					<input type="hidden" name="tenant_id" value="{value_tenant_id}"/>
					<input size="{size_last_name}" type="text" name="last_name" value="{value_last_name}" onClick="tenant_lookup();" readonly="readonly">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_tenant_statustext"/>
						</xsl:attribute>
					</input>
					<input size="{size_first_name}" type="text" name="first_name" value="{value_first_name}" onClick="tenant_lookup();" readonly="readonly">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_tenant_statustext"/>
						</xsl:attribute>
					</input>
				</div>
				<xsl:call-template name="b_account_form"/>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_amount"/>
					</label>
					<input type="text" name="values[amount]" value="{value_amount}" class="amount pure-input-3-4">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_amount_statustext"/>
						</xsl:attribute>
						<xsl:if test="mode='view'">
							<xsl:attribute name="readonly">
								<xsl:text>readonly</xsl:text>
							</xsl:attribute>
						</xsl:if>
					</input>
					<xsl:text> </xsl:text> [ <xsl:value-of select="currency"/> ]
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_category"/>
					</label>
					<xsl:call-template name="cat_select">
						<xsl:with-param name="class">pure-input-3-4</xsl:with-param>
					</xsl:call-template>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="lang_remark"/>
					</label>
					<textarea cols="60" rows="6" name="values[remark]" class="pure-input-3-4">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_remark_statustext"/>
						</xsl:attribute>
						<xsl:if test="mode='view'">
							<xsl:attribute name="readonly">
								<xsl:text>readonly</xsl:text>
							</xsl:attribute>
						</xsl:if>
						<xsl:value-of select="value_remark"/>
					</textarea>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'files')"/>
					</label>
					<!--div id="paging_1"> </div-->
					<div class="pure-u-md-3-4">
						<xsl:for-each select="datatable_def">
							<xsl:if test="container = 'datatable-container_1'">
								<xsl:call-template name="table_setup">
									<xsl:with-param name="container" select ='container'/>
									<xsl:with-param name="requestUrl" select ='requestUrl' />
									<xsl:with-param name="ColumnDefs" select ='ColumnDefs' />
									<xsl:with-param name="tabletools" select ='tabletools' />
									<xsl:with-param name="data" select ='data' />
									<xsl:with-param name="config" select ='config' />
								</xsl:call-template>
							</xsl:if>
						</xsl:for-each>
					</div>
				</div>
				<xsl:choose>
					<xsl:when test="value_claim_id!='' and mode='edit'">
						<xsl:call-template name="file_upload"/>
					</xsl:when>
				</xsl:choose>
				<br></br>
				<div class="pure-control-group">
					<xsl:if test="mode='edit'">
						<xsl:variable name="lang_save">
							<xsl:value-of select="lang_save"/>
						</xsl:variable>
						<input type="submit" class="pure-button pure-button-primary" name="values[save]" value="{$lang_save}" onMouseout="window.status='';return true;">
							<xsl:attribute name="onMouseover">
								<xsl:text>window.status='</xsl:text>
								<xsl:value-of select="lang_save_statustext"/>
								<xsl:text>'; return true;</xsl:text>
							</xsl:attribute>
						</input>
						<xsl:variable name="lang_apply">
							<xsl:value-of select="lang_apply"/>
						</xsl:variable>
						<input type="submit" class="pure-button pure-button-primary" name="values[apply]" value="{$lang_apply}" onMouseout="window.status='';return true;">
							<xsl:attribute name="onMouseover">
								<xsl:text>window.status='</xsl:text>
								<xsl:value-of select="lang_apply_statustext"/>
								<xsl:text>'; return true;</xsl:text>
							</xsl:attribute>
						</input>
					</xsl:if>
					<xsl:variable name="lang_cancel">
						<xsl:value-of select="lang_cancel"/>
					</xsl:variable>
					<input type="submit" class="pure-button pure-button-primary" name="values[cancel]" value="{$lang_cancel}" onMouseout="window.status='';return true;">
						<xsl:attribute name="onMouseover">
							<xsl:text>window.status='</xsl:text>
							<xsl:value-of select="lang_cancel_statustext"/>
							<xsl:text>'; return true;</xsl:text>
						</xsl:attribute>
					</input>
				</div>
				<br></br>
				<fieldset style="border: 1px solid #000;">
					<div class="pure-control-group">
						<div class="pure-custom" style="width: 100%;">
							<xsl:for-each select="datatable_def">
								<xsl:if test="container = 'datatable-container_2'">
									<xsl:call-template name="table_setup">
										<xsl:with-param name="container" select ='container'/>
										<xsl:with-param name="requestUrl" select ='requestUrl' />
										<xsl:with-param name="ColumnDefs" select ='ColumnDefs' />
										<xsl:with-param name="tabletools" select ='tabletools' />
										<xsl:with-param name="data" select ='data' />
										<xsl:with-param name="config" select ='config' />
									</xsl:call-template>
								</xsl:if>
							</xsl:for-each>
						</div>
					</div>
				</fieldset>
			</div>
		</div>
	</form>
	<script type="text/javascript">
		var property_js = '<xsl:value-of select="property_js"/>';
		var base_java_url = <xsl:value-of select="base_java_url"/>;
		var datatable = new Array();
		var myColumnDefs = new Array();

		<xsl:for-each select="datatable">
			datatable[<xsl:value-of select="name"/>] = [
			{
			values:<xsl:value-of select="values"/>,
			total_records: <xsl:value-of select="total_records"/>,
			edit_action:  <xsl:value-of select="edit_action"/>,
			is_paginator:  <xsl:value-of select="is_paginator"/>,
			<xsl:if test="rows_per_page">
				rows_per_page: "<xsl:value-of select="rows_per_page"/>",
			</xsl:if>
			<xsl:if test="initial_page">
				initial_page: "<xsl:value-of select="initial_page"/>",
			</xsl:if>
			footer:<xsl:value-of select="footer"/>
			}
			]
		</xsl:for-each>

		<xsl:for-each select="myColumnDefs">
			myColumnDefs[<xsl:value-of select="name"/>] = <xsl:value-of select="values"/>
		</xsl:for-each>
	</script>
</xsl:template>


<!-- New template-->
<xsl:template match="table_header_workorder">
	<tr class="th">
		<td class="th_text" width="4%" align="right">
			<xsl:value-of select="lang_workorder_id"/>
		</td>
		<td class="th_text" width="10%" align="right">
			<xsl:value-of select="lang_budget"/>
		</td>
		<td class="th_text" width="5%" align="right">
			<xsl:value-of select="lang_calculation"/>
		</td>
		<td class="th_text" width="10%" align="right">
			<xsl:value-of select="lang_vendor"/>
		</td>
		<td class="th_text" width="10%" align="right">
			<xsl:value-of select="lang_charge_tenant"/>
		</td>
		<td class="th_text" width="10%" align="right">
			<xsl:value-of select="lang_select"/>
		</td>
	</tr>
</xsl:template>

<!-- New template-->
<xsl:template match="workorder_budget">
	<xsl:variable name="workorder_link">
		<xsl:value-of select="//workorder_link"/>&amp;id=<xsl:value-of select="workorder_id"/>
	</xsl:variable>
	<xsl:variable name="workorder_id">
		<xsl:value-of select="workorder_id"/>
	</xsl:variable>
	<tr>
		<xsl:attribute name="class">
			<xsl:choose>
				<xsl:when test="@class">
					<xsl:value-of select="@class"/>
				</xsl:when>
				<xsl:when test="position() mod 2 = 0">
					<xsl:text>row_off</xsl:text>
				</xsl:when>
				<xsl:otherwise>
					<xsl:text>row_on</xsl:text>
				</xsl:otherwise>
			</xsl:choose>
		</xsl:attribute>
		<td align="right">
			<a href="{$workorder_link}" target="_blank">
				<xsl:value-of select="workorder_id"/>
			</a>
		</td>
		<td align="right">
			<xsl:value-of select="budget"/>
		</td>
		<td align="right">
			<xsl:value-of select="calculation"/>
		</td>
		<td align="left">
			<xsl:value-of select="vendor_name"/>
		</td>
		<td align="center">
			<xsl:choose>
				<xsl:when test="charge_tenant='1'">
					<b>x</b>
				</xsl:when>
			</xsl:choose>
			<xsl:choose>
				<xsl:when test="claimed!=''">
					<b>
						<xsl:text>[</xsl:text>
						<xsl:value-of select="claimed"/>
						<xsl:text>]</xsl:text>
					</b>
				</xsl:when>
			</xsl:choose>
		</td>
		<td align="center">
			<xsl:choose>
				<xsl:when test="selected = 1">
					<input type="checkbox" name="values[workorder][]" value="{$workorder_id}" checked="checked" onMouseout="window.status='';return true;">
						<xsl:attribute name="title">
							<xsl:value-of select="//lang_select_workorder_statustext"/>
						</xsl:attribute>
					</input>
				</xsl:when>
				<xsl:otherwise>
					<input type="checkbox" name="values[workorder][]" value="{$workorder_id}" onMouseout="window.status='';return true;">
						<xsl:attribute name="title">
							<xsl:value-of select="//lang_select_workorder_statustext"/>
						</xsl:attribute>
					</input>
				</xsl:otherwise>
			</xsl:choose>
		</td>
	</tr>
</xsl:template>
<!-- New template-->
<xsl:template match="options">
	<option value="{id}">
		<xsl:if test="selected != 0">
			<xsl:attribute name="selected" value="selected"/>
		</xsl:if>
		<xsl:if test="disabled = 1">
			<xsl:attribute name="disabled" value="disabled"/>
		</xsl:if>
		<xsl:value-of disable-output-escaping="yes" select="name"/>
	</option>
</xsl:template>