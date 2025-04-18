<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<!--div class="close" style="float: right"><a onclick="schedule.closeOverlay(); return false" style="cursor:pointer;"><xsl:value-of select="php:function('lang', 'Close')"/></a></div-->
	<h3>
		<xsl:value-of select="php:function('lang', 'Booking')"/> #<xsl:value-of select="booking/id"/>
	</h3>
	<div class="pure-form pure-form-aligned">
		<xsl:if test="booking/application_id!=''">
			<div class="pure-control-group">
				<label>
					<xsl:value-of select="php:function('lang', 'Application')"/>
				</label>
				<a href="{booking/application_link}">#<xsl:value-of select="booking/application_id"/></a>
			</div>
		</xsl:if>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="php:function('lang', 'Activity')"/>
			</label>
			<xsl:value-of select="booking/activity_name"/>
		</div>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="php:function('lang', 'Where')"/>
			</label>
			<a href="{booking/building_link}">
				<xsl:value-of select="booking/resources[position()=1]/building_name"/>
			</a>
			(<xsl:value-of select="booking/resource_info"/>)
		</div>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="php:function('lang', 'When')"/>
			</label>
			<xsl:value-of select="booking/when"/>
		</div>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="php:function('lang', 'Who')"/>
			</label>
			<a href="{booking/org_link}">
				<xsl:value-of select="booking/group/organization_name"/>
			</a>:
			<a href="{booking/group_link}">
				<xsl:value-of select="booking/group/name"/>
			</a>
		</div>
	</div>
	<xsl:if test="booking/edit_link">
		<div class="actions">
			<button onclick="location.href='{booking/edit_link}'" class="pure-button pure-button-primary">
				<xsl:value-of select="php:function('lang', 'Edit booking')"/>
			</button>
			<button onclick="location.href='{booking/delete_link}'" class="pure-button pure-button-primary">
				<xsl:value-of select="php:function('lang', 'Delete booking')"/>
			</button>
		</div>
	</xsl:if>
</xsl:template>
