<?php
/**
* CSV to XML conversion tool
* Given an XSD and a CSV file, try to bind the CSV data into XML
* We will:
*   Walk through the XSD to determine rough structure
*   Read the first line of the CSV file for xpaths describing the column data
*   Sort the CSV data, left to right based on XML (xpath) order, top to bottom based on similarity
*   Parse each line of the CSV data, creating new XML entities when data changes are encountered.
*
* This tool's initial use-case was to import journal data from a flat structure to the PKP OJS native importexport XML.
* C.f. https://github.com/pkp/ojs/tree/ojs-dev-2_4/plugins/importexport/native or similar
*
* Copyright 2015, University of Pittsburgh
* Author: Clinton Graham <ctgraham@pitt.edu>
* License: GPL 2.0 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*
**/

/**
* The SchemaWalker class reads a simple XSD and returns information about the structure via xpath strings
* We are primarily concerned with xpaths that could contain text content and with xpaths that could be repeated
**/
class SchemaWalker {

  /**
  * @var $doc DOMDocument
  **/
  private $doc;
  /**
  * @var $xpath DOMXPath
  **/
  private $xpath;
  /**
  * @var $allElements array array('name' => DOMNode, ...)
  **/
  private $allElements;
  /**
  * @var $allPaths array array('xpath', ...) in path order
  **/
  private $allPaths;
  /**
  * @var $rootElement string
  **/
  private $rootElement;
  /**
  * @var $pathPlurality array array('xpath' => $boolean, ...) indicating if xpath has maxoccurs > 1
  **/
  private $pathPlurality;

  private $debug = false;

  /**
  * Constructor
  * @param string $filename Filename of the XSD
  * @param string $root Root element name
  **/
  public function __construct($filename, $root) {
    $this->doc = new DOMDocument();
    $this->doc->load($filename);
    $this->xpath = new DOMXPath($this->doc);
    $this->xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
    $this->rootElement = $root;
  }

  /**
  * Walk the element tree, returning an array of paths
  * @param DOMNode $element The element to traverse
  * @param string $path Xpath to this element
  * @return array List of xpaths
  **/
  protected function walkElements($element, $path = NULL) {
    $firstRun = false;
    if ($path === NULL) {
      $firstRun = true;
      $path = '';
    }
    $eleArray = array();
    // Process attributes first
    $elementDefs = $this->xpath->evaluate("xs:attribute", $element);
    foreach($elementDefs as $elementDef) {
      $eleArray = array_merge($eleArray, $this->walkElements($elementDef, $path));
    }
    // Now process this node itself
    $multiple = false;
    switch ($element->nodeName) {
      case 'xs:element':
        if ($element->getAttribute('name')) {
          $path = $path.'/'.$element->getAttribute('name');
          if ($element->getAttribute('type')) {
            $eleArray[] = $path;
          }
          // the plurality may have been set from a ref="element"
          if (!isset($this->pathPlurality[$path])) { 
            $multiple = $element->getAttribute('maxOccurs') && ($element->getAttribute('maxOccurs') != '1');
            $this->pathPlurality[$path] = $multiple;
          }
        } else if ($element->getAttribute('ref')) {
          $multiple = $element->getAttribute('maxOccurs') && ($element->getAttribute('maxOccurs') != '1');
          $this->pathPlurality[$path.'/'.$element->getAttribute('ref')] = $multiple;
          $eleArray = array_merge($eleArray, $this->walkElements($this->allElements[$element->getAttribute('ref')], $path));
        }
        break;
      case 'xs:attribute':
        $path = $path.'@'.$element->getAttribute('name');
        $eleArray[] = $path;
        $this->pathPlurality[$path] = $multiple;
        break;
      case 'xs:simpleContent':
        $eleArray[] = $path;
        $this->pathPlurality[$path] = $multiple;
        break;
      case 'xs:complexType':
        if ($element->getAttribute('mixed') == 'true') {
          $eleArray[] = $path;
          $this->pathPlurality[$path] = $multiple;
        }
    }
    // Process any child nodes
    $elementDefs = $this->xpath->evaluate("*", $element);
    foreach($elementDefs as $elementDef) {
      // except attributes
      if ($elementDef->nodeName !== 'xs:attribute') {
        $eleArray = array_merge($eleArray, $this->walkElements($elementDef, $path));
      }
    }

    if ($this->debug && $firstRun) {
      error_log(var_export($eleArray, true));
      error_log(var_export($this->pathPlurality, true));
    }
    return $eleArray;
  }

  /**
  * Get all elements, indexed by name
  * @return array Hash of element names to DOMNodes
  **/
  public function elementsByName() {
    $this->allElements = array();
    $elements = $this->xpath->evaluate("/xs:schema/xs:element");
    foreach($elements as $element) {
      $this->allElements[$element->getAttribute('name')] = $element;
    }
    return $this->allElements;
  }

  /**
  * Get a list of all element xpaths
  * @return array List of xpaths
  **/
  public function elementsByPath() {
    if (!$this->allElements) {
      $this->elementsByName();
    }
    $this->allPaths = $this->walkElements($this->allElements[$this->rootElement]);
    return $this->allPaths;
  }

  /**
  * Check if an xpath exists in the schema
  * @param string $path xpath
  * @return boolean 
  **/
  public function pathExists($path) {
    if (!$this->allPaths) {
      $this->elementsByPath();
    }
    return in_array($path, $this->allPaths);
  }

  /**
  * Get a boolean whether this path allows multiple elements
  * @param string $path xpath
  * @return boolean 
  **/
  public function multipleOf($path) {
    return isset($this->pathPlurality[$path]) && $this->pathPlurality[$path];
  }


  /**
  * Get a numeric offset representing the xpath (depth ordering)
  * @param string $path xpath
  * @return int 
  **/
  public function orderOf($path) {
    if (!$this->allPaths) {
      $this->elementsByPath();
    }
    return array_search($path, $this->allPaths);
  }

  /**
  * Get an xpath by numeric offset (depth ordering)
  * @param int $order
  * @return string 
  **/
  public function pathByOrder($order) {
    if (!$this->allPaths) {
      $this->elementsByPath();
    }
    return isset($this->allPaths[$order]) ? $this->allPaths[$order] : false;
  }

}

/**
* The DataWalker class reads a CSV file with xpath headers and converts the contents to XML based on a schema and root element
**/
class DataWalker {
  /**
  * @var $dom DOMDocument
  **/
  private $dom;
 
  /**
  * @var $context array
  **/
  private $context;

  /**
  * @var $filename string
  **/
  private $filename;

  /**
  * @var $tempDirectory string
  **/
  private $tempDirectory;

  /**
  * @var $schemaFilename string
  **/
  private $schemaFilename;

  /**
  * @var $rootElement string
  **/
  private $rootElement;

  /**
  * @var $columnMap array
  **/
  private $columnMap;

  /**
  * @var $fileStore string
  **/
  private $fileStore = 'filestore://';

  private $debug = false;

  /**
  * Constructor
  * @param string $filename Filename of the CSV
  * @param string $schemaname Filename of the XSD
  * @param string $rootelement Root element name
  **/
  public function __construct($filename, $schemaname, $rootelement) {
    $this->filename = $filename;
    $this->schemaFilename = $schemaname;
    $this->rootElement = $rootelement;
    $this->walker = new SchemaWalker($this->schemaFilename, $this->rootElement);
  }

