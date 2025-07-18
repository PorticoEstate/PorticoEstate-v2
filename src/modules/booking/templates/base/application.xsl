<func:function name="phpgw:conditional">
	<xsl:param name="test"/>
	<xsl:param name="true"/>
	<xsl:param name="false"/>
	<func:result>
		<xsl:choose>
			<xsl:when test="$test">
				<xsl:value-of select="$true"/>
			</xsl:when>
			<xsl:otherwise>
				<xsl:value-of select="$false"/>
			</xsl:otherwise>
		</xsl:choose>
	</func:result>
</func:function>

<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<style type="text/css">
		.pure-form-contentTable {display: inline-block;}
	</style>

	<xsl:call-template name="msgbox"/>
	<div class= "pure-form pure-form-stacked" id="form" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="application/tabs"/>
			<div id="application" class="booking-container">
				<fieldset>
					<div class="pure-g">
						<div class="pure-u-1">
							<h1>
								<xsl:value-of select="application/id"/>
							</h1>
							<div class="pure-control-group">
								<xsl:if test="frontend and application/status='ACCEPTED'">
									<form method="POST">
										<input type="hidden" name="print" value="ACCEPTED"/>
										<input type="submit" value="{php:function('lang', 'Print as PDF')}" />
									</form>
								</xsl:if>
							</div>
							<div class="pure-control-group">
								<xsl:if test="not(frontend)">
									<div style="border: 3px solid red; padding: 3px 4px 3px 4px">
										<xsl:choose>
											<xsl:when test="not(application/case_officer)">
												<xsl:value-of select="php:function('lang', 'In order to work with this application, you must first')"/>
												<xsl:text> </xsl:text>
												<a href="#assign">
													<xsl:value-of select="php:function('lang', 'assign yourself')"/>
												</a>
												<xsl:text> </xsl:text>
												<xsl:value-of select="php:function('lang', 'as the case officer responsible for this application.')"/>
											</xsl:when>
											<xsl:when test="application/case_officer and not(application/case_officer/is_current_user)">
												<xsl:value-of select="php:function('lang', 'The user currently assigned as the responsible case officer for this application is')"/>
												<xsl:text> </xsl:text>'<xsl:value-of select="application/case_officer/name"/>'.
												<br/>
												<xsl:value-of select="php:function('lang', 'In order to work with this application, you must therefore first')"/>
												<xsl:text> </xsl:text>
												<a href="#assign">
													<xsl:value-of select="php:function('lang', 'assign yourself')"/>
												</a>
												<xsl:text> </xsl:text>
												<xsl:value-of select="php:function('lang', 'as the case officer responsible for this application.')"/>
											</xsl:when>
											<xsl:otherwise>
												<xsl:attribute name="style">display:none</xsl:attribute>
											</xsl:otherwise>
										</xsl:choose>
									</div>
								</xsl:if>
							</div>
							<xsl:if test="not(frontend)">
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Status')" />
									</label>
									<span>
										<xsl:value-of select="php:function('lang', string(application/status))"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Created')" />
									</label>
									<span>
										<xsl:value-of select="php:function('pretty_timestamp', application/created)"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Modified')" />
									</label>
									<span>
										<xsl:value-of select="php:function('pretty_timestamp', application/modified)"/>
									</span>
								</div>
							</xsl:if>
							<xsl:if test="frontend">
								<div class="proplist">
									<span style="font-size: 110%; font-weight: bold;">Din søknad har status <xsl:value-of select="php:function('lang', string(application/status))"/></span>
									<span class="text">, opprettet <xsl:value-of select="php:function('pretty_timestamp', application/created)"/>, sist endret <xsl:value-of select="php:function('pretty_timestamp', application/modified)"/></span>
									<span class="text">
										<br />Melding fra saksbehandler ligger under historikk, deretter vises kopi av din søknad.<br /> Skal du gi en melding til saksbehandler skriver du denne inn i feltet under "Legg til en kommentar"</span>
								</div>
							</xsl:if>
							<!--							<form method="POST">
								<div class="pure-control-group">
									<label for="comment">
										<xsl:value-of select="php:function('lang', 'Add a comment')" />
									</label>
									<textarea name="comment" id="comment" style="width: 60%; height: 7em"></textarea>
									<br/>
								</div>
								<div class="pure-control-group">
									<label>&nbsp;</label>
									<input type="submit" value="{php:function('lang', 'Add comment')}" />
								</div>
							</form>-->
						</div>
					</div>
					<div class="pure-g">
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>1. <xsl:value-of select="php:function('lang', 'History and comments (%1)', count(application/comments/author))" /></h3>
								<!--</legend>-->
							</div>
							<table class="pure-table pure-table-striped">
								<tr>
									<th>
										<xsl:value-of select="php:function('lang', 'Time')" />
									</th>
									<th>
										<xsl:value-of select="php:function('lang', 'Comment')" />
									</th>
								</tr>

								<xsl:for-each select="application/comments[author]">

									<tr>
										<td>
											<xsl:value-of select="php:function('pretty_timestamp', time)"/>: <xsl:value-of select="author"/>
										</td>
										<xsl:choose>
											<xsl:when test='contains(comment,"bookingfrontend.uidocument_building.download")'>
												<td>
													<xsl:value-of select="comment" disable-output-escaping="yes"/>
												</td>
											</xsl:when>
											<xsl:otherwise>
												<td>
													<xsl:value-of select="comment" disable-output-escaping="yes"/>
												</td>
											</xsl:otherwise>
										</xsl:choose>
									</tr>


								</xsl:for-each>

							</table>
						</div>
					</div>

					<div class="pure-g">
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>1.1 <xsl:value-of select="php:function('lang', 'attachments')" /></h3>
								<!--</legend>-->
							</div>
							<div id="attachments_container"/>
							<br/>
							<form method="POST" enctype='multipart/form-data' id='file_form'>
								<input name="name" id='field_name' type='file' >
									<xsl:attribute name='title'>
										<xsl:value-of select="document/name"/>
									</xsl:attribute>
									<xsl:attribute name="data-validation">
										<xsl:text>mime size</xsl:text>
									</xsl:attribute>
									<xsl:attribute name="data-validation-allowing">
										<xsl:text>jpg, jpeg, png, gif, xls, xlsx, doc, docx, txt, pdf, odt, ods</xsl:text>
									</xsl:attribute>
									<xsl:attribute name="data-validation-max-size">
										<xsl:text>2M</xsl:text>
									</xsl:attribute>
									<xsl:attribute name="data-validation-error-msg">
										<xsl:text>Max 2M:: jpg, jpeg, png, gif, xls, xlsx, doc, docx, txt , pdf, odt, ods</xsl:text>
									</xsl:attribute>
								</input>
								<br/>
								<br/>
								<input type="submit" value="{php:function('lang', 'Add attachment')}" />
							</form>

						</div>
					</div>

					<div class="pure-g">
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>2. <xsl:value-of select="php:function('lang', 'Why?')" /></h3>
								<!--</legend>-->
							</div>
							<xsl:if test="simple != 1">
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Activity')" />
									</label>
									<span>
										<xsl:value-of select="application/activity_name"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Event name')" />
									</label>
									<span>
										<xsl:value-of select="application/name" disable-output-escaping="yes"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Organizer')" />
									</label>
									<span>
										<xsl:value-of select="application/organizer" disable-output-escaping="yes"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Homepage')" />
									</label>
									<xsl:if test="application/homepage and normalize-space(application/homepage)">
										<a>
											<xsl:attribute name="href">
												<xsl:value-of select="application/homepage"/>
											</xsl:attribute>
											<xsl:value-of select="application/homepage"/>
										</a>
									</xsl:if>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Description')" />
									</label>
									<span>
										<xsl:value-of select="application/description" disable-output-escaping="yes"/>
									</span>
								</div>
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Extra info')" />
									</label>
									<span>
										<xsl:value-of select="application/equipment" disable-output-escaping="yes"/>
									</span>
								</div>
							</xsl:if>
						</div>

						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>3. <xsl:value-of select="php:function('lang', 'when_and_where')" /></h3>
								<!--</legend>-->
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'Building')" />
								</label>
								<span>
									<xsl:value-of select="application/building_name"/>
									(<a href="javascript: void(0)" onclick="window.open('{application/schedule_link}', '', 'width=1048, height=600, scrollbars=yes');return false;">
										<xsl:value-of select="php:function('lang', 'Building schedule')" />
									</a>)
								</span>
							</div>

							<!-- Display application count if multiple applications -->
							<xsl:if test="application/related_application_count > 1">
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Applications')" />
									</label>
									<span style="font-weight: bold; color: #2c5aa0;">
										<xsl:value-of select="application/related_application_count"/> applications combined
									</span>
								</div>
							</xsl:if>
							<p>
								<small>
									<xsl:value-of select="php:function('lang', 'date format')" />:
									<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />
								</small>
							</p>

							<script type="text/javascript">
								var allocationParams = {};
								var bookingParams = {};
								var eventParams = {};
								var applicationDate = {};
							</script>
							<xsl:variable name='assocdata'>
								<xsl:value-of select="assoc/data" />
							</xsl:variable>
							<xsl:variable name='collisiondata'>
								<xsl:value-of select="collision/data" />
							</xsl:variable>
							<script type="text/javascript">
								building_id = <xsl:value-of select="application/building_id"/>;
							</script>
							<xsl:for-each select="application/combined_dates">
								<div style="border: 1px solid #ddd; padding: 10px; margin: 10px 0; border-radius: 5px;">
									<div class="pure-control-group">
										<label style="font-weight: bold;">
											<xsl:value-of select="php:function('lang', 'time_period')" />:</label>
										<span>
											<xsl:value-of select="php:function('pretty_timestamp', from_)"/>
											<xsl:text> - </xsl:text>
											<xsl:value-of select="php:function('pretty_timestamp', to_)"/>
										</span>
										<xsl:if test="../case_officer/is_current_user">
											<xsl:if test="contains($collisiondata, from_)">
												<xsl:if test="not(contains($assocdata, from_))">
													<a href="javascript: void(0)"
													   onclick="open_schedule(building_id,'{from_}');return false;">
														<i class="fa fa-exclamation-circle"></i>
													</a>
												</xsl:if>
											</xsl:if>
										</xsl:if>
									</div>
									<div class="pure-control-group">
										<label>
											<xsl:value-of select="php:function('lang', 'Resources')" />:</label>
										<span>
											<xsl:for-each select="resource_names">
												<xsl:value-of select="."/>
												<xsl:if test="position() != last()">, </xsl:if>
											</xsl:for-each>
										</span>
									</div>
								</div>
								<xsl:if test="../edit_link">
									<script type="text/javascript">
										allocationParams[<xsl:value-of select="id"/>] = <xsl:value-of select="allocation_params"/>;
										bookingParams[<xsl:value-of select="id"/>] = <xsl:value-of select="booking_params"/>;
										eventParams[<xsl:value-of select="id"/>] = <xsl:value-of select="event_params"/>;
									</script>
									<div class="pure-control-group">
										<label>&nbsp;</label>
										<select name="create" onchange="if(this.selectedIndex==1) JqueryPortico.booking.postToUrl('index.php?menuaction=booking.uiallocation.add', allocationParams[{id}]); if(this.selectedIndex==2) JqueryPortico.booking.postToUrl('index.php?menuaction=booking.uibooking.add', eventParams[{id}]); if(this.selectedIndex==3) JqueryPortico.booking.postToUrl('index.php?menuaction=booking.uievent.add', eventParams[{id}]);">
											<xsl:if test="not(../case_officer/is_current_user)">
												<xsl:attribute name="disabled">disabled</xsl:attribute>
											</xsl:if>
											<xsl:if test="not(contains($assocdata, from_))">
												<option>
													<xsl:value-of select="php:function('lang', '- Actions -')" />
												</option>
												<option>
													<xsl:value-of select="php:function('lang', 'Create allocation')" />
												</option>
												<option>
													<xsl:value-of select="php:function('lang', 'Create booking')" />
												</option>
												<option>
													<xsl:value-of select="php:function('lang', 'Create event')" />
												</option>
											</xsl:if>
											<xsl:if test="contains($assocdata, from_)">
												<xsl:attribute name="disabled">disabled</xsl:attribute>
												<option>
													<xsl:value-of select="php:function('lang', '- Created -')" />
												</option>
											</xsl:if>
										</select>
									</div>
								</xsl:if>
							</xsl:for-each>
						</div>
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>4. <xsl:value-of select="php:function('lang', 'Who?')" /></h3>
								<!--</legend>-->
							</div>
							<xsl:if test="simple != 1">

								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Target audience')" />
									</label>
									<div class="custom-container">
										<ul class="list-left">
											<xsl:for-each select="audience">
												<xsl:if test="../application/audience=id">
													<li>
														<xsl:value-of select="name"/>
													</li>
												</xsl:if>
											</xsl:for-each>
										</ul>
									</div>
								</div>
								<div class="pure-control-group">
									<label style="vertical-align: top;width: auto;">
										<xsl:value-of select="php:function('lang', 'Number of participants')" />
									</label>
									<div class="pure-form-contentTable">
										<table id="agegroup" class="pure-table pure-table-striped">
											<thead>
												<tr>
													<th>
														<xsl:value-of select="php:function('lang', 'Name')" />
													</th>
													<th>
														<xsl:value-of select="php:function('lang', 'Male')" />
													</th>
													<th>
														<xsl:value-of select="php:function('lang', 'Female')" />
													</th>
												</tr>
											</thead>
											<tbody>
												<xsl:for-each select="agegroups">
													<xsl:variable name="id">
														<xsl:value-of select="id"/>
													</xsl:variable>
													<xsl:if test="(../application/agegroups/male[../agegroup_id = $id]) > 0 or (../application/agegroups/female[../agegroup_id = $id]) > 0">
														<tr>
															<td>
																<xsl:value-of select="name"/>
															</td>
															<td>
																<xsl:value-of select="../application/agegroups/male[../agegroup_id = $id]"/>
															</td>
															<td>
																<xsl:value-of select="../application/agegroups/female[../agegroup_id = $id]"/>
															</td>
														</tr>
													</xsl:if>
												</xsl:for-each>
											</tbody>
										</table>
									</div>
								</div>
							</xsl:if>
						</div>
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>6. <xsl:value-of select="php:function('lang', 'Contact information')" /></h3>
								<!--</legend>-->
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'Name')" />
								</label>
								<span>
									<xsl:value-of select="application/contact_name"/>
								</span>
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'Email')" />
								</label>
								<span>
									<xsl:value-of select="application/contact_email"/>
								</span>
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'Phone')" />
								</label>
								<span>
									<xsl:value-of select="application/contact_phone"/>
								</span>
							</div>
						</div>
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>7. <xsl:value-of select="php:function('lang', 'responsible applicant')" /> / <xsl:value-of select="php:function('lang', 'invoice information')" /></h3>
								<!--</legend>-->
							</div>
							<xsl:if test="application/customer_organization_name != ''">
								<div class="pure-control-group">
									<label>
										<xsl:value-of select="php:function('lang', 'Organization')" />
									</label>
									<span>
										<xsl:value-of select="application/customer_organization_name"/>
									</span>
								</div>
							</xsl:if>

							<div class="pure-control-group">
								<xsl:if test="application/customer_identifier_type = 'organization_number'">
									<label>
										<xsl:value-of select="php:function('lang', 'organization number')" />
									</label>
									<span>
										<xsl:value-of select="application/customer_organization_number"/>
									</span>
								</xsl:if>
								<xsl:if test="application/customer_identifier_type = 'ssn'">
									<label>
										<xsl:value-of select="php:function('lang', 'Date of birth or SSN')" />
									</label>
									<span>
										<xsl:value-of select="application/customer_ssn"/>
									</span>
								</xsl:if>
							</div>
							<div class="pure-control-group">
								<label for="field_street">
									<xsl:value-of select="php:function('lang', 'Street')"/>
								</label>
								<span>
									<xsl:value-of select="application/responsible_street"/>
								</span>
							</div>
							<div class="pure-control-group">
								<label for="field_zip_code">
									<xsl:value-of select="php:function('lang', 'Zip code')"/>
								</label>
								<span>
									<xsl:value-of select="application/responsible_zip_code"/>
								</span>
							</div>
							<div class="pure-control-group">
								<label for="field_responsible_city">
									<xsl:value-of select="php:function('lang', 'Postal City')"/>
								</label>
								<span>
									<xsl:value-of select="application/responsible_city"/>
								</span>
							</div>
						</div>
					</div>
					<div class="pure-g">
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h3>8. <xsl:value-of select="php:function('lang', 'Terms and conditions')" /></h3>
								<!--</legend>-->
							</div>
							<div class="pure-control-group">
								<xsl:if test="config/application_terms">
									<p>
										<xsl:value-of select="config/application_terms"/>
									</p>
								</xsl:if>
								<br />
								<div id='regulation_documents'>&nbsp;</div>
								<br />
								<p>
									<xsl:value-of select="php:function('lang', 'To borrow premises you must verify that you have read terms and conditions')" />
								</p>
							</div>
						</div>
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
								<h4>
									<xsl:value-of select="php:function('lang', 'additional requirements')" />
								</h4>
								<!--</legend>-->
							</div>
							<xsl:value-of disable-output-escaping="yes" select="application/agreement_requirements"/>
						</div>

					</div>
					<xsl:if test="not(frontend)">
						<div class="pure-g">
							<div class="pure-u-1">
								<div class="heading">
									<!--<legend>-->
									<h3>
										<xsl:value-of select="php:function('lang', 'Associated items')" />
									</h3>
									<!--</legend>-->
								</div>
								<div class="pure-control-group">
									<div id="associated_container"/>
								</div>
							</div>
							<div class="pure-u-1">
								<div class="heading">
									<!--<legend>-->
									<h3>
										<xsl:value-of select="php:function('lang', 'payments')" />
									</h3>
									<!--</legend>-->
								</div>
								<div class="pure-control-group">
									<div id="payments_container"/>
								</div>
							</div>
							<div id="order_details" class="pure-u-1" style="display:none;">
								<div class="heading">
									<!--<legend>-->
									<h3>
										<xsl:value-of select="php:function('lang', 'details')" />
									</h3>
									<!--</legend>-->
								</div>
								<div class="pure-control-group">
									<div id="order_container"/>
								</div>
							</div>
						</div>
					</xsl:if>
					<xsl:if test="application/edit_link">
						<div class="pure-g">
							<div class="pure-u-1">
								<div class="heading">
									<!--<legend>-->
									<h3>
										<xsl:value-of select="php:function('lang', 'Actions')" />
									</h3>
									<!--</legend>-->
								</div>
								<form method="POST">
									<div class="pure-control-group">
										<label for="comment">
											<xsl:value-of select="php:function('lang', 'Add a comment')" />
										</label>
										<textarea name="comment" id="comment"></textarea>
										<br/>
									</div>
									<div class="pure-control-group">
										<label>&nbsp;</label>
										<input type="submit" value="{php:function('lang', 'Add comment')}" />
									</div>
								</form>
								<br/>
								<div id="return_after_action" class="pure-control-group">
									<xsl:if test="application/case_officer/is_current_user">
										<form method="POST" style="display:inline">
											<input type="hidden" name="unassign_user"/>
											<input type="submit" value="{php:function('lang', 'Unassign me')}" class="pure-button pure-button-primary" />
										</form>
										<form method="POST" style="display:inline">
											<input type="hidden" name="display_in_dashboard" value="{phpgw:conditional(application/display_in_dashboard='1', '0', '1')}"/>
											<input type="submit" value="{php:function('lang', phpgw:conditional(application/display_in_dashboard='1', 'Hide from my Dashboard until new activity occurs', 'Display in my Dashboard'))}" class="pure-button pure-button-primary" />
										</form>
									</xsl:if>
									<xsl:if test="not(application/case_officer/is_current_user)">
										<a name="assign"/>
										<form method="POST">
											<input type="hidden" name="assign_to_user"/>
											<input type="hidden" name="status" value="PENDING"/>
											<input type="submit" value="{php:function('lang', phpgw:conditional(application/case_officer, 'Re-assign to me', 'Assign to me'))}" class="pure-button pure-button-primary" />
											<xsl:if test="application/case_officer">
												<xsl:value-of select="php:function('lang', 'Currently assigned to user:')"/>
												<xsl:text> </xsl:text>
												<xsl:value-of select="application/case_officer/name"/>
											</xsl:if>
										</form>
									</xsl:if>
								</div>
								<xsl:if test="application/status!='REJECTED'">
									<div>
										<form method="POST">
											<input type="hidden" name="status" value="REJECTED"/>
											<input onclick="return confirm('{php:function('lang', 'Are you sure you want to delete?')}')" type="submit" value="{php:function('lang', 'Reject application')}" class="pure-button pure-button-primary">
												<xsl:if test="not(application/case_officer)">
													<xsl:attribute name="disabled">disabled</xsl:attribute>
												</xsl:if>
											</input>
										</form>
									</div>
								</xsl:if>
								<xsl:if test="application/status='PENDING'">
									<xsl:if test="num_associations='0'">
										<input type="submit" disabled="" value="{php:function('lang', 'Accept application')}" class="pure-button pure-button-primary" />
										<xsl:value-of select="php:function('lang', 'One or more bookings, allocations or events needs to be created before an application can be Accepted')"/>
									</xsl:if>
									<xsl:if test="num_associations!='0'">
										<div>
											<form method="POST">
												<input type="hidden" name="status" value="ACCEPTED"/>
												<input type="submit" value="{php:function('lang', 'Accept application')}" class="pure-button pure-button-primary" >
													<xsl:if test="not(application/case_officer)">
														<xsl:attribute name="disabled">disabled</xsl:attribute>
													</xsl:if>
												</input>
											</form>
										</div>
									</xsl:if>
								</xsl:if>
								<div>
									<xsl:choose>
										<xsl:when test="external_archive != '' and application/external_archive_key =''">
											<form method="POST" action ="{export_pdf_action}" >
												<input type="hidden" name="export" value="pdf"/>
												<input onclick="return confirm('{php:function('lang', 'transfer case to external system?')}')" type="submit" value="{php:function('lang', 'PDF-export to archive')}" class="pure-button pure-button-primary">
													<xsl:if test="not(application/case_officer/is_current_user)">
														<xsl:attribute name="disabled">disabled</xsl:attribute>
													</xsl:if>
												</input>
												<label for="preview">
													<input name="preview" type="checkbox" value="1" id="preview" />
													<xsl:value-of select="php:function('lang', 'preview')"/>
												</label>
											</form>
										</xsl:when>
										<xsl:when test="application/external_archive_key !=''">
											<div class="pure-control-group">
												<label>
													<xsl:value-of select="php:function('lang', 'external archive key')"/>
												</label>
												<xsl:value-of select="application/external_archive_key"/>
											</div>
										</xsl:when>
									</xsl:choose>
								</div>

								<!--dd><br/><a href="{application/dashboard_link}"><xsl:value-of select="php:function('lang', 'Back to Dashboard')" /></a></dd-->
							</div>
						</div>
					</xsl:if>
				</fieldset>
			</div>
		</div>
		<div class="proplist-col">
			<xsl:if test="application/edit_link">
				<button class="pure-button pure-button-primary">
					<xsl:if test="application/case_officer/is_current_user">
						<xsl:attribute name="onclick">window.location.href='<xsl:value-of select="application/edit_link"/>'</xsl:attribute>
					</xsl:if>
					<xsl:if test="not(application/case_officer/is_current_user)">
						<xsl:attribute name="disabled">disabled</xsl:attribute>
					</xsl:if>
					<xsl:value-of select="php:function('lang', 'Edit')" />
				</button>
			</xsl:if>
			<a class="pure-button pure-button-primary" href="{application/dashboard_link}">
				<xsl:value-of select="php:function('lang', 'Back to Dashboard')" />
			</a>
		</div>
	</div>
	<script type="text/javascript">
		var template_set = '<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|template_set')" />';
		var initialSelection = <xsl:value-of select="application/resources_json"/>;
		var application_id = '<xsl:value-of select="application/id"/>';
		var resourceIds = '<xsl:value-of select="application/resource_ids"/>';
		var currentuser = '<xsl:value-of select="application/currentuser"/>';
		if (!resourceIds || resourceIds == "") {
		resourceIds = false;
		}
		var lang = <xsl:value-of select="php:function('js_lang', 'Resources', 'Resource Type', 'No records found', 'ID', 'Type', 'From', 'To', 'Document', 'Active' ,'Delete', 'del', 'Name', 'Cost', 'order id', 'Amount', 'currency', 'status', 'payment method', 'refund','refunded', 'Actions', 'cancel', 'created', 'article', 'Select', 'cost', 'unit', 'quantity', 'Selected', 'Delete', 'Sum', 'tax')"/>;
		var app_id = <xsl:value-of select="application/id"/>;
		var building_id = <xsl:value-of select="application/building_id"/>;
		var resources = <xsl:value-of select="application/resources"/>;

	    <![CDATA[
			var resourcesURL = phpGWLink('index.php', {menuaction:'booking.uiresource.index', sort:'name', length:-1}, true) +'&' + resourceIds;
			var associatedURL = phpGWLink('index.php', {menuaction:'booking.uiapplication.associated', sort:'from_',dir:'asc',filter_application_id:app_id, length:-1}, true);
			var documentsURL = phpGWLink('index.php', {menuaction:'booking.uidocument_view.regulations', sort:'name', length:-1}, true) +'&owner[]=building::' + building_id;
			var attachmentsResourceURL = phpGWLink('index.php', {menuaction:'booking.uidocument_application.index', sort:'name', no_images:1, filter_owner_id:app_id, length:-1}, true);
			var paymentURL = phpGWLink('index.php', {menuaction:'booking.uiapplication.payments', sort:'from_',dir:'asc',application_id:app_id, length:-1}, true);
			for (var i = 0; i < initialSelection.length; i++)
			{
				documentsURL += '&owner[]=resource::' + initialSelection[i];
			}

		]]>

		var colDefsResources = [{key: 'name', label: lang['Resources'], formatter: genericLink}, {key: 'rescategory_name', label: lang['Resource Type']}];

		if (currentuser == 1) {
		var colDefsAssociated = [
		{key: 'id', label: lang['ID'], formatter: genericLink},
		{key: 'type', label: lang['Type']},
		{key: 'from_', label: lang['From']},
		{key: 'to_', label: lang['To']},
		{key: 'cost', label: lang['Cost']},
		{key: 'active', label: lang['Active']},
		{key: 'dellink', label: lang['Delete'], formatter: genericLink2}];
		} else {
		var colDefsAssociated = [
		{key: 'id', label: lang['ID'], formatter: genericLink},
		{key: 'type', label: lang['Type']},
		{key: 'from_', label: lang['From']},
		{key: 'to_', label: lang['To']},
		{key: 'active', label: lang['Active']}];
		}

		var colDefsDocuments = [{key: 'name', label: lang['Document'], formatter: genericLink}];

		// Resources now shown with dates instead of separate table
		createTable('associated_container',associatedURL,colDefsAssociated,'results', 'pure-table pure-table-bordered');
		createTable('regulation_documents',documentsURL,colDefsDocuments, '', 'pure-table pure-table-bordered');

		var colDefsAttachmentsResource = [{key: 'name', label: lang['Name'], formatter: genericLink}];
		createTable('attachments_container', attachmentsResourceURL, colDefsAttachmentsResource, '', 'pure-table pure-table-bordered');

		var colDefsPayment = [
		{
		label: lang['Select'],
		attrs: [{name: 'class', value: "align-middle"}],
		object: [
		{
		type: 'input',
		attrs: [
		{name: 'type', value: 'radio'},
		{name: 'onClick', value: 'show_order(this);'}
		]
		}
		], value: 'order_id'
		},
		{key: 'order_id', label: lang['order id']},
		{key: 'created_value', label: lang['created']},
		{key: 'amount', label: lang['Amount']},
		{key: 'refunded_amount', label: lang['refunded']},
		{key: 'currency', label: lang['currency']},
		{key: 'status_text', label: lang['status']},
		{key: 'payment_method', label: lang['payment method']},
		{key: 'actions', label: lang['Actions'], formatter: genericLink2({name: 'delete', label:lang['refund']},{name: 'edit', label:lang['cancel']})}
		];

		createTable('payments_container', paymentURL, colDefsPayment,'', 'pure-table pure-table-bordered');

	</script>
</xsl:template>
