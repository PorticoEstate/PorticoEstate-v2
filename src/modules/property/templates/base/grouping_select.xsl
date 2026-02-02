
<!-- $Id$ -->
<xsl:template name="grouping_select">
	<xsl:param name="class" />
		<xsl:variable name="lang_grouping_statustext">
			<xsl:value-of select="lang_grouping_statustext"/>
		</xsl:variable>
		<xsl:variable name="select_name">
			<xsl:value-of select="select_grouping"/>
		</xsl:variable>
		<select name="{$select_name}" title="{$lang_grouping_statustext}" >
			<xsl:choose>
				<xsl:when test="$class != ''">
					<xsl:attribute name="class">
						<xsl:value-of select="$class"/>
					</xsl:attribute>
				</xsl:when>
				<xsl:otherwise>
					<xsl:attribute name="class">
						<xsl:text>pure-input-1-2</xsl:text>
					</xsl:attribute>
				</xsl:otherwise>
			</xsl:choose>
			<option value="">
				<xsl:value-of select="lang_no_grouping"/>
			</option>
			<xsl:apply-templates select="grouping_list"/>
		</select>
</xsl:template>

<!-- New template-->
<xsl:template match="grouping_list">
		<xsl:variable name="id">
			<xsl:value-of select="id"/>
		</xsl:variable>
		<xsl:choose>
			<xsl:when test="selected">
				<option value="{$id}" selected="selected">
					<xsl:value-of disable-output-escaping="yes" select="name"/>
				</option>
			</xsl:when>
			<xsl:otherwise>
				<option value="{$id}">
					<xsl:value-of disable-output-escaping="yes" select="name"/>
				</option>
			</xsl:otherwise>
		</xsl:choose>
</xsl:template>
