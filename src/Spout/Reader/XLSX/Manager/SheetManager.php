<?php

namespace Box\Spout\Reader\XLSX\Manager;

use Box\Spout\Reader\Wrapper\XMLReader;
use Box\Spout\Reader\XLSX\Creator\EntityFactory;
use Box\Spout\Reader\XLSX\Sheet;

/**
 * Class SheetManager
 * This class manages XLSX sheets
 *
 * @package Box\Spout\Reader\XLSX\Manager
 */
class SheetManager
{
    /** Paths of XML files relative to the XLSX file root */
    const WORKBOOK_XML_RELS_FILE_PATH = 'xl/_rels/workbook.xml.rels';
    const WORKBOOK_XML_FILE_PATH = 'xl/workbook.xml';

    /** Definition of XML node names used to parse data */
    const XML_NODE_WORKBOOK_VIEW = 'workbookView';
    const XML_NODE_SHEET = 'sheet';
    const XML_NODE_SHEETS = 'sheets';
    const XML_NODE_RELATIONSHIP = 'Relationship';

    /** Definition of XML attributes used to parse data */
    const XML_ATTRIBUTE_ACTIVE_TAB = 'activeTab';
    const XML_ATTRIBUTE_R_ID = 'r:id';
    const XML_ATTRIBUTE_NAME = 'name';
    const XML_ATTRIBUTE_ID = 'Id';
    const XML_ATTRIBUTE_TARGET = 'Target';

    /** @var string Path of the XLSX file being read */
    protected $filePath;

    /** @var \Box\Spout\Common\Manager\OptionsManagerInterface Reader's options manager */
    protected $optionsManager;

    /** @var \Box\Spout\Reader\XLSX\Manager\SharedStringsManager Manages shared strings */
    protected $sharedStringsManager;

    /** @var \Box\Spout\Common\Helper\GlobalFunctionsHelper Helper to work with global functions */
    protected $globalFunctionsHelper;

    /** @var EntityFactory Factory to create entities */
    protected $entityFactory;

    /** @var \Box\Spout\Common\Helper\Escaper\XLSX Used to unescape XML data */
    protected $escaper;

    /**
     * @param string $filePath Path of the XLSX file being read
     * @param \Box\Spout\Common\Manager\OptionsManagerInterface $optionsManager Reader's options manager
     * @param \Box\Spout\Reader\XLSX\Manager\SharedStringsManager $sharedStringsManager Manages shared strings
     * @param \Box\Spout\Common\Helper\Escaper\XLSX $escaper Used to unescape XML data
     * @param EntityFactory $entityFactory Factory to create entities
     */
    public function __construct($filePath, $optionsManager, $sharedStringsManager, $escaper, $entityFactory)
    {
        $this->filePath = $filePath;
        $this->optionsManager = $optionsManager;
        $this->sharedStringsManager = $sharedStringsManager;
        $this->escaper = $escaper;
        $this->entityFactory = $entityFactory;
    }

    /**
     * Returns the sheets metadata of the file located at the previously given file path.
     * The paths to the sheets' data are read from the [Content_Types].xml file.
     *
     * @return Sheet[] Sheets within the XLSX file
     */
    public function getSheets()
    {
        $sheets = [];
        $sheetIndex = 0;
        $activeSheetIndex = 0; // By default, the first sheet is active

        $xmlReader = $this->entityFactory->createXMLReader();

        if ($xmlReader->openFileInZip($this->filePath, self::WORKBOOK_XML_FILE_PATH)) {
            while ($xmlReader->read()) {
                if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_WORKBOOK_VIEW)) {
                    // The "workbookView" node is located before "sheet" nodes, ensuring that
                    // the active sheet is known before parsing sheets data.
                    $activeSheetIndex = (int) $xmlReader->getAttribute(self::XML_ATTRIBUTE_ACTIVE_TAB);
                } else if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_SHEET)) {
                    $isSheetActive = ($sheetIndex === $activeSheetIndex);
                    $sheets[] = $this->getSheetFromSheetXMLNode($xmlReader, $sheetIndex, $isSheetActive);
                    $sheetIndex++;
                } else if ($xmlReader->isPositionedOnEndingNode(self::XML_NODE_SHEETS)) {
                    // stop reading once all sheets have been read
                    break;
                }
            }

            $xmlReader->close();
        }

        return $sheets;
    }

    /**
     * Returns an instance of a sheet, given the XML node describing the sheet - from "workbook.xml".
     * We can find the XML file path describing the sheet inside "workbook.xml.res", by mapping with the sheet ID
     * ("r:id" in "workbook.xml", "Id" in "workbook.xml.res").
     *
     * @param \Box\Spout\Reader\Wrapper\XMLReader $xmlReaderOnSheetNode XML Reader instance, pointing on the node describing the sheet, as defined in "workbook.xml"
     * @param int $sheetIndexZeroBased Index of the sheet, based on order of appearance in the workbook (zero-based)
     * @param bool $isSheetActive Whether this sheet was defined as active
     * @return \Box\Spout\Reader\XLSX\Sheet Sheet instance
     */
    protected function getSheetFromSheetXMLNode($xmlReaderOnSheetNode, $sheetIndexZeroBased, $isSheetActive)
    {
        $sheetId = $xmlReaderOnSheetNode->getAttribute(self::XML_ATTRIBUTE_R_ID);
        $escapedSheetName = $xmlReaderOnSheetNode->getAttribute(self::XML_ATTRIBUTE_NAME);
        $sheetName = $this->escaper->unescape($escapedSheetName);

        $sheetDataXMLFilePath = $this->getSheetDataXMLFilePathForSheetId($sheetId);

        return $this->entityFactory->createSheet(
            $this->filePath,
            $sheetDataXMLFilePath,
            $sheetIndexZeroBased,
            $sheetName,
            $isSheetActive,
            $this->optionsManager,
            $this->sharedStringsManager
        );
    }

    /**
     * @param string $sheetId The sheet ID, as defined in "workbook.xml"
     * @return string The XML file path describing the sheet inside "workbook.xml.res", for the given sheet ID
     */
    protected function getSheetDataXMLFilePathForSheetId($sheetId)
    {
        $sheetDataXMLFilePath = '';

        // find the file path of the sheet, by looking at the "workbook.xml.res" file
        $xmlReader = $this->entityFactory->createXMLReader();
        if ($xmlReader->openFileInZip($this->filePath, self::WORKBOOK_XML_RELS_FILE_PATH)) {
            while ($xmlReader->read()) {
                if ($xmlReader->isPositionedOnStartingNode(self::XML_NODE_RELATIONSHIP)) {
                    $relationshipSheetId = $xmlReader->getAttribute(self::XML_ATTRIBUTE_ID);

                    if ($relationshipSheetId === $sheetId) {
                        // In workbook.xml.rels, it is only "worksheets/sheet1.xml"
                        // In [Content_Types].xml, the path is "/xl/worksheets/sheet1.xml"
                        $sheetDataXMLFilePath = $xmlReader->getAttribute(self::XML_ATTRIBUTE_TARGET);

                        // sometimes, the sheet data file path already contains "/xl/"...
                        if (strpos($sheetDataXMLFilePath, '/xl/') !== 0) {
                            $sheetDataXMLFilePath = '/xl/' . $sheetDataXMLFilePath;
                            break;
                        }
                    }
                }
            }

            $xmlReader->close();
        }

        return $sheetDataXMLFilePath;
    }
}
