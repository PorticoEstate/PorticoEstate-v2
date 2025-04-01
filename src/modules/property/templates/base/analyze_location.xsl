<xsl:template match="data">
<xsl:apply-templates select="analyze"/>
</xsl:template>

<xsl:template match="analyze">
    <xsl:variable name="lang_analyze_location">Location Hierarchy Analysis</xsl:variable>
    <xsl:variable name="lang_run_analysis">Run Analysis</xsl:variable>
    <xsl:variable name="lang_analysis_results">Analysis Results</xsl:variable>
    <xsl:variable name="lang_analysis_description">This tool analyzes the location hierarchy for inconsistencies and suggests fixes. It requires admin privileges.</xsl:variable>
    
    <div class="pure-form pure-form-aligned">
        <div class="pure-control-group">
            <label><xsl:value-of select="$lang_analyze_location"/></label>
        </div>
        
        <div class="pure-control-group">
            <p><xsl:value-of select="$lang_analysis_description"/></p>
        </div>
        
        <form method="post" action="">
            <input type="hidden" name="run_analysis" value="yes" />
            
            <div class="pure-controls">
                <button type="submit" class="pure-button pure-button-primary">
                    <xsl:value-of select="$lang_run_analysis"/>
                </button>
            </div>
        </form>
        
        <xsl:if test="analysis_ran = true()">
            <div class="pure-control-group">
                <h3><xsl:value-of select="$lang_analysis_results"/></h3>
                <div class="analysis-results" style="background: #f8f8f8; border: 1px solid #ddd; padding: 10px; white-space: pre-wrap; font-family: monospace; max-height: 800px; overflow: auto;">
                    <xsl:value-of select="analysis_results" disable-output-escaping="yes"/>
                </div>
            </div>
        </xsl:if>
    </div>
</xsl:template>

