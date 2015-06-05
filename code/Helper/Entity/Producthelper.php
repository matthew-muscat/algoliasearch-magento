<?php

class Algolia_Algoliasearch_Helper_Entity_Producthelper extends Algolia_Algoliasearch_Helper_Entity_Helper
{
    private static $_productAttributes;
    private static $_activeCategories;
    private static $_categoryNames;

    private $_dataPrefix = 'algolia_';

    protected function getIndexNameSuffix()
    {
        return '_products';
    }

    public function getAllAttributes()
    {
        if (is_null(self::$_productAttributes))
        {
            self::$_productAttributes = array();

            /** @var $config Mage_Eav_Model_Config */
            $config = Mage::getSingleton('eav/config');

            $allAttributes = $config->getEntityAttributeCodes('catalog_product');

            $productAttributes = array_merge(array('name', 'path', 'categories', 'description', 'ordered_qty', 'stock_qty', 'price_with_tax'), $allAttributes);

            $excludedAttributes = array(
                'all_children', 'available_sort_by', 'children', 'children_count', 'custom_apply_to_products',
                'custom_design', 'custom_design_from', 'custom_design_to', 'custom_layout_update', 'custom_use_parent_settings',
                'default_sort_by', 'display_mode', 'filter_price_range', 'global_position', 'image', 'include_in_menu', 'is_active',
                'is_always_include_in_menu', 'is_anchor', 'landing_page', 'level', 'lower_cms_block',
                'page_layout', 'path_in_store', 'position', 'small_image', 'thumbnail', 'url_key', 'url_path',
                'visible_in_menu');

            $productAttributes = array_diff($productAttributes, $excludedAttributes);

            foreach ($productAttributes as $attributeCode)
                self::$_productAttributes[$attributeCode] = $config->getAttribute('catalog_category', $attributeCode)->getFrontendLabel();
        }

        return self::$_productAttributes;
    }

    private function isAttributeEnabled($additionalAttributes, $attr_name)
    {
        foreach ($additionalAttributes as $attr)
            if ($attr['attribute'] == $attr_name)
                return true;

        return false;
    }

    private function getReportForProduct($product)
    {
        $report = Mage::getResourceModel('reports/product_collection')
            ->addOrderedQty()
            ->addAttributeToFilter('sku', $product->getSku())
            ->setOrder('ordered_qty', 'desc')
            ->getFirstItem();

        return $report;
    }

    public function getIndexSettings($storeId)
    {
        $attributesToIndex          = array();
        $unretrievableAttributes    = array();
        $attributesForFaceting      = array();

        foreach ($this->config->getProductAdditionalAttributes($storeId) as $attribute)
        {
            if ($attribute['searchable'] == '1')
            {
                if ($attribute['order'] == 'ordered')
                    $attributesToIndex[] = $attribute['attribute'];
                else
                    $attributesToIndex[] = 'unordered('.$attribute['attribute'].')';
            }

            if ($attribute['retrievable'] != '1')
                $unretrievableAttributes[] = $attribute['attribute'];
        }

        $customRankings = $this->config->getProductCustomRanking($storeId);

        $customRankingsArr = array();

        $facets = $this->config->getFacets();

        foreach($facets as $facet)
            $attributesForFaceting[] = $facet['attribute'];

        foreach ($customRankings as $ranking)
            $customRankingsArr[] =  $ranking['order'] . '(' . $ranking['attribute'] . ')';


        $indexSettings = array(
            'attributesToIndex'         => array_values(array_unique($attributesToIndex)),
            'customRanking'             => $customRankingsArr,
            'unretrievableAttributes'   => $unretrievableAttributes,
            'attributesForFaceting'     => $attributesForFaceting,
        );

        // Additional index settings from event observer
        $transport = new Varien_Object($indexSettings);
        Mage::dispatchEvent('algolia_index_settings_prepare', array('store_id' => $storeId, 'index_settings' => $transport));
        $indexSettings = $transport->getData();

        $mergeSettings = $this->algolia_helper->mergeSettings($this->getIndexName(), $indexSettings);

        /**
         * Handle Slaves
         */


        $sorting_indices = $this->config->getSortingIndices();

        if (count($sorting_indices) > 0)
        {
            $slaves = array();

            foreach ($sorting_indices as $values)
                $slaves[] = $this->getIndexName($storeId).'_'.$values['attribute'].'_'.$values['sort'];

            $this->algolia_helper->setSettings($this->getIndexName($storeId), array('slaves' => $slaves));

            foreach ($sorting_indices as $values)
            {
                $mergeSettings['ranking'] = array($values['sort'].'('.$values['attribute'].')', 'typo', 'geo', 'words', 'proximity', 'attribute', 'exact', 'custom');

                $this->algolia_helper->setSettings($this->getIndexName($storeId).'_'.$values['attribute'].'_'.$values['sort'], $mergeSettings);
            }
        }

        unset($mergeSettings['ranking']);

        return $mergeSettings;
    }

