<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2014 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://isotopeecommerce.org
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Backend;

class SubtableVersion extends \Backend
{
    /**
     * Create initial version record if it does not exist
     *
     * @param string $strTable
     * @param int    $intId
     * @param string $strSubtable
     * @param array  $arrData
     */
    public static function initialize($strTable, $intId, $strSubtable, $arrData)
    {
        $objVersion = \Database::getInstance()
            ->prepare('SELECT COUNT(*) AS count FROM tl_version WHERE fromTable=? AND pid=?')
            ->limit(1)
            ->execute($strSubtable, $intId)
        ;

        if ($objVersion->count < 1) {
            static::create($strTable, $intId, $strSubtable, $arrData);
        }
    }

    /**
     * Create a new subtable version record
     *
     * @param string $strTable
     * @param int    $intId
     * @param string $strSubtable
     * @param array  $arrData
     */
    public static function create($strTable, $intId, $strSubtable, $arrData)
    {
        $objVersion = \Database::getInstance()
            ->prepare('SELECT * FROM tl_version WHERE pid=? AND fromTable=? ORDER BY version DESC')
            ->limit(1)
            ->execute($intId, $strTable)
        ;

        // Parent table must have a version
        if ($objVersion->numRows == 0) {
            return;
        }

        \Database::getInstance()->prepare("UPDATE tl_version SET active='' WHERE pid=? AND fromTable=?")
                       ->execute($intId, $strSubtable);

        \Database::getInstance()
            ->prepare(/** @lang text */ 'INSERT INTO tl_version %s')
            ->set(
                [
                    'pid'         => $objVersion->pid,
                    'tstamp'      => $objVersion->tstamp,
                    'version'     => $objVersion->version,
                    'fromTable'   => $strSubtable,
                    'username'    => $objVersion->username,
                    'userid'      => $objVersion->userid,
                    'description' => $objVersion->description,
                    'editUrl'     => $objVersion->editUrl,
                    'active'      => '1',
                    'data'        => serialize($arrData),
                ]
            )
            ->execute()
        ;
    }

    /**
     * Find a subtable version record
     *
     * @param string $strTable
     * @param int    $intPid
     * @param string $intVersion
     *
     * @return array|null
     */
    public static function find($strTable, $intPid, $intVersion)
    {
        $objVersion = \Database::getInstance()
            ->prepare('SELECT data FROM tl_version WHERE fromTable=? AND pid=? AND version=?')
            ->limit(1)
            ->execute($strTable, $intPid, $intVersion)
        ;

        if (!$objVersion->numRows) {
            return null;
        }

        $arrData = deserialize($objVersion->data);

        if (!is_array($arrData)) {
            return null;
        }

        return $arrData;
    }
}