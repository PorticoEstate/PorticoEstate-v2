<!-- $Id$ -->
<xsl:template match="section" xmlns:php="http://php.net/xsl">
	<xsl:param name="template_set"/>

	<xsl:choose>
		<xsl:when test="msgbox_data != ''">
			<xsl:call-template name="msgbox"/>
		</xsl:when>
	</xsl:choose>
	
	<xsl:variable name="tab_selected">
		<xsl:value-of select="tab_selected"/>
	</xsl:variable>
	
	<div class="frontend_body">
		<div class="pure-form pure-form-aligned">
			<div>
				<xsl:if test="$template_set != 'bootstrap'">
					<xsl:attribute name="id">tab-content</xsl:attribute>
					<xsl:value-of disable-output-escaping="yes" select="tabs" />
				</xsl:if>
				<div id="{$tab_selected}">
					<xsl:choose>
						<xsl:when test="normalize-space(//header/selected_location) != ''">
							<div class="toolbar-container">
								<div>
									<xsl:for-each select="filters">
										<xsl:variable name="name">
											<xsl:value-of select="name"/>
										</xsl:variable>
										<select id="{$name}" name="{$name}">
											<xsl:for-each select="list">
												<xsl:variable name="id">
													<xsl:value-of select="id"/>
												</xsl:variable>
												<xsl:choose>
													<xsl:when test="id = 'NEW'">
														<option value="{$id}" selected="selected">
															<xsl:value-of select="name"/>
														</option>
													</xsl:when>
													<xsl:otherwise>
														<xsl:choose>
															<xsl:when test="selected = 'selected'">
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
													</xsl:otherwise>
												</xsl:choose>
											</xsl:for-each>
										</select>									
									</xsl:for-each>
								</div>
							</div>
							<div class="tickets">
								<xsl:for-each select="datatable_def">
									<xsl:if test="container = 'datatable-container_0'">
										<xsl:call-template name="table_setup">
											<xsl:with-param name="container" select ='container'/>
											<xsl:with-param name="requestUrl" select ='requestUrl' />
											<xsl:with-param name="ColumnDefs" select ='ColumnDefs' />
											<xsl:with-param name="tabletools" select ='tabletools' />
											<xsl:with-param name="data" select ='data' />
											<xsl:with-param name="config" select ='config' />
											<xsl:with-param name="class" select="'table table-striped table-bordered'" />
										</xsl:call-template>
									</xsl:if>
								</xsl:for-each>
							</div>
						</xsl:when>
						<xsl:otherwise>
							<div class="tickets">
								<xsl:value-of select="php:function('lang', 'no_buildings')"/>
							</div>
						</xsl:otherwise>
					</xsl:choose>
				</div>
				<xsl:value-of disable-output-escaping="yes" select="tabs_content" />	
			</div>
		</div>
	</div>
	<script type="text/javascript" class="init">

		<xsl:for-each select="filters">
			<xsl:if test="type = 'filter'">
				$('select#<xsl:value-of select="name"/>').change( function() 
				{
				<xsl:value-of select="extra"/>
				filterData('<xsl:value-of select="name"/>', $(this).val());
				});
			</xsl:if>
		</xsl:for-each>

		<![CDATA[
			function filterData(param, value)
			{
				oTable0.dataTableSettings[0]['ajax']['data'][param] = value;
				oTable0.api().draw();
			}
		]]>

	</script>
</xsl:template>

<xsl:template match="lightbox_name" xmlns:php="http://php.net/xsl">
</xsl:template>

