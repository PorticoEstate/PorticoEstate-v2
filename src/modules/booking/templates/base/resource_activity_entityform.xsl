<xsl:template match="data">
	<xsl:choose>
		<xsl:when test="edit">
			<xsl:call-template name="jquery_phpgw_i18n" />
			<xsl:apply-templates select="edit" />
		</xsl:when>
		<xsl:when test="show">
			<xsl:apply-templates select="show" />
		</xsl:when>
	</xsl:choose>
</xsl:template>

<xsl:template match="edit" xmlns:php="http://php.net/xsl">
	<xsl:call-template name="msgbox" />
	
	<form action="" method="POST" id='form' class="pure-form pure-form-stacked" name="form">
		<input type="hidden" name="tab" value="" />
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="tabs" />
			<div class="tab_content" id="entityform_new">
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'name')" />
					</label>
					<input id="field_name" name="name" value="{entityform/name}" required="required" class="pure-u-1 pure-u-sm-1-2 pure-u-md-1">
						<xsl:attribute name="data-validation">
							<xsl:text>required</xsl:text>
						</xsl:attribute>
						<xsl:attribute name="data-validation-error-msg">
							<xsl:value-of select="php:function('lang', 'Please enter a name')" />
						</xsl:attribute>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<input type="checkbox" value="1" name="active" id="field_active">
							<xsl:if test="entityform/active=1">
								<xsl:attribute name="checked">checked</xsl:attribute>
							</xsl:if>
						</input>
						<xsl:value-of select="php:function('lang', 'Active')" />
					</label>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Activities')" />
					</label>
					<select id="field_activities" name="activities[]" multiple="" required="required" class="pure-u-1 pure-u-sm-1-2 pure-u-md-1">
						<xsl:apply-templates select="activities/options" />
					</select>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Building')" />
					</label>
					<input id="field_building_id" name="building_id" type="hidden">
						<xsl:attribute name="value">
							<xsl:value-of select="entityform/building_id" />
						</xsl:attribute>
					</input>
					<input id="field_building_name" name="building_name" type="text" class="pure-u-1 pure-u-sm-1-2 pure-u-md-1">
						<xsl:attribute name="value">
							<xsl:value-of select="entityform/building_name" />
						</xsl:attribute>
					</input>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Resources')" />
					</label>
					<input type="hidden" data-validation="application_resources">
						<xsl:attribute name="data-validation-error-msg">
							<xsl:value-of select="php:function('lang', 'Please choose at least 1 resource')" />
						</xsl:attribute>
					</input>
					<div id="resources_container">
						<span class="select_first_text">
							<xsl:value-of select="php:function('lang', 'Select a building first')" />
						</span>
					</div>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Entities')" />
					</label>
					<select id="field_entities" name="entities" class="pure-u-1 pure-u-sm-1-2 pure-u-md-1">
						<option>
							<xsl:value-of select="php:function('lang', 'Select an entity type')" />
						</option>
						<xsl:apply-templates select="entities/options" />
					</select>
				</div>
				<div class="pure-control-group">
					<label>
						<xsl:value-of select="php:function('lang', 'Categories')" />
					</label>
					<div id="categories_container">
						<span class="select_first_text">
							<xsl:value-of select="php:function('lang', 'Select an entity type first')" />
						</span>
						<select id="field_category" name="location_id" class="pure-u-1 pure-u-sm-1-2 pure-u-md-1">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter a name')" />
							</xsl:attribute>
							<xsl:apply-templates select="categories/options" />
						</select>
					</div>
				</div>
			</div>
		</div>
		<div class="form_buttons">
			<button type="submit" name="save" class="pure-button pure-button-primary">
				<xsl:value-of select="php:function('lang', 'Save')" />
			</button>
			<a class="cancel pure-button">
				<xsl:attribute name="href">
					<xsl:value-of select="cancel_link" />
				</xsl:attribute>
				<xsl:value-of select="php:function('lang', 'Cancel')" />
			</a>
		</div>
	</form>
	<script type="text/javascript">
		var template_set = '<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|template_set')" />';
		var date_format = '<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />';
		var lang = 	<xsl:value-of select="php:function('js_lang', 'Name', 'From', 'To', 'Resource Type', 'Select', 'Selected', 'Delete', 'Activities')"/>;
		var initialSelection =<xsl:value-of select="resources_json"/>;
	</script>
</xsl:template>

<xsl:template match="show" xmlns:php="http://php.net/xsl">
	<xsl:call-template name="msgbox" />
	<div class="pure-g">
		<div class="pure-u-1 pure-u-md-1-2">
			<table class="pure-table pure-table-bordered">
				<tbody>
					<tr>
						<th><xsl:value-of select="php:function('lang', 'Name')" /></th>
						<td><xsl:value-of select="entityform/name" /></td>
					</tr>
					<tr>
						<th><xsl:value-of select="php:function('lang', 'Active')" /></th>
						<td>
							<xsl:choose>
								<xsl:when test="entityform/active = 1">
									<xsl:value-of select="php:function('lang', 'yes')" />
								</xsl:when>
								<xsl:otherwise>
									<xsl:value-of select="php:function('lang', 'no')" />
								</xsl:otherwise>
							</xsl:choose>
						</td>
					</tr>
					<tr>
						<th><xsl:value-of select="php:function('lang', 'Building')" /></th>
						<td><xsl:value-of select="entityform/building_name" /></td>
					</tr>
					<tr>
						<th><xsl:value-of select="php:function('lang', 'Resources')" /></th>
						<td><xsl:value-of select="entityform/resource_names" /></td>
					</tr>
					<tr>
						<th><xsl:value-of select="php:function('lang', 'Activities')" /></th>
						<td><xsl:value-of select="entityform/activity_names" /></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
	<div class="form_buttons">
		<a class="pure-button pure-button-primary">
			<xsl:attribute name="href"><xsl:value-of select="edit_link" /></xsl:attribute>
			<xsl:value-of select="php:function('lang', 'Edit')" />
		</a>
		<a class="cancel pure-button">
			<xsl:attribute name="href"><xsl:value-of select="cancel_link" /></xsl:attribute>
			<xsl:value-of select="php:function('lang', 'Cancel')" />
		</a>
	</div>
</xsl:template>

<xsl:template match="options">
	<option value="{id}">
		<xsl:if test="selected = 1">
			<xsl:attribute name="selected" value="selected" />
		</xsl:if>
		<xsl:value-of disable-output-escaping="yes" select="name" />
	</option>
</xsl:template>