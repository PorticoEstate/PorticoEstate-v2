
<!-- $Id: tts.xsl 15283 2016-06-14 09:21:39Z sigurdne $ -->

<xsl:template match="data">
	<xsl:choose>
		<xsl:when test="navigate">
			<xsl:apply-templates select="navigate"/>
		</xsl:when>
	</xsl:choose>
	<xsl:call-template name="jquery_phpgw_i18n"/>
</xsl:template>

<!-- navigate -->
<xsl:template xmlns:php="http://php.net/xsl" match="navigate">

	<style>
		.tts-nav {
			max-width: 1200px;
			margin: 0 auto;
			padding: var(--ds-spacing-6, 1.5rem) var(--ds-spacing-4, 1rem);
		}

		.tts-nav__grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
			gap: var(--ds-spacing-4, 1rem);
		}

		.tts-nav__link {
			display: block;
			height: 100%;
			color: var(--ds-color-text-default, #1e2b3c);
			text-decoration: none;
		}

		.tts-nav__card {
			height: 100%;
			display: flex;
			flex-direction: column;
			background: var(--ds-color-background-default, #fff);
			border: 1px solid var(--ds-color-border-subtle, #d9dde3);
			border-radius: var(--ds-border-radius-md, 0.5rem);
			overflow: hidden;
			box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
			transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
		}

		.tts-nav__link:hover .tts-nav__card,
		.tts-nav__link:focus .tts-nav__card,
		.tts-nav__link:focus-visible .tts-nav__card {
			transform: translateY(-2px);
			box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
			border-color: var(--ds-color-accent-border-default, #2a6fc1);
		}

		.tts-nav__icon-wrap {
			display: flex;
			align-items: center;
			justify-content: center;
			padding: var(--ds-spacing-6, 1.5rem) var(--ds-spacing-4, 1rem);
			font-size: 1.5rem;
			color: var(--ds-color-neutral-text-subtle, #6c757d);
		}

		.tts-nav__icon-wrap h1 {
			margin: 0;
			font-size: inherit;
			line-height: 1;
			font-weight: var(--ds-font-weight-regular, 400);
		}

		.tts-nav__footer {
			margin-top: auto;
			padding: var(--ds-spacing-3, 0.75rem) var(--ds-spacing-4, 1rem);
			text-align: center;
			font-weight: var(--ds-font-weight-medium, 500);
			background: var(--ds-color-background-subtle, #f4f6f8);
			border-top: 1px solid var(--ds-color-border-subtle, #d9dde3);
		}
	</style>
	<div class="tts-nav">
		<div class="tts-nav__grid">
			<xsl:for-each select="sub_menu">
				<div>
					<a href="{url}" class="tts-nav__link">
						<div class="tts-nav__card">
							<div class="tts-nav__icon-wrap">
								<h1>
									<i class="{icon}"></i>
								</h1>
							</div>
							<div class="tts-nav__footer">
								<xsl:value-of select="text"/>
							</div>
						</div>
					</a>
				</div>
			</xsl:for-each>
		</div>

	</div>
</xsl:template>