  public function parse() {

    $tmpPrefix = 'nat';
    $tmpname = tempnam("/tmp", $tmpPrefix);

    $csvContent = file_get_contents($this->filename);
    // Check if this is CRLF terminated, LF terminated, or mixed
    $crlfs = substr_count($csvContent, "\r\n");
    $lfs = substr_count($csvContent, "\n");
    if ($crlfs && $lfs > $crlfs) {
      // Open the CSV file, rewite it, removing spurrious newlines because fgetcsv() can't handle them
      $file = fopen($this->filename, 'r');
      if (!$file) {
        die('Failed to open '.$this->filename."\n");
      }
      $tmpfile = fopen($tmpname, 'w');
      if (!$tmpfile) {
        die('Failed to open '.$tmpname."\n");
      }
      while ($line = fgets($file)) {
        if (substr($line, -2) == "\r\n") {
          fputs($tmpfile, $line);
        } else {
          fputs($tmpfile, substr($line, 0, strlen($line) - 1));
        }
      }
      fclose($file);
      fclose($tmpfile);
    } else {
      file_put_contents($tmpname, $csvContent);
    }
    $csvContent = '';
    $tmpfile = fopen($tmpname, 'r');
    if (!$tmpfile) {
      die('Failed to open '.$tmpname."\n");
    }
    // The first line must be a list of xpaths
    $line = fgetcsv($tmpfile);
    $columns = array();
    // verify each xpath
    foreach ($line as $key => $col) {
      if ($this->walker->pathExists($col)) {
        $columns[$key] = $this->walker->orderOf($col);
      } else if ($col) {
        error_log($col.' does not exist in the schema');
      }
    }
    // check for repeated columns
    $dups = array_count_values(array_values($columns));
    $dupCount = 0;
    foreach ($dups as $k => $dup) {
      if ($dup == 1) {
        // Single column: not duplicated
        unset($dups[$k]);
      } else {
        // Check if we've already counted the duplicates
        if ($dupCount > 0) {
          // If we have, are the counts consistent?
          if ($dupCount != $dup) {
            // If not consistent, flag this error
            error_log('The number of repeated columns is not consistent.  YMMV.');
            $dupCount = -1;
          }
        } else if ($dupCount == 0) {
          // If we haven't counted yet, this is our base count
          $dupCount = $dup;
        }
      }
    }
    asort($columns);
    $columnMap = array();
    // move repeated columns to the very end of the line
    $dupGroup = array(); // temporarily record a group of duplicates
    $offset = 0; // remember a numeric shift of elements after the duplicates
    foreach ($columns as $key => $col) {
      if (isset($dups[$col])) {
        // If this column is a duplicate, save it to the group
        $dupGroup[$key] = $col;
      } else {
        // if duplicates have been encountered, inject them here
        if ($dupGroup) {
          ksort($dupGroup);
          foreach ($dupGroup as $dk => $dc) {
            // increment the offset as the elements are injected
            $columnMap[$offset++] = array('xpath' => $this->walker->pathByOrder($dc), 'position' => $dk);
          }
          $dupGroup = array();
        }
        $columnMap[$offset++] = array('xpath' => $this->walker->pathByOrder($col), 'position' => $key);
      }
    }
    // catch the final duplicate group, if applicable
    if ($dupGroup) {
      ksort($dupGroup);
      foreach ($dupGroup as $dk => $dc) {
        $columnMap[$offset++] = array('xpath' => $this->walker->pathByOrder($dc), 'position' => $dk);
      }
      $dupGroup = array();
    }
    // create a new tempfile with the columns in order
    $sortname = $tmpname.'.sorting';
    $sortfile = fopen($sortname, 'w');
    if (!$sortfile) {
      die('Failed to open '.$sortname."\n");
    }
    // rewrite each line in new column order
    while ($line = fgetcsv($tmpfile)) {
      $lineout = array();
      foreach ($columnMap as $value) {
        $lineout[] = $line[$value['position']];
      }
      fputcsv($sortfile, $lineout);
    }
    fclose($tmpfile);
    fclose($sortfile);
    unlink($tmpname);

    // sort this file externally
    $tmpname = $tmpname.'.sorted';
    system("sort < $sortname > $tmpname");
    unlink($sortname);

    // create a positional sequence 
    $i = 0;
    foreach ($columnMap as $k => $v) {
      $columnMap[$k]['position'] = $i++;
    }
    krsort($columnMap);
    $this->columnMap = $columnMap;

    // Create a temporary directory to store files by hash
    // We'll enumerate this later to add file contents into the XML
    // We don't build the XML with the file contents because it uses too much memory!
    $this->tempDirectory = sys_get_temp_dir().'/'.$tmpPrefix.'.'.getmypid().'/';
    if (file_exists($this->tempDirectory)) {
      system('rm -fr '.$this->tempDirectory);
    }
    mkdir($this->tempDirectory);

    // Now we can finally work on the file
    $file = fopen($tmpname, 'r');
    if (!$file) {
      die('Failed to open '.$tmpname."\n");
    }
    $this->dom = new DOMDocument();
    $element = array();
    // evaluate each line in the file
    $i = 1;
    $lastline = fgetcsv($file);
    while ($line = fgetcsv($file)) {
      $nonempty = false;
      foreach ($line as $v) {
        if (!empty($v)) {
          $nonempty = true;
        }
      }
      if ($nonempty) {
        if ($this->debug) { error_log('NEWLINE--------------------'.$i); }
        $this->parseLine($lastline, $line);
        $lastline = $line;
      }
      $i++;
    }
    if ($this->debug) { error_log('LASTLINE--------------------'); }
    // create a blank line
    foreach ($columnMap as $map) {
      $line[$map['position']] = '';
    }
    $this->parseLine($lastline, $line);
    if ($this->debug) { error_log('--- CREATE ROOT ----'); }
    $this->createContextElement('/'.$this->rootElement);

    unlink($tmpname);

    $this->dom->formatOutput = true;
    $xml = $this->dom->saveXML();
    unset($this->dom);

    $xmlOut = '';
    for ($i = 0; $i < strlen($xml); $i++) {
      if (substr($xml, $i, strlen($this->fileStore)) === $this->fileStore) {
        $xmlOut .= file_get_contents($this->tempDirectory.substr($xml, $i + strlen($this->fileStore), strlen(sha1(''))));
        $i = $i + strlen($this->fileStore.sha1('')) - 1;
      } else {
        $xmlOut .= $xml[$i];
      }
    }
    system('rm -fr '.$this->tempDirectory);
    
    return $xmlOut;
  }