    public function getObject(Mage_Catalog_Model_Product $product, $defaultData = array())
    {
        $transport      = new Varien_Object($defaultData);

        Mage::dispatchEvent('algolia_product_index_before', array('product' => $product, 'custom_data' => $transport));

        $defaultData    = $transport->getData();

        $defaultData    = is_array($defaultData) ? $defaultData : explode("|",$defaultData);

        $customData = array(
            'objectID'          => $product->getId(),
            'name'              => $product->getName(),
            'price'             => $product->getPrice(),
            'price_with_tax'    => Mage::helper('tax')->getPrice($product, $product->getPrice(), true, null, null, null, null, false),
            'url'               => $product->getProductUrl(),
            'description'       => $product->getDescription()
        );

        $categories             = array();
        $categories_with_path   = array();

        foreach ($this->getProductActiveCategories($product, $product->getStoreId()) as $categoryId)
        {
            $category = Mage::getModel('catalog/category')->load($categoryId);

            $categoryName = $category->getName();

            if ($categoryName)
                $categories[] = $categoryName;

            $category->getUrlInstance()->setStore($product->getStoreId());
            $path = '';

            foreach ($category->getPathIds() as $treeCategoryId)
            {
                if ($path != '')
                    $path .= ' /// ';

                $path .= $this->getCategoryName($treeCategoryId, $product->getStoreId());
            }

            $categories_with_path[] = $path;
        }

        $customData['categories'] = $categories_with_path;

        $customData['categories_without_path'] = $categories;

        if (false === isset($defaultData['thumbnail_url']))
        {
            try
            {
                $customData['thumbnail_url'] = $product->getThumbnailUrl();
                $customData['thumbnail_url'] = str_replace(array('https://', 'http://'
                ), '//', $customData['thumbnail_url']);
            }
            catch (\Exception $e) {}
        }

        if (false === isset($defaultData['image_url']))
        {
            try
            {
                $customData['image_url'] = $product->getImageUrl();
                $customData['image_url'] = str_replace(array('https://', 'http://'), '//', $customData['image_url']);
            }
            catch (\Exception $e) {}
        }

        $additionalAttributes = $this->config->getProductAdditionalAttributes($product->getStoreId());

        // skip default calculation if we have provided these attributes via the observer in $defaultData
        if (false === isset($defaultData['ordered_qty']) && false === isset($defaultData['stock_qty']))
        {
            $ordered_qty = Mage::getResourceModel('reports/product_collection')
                ->addOrderedQty()
                ->addAttributeToFilter('sku', $product->getSku())
                ->setOrder('ordered_qty', 'desc')
                ->getFirstItem()
                ->ordered_qty;

            $customData['ordered_qty'] = (int) $ordered_qty;
            $customData['stock_qty']   = (int) Mage::getModel('cataloginventory/stock_item')->loadByProduct($product)->getQty();

            if ($product->getTypeId() == 'configurable')
            {
                $sub_products = $product->getTypeInstance(true)->getUsedProducts(null, $product);
                $ordered_qty  = 0;
                $stock_qty    = 0;

                foreach ($sub_products as $sub_product)
                {
                    $stock_qty += (int)Mage::getModel('cataloginventory/stock_item')->loadByProduct($sub_product)->getQty();

                    $ordered_qty += (int) $this->getReportForProduct($sub_product)->ordered_qty;
                }

                $customData['ordered_qty'] = $ordered_qty;
                $customData['stock_qty']   = $stock_qty;
            }

            if ($this->isAttributeEnabled($additionalAttributes, 'ordered_qty') == false)
                unset($customData['ordered_qty']);

            if ($this->isAttributeEnabled($additionalAttributes, 'stock_qty') == false)
                unset($customData['stock_qty']);
        }

        foreach ($additionalAttributes as $attribute)
        {
            $value = $product->hasData($this->_dataPrefix . $attribute['attribute'])
                ? $product->getData($this->_dataPrefix . $attribute['attribute'])
                : $product->getData($attribute['attribute']);

            $value = Mage::getResourceSingleton('algoliasearch/fulltext')->getAttributeValue($attribute['attribute'], $value, $product->getStoreId(), Mage_Catalog_Model_Product::ENTITY);

            if ($value)
                $customData[$attribute['attribute']] = $value;
        }

        $customData = array_merge($customData, $defaultData);

        $customData['type_id'] = $product->getTypeId();

        $this->castProductObject($customData);

        return $customData;
    }

    public function getProductActiveCategories(Mage_Catalog_Model_Product $product, $storeId = null)
    {
        $activeCategories = array();
        foreach ($product->getCategoryIds() as $categoryId) {
            if ($this->isCategoryActive($categoryId, $storeId)) {
                $activeCategories[] = $categoryId;
            }
        }
        return $activeCategories;
    }

