
<!-- $Id$ -->
<xsl:template name="b_account_form">
	<xsl:param name="class" />
	<xsl:apply-templates select="b_account_data">
		<xsl:with-param name="class">
			<xsl:value-of select="$class"/>
		</xsl:with-param>
	</xsl:apply-templates>
</xsl:template>

<!-- New template-->
<xsl:template match="b_account_data" xmlns:php="http://php.net/xsl">
	<xsl:param name="class" />
	<script type="text/javascript">
		function b_account_lookup()
		{
		TINY.box.show({iframe:'<xsl:value-of select="b_account_link"/>', boxid:"frameless",width:Math.round($(window).width()*0.9),height:Math.round($(window).height()*0.9),fixed:false,maskid:"darkmask",maskopacity:40, mask:true, animate:true, close: true});
		}
	</script>
	<xsl:choose>
		<xsl:when test="disabled='1'">
			<div class="pure-control-group">
				<label>
					<xsl:value-of select="lang_b_account"/>
				</label>
				<input size="9" type="text" value="{value_b_account_id}" readonly="readonly"/>
				<input size="30" type="text" value="{value_b_account_name}" readonly="readonly"/>
				<input size="9" type="hidden" name="b_account_id" value="{value_b_account_id}" readonly="readonly"/>
				<input size="30" type="hidden" name="b_account_name" value="{value_b_account_name}" readonly="readonly"/>
			</div>
		</xsl:when>
		<xsl:otherwise>
			<div class="pure-control-group">
				<label>
					<a href="javascript:b_account_lookup()" title="{lang_select_b_account_help}">
						<xsl:value-of select="lang_b_account"/>
					</a>
				</label>
				<div class="{$class} pure-custom">
					<input size="9" type="text" id="b_account_id" name="b_account_id" value="{value_b_account_id}" class ="pure-u-1-5">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_select_b_account_help"/>
						</xsl:attribute>
						<xsl:if test="required='1'">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please select a budget account !')"/>
							</xsl:attribute>
						</xsl:if>
					</input>
					<input size="30" type="text" name="b_account_name" value="{value_b_account_name}" onClick="b_account_lookup();" readonly="readonly" class ="pure-u-4-5">
						<xsl:attribute name="title">
							<xsl:value-of select="lang_select_b_account_help"/>
						</xsl:attribute>
					</input>
				</div>
			</div>
		</xsl:otherwise>
	</xsl:choose>
</xsl:template>
