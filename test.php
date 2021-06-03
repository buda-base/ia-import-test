<?php 

  // read an XML file, extract metadata by applying the xpath queries given in                                                                                          
  // $metaMap, and add the results to $metaArray                                                                                                                        
  //                                                                                                                                                                    
  // for examples see the MusicBrainzExtractor class (in the ExtractMeta derive                                                                                         
  // module) and the several Raw*BookPreprocessor classes                                                                                                               
  //                                                                                                                                                                    
  // a note on namespace info: the prefix used in your xpath queries needs to                                                                                           
  // match that given in "ns info" part of your $metaMap (e.g.,                                                                                                         
  // 'dc=http://purl.org/dc/elements/1.0/'), but that prefix needn't be the same                                                                                        
  // as what's used in the original XML document; what matters is that the                                                                                              
  // prefix you use links your element names to the correct URI (see                                                                                                    
  // http://www.php.net/manual/en/simplexmlelement.registerxpathnamespace.php).                                                                                         
  // note also that if the XML uses no prefix but does specify a (default)                                                                                              
  // namespace, you *do* have to use a prefix in the queries and provide it                                                                                             
  // in your "ns info," to link the prefix you've chosen to the namespace URI                                                                                           
  public static function mapMetadataFromXml(&$metaArray, $xmlPath, $metaMap, $trim = false) {
    $xml = simplexml_load_file($xmlPath);
    if (!$xml) fatal("Unable to read and parse XML metadata file \"$xmlPath\"");

    // loop over all specified namespaces                                                                                                                               
    foreach ($metaMap as $nsInfo => $elemList) {
      if ($nsInfo != '') {
        list($prefix, $uri) = explode('=', $nsInfo); // e.g., 'dc=http://purl.org/dc/elements/1.0/'                                                                     
        $xml->registerXPathNamespace($prefix, $uri);
      }
      // loop over all xpath queries for that namespace                                                                                                                 
      foreach ($elemList as $query => $metaElem) {
        $obj = $xml->xpath($query);
        if ($obj === false) continue; // xpath errored (typically '/a/b/c' where b is not present)                                                                      
        // loop over all values returned by the xpath query (if any)                                                                                                    
        foreach (array_unique($obj) as $val) {  // some need de-duping (should probably make uniq optional)                                                             
          // add each (non-null) value to the array for the corresponding meta.xml element                                                                              
          $str = (string) $val;
          if ($str === '') continue;
          if ($trim)
            $str = trim(str_replace("\xC2\xA0", ' ', $str)); // xC2 xA0 is nbsp                                                                                         
          if (is_string($metaElem)) {
            // usual case, $metaElem is just the name of the meta.xml element to use                                                                                    
            $metaArray[$metaElem][] = $str;
          } else {
            // $metaElem is array of meta.xml element and template to insert the value into, as in                                                                      
            // ['external-identifier', 'urn:mb-release-id:{value}']                                                                                                     
            //                                                                                                                                                          
            // note that by using a pattern without {value}, you can insert a constant based on the                                                                     
            // success of the xpath query, as in                                                                                                                        
            // ['has-some-property', 'true']                                                                                                                            
            $metaArray[$metaElem[0]][] = str_replace('{value}', $str, $metaElem[1]);
          }
        }
      }
    }
  }
