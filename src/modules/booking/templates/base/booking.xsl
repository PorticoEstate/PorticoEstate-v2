<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<xsl:call-template name="jquery_phpgw_i18n"/>
	<xsl:call-template name="msgbox"/>
	<form action="" method="POST" id='form'  class="pure-form pure-form-aligned" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="booking/tabs"/>
			<div id="booking" class="booking-container">
				<fieldset>
					<h1>#<xsl:value-of select="booking/id"/> (<xsl:value-of select="booking/activity_name"/>)</h1>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Application')"/>
						</label>
						<xsl:if test="booking/application_id != ''">
							<a href="{booking/application_link}">#<xsl:value-of select="booking/application_id"/></a>
						</xsl:if>
					</div>
					<div class="pure-control-group">
						<label>
							<input type="checkbox" value="1" name="active" >
								<xsl:if test="booking/active=1">
									<xsl:attribute name="checked">checked</xsl:attribute>
								</xsl:if>
								<xsl:attribute name="disabled">disabled</xsl:attribute>
							</input>
						</label>
						<xsl:value-of select="php:function('lang', 'active')"/>
					</div>
					<div class="pure-control-group">
						<label>
							<input type="checkbox" value="1" name="skip_bas" >
								<xsl:if test="booking/skip_bas=1">
									<xsl:attribute name="checked">checked</xsl:attribute>
								</xsl:if>
								<xsl:attribute name="disabled">disabled</xsl:attribute>
							</input>
						</label>
						<xsl:value-of select="php:function('lang', 'skip bas')"/>
					</div>

					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'From')" />
						</label>
						<xsl:value-of select="php:function('pretty_timestamp', booking/from_)"/>
					</div>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'To')" />
						</label>
						<xsl:value-of select="php:function('pretty_timestamp', booking/to_)"/>
					</div>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Cost')" />
						</label>
						<xsl:value-of select="booking/cost"/>
					</div>
					<div>
						<div class="heading">
							<!--<legend>-->
							<h3>
								<xsl:value-of select="php:function('lang', 'History of Cost (%1)', count(cost_history/author))" />
							</h3>
							<!--</legend>-->
						</div>
						<xsl:for-each select="cost_history[author]">
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('pretty_timestamp', time)"/>: <xsl:value-of select="author"/>
								</label>
								<span>
									<xsl:value-of select="comment"/>
									<xsl:text> :: </xsl:text>
									<xsl:value-of select="cost"/>
								</span>
							</div>
						</xsl:for-each>
					</div>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Season')" />
						</label>
						<xsl:value-of select="booking/season_name"/>
					</div>
					<div class="pure-control-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Group')" />
						</label>
						<xsl:value-of select="booking/group_name"/>
					</div>
					<div class="pure-control-group">
						<label style="vertical-align:top;">
							<xsl:value-of select="php:function('lang', 'Resources')" />
						</label>
						<div id="resources_container" style="display:inline-block;"></div>
					</div>
					<div class="pure-control-group">
						<label style="vertical-align:top;">
							<xsl:value-of select="php:function('lang', 'participants')" />
						</label>
						<div id="participant_container" style="display:inline-block;"></div>
					</div>
					<div class="pure-control-group">
						<label for="field_sms_content">
							<xsl:value-of select="php:function('lang', 'SMS')" />
						</label>
						<textarea rows="5" id="field_sms_content" name="sms_content" class="pure-input-1-2" >
						</textarea>
					</div>
					<div class="pure-controls">
						<label for="send_sms" class="pure-checkbox">
							<input type="checkbox" id="send_sms" name="send_sms" value="1"/>
							<xsl:value-of select="php:function('lang', 'send SMS')" />
						</label>
						<button type="submit" class="pure-button pure-button-primary">
							<xsl:value-of select="php:function('lang', 'send SMS')" />
						</button>
					</div>

				</fieldset>
			</div>
		</div>
	</form>
	<div class="form-buttons">
		<xsl:if test="booking/permission/write">
			<button class="pure-button pure-button-primary">
				<xsl:attribute name="onclick">window.location.href="<xsl:value-of select="booking/edit_link"/>"</xsl:attribute>
				<xsl:value-of select="php:function('lang', 'Edit')" />
			</button>
			<button class="pure-button pure-button-primary">
				<xsl:attribute name="onclick">window.location.href="<xsl:value-of select="booking/delete_link"/>"</xsl:attribute>
				<xsl:value-of select="php:function('lang', 'Delete booking')" />
			</button>
		</xsl:if>
	</div>
	<script type="text/javascript">
		var resourceIds = '<xsl:value-of select="booking/resource_ids"/>';
		var booking_id = '<xsl:value-of select="booking/id"/>';
		var lang = <xsl:value-of select="php:function('js_lang', 'Name', 'Resource Type', 'phone', 'email', 'quantity', 'from', 'to')"/>;
    <![CDATA[
			var resourcesURL = phpGWLink('index.php', {menuaction: 'booking.uiresource.index', sort:'name', length:-1}, true) +'&' + resourceIds;
			var participantURL = phpGWLink('index.php', {menuaction:'booking.uiparticipant.index', sort:'phone', filter_reservation_id: booking_id, filter_reservation_type: 'booking', length:-1}, true);
      ]]>
		var colDefsResources = [{key: 'name', label: lang['Name'], formatter: genericLink}, {key: 'rescategory_name', label: lang['Resource Type']}];
		createTable('resources_container',resourcesURL,colDefsResources, '', 'pure-table pure-table-bordered');

		var colDefsParticipantURL = [
		{key: 'phone', label: lang['phone']},
		{key: 'quantity', label: lang['quantity']},
		{key: 'from_', label: lang['from']},
		{key: 'to_', label: lang['to']}
		];

		var paginatorTableparticipant = new Array();
		paginatorTableparticipant.limit = 10;
		createPaginatorTable('participant_container', paginatorTableparticipant);

		createTable('participant_container', participantURL, colDefsParticipantURL, '', 'pure-table pure-table-bordered', paginatorTableparticipant);


	</script>
</xsl:template>
