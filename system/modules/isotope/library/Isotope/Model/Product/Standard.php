<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2012 Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://www.isotopeecommerce.com
 * @license    http://opensource.org/licenses/lgpl-3.0.html LGPL
 */

namespace Isotope\Model\Product;

use Isotope\Isotope;
use Isotope\Interfaces\IsotopeAttribute;
use Isotope\Interfaces\IsotopeProduct;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Model\Gallery;
use Isotope\Model\Product;
use Isotope\Model\ProductPrice;
use Isotope\Model\TaxClass;


/**
 * Class Product
 *
 * Provide methods to handle Isotope products.
 * @copyright  Isotope eCommerce Workgroup 2009-2012
 * @author     Andreas Schempp <andreas.schempp@terminal42.ch>
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Christian de la Haye <service@delahaye.de>
 */
class Standard extends Product implements IsotopeProduct
{

    /**
     * Price model for the current product
     * @var Isotope\Model\ProductPrice
     */
    protected $objPrice;


    /**
     * Cached product data
     * @var array
     */
    protected $arrCache = array();

    /**
     * Attributes assigned to this product type
     * @var array
     */
    protected $arrAttributes = array();

    /**
     * Variant attributes assigned to this product type
     * @var array
     */
    protected $arrVariantAttributes = array();

    /**
     * Product Options
     * @var array
     */
    protected $arrOptions = array();

    /**
     * Product Options of all variants
     * @var array
     */
    protected $arrVariantOptions = null;

    /**
     * Downloads for this product
     * @var array
     */
    protected $arrDownloads = null;

    /**
     * Unique form ID
     * @var string
     */
    protected $formSubmit = 'iso_product';

    /**
     * For option widgets, helps determine the encoding type for a form
     * @var boolean
     */
    protected $hasUpload = false;

    /**
     * For option widgets, don't submit if certain validation(s) fail
     * @var boolean
     */
    protected $doNotSubmit = false;


