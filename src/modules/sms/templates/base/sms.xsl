<!-- $Id$ -->

<xsl:template name="app_data">
	<xsl:choose>
		<xsl:when test="send">
			<xsl:apply-templates select="send"/>
		</xsl:when>
		<xsl:when test="send_group">
			<xsl:apply-templates select="send_group"/>
		</xsl:when>
		<xsl:when test="list_outbox">
			<xsl:apply-templates select="list_outbox"/>
		</xsl:when>
		<xsl:when test="daemon_manual">
			<xsl:apply-templates select="daemon_manual"/>
		</xsl:when>
		<xsl:otherwise>
			<xsl:apply-templates select="list_inbox"/>
		</xsl:otherwise>
	</xsl:choose>
</xsl:template>
	
<xsl:template match="list_inbox">
	<xsl:choose>
		<xsl:when test="menu != ''">
			<xsl:apply-templates select="menu"/>
		</xsl:when>
	</xsl:choose>
	<dl>
		<xsl:choose>
			<xsl:when test="msgbox_data != ''">
				<dt>
					<xsl:call-template name="msgbox"/>
				</dt>
			</xsl:when>
		</xsl:choose>
	</dl>
	<table width="100%" cellpadding="2" cellspacing="2" align="center">
		<tr>
			<td align="right">
				<xsl:call-template name="search_field"/>
			</td>
		</tr>
		<tr>
			<td colspan="3" width="100%">
				<xsl:call-template name="nextmatchs"/>
				<!--	<xsl:with-param name="nextmatchs_params"/>
				</xsl:call-template> -->
			</td>
		</tr>
	</table>
	<table class="pure-table pure-table-bordered">
		<thead>
			<xsl:apply-templates select="table_header_inbox"/>
		</thead>
		<tbody>
			<xsl:apply-templates select="values_inbox"/>
		</tbody>
	</table>

	<xsl:choose>
		<xsl:when test="table_add != ''">
			<xsl:apply-templates select="table_add"/>
		</xsl:when>
	</xsl:choose>
</xsl:template>

<xsl:template match="table_header_inbox">
	<xsl:variable name="sort_entry_time">
		<xsl:value-of select="sort_entry_time"/>
	</xsl:variable>
	<xsl:variable name="sort_sender">
		<xsl:value-of select="sort_sender"/>
	</xsl:variable>
	<tr>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_id"/>
		</th>
		<th style="width:10%; text-align:left;">
			<xsl:value-of select="lang_user"/>
		</th>
		<th style="width:5%; text-align:left;">
			<a href="{$sort_sender}">
				<xsl:value-of select="lang_sender"/>
			</a>
		</th>
		<th style="width:15%; text-align:left;">
			<a href="{$sort_entry_time}">
				<xsl:value-of select="lang_entry_time"/>
			</a>
		</th>
		<th style="width:50%; text-align:left;">
			<xsl:value-of select="lang_message"/>
		</th>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_answer"/>
		</th>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_delete"/>
		</th>
	</tr>
</xsl:template>

<xsl:template match="values_inbox">
	<xsl:variable name="lang_delete_sms_text">
		<xsl:value-of select="lang_delete_place_text"/>
	</xsl:variable>
	<xsl:variable name="lang_answer_sms_text">
		<xsl:value-of select="lang_answer_place_text"/>
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
			<xsl:value-of select="id"/>
		</td>
		<td align="right">
			<xsl:value-of select="user"/>
		</td>
		<td align="right">
			<xsl:value-of select="sender"/>
		</td>

		<td align="right">
			<xsl:value-of select="entry_time"/>
		</td>
		<td align="left">
			<xsl:value-of select="message"/>
		</td>

		<td align="center">
			<xsl:variable name="link_answer">
				<xsl:value-of select="link_answer"/>
			</xsl:variable>
			<a href="{$link_answer}" onMouseover="window.status='{$lang_answer_sms_text}';return true;" onMouseout="window.status='';return true;">
				<xsl:value-of select="text_answer"/>
			</a>
		</td>
		<td align="center">
			<xsl:variable name="link_delete">
				<xsl:value-of select="link_delete"/>
			</xsl:variable>
			<a href="{$link_delete}" onMouseover="window.status='{$lang_delete_sms_text}';return true;" onMouseout="window.status='';return true;">
				<xsl:value-of select="text_delete"/>
			</a>
		</td>
	</tr>
