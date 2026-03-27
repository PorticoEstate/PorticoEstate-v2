<xsl:template match="data">
	<xsl:apply-templates select="documents" />
</xsl:template>

<xsl:template match="documents">
	<div class="pure-form pure-form-stacked">
		<h3>Location Document Move Workflow</h3>

		<xsl:if test="analysis_ran != 1">
			<xsl:if test="previously_selected">
				<h4>Step 1: Analyze and select mappings to move</h4>
				<p class="pure-text-muted">Previously selected mappings are shown below. Click "Start Analysis" to include additional candidates.</p>
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
						<xsl:for-each select="previously_selected">
							<tr>
								<td>
									<xsl:if test="files_moved != 1">
										<label class="pure-checkbox">
											<input type="checkbox" name="mapping_keys[]" value="{selection_key}" checked="checked" />
										</label>
									</xsl:if>
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
			</xsl:if>

			<div class="pure-alert pure-alert-primary">
				<p>Click the button below to start the location document move analysis.</p>
			</div>
			<form method="post" action="" class="pure-form">
				<input type="hidden" name="start_analysis" value="yes" />
				<button type="submit" class="pure-button pure-button-primary pure-button-lg">Start Analysis</button>
			</form>
		</xsl:if>

		<xsl:if test="selection_saved = 1">
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
						<xsl:if test="files_moved != 1">
							<label class="pure-checkbox">
								<input type="checkbox" name="mapping_keys[]" value="{selection_key}">
									<xsl:if test="files_to_move = 1">
										<xsl:attribute name="checked">checked</xsl:attribute>
									</xsl:if>
								</input>
							</label>
						</xsl:if>
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

		<h4>VFS Directory Repair</h4>
		<div class="pure-alert pure-alert-primary">
			<p>Step 1 runs a dry-run and shows missing directory definitions. Step 2 executes inserts for those missing rows.</p>
		</div>

		<h4>Step 1: Analyze missing directory definitions (dry-run)</h4>
		<form method="post" action="" class="pure-form">
			<input type="hidden" name="analyze_vfs_repair" value="yes" />
			<button type="submit" class="pure-button pure-button-primary">Analyze VFS repair (dry-run)</button>
		</form>

		<xsl:if test="vfs_repair_analysis">
			<div class="pure-alert pure-alert-info">
				<p>
					Scanned paths:
					<xsl:value-of select="vfs_repair_analysis/scanned_paths" />
					,
					checked directory definitions:
					<xsl:value-of select="vfs_repair_analysis/checked_directory_definitions" />
					,
					missing definitions:
					<xsl:value-of select="vfs_repair_analysis/missing_count" />
				</p>
				<xsl:if test="vfs_repair_analysis/candidates">
					<table class="pure-table pure-table-bordered">
						<tr>
							<th>Full path</th>
							<th>Parent directory</th>
							<th>Name</th>
							<th>Owner ID</th>
							<th>App</th>
						</tr>
						<xsl:for-each select="vfs_repair_analysis/candidates">
							<tr>
								<td><xsl:value-of select="full_path" /></td>
								<td><xsl:value-of select="directory" /></td>
								<td><xsl:value-of select="name" /></td>
								<td><xsl:value-of select="owner_id" /></td>
								<td><xsl:value-of select="app" /></td>
							</tr>
						</xsl:for-each>
					</table>
				</xsl:if>
			</div>
		</xsl:if>

		<h4>Step 2: Execute VFS directory repair</h4>
		<form method="post" action="" class="pure-form">
			<input type="hidden" name="execute_vfs_repair" value="yes" />
			<label class="pure-checkbox">
				<input type="checkbox" name="confirm_vfs_repair" value="1" />
				Confirm execution of VFS directory repair inserts
			</label>
			<button type="submit" class="pure-button pure-button-primary">Execute VFS repair</button>
		</form>

		<xsl:if test="vfs_repair_execution_results">
			<div class="pure-alert pure-alert-info">
				<p>
					Candidates:
					<xsl:value-of select="vfs_repair_execution_results/candidate_count" />
					,
					inserted:
					<xsl:value-of select="vfs_repair_execution_results/inserted_count" />
					,
					skipped:
					<xsl:value-of select="vfs_repair_execution_results/skipped_count" />
					,
					failed:
					<xsl:value-of select="vfs_repair_execution_results/failed_count" />
				</p>
				<xsl:if test="vfs_repair_execution_results/results">
					<table class="pure-table pure-table-bordered">
						<tr>
							<th>Full path</th>
							<th>Parent directory</th>
							<th>Name</th>
							<th>Status</th>
							<th>Message</th>
						</tr>
						<xsl:for-each select="vfs_repair_execution_results/results">
							<tr>
								<td><xsl:value-of select="full_path" /></td>
								<td><xsl:value-of select="directory" /></td>
								<td><xsl:value-of select="name" /></td>
								<td><xsl:value-of select="status" /></td>
								<td><xsl:value-of select="message" /></td>
							</tr>
						</xsl:for-each>
					</table>
				</xsl:if>
			</div>
		</xsl:if>
	</div>
</xsl:template>
