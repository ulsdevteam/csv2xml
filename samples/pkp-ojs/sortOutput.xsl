<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="xml" version="1.0" encoding="UTF-8" indent="yes" />
  <xsl:template match="issue">
    <xsl:copy>
    <xsl:apply-templates select="@*"/>
    <xsl:apply-templates>
      <xsl:sort select="substring-before(concat(article/pages, '-'), '-')" data-type="number" />
    </xsl:apply-templates>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="section">
    <xsl:copy>
    <xsl:apply-templates select="@*"/>
    <xsl:apply-templates>
      <xsl:sort select="substring-before(concat(pages, '-'), '-')" data-type="number" />
    </xsl:apply-templates>
    </xsl:copy>
  </xsl:template>
  <xsl:template match="@*|node()">
    <xsl:copy>
      <xsl:apply-templates select="@*|node()"/>
    </xsl:copy>
  </xsl:template>
</xsl:stylesheet>
