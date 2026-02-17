<xsl:template match="data" xmlns:php="http://php.net/xsl">
	<script type="text/javascript">
		var documentOwnerType = "<xsl:value-of select="document/owner_type"/>";
		var documentOwnerAutocomplete = <xsl:value-of select="document/inline"/> == 0;
		// Translated strings for focal point editor
		var lang_edit_focal_point = "<xsl:value-of select="php:function('lang', 'edit_focal_point')" />";
	</script>
	<xsl:call-template name="msgbox"/>
	<form action="" method="POST" enctype='multipart/form-data' id='form' class="pure-form pure-form-aligned" name="form">
		<input type="hidden" name="tab" value=""/>
		<div id="tab-content">
			<xsl:value-of disable-output-escaping="yes" select="document/tabs"/>
			<div id="document" class="booking-container">
				<fieldset>
					<xsl:if test="document/id">
						<div class="heading">
							<!--<legend>-->
								<h3>
									<xsl:value-of select="php:function('lang', 'Edit document')" />
								</h3>
							<!--</legend>-->
						</div>
					</xsl:if>
					<xsl:if test="not(document/id)">
						<div class="heading">
							<!--<legend>-->
								<h3>
									<xsl:value-of select="php:function('lang', 'Upload document')" />
								</h3>
							<!--</legend>-->
						</div>
					</xsl:if>
					<xsl:if test="document/id">
						<input name='field_id' type='hidden'>
							<xsl:attribute name="value">
								<xsl:value-of select="document/id"/>
							</xsl:attribute>
						</input>
					</xsl:if>
					<div class="pure-control-group">
						<label for="field_name">
							<xsl:value-of select="php:function('lang', 'Document')" />
						</label>
						<input name="name" id='field_name' class="pure-input-3-4">
							<xsl:attribute name="value">
								<xsl:value-of select="document/name"/>
							</xsl:attribute>
							<xsl:attribute name="type">
								<xsl:choose>
									<xsl:when test="document/id">text</xsl:when>
									<xsl:otherwise>file</xsl:otherwise>
								</xsl:choose>
							</xsl:attribute>
							<xsl:if test="document/id">
								<xsl:attribute name="disabled" value="disabled"/>
							</xsl:if>
							<xsl:attribute name='title'>
								<xsl:value-of select="document/name"/>
							</xsl:attribute>
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter a name')" />
							</xsl:attribute>
						</input>
					</div>
					<div class="pure-control-group">
						<label for="field_description">
							<xsl:value-of select="php:function('lang', 'Description')" />
						</label>
						<textarea name="description" id='field_description' class="pure-input-3-4">
							<xsl:value-of select="document/description"/>
						</textarea>
					</div>
					<div class="pure-control-group">
						<label for="field_category">
							<xsl:value-of select="php:function('lang', 'Category')" />
						</label>
						<select name='category' id='field_category' class="pure-input-3-4">
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please select a category')" />
							</xsl:attribute>
							<option value=''>
								<xsl:value-of select="php:function('lang', 'Select Category...')" />
							</option>
							<xsl:for-each select="document/document_types/*">
								<option>
									<xsl:if test="../../category = local-name()">
										<xsl:attribute name="selected">selected</xsl:attribute>
									</xsl:if>
									<xsl:attribute name="value">
										<xsl:value-of select="local-name()"/>
									</xsl:attribute>
									<xsl:value-of select="php:function('lang', string(node()))"/>
								</option>
							</xsl:for-each>
						</select>
					</div>
					<div class="pure-control-group">
						<label for="field_owner_name">
							<xsl:value-of select="php:function('lang', string(document/owner_type_label))" />
						</label>
						<input id="field_owner_name" name="owner_name" type="text" class="pure-input-3-4">
							<xsl:attribute name="value">
								<xsl:value-of select="document/owner_name"/>
							</xsl:attribute>
							<xsl:attribute name="data-validation">
								<xsl:text>required</xsl:text>
							</xsl:attribute>
							<xsl:attribute name="data-validation-error-msg">
								<xsl:value-of select="php:function('lang', 'Please enter an owner name')" />
							</xsl:attribute>
							<xsl:if test="document/inline = '1'">
								<xsl:attribute name="disabled">disabled</xsl:attribute>
							</xsl:if>
						</input>
						<input id="field_owner_id" name="owner_id" type="hidden">
							<xsl:attribute name="value">
								<xsl:value-of select="document/owner_id"/>
							</xsl:attribute>
						</input>
					</div>

					<!-- Focal point fields - hidden -->
					<input type="hidden" id="field_focal_point_x" name="focal_point_x">
						<xsl:attribute name="value">
							<xsl:value-of select="document/focal_point_x"/>
						</xsl:attribute>
					</input>
					<input type="hidden" id="field_focal_point_y" name="focal_point_y">
						<xsl:attribute name="value">
							<xsl:value-of select="document/focal_point_y"/>
						</xsl:attribute>
					</input>
					<input type="hidden" id="field_rotation" name="rotation">
						<xsl:attribute name="value">
							<xsl:value-of select="document/rotation"/>
						</xsl:attribute>
					</input>
					<input type="hidden" id="focal-point-download-link">
						<xsl:attribute name="value">
							<xsl:text>/bookingfrontend/</xsl:text>
							<xsl:value-of select="document/owner_type"/>
							<xsl:text>s/document/</xsl:text>
							<xsl:value-of select="document/id"/>
							<xsl:text>/download</xsl:text>
						</xsl:attribute>
					</input>

					<!-- Focal point editor button - only show for images (except applications) -->
					<xsl:if test="document/id and document/is_image = 1 and document/owner_type != 'application'">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="php:function('lang', 'focal_point')" />
							</label>
							<button type="button" id="edit-focal-point-btn" class="pure-button">
								<xsl:choose>
									<xsl:when test="document/focal_point_x">
										<xsl:value-of select="php:function('lang', 'edit_focal_point')" />
										<xsl:text> (</xsl:text>
										<xsl:value-of select="document/focal_point_x"/>
										<xsl:text>%, </xsl:text>
										<xsl:value-of select="document/focal_point_y"/>
										<xsl:text>%</xsl:text>
										<xsl:if test="document/rotation">
											<xsl:text>, </xsl:text>
											<xsl:value-of select="document/rotation"/>
											<xsl:text>°</xsl:text>
										</xsl:if>
										<xsl:text>)</xsl:text>
									</xsl:when>
									<xsl:otherwise>
										<xsl:value-of select="php:function('lang', 'set_focal_point')" />
									</xsl:otherwise>
								</xsl:choose>
							</button>
						</div>
					</xsl:if>

					<!-- Image previews - only show for picture types and existing documents (except applications) -->
					<xsl:if test="document/id and document/is_image = 1 and document/owner_type != 'application'">
						<div class="pure-control-group">
							<label>
								<xsl:value-of select="php:function('lang', 'previews')" />
							</label>
							<div style="display: flex; gap: 20px; flex-wrap: wrap;">
								<!-- 411x200 card preview with focal point -->
								<div style="text-align: center;">
									<div style="border: 1px solid #ccc; padding: 5px; background: #f5f5f5;">
										<img class="preview-image">
											<xsl:attribute name="src">
												<xsl:text>/bookingfrontend/</xsl:text>
												<xsl:value-of select="document/owner_type"/>
												<xsl:text>s/document/</xsl:text>
												<xsl:value-of select="document/id"/>
												<xsl:text>/download</xsl:text>
											</xsl:attribute>
											<xsl:attribute name="alt">
												<xsl:value-of select="document/name"/>
											</xsl:attribute>
											<xsl:attribute name="style">
												<xsl:text>width: 411px; height: 200px; object-fit: cover; display: block;</xsl:text>
												<xsl:if test="document/focal_point_x">
													<xsl:text> object-position: </xsl:text>
													<xsl:value-of select="document/focal_point_x"/>
													<xsl:text>% </xsl:text>
													<xsl:value-of select="document/focal_point_y"/>
													<xsl:text>%;</xsl:text>
												</xsl:if>
											</xsl:attribute>
										</img>
									</div>
									<small style="color: #666;">
										<xsl:value-of select="php:function('lang', 'card_preview')" />
										<span class="focal-label">
											<xsl:if test="document/focal_point_x">
												<xsl:text> (focal: </xsl:text>
												<xsl:value-of select="document/focal_point_x"/>
												<xsl:text>%, </xsl:text>
												<xsl:value-of select="document/focal_point_y"/>
												<xsl:text>%</xsl:text>
												<xsl:if test="document/rotation">
													<xsl:text>, rotation: </xsl:text>
													<xsl:value-of select="document/rotation"/>
													<xsl:text>°</xsl:text>
												</xsl:if>
												<xsl:text>)</xsl:text>
											</xsl:if>
										</span>
									</small>
								</div>

								<!-- 200x200 square preview with focal point -->
								<div style="text-align: center;">
									<div style="border: 1px solid #ccc; padding: 5px; background: #f5f5f5;">
										<img class="preview-image">
											<xsl:attribute name="src">
												<xsl:text>/bookingfrontend/</xsl:text>
												<xsl:value-of select="document/owner_type"/>
												<xsl:text>s/document/</xsl:text>
												<xsl:value-of select="document/id"/>
												<xsl:text>/download</xsl:text>
											</xsl:attribute>
											<xsl:attribute name="alt">
												<xsl:value-of select="document/name"/>
											</xsl:attribute>
											<xsl:attribute name="style">
												<xsl:text>width: 200px; height: 200px; object-fit: cover; display: block;</xsl:text>
												<xsl:if test="document/focal_point_x">
													<xsl:text> object-position: </xsl:text>
													<xsl:value-of select="document/focal_point_x"/>
													<xsl:text>% </xsl:text>
													<xsl:value-of select="document/focal_point_y"/>
													<xsl:text>%;</xsl:text>
												</xsl:if>
											</xsl:attribute>
										</img>
									</div>
									<small style="color: #666;">
									<xsl:value-of select="php:function('lang', 'square_preview')" />
								</small>
								</div>

								<!-- 200x411 portrait preview with focal point -->
								<div style="text-align: center;">
									<div style="border: 1px solid #ccc; padding: 5px; background: #f5f5f5;">
										<img class="preview-image">
											<xsl:attribute name="src">
												<xsl:text>/bookingfrontend/</xsl:text>
												<xsl:value-of select="document/owner_type"/>
												<xsl:text>s/document/</xsl:text>
												<xsl:value-of select="document/id"/>
												<xsl:text>/download</xsl:text>
											</xsl:attribute>
											<xsl:attribute name="alt">
												<xsl:value-of select="document/name"/>
											</xsl:attribute>
											<xsl:attribute name="style">
												<xsl:text>width: 200px; height: 411px; object-fit: cover; display: block;</xsl:text>
												<xsl:if test="document/focal_point_x">
													<xsl:text> object-position: </xsl:text>
													<xsl:value-of select="document/focal_point_x"/>
													<xsl:text>% </xsl:text>
													<xsl:value-of select="document/focal_point_y"/>
													<xsl:text>%;</xsl:text>
												</xsl:if>
											</xsl:attribute>
										</img>
									</div>
									<small style="color: #666;">
									<xsl:value-of select="php:function('lang', 'portrait_preview')" />
								</small>
								</div>
							</div>
						</div>
					</xsl:if>

					<!-- Focal Point Editor Modal (except applications) -->
					<xsl:if test="document/id and document/is_image = 1 and document/owner_type != 'application'">
						<div class="modal fade" id="focal-point-modal" tabindex="-1" role="dialog">
							<div class="modal-dialog modal-lg" style="max-width: 90vw;" role="document">
								<div class="modal-content">
									<div class="modal-header">
										<h5 class="modal-title" id="focalPointModalLabel">
											<xsl:value-of select="php:function('lang', 'edit_focal_point')" />
										</h5>
										<button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close">
										</button>
									</div>
									<div class="modal-body">
										<p style="margin-bottom: 10px;">
											<xsl:value-of select="php:function('lang', 'focal_point_instructions')" />
										</p>
										<div style="text-align: center; margin-bottom: 15px;">
											<div style="margin-bottom: 10px;">
												<button type="button" class="btn btn-sm btn-outline-secondary" id="rotate-left-btn" title="Rotate 90° counter-clockwise">
													<i class="fa fa-rotate-left"></i> Rotate Left
												</button>
												<button type="button" class="btn btn-sm btn-outline-secondary" id="rotate-right-btn" title="Rotate 90° clockwise">
													<i class="fa fa-rotate-right"></i> Rotate Right
												</button>
												<button type="button" class="btn btn-sm btn-outline-secondary" id="reset-rotation-btn" title="Reset rotation">
													<i class="fa fa-undo"></i> Reset Rotation
												</button>
											</div>
											<div>
												<strong id="rotation-display">Rotation: 0°</strong>
												<span style="margin: 0 10px;">|</span>
												<strong id="focal-point-display">X: 50%, Y: 50%</strong>
											</div>
										</div>
										<div style="text-align: center; background: #f5f5f5; padding: 20px; max-height: calc(80vh - 200px); overflow: auto;">
											<img id="focal-point-image" style="max-width: 100%; max-height: 70vh; height: auto; width: auto; display: inline-block;" />
										</div>
									</div>
									<div class="modal-footer">
										<button type="button" class="btn btn-light" id="reset-focal-point-btn">
											<xsl:value-of select="php:function('lang', 'reset_to_center')" />
										</button>
										<button type="button" class="btn btn-warning" id="remove-focal-point-btn">
											<xsl:value-of select="php:function('lang', 'remove_focal_point')" />
										</button>
										<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
											<xsl:value-of select="php:function('lang', 'Cancel')" />
										</button>
										<button type="button" class="btn btn-primary" id="save-focal-point-btn">
											<xsl:value-of select="php:function('lang', 'apply_focal_point')" />
										</button>
									</div>
								</div>
							</div>
						</div>
					</xsl:if>

				</fieldset>
			</div>
		</div>
		<div class="form-buttons">
			<input type="submit" class="pure-button pure-button-primary">
				<xsl:attribute name="value">
					<xsl:choose>
						<xsl:when test="document/id">
							<xsl:value-of select="php:function('lang', 'Update')"/>
						</xsl:when>
						<xsl:otherwise>
							<xsl:value-of select="php:function('lang', 'Create')"/>
						</xsl:otherwise>
					</xsl:choose>
				</xsl:attribute>
			</input>
			<input type="button" class="pure-button pure-button-primary" name="cancel">
				<xsl:attribute name="onclick">window.location="<xsl:value-of select="document/cancel_link"/>"</xsl:attribute>
				<xsl:attribute name="value">
					<xsl:value-of select="php:function('lang', 'Cancel')" />
				</xsl:attribute>
			</input>
		</div>
	</form>
</xsl:template>
