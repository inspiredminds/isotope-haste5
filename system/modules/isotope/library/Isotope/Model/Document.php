<?php

/*
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009 - 2019 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @link       https://isotopeecommerce.org
 * @license    https://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Model;

use Codefog\HasteBundle\StringParser;
use Contao\StringUtil;
use Contao\System;
use Isotope\Interfaces\IsotopeProductCollection;

/**
 * Parent class for all documents.
 */
abstract class Document extends TypeAgent
{

    /**
     * Table name
     * @var string
     */
    protected static $strTable = 'tl_iso_document';

    /**
     * Interface to validate document
     * @var string
     */
    protected static $strInterface = '\Isotope\Interfaces\IsotopeDocument';

    /**
     * List of types (classes) for this model
     * @var array
     */
    protected static $arrModelTypes = array();


    /**
     * Prepares the collection tokens
     *
     * @param IsotopeProductCollection|\Contao\Model $objCollection
     *
     * @return array
     */
    protected function prepareCollectionTokens(IsotopeProductCollection $objCollection)
    {
        $arrTokens = array();

        foreach ($objCollection->row() as $k => $v) {
            $arrTokens['collection_' . $k] = $v;
        }

        return $arrTokens;
    }

    /**
     * Prepare file name
     *
     * @param string $strName   File name
     * @param array  $arrTokens Simple tokens (optional)
     * @param string $strPath   Path (optional)
     *
     * @return string Sanitized file name
     */
    protected function prepareFileName($strName, $arrTokens = array(), $strPath = '')
    {
        /** @var StringParser $stringParser */
        $stringParser = System::getContainer()->get(StringParser::class);

        // Replace simple tokens
        $strName = $this->sanitizeFileName(
            $stringParser->recursiveReplaceTokensAndTags(
                $strName,
                $arrTokens,
                StringParser::NO_TAGS | StringParser::NO_BREAKS | StringParser::NO_ENTITIES
            )
        );

        if ($strPath) {
            // Make sure the path contains a trailing slash
            $strPath = preg_replace('/([^\/]+)$/', '$1/', $strPath);

            $strName = $strPath . $strName;
        }

        return $strName;
    }

    /**
     * Sanitize file name
     *
     * @param string $strName              File name
     * @param bool   $blnPreserveUppercase Preserve uppercase (true by default)
     *
     * @return string Sanitized file name
     */
    protected function sanitizeFileName($strName, $blnPreserveUppercase = true)
    {
        return StringUtil::standardize(StringUtil::ampersand($strName, false), $blnPreserveUppercase);
    }
}
