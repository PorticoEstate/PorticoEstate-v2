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

		.card-img-top {
		width: 100%;
		height: 250px;
		object-fit: cover;
		}

		tr.clickable-row {
		cursor: pointer;
		color: #0275d8;
		}

		tr.clickable-row:hover {
		color: #224ac1;
		background-color: #f7f7f7;
		}


		* ==========================================
		* CUSTOM UTIL CLASSES
		* ==========================================
		*
		*/


		a:hover,a:focus{
		text-decoration: none;
		outline: none;
		}
		#accordion .panel{
		border: none;
		border-radius: 0;
		box-shadow: none;
		margin-bottom: 15px;
		position: relative;
		}
		#accordion .panel:before{
		content: "";
		display: block;
		width: 1px;
		height: 100%;
		border: 1px dashed #0275d8;
		position: absolute;
		top: 25px;
		left: 18px;
		}
		#accordion .panel:last-child:before{ display: none; }
		#accordion .panel-heading{
		padding: 0;
		border: none;
		border-radius: 0;
		position: relative;
		}
		#accordion .panel-title a{
		display: block;
		padding: 10px 30px 10px 60px;
		margin: 0;
		background: #fff;
		font-size: 18px;
		font-weight: 700;
		letter-spacing: 1px;
		color: #1d3557;
		border-radius: 0;
		position: relative;
		}
		#accordion .panel-title a:before,
		#accordion .panel-title a.collapsed:before{
		content: "\f107";
		font-family: "Font Awesome 5 Free";
		font-weight: 900;
		width: 40px;
		height: 100%;
		line-height: 40px;
		background: #0275d8;
		border: 1px solid #0275d8;
		border-radius: 3px;
		font-size: 17px;
		color: #fff;
		text-align: center;
		position: absolute;
		top: 0;
		left: 0;
		transition: all 0.3s ease 0s;
		}
		#accordion .panel-title a.collapsed:before{
		content: "\f105";
		background: #fff;
		border: 1px solid #0275d8;
		color: #000;
		}
		#accordion .panel-body{
		padding: 10px 30px 10px 30px;
		margin-left: 40px;
		background: #fff;
		border-top: none;
		font-size: 15px;
		color: #6f6f6f;
		line-height: 28px;
		letter-spacing: 1px;
		}

		.pure-form-contentTable {display: inline-block;}
	</style>
	<xsl:variable name="messenger_enabled">
		<xsl:value-of select="php:function('get_phpgw_info', 'user|apps|messenger|enabled')" />
	</xsl:variable>

	<xsl:call-template name="msgbox"/>
	<!-- Begin Page Content -->
	<div class="container-fluid">
		<div class="row pl-3 pr-3 mt-4">

			<div class="col-6">
				<!-- BUTTONS -->
				<ul class="list-inline">
					<li class="list-inline-item mb-2">
						<div class="dropdown">
							<button class="btn btn-outline-success btn-circle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<i class="fas fa-reply" aria-hidden="true" title="Send svar eller opprett notat i saken"></i>
							</button>
							<div class="dropdown-menu animated--fade-in" aria-labelledby="dropdownMenuButton">
								<button class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#commentModal">
									<xsl:choose>
										<xsl:when test="not(application/case_officer/is_current_user)">
											<xsl:attribute name="disabled">disabled</xsl:attribute>
											<i class="fas fa-reply me-1 text-secondary"></i>
										</xsl:when>
										<xsl:otherwise>
											<i class="fas fa-reply me-1 text-success"></i>
										</xsl:otherwise>
									</xsl:choose>
									Send svar til innsender
								</button>
								<button class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#messengerModal">
									<xsl:choose>
										<xsl:when test="$messenger_enabled !='true' or application/case_officer/is_current_user or application/case_officer_id ='' ">
											<xsl:attribute name="disabled">disabled</xsl:attribute>
											<i class="fas fa-reply me-1 text-secondary"></i>
										</xsl:when>
										<xsl:otherwise>
											<i class="fas fa-reply me-1 text-success"></i>
										</xsl:otherwise>
									</xsl:choose>
									Send melding til saksbehandler
								</button>
								<button class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#internal_noteModal">
									<xsl:choose>
										<xsl:when test="not(application/case_officer/is_current_user)">
											<xsl:attribute name="disabled">disabled</xsl:attribute>
											<i class="far fa-sticky-note me-1 text-secondary"></i>
										</xsl:when>
										<xsl:otherwise>
											<i class="far fa-sticky-note me-1 text-warning"></i>
										</xsl:otherwise>
									</xsl:choose>
									Opprett internt notat
								</button>
							</div>
						</div>
					</li>
					<li class="list-inline-item mb-2">
						<div class="dropdown">
							<button class="btn btn-outline-warning btn-circle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<i class="fas fa-arrow-right" aria-hidden="true" title="Videresend sak"></i>
							</button>
							<div class="dropdown-menu animated--fade-in" aria-labelledby="dropdownMenuButton">
								<a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#change_userModal">
									<i class="fas fa-arrow-right me-1 text-warning"></i>Sett sak til en annen saksbehandler
								</a>
							</div>
						</div>
					</li>
					<li class="list-inline-item mb-2">
						<div class="dropdown" id="action_dropdown">
							<button class="btn btn-outline-primary btn-circle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<i class="fas fa-flag" aria-hidden="true" title="Flere handlinger"></i>
							</button>
							<div id="return_after_action" class="dropdown-menu animated--fade-in" aria-labelledby="dropdownMenuButton">
								<xsl:if test="application/case_officer/is_current_user">
									<form method="POST" style="display:inline">
										<input type="hidden" name="unassign_user"/>
										<button type="submit"  class="dropdown-item" >
											<i class="fas fa-flag me-1 text-primary"></i>
											<xsl:value-of select="php:function('lang', 'Unassign me')"/>
										</button>
									</form>
									<form method="POST" style="display:inline">
										<input type="hidden" name="display_in_dashboard" value="{phpgw:conditional(application/display_in_dashboard='1', '0', '1')}"/>
										<button type="submit" class="dropdown-item">
											<i class="fas fa-flag me-1 text-primary"></i>
											<xsl:value-of select="php:function('lang', phpgw:conditional(application/display_in_dashboard='1', 'Hide from my Dashboard until new activity occurs', 'Display in my Dashboard'))"/>
										</button>
									</form>
								</xsl:if>
								<xsl:if test="not(application/case_officer/is_current_user)">
									<form method="POST">
										<input type="hidden" name="assign_to_user"/>
										<input type="hidden" name="status" value="PENDING"/>
										<button type="submit" class="dropdown-item" >
											<i class="fas fa-flag me-1 text-primary"></i>
											<xsl:value-of select="php:function('lang', phpgw:conditional(application/case_officer, 'Re-assign to me', 'Assign to me'))"/>
										</button>
									</form>
								</xsl:if>

								<xsl:if test="application/status!='REJECTED'">
									<form method="POST">
										<input type="hidden" name="status" value="REJECTED"/>
										<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#rejectApplicationModal">
											<xsl:choose>
												<xsl:when test="not(application/case_officer/is_current_user)">
												  <xsl:attribute name="disabled">disabled</xsl:attribute>
												  <i class="fas fa-flag me-1 text-secondary"></i>
												</xsl:when>
												<xsl:otherwise>
												  <i class="fas fa-flag me-1 text-primary"></i>
												</xsl:otherwise>
											  </xsl:choose>
											  <xsl:value-of select="php:function('lang', 'Reject application')" />
											</button>
									</form>
								</xsl:if>
								<xsl:if test="application/status='PENDING' or application/status='REJECTED' or application/status='NEWPARTIAL1'">
									<xsl:choose>
										<xsl:when test="num_associations='0'">
											<button type="submit" disabled="" value="{php:function('lang', 'Accept application')}" class="dropdown-item" >
												<i class="fas fa-flag me-1 text-secondary"></i>
												<xsl:value-of select="php:function('lang', 'One or more bookings, allocations or events needs to be created before an application can be Accepted')"/>
											</button>
										</xsl:when>
										<xsl:when test="num_associations!='0'">
											<div>
												<button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#acceptApplicationModal">
													<xsl:choose>
														<!--xsl:when test="not(application/case_officer)"-->
														<xsl:when test="not(application/case_officer/is_current_user)">
															<xsl:attribute name="disabled">disabled</xsl:attribute>
															<i class="fas fa-flag me-1 text-secondary"></i>
														</xsl:when>
														<xsl:otherwise>
															<i class="fas fa-flag me-1 text-primary"></i>
														</xsl:otherwise>
													</xsl:choose>
													<xsl:value-of select="php:function('lang', 'Accept application')" />
												</button>
											</div>
										</xsl:when>
									</xsl:choose>
								</xsl:if>
								<div>
									<xsl:choose>
										<xsl:when test="external_archive != '' and application/external_archive_key =''">
											<form method="POST" action ="{export_pdf_action}" >
												<input type="hidden" name="export" value="pdf"/>
												<button onclick="return confirm('{php:function('lang', 'transfer case to external system?')}')" type="submit" class="dropdown-item" >
													<xsl:if test="not(application/case_officer/is_current_user)">
														<xsl:attribute name="disabled">disabled</xsl:attribute>
													</xsl:if>
													<i class="fas fa-flag me-1 text-primary"></i>
													<xsl:value-of select="php:function('lang', 'PDF-export to archive')" />
												</button>
											</form>
											<form method="POST" action ="{export_pdf_action}" >
												<input type="hidden" name="export" value="pdf"/>
												<input type="hidden" name="preview" value="1"/>
												<button onclick="return confirm('{php:function('lang', 'transfer case to external system?')}')" type="submit" class="dropdown-item" >
													<xsl:if test="not(application/case_officer/is_current_user)">
														<xsl:attribute name="disabled">disabled</xsl:attribute>
													</xsl:if>
													<i class="fas fa-flag me-1 text-primary"></i>
													<xsl:value-of select="php:function('lang', 'PDF-export to archive')" />
													<xsl:text> (</xsl:text>
													<xsl:value-of select="php:function('lang', 'preview')"/>
													<xsl:text>)</xsl:text>
												</button>
											</form>
										</xsl:when>
									</xsl:choose>
								</div>

								<xsl:if test="application/edit_link">
									<button class="dropdown-item">
										<xsl:choose>
											<xsl:when test="not(application/case_officer/is_current_user)">
												<xsl:attribute name="disabled">disabled</xsl:attribute>
												<i class="fas fa-flag me-1 text-secondary"></i>
											</xsl:when>
											<xsl:otherwise>
												<xsl:choose>
													<xsl:when test="show_edit_selection = 1">
														<xsl:attribute name="onclick">$('#editSelectionModal').modal('show')</xsl:attribute>
														<i class="fas fa-edit me-1 text-primary"></i>
													</xsl:when>
													<xsl:otherwise>
														<xsl:attribute name="onclick">window.location.href='<xsl:value-of select="application/edit_link"/>'</xsl:attribute>
														<i class="fas fa-edit me-1 text-primary"></i>
													</xsl:otherwise>
												</xsl:choose>
											</xsl:otherwise>
										</xsl:choose>
										<xsl:value-of select="php:function('lang', 'Edit')" />
									</button>
								</xsl:if>
								<a class="dropdown-item" href="{application/dashboard_link}">
									<i class="fas fa-flag me-1 text-primary"></i>
									<xsl:value-of select="php:function('lang', 'Back to Dashboard')" />
								</a>
								<a class="dropdown-item">
									<xsl:attribute name="href">
										<xsl:value-of select="php:function('get_phpgw_link', '/index.php', 'menuaction:booking.uiapplication.index')" />
									</xsl:attribute>
									<i class="fas fa-flag me-1 text-primary"></i>Tilbake til hovedoversikt
								</a>
							</div>
						</div>
					</li>

				</ul>
			</div>
			<div class="col-6">
				<ul class="list-inline float-end" role="tablist">
					<li class="nav-item list-inline-item me-2">
						<button class="btn btn-outline-primary active border" data-bs-toggle="tab" data-bs-target="#booking">
							<i class="fas fa-calendar-alt fa-2x" aria-hidden="true" title="Søknad"></i>
						</button>

					</li>
					<li class="nav-item list-inline-item me-2">
						<button class="btn btn-outline-warning border" data-bs-toggle="tab" data-bs-target="#internal_notes">
							<i class="far fa-sticky-note fa-2x text-warning" aria-hidden="true" title="Interne notat"></i>
						</button>

					</li>
					<!--li class="nav-item nav-item me-2">
						<button class="nav-link border" data-bs-toggle="tab" href="#checklist">
							<i class="fas fa-clipboard-list fa-3x" aria-hidden="true" title="Sjekkliste"></i>
						</button>
					</li>-->
					<li class="nav-item list-inline-item me-2">
						<button class="btn btn-outline-primary border" data-bs-toggle="tab" data-bs-target="#history">
							<i class="fas fa-history fa-2x" aria-hidden="true" title="Historikk"></i>
						</button>
					</li>
				</ul>
			</div>

		</div>


		<!-- TABS LINE START -->

		<div class="row">

		</div>
		<!-- TABS LINE END -->
		<div class="tab-content">

			<!-- FIRST PANE START -->
			<div class="tab-pane active" id="booking">

				<!--				<div class="row mt-2 float-end">
					<div class="container-fluid">


						<a href="#" class="btn btn-success btn-icon-split me-2">
							<span class="icon text-white-50">
								<i class="fas fa-check"></i>
							</span>
							<span class="text">Godkjenn</span>
						</a>

						<a href="#" class="btn btn-danger btn-icon-split">
							<span class="icon text-white-50">
								<i class="fas fa-trash"></i>
							</span>
							<span class="text">Avslå</span>
						</a>
					</div>
				</div>-->

				<div class="clearfix"></div>


				<div class="row mt-3">
					<div class="d-flex w-100 justify-content-between">
						<p class="mb-1">
							<xsl:value-of select="php:function('lang', 'case officer')" />
						</p>
					</div>
					<div class="d-flex w-100">
						<xsl:choose>
							<xsl:when test="application/case_officer_full_name !=''">
								<xsl:value-of select="application/case_officer_full_name"/>
							</xsl:when>
							<xsl:otherwise>
								<xsl:value-of select="php:function('lang', 'none')" />
							</xsl:otherwise>
						</xsl:choose>
					</div>
					<div class="d-flex w-100">
						<xsl:choose>
							<xsl:when test="not(application/case_officer)">

								<div class="alert alert-primary" role="alert">
									<xsl:value-of select="php:function('lang', 'In order to work with this application, you must first')"/>
									<xsl:text> </xsl:text>
									<xsl:value-of select="php:function('lang', 'assign yourself')"/>
									<xsl:text> </xsl:text>
									<xsl:value-of select="php:function('lang', 'as the case officer responsible for this application.')"/>
								</div>
							</xsl:when>
							<xsl:when test="application/case_officer and not(application/case_officer/is_current_user)">
								<div class="alert alert-primary" role="alert">
									<xsl:value-of select="php:function('lang', 'The user currently assigned as the responsible case officer for this application is')"/>
									<xsl:text> </xsl:text>'<xsl:value-of select="application/case_officer_full_name"/>'.
									<br/>
									<xsl:value-of select="php:function('lang', 'In order to work with this application, you must therefore first')"/>
									<xsl:text> </xsl:text>
									<xsl:value-of select="php:function('lang', 'assign yourself')"/>
									<xsl:text> </xsl:text>
									<xsl:value-of select="php:function('lang', 'as the case officer responsible for this application.')"/>
								</div>
							</xsl:when>
							<xsl:otherwise>
								<xsl:attribute name="style">display:none</xsl:attribute>
							</xsl:otherwise>
						</xsl:choose>
					</div>

					<!-- Only show status and dates for single applications (detailed info is in summary above for multiple) -->
					<xsl:if test="application/related_application_count &lt;= 1">
						<div class="d-flex w-100 justify-content-between">
							<p class="mb-1 mt-3">
								<xsl:value-of select="php:function('lang', 'Status')" />
							</p>
						</div>
						<p>
							<xsl:value-of select="php:function('lang', string(application/status))"/>
						</p>
						<div class="d-flex w-100 justify-content-between">
							<p class="mb-1">
								<xsl:value-of select="php:function('lang', 'Created')" />
							</p>
						</div>
						<p>
							<xsl:value-of select="php:function('pretty_timestamp', application/created)"/>
						</p>
						<div class="d-flex w-100 justify-content-between">
							<p class="mb-1">
								<xsl:value-of select="php:function('lang', 'Modified')" />
							</p>
						</div>
						<p>
							<xsl:value-of select="php:function('pretty_timestamp', application/modified)"/>
						</p>
					</xsl:if>
					<xsl:if test="application/external_archive_key !=''">
						<div class="d-flex w-100 justify-content-between">
							<p class="mb-1">
								<xsl:value-of select="php:function('lang', 'external archive key')"/>
							</p>
						</div>
						<p>
							<xsl:value-of select="application/external_archive_key"/>
						</p>
					</xsl:if>
				</div>

				<!-- Accordian -->
				<div class="row mt-3">

					<div class="container-fluid">
						<div class="row">
							<div class="col-md-12">
								<!-- Show related applications summary if multiple applications -->
								<xsl:if test="application/related_application_count > 1">
									<div class="alert alert-info mb-4">
										<h5 class="alert-heading">
											<i class="fas fa-info-circle me-2"></i>
											<xsl:value-of select="php:function('lang', 'Combined Applications Summary')" />
										</h5>
										<p class="mb-3">
											<xsl:value-of select="php:function('lang', 'This view combines information from')" />
											<xsl:text> </xsl:text>
											<strong><xsl:value-of select="application/related_application_count"/></strong>
											<xsl:text> </xsl:text>
											<xsl:value-of select="php:function('lang', 'related applications')" />:
										</p>
										<div class="row">
											<xsl:for-each select="application/related_applications_info">
												<div class="col-md-6 mb-3">
													<div class="card border-light">
														<div class="card-body p-3">
															<h6 class="card-title">
																<strong>
																	<xsl:value-of select="php:function('lang', 'Application')" />
																	<xsl:text> #</xsl:text>
																	<xsl:value-of select="id"/>
																</strong>
															</h6>
															<p class="card-text mb-2">
																<strong><xsl:value-of select="name"/></strong>
															</p>
															<p class="card-text mb-2">
																<small class="text-muted">
																	<xsl:value-of select="php:function('lang', 'Status')" />:
																	<span class="badge bg-primary text-white"><xsl:value-of select="php:function('lang', string(status))"/></span>
																</small>
															</p>
															<p class="card-text mb-2">
																<small class="text-muted">
																	<xsl:value-of select="php:function('lang', 'Created')" />: <xsl:value-of select="created"/>
																</small>
															</p>
															<xsl:if test="date_ranges">
																<p class="card-text mb-1">
																	<small><strong><xsl:value-of select="php:function('lang', 'Dates')" />:</strong></small>
																</p>
																<ul class="list-unstyled mb-2">
																	<xsl:for-each select="date_ranges">
																		<li><small>• <xsl:value-of select="."/></small></li>
																	</xsl:for-each>
																</ul>
															</xsl:if>
															<xsl:if test="resource_names">
																<p class="card-text mb-1">
																	<small><strong><xsl:value-of select="php:function('lang', 'Resources')" />:</strong></small>
																</p>
																<p class="card-text">
																	<small>
																		<xsl:for-each select="resource_names">
																			<xsl:value-of select="."/>
																			<xsl:if test="position() != last()">, </xsl:if>
																		</xsl:for-each>
																	</small>
																</p>
															</xsl:if>
														</div>
													</div>
												</div>
											</xsl:for-each>
										</div>
									</div>
								</xsl:if>

								<div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">
									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingOne">
											<h4 class="panel-title">
												<a role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseOne" aria-expanded="true" aria-controls="collapseOne" class="">
													Søker: <xsl:value-of select="application/contact_name"/>
													<xsl:if test="organization/name != ''">
														<span class="text-muted"> - <xsl:value-of select="organization/name"/></span>
													</xsl:if>
												</a>
											</h4>
										</div>
										<div id="collapseOne" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingOne" style="">
											<div class="panel-body">
												<div class="list-group">
													<div class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Name')" />
															</h5>
															<small></small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/contact_name"/>
														</p>
														<!--<small>Dette er søkers første søknad i Aktiv kommune.</small>-->
													</div>
													<div href="#" class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Email')" />
															</h5>
															<small class="text-body-secondary"></small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/contact_email"/>
														</p>
														<small class="text-body-secondary"></small>
													</div>
													<div href="#" class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Phone')" />
															</h5>
															<small class="text-body-secondary"></small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/contact_phone"/>
														</p>
														<small class="text-body-secondary"></small>
													</div>
													<xsl:if test="organization/name != ''">
														<div href="#" class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Organization')" />
																</h5>
																<small class="text-body-secondary">Organisasjon</small>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="organization/name"/>
															</p>
															<xsl:if test="organization/organization_number != ''">
																<small class="text-body-secondary">
																	Org.nr: <xsl:value-of select="organization/organization_number"/>
																</small>
															</xsl:if>
														</div>
													</xsl:if>
												</div>
											</div>
										</div>
									</div>

									<xsl:if test="organization/name != ''">
										<div class="panel panel-default">
											<div class="panel-heading" role="tab" id="headingTwo">
												<h4 class="panel-title">
													<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseTwo" aria-expanded="true" aria-controls="collapseTwo">
														<xsl:value-of select="php:function('lang', 'Organization')" />: <xsl:value-of select="organization/name"/>
													</a>
												</h4>
											</div>
											<div id="collapseTwo" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingTwo">
												<div class="panel-body">

													<div class="list-group">
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Organization')" />
																</h5>
																<small>Hentet fra enhetsregisteret</small>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="organization/name"/>
															</p>
															<!--<small>Dette er organisasjonens første søknad i Aktiv kommune.</small>-->
														</div>
														<xsl:if test="application/customer_identifier_type = 'organization_number'">
															<div href="#" class="list-group-item flex-column align-items-start">
																<div class="d-flex w-100 justify-content-between">
																	<h5 class="mb-1">
																		<xsl:value-of select="php:function('lang', 'organization number')" />
																	</h5>
																	<small class="text-body-secondary"></small>
																</div>
																<p class="mb-1 font-weight-bold">
																	<xsl:value-of select="organization/organization_number"/>
																</p>
																<small class="text-body-secondary"></small>
															</div>
														</xsl:if>
														<div href="#" class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'in tax register')"/>
																</h5>
																<small class="text-body-secondary">Hentet fra brukerinput</small>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:choose>
																	<xsl:when test="organization/in_tax_register = 1">
																		<xsl:value-of select="php:function('lang', 'yes')"/>
																	</xsl:when>
																	<xsl:otherwise>
																		<xsl:value-of select="php:function('lang', 'no')"/>
																	</xsl:otherwise>
																</xsl:choose>
															</p>
															<small class="text-body-secondary"></small>
														</div>
													</div>
												</div>
											</div>
										</div>
									</xsl:if>

									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingTwelve">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="collapseTwelve" aria-expanded="true" aria-controls="collapseTwelve">
													<xsl:value-of select="php:function('lang', 'invoice information')" />
												</a>
											</h4>
										</div>
										<div id="collapseTwelve" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingTwelve">
											<div class="panel-body">

												<div class="list-group">
													<xsl:if test="application/customer_identifier_type = 'organization_number'">
														<div href="#" class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'organization number')" />
																</h5>
																<small class="text-body-secondary"></small>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="organization/organization_number"/>
															</p>
															<small class="text-body-secondary"></small>
														</div>
													</xsl:if>
													<xsl:if test="application/customer_identifier_type = 'ssn'">

														<div href="#" class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Date of birth or SSN')" />
																</h5>
																<small class="text-body-secondary">Hentet fra ID-Porten</small>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="substring (application/customer_ssn ,1, 6 )"/>
																<xsl:text>*****</xsl:text>
															</p>
															<small class="text-body-secondary"></small>
														</div>
													</xsl:if>
													<div href="#" class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Street')"/>
															</h5>
															<small class="text-body-secondary">Hentet fra brukerinput</small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/responsible_street"/>
														</p>
														<small class="text-body-secondary"></small>
													</div>
													<div href="#" class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Zip code')"/>
															</h5>
															<small class="text-body-secondary">Hentet fra brukerinput</small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/responsible_zip_code"/>
														</p>
														<small class="text-body-secondary"></small>
													</div>
													<div href="#" class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Postal City')"/>
															</h5>
															<small class="text-body-secondary">Hentet fra brukerinput</small>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/responsible_city"/>
														</p>
														<small class="text-body-secondary"></small>
													</div>
												</div>
											</div>
										</div>
									</div>
									<xsl:if test="simple != 1">

										<div class="panel panel-default">
											<div class="panel-heading" role="tab" id="headingThree">
												<h4 class="panel-title">
													<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseThree" aria-expanded="true" aria-controls="collapseThree">
														<xsl:value-of select="php:function('lang', 'Who?')" />
													</a>
												</h4>
											</div>
											<div id="collapseThree" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingThree">
												<div class="panel-body">

													<div class="list-group">
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Target audience')" />
																</h5>
																<small></small>
															</div>
															<p class="mb-1 font-weight-bold">
																<ul class="list-left">
																	<xsl:for-each select="audience">
																		<xsl:if test="../application/audience=id">
																			<li>
																				<xsl:value-of select="name"/>
																			</li>
																		</xsl:if>
																	</xsl:for-each>
																</ul>
															</p>
															<small></small>
														</div>
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Number of participants')" />
																</h5>
																<small></small>
															</div>
															<p class="mb-1 font-weight-bold">
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
															</p>
															<small></small>
														</div>
													</div>
												</div>
											</div>
										</div>
									</xsl:if>
									<xsl:if test="simple != 1">
										<div class="panel panel-default">
											<div class="panel-heading" role="tab" id="headingFour">
												<h4 class="panel-title">
													<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseFour" aria-expanded="true" aria-controls="collapseFour">
														<xsl:value-of select="php:function('lang', 'Why?')" />
													</a>
												</h4>
											</div>
											<div id="collapseFour" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingFour">
												<div class="panel-body">
													<div class="list-group">
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Activity')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="application/activity_name"/>
															</p>
														</div>
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Event name')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="application/name" disable-output-escaping="yes"/>
															</p>
														</div>
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Description')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="application/description" disable-output-escaping="yes"/>
															</p>
														</div>
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Extra info')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="application/equipment" disable-output-escaping="yes"/>
															</p>
														</div>


														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Organizer')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:value-of select="application/organizer" disable-output-escaping="yes"/>
															</p>
														</div>
														<div class="list-group-item flex-column align-items-start">
															<div class="d-flex w-100 justify-content-between">
																<h5 class="mb-1">
																	<xsl:value-of select="php:function('lang', 'Homepage')" />
																</h5>
															</div>
															<p class="mb-1 font-weight-bold">
																<xsl:if test="application/homepage and normalize-space(application/homepage)">
																	<a>
																		<xsl:attribute name="href">
																			<xsl:value-of select="application/homepage"/>
																		</xsl:attribute>
																		<xsl:value-of select="application/homepage"/>
																	</a>
																</xsl:if>
															</p>
														</div>
													</div>
												</div>
											</div>
										</div>
									</xsl:if>
									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingFive">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseFive" aria-expanded="true" aria-controls="collapseFive">
													Ønsker ressurs: <i class="fas fa-redo-alt text-primary"></i>
												</a>
											</h4>
										</div>

										<div id="collapseFive" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingFive">
											<div class="panel-body">
												<div class="list-group">
													<div class="list-group-item flex-column align-items-start">
														<div class="d-flex w-100 justify-content-between">
															<h5 class="mb-1">
																<xsl:value-of select="php:function('lang', 'Building')" />
															</h5>
														</div>
														<p class="mb-1 font-weight-bold">
															<xsl:value-of select="application/building_name"/>
															(<a href="javascript: void(0)" onclick="window.open('{application/schedule_link}', '', 'width=1048, height=600, scrollbars=yes');return false;">
																<xsl:value-of select="php:function('lang', 'Building schedule')" />
															</a>)
														</p>

														<!-- Display application count if multiple applications -->
														<xsl:if test="application/related_application_count > 1">
															<p class="mb-1" style="font-weight: bold; color: #2c5aa0;">
																<xsl:value-of select="application/related_application_count"/> applications combined
															</p>
														</xsl:if>
													</div>
												</div>

												<!-- Resources now shown with dates below -->
												<div class="list-group">
													<div class="list-group-item flex-column align-items-start">
														<!-- Show combined orders for multiple applications -->
<!--														<xsl:if test="application/related_application_count > 1 and application/combined_orders">-->
<!--															<div class="d-flex w-100 justify-content-between">-->
<!--																<h5 class="mb-1">-->
<!--																	<xsl:value-of select="php:function('lang', 'Orders from all applications')" />-->
<!--																</h5>-->
<!--															</div>-->
<!--															<xsl:for-each select="application/combined_orders">-->
<!--																<div class="mb-3">-->
<!--																	<h6><xsl:value-of select="php:function('lang', 'Order')" /> #<xsl:value-of select="order_id"/></h6>-->
<!--																	<table class="table table-sm">-->
<!--																		<thead>-->
<!--																			<tr>-->
<!--																				<th><xsl:value-of select="php:function('lang', 'Article')" /></th>-->
<!--																				<th><xsl:value-of select="php:function('lang', 'Quantity')" /></th>-->
<!--																				<th><xsl:value-of select="php:function('lang', 'Unit Price')" /></th>-->
<!--																				<th><xsl:value-of select="php:function('lang', 'Amount')" /></th>-->
<!--																			</tr>-->
<!--																		</thead>-->
<!--																		<tbody>-->
<!--																			<xsl:for-each select="lines">-->
<!--																				<tr>-->
<!--																					<td><xsl:value-of select="name"/></td>-->
<!--																					<td><xsl:value-of select="quantity"/> <xsl:value-of select="unit"/></td>-->
<!--																					<td><xsl:value-of select="unit_price"/></td>-->
<!--																					<td><xsl:value-of select="amount"/></td>-->
<!--																				</tr>-->
<!--																			</xsl:for-each>-->
<!--																		</tbody>-->
<!--																		<tfoot>-->
<!--																			<tr>-->
<!--																				<th colspan="3"><xsl:value-of select="php:function('lang', 'Total')" /></th>-->
<!--																				<th><xsl:value-of select="sum"/></th>-->
<!--																			</tr>-->
<!--																		</tfoot>-->
<!--																	</table>-->
<!--																</div>-->
<!--															</xsl:for-each>-->
<!--														</xsl:if>-->

														<!-- Always show articles_container for JavaScript-populated orders -->
														<div id="articles_container" style="display:inline-block;">
														</div>
													</div>
												</div>
											</div>
										</div>
									</div>

									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingSix">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseSix" aria-expanded="true" aria-controls="collapseSix">
													<i class="fas fa-calendar-plus me-2"></i>
													<xsl:value-of select="php:function('lang', 'recurring_allocation')" />
												</a>
											</h4>
										</div>

										<div id="collapseSix" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingSix">
											<div class="panel-body">
