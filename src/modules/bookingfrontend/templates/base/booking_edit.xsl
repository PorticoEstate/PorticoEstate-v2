<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<div id="booking-edit-page-content" class="margin-top-content">
		<div class="container wrapper">
			<div class="location">
				<span>
					<a>
						<xsl:attribute name="href">
							<xsl:value-of select="php:function('get_phpgw_link', '/bookingfrontend/', '')"/>
						</xsl:attribute>
						<xsl:value-of select="php:function('lang', 'Home')" />
					</a>
				</span>
				<span>
					<xsl:value-of select="php:function('lang', 'Booking')"/> #<xsl:value-of select="booking/id"/>
				</span>
			</div>
			<div class="row">
				<form action="" method="POST" id="booking_form" class="col-md-8">
					<div class="col mb-4">
						<xsl:call-template name="msgbox"/>
					</div>
					<input type="hidden" name="season_id" value="{booking/season_id}"/>
					<input type="hidden" name="allocation_id" value="{booking/allocation_id}"/>
					<input type="hidden" name="step" value="1"/>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Active')"/>
						</label>
						<select id="field_active" class="form-control" name="active">
							<option value="1">
								<xsl:if test="booking/active=1">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Active')"/>
							</option>
							<option value="0">
								<xsl:if test="booking/active=0">
									<xsl:attribute name="selected">checked</xsl:attribute>
								</xsl:if>
								<xsl:value-of select="php:function('lang', 'Inactive')"/>
							</option>
						</select>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Activity')" />
						</label>
						<select name="activity_id" class="form-control" id="field_activity">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please select an activity')" />
							</xsl:attribute>
							<option value="">
								<xsl:value-of select="php:function('lang', '-- select an activity --')" />
							</option>
							<xsl:for-each select="activities">
								<option>
									<xsl:if test="../booking/activity_id = id">
										<xsl:attribute name="selected">selected</xsl:attribute>
									</xsl:if>
									<xsl:attribute name="value">
										<xsl:value-of select="id"/>
									</xsl:attribute>
									<xsl:value-of select="name"/>
								</option>
							</xsl:for-each>
						</select>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Building (2018)')"/>
						</label>
						<input id="field_building_id" class="form-control" name="building_id" type="hidden" value="{booking/building_id}">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter a building')" />
							</xsl:attribute>
						</input>
						<xsl:value-of select="booking/building_name"/>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Resource (2018)')" />
						</label>
						<button type="button" class="btn btn-light dropdown-toggle" data-toggle="dropdown">
							<xsl:value-of select="php:function('lang', 'choose')" />
							<span class="caret"></span>
						</button>
						<ul class="dropdown-menu px-2 resourceDropdown" data-bind="foreach: bookableresource">
							<li>
								<div class="form-check checkbox checkbox-primary">
									<label class="check-box-label">
										<input class="form-check-input choosenResource" type="checkbox" name="resources[]" data-bind="textInput: id, checked: selected" />
										<span class="label-text" data-bind="text: name"></span>
									</label>
								</div>
							</li>
						</ul>
					</div>
					<div class="form-group">
						<span class="font-weight-bold d-block mt-2 span-label">
							<xsl:value-of select="php:function('lang', 'Chosen resources (2018)')" />
						</span>
						<div data-bind="foreach: bookableresource">
							<span class="selectedItems mr-2" data-bind='text: selected() ? name : "", visible: selected()'></span>
						</div>
						<span data-bind="ifnot: isResourceSelected" class="isSelected validationMessage">
							<xsl:value-of select="php:function('lang', 'No resource chosen (2018)')" />
						</span>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Organization')"/>
						</label>
						<input id="field_organization_id" class="form-control" name="organization_id" type="hidden" value="{booking/organization_id}">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter an organization')" />
							</xsl:attribute>
						</input>
						<xsl:value-of select="booking/organization_name"/>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Group')"/>
						</label>
						<select name="group_id" class="form-control">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please select a group')" />
							</xsl:attribute>
							<option value="">
								<xsl:value-of select="php:function('lang', 'Select a group')"/>
							</option>
							<xsl:for-each select="groups">
								<option value="{id}">
									<xsl:if test="../booking/group_id = id">
										<xsl:attribute name="selected">selected</xsl:attribute>
									</xsl:if>
									<xsl:value-of select="name"/>
								</option>
							</xsl:for-each>
						</select>
					</div>
					<div class="form-group">
						<label>
							<xsl:value-of select="php:function('lang', 'From')"/>
						</label>
						<input class="form-control" id="from_date" type="text" name="from_">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter a from date')" />
							</xsl:attribute>
							<xsl:attribute name="value">
								<xsl:value-of select="booking/from_" />
							</xsl:attribute>
						</input>
					</div>
					<div class="form-group">
						<label>
							<xsl:value-of select="php:function('lang', 'To')"/>
						</label>
						<input class="form-control" id="to_date" type="text" name="to_">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter an end date')" />
							</xsl:attribute>
							<xsl:attribute name="value">
								<xsl:value-of select="booking/to_" />
							</xsl:attribute>
						</input>
					</div>
					<div class="form-group">
						<label>
							<xsl:value-of select="php:function('lang', 'Recurring booking')" />
						</label>
						<div>
							<input type="checkbox" class="mr-2" name="outseason" id="outseason">
								<xsl:if test="outseason='on'">
									<xsl:attribute name="checked">checked</xsl:attribute>
								</xsl:if>
							</input>
							<xsl:value-of select="php:function('lang', 'Out season')" />
						</div>
						<div>
							<input type="checkbox" class="mr-2" name="recurring" id="recurring">
								<xsl:if test="recurring='on'">
									<xsl:attribute name="checked">checked</xsl:attribute>
								</xsl:if>
							</input>
							<xsl:value-of select="php:function('lang', 'Repeat until')" />
						</div>
						<input class="form-control" id="repeat_date" name="repeat_until" type="text" autocomplete="off">
							<xsl:attribute name="placeholder">
								<xsl:value-of select="php:function('lang', 'Choose date')"/>
							</xsl:attribute>
							<xsl:attribute name="value">
								<xsl:value-of select="repeat_until" />
							</xsl:attribute>
						</input>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Target audience')" />
						</label>
						<div class="dropdown d-inline-block">
							<button class="btn btn-secondary dropdown-toggle d-inline mr-2 btn-sm" id="audienceDropdownBtn" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
								<xsl:value-of select="php:function('lang', 'choose')" />
							</button>
							<div class="dropdown-menu" data-bind="foreach: audiences" aria-labelledby="dropdownMenuButton">
								<a class="dropdown-item" data-bind="text: name, id: id, click: $root.audienceSelected" href="#"></a>
							</div>
							<input type="text" name="audience[]" hidden="hidden" data-bind="value: audienceSelectedValue" />
						</div>
					</div>
					<div class="form-group">
						<label class="text-uppercase">
							<xsl:value-of select="php:function('lang', 'Estimated number of participants')" />
						</label>
						<div class="p-2 border">
							<div class="row mb-2">
								<div class="col-3">
									<span class="span-label mt-2"></span>
								</div>
								<div class="col-4">
									<span>
										<xsl:value-of select="php:function('lang', 'Male')" />
									</span>
								</div>
								<div class="col-4">
									<xsl:value-of select="php:function('lang', 'Female')" />
								</div>
							</div>
							<div class="row mb-2" data-bind="foreach: agegroup">
								<span data-bind="text: id, visible: false"/>
								<div class="col-3">
									<span class="mt-2" data-bind="text: agegroupLabel"></span>
								</div>
								<div class="col-4">
									<input class="form-control sm-input maleInput" data-bind=""/>
								</div>
								<div class="col-4">
									<input class="form-control sm-input femaleInput" data-bind=""/>
								</div>
							</div>
						</div>
					</div>
					<input type="submit" class="btn btn-light mr-4">
						<xsl:attribute name="value">
							<xsl:value-of select="php:function('lang', 'Save')"/>
						</xsl:attribute>
					</input>
					<a class="cancel">
						<xsl:attribute name="href">
							<xsl:value-of select="booking/cancel_link"/>
						</xsl:attribute>
						<xsl:value-of select="php:function('lang', 'Go back')"/>
					</a>
				</form>
			</div>
		</div>
	</div>
	<script>
		var initialSelection = <xsl:value-of select="booking/resource_ids_json"/>;
		var initialAudience = <xsl:value-of select="booking/audience_json"/>;
		var initialSelectionAgegroup = <xsl:value-of select="booking/agegroups_json" />;
		var building_id = <xsl:value-of select="booking/building_id"/>;
		var lang = <xsl:value-of select="php:function('js_lang', 'Name', 'Resources Type')" />;
		$(".maleInput").attr('data-bind', "textInput: inputCountMale, attr: {'name': malename }");
		$(".femaleInput").attr('data-bind', "textInput: inputCountFemale, attr: {'name': femalename }");
		BookingNewModel = GenerateUIModelForResourceAudienceAndAgegroup();
		bnm = new BookingNewModel();
		ko.applyBindings(bnm, document.getElementById("booking-new-page-content"));
		AddBookableResourceDataWithinBooking(building_id, initialSelection, bnm.bookableresource);
		AddAudiencesAndAgegroupData(building_id, bnm.agegroup, initialSelectionAgegroup, bnm.audiences, initialAudience);
		bnm.audienceSelectedValue(<xsl:value-of select="booking/audience" />);
	</script>
</xsl:template>
