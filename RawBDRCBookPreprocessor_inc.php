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
    ['meta/{id}.xml'
                  => ['work=http://www.tbrc.org/models/work#'
                             => ['//work:archiveInfo/@access'  => 'access_restriction',
                                 '//work:archiveInfo/@license' => 'rights']]];

  protected $marcxmlLocation = 'meta/marc-{id}.xml';

  protected $imgDirectory = '{images,archive}/{id}-{*}/'; // {images,archive} is preference order, {*} is rotation through all values
  protected $imgFileRegex = '/^{*}[_-]?(\d{4})\.(?:jpg|tif)$/i'; // {*} will rotate through all the volumes
  protected $errOnImgMismatch = true;
  protected $forceSequentialIndex = true;
  protected $minImages = 3;

  protected $renameExceptionRegex = '/\.(jpg|tif)$/i'; // i.e., all of them
  protected function renameExceptionHandler($imgFile, $to, $tmpDir) {
    $this->anyFormatToJp2($imgFile, $to, $tmpDir);
  }

  private $restriction_to_addl_collections =
    ['openAccess'            => ['stream_only'],
     'fairUse'               => ['buddhist-digital-resource-center-restricted', 'inlibrary'],
     'restrictedSealed'      => ['buddhist-digital-resource-center-restricted'],
     'temporarilyRestricted' => ['buddhist-digital-resource-center-restricted'],
     'restrictedByQuality'   => ['buddhist-digital-resource-center-restricted'],
     'restrictedByTbrc'      => ['buddhist-digital-resource-center-restricted'],
     'restrictedInChina'     => ['geo_restricted']];

  protected function itemSpecialTasks($bookDir, $naming, $namingFmt) {
    // conditional metadata changes
    $metaxml = $this->get_metaxml();
    $changes = [];

    // assign collection(s) based on <access_restriction> (ultimately from MARC 506$a)...
    $restriction = (string) $metaxml->access_restriction;
    if ($restriction == '')
      fatal('no access_restriction value found in meta.xml');
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
    // ...and "Fragile Palm Leaves Foundation" items
    $publisher = (string) $metaxml->publisher;
    if (Util::str_contains(strtolower($publisher), 'fragile palm leaves foundation'))
      $changes['collection'][] = 'bdrc-fplmanuscripts';

    // override <rights> (from meta/{id}.xml: archiveInfo@license) if it was "ccby"
    if ((string) $metaxml->rights == 'ccby')
      $changes['rights'] = 'Public Domain';

    // make all restricted items noindex
    if (!in_array($restriction, ['openAccess', 'fairUse']))
      $changes['noindex'] = 'true';

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