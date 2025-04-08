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
	<div class="pure-form pure-form-aligned">
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
				</xsl:if>
				<xsl:if test="not(sql_statements)">
					<p>No SQL statements generated.</p>
				</xsl:if>
			</div>
		</xsl:if>
	</div>
</xsl:template>