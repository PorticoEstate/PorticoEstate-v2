
<!-- $Id: tts.xsl 15283 2016-06-14 09:21:39Z sigurdne $ -->

<xsl:template match="data">
	<xsl:choose>
		<xsl:when test="navigate">
			<xsl:apply-templates select="navigate"/>
		</xsl:when>
	</xsl:choose>
	<xsl:call-template name="jquery_phpgw_i18n"/>
</xsl:template>

<!-- navigate -->
<xsl:template xmlns:php="http://php.net/xsl" match="navigate">

	<style>
		.card .btn {
		z-index: 1;
		}
	</style>
	<div class="container">
		<div class="row mt-4">
			<xsl:for-each select="sub_menu">
				<div class="col-4 mb-3">
					<a href="{url}" class="text-secondary">
						<div class="card h-100 mb-2">
							<div class="card-block text-center">
								<h1 class="p-3">
									<i class="{icon} text-secondary"></i>
								</h1>
							</div>
							<div class="card-footer text-center">
								<xsl:value-of select="text"/>
							</div>
						</div>
					</a>
				</div>
			</xsl:for-each>
		</div>

	</div>
</xsl:template>