</xsl:template>

<xsl:template match="list_outbox">
	<xsl:choose>
		<xsl:when test="menu != ''">
			<xsl:apply-templates select="menu"/>
		</xsl:when>
	</xsl:choose>
	<dl>
		<xsl:choose>
			<xsl:when test="msgbox_data != ''">
				<dt>
					<xsl:call-template name="msgbox"/>
				</dt>
			</xsl:when>
		</xsl:choose>
	</dl>
	<table width="100%" cellpadding="2" cellspacing="2" align="center">
		<tr>
			<td align="right">
				<xsl:call-template name="search_field"/>
			</td>
		</tr>
		<tr>
			<td colspan="3" width="100%">
				<xsl:call-template name="nextmatchs"/>
				<!--	<xsl:with-param name="nextmatchs_params"/>
				</xsl:call-template> -->
			</td>
		</tr>
	</table>
	<table class="pure-table pure-table-bordered">
		<thead>
			<xsl:apply-templates select="table_header_outbox"/>
		</thead>
		<tbody>
			<xsl:apply-templates select="values_outbox"/>
		</tbody>
	</table>
	<xsl:choose>
		<xsl:when test="table_add != ''">
			<xsl:apply-templates select="table_add"/>
		</xsl:when>
	</xsl:choose>
</xsl:template>

<xsl:template match="table_header_outbox">
	<xsl:variable name="sort_entry_time">
		<xsl:value-of select="sort_entry_time"/>
	</xsl:variable>
	<tr>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_id"/>
		</th>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_user"/>
		</th>

		<th style="width:5%; text-align:left;">
			<a href="{$sort_entry_time}">
				<xsl:value-of select="lang_entry_time"/>
			</a>
		</th>

		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_receiver"/>
		</th>
		<th style="width:50%; text-align:left;">
			<xsl:value-of select="lang_message"/>
		</th>

		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_status"/>
		</th>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_group"/>
		</th>
		<th style="width:5%; text-align:left;">
			<xsl:value-of select="lang_delete"/>
		</th>
	</tr>
</xsl:template>

<xsl:template match="values_outbox">
	<xsl:variable name="lang_delete_sms_text">
		<xsl:value-of select="lang_delete_place_text"/>
	</xsl:variable>

	<tr>
		<td align="right">
			<xsl:value-of select="id"/>
		</td>
		<td align="right">
			<xsl:value-of select="user"/>
		</td>

		<td align="right">
			<xsl:value-of select="entry_time"/>
		</td>
		<td align="right">
			<xsl:value-of select="receiver"/>
		</td>
		<td align="left">
			<xsl:value-of select="message"/>
		</td>
		<td align="right">
			<xsl:value-of select="status"/>
		</td>
		<td align="right">
			<xsl:value-of select="group"/>
		</td>

		<td align="center">
			<xsl:variable name="link_delete">
				<xsl:value-of select="link_delete"/>
			</xsl:variable>
			<a href="{$link_delete}" title="{$lang_delete_sms_text}">
				<xsl:value-of select="text_delete"/>
			</a>
		</td>
	</tr>
</xsl:template>