    public function isCategoryActive($categoryId, $storeId = null)
    {
        $storeId = intval($storeId);
        $categoryId = intval($categoryId);
        // Check whether the specified category is active
        if ($path = $this->getCategoryPath($categoryId, $storeId)) {
            // Check whether all parent categories for the current category are active
            $isActive = TRUE;
            $parentCategoryIds = explode('/', $path);
            // Exclude root category
            if (count($parentCategoryIds) <= 2) {
                return FALSE;
            }
            // Remove root category
            array_shift($parentCategoryIds);
            // Remove current category as it is already verified
            array_pop($parentCategoryIds);
            // Start from the first parent
            $parentCategoryIds = array_reverse($parentCategoryIds);
            foreach ($parentCategoryIds as $parentCategoryId) {
                if ( ! ($parentCategoryPath = $this->getCategoryPath($parentCategoryId, $storeId))) {
                    $isActive = FALSE;
                    break;
                }
            }
            if ($isActive) {
                return TRUE;
            }
        }
        return FALSE;
    }

    public function getCategoryPath($categoryId, $storeId = null)
    {
        $categories = $this->getCategories();
        $storeId = intval($storeId);
        $categoryId = intval($categoryId);
        $path = null;
        $key = $storeId.'-'.$categoryId;
        if (isset($categories[$key])) {
            $path = ($categories[$key]['value'] == 1) ? strval($categories[$key]['path']) : null;
        } elseif ($storeId !== 0) {
            $key = '0-'.$categoryId;
            if (isset($categories[$key])) {
                $path = ($categories[$key]['value'] == 1) ? strval($categories[$key]['path']) : null;
            }
        }
        return $path;
    }

    public function getCategories()
    {
        if (is_null(self::$_activeCategories)) {
            self::$_activeCategories = array();
            $resource = Mage::getResourceModel('catalog/category'); /** @var $resource Mage_Catalog_Model_Resource_Category */
            if ($attribute = $resource->getAttribute('is_active')) {
                $connection = Mage::getSingleton('core/resource')->getConnection('core_read'); /** @var $connection Varien_Db_Adapter_Pdo_Mysql */
                $select = $connection->select()
                    ->from(array('backend' => $attribute->getBackendTable()), array('key' => new Zend_Db_Expr("CONCAT(backend.store_id, '-', backend.entity_id)"), 'category.path', 'backend.value'))
                    ->join(array('category' => $resource->getTable('catalog/category')), 'backend.entity_id = category.entity_id', array())
                    ->where('backend.entity_type_id = ?', $attribute->getEntityTypeId())
                    ->where('backend.attribute_id = ?', $attribute->getAttributeId())
                    ->order('backend.store_id')
                    ->order('backend.entity_id');
                self::$_activeCategories = $connection->fetchAssoc($select);
            }
        }
        return self::$_activeCategories;
    }

    public function getCategoryName($categoryId, $storeId = null)
    {
        if ($categoryId instanceof Mage_Catalog_Model_Category) {
            $categoryId = $categoryId->getId();
        }
        if ($storeId instanceof Mage_Core_Model_Store) {
            $storeId = $storeId->getId();
        }
        $categoryId = intval($categoryId);
        $storeId = intval($storeId);

        if (is_null(self::$_categoryNames)) {
            self::$_categoryNames = array();
            $resource = Mage::getResourceModel('catalog/category'); /** @var $resource Mage_Catalog_Model_Resource_Category */
            if ($attribute = $resource->getAttribute('name')) {
                $connection = Mage::getSingleton('core/resource')->getConnection('core_read'); /** @var $connection Varien_Db_Adapter_Pdo_Mysql */
                $select = $connection->select()
                    ->from(array('backend' => $attribute->getBackendTable()), array(new Zend_Db_Expr("CONCAT(backend.store_id, '-', backend.entity_id)"), 'backend.value'))
                    ->join(array('category' => $resource->getTable('catalog/category')), 'backend.entity_id = category.entity_id', array())
                    ->where('backend.entity_type_id = ?', $attribute->getEntityTypeId())
                    ->where('backend.attribute_id = ?', $attribute->getAttributeId())
                    ->where('category.level > ?', 1);
                self::$_categoryNames = $connection->fetchPairs($select);
            }
        }

        $categoryName = null;
        $key = $storeId.'-'.$categoryId;
        if (isset(self::$_categoryNames[$key])) { // Check whether the category name is present for the specified store
            $categoryName = strval(self::$_categoryNames[$key]);
        } elseif ($storeId != 0) { // Check whether the category name is present for the default store
            $key = '0-'.$categoryId;
            if (isset(self::$_categoryNames[$key])) {
                $categoryName = strval(self::$_categoryNames[$key]);
            }
        }

        return $categoryName;
    }

}