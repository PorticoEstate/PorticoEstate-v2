<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<xsl:call-template name="msgbox"/>
	<!-- <xsl:call-template name="xmlsource"/> -->
	<form action="" method="POST">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="allocation/tabs"/>
			<div id="allocation_new" class="booking-container">
				<input type="hidden" name="organization_name" value="{allocation/organization_name}"/>
				<input type="hidden" name="organization_id" value="{allocation/organization_id}"/>
				<input type="hidden" name="building_name" value="{allocation/building_name}"/>
				<input type="hidden" name="building_id" value="{allocation/building_id}"/>
				<input type="hidden" name="from_" value="{from_date}"/>
				<input type="hidden" name="to_" value="{to_date}"/>
				<input type="hidden" name="weekday" value="{weekday}"/>
				<input type="hidden" name="building_id" value="{allocation/building_id}"/>
				<input type="hidden" name="cost" value="{allocation/cost}"/>
				<input type="hidden" name="season_id" value="{allocation/season_id}"/>
				<input type="hidden" name="field_building_id" value="{allocation/building_id}"/>
				<input type="hidden" name="step" value="{step}" />
				<input type="hidden" name="repeat_until" value="{repeat_until}" />
				<input type="hidden" name="field_interval" value="{interval}" />
				<input type="hidden" name="outseason" value="{outseason}" />
				<input type="hidden" name="application_id" value="{allocation/application_id}"/>
				<input type="hidden" name="temp_id" value="{temp_id}"/>
				<input type="hidden" name="additional_invoice_information" value="{allocation/additional_invoice_information}"/>
				<input type="hidden" name="skip_bas" value="{allocation/skip_bas}"/>

				<xsl:for-each select="allocation/resources">
					<input type="hidden" name="resources[]" value="{.}" />
				</xsl:for-each>
				<div class="row">
					<div class="col-12">
						<h6 class="text-muted mb-3">
							<i class="fas fa-exclamation-triangle me-1"></i>
							<xsl:value-of select="php:function('lang', 'Allocations with existing allocations or bookings')" />
							<span class="badge bg-light text-dark ms-2">
								<xsl:value-of select="count(invalid_dates)"/> stk
							</span>
						</h6>
						
						<div class="table-responsive">
							<table class="table table-sm table-hover">
								<thead class="table-light">
									<tr>
										<th style="width: 20%;"><i class="fas fa-calendar me-1"></i>Dato</th>
										<th style="width: 15%;"><i class="fas fa-clock me-1"></i>Tid</th>
										<th style="width: 15%;"><i class="fas fa-cube me-1"></i>Ressurser</th>
										<th style="width: 20%;"><i class="fas fa-exclamation-triangle me-1"></i>Status</th>
										<th style="width: 30%;"><i class="fas fa-cogs me-1"></i>Handling</th>
									</tr>
								</thead>
								<tbody>
									<xsl:for-each select="invalid_dates">
										<tr class="table-warning">
											<td><span class="fw-medium"><xsl:value-of select="substring-before(from_, ' ')"/></span></td>
											<td><span class="font-monospace"><xsl:value-of select="substring-after(from_, ' ')"/> - <xsl:value-of select="substring-after(to_, ' ')"/></span></td>
											<td><span class="text-muted small"><xsl:value-of select="/data/allocation/resource_names"/></span></td>
											<td><span class="badge bg-warning"><i class="fas fa-exclamation-triangle me-1"></i>Konflikt</span></td>
											<td>
												<xsl:choose>
													<xsl:when test="conflict_count > 0">
														<div class="d-flex flex-column align-items-start">
															<small class="text-danger mb-1">
																<i class="fas fa-exclamation-triangle me-1"></i>
																<xsl:value-of select="php:function('lang', 'conflict_with')" />
															</small>
															<div class="mb-2">
																<xsl:for-each select="conflict_links/*">
																	<a class="btn btn-outline-danger btn-sm me-1 mb-1">
																		<xsl:attribute name="href">
																			<xsl:value-of select="link"/>
																		</xsl:attribute>
																		<xsl:attribute name="title">
																			<xsl:value-of select="php:function('lang', 'view_details')" />
																		</xsl:attribute>
																		<xsl:attribute name="target">_blank</xsl:attribute>
																		<xsl:choose>
																			<xsl:when test="type = 'block'">
																				<i class="fas fa-ban me-1"></i>
																			</xsl:when>
																			<xsl:when test="type = 'allocation'">
																				<i class="fas fa-calendar-check me-1"></i>
																			</xsl:when>
																			<xsl:when test="type = 'booking'">
																				<i class="fas fa-calendar-alt me-1"></i>
																			</xsl:when>
																			<xsl:when test="type = 'event'">
																				<i class="fas fa-star me-1"></i>
																			</xsl:when>
																		</xsl:choose>
																		<xsl:value-of select="name"/>
																	</a>
																</xsl:for-each>
															</div>
														</div>
													</xsl:when>
													<xsl:otherwise>
														<span class="text-muted small">
															<i class="fas fa-exclamation-triangle me-1"></i>Ukjent konflikt
														</span>
													</xsl:otherwise>
												</xsl:choose>
											</td>
										</tr>
									</xsl:for-each>
								</tbody>
							</table>
						</div>

						<xsl:if test="count(invalid_dates) = 0">
							<div class="text-center text-muted py-4">
								<i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
								<p class="mb-0">Ingen konflikter funnet</p>
							</div>
						</xsl:if>
					</div>
				</div>

				<div class="row mt-4">
					<div class="col-12">
						<h6 class="text-muted mb-3">
							<i class="fas fa-check-circle me-1"></i>
							<xsl:value-of select="php:function('lang', 'Allocations that can be created')" />
							<span class="badge bg-light text-dark ms-2">
								<xsl:value-of select="count(valid_dates)"/> stk
							</span>
						</h6>
						
						<div class="table-responsive">
							<table class="table table-sm table-hover">
								<thead class="table-light">
									<tr>
										<th style="width: 20%;"><i class="fas fa-calendar me-1"></i>Dato</th>
										<th style="width: 15%;"><i class="fas fa-clock me-1"></i>Tid</th>
										<th style="width: 15%;"><i class="fas fa-cube me-1"></i>Ressurser</th>
										<th style="width: 20%;"><i class="fas fa-check-circle me-1"></i>Status</th>
										<th style="width: 30%;"><i class="fas fa-cogs me-1"></i>Handling</th>
									</tr>
								</thead>
								<tbody>
									<xsl:for-each select="valid_dates">
										<tr class="table-success">
											<td><span class="fw-medium"><xsl:value-of select="substring-before(from_, ' ')"/></span></td>
											<td><span class="font-monospace"><xsl:value-of select="substring-after(from_, ' ')"/> - <xsl:value-of select="substring-after(to_, ' ')"/></span></td>
											<td><span class="text-muted small"><xsl:value-of select="/data/allocation/resource_names"/></span></td>
											<td><span class="badge bg-success"><i class="fas fa-check me-1"></i>Kan opprettes</span></td>
											<td><span class="text-muted small"><i class="fas fa-clock me-1"></i>Klar for opprettelse</span></td>
										</tr>
									</xsl:for-each>
								</tbody>
							</table>
						</div>

						<xsl:if test="count(valid_dates) = 0">
							<div class="text-center text-muted py-4">
								<i class="fas fa-calendar-times fa-3x mb-3 text-muted"></i>
								<p class="mb-0">Ingen tildelinger kan opprettes</p>
							</div>
						</xsl:if>
					</div>
				</div>
				<div class="form-buttons">
					<input type="submit" name="create" class="pure-button pure-button-primary">
						<xsl:attribute name="value">
							<xsl:value-of select="php:function('lang', 'Create')" />
						</xsl:attribute>
					</input>
					<a class="cancel pure-button pure-button-primary">
						<xsl:attribute name="href">
							<xsl:value-of select="allocation/cancel_link"/>
						</xsl:attribute>
						<xsl:value-of select="php:function('lang', 'Cancel')" />
					</a>
				</div>
			</div>
		</div>
	</form>
	<script type="text/javascript">
		var initialSelection = <xsl:value-of select="allocation/resources_json"/>;
	</script>
</xsl:template>
<xsl:template name="xmlsource">
	NODE <xsl:value-of select="name()"/>
	ATTR { <xsl:for-each select="attribute::*">
		<xsl:value-of select="name()"/>=<xsl:value-of select="."/>
	</xsl:for-each> }
	CHILDREN: { <xsl:for-each select="*">
		<xsl:call-template name="xmlsource"/>
	</xsl:for-each> }
	TEXT <xsl:value-of select="text()"/>
	<br/>
</xsl:template>
