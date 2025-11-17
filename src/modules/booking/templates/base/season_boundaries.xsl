<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<style type="text/css">
		.time-picker {display: inline;}
		
		/* Weekday checkbox styling */
		.weekday-checkbox:hover {
			background: #e8f4ff !important;
			border-color: #007acc !important;
			transform: translateY(-1px);
		}
		
		.weekday-checkbox.checked {
			background: #007acc !important;
			border-color: #005999 !important;
			color: white;
		}
		
		.weekday-checkbox.checked .day-short {
			color: white !important;
		}
		
		.weekday-checkbox.checked .day-full {
			color: rgba(255, 255, 255, 0.8) !important;
		}
		
		/* Weekend styling */
		.weekday-checkbox.weekend {
			background: #fff5f5 !important;
			border-color: #fbb !important;
		}
		
		.weekday-checkbox.weekend:hover {
			background: #ffe8e8 !important;
			border-color: #f99 !important;
		}
		
		.weekday-checkbox.weekend.checked {
			background: #e53e3e !important;
			border-color: #c53030 !important;
		}
	</style>
	<script type="text/javascript">
		// Inline JavaScript for weekday checkboxes
		$(document).ready(function() {
			setTimeout(function() {
				var $weekdayCheckboxes = $('.weekday-checkboxes');
				console.log('Inline JS: Found weekday checkboxes:', $weekdayCheckboxes.length);
				
				if ($weekdayCheckboxes.length > 0) {
					// Add quick select buttons
					var $quickButtons = $('<div class="boundary-quick-select" style="margin-top: 10px;"></div>');
					$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="weekdays"><xsl:value-of select="php:function('lang', 'weekdays')" /></button> ');
					$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="weekend"><xsl:value-of select="php:function('lang', 'weekend')" /></button> ');
					$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="all"><xsl:value-of select="php:function('lang', 'all')" /></button> ');
					$quickButtons.append('<button type="button" class="pure-button pure-button-small" data-action="clear"><xsl:value-of select="php:function('lang', 'clear')" /></button>');
					$weekdayCheckboxes.after($quickButtons);
					
					// Update checkbox visual states
					function updateCheckboxStates() {
						$('.weekday-checkbox').each(function() {
							var $label = $(this);
							var $checkbox = $label.find('input[type="checkbox"]');
							if ($checkbox.prop('checked')) {
								$label.addClass('checked');
							} else {
								$label.removeClass('checked');
							}
						});
					}
					
					// Initialize states
					updateCheckboxStates();
					
					// Handle checkbox clicks
					$(document).on('click', '.weekday-checkbox', function(e) {
						e.preventDefault();
						console.log('Inline JS: Weekday checkbox clicked');
						var $checkbox = $(this).find('input[type="checkbox"]');
						$checkbox.prop('checked', !$checkbox.prop('checked'));
						console.log('Inline JS: Checkbox state:', $checkbox.prop('checked'));
						updateCheckboxStates();
					});
					
					// Handle quick select buttons
					$quickButtons.on('click', 'button', function(e) {
						e.preventDefault();
						var action = $(this).data('action');
						var $checkboxes = $('input[name="wday[]"]');
						console.log('Inline JS: Quick button clicked:', action);
						
						$checkboxes.prop('checked', false);
						
						switch(action) {
							case 'weekdays':
								$('input[name="wday[]"][value="1"], input[name="wday[]"][value="2"], input[name="wday[]"][value="3"], input[name="wday[]"][value="4"], input[name="wday[]"][value="5"]').prop('checked', true);
								break;
							case 'weekend':
								$('input[name="wday[]"][value="6"], input[name="wday[]"][value="7"]').prop('checked', true);
								break;
							case 'all':
								$checkboxes.prop('checked', true);
								break;
						}
						updateCheckboxStates();
					});
				}
			}, 500);
		});
	</script>
	<xsl:call-template name="msgbox"/>
	<form action="" method="POST" id='form'  class="pure-form pure-form-aligned" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="season/tabs"/>
			<div id="season_boundaries" class="booking-container">
				<table id="boundary-table" class="pure-table pure-table-bordered">
					<thead>
						<tr>
							<th>
								<xsl:value-of select="php:function('lang', 'Week day')" />
							</th>
							<th>
								<xsl:value-of select="php:function('lang', 'From')" />
							</th>
							<th>
								<xsl:value-of select="php:function('lang', 'To')" />
							</th>
							<th><xsl:value-of select="php:function('lang', 'Actions')" /></th>
						</tr>
					</thead>
					<tbody>
						<xsl:choose>
							<xsl:when test="count(boundaries/*) &gt; 0">
								<xsl:for-each select="boundaries">
									<tr>
										<td>
											<xsl:value-of select="wday_name"/>
										</td>
										<td>
											<xsl:value-of select="from_"/>
										</td>
										<td>
											<xsl:value-of select="to_"/>
										</td>
										<td>
											<xsl:if test="../season/permission/write">
												<a href="{edit_link}" class="pure-button pure-button-primary" style="margin-right: 5px;">
													<xsl:value-of select="php:function('lang', 'Edit')" />
												</a>
												<a href="{delete_link}" class="pure-button" onclick="return confirm('{php:function('lang', 'Are you sure you want to delete this boundary?')}')">
													<xsl:value-of select="php:function('lang', 'Delete')" />
												</a>
											</xsl:if>
										</td>
									</tr>
								</xsl:for-each>
							</xsl:when>
							<xsl:otherwise>
								<td colspan='4'>
									<xsl:value-of select="php:function('lang', 'No Data.')"/>
								</td>
							</xsl:otherwise>
						</xsl:choose>
					</tbody>
				</table>
				<xsl:if test="season/permission/write">
					<div class="pure-g">
						<div class="pure-u-1">
							<div class="heading">
								<!--<legend>-->
									<h3>
										<xsl:value-of select="php:function('lang', 'Set Boundaries')" />
									</h3>
								<!--</legend>-->
							</div>
							<div class="pure-control-group">
								<label for="field_status">
									<xsl:choose>
										<xsl:when test="season/is_editing">
											<xsl:value-of select="php:function('lang', 'Week day')" />
										</xsl:when>
										<xsl:otherwise>
											<xsl:value-of select="php:function('lang', 'Week days')" />
										</xsl:otherwise>
									</xsl:choose>
								</label>
								<div class="weekday-checkboxes" style="display: flex; flex-wrap: wrap; gap: 15px; margin: 10px 0;">
									<!-- Monday - start of week -->
									<label class="weekday-checkbox" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="1" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '1'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'mo')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'monday')" /></div>
									</label>
									<!-- Tuesday -->
									<label class="weekday-checkbox" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="2" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '2'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'tu')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'tuesday')" /></div>
									</label>
									<!-- Wednesday -->
									<label class="weekday-checkbox" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="3" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '3'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'we')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'wednesday')" /></div>
									</label>
									<!-- Thursday -->
									<label class="weekday-checkbox" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="4" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '4'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'th')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'thursday')" /></div>
									</label>
									<!-- Friday -->
									<label class="weekday-checkbox" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="5" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '5'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'fr')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'friday')" /></div>
									</label>
									<!-- Saturday -->
									<label class="weekday-checkbox weekend" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="6" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '6'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'sa')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'saturday')" /></div>
									</label>
									<!-- Sunday - end of week -->
									<label class="weekday-checkbox weekend" style="display: flex; flex-direction: column; align-items: center; cursor: pointer; padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; min-width: 60px; background: #f9f9f9; transition: all 0.2s ease;">
										<input type="checkbox" name="wday[]" value="7" style="display: none;">
											<xsl:if test="season/is_editing and boundary/wday = '7'">
												<xsl:attribute name="checked">checked</xsl:attribute>
											</xsl:if>
										</input>
										<div class="day-short" style="font-weight: bold; font-size: 14px; color: #666;"><xsl:value-of select="php:function('lang', 'su')" /></div>
										<div class="day-full" style="font-size: 12px; color: #999; margin-top: 2px;"><xsl:value-of select="php:function('lang', 'sunday')" /></div>
									</label>
								</div>
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'From')" />
								</label>
								<div class="time-picker">
									<input id="field_from" name="from_" type="text">
										<xsl:attribute name="value">
											<xsl:value-of select="boundary/from_"/>
										</xsl:attribute>
									</input>
								</div>
							</div>
							<div class="pure-control-group">
								<label>
									<xsl:value-of select="php:function('lang', 'To')" />
								</label>
								<div class="time-picker">
									<input id="field_to" name="to_" type="text">
										<xsl:attribute name="value">
											<xsl:value-of select="boundary/to_"/>
										</xsl:attribute>
									</input>
								</div>
							</div>
							<div class="pure-control-group">
								<label>&nbsp;</label>
								<input type="submit" class="pure-button pure-button-primary">
									<xsl:attribute name="value">
										<xsl:value-of select="php:function('lang', 'Set')"/>
									</xsl:attribute>
								</input>
							</div>
						</div>
					</div>
				</xsl:if>
				<!-- <form action="" method="POST">
					<dl class="form">
						<dt class="heading"><xsl:value-of select="php:function('lang', 'Copy boundaries')" /></dt>
						<input type="search"/>
						<input type="submit">
							<xsl:attribute name="value"><xsl:value-of select="php:function('lang', 'Search')"/></xsl:attribute>
						</input>
						<div id="foo_container"/>
						<input type="submit">
							<xsl:attribute name="value"><xsl:value-of select="php:function('lang', 'Clone boundaries')"/></xsl:attribute>
						</input>
					</dl>
					<div class="form-buttons">
						<input type="submit">
							<xsl:attribute name="value"><xsl:value-of select="php:function('lang', 'Add')"/></xsl:attribute>
						</input>
						<a class="cancel">
							<xsl:attribute name="href"><xsl:value-of select="season/cancel_link"/></xsl:attribute>
							<xsl:value-of select="php:function('lang', 'Cancel')"/>
						</a>
					</div>
				</form> -->
			</div>
		</div>
		<div class="form-buttons">
			<input type="button" class="pure-button pure-button-primary" name="cencel">
				<xsl:attribute name="onclick">window.location.href="<xsl:value-of select="season/cancel_link"/>"</xsl:attribute>
				<xsl:attribute name="value">
					<xsl:value-of select="php:function('lang', 'Cancel')" />
				</xsl:attribute>
			</input>
		</div>
	</form>
</xsl:template>