    /**
     * Construct the object
     * @param   array
     * @param   array
     * @param   boolean
     */
    public function __construct(\Database\Result $objResult=null)
    {
        parent::__construct($objResult);

        $this->Database = \Database::getInstance();

        $arrData = $this->arrData;

        if ($arrData['pid'] > 0)
        {
            $objParent = static::findByPk($arrData['id']);

            if (null === $objParent) {
                throw new \UnderflowException('Parent record of product ID ' . $arrData['id'] . ' not found');
            }

            $this->arrData = $objParent->row();
        }

        $this->arrOptions = is_array($arrOptions) ? $arrOptions : array();

        if (!$this->arrData['type'])
        {
            return;
        }

        $this->formSubmit = 'iso_product_' . $this->arrData['id'];
        $this->arrAttributes = $this->getSortedAttributes($this->getRelated('type')->attributes);
        $this->arrVariantAttributes = $this->hasVariants() ? $this->getSortedAttributes($this->getRelated('type')->variant_attributes) : array();
        $this->arrCache['quantity_requested'] = $intQuantity;

        // !HOOK: allow to customize attributes
        if (isset($GLOBALS['ISO_HOOKS']['productAttributes']) && is_array($GLOBALS['ISO_HOOKS']['productAttributes']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['productAttributes'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $objCallback->$callback[1]($this->arrAttributes, $this->arrVariantAttributes, $this);
            }
        }

        // Remove attributes not in this product type
        foreach ($this->arrData as $attribute => $value)
        {
            if (!in_array($attribute, $this->arrAttributes) && !in_array($attribute, $this->arrVariantAttributes) && $GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['legend'] != '')
            {
                unset($this->arrData[$attribute]);
            }
        }

        if ($arrData['pid'] > 0)
        {
            $this->loadVariantData($arrData);
        }
    }


    /**
     * Get a property
     * @param   string
     * @return  mixed
     */
    public function __get($strKey)
    {
        switch ($strKey)
        {
            case 'id':
            case 'pid':
            case 'href_reader':
                return $this->arrData[$strKey];

            case 'formSubmit':
                return $this->formSubmit;

            case 'price':
                return Isotope::calculatePrice($this->arrData['price'], $this, 'price', $this->arrData['tax_class']);

            case 'total_price':
                $varPrice = $this->price;

                return $varPrice === null ? null : ($this->quantity_requested * $varPrice);

            case 'tax_free_price':
                $objTaxClass = TaxClass::findByPk($this->arrData['tax_class']);
                $fltPrice = $objTaxClass === null ? $this->arrData['price'] : $objTaxClass->calculateNetPrice($this->arrData['price']);

                return Isotope::calculatePrice($fltPrice, $this, 'tax_free_price', $this->arrData['tax_class']);

            case 'tax_free_total_price':
                $varPrice = $this->tax_free_price;

                return $varPrice === null ? null : ($this->quantity_requested * $varPrice);

            case 'quantity_requested':
                if (!$this->arrCache[$strKey] && \Input::post('FORM_SUBMIT') == $this->formSubmit)
                {
                    $this->arrCache[$strKey] = (int) \Input::post('quantity_requested');
                }

                return $this->arrCache[$strKey] ? $this->arrCache[$strKey] : 1;

            case 'description_meta':
                return $this->arrData['description_meta'] != '' ? $this->arrData['description_meta'] : ($this->arrData['teaser'] != '' ? $this->arrData['teaser'] : $this->arrData['description']);

            default:
                // Initialize attribute
                if (!isset($this->arrCache[$strKey]))
                {
                    switch ($strKey)
                    {
                        case 'categories':
                            $this->arrCache[$strKey] = $this->Database->execute("SELECT page_id FROM tl_iso_product_categories WHERE pid=" . ($this->pid ? $this->pid : $this->id) . " ORDER BY sorting")->fetchEach('page_id');
                            break;

                        default:
                            if ($this->pid > 0 && $GLOBALS['TL_DCA']['tl_iso_products']['fields'][$strKey]['attributes']['customer_defined'] || $GLOBALS['TL_DCA']['tl_iso_products']['fields'][$strKey]['attributes']['variant_option']) {

							    return isset($this->arrOptions[$strKey]) ? deserialize($this->arrOptions[$strKey]) : null;
						    }

                            return isset($this->arrData[$strKey]) ? deserialize($this->arrData[$strKey]) : null;
                    }
                }

                return $this->arrCache[$strKey];
        }
    }


    /**
     * Set a property
     * @param   string
     * @param   mixed
     */
    public function __set($strKey, $varValue)
    {
        switch ($strKey)
        {
            case 'reader_jumpTo':

                // Remove the target URL if no page ID is given
                if ($varValue == '' || $varValue < 1)
                {
                    $this->arrData['href_reader'] = '';
                    break;
                }

                global $objPage;
                $strUrlKey = $this->arrData['alias'] ? $this->arrData['alias'] : ($this->arrData['pid'] ? $this->arrData['pid'] : $this->arrData['id']);

                // make sure the page object is loaded because of the url language feature (e.g. when rebuilding the search index in the back end or ajax actions)
                if (!$objPage)
                {
                    $objTargetPage = $this->getPageDetails($varValue);

                    if ($objTargetPage === null)
                    {
                        $this->arrData['href_reader'] = '';
                        break;
                    }

                    $strUrl = \Controller::generateFrontendUrl($objTargetPage->row(), ($GLOBALS['TL_CONFIG']['useAutoItem'] ? '/' : '/product/') . $strUrlKey, $objTargetPage->rootLanguage);
                }
                else
                {
                    $strUrl = \Controller::generateFrontendUrl(\Database::getInstance()->prepare("SELECT * FROM tl_page WHERE id=?")->execute($varValue)->fetchAssoc(), ($GLOBALS['TL_CONFIG']['useAutoItem'] ? '/' : '/product/') . $strUrlKey, $objPage->rootLanguage);
                }

                if (!empty($this->arrOptions))
                {
                    $arrOptions = array();

                    foreach ($this->arrOptions as $k => $v)
                    {
                        $arrOptions[] = $k . '=' . urlencode($v);
                    }

                    $strUrl .= (strpos('?', $strUrl) === false ? '?' : '&amp;') . implode('&amp;', $arrOptions);
                }

                $this->arrData['href_reader'] = $strUrl;
                break;

            case 'reader_jumpTo_Override':
                $this->arrData['href_reader'] = $varValue;
                break;

            case 'sku':
            case 'name':
            case 'price':
                $this->arrData[$strKey] = $varValue;
                break;

            case 'quantity_requested':
                $this->arrCache[$strKey] = $varValue;
                break;

            default:
                $this->arrCache[$strKey] = $varValue;
        }
    }

    /**
     * Returns true if the product is published, otherwise returns false
     * @return  bool
     */
    public function isPublished()
    {
        if (!$this->arrData['published'])
        {
            return false;
        }
        elseif ($this->arrData['start'] > 0 && $this->arrData['start'] > time())
        {
            return false;
        }
        elseif ($this->arrData['stop'] > 0 && $this->arrData['stop'] < time())
        {
            return false;
        }

        return true;
    }

    /**
     * Returns true if the product is available to show on the website
     * @return  bool
     */
    public function isAvailableInFrontend()
    {
        if (BE_USER_LOGGED_IN !== true && !$this->isPublished()) {
            return false;
        }

        // Show to guests only
        if ($this->arrData['guests'] && FE_USER_LOGGED_IN === true && BE_USER_LOGGED_IN !== true && !$this->arrData['protected']) {
            return false;
        }

        // Protected product
        if (BE_USER_LOGGED_IN !== true && $this->arrData['protected']) {
            if (FE_USER_LOGGED_IN !== true) {
                return false;
            }

            $groups = deserialize($this->arrData['groups']);

            if (!is_array($groups) || empty($groups) || !count(array_intersect($groups, FrontendUser::getInstance()->groups))) {
                return false;
            }
        }

        // Check that the product is in any page of the current site
        if (count(\Isotope\Frontend::getPagesInCurrentRoot($this->categories, \FrontendUser::getInstance())) == 0) {
            return false;
        }

        // Check if "advanced price" is available
        if ($this->arrData['price'] === null && (in_array('price', $this->arrAttributes) || in_array('price', $this->arrVariantAttributes))) {
            return false;
        }

        return true;
    }

    /**
     * Returns true if the product is available
     * @return  bool
     */
    public function isAvailableForCollection(IsotopeProductCollection $objCollection)
    {
        if ($objCollection->isLocked()) {
            return true;
        }

        if (BE_USER_LOGGED_IN !== true && !$this->isPublished()) {
            return false;
        }

        // Show to guests only
        if ($this->arrData['guests'] && $objCollection->member > 0 && BE_USER_LOGGED_IN !== true && !$this->arrData['protected']) {
            return false;
        }

        // Protected product
        if (BE_USER_LOGGED_IN !== true && $this->arrData['protected']) {
            if ($objCollection->member == 0) {
                return false;
            }

            $groups = deserialize($this->arrData['groups']);
            $memberGroups = deserialize($objCollection->getRelated('member')->groups);

            if (!is_array($groups) || empty($groups) || !is_array($memberGroups) || empty($memberGroups) || !count(array_intersect($groups, $memberGroups))) {
                return false;
            }
        }

        // Check that the product is in any page of the current site
        if (count(\Isotope\Frontend::getPagesInCurrentRoot($this->categories, $objCollection->getRelated('member'))) == 0) {
            return false;
        }

        return true;
    }

    /**
     * Checks whether a product is new according to the current store config
     * @return  boolean
     */
    public function isNew()
    {
        return $this->dateAdded >= Isotope::getConfig()->getNewProductLimit();
    }

    /**
     * Return true if the product or product type has shipping exempt activated
     * @return  bool
     */
    public function isExemptFromShipping()
    {
        return ($this->arrData['shipping_exempt'] || $this->getRelated('type')->shipping_exempt) ? true : false;
    }

    /**
     * Returns true if variants are enabled in the product type, otherwise returns false
     * @return  bool
     */
    public function hasVariants()
    {
        return (bool) $this->getRelated('type')->variants;
    }

    /**
     * Returns true if product has variants, and the price is a variant attribute
     * @return  bool
     */
    public function hasVariantPrices()
    {
        if ($this->hasVariants() && in_array('price', $this->arrVariantAttributes))
        {
            return true;
        }

        return false;
    }

    /**
     * Returns true if advanced prices are enabled in the product type, otherwise returns false
     * @return  bool
     */
    public function hasAdvancedPrices()
    {
        return (bool) $this->getRelated('type')->prices;
    }

    /**
     * Return true if the user should see lowest price tier as lowest price
     * @return  bool
     */
    public function canSeePriceTiers()
    {
        return $this->hasAdvancedPrices() && $this->getRelated('type')->show_price_tiers;
    }

    /**
     * Get product price model
     * @return  IsotopePrice
     */
    public function getPrice()
    {
        if (null === $this->objPrice) {
            $this->objPrice = ProductPrice::findForProduct($this);
        }

        return $this->objPrice;
    }

    /**
     * Return minimum quantity for the product (from advanced price tiers)
     * @return  int
     */
    public function getMinimumQuantity()
    {
        // Minimum quantity is only available for advanced pricing
        if (!$this->hasAdvancedPrices()) {
            return 1;
        }

        $this->getPrice()->getLowestTier();
    }


    /**
     * Return the product attributes
     * @return  array
     */
    public function getProductAttributes()
    {
        return $this->arrAttributes;
    }


    /**
     * Return the product variant attributes
     * @return  array
     */
    public function getVariantAttributes()
    {
        return $this->arrVariantAttributes;
    }


    /**
     * Return variant options data
     * @return  array|false
     */
    public function getVariantOptions()
    {
        if (!$this->hasVariants()) {
            return false;
        }

        if (!is_array($this->arrVariantOptions)) {

            $time = time();
            $this->arrVariantOptions = array('current'=>array());

            // Find all possible variant options
            $objVariant = clone $this;
            $objVariants = static::findPublishedByPid($arrData['id']);

            if (null !== $objVariants) {
                while ($objVariants->next()) {

                    $objVariant->loadVariantData($objVariants->row(), false);

                    if ($objVariant->isAvailableInFrontend()) {
                        $arrVariantOptions = $objVariant->getOptions();

                        $this->arrVariantOptions['ids'][] = $objVariant->id;
                        $this->arrVariantOptions['options'][$objVariant->id] = $arrVariantOptions;
                        $this->arrVariantOptions['variants'][$objVariant->id] = $objVariants->row();

                        foreach ($arrVariantOptions as $attribute => $value) {
                            if (!in_array((string) $value, (array) $this->arrVariantOptions['attributes'][$attribute], true)) {
                                $this->arrVariantOptions['attributes'][$attribute][] = (string) $value;
                            }
                        }
                    }
                }
            }
        }

        return $this->arrVariantOptions;
    }


    /**
     * Return all available variant IDs of this product
     * @return  array|false
     */
    public function getVariantIds()
    {
        $arrVariantOptions = $this->getVariantOptions();

        if ($arrVariantOptions === false)
        {
            return false;
        }

        return (array) $arrVariantOptions['ids'];
    }


    /**
     * Return all downloads for this product
     * @todo    Confirm that files are available
     * @return  array
     */
    public function getDownloads()
    {
        if (!$this->getRelated('type')->downloads)
        {
            $this->arrDownloads = array();
        }

        // Cache downloads for this product
        elseif (!is_array($this->arrDownloads))
        {
            $this->arrDownloads = $this->Database->execute("SELECT * FROM tl_iso_downloads WHERE pid={$this->arrData['id']} OR pid={$this->arrData['pid']}")->fetchAllAssoc();
        }

        return $this->arrDownloads;
    }


    /**
     * Return all product options
     * @return  array
     */
    public function getOptions()
    {
        return $this->arrOptions;
    }


    /**
     * Set options data
     * @param   array
     */
    public function setOptions(array $arrOptions)
    {
        $this->arrOptions = $arrOptions;
    }


    /**
     * Check if a product has downloads
     * @todo    Confirm that files are available
     * @return  array
     */
    public function hasDownloads()
    {
        // Cache downloads if not yet done
        $this->getDownloads();

        return !empty($this->arrDownloads);
    }


    /**
     * Generate a product template
     * @param   array
     * @return  string
     */
    public function generate(array $arrConfig)
    {
        $this->formSubmit = (($arrConfig['module'] instanceof \ContentElement) ? 'cte' : 'fmd') . $arrConfig['module']->id . '_product_' . ($this->pid ? $this->pid : $this->id);
        $this->validateVariant();

        $objProduct = $this;
        $arrGalleries = array();

        $objTemplate = new \Isotope\Template($arrConfig['template']);
        $objTemplate->setData($this->arrData);
        $objTemplate->product = $this;
        $objTemplate->config = $arrConfig;

        $objTemplate->generateAttribute = function($strAttribute) use ($objProduct) {

            $objAttribute = $GLOBALS['TL_DCA']['tl_iso_products']['attributes'][$strAttribute];

            if (!($objAttribute instanceof IsotopeAttribute)) {
                throw new \InvalidArgumentException($strAttribute . ' is not a valid attribute');
            }

            return $objAttribute->generate($objProduct);
        };

        $objTemplate->getGallery = function($strAttribute) use ($objProduct, $arrConfig, &$arrGalleries) {

            if (!isset($arrGalleries[$strAttribute])) {
                $arrGalleries[$strAttribute] = Gallery::createForProductAttribute($arrConfig['gallery'], $objProduct, $strAttribute);
            }

            return $arrGalleries[$strAttribute];
        };


        if ($this->arrCache['from_price'] !== null)
        {
            $fltPrice = Isotope::calculatePrice($this->arrCache['from_price'], $this, 'price', $this->arrData['tax_class']);
            $fltOriginalPrice = Isotope::calculatePrice($this->arrCache['from_price'], $this, 'original_price', $this->arrData['tax_class']);

            $strPrice = sprintf($GLOBALS['TL_LANG']['MSC']['priceRangeLabel'], Isotope::formatPriceWithCurrency($fltPrice));
            $strOriginalPrice = sprintf($GLOBALS['TL_LANG']['MSC']['priceRangeLabel'], Isotope::formatPriceWithCurrency($fltOriginalPrice));
        }
        else
        {
        // Add price to template
        $strPrice = '';
        $fltPrice = $this->getPrice()->getAmount();
        $fltOriginalPrice = $this->getPrice()->getOriginalAmount();

            $strPrice = Isotope::formatPriceWithCurrency($fltPrice);
            $strOriginalPrice = Isotope::formatPriceWithCurrency($fltOriginalPrice);
        }

        if ($fltPrice != $fltOriginalPrice)
        {
            $strPrice = '<div class="original_price"><strike>' . $strOriginalPrice . '</strike></div><div class="price">' . $strPrice . '</div>';
        }

        $objTemplate->price = $strPrice;

        $arrProductOptions = array();
        $arrAjaxOptions = array();

        foreach (array_unique(array_merge($this->arrAttributes, $this->arrVariantAttributes)) as $attribute)
        {
            $arrData = $GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute];

            if ($arrData['attributes']['customer_defined'] || $arrData['attributes']['variant_option']) {

                $strWidget = $this->generateProductOptionWidget($attribute);

                if ($strWidget != '')
                {
                    $objTemplate->hasOptions = true;
                    $arrProductOptions[$attribute] = array_merge($arrData, array
                    (
                        'name'    => $attribute,
                        'html'    => $strWidget,
                    ));

                    if ($arrData['attributes']['variant_option'] || $arrData['attributes']['ajax_option']) {
                        $arrAjaxOptions[] = $attribute;
                    }
                }

            }
        }

        $arrButtons = array();

        // !HOOK: retrieve buttons
        if (isset($GLOBALS['ISO_HOOKS']['buttons']) && is_array($GLOBALS['ISO_HOOKS']['buttons']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['buttons'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $arrButtons = $objCallback->$callback[1]($arrButtons);
            }

            $arrButtons = array_intersect_key($arrButtons, array_flip($arrConfig['buttons']));
        }

        if (\Input::post('FORM_SUBMIT') == $this->formSubmit && !$this->doNotSubmit)
        {
            foreach ($arrButtons as $button => $data)
            {
                if (\Input::post($button) != '')
                {
                    if (isset($data['callback']))
                    {
                        $objCallback = \System::importStatic($data['callback'][0]);
                        $objCallback->{$data['callback'][1]}($this, $arrConfig);
                    }
                    break;
                }
            }
        }

        $objTemplate->buttons = $arrButtons;
        $objTemplate->quantityLabel = $GLOBALS['TL_LANG']['MSC']['quantity'];
        $objTemplate->useQuantity = $arrConfig['useQuantity'];
        $objTemplate->quantity_requested = $this->quantity_requested;
        $objTemplate->minimum_quantity = $this->getMinimumQuantity();
        $objTemplate->raw = array_merge($this->arrData, $this->arrCache);
        $objTemplate->raw_options = $this->arrOptions;
        $objTemplate->href_reader = $this->href_reader;
        $objTemplate->label_detail = $GLOBALS['TL_LANG']['MSC']['detailLabel'];
        $objTemplate->options = \Isotope\Frontend::generateRowClass($arrProductOptions, 'product_option');
        $objTemplate->hasOptions = !empty($arrProductOptions);
        $objTemplate->enctype = $this->hasUpload ? 'multipart/form-data' : 'application/x-www-form-urlencoded';
        $objTemplate->formId = $this->formSubmit;
        $objTemplate->action = ampersand(\Environment::get('request'), true);
        $objTemplate->formSubmit = $this->formSubmit;
        $objTemplate->product_id = ($this->pid ? $this->pid : $this->id);
        $objTemplate->module_id = $arrConfig['module']->id;

        $GLOBALS['AJAX_PRODUCTS'][] = array('formId'=>$this->formSubmit, 'attributes'=>$arrAjaxOptions);

        // !HOOK: alter product data before output
        if (isset($GLOBALS['ISO_HOOKS']['generateProduct']) && is_array($GLOBALS['ISO_HOOKS']['generateProduct']))
        {
            foreach ($GLOBALS['ISO_HOOKS']['generateProduct'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $objCallback->$callback[1]($objTemplate, $this);
            }
        }

        return $objTemplate->parse();
    }


    /**
     * Return a widget object based on a product attribute's properties
     * @param   string
     * @param   boolean
     * @return  string
     */
    protected function generateProductOptionWidget($strField)
    {
        $objAttribute = $GLOBALS['TL_DCA']['tl_iso_products']['attributes'][$strField];
        $arrData = $GLOBALS['TL_DCA']['tl_iso_products']['fields'][$strField];

        $strClass = $objAttribute->getFrontendWidget();

        $arrData['eval']['mandatory'] = ($arrData['eval']['mandatory'] && !\Environment::get('isAjaxRequest')) ? true : false;
        $arrData['eval']['required'] = $arrData['eval']['mandatory'];

        // Make sure variant options are initialized
        $this->getVariantOptions();

        if ($objAttribute->isVariantOption() && is_array($arrData['options']))
        {
            if ((count((array) $this->arrVariantOptions['attributes'][$strField]) == 1) && !$this->getRelated('type')->force_variant_options)
            {
                $this->arrOptions[$strField] = $this->arrVariantOptions['attributes'][$strField][0];
                $this->arrVariantOptions['current'][$strField] = $this->arrVariantOptions['attributes'][$strField][0];
                $arrData['default'] = $this->arrVariantOptions['attributes'][$strField][0];

                if (!\Environment::get('isAjaxRequest'))
                {
                    return '';
                }
            }

            if ($arrData['inputType'] == 'select')
            {
                $arrData['eval']['includeBlankOption'] = true;
            }

            $arrField = $strClass::getAttributesFromDca($arrData, $strField, $arrData['default']);

            // Necessary, because prepareForData can unset the options
            if (is_array($arrData['options']))
            {
                // Unset if no variant has this option
                foreach ($arrField['options'] as $k => $option)
                {
                    // Keep groups and blankOptionLabels
                    if (!$option['group'] && $option['value'] != '')
                    {
                        // Unset option if no attribute has this option at all (in any enabled variant)
                        if (!in_array((string) $option['value'], (array) $this->arrVariantOptions['attributes'][$strField], true))
                        {
                            unset($arrField['options'][$k]);
                        }

                        // Check each variant if it is found trough the url
                        else
                        {
                            $blnValid = false;

                            foreach ((array) $this->arrVariantOptions['options'] as $arrVariant)
                            {
                                if ($arrVariant[$strField] == $option['value'] && count($this->arrVariantOptions['current']) == count(array_intersect_assoc($this->arrVariantOptions['current'], $arrVariant)))
                                {
                                    $blnValid = true;
                                }
                            }

                            if (!$blnValid)
                            {
                                unset($arrField['options'][$k]);
                            }
                        }
                    }
                }
            }

            $arrField['options'] = array_values($arrField['options']);

            if (\Input::get($strField) != '' && \Input::post('FORM_SUBMIT') != $this->formSubmit)
            {
                if (in_array(\Input::get($strField), (array) $this->arrVariantOptions['attributes'][$strField], true))
                {
                    $arrField['value'] = \Input::get($strField);
                    $this->arrVariantOptions['current'][$strField] = \Input::get($strField);
                }
            }
            elseif ($this->pid > 0)
            {
                $arrField['value'] = $this->arrOptions[$strField];
                $this->arrVariantOptions['current'][$strField] = $this->arrOptions[$strField];
            }
        }
        else
        {
            if (is_array($GLOBALS['ISO_ATTR'][$arrData['attributes']['type']]['callback']) && !empty($GLOBALS['ISO_ATTR'][$arrData['attributes']['type']]['callback']))
            {
                foreach ($GLOBALS['ISO_ATTR'][$arrData['attributes']['type']]['callback'] as $callback)
                {
                    $objCallback = \System::importStatic($callback[0]);
                    $arrData = $objCallback->{$callback[1]}($strField, $arrData, $this);
                }
            }

            $arrField = $strClass::getAttributesFromDca($arrData, $strField, $arrData['default']);
        }

        $objWidget = new $strClass($arrField);

        $objWidget->storeValues = true;
        $objWidget->tableless = true;
        $objWidget->id .= "_" . $this->formSubmit;

        // Validate input
        if (\Input::post('FORM_SUBMIT') == $this->formSubmit)
        {
            $objWidget->validate();

            if ($objWidget->hasErrors())
            {
                $this->doNotSubmit = true;
            }

            // Store current value
            elseif ($objWidget->submitInput() || $objWidget instanceof \uploadable)
            {
                $varValue = $objWidget->value;

                // Convert date formats into timestamps
                if ($varValue != '' && in_array($arrData['eval']['rgxp'], array('date', 'time', 'datim')))
                {
                    $objDate = new \Date($varValue, $GLOBALS['TL_CONFIG'][$arrData['eval']['rgxp'] . 'Format']);
                    $varValue = $objDate->tstamp;
                }

                // Trigger the save_callback
                if (is_array($arrData['save_callback']))
                {
                    foreach ($arrData['save_callback'] as $callback)
                    {
                        $objCallback = \System::importStatic($callback[0]);

                        try {
                            $varValue = $objCallback->$callback[1]($varValue, $this, $objWidget);
                        } catch (\Exception $e) {
                            $objWidget->class = 'error';
                            $objWidget->addError($e->getMessage());
                            $this->doNotSubmit = true;
                        }
                    }
                }

                if (!$objWidget->hasErrors())
                {
                    $this->arrOptions[$strField] = $varValue;

                    if ($arrData['attributes']['variant_option'] && $varValue != '')
                    {
                        $this->arrVariantOptions['current'][$strField] = $varValue;
                    }
                }
            }
        }

        $wizard = '';

        // Datepicker
        if ($arrData['eval']['datepicker'])
        {
            $GLOBALS['TL_JAVASCRIPT'][] = 'plugins/datepicker/datepicker.js';
            $GLOBALS['TL_CSS'][] = 'plugins/datepicker/dashboard.css';

            $rgxp = $arrData['eval']['rgxp'];
            $format = Date::formatToJs($GLOBALS['TL_CONFIG'][$rgxp.'Format']);

            switch ($rgxp)
            {
                case 'datim':
                    $time = ",\n      timePicker:true";
                    break;

                case 'time':
                    $time = ",\n      pickOnly:\"time\"";
                    break;

                default:
                    $time = '';
                    break;
            }

            $wizard .= ' <img src="plugins/datepicker/icon.gif" width="20" height="20" alt="" id="toggle_' . $objWidget->id . '" style="vertical-align:-6px">
  <script>
  window.addEvent("domready", function() {
    new Picker.Date($$("#ctrl_' . $objWidget->id . '"), {
      draggable:false,
      toggle:$$("#toggle_' . $objWidget->id . '"),
      format:"' . $format . '",
      positionOffset:{x:-197,y:-182}' . $time . ',
      pickerClass:"datepicker_dashboard",
      useFadeInOut:!Browser.ie,
      startDay:' . $GLOBALS['TL_LANG']['MSC']['weekOffset'] . ',
      titleFormat:"' . $GLOBALS['TL_LANG']['MSC']['titleFormat'] . '"
    });
  });
  </script>';
        }

        // Add a custom wizard
        if (is_array($arrData['wizard']))
        {
            foreach ($arrData['wizard'] as $callback)
            {
                $objCallback = \System::importStatic($callback[0]);
                $wizard .= $objCallback->$callback[1]($this);
            }
        }

        if ($objWidget instanceof \uploadable)
        {
            $this->hasUpload = true;
        }

        return $objWidget->parse() . $wizard;
    }


    /**
     * Load data of a product variant if the options match one
     */
    protected function validateVariant()
    {
        if (!$this->hasVariants())
        {
            return;
        }

        // Make sure variant options are initialized
        $this->getVariantOptions();

        $arrOptions = array();

        foreach ($this->arrAttributes as $attribute)
        {
            if ($GLOBALS['TL_DCA']['tl_iso_products']['fields'][$attribute]['attributes']['variant_option'])
            {
                if (\Input::post('FORM_SUBMIT') == $this->formSubmit && in_array(\Input::post($attribute), (array) $this->arrVariantOptions['attributes'][$attribute], true))
                {
                    $arrOptions[$attribute] = \Input::post($attribute);
                }
                elseif (\Input::post('FORM_SUBMIT') == '' && in_array(\Input::get($attribute), (array) $this->arrVariantOptions['attributes'][$attribute], true))
                {
                    $arrOptions[$attribute] = \Input::get($attribute);
                }
                elseif (count((array) $this->arrVariantOptions['attributes'][$attribute]) == 1)
                {
                    $arrOptions[$attribute] = $this->arrVariantOptions['attributes'][$attribute][0];
                }
            }
        }

        $intOptions = count($arrOptions);

        if ($intOptions > 0)
        {
            $intVariant = false;

            foreach ((array) $this->arrVariantOptions['options'] as $id => $arrVariant)
            {
                if ($intOptions == count($arrVariant) && $intOptions == count(array_intersect_assoc($arrOptions, $arrVariant)))
                {
                    if ($intVariant === false)
                    {
                        $intVariant = $id;
                    }
                    else
                    {
                        $this->doNotSubmit = true;

                        return;
                    }
                }
            }

            // Variant not found
            if ($intVariant === false || !is_array($this->arrVariantOptions['variants'][$intVariant]))
            {
                $this->doNotSubmit = true;

                return;
            }

            // Variant already loaded
            if ($intVariant == $this->id)
            {
                return;
            }

            $this->loadVariantData($this->arrVariantOptions['variants'][$intVariant]);
        }
    }


    /**
     * Load variant data basing on provided data
     * @param   array
     * @param   array
     */
    public function loadVariantData($arrData, $arrInherit=false)
    {
        $arrInherit = deserialize($arrData['inherit'], true);

        $this->arrData['id'] = $arrData['id'];
        $this->arrData['pid'] = $arrData['pid'];

        foreach ($this->arrVariantAttributes as $attribute)
        {
            if (in_array($attribute, $arrInherit))
            {
                continue;
            }

            $this->arrData[$attribute] = $arrData[$attribute];

            if (in_array($attribute, $GLOBALS['ISO_CONFIG']['fetch_fallback']))
            {
                $this->arrData[$attribute.'_fallback'] = $arrData[$attribute.'_fallback'];
            }

            if (is_array($this->arrCache) && isset($this->arrCache[$attribute]))
            {
                unset($this->arrCache[$attribute]);
            }
        }

        // Load variant options
        $this->arrOptions = array_merge($this->arrOptions, array_intersect_key($arrData, array_flip(array_intersect($this->arrAttributes, $GLOBALS['ISO_CONFIG']['variant_options']))));

        // Unset arrDownloads cache
        $this->arrDownloads = null;
    }


    /**
     * Sort the attributes based on their position (from wizard) and return their names only
     * @param   mixed
     * @return  array
     */
    protected function getSortedAttributes($varValue)
    {
        $arrAttributes = deserialize($varValue, true);

        uasort($arrAttributes, function ($a, $b) {
            return $a["position"] > $b["position"];
        });

        return array_keys($arrAttributes);
    }
}
