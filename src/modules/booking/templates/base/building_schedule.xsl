<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<style typ="text/css" rel="stylesheet">
		#week-selector {list-style: outside none none;}
		#week-selector li {display: inline-block;}
		#cal_container {margin: 0 20px;}
		#cal_container #datepicker {width: 2px;opacity: 0;position: absolute;display:none;}
		#cal_container #numberWeek {width: 20px;display: inline-block;}
	</style>
	<xsl:call-template name="msgbox"/>
	<form action="" method="POST" id='form'  class="pure-form pure-form-aligned" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="building/tabs"/>
			<div id="building_schedule">

				<label for="resource_id">
					<xsl:value-of select="php:function('lang', 'resource')"/>
				</label>
				<select id="resource_id" name="resource_id[]" class="form-select">
					<xsl:attribute name="multiple">
						<xsl:text>true</xsl:text>
					</xsl:attribute>
					<xsl:attribute name="title">
						<xsl:value-of select="php:function('lang', 'select resource')"/>
					</xsl:attribute>
					<xsl:apply-templates select="building/resource_list/options"/>
				</select>

				<ul id="week-selector">
					<li>
						<span class="pure-button pure-button-primary" onclick="schedule.prevWeek(); return false">
							<xsl:value-of select="php:function('lang', 'Previous week')"/>
						</span>
					</li>
					<li id="cal_container">
						<div>
							<span>
								<xsl:value-of select="php:function('lang', 'Week')" />: </span>
							<label id="numberWeek"></label>
							<input type="text" id="datepicker" />
							<img id="pickerImg" src="{building/picker_img}" />
						</div>
					</li>
					<li>
						<span class="pure-button pure-button-primary" onclick="schedule.nextWeek(); return false">
							<xsl:value-of select="php:function('lang', 'Next week')"/>
						</span>
					</li>
				</ul>
				<div id="schedule_container"></div>
			</div>
		</div>
		<div class="form-buttons">
			<input type="button" class="pure-button pure-button-primary" name="cancel">
				<xsl:attribute name="onclick">window.location="<xsl:value-of select="building/cancel_link"/>"</xsl:attribute>
				<xsl:attribute name="value">
					<xsl:value-of select="php:function('lang', 'Cancel')" />
				</xsl:attribute>
			</input>
		</div>
	</form>
	<script type="text/javascript">
		var lang = <xsl:value-of select="php:function('js_lang', 'free')"/>;
		$(window).on('load', function() {
		schedule.setupWeekPicker('cal_container');
		schedule.datasourceUrl = '<xsl:value-of select="building/datasource_url"/>';
		schedule.includeResource = true;
		schedule.colFormatter = 'backendScheduleDateColumn';
		var handleHistoryNavigation = function (state) {
		schedule.date = parseISO8601(state);
		schedule.renderSchedule('schedule_container', schedule.datasourceUrl, schedule.date, schedule.colFormatter, schedule.includeResource);
		};

		var initialRequest = getUrlData("date") || '<xsl:value-of select="building/date"/>';

		var state = getUrlData("date") || initialRequest;
		schedule.state = state;
		if (state){
		handleHistoryNavigation(state);
		schedule.week = $.datepicker.iso8601Week(schedule.date);
		$('#cal_container #numberWeek').text(schedule.week);
		$("#cal_container #datepicker").datepicker("setDate", parseISO8601(state));
		}


	$("#resource_id").porticoMultiselect({width: '250px'}).on('change.schedule', function ()
	{
		var $select = $(this);
		// An empty selection means "show all resources".
		if (!$select.find('option:selected').length)
		{
			$select.find('option').prop('selected', true);
			$select.trigger('change.select2');
		}
		var resource_ids = $select.val() || [];
		schedule.filter_resource(resource_ids);
	});
		});
	</script>
</xsl:template>

<xsl:template match="options">
	<option value="{id}">
		<xsl:if test="selected != 0">
			<xsl:attribute name="selected" value="selected"/>
		</xsl:if>
		<xsl:value-of disable-output-escaping="yes" select="name"/>
	</option>
</xsl:template>
