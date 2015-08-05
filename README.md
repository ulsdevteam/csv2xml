# csv2xml

Convert a CSV with xpath headers to XML based on an XSD.

Copyright 2015 University of Pittsburgh.
Licensed under GPL 2.0 or later.

## Assumptions

 * schema.xsd is used to generate a finite list of possible xpaths of elements and attributes
 * input.csv is a comma separated spreadsheet of textual data associated with xpaths via the column headers
 * input.csv resembles a de-normalized database dump
 * input.csv may contain a single set of repeating elements for convenience
 * input.csv may contain a magical element `embed` which will be read for a filename and the contents base64 encoded into the element.
 
 ## Known Bugs

 * The logic does not correctly handle anonymous repeating sequences.  For example, foo and bar will be treated as non-repeating elements in:
```
<xsd:sequence minOccurs="0" maxOccurs="unbounded">
  <xsd:element minOccurs="0" maxOccurs="1" ref="foo" />
  <xsd:element minOccurs="0" maxOccurs="1" ref="bar" />
</xsd:sequence>
```
* The magic element "embed" should be designated in some other way - perhaps a `#filestore` at the end?