<div class="row mb-3">
	<div class="col-md-6">
		<h6 class="text-muted mb-2">
			<i class="fas fa-cog me-1"></i><xsl:value-of select="php:function('lang', 'settings')" />
		</h6>
		<div class="d-flex flex-wrap gap-2 mb-3">
			<span class="badge bg-secondary">
				<i class="fas fa-calendar-week me-1"></i>
				Hver
				<xsl:choose>
					<xsl:when test="recurring_data/field_interval = 1">uke</xsl:when>
					<xsl:otherwise><xsl:value-of select="recurring_data/field_interval"/> uker</xsl:otherwise>
				</xsl:choose>
			</span>
			<xsl:if test="season_info/name">
				<span class="badge bg-primary">
					<i class="fas fa-calendar-alt me-1"></i>
					<xsl:value-of select="season_info/name"/>
				</span>
			</xsl:if>
			<xsl:if test="recurring_data/repeat_until != '' or recurring_data/calculated_repeat_until">
				<span class="badge bg-info">
					<i class="fas fa-calendar-check me-1"></i>
					Til
					<xsl:choose>
						<xsl:when test="recurring_data/repeat_until != ''">
							<xsl:value-of select="recurring_data/repeat_until"/>
						</xsl:when>
						<xsl:otherwise>
							<xsl:value-of select="recurring_data/calculated_repeat_until"/>
						</xsl:otherwise>
					</xsl:choose>
				</span>
			</xsl:if>
			<xsl:if test="recurring_data/outseason = 1">
				<span class="badge bg-success">
					<i class="fas fa-calendar-check me-1"></i>
					<xsl:value-of select="php:function('lang', 'follows_season_end')" />
				</span>
			</xsl:if>
		</div>

		<xsl:if test="season_info">
			<div class="alert alert-light border mb-3">
				<div class="d-flex align-items-center">
					<i class="fas fa-info-circle text-primary me-2"></i>
					<div>
						<strong>Sesong: <xsl:value-of select="season_info/name"/></strong><br/>
						<small class="text-muted">
							Periode: <xsl:value-of select="season_info/from_formatted"/> - <xsl:value-of select="season_info/to_formatted"/>
							<span class="mx-2">•</span>
							<xsl:value-of select="season_info/end_reason"/>
						</small>
					</div>
				</div>
			</div>
		</xsl:if>
	</div>
	<div class="col-md-6 text-end">
		<xsl:choose>
			<xsl:when test="application/case_officer/is_current_user and can_create_allocations = 1">
				<a class="btn btn-success btn-sm shadow-sm">
					<xsl:attribute name="href">
						<xsl:value-of select="recurring_allocation_url"/>
					</xsl:attribute>
					<xsl:choose>
						<xsl:when test="has_conflicts = 1">
							<i class="fas fa-exclamation-triangle me-2"></i>
						</xsl:when>
						<xsl:otherwise>
							<i class="fas fa-plus-circle me-2"></i>
						</xsl:otherwise>
					</xsl:choose>
					<xsl:value-of select="php:function('lang', string(create_button_text))" />
					<xsl:if test="create_button_count > 0">
						<span class="badge bg-light text-dark ms-2"><xsl:value-of select="create_button_count"/></span>
					</xsl:if>
				</a>
			</xsl:when>
			<xsl:when test="application/case_officer/is_current_user and can_create_allocations = 0">
				<button class="btn btn-secondary btn-sm shadow-sm" disabled="disabled">
					<xsl:attribute name="title">
						<xsl:value-of select="php:function('lang', 'use_edit_to_modify_allocations')" />
					</xsl:attribute>
					<i class="fas fa-check-circle me-2"></i>
					<xsl:choose>
						<xsl:when test="has_partial_allocations_with_conflicts = 1">
							<xsl:value-of select="php:function('lang', 'allocations_created_with_conflicts')" />
						</xsl:when>
						<xsl:otherwise>
							<xsl:value-of select="php:function('lang', 'all_allocations_created')" />
						</xsl:otherwise>
					</xsl:choose>
				</button>
			</xsl:when>
			<xsl:otherwise>
				<button class="btn btn-success btn-sm shadow-sm" disabled="disabled">
					<xsl:attribute name="title">
						<xsl:value-of select="php:function('lang', 'only_case_officer_can_create')" />
					</xsl:attribute>
					<i class="fas fa-lock me-2"></i>
					<xsl:value-of select="php:function('lang', string(create_button_text))" />
					<xsl:if test="create_button_count > 0">
						<span class="badge bg-light text-dark ms-2"><xsl:value-of select="create_button_count"/></span>
					</xsl:if>
				</button>
			</xsl:otherwise>
		</xsl:choose>
	</div>
