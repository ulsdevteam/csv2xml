# csv2xml

Convert a CSV with xpath headers to XML based on an XSD.

Copyright 2015 University of Pittsburgh.
Licensed under GPL 2.0 or later.

## Assumptions

 * *nix architecture with PHP 5.3+ and XML/DOM support.
 * schema.xsd is used to generate a finite list of possible xpaths of elements and attributes
 * schema.xsd must name the http://www.w3.org/2001/XMLSchema as "xs"
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
* schema.xsd must name the http://www.w3.org/2001/XMLSchema as "xs"
* the root element should be deduced from the xpath headers, not required at the command line
* there is not yet a way to distinguish ambiguous children/grandchildren.

If articles have multiple titles and multiple authors, how can we tell if Quantum Voltage is an alternate title for Electrodynamics, or is a different article?

| volume | year | number | sectionTitle | articleTitle    | author   |
|:-------|------|-------:|:-------------|:----------------|:---------|
| 1      | 2010 |      1 | General      | Introduction    | Einstein |
| 1      | 2010 |      1 | General      | Introduction    | Tesla    |
| 1      | 2010 |      1 | General      | Introduccion    | Einstein |
| 1      | 2010 |      1 | General      | Introduccion    | Tesla    |
| 1      | 2010 |      2 | Research     | Electrodynamics | Einstein |
| 1      | 2010 |      2 | Research     | Electrodynamics | Tesla    |
| 1      | 2010 |      2 | Research     | Quantum Voltage | Einstein |
| 1      | 2010 |      2 | Research     | Quantum Voltage | Tesla    |

This currently works for the sample PKP-OJS data only because of a bug.  :(