  /**
  * Calculate the parent XPath for the given XPath $xpath
  * @param string $xpath
  * @return string
  **/
  private function parentPath($xpath) {
    $components = explode('/', str_replace('@', '/', $xpath));
    array_pop($components);
    return implode('/', $components);
  }

  /**
  * Calculate the shared XPath for two given XPaths $xpath1 and $xpath2
  * @param string $xpath1
  * @param string $xpath2
  * @return string
  **/
  private function sharedPath($xpath1, $xpath2) {
    // is xpath2 already in xpath1?
    if (strpos($xpath1, $xpath2) === 0) {
      return $xpath2;
    }
    // reduce $xpath1 until it is in $xpath2
    $xpath = $xpath1;
    while ($xpath = $this->parentPath($xpath)) {
      if (strpos($xpath2, $xpath) === 0) {
         return $xpath;
      }
    }
    return '/';
  }


  /**
  * Create a new DOM Element for the XPath $xpath, with option text value $value
  * @param string $xpath
  * @param string $value
  * @return DOMElement
  **/
  private function createDOMElement($xpath, $value = NULL) {
    // check if this is an attribute or an element
    if ($this->debug) { error_log(' %creating '.$xpath); }
    if (strpos($xpath, '@') !== false) {
      $e = $this->dom->createAttribute( array_pop(explode('@', $xpath)) );
      $e->value = htmlspecialchars(utf8_encode($value), ENT_NOQUOTES);
    } else {
      $e = $this->dom->createElement( array_pop(explode('/', $xpath)) );
      // add each member to the element
      if (isset($this->context[$xpath])) {
        foreach (array_reverse($this->context[$xpath]) as $element) {
          $e->appendChild($element);
        }
        $this->context[$xpath] = array();
      }
      if ($value) {
        $t = $this->dom->createTextNode( utf8_encode($value) );
        $e->appendChild($t);
      }
    }
    return $e;
  }

  /**
  * Find the nearest parent container which is repeatable
  * @param string $xpath
  * @return string
  **/
  private function multiParentOf($xpath) {
    while ($xpath != '/'.$this->rootElement && ! $this->walker->multipleOf($xpath)) {
      $xpath = $this->parentPath($xpath);
    }
    return $xpath;
  }
   