</div>

<div class="row">
	<div class="col-12">
		<h6 class="text-muted mb-3">
			<i class="fas fa-list me-1"></i>
			<xsl:value-of select="php:function('lang', 'allocations_to_be_created')" />
			<span class="badge bg-light text-dark ms-2">
				<xsl:value-of select="count(recurring_preview/*)"/> stk
			</span>
		</h6>

		<div class="table-responsive">
			<table class="table table-sm table-hover">
				<thead class="table-light">
					<tr>
						<th style="width: 20%;"><i class="fas fa-calendar me-1"></i>Dato</th>
						<th style="width: 15%;"><i class="fas fa-clock me-1"></i>Tid</th>
						<th style="width: 15%;"><i class="fas fa-cube me-1"></i><xsl:value-of select="php:function('lang', 'resources')" /></th>
						<th style="width: 20%;"><i class="fas fa-check-circle me-1"></i>Status</th>
						<th style="width: 30%;"><i class="fas fa-cogs me-1"></i>Handling</th>
					</tr>
				</thead>
				<tbody>
					<xsl:for-each select="recurring_preview/*">
						<tr>
							<xsl:choose>
								<xsl:when test="exists = 1">
									<xsl:attribute name="class">table-success</xsl:attribute>
								</xsl:when>
								<xsl:otherwise>
									<xsl:attribute name="class">table-light</xsl:attribute>
								</xsl:otherwise>
							</xsl:choose>
							<td>
								<span class="fw-medium">
									<xsl:value-of select="date_display"/>
								</span>
							</td>
							<td>
								<span class="font-monospace">
									<xsl:value-of select="time_display"/>
								</span>
							</td>
							<td>
								<span class="text-muted small">
									<xsl:value-of select="resource_display"/>
								</span>
							</td>
							<td>
								<xsl:choose>
									<xsl:when test="exists = 1">
										<span class="badge bg-success">
											<i class="fas fa-check-circle me-1"></i>
											<xsl:value-of select="php:function('lang', 'created')" />
										</span>
									</xsl:when>
									<xsl:when test="has_conflict = 1">
										<span class="badge bg-danger">
											<i class="fas fa-exclamation-triangle me-1"></i>
											<xsl:value-of select="php:function('lang', 'conflict')" />
										</span>
									</xsl:when>
									<xsl:otherwise>
										<span class="badge bg-secondary">
											<i class="fas fa-hourglass-half me-1"></i>
											<xsl:value-of select="php:function('lang', 'pending')" />
										</span>
									</xsl:otherwise>
								</xsl:choose>
							</td>
							<td>
								<xsl:choose>
									<xsl:when test="exists = 1">
										<a class="btn btn-outline-primary btn-sm">
											<xsl:attribute name="href">
												<xsl:value-of select="allocation_link"/>
											</xsl:attribute>
											<xsl:attribute name="title">
												Vis tildeling ID <xsl:value-of select="allocation_id"/>
											</xsl:attribute>
											<i class="fas fa-eye me-1"></i>
											Vis
										</a>
									</xsl:when>
									<xsl:when test="has_conflict = 1">
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
															<xsl:when test="type = 'event'">
																<i class="fas fa-star me-1"></i>
															</xsl:when>
														</xsl:choose>
														<xsl:value-of select="name"/>
													</a>
												</xsl:for-each>
											</div>
											<xsl:if test="schedule_link != ''">
												<a class="btn btn-outline-warning btn-sm">
													<xsl:attribute name="href">
														<xsl:value-of select="schedule_link"/>
													</xsl:attribute>
													<xsl:attribute name="title">
														<xsl:value-of select="php:function('lang', 'view_schedule_this_day')" />
													</xsl:attribute>
													<xsl:attribute name="target">_blank</xsl:attribute>
													<i class="fas fa-calendar me-1"></i>
													<xsl:value-of select="php:function('lang', 'view_schedule')" />
												</a>
											</xsl:if>
										</div>
									</xsl:when>
									<xsl:otherwise>
										<span class="text-muted small">
											<i class="fas fa-clock me-1"></i>
											<xsl:value-of select="php:function('lang', 'not_created_yet')" />
										</span>
									</xsl:otherwise>
								</xsl:choose>
							</td>
						</tr>
					</xsl:for-each>
				</tbody>
			</table>
		</div>

		<xsl:if test="not(recurring_preview/*)">
			<div class="text-center text-muted py-4">
				<i class="fas fa-calendar-times fa-3x mb-3 text-muted"></i>
				<p class="mb-0">Ingen gjentakende tildelinger funnet</p>
				<small>Sjekk innstillingene for gjentakende tildeling</small>
			</div>
		</xsl:if>
	</div>
</div>
											</div>
										</div>
									</div>

									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingSeven">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseSeven" aria-expanded="true" aria-controls="collapseSeven">
													<xsl:value-of select="php:function('lang', 'payments')" />
												</a>
											</h4>
										</div>
										<div id="collapseSeven" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingSeven">
											<div class="panel-body">
												<div class="list-group-item flex-column align-items-start">
													<div id="payments_container"/>
												</div>
												<div class="list-group-item flex-column align-items-start" id="order_details"> <!-- style="display:none;"-->
													<div class="d-flex w-100 justify-content-between">
														<h5 class="mb-1">
															<xsl:value-of select="php:function('lang', 'details')" />
														</h5>
													</div>
													<div id="order_container"/>
												</div>
											</div>
										</div>
									</div>
									<!--									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingEight">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseEight" aria-expanded="true" aria-controls="collapseEight">
													Booking-konflikter på ressurs: Ingen
												</a>
											</h4>
										</div>
										<div id="collapseEight" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingEight">
											<div class="panel-body">
											</div>
										</div>
									</div>-->
									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingNine">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseNine" aria-expanded="true" aria-controls="collapseNine">
													<xsl:value-of select="php:function('lang', 'Associated items')" />
												</a>
											</h4>
										</div>
										<div id="collapseNine" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingNine">
											<div class="panel-body">
												<div id="associated_container"/>
											</div>
										</div>
									</div>
									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingTen">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseTen" aria-expanded="true" aria-controls="collapseTen">
													<xsl:value-of select="php:function('lang', 'attachments')" />
												</a>
											</h4>
										</div>
										<div id="collapseTen" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingTen">
											<div class="panel-body">
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
													<input type="submit" value="{php:function('lang', 'Add attachment')}" class="pure-button pure-button-primary"/>
												</form>
											</div>
										</div>
									</div>
									<div class="panel panel-default">
										<div class="panel-heading" role="tab" id="headingEleven">
											<h4 class="panel-title">
												<a class="" role="button" data-bs-toggle="collapse" data-parent="#accordion" href="#collapseEleven" aria-expanded="true" aria-controls="collapseEleven">
													<xsl:value-of select="php:function('lang', 'Terms and conditions')" />
												</a>
											</h4>
										</div>
										<div id="collapseEleven" class="panel-collapse collapse show" role="tabpanel" aria-labelledby="headingEleven">
											<div class="panel-body">
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
											<div class="panel-body">
												<!--<legend>-->
												<h4>
													<xsl:value-of select="php:function('lang', 'additional requirements')" />
												</h4>
												<!--</legend>-->
												<xsl:value-of disable-output-escaping="yes" select="application/agreement_requirements"/>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>


				</div>
				<!-- END ACCORDIAN -->

			</div>
			<!-- /FIRST PANE END -->

			<!-- SECOND PANE START -->
			<div class="tab-pane fade" id="internal_notes">
				<xsl:variable name="date_format">
					<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />
					<!--<xsl:text> H:i</xsl:text>-->
				</xsl:variable>
				<h3>
					<xsl:value-of select="php:function('lang', 'internal notes')" />
				</h3>

				<xsl:for-each select="internal_notes">
					<div class="panel-body">
						<div class="list-group">
							<div href="#" class="list-group-item flex-column align-items-start">
								<div class="d-flex w-100 justify-content-between">
									<h5 class="mb-1">
										<xsl:value-of select="php:function('date', $date_format, number(datetime))"/>
									</h5>
									<small class="text-body-secondary">
										<xsl:value-of select="owner"/>
									</small>
								</div>
								<p class="mb-1 font-weight-bold">
									<xsl:value-of select="new_value" disable-output-escaping="yes"/>
								</p>
								<small class="text-body-secondary"></small>
							</div>
						</div>
					</div>
				</xsl:for-each>
			</div>

			<!-- FOURTH PANE START -->
			<div class="tab-pane fade" id="history">
				<h3>
					<xsl:value-of select="php:function('lang', 'History and comments (%1)', count(application/comments/author))" />
				</h3>

				<xsl:for-each select="application/comments[author]">
					<div class="panel-body">
						<div class="list-group">
							<div href="#" class="list-group-item flex-column align-items-start">
								<div class="d-flex w-100 justify-content-between">
									<h5 class="mb-1">
										<xsl:value-of select="php:function('pretty_timestamp', time)"/>
									</h5>
									<small class="text-body-secondary">
										<xsl:value-of select="author"/>
									</small>
								</div>
								<p class="mb-1 font-weight-bold">
									<xsl:value-of select="comment" disable-output-escaping="yes"/>
								</p>
								<small class="text-body-secondary"></small>
							</div>
						</div>
					</div>
				</xsl:for-each>
			</div>

			<!-- FOURTH PANE END -->

			<!-- /END TABS CONTAINER -->
		</div>

	</div>

	<!--			<div id="application" class="booking-container">
	</div>-->

	<script type="text/javascript">
		var template_set = '<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|template_set')" />';
		var date_format = '<xsl:value-of select="php:function('get_phpgw_info', 'user|preferences|common|dateformat')" />';
		var initialSelection = <xsl:value-of select="application/resources_json"/>;
		var application_id = '<xsl:value-of select="application/id"/>';
		var resourceIds = '<xsl:value-of select="application/resource_ids"/>';
		var currentuser = '<xsl:value-of select="application/currentuser"/>';
		if (!resourceIds || resourceIds == "") {
		resourceIds = false;
		}
		var lang = <xsl:value-of select="php:function('js_lang', 'Resources', 'Resource Type', 'No records found', 'ID', 'Type', 'From', 'To', 'Document', 'Active' ,'Delete', 'del', 'Name', 'Cost', 'order id', 'unit cost', 'Amount', 'currency', 'status', 'payment method', 'refund','refunded', 'Actions', 'cancel', 'created', 'article', 'Select', 'cost', 'unit', 'quantity', 'Selected', 'Delete', 'Sum', 'tax')"/>;
		var app_id = <xsl:value-of select="application/id"/>;
		var building_id = <xsl:value-of select="application/building_id"/>;
		var resources = <xsl:value-of select="application/resources"/>;

	    <![CDATA[
			var resourcesURL = phpGWLink('index.php', {menuaction:'booking.uiresource.index', sort:'name', length:-1}, true) +'&' + resourceIds;
			var associatedURL = phpGWLink('index.php', {menuaction:'booking.uiapplication.associated', sort:'from_',dir:'asc',filter_application_id:app_id, length:-1}, true);
			var documentsURL = phpGWLink('index.php', {menuaction:'booking.uidocument_view.regulations', sort:'name', length:-1}, true) +'&owner[]=building::' + building_id;
			var attachmentsResourceURL = phpGWLink('index.php', {menuaction:'booking.uidocument_application.index', sort:'name', no_images:1, filter_owner_id:app_id, length:-1}, true);
			var paymentURL = phpGWLink('index.php', {menuaction:'booking.uiapplication.payments', sort:'order_id',dir:'asc',application_id:app_id, length:-1}, true);
			var articlesURL = phpGWLink('index.php', {menuaction:'booking.uiapplication.articles', sort:'id',dir:'asc',application_id:app_id, length:-1}, true);

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

		if (currentuser == 1)
		{
			var colDefsAttachmentsResource = [
			{key: 'name', label: lang['Name'], formatter: genericLink},
			{key: 'option_delete', label: lang['Delete'], formatter: genericLink2}];
		}
		else
		{
			var colDefsAttachmentsResource = [{key: 'name', label: lang['Name'], formatter: genericLink}];
		}

		createTable('attachments_container', attachmentsResourceURL, colDefsAttachmentsResource, '', 'pure-table pure-table-bordered');

		var colDefsArticles = [
		{key: 'article', label: lang['article']},
		{key: 'quantity', label: lang['quantity']},
		{key: 'unit', label: lang['unit']},
		{key: 'cost', label: lang['cost']},
		{key: 'currency', label: lang['currency']}
		];
		createTable('articles_container', articlesURL, colDefsArticles, '', 'pure-table pure-table-bordered');

		var colDefsPayment = [
		{
		label: lang['Select'],
		attrs: [{name: 'class', value: "align-middle"}],
		object: [
		{
		type: 'input',
		attrs: [
		{name: 'type', value: 'radio'},
		{name: 'name', value: 'order_selector'},
		{name: 'class', value: 'order_selector'},
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
		{key: 'actions', label: lang['Actions'], formatter: genericLink2({name: 'delete', label: lang['refund']}, {name: 'edit', label: lang['cancel']})}
		];

		createTable('payments_container', paymentURL, colDefsPayment,'', 'pure-table pure-table-bordered');

		// Auto-open approval modal if requested after allocation creation
		<xsl:if test="open_approve_modal = '1'">
			<![CDATA[
			$(document).ready(function() {
				// Check if approve modal exists and is not disabled
				var acceptModal = $('#acceptApplicationModal');
				var approveButton = $('button[data-bs-target="#acceptApplicationModal"]');

				if (acceptModal.length > 0 && approveButton.length > 0 && !approveButton.prop('disabled')) {
					// Show confirmation and then open modal
						acceptModal.modal('show');
				}
			});
			]]>
		</xsl:if>

	</script>


	<div class="modal fade" id="commentModal" tabindex="-1" role="dialog" aria-labelledby="commentModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="commentModalLabel">
						<xsl:value-of select="php:function('lang', 'Add a comment')" />
					</h5>
					<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">x</span>
					</button>
				</div>
				<div class="modal-body">
					<xsl:if test="application/edit_link">
						<div class="pure-u-1">
							<form method="POST">
								<div class="pure-control-group">
									<label for="comment">
									</label>
									<textarea name="comment" id="comment" required="required">

									</textarea>
									<br/>
								</div>
								<div class="pure-control-group">
									<label>&nbsp;</label>
									<input type="submit" value="{php:function('lang', 'Add comment')}" class="pure-button pure-button-primary" />
								</div>
							</form>
							<br/>
						</div>
					</xsl:if>
				</div>
			</div>
			<!-- /.modal-content -->
		</div>
		<!-- /.modal-dialog -->
	</div>

	<div class="modal fade" id="messengerModal" tabindex="-1" role="dialog" aria-labelledby="messengerModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="messengerModalLabel">
						<xsl:value-of select="php:function('lang', 'Add message')" />
						<xsl:text> (</xsl:text>
						<xsl:value-of select="application/case_officer_full_name"/>
						<xsl:text>)</xsl:text>
					</h5>

					<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">x</span>
					</button>
				</div>
				<form method="POST">
					<div class="modal-body">
						<xsl:if test="application/edit_link">
							<div class="form-group">
								<label for="message_subject">
									<xsl:value-of select="php:function('lang', 'Subject')" />
								</label>
								<input type="hidden" name="message_recipient" value="{application/case_officer_id}"/>
								<input type="text" name="message_subject" id="message_subject" required="required" class="form-control">
									<xsl:attribute name='value'>
										<xsl:value-of select="php:function('lang', 'application')" />
										<xsl:text> #</xsl:text>
										<xsl:value-of select="application/id" />
										<xsl:text> - </xsl:text>
										<xsl:value-of select="application/building_name" />
									</xsl:attribute>
								</input>
								<label for="message_content">
									<xsl:value-of select="php:function('lang', 'content')" />
								</label>
								<textarea name="message_content" id="message_content" required="required" class="form-control">
								</textarea>
							</div>
						</xsl:if>
					</div>
					<div class="modal-footer">
						<button type="submit" class="btn btn-primary">
							<xsl:value-of select="php:function('lang', 'save')" />
						</button>
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<xsl:value-of select="php:function('lang', 'cancel')" />
						</button>
					</div>
				</form>
			</div>
			<!-- /.modal-content -->
		</div>
		<!-- /.modal-dialog -->
	</div>

	<div class="modal fade" id="change_userModal" tabindex="-1" role="dialog" aria-labelledby="change_userModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="change_userModalLabel">
						<xsl:value-of select="php:function('lang', 'case officer')" />
					</h5>
					<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">x</span>
					</button>
				</div>
				<form method="POST">
					<div class="modal-body">
						<xsl:if test="application/edit_link">
							<div class="form-group">
								<select name="assign_to_new_user" id="new_case_officer" required="required" class="form-control" aria-describedby="case_officer_help">
									<option value="0">
										<xsl:value-of select="php:function('lang', 'select')" />
									</option>
									<xsl:apply-templates select="user_list/options"/>
								</select>
								<small id="case_officer_help" class="form-text text-body-secondary">velg ny saksbehandler</small>
							</div>
						</xsl:if>
					</div>
					<div class="modal-footer">
						<button type="submit" class="btn btn-primary">
							<xsl:value-of select="php:function('lang', 'save')" />
						</button>
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<xsl:value-of select="php:function('lang', 'cancel')" />
						</button>
					</div>

				</form>
			</div>
			<!-- /.modal-content -->
		</div>
		<!-- /.modal-dialog -->
	</div>

	<div class="modal fade" id="internal_noteModal" tabindex="-1" role="dialog" aria-labelledby="internal_noteModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="internal_noteModalLabel">
						<xsl:value-of select="php:function('lang', 'internal notes')" />
					</h5>
					<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
						<span aria-hidden="true">x</span>
					</button>
				</div>
				<form method="POST">
					<div class="modal-body">
						<xsl:if test="application/edit_link">
							<div class="form-group">
								<label for="internal_note_content">
									<xsl:value-of select="php:function('lang', 'content')" />
								</label>
								<textarea name="internal_note_content" id="internal_note_content" required="required" class="form-control">
								</textarea>
							</div>
						</xsl:if>
					</div>
					<div class="modal-footer">
						<button type="submit" class="btn btn-primary">
							<xsl:value-of select="php:function('lang', 'save')" />
						</button>
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
							<xsl:value-of select="php:function('lang', 'cancel')" />
						</button>
					</div>
				</form>
			</div>
			<!-- /.modal-content -->
		</div>
		<!-- /.modal-dialog -->
	</div>

	<div class="modal fade" id="acceptApplicationModal" tabindex="-1" role="dialog" aria-labelledby="acceptApplicationModalLabel" aria-hidden="true">
	  <div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
		  <div class="modal-header">
			<h5 class="modal-title" id="acceptApplicationModalLabel">
			  <xsl:value-of select="php:function('lang', 'confirm_application_approval')" />
			</h5>
			<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
			  <span aria-hidden="true">x</span>
			</button>
		  </div>
		  <form method="POST">
			<div class="modal-body">
			  <p><xsl:value-of select="php:function('lang', 'approval_description')" /></p>
			  <div class="form-group">
				<h5>
				  <xsl:value-of select="php:function('lang', 'comment_to_organizer')" />
				</h5>
				<textarea name="acceptance_message" id="acceptance_message" class="form-control" rows="4" placeholder="{php:function('lang', 'comment_placeholder')}"></textarea>
			  </div>
			  <div class="form-group form-check">
				<input type="checkbox" class="form-check-input" id="send_acceptance_email" name="send_acceptance_email" value="1" checked="checked"/>
				<label class="form-check-label" for="send_acceptance_email">
				  <xsl:value-of select="php:function('lang', 'send_email_organizer_summary')" />
				</label>
			  </div>
			  <input type="hidden" name="status" value="ACCEPTED"/>
			</div>
			<div class="modal-footer">
			  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
				<xsl:value-of select="php:function('lang', 'Cancel')" />
			  </button>
			  <button type="submit" class="btn btn-success">
				<xsl:value-of select="php:function('lang', 'Approve')" />
			  </button>
			</div>
		  </form>
		</div>
	  </div>
	</div>

	<div class="modal fade" id="rejectApplicationModal" tabindex="-1" role="dialog" aria-labelledby="rejectApplicationModalLabel" aria-hidden="true">
	  <div class="modal-dialog modal-dialog-centered" role="document">
		<div class="modal-content">
		  <div class="modal-header">
			<h5 class="modal-title" id="rejectApplicationModalLabel">
			  <xsl:value-of select="php:function('lang', 'Reject Application')" />
			</h5>
			<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
			  <span aria-hidden="true">x</span>
			</button>
		  </div>
		  <form method="POST">
			<div class="modal-body">
			  <p><xsl:value-of select="php:function('lang', 'Are you sure you want to delete?')" /></p>
			  <div class="form-group">
				<label for="rejection_reason">
				  <xsl:value-of select="php:function('lang', 'Reason for rejection')" />
				</label>
				<textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="4"></textarea>
			  </div>
			  <div class="form-group form-check">
				<input type="checkbox" class="form-check-input" id="send_rejection_email" name="send_rejection_email" value="1" checked="checked"/>
				<label class="form-check-label" for="send_rejection_email">
				  <xsl:value-of select="php:function('lang', 'send_email_to_applicant')" />
				</label>
			  </div>
			  <input type="hidden" name="status" value="REJECTED"/>
			</div>
			<div class="modal-footer">
			  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
				<xsl:value-of select="php:function('lang', 'Cancel')" />
			  </button>
			  <button type="submit" class="btn btn-danger">
				<xsl:value-of select="php:function('lang', 'reject application')" />
			  </button>
			</div>
		  </form>
		</div>
	  </div>
	</div>

	<script>
		$(document).ready(function() {
		$('#new_case_officer').select2({
		dropdownParent: $('#change_userModal'),
		width: '90%'
		});

		$('#new_case_officer').on('select2:open', function (e) {

		$(".select2-search__field").each(function()
		{
		if ($(this).attr("aria-controls") == 'select2-new_case_officer-results')
		{
		$(this)[0].focus();
		}
		});
		});

		});
	</script>

	<!-- Edit Selection Modal for Combined Applications -->
	<xsl:if test="show_edit_selection = 1">
		<div class="modal fade" id="editSelectionModal" tabindex="-1" role="dialog" aria-labelledby="editSelectionModalLabel" aria-hidden="true">
			<div class="modal-dialog modal-lg" role="document">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title" id="editSelectionModalLabel">
							<i class="fas fa-edit me-2"></i>Select Application to Edit
						</h5>
						<button type="button" class="close" data-dismiss="modal" aria-label="Close">
							<span aria-hidden="true">×</span>
						</button>
					</div>
					<div class="modal-body">
						<p class="text-muted mb-4">
							This combined application contains <xsl:value-of select="count(related_applications)"/> related applications.
							Choose which one you want to edit:
						</p>

						<div class="row">
							<xsl:for-each select="related_applications">
								<div class="col-md-6 mb-3">
									<div class="card application-selection-card">
										<xsl:attribute name="class">
											<xsl:text>card application-selection-card</xsl:text>
											<xsl:if test="is_main = 1">
												<xsl:text> border-danger</xsl:text>
											</xsl:if>
										</xsl:attribute>

										<div class="card-body">
											<div class="d-flex justify-content-between align-items-start mb-2">
												<h6 class="card-title mb-0">
													<xsl:value-of select="name"/>
													<xsl:if test="is_main = 1">
														<span class="badge badge-danger ml-2">MAIN</span>
													</xsl:if>
												</h6>
												<span class="badge">
													<xsl:attribute name="class">
														<xsl:text>badge badge-</xsl:text>
														<xsl:choose>
															<xsl:when test="status = 'NEW'">primary</xsl:when>
															<xsl:when test="status = 'PENDING'">warning</xsl:when>
															<xsl:when test="status = 'ACCEPTED'">success</xsl:when>
															<xsl:when test="status = 'REJECTED'">danger</xsl:when>
															<xsl:otherwise>secondary</xsl:otherwise>
														</xsl:choose>
													</xsl:attribute>
													<xsl:value-of select="status"/>
												</span>
											</div>

											<small class="text-muted">
												<strong>ID:</strong> <xsl:value-of select="id"/><br/>
												<strong>Created:</strong> <xsl:value-of select="created"/><br/>
												<xsl:if test="dates != ''">
													<strong>Dates:</strong> <xsl:value-of select="dates"/><br/>
												</xsl:if>
												<xsl:if test="resources != ''">
													<strong>Resources:</strong> <xsl:value-of select="resources"/>
												</xsl:if>
											</small>

											<a class="btn btn-primary btn-sm mt-2">
												<xsl:attribute name="href">
													<xsl:value-of select="../application/edit_link"/>
													<xsl:text>&amp;selected_app_id=</xsl:text>
													<xsl:value-of select="id"/>
												</xsl:attribute>
												<i class="fas fa-edit me-1"></i>Edit This Application
											</a>
										</div>
									</div>
								</div>
							</xsl:for-each>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-dismiss="modal">
							<i class="fas fa-times me-1"></i>Cancel
						</button>
					</div>
				</div>
			</div>
		</div>

		<style>
			.application-selection-card {
				transition: transform 0.2s, box-shadow 0.2s;
				cursor: pointer;
			}
			.application-selection-card:hover {
				transform: translateY(-2px);
				box-shadow: 0 4px 12px rgba(0,0,0,0.15);
			}
		</style>
	</xsl:if>

	<!-- Recurring Approval Summary Modal -->
	<xsl:if test="show_recurring_summary = '1' and recurring_preview">
		<div class="modal fade" id="recurringApprovalSummaryModal" tabindex="-1" role="dialog" aria-labelledby="recurringApprovalSummaryModalLabel" aria-hidden="true">
			<div class="modal-dialog modal-lg modal-dialog-centered" role="document">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title" id="recurringApprovalSummaryModalLabel">
							<i class="fas fa-calendar-check me-2"></i>
							<xsl:value-of select="php:function('lang', 'recurring_allocations_summary')" />
						</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="row mb-4">
							<div class="col-12">
								<div class="alert alert-success d-flex align-items-center">
									<i class="fas fa-check-circle fa-2x me-3"></i>
									<div>
										<h6 class="mb-1">Søknad godkjent!</h6>
										<p class="mb-0">Gjentakende tildelinger er opprettet automatisk.</p>
									</div>
								</div>
							</div>
						</div>

						<!-- Summary Statistics -->
						<div class="row mb-4">
							<div class="col-md-4">
								<div class="card bg-light text-center">
									<div class="card-body">
										<h3 class="text-success mb-1">
											<xsl:value-of select="count(recurring_preview/*[exists = 1])"/>
										</h3>
										<small class="text-muted"><xsl:value-of select="php:function('lang', 'created')" /></small>
									</div>
								</div>
							</div>
							<div class="col-md-4">
								<div class="card bg-light text-center">
									<div class="card-body">
										<h3 class="text-warning mb-1">
											<xsl:value-of select="count(recurring_preview/*[has_conflict = 1 or (exists != 1 and not(has_conflict = 1))])"/>
										</h3>
										<small class="text-muted">Feilet</small>
									</div>
								</div>
							</div>
							<div class="col-md-4">
								<div class="card bg-light text-center">
									<div class="card-body">
										<h3 class="text-primary mb-1">
											<xsl:value-of select="count(recurring_preview/*)"/>
										</h3>
										<small class="text-muted">Totalt</small>
									</div>
								</div>
							</div>
						</div>

						<!-- Successfully Created Allocations -->
						<div class="mb-4">
							<h6 class="text-success mb-3">
								<i class="fas fa-check-circle me-2"></i>
								Opprettede tildelinger (<xsl:value-of select="count(recurring_preview/*[exists = 1])"/>)
							</h6>
							<div class="table-responsive">
								<table class="table table-sm">
									<thead class="table-success">
										<tr>
											<th>Dato</th>
											<th>Tid</th>
											<th>Ressurser</th>
											<th>Handling</th>
										</tr>
									</thead>
									<tbody>
										<xsl:for-each select="recurring_preview/*[exists = 1]">
											<tr>
												<td>
													<span class="fw-medium">
														<xsl:value-of select="date_display"/>
													</span>
												</td>
												<td>
													<span class="font-monospace">
														<xsl:value-of select="time_display"/>
													</span>
												</td>
												<td>
													<span class="text-muted small">
														<xsl:value-of select="resource_display"/>
													</span>
												</td>
												<td>
													<a class="btn btn-outline-primary btn-sm">
														<xsl:attribute name="href">
															<xsl:value-of select="allocation_link"/>
														</xsl:attribute>
														<xsl:attribute name="title">
															Vis tildeling ID <xsl:value-of select="allocation_id"/>
														</xsl:attribute>
														<i class="fas fa-eye me-1"></i>Vis
													</a>
												</td>
											</tr>
										</xsl:for-each>
									</tbody>
								</table>
							</div>
						</div>

						<!-- Failed Allocations -->
						<div class="mb-4">
							<h6 class="text-warning mb-3">
								<i class="fas fa-exclamation-triangle me-2"></i>
								Tildelinger som ikke kunne opprettes (<xsl:value-of select="count(recurring_preview/*[has_conflict = 1 or (exists != 1 and not(has_conflict = 1))])"/>)
							</h6>
							<div class="table-responsive">
								<table class="table table-sm">
									<thead class="table-warning">
										<tr>
											<th>Dato</th>
											<th>Tid</th>
											<th>Ressurser</th>
											<th>Årsak</th>
										</tr>
									</thead>
									<tbody>
										<xsl:for-each select="recurring_preview/*[has_conflict = 1 or (exists != 1 and not(has_conflict = 1))]">
											<tr>
												<td><xsl:value-of select="date_display"/></td>
												<td><xsl:value-of select="time_display"/></td>
												<td><xsl:value-of select="resource_display"/></td>
												<td>
													<xsl:choose>
														<xsl:when test="has_conflict = 1">
															<span class="badge bg-warning">Konflikt</span>
														</xsl:when>
														<xsl:otherwise>
															<span class="badge bg-secondary">Feilet</span>
														</xsl:otherwise>
													</xsl:choose>
												</td>
											</tr>
										</xsl:for-each>
									</tbody>
								</table>
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-primary" data-bs-dismiss="modal">
							<i class="fas fa-check me-1"></i>OK
						</button>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
			$(document).ready(function() {
				// Auto-show the recurring summary modal
				$('#recurringApprovalSummaryModal').modal('show');
			});
		</script>
	</xsl:if>

</xsl:template>
<!-- New template-->
<xsl:template match="options">
	<option value="{id}">
		<xsl:if test="selected != 0">
			<xsl:attribute name="selected" value="selected"/>
		</xsl:if>
		<xsl:value-of disable-output-escaping="yes" select="name"/>
	</option>
</xsl:template>