<xsl:template match="table_add">
	<div class="pure-controls">
		<xsl:variable name="send_action">
			<xsl:value-of select="send_action"/>
		</xsl:variable>
		<xsl:variable name="lang_send">
			<xsl:value-of select="lang_send"/>
		</xsl:variable>
		<form method="post" action="{$send_action}">
			<input type="submit" name="add" value="{$lang_send}" class="pure-button pure-button-primary">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_send_statustext"/>
				</xsl:attribute>
			</input>
		</form>
		<!--
		<xsl:variable name="send_group_action">
			<xsl:value-of select="send_group_action"/>
		</xsl:variable>
		<xsl:variable name="lang_send_group">
			<xsl:value-of select="lang_send_group"/>
		</xsl:variable>
		<form method="post" action="{$send_group_action}">
			<input type="submit" name="add" value="{$lang_send_group}" class="pure-button pure-button-primary">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_send_group_statustext"/>
				</xsl:attribute>
			</input>
		</form>
		-->
	</div>
</xsl:template>


<!-- send -->
<xsl:template match="send" xmlns:php="http://php.net/xsl">
	<dl>
		<xsl:choose>
			<xsl:when test="msgbox_data != ''">
				<dt>
					<xsl:call-template name="msgbox"/>
				</dt>
			</xsl:when>
		</xsl:choose>
	</dl>
	<xsl:variable name="form_action">
		<xsl:value-of select="form_action"/>
	</xsl:variable>
	<form name ="fm_sendsms" id="fm_sendsms" method="post" action="{$form_action}" class="pure-form pure-form-aligned">
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="lang_from"/>
				<xsl:text>: </xsl:text>
				<xsl:value-of select="value_sms_from"/>
			</label>
		</div>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="lang_to"/>
			</label>
			<input type="text" name="p_num_text" value="{value_p_num}" class="pure-input-3-4">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_to_status_text"/>
				</xsl:attribute>
			</input>
		</div>
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="lang_message"/>
			</label>
			<textarea class="pure-input-3-4" cols="39" rows="5" name="message" id="ta_sms_content" onKeyUp="javascript: SmsCountKeyUp({value_max_length});" onKeyDown="javascript: SmsCountKeyDown({value_max_length});" wrap="virtual">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_message_status_text"/>
				</xsl:attribute>
				<xsl:value-of select="value_message"/>
			</textarea>
		</div>
		
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="lang_character_left"/>
			</label>
			<input type="text" readonly = "readonly" size="3" maxlength="3" name="charNumberLeftOutput" id="charNumberLeftOutput" value="{value_max_length}" >
			</input>
		</div>

		<!--
					<div class="pure-control-group">
						<label>
							<input type="checkbox" name="msg_flash" value="on">
							</input>
							<xsl:text> </xsl:text>
							<xsl:value-of select="lang_send_as_flash"/>
						</label>
					</div>
					<div class="pure-control-group">
						<label>
							<input type="checkbox" name="msg_unicode" value="on">
							</input>
							<xsl:text> </xsl:text>
							<xsl:value-of select="lang_send_as_unicode"/>
						</label>
					</div>
		-->
		<div class="pure-control-group">
			<xsl:variable name="lang_save">
				<xsl:value-of select="php:function('lang', 'send sms')" />
			</xsl:variable>
			<input type="submit" name="values[save]" value="{$lang_save}" class="pure-button pure-button-primary">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_save_status_text"/>
				</xsl:attribute>
			</input>
			<xsl:variable name="lang_cancel">
				<xsl:value-of select="lang_cancel"/>
			</xsl:variable>
			<input type="submit" name="values[cancel]" value="{$lang_cancel}" class="pure-button pure-button-primary">
				<xsl:attribute name="title">
					<xsl:value-of select="lang_cancel_status_text"/>
				</xsl:attribute>
			</input>
		</div>
	</form>
</xsl:template>

<xsl:template match="daemon_manual">
	<xsl:choose>
		<xsl:when test="menu != ''">
			<xsl:apply-templates select="menu"/>
		</xsl:when>
	</xsl:choose>
	<table width="100%" cellpadding="2" cellspacing="2" align="center">
		<xsl:choose>
			<xsl:when test="msgbox_data != ''">
				<tr>
					<td align="left" colspan="3">
						<xsl:call-template name="msgbox"/>
					</td>
				</tr>
			</xsl:when>
		</xsl:choose>
	</table>
</xsl:template>

