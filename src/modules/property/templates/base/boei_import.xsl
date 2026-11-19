<xsl:template match="data">
	<xsl:apply-templates select="boei"/>
</xsl:template>

<xsl:template match="boei">
	<style>
		.boei-import { max-width: 70rem; overflow-wrap: anywhere; }
		.boei-import .boei-card { border: 1px solid #bbb; padding: 1rem; margin-block: 1rem; min-width: 0; }
		.boei-import .boei-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: .75rem; }
		.boei-import input { box-sizing: border-box; max-width: 100%; }
		.boei-import .boei-fields input { width: 100%; }
		.boei-import .boei-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-block-start: 1rem; }
		.boei-import button { white-space: normal; }
		.boei-import .boei-error { border: 2px solid #a00; padding: .75rem; }
	</style>
	<div class="boei-import pure-form pure-form-stacked">
		<h2><xsl:value-of select="labels/title"/></h2>
		<p><xsl:value-of select="labels/instructions"/></p>
		<xsl:if test="error"><p class="boei-error" role="alert"><xsl:value-of select="error"/></p></xsl:if>
		<xsl:if test="message"><p role="status"><xsl:value-of select="message"/></p></xsl:if>
		<form method="get" action="">
			<input type="hidden" name="menuaction" value="property.uianalyze_location.boei"/>
			<label for="boei-loc1"><xsl:value-of select="labels/object"/> (loc1)</label>
			<input id="boei-loc1" name="loc1" value="{loc1}" maxlength="4"/>
			<button class="pure-button" type="submit"><xsl:value-of select="labels/filter"/></button>
		</form>
		<form method="post" action="{action_url}" class="boei-actions">
			<input type="hidden" name="token" value="{token}"/>
			<button class="pure-button" name="action" value="refresh" type="submit"><xsl:value-of select="labels/refresh"/></button>
		</form>
		<xsl:if test="not(objects)"><p><xsl:value-of select="labels/empty"/></p></xsl:if>
		<xsl:for-each select="objects">
			<section class="boei-card">
				<h3><xsl:value-of select="../labels/object"/><xsl:text> </xsl:text><xsl:value-of select="objekt_id"/> - <xsl:value-of select="navn"/></h3>
				<p><xsl:value-of select="../labels/status"/>: <xsl:value-of select="status_label"/></p>
				<xsl:if test="source_present = 0"><p role="alert"><xsl:value-of select="../labels/source_missing"/></p></xsl:if>
				<xsl:choose>
					<xsl:when test="../loc1 = ''">
						<p class="boei-completion">
							<xsl:value-of select="../labels/completion"/><xsl:text>: </xsl:text>
							<strong><xsl:value-of select="completed_units"/> / <xsl:value-of select="total_units"/></strong>
							<xsl:if test="total_units &gt; 0"><xsl:text> (</xsl:text><xsl:value-of select="completion_percent"/><xsl:text>%)</xsl:text></xsl:if>
						</p>
						<xsl:if test="total_units &gt; 0">
							<progress value="{completed_units}" max="{total_units}" aria-label="{../labels/completion}"><xsl:value-of select="completion_percent"/>%</progress>
						</xsl:if>
						<p class="boei-completion-status">
							<xsl:value-of select="../labels/pending"/><xsl:text>: </xsl:text><xsl:value-of select="pending_units"/>
							<xsl:text> / </xsl:text><xsl:value-of select="../labels/approved"/><xsl:text>: </xsl:text><xsl:value-of select="approved_units"/>
							<xsl:text> / </xsl:text><xsl:value-of select="../labels/imported"/><xsl:text>: </xsl:text><xsl:value-of select="imported_units"/>
						</p>
						<a href="{url}"><xsl:value-of select="../labels/open"/></a>
					</xsl:when>
					<xsl:otherwise>
						<xsl:if test="status = 'pending' or status = 'approved'">
							<form method="post" action="{../action_url}">
								<input type="hidden" name="token" value="{../token}"/>
								<div class="boei-fields">
									<label>Gnr<input name="gnr" inputmode="numeric" value="{gnr}"/></label>
									<label>Bnr<input name="bnr" inputmode="numeric" value="{bnr}"/></label>
								</div>
								<div class="boei-actions">
									<button class="pure-button" name="action" value="save_object" type="submit"><xsl:value-of select="../labels/save"/></button>
									<button class="pure-button pure-button-primary" name="action" value="approve_object" type="submit"><xsl:value-of select="../labels/approve"/></button>
								</div>
							</form>
						</xsl:if>
						<form method="post" action="{../action_url}">
							<input type="hidden" name="token" value="{../token}"/>
							<label class="pure-checkbox"><input type="checkbox" name="confirm_import" value="1" required="required"/> <xsl:value-of select="../labels/confirm"/></label>
							<button class="pure-button pure-button-primary" name="action" value="import" type="submit"><xsl:value-of select="../labels/import"/></button>
						</form>
					</xsl:otherwise>
				</xsl:choose>
			</section>
		</xsl:for-each>
		<xsl:for-each select="units">
			<section class="boei-card">
				<h3><xsl:value-of select="../labels/unit"/><xsl:text> </xsl:text><xsl:value-of select="objekt_id"/> / <xsl:value-of select="leie_id"/></h3>
				<p><xsl:value-of select="../labels/status"/>: <xsl:value-of select="status_label"/> <xsl:value-of select="location_code"/></p>
				<p class="boei-source-address"><xsl:value-of select="../labels/source"/>: <xsl:value-of select="source_street_name"/><xsl:text> </xsl:text><xsl:value-of select="source_address"/><xsl:text> (ID: </xsl:text><xsl:value-of select="source_street_id"/><xsl:text>)</xsl:text></p>
				<xsl:if test="source_present = 0"><p role="alert"><xsl:value-of select="../labels/source_missing"/></p></xsl:if>
				<form method="post" action="{../action_url}">
					<input type="hidden" name="token" value="{../token}"/>
					<input type="hidden" name="leie_id" value="{leie_id}"/>
					<fieldset>
						<xsl:if test="status = 'imported'"><xsl:attribute name="disabled">disabled</xsl:attribute></xsl:if>
						<div class="boei-fields">
							<label><xsl:value-of select="../labels/building"/><input name="bygningsnr" inputmode="numeric" value="{bygningsnr}"/></label>
							<label><xsl:value-of select="../labels/street"/><input name="gate_kode" inputmode="numeric" value="{gate_kode}"/></label>
							<label><xsl:value-of select="../labels/number"/><input name="street_number" value="{street_number}" maxlength="10"/></label>
							<label>ADF<input name="adf" value="{adf}" maxlength="20"/></label>
						</div>
						<p><xsl:value-of select="street_name"/></p>
						<xsl:if test="status != 'imported'">
							<label class="pure-checkbox"><input type="checkbox" name="propagate" value="1"/> <xsl:value-of select="../labels/propagate"/></label>
							<div class="boei-actions">
								<button class="pure-button" name="action" value="save_unit" type="submit"><xsl:value-of select="../labels/save"/></button>
								<button class="pure-button pure-button-primary" name="action" value="approve_unit" type="submit"><xsl:value-of select="../labels/approve"/></button>
							</div>
						</xsl:if>
					</fieldset>
				</form>
			</section>
		</xsl:for-each>
	</div>
</xsl:template>