  /**
  * Compare the last line to the current line; create new elements from last line's data based on current line changes.
  * @param string $lastline
  * @param string $line
  **/
  private function parseLine($lastline, $line) {
    // $lastxpath is the path to the previous element
    $lastxpath = '/';
    // find the greatest common ancestor (shared xpath)
    // elements will ultimately be appended into this context
    $changed = '/'.$this->rootElement;
    $stopat = '/'.$this->rootElement;
    // check for where this line diverges from the last line
    foreach (array_reverse($this->columnMap, true) as $map) {
      if ($lastline[$map['position']] != $line[$map['position']]) {
        $changed = $map['xpath'];
        break;
      }
    }
    // check where the last parent element started for this change
    foreach (array_reverse($this->columnMap, true) as $map) {
      if ($this->multiParentOf($changed) == $this->multiParentOf($map['xpath'])) {
        $stopat = $map['xpath'];
        break;
      }
    }
    $queue_into = $this->multiParentOf($stopat);
    if ($this->debug) {error_log('STOP: '.$stopat.'; CHG: '.$changed.'; Q: '.$queue_into); }
    $lastxpath = '';
    // $dupCheck is an array of xpaths we have already seen in this line
    $dupCheck = array();
    // check each column to see if it changed since the last line
    foreach ($this->columnMap as $map) {
      // if this column has already been processed, force the next element to be a sibling
      if (in_array($map['xpath'], $dupCheck, true)) {
        // calculate the parent element
        $newpath = $this->parentPath($lastxpath);
        // create a new parent for this duplicate xpath if applicable
        if ($this->parentPath($map['xpath']) === $newpath) {
          $this->createContextElement($newpath);
        }
        // clear the duplicates.  NB: only one element can be duplicated!
        $dupCheck = array();
      }
      if ($lastline[$map['position']]) {
        // if a prior element was potentially created, create any containers up to the shared path
        if ($lastxpath) {
          $this->createContainers($lastxpath, $this->sharedPath($lastxpath, $map['xpath']));
        }
        // try to create this element or attribute
        if (strpos($map['xpath'], '@') !== false) {
          // if an attribute, create the node and value
          if ($this->debug) { error_log(' >creating '.$map['xpath']); }
          $e = $this->dom->createAttribute( array_pop(explode('@', $map['xpath'])) );
          $e->value = htmlspecialchars($lastline[$map['position']], ENT_NOQUOTES);
        } else if (substr($map['xpath'], -6) == '/embed') {
          // This element (the embed) is magical
          if (file_exists($lastline[$map['position']]) && is_file($lastline[$map['position']])) {
            $file = sha1_file($lastline[$map['position']]);
            $contents = base64_encode(file_get_contents($lastline[$map['position']]));
            if (file_put_contents($this->tempDirectory.$file, $contents) === false) {
              error_log('file_put_contents errored on '.$lastline[$map['position']]);
            }
            $e = $this->createDOMElement($map['xpath'], $this->fileStore.$file);
          } else {
            error_log('File "'.$lastline[$map['position']].'" does not exist.');
          }
        } else {
          // if an element, create the node and value
          $e = $this->createDOMElement($map['xpath'], $lastline[$map['position']]);
        }
        // calculate the parent element
        $newpath = $this->parentPath($map['xpath']);
        // add this to the membership of the parent
        if ($this->debug) { error_log(' >appending to '.$newpath); }
        $this->context[$newpath][$this->hashDOMNode($e)] = $e;
        $lastxpath = $map['xpath'];
      }
      // add this element to our duplicate checking
      $dupCheck[] = $map['xpath'];
      // Don't create the elements after this
      if ($map['xpath'] == $stopat) {
        $this->createContainers($queue_into, $map['xpath']);
        $this->createContextElement($queue_into);
        break;
      }
    }
  }


  function hashDOMNode($e) {
    $d = new DOMDocument();
    $d->loadXML('<root />');
    $n = $d->importNode($e, true);
    $d->documentElement->appendChild($n);
    return sha1($d->saveXML());
  }

  /**
  * Using $context resources, create containers for parents of $createfrom until $createto
  * For example if $createfrom, is '/issues/issue/section/article/galley/file/href' and $createto is '/issues/issue/section/article/pages',
  * create a 'file' element and place href in it, then create a 'galley' element and place 'file' in that.
  *
  * @param string $createfrom, is the path to the element that needs to be created
  * @param string $createto is the path to the node about to be created by the caller
  *
  **/
  function createContainers($createfrom, $createto) {
    // create an array of components from the previous xpath
    $components = explode('/', $this->parentPath($createfrom));
    // create each remaining component as a container
    while ($components) {
      // work with the last component of the xpath
      $component = array_pop($components);
      // reconstruct the xpath
      $path = implode('/', $components).'/'.$component;
      if ($path == substr($createto, 0, strlen($path))) {
        // if this xpath is wholly contained within the new xpath, we're done
        break;
      } else {
        // create this xpath as an element
        $this->createContextElement($path);
      }
    }
  }

  /**
  * Create an element specified by $xpath, using the content specified in $context
  *
  * @param string $xpath an XPath to the new node
  **/
  function createContextElement($xpath) {
    // if we have collected any members of this xpath
    if (isset($this->context[$xpath])) {
      // create the element
      if ($this->debug) { error_log(' :creating '.$xpath); }
      $new = $this->dom->createElement(substr($xpath, strrpos($xpath, '/') + 1));
      // add each member to the element
      foreach (array_reverse($this->context[$xpath]) as $k => $element) {
        $new->appendChild($element);
      }
      // is this the root element?
      if ($xpath == '/'.$this->rootElement) {
        // if so, append it as the child of the DOM
        $this->dom->appendChild($new);
      } else {
        // otherwise, clear out the membership of this element and add this element as a member of the parent
        $this->context[$xpath] = array();
        if ($this->debug) { error_log(' :appending to '.$this->parentPath($xpath)); }
        $this->context[$this->parentPath($xpath)][$this->hashDOMNode($new)] = $new;
      }
    }
  }
}

// Application: read a CSV file to create an XML based on the root element and the schema specified
if (count($argv) < 4) {
  die('Usage: '.$argv[0].' <schema.xsd> <rootElement> <file.csv>'."\n");
}

$reader = new DataWalker($argv[3], $argv[1], $argv[2]);
print $reader->parse();
exit;

