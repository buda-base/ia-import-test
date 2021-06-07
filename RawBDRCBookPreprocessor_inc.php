<?php

class RawBDRCBookPreprocessor extends RawBookPreprocessor {

  public function __construct() {
    $this->progressObj = new Progress;
  }

  protected $idRegex = '/^bdrc-(.+)/';

  protected $metaFixed =
    // meta.xml elements that are constant for all books covered by this class
    ['mediatype'   => 'texts',
     'contributor' => 'Buddhist Digital Resource Center',
     'sponsor'     => 'Buddhist Digital Resource Center'];

  protected $metaMap =
    // elements that vary across books; plucked from the xml metadata files
    // provided with the book and mapped to meta.xml elements as shown here
    //
    //[path to file]  [ns info]  [xpath]                         [elem in meta.xml]
    [ 
      // for the legacy tbrc.org format
      'meta/{id}.xml'
                  => ['work=http://www.tbrc.org/models/work#'
                             => ['//work:archiveInfo/@access'  => 'access_restriction',
                                 '//work:archiveInfo/@license' => 'rights']],
      // for the new BUDA format, read the MARCXML
      'meta/marc-{id}.xml'
                  => ['marc=http://www.loc.gov/MARC21/slim'
                             => ['//marc:datafield@tag=="506"/marc:subfield@tag=="a"'  => 'marc_506a']]
    ];                           ];

  protected $marcxmlLocation = 'meta/marc-{id}.xml';

  protected $imgDirectory = '{images,archive}/{id}-{*}/'; // {images,archive} is preference order, {*} is rotation through all values
  protected $imgFileRegex = '/^{*}[_-]?(\d{4})\.(?:jpe?g|tiff?)$/i'; // {*} will rotate through all the volumes
  protected $errOnImgMismatch = true;
  protected $forceSequentialIndex = true;
  protected $minImages = 3;

  protected $renameExceptionRegex = '/\.(jpe?g|tiff?)$/i'; // i.e., all of them
  protected function renameExceptionHandler($imgFile, $to, $tmpDir) {
    $this->anyFormatToJp2($imgFile, $to, $tmpDir);
  }

  // tbrc.org format
  private $restriction_to_addl_collections =
    ['openAccess'            => ['stream_only'],
     'fairUse'               => ['buddhist-digital-resource-center-restricted', 'inlibrary'],
     'fairUseNolib'          => ['buddhist-digital-resource-center-restricted'],
     'restrictedSealed'      => ['buddhist-digital-resource-center-restricted'],
     'temporarilyRestricted' => ['buddhist-digital-resource-center-restricted'],
     'restrictedByQuality'   => ['buddhist-digital-resource-center-restricted'],
     'restrictedByTbrc'      => ['buddhist-digital-resource-center-restricted'],
     'restrictedInChina'     => ['geo_restricted']];
     'restrictedInChinaLib'  => ['geo_restricted']];
  
  // BUDA MARCXML format
  private $marc_506a_to_addl_collections =
    ['Access restricted.'    => 
       ['buddhist-digital-resource-center-restricted'],
     'Access restricted in some countries.'  => 
       ['geo_restricted'],
     'Open Access.' =>
       ['stream_only'],
     'Access restricted to a few sample pages.' =>
       ['buddhist-digital-resource-center-restricted'],
     'Access restricted to a few sample pages, access restricted in some countries.' =>
       ['geo_restricted']
    ]

  private $marc_506a_noindex = ['Access restricted.',
     'Access restricted in some countries.',
     'Access restricted to a few sample pages, access restricted in some countries.'
    ]

  private function get_json($bookDir) {
    $fname = $bookDir . $this->bookId . ".json";
    if (!Util::file_exists($fname)) {
      return null;
    }
    $json_str = file_get_contents($fname);
    $json_data = json_decode($json_str, true);
    return $json_data;
  }

  protected function itemSpecialTasks($bookDir, $naming, $namingFmt) {
    // conditional metadata changes
    $metaxml = $this->get_metaxml();
    $json_data = $this->get_json($bookDir);
    $changes = [];

    // assign collection(s)
    // first look at tbrc.org format:
    $restriction = (string) $metaxml->access_restriction;
    $marc_506a = (string) $metaxml->marc_506a;
    if ($restriction != '') {
      if (!isset($this->restriction_to_addl_collections[$restriction]))
        fatal("unexpected access_restriction value \"$restriction\"");
      $changes['collection'] = Util::cons('buddhist-digital-resource-center',
                                          $this->restriction_to_addl_collections[$restriction]);
      // ...with special adjustments for "restrictedInChina" items...
      if ($restriction == 'restrictedInChina') {
        $changes['geo_restricted'] = 'CN';
        if ((string) $metaxml->rights == 'copyright')
          $changes['collection'][] = 'buddhist-digital-resource-center-restricted';
      }
      // override <rights> (from meta/{id}.xml: archiveInfo@license) if it was "ccby"
      if ((string) $metaxml->rights == 'ccby')
        $changes['rights'] = 'Public Domain';

      // make all restricted items noindex
      if (!in_array($restriction, ['openAccess', 'fairUse']))
        $changes['noindex'] = 'true';
    } elseif ($marc_506a != '') {
      if (!isset($this->marc_506a_to_addl_collections[$marc_506a]))
        fatal("unexpected access_restriction value \"$marc_506a\"");
      $changes['collection'] = Util::cons('buddhist-digital-resource-center',
                                          $this->marc_506a_to_addl_collections[$marc_506a]);
      // make all restricted items noindex
      if (!in_array($marc_506a, $marc_506a_noindex))
        $changes['noindex'] = 'true';

      if ($marc_506a == "Access restricted to a few sample pages.") {
        // put copyrighted books in 'inlibrary' collection if they're ok to put there:
        $json_data = get_json($bookDir);
        if (!array_key_exists("digitalLendingPossible", $json_data) || $json_data["digitalLendingPossible"] == true) {
          $changes['collection'][] = 'inlibrary';
          $changes['noindex'] = 'false';
        }
      }
    } else {
      fatal("cannot find supplementary data from tbrc or BUDA format");
    }
    
    // FPL items (common, based on the ID)
    if (strpos( str($this->bookId) , "W1FPL" ) === 0 || strpos( str($this->bookId) , "W1EAP" ) === 0 ) {
      $changes['collection'][] = 'bdrc-fplmanuscripts';
    }

    // set BookReader default to 1-up mode if images are in extreme landscape
    // (judge based on 3rd image of 1st volume, as first two images are cover sheets)
    $zip     = $naming->zipName(       $namingFmt);
    $zipDir  = $naming->archiveDirName($namingFmt);
    $imgFile = $naming->imageName(     $namingFmt, 2); // 3rd image
    $unzipCmd = CommandLine::cmd('unzip')->option('-p')->arg("$bookDir$zip")->arg("$zipDir/$imgFile");
    list($width, $height) = ImageMetadata::get_image_size($unzipCmd);
    if ($width > (1.25 * $height))
      $changes['bookreader-defaults'] = 'mode/1up';

    // write changes
    $this->write_metaxml_changes($changes);
  }

  protected function prelimScandata() {
    // initialize new scandata
    list($scandataxml, $pageData) = ScandataXML::getOrMakeScandata(false, null, 'pageData');

    // fill <pageData> (providing just "leafNum", and setting pageType to "Title" on the third image)
    // loop, adding a new <page> to <pageData> for each image
    $empty = [];
    for ($leaf = 0; $leaf < $this->numImages; $leaf++) {
      $vals = $leaf == 2 ? ['pageType' => 'Title'] : $empty;
      ScandataXML::fillPageElem($pageData, null, (string) $leaf, $vals);
    }

    return $scandataxml;
  }
}