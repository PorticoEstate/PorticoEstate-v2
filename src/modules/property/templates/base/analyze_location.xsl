<xsl:template match="data">
	<xsl:apply-templates select="analyze" />
</xsl:template>
<xsl:template match="analyze">
	<xsl:variable name="lang_analyze_location">Location Hierarchy Analysis</xsl:variable>
	<xsl:variable name="lang_run_analysis">Run Analysis</xsl:variable>
	<xsl:variable name="lang_analysis_results">Analysis Results</xsl:variable>
	<xsl:variable name="lang_statistics">Statistics</xsl:variable>
	<xsl:variable name="lang_issues">Issues Found</xsl:variable>
	<xsl:variable name="lang_suggestions">Suggestions</xsl:variable>
	<xsl:variable name="lang_sql_statements">SQL Statements</xsl:variable>
	<xsl:variable name="lang_loc1_input">Enter loc1 (optional):</xsl:variable>
	<xsl:variable name="lang_next_location">Next Location</xsl:variable>
	<div class="pure-form pure-form-stacked">
		<div class="pure-control-group">
			<label>
				<xsl:value-of select="$lang_analyze_location" />
			</label>
		</div>
		<form method="post" action="">
			<div class="pure-control-group">
				<label for="loc1">
					<xsl:value-of select="$lang_loc1_input" />
				</label>
				<input type="text" name="loc1" id="loc1" value="{selected_loc1}" placeholder="e.g., 1234" />
			</div>
			<input type="hidden" name="run_analysis" value="yes" />
			<div class="pure-controls">
				<button type="submit" class="pure-button pure-button-primary">
					<xsl:value-of select="$lang_run_analysis" />
				</button>
				<button type="button" class="pure-button" onclick="incrementAndSubmit()">
					<xsl:value-of select="$lang_next_location" />
				</button>
			</div>
		</form>
		<script>
			function incrementAndSubmit() {
				var loc1Input = document.getElementById('loc1');
				var currentValue = parseInt(loc1Input.value) || 0;
				loc1Input.value = currentValue + 1;
				loc1Input.form.submit();
			}
		</script>
		<xsl:if test="analysis_ran = true()">
			<div class="pure-control-group">
				<h3>
					<xsl:value-of select="$lang_analysis_results" />
				</h3>
				<!-- Display Statistics -->
				<h4>
					<xsl:value-of select="$lang_statistics" />
				</h4>
				<table class="pure-table pure-table-bordered">
					<tr>
						<th>Level</th>
						<th>Count</th>
					</tr>
					<tr>
						<td>Properties (Level 1)</td>
						<td>
							<xsl:value-of select="statistics/level1_count" />
						</td>
					</tr>
					<tr>
						<td>Buildings (Level 2)</td>
						<td>
							<xsl:value-of select="statistics/level2_count" />
						</td>
					</tr>
					<tr>
						<td>Entrances (Level 3)</td>
						<td>
							<xsl:value-of select="statistics/level3_count" />
						</td>
					</tr>
					<tr>
						<td>Apartments (Level 4)</td>
						<td>
							<xsl:value-of select="statistics/level4_count" />
						</td>
					</tr>
					<tr>
						<td>Unique Building Numbers</td>
						<td>
							<xsl:value-of select="statistics/unique_buildings" />
						</td>
					</tr>
					<tr>
						<td>Unique Street Addresses</td>
						<td>
							<xsl:value-of select="statistics/unique_addresses" />
						</td>
					</tr>
				</table>
				
				<!-- Display Issues Summary -->
				<h4>Issues Summary</h4>
				<table class="pure-table pure-table-bordered">
					<tr>
						<th>Issue Type</th>
						<th>Count</th>
						<th>Description</th>
					</tr>
					<tr class="pure-table-odd">
						<td>Total Issues</td>
						<td>
							<xsl:value-of select="statistics/total_issues" />
						</td>
						<td>Total number of issues found</td>
					</tr>
					<xsl:for-each select="statistics/issues_by_type/*">
						<tr>
							<td>
								<xsl:value-of select="name()" />
							</td>
							<td>
								<xsl:value-of select="." />
							</td>
							<td>
								<xsl:value-of select="../../issue_descriptions/*[name() = name(current())]" />
							</td>
						</tr>
					</xsl:for-each>
				</table>
				
				<!-- Display Issues -->
				<h4>
					<xsl:value-of select="$lang_issues" />
				</h4>
				<xsl:if test="issues">
					<table class="pure-table pure-table-bordered">
						<tr>
							<th>Type</th>
							<th>Details</th>
						</tr>
						<xsl:for-each select="issues">
							<tr>
								<td>
									<xsl:value-of select="type" />
								</td>
								<td>
									<xsl:for-each select="*">
										<xsl:if test="name() != 'type'">
											<strong>
												<xsl:value-of select="name()" />:												
											</strong>
											<xsl:value-of select="." />
											<br />
										</xsl:if>
									</xsl:for-each>
								</td>
							</tr>
						</xsl:for-each>
					</table>
				</xsl:if>
				<xsl:if test="not(issues)">
					<p>No issues found.</p>
				</xsl:if>
				<!-- Display Suggestions -->
				<h4>
					<xsl:value-of select="$lang_suggestions" />
				</h4>
				<xsl:if test="suggestions">
					<ul>
						<xsl:for-each select="suggestions">
							<li>
								<xsl:value-of select="." />
							</li>
						</xsl:for-each>
					</ul>
				</xsl:if>
				<xsl:if test="not(suggestions)">
					<p>No suggestions available.</p>
				</xsl:if>
				<!-- Display SQL Statements -->
				<h4>
					<xsl:value-of select="$lang_sql_statements" />
				</h4>
				<xsl:if test="sql_statements">
					<!-- Add SQL execution form -->
					<form method="post" action="" class="pure-form">
						<input type="hidden" name="loc1" value="{selected_loc1}" />
						<input type="hidden" name="execute_sql" value="yes" />
						
						<fieldset>
							<legend>Execute SQL statements</legend>
								<div class="">
									<label for="create_schema" class="pure-checkbox">
										<input type="checkbox" id="create_schema" name="sql_types[]" value="schema" />
										Create mapping table
									</label>
									<label for="create_mapping" class="pure-checkbox">
										<input type="checkbox" id="create_mapping" name="sql_types[]" value="corrections" />
										Create mapping records									
									</label>

									<label for="fix_loc2" class="pure-checkbox">
										<input type="checkbox" id="fix_loc2" name="sql_types[]" value="missing_loc2" />
										Fix missing loc2 entries
									</label>
									<label for="fix_loc3" class="pure-checkbox">
										<input type="checkbox" id="fix_loc3" name="sql_types[]" value="missing_loc3" />
										Fix missing loc3 entries
									</label>
									<label for="fix_loc4" class="pure-checkbox">
										<input type="checkbox" id="fix_loc4" name="sql_types[]" value="location4_updates" />
										Update location4 entries
									</label>
									<label for="update_loc3_name" class="pure-checkbox">
										<input type="checkbox" id="update_loc3_name" name="sql_types[]" value="loc3_name_updates" />
										Update loc3 name entries
									</label>
									<label for="update_loc2_name" class="pure-checkbox">
										<input type="checkbox" id="update_loc2_name" name="sql_types[]" value="loc2_name_updates" />
										Update loc2 name entries (building summary)
									</label>
									<label for="update_location_from_mapping" class="pure-checkbox">
										<input type="checkbox" id="update_location_from_mapping" name="sql_types[]" value="update_location_from_mapping" />
										Update all tables from mapping
									</label>

									<label for="select_all" class="pure-checkbox">
										<input type="checkbox" id="select_all" onclick="toggleAllSql(this)" />
										Select all
									</label>
								</div>
							<button type="submit" class="pure-button pure-button-primary">Execute Selected SQL</button>
						</fieldset>
					</form>
					
					<script>
		<![CDATA[
						// Function to toggle all checkboxes]]			
						function toggleAllSql(source) {
							var checkboxes = document.querySelectorAll('input[name="sql_types[]"]');
							for (var i = 0; i < checkboxes.length; i++) {
								checkboxes[i].checked = source.checked;
							}
						}

		]]>
					</script>
					
					<!-- Display SQL execution results if any -->
					<xsl:if test="sql_execution_results">
						<div class="pure-alert pure-alert-success">
							<h5>SQL Execution Results</h5>
							<ul>
								<xsl:for-each select="sql_execution_results/*">
									<li>
										<strong><xsl:value-of select="name()" />:</strong>
										<xsl:value-of select="." /> statements executed
									</li>
								</xsl:for-each>
							</ul>
						</div>
					</xsl:if>
					
					<h5>Schema</h5>
					<pre>
						<xsl:for-each select="sql_statements/schema">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Missing loc2</h5>
					<pre>
						<xsl:for-each select="sql_statements/missing_loc2">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Missing loc3</h5>
					<pre>
						<xsl:for-each select="sql_statements/missing_loc3">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Update loc3 name</h5>
					<pre>
						<xsl:for-each select="sql_statements/loc3_name_updates">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Update loc2 name (building summary)</h5>
					<pre>
						<xsl:for-each select="sql_statements/loc2_name_updates">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Corrections</h5>
					<pre>
						<xsl:for-each select="sql_statements/corrections">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>FM Location4 Updates</h5>
					<pre>
						<xsl:for-each select="sql_statements/location4_updates">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
					<h5>Update all tables from mapping</h5>
					<pre>
						<xsl:for-each select="sql_statements/update_location_from_mapping">
							<xsl:value-of select="." />
							<xsl:text>&#10;</xsl:text>
						</xsl:for-each>
					</pre>
				</xsl:if>
				<xsl:if test="not(sql_statements)">
					<p>No SQL statements generated.</p>
				</xsl:if>
			</div>
		</xsl:if>
	</div>
	
	<style>
		.stacked-controls .pure-checkbox {
			display: block;
			margin-bottom: 0.5em;
		}
	</style>
</xsl:template>