<!-- BEGIN header -->
<form method="post" action="{action_url}">
	<table class="pure-table pure-table-bordered pure-table-striped pure-form">
		<th>
		<td colspan="2">
			<font color="{th_text}">&nbsp;<b>{title}</b></font>
		</td>
		</th>
		<tr bgcolor="{th_err}">
			<td colspan="2">&nbsp;<b>{error}</b></font>
			</td>
		</tr>
		<!-- END header -->
		<!-- BEGIN body -->
		<tr class="row_on">
			<td colspan="2">&nbsp;</td>
		</tr>
		<tr class="row_off">
			<td colspan="2">&nbsp;<b>{lang_todo}/{lang_Settings}</b></font>
			</td>
		</tr>

		<!-- END body -->
		<!-- BEGIN footer -->
		<tr class="{th}">
			<td colspan="2">&nbsp;</td>
		</tr>
		<tr>
			<td colspan="2" align="center">
				<input type="submit" name="submit" value="{lang_submit}" class="pure-button" />
				<input type="submit" name="cancel" value="{lang_cancel}" class="pure-button" />
			</td>
		</tr>
	</table>
</form>
<!-- END footer -->