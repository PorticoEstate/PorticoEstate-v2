<xsl:template match="data">
	<xsl:apply-templates select="documents" />
</xsl:template>

<xsl:template match="documents">
	<div class="pure-form pure-form-stacked">
		<h3>Location Document Move Workflow</h3>

		<xsl:if test="selection_saved = true()">
			<div class="pure-alert pure-alert-success">
				Selection saved. Mappings selected for move:
				<xsl:value-of select="selection_count" />
			</div>
		</xsl:if>

		<xsl:if test="execution_warning">
			<div class="pure-alert pure-alert-warning">
				<xsl:value-of select="execution_warning" />
			</div>
		</xsl:if>

		<xsl:if test="execution_results">
			<div class="pure-alert pure-alert-info">
				<h4>Execution Results</h4>
				<table class="pure-table pure-table-bordered">
					<tr>
						<th>Old location_code</th>
						<th>New location_code</th>
						<th>Status</th>
						<th>VFS rows updated</th>
						<th>Filesystem moves</th>
						<th>Message</th>
					</tr>
					<xsl:for-each select="execution_results">
						<tr>
							<td><xsl:value-of select="old_location_code" /></td>
							<td><xsl:value-of select="new_location_code" /></td>
							<td><xsl:value-of select="status" /></td>
							<td><xsl:value-of select="updated_rows" /></td>
							<td><xsl:value-of select="moved_directories" /></td>
							<td><xsl:value-of select="message" /></td>
						</tr>
					</xsl:for-each>
				</table>
			</div>
		</xsl:if>

		<h4>Summary</h4>
		<table class="pure-table pure-table-bordered">
			<tr>
				<th>Total candidates</th>
				<th>Selected for move</th>
				<th>Completed moves</th>
			</tr>
			<tr>
				<td><xsl:value-of select="summary/total_candidates" /></td>
				<td><xsl:value-of select="summary/selected_for_move" /></td>
				<td><xsl:value-of select="summary/completed_moves" /></td>
			</tr>
		</table>

		<h4>Step 1: Analyze and select mappings to move</h4>
		<form method="post" action="" class="pure-form">
			<input type="hidden" name="save_files_to_move" value="yes" />
			<table class="pure-table pure-table-bordered">
				<tr>
					<th>
						<label class="pure-checkbox">
							<input type="checkbox" id="select_all_mappings" onclick="toggleAllMappings(this)" />
							Select all
						</label>
					</th>
					<th>Old location_code</th>
					<th>New location_code</th>
					<th>Mapping rows</th>
					<th>Matched directories</th>
					<th>Already moved</th>
				</tr>
				<xsl:for-each select="candidates[directory_count &gt; 0]">
					<tr>
						<td>
							<label class="pure-checkbox">
								<input type="checkbox" name="mapping_keys[]" value="{selection_key}">
									<xsl:if test="files_to_move = 1">
										<xsl:attribute name="checked">checked</xsl:attribute>
									</xsl:if>
								</input>
							</label>
						</td>
						<td><xsl:value-of select="old_location_code" /></td>
						<td><xsl:value-of select="new_location_code" /></td>
						<td><xsl:value-of select="mapping_count" /></td>
						<td>
							<xsl:value-of select="directory_count" />
							<xsl:if test="directories">
								<details>
									<summary>Show directories</summary>
									<ul>
										<xsl:for-each select="directories">
											<li><xsl:value-of select="." /></li>
										</xsl:for-each>
									</ul>
								</details>
							</xsl:if>
						</td>
						<td>
							<xsl:choose>
								<xsl:when test="files_moved = 1">yes</xsl:when>
								<xsl:otherwise>no</xsl:otherwise>
							</xsl:choose>
						</td>
					</tr>
				</xsl:for-each>
			</table>
			<button type="submit" class="pure-button pure-button-primary">Save files_to_move selection</button>
		</form>

		<script>
		<![CDATA[
			function toggleAllMappings(source) {
				var checkboxes = document.querySelectorAll('input[name="mapping_keys[]"]');
				for (var i = 0; i < checkboxes.length; i++) {
					checkboxes[i].checked = source.checked;
				}
			}
		]]>
		</script>

		<h4>Step 2: Execute selected moves</h4>
		<form method="post" action="" class="pure-form">
			<input type="hidden" name="execute_move" value="yes" />
			<label class="pure-checkbox">
				<input type="checkbox" name="confirm_execute" value="1" />
				Confirm execution of move/rename for mappings where files_to_move = 1 and files_moved = 0
			</label>
			<button type="submit" class="pure-button pure-button-primary">Execute selected moves</button>
		</form>
	</div>
</xsl:template>
