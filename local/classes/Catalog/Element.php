<?
namespace Godra\Api\Catalog;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Highloadblock as HL;
use Bitrix\Main\Entity;
use Godra\Api\Helpers\Auth\Authorisation;
use Godra\Api\Helpers\Utility\Misc;
use Godra\Api\User\Get;

class Element extends Base
{
    /**
     * Отдаётся при /api/map
     * * Реализованные поля:
     * * * element_code Для выборки одного элемента
     * * * section_code Для выборки всех элементов раздела
     * @var array
     */
    protected static $row_data = [
        'order' => [
            'mandatory' => false,
            'description' => 'Сортировка вида { name: название поля, direction: направлениесортировки(asc/desc) }'
        ],
        'section_code' => [
            'mandatory' => false,
            'alias' => 'CODE',
            'description' => 'Символьный код раздела'
        ],
        'element_code' => [
            'mandatory' => false,
            'alias' => 'CODE',
            'description' => 'Символьный код товара'
        ],
        'limit' => [
            'mandatory' => false,
            'alias' => 'limit',
            'description' => 'Кол-во товаров'
        ],
        'page' => [
            'mandatory' => false,
            'alias' => 'page',
            'description' => 'Текущая страница  '
        ],
    ];

    /**
     * Апи код информационного блока каталога
     * @var string
     */
    protected static $api_ib_code = IBLOCK_CATALOG_API;

    /**
     * id highload-блока договора
     * @var int
     */
    public static $dogovoraHlId = HIGHLOAD_DOGOVORA_ID;

    /**
     * Поля для выборки из ElementTable
     * * Принимает параметры:
     * * * name => название поля,
     * * * method => метод работы с резултатом ( например \\CFile::getPath или \\CFile::getPath($var) )
     * @var array
     */
    protected static $select_rows = [
        [ 'name' => 'NAME'],
        [ 'name' => 'CODE'],
        [ 'name' => 'DETAIL_TEXT'],
        [ 'name' => 'PREVIEW_TEXT'],
        [ 'name' => 'SHOW_COUNTER'],
        [ 'name' => 'ID' , 'alias' => 'id'],
        [ 'name' => 'PREVIEW_PICTURE', 'method' => '\\CFile::getPath'],
        [ 'name' => 'DETAIL_PICTURE',  'method' => '\\CFile::getPath'],
    ];

    /**
     * Поля выборки из ProductTable
     * @var array
     */
    protected static $select_product_rows = [
        [ 'name' => 'ID' , 'alias' => 'id'],
        [ 'name' => 'NAME'],
    ];
	
	protected static $priceRange = [];

    /**
     * Получить список товаров, или 1 товар при наличии code в post_data
     * @return void|array
     */
    public static function getList()
    {
        $products = self::get();

        foreach ($products as &$product)
        {
            // пока цена и количество формируются так
            $product['price']  = self::getPrice($product['id'], $product['props']['Базовая единица'][0]['description']);
            $product['amount'] = self::getAmountById($product['id']);
        }

        // подготовка массива
        $products = self::prepareProducts($products);

        return $products;
    }

    public static function getProduct() 
	{
        $params = Misc::getPostDataFromJson();

//        $params['code'] ='dizelnyy_generator_tss_ad_2000s_t400_1rm26';

        if (empty($params['code']) || !isset($params['code']))
		{
            return ['error' => 'Пустой поле code'];
        }

        $productProps = self::getAllElementProps();
//
        $propCodes = [];

        foreach ($productProps as $key =>$prop) 
		{
            $propCodes[] = 'PROPERTY_' . $prop['CODE'];
        }

        $propCodes = 'PROPERTY_*';
        $headers = apache_request_headers();
		
        $availableProductsXmlId = self::getAvailableProductsId($headers);

		//
		$priceTypeXmlId = (new \Godra\Api\Helpers\Contract)->getPriceTypeByUserId(\Bitrix\Main\Engine\CurrentUser::get()->getId());
		//

        $filter = $availableProductsXmlId ?
            [
                'LOGIC' => 'AND',
                ['CODE' => $params['code']],
                [ 'XML_ID' => $availableProductsXmlId ]
            ]
            :
            ['CODE' => $params['code']];

        $product = self::getElement(
            $propCodes,
            $filter,
            $priceTypeXmlId,
        );

//        return '<pre>'.Print_r($product).'</pre>';
        return $product;
    }

    /**
     * Метод для получения всех свойств элемента инфоблока Каталог
     *
     * @return array
     */
    public static function getIgnoreElementProps() {
        return [
            'OPISANIE_TOVARA',
            'DETALNOE_OPISANIE_TOVARA',
            'CML2_TRAITS',
            'CML2_TAXES',
            'MORE_PHOTO',
            'FILES',
            'VNESHNYAYA_SSYLKA_NA_IZOBRAZHENIE',
            'YAVLYAETSYA_DGU',
            'ZAPCHASTI',
            'PORYADOK_SVOYSTV',
            'CML2_MANUFACTURER',
            'VARIANTY_ISPOLNENIYA',
            'MATERINSKIE_IZDELIYA',
            'DOP_OBORUDOVANIE',
            'DOP_KOMPLEKTATSIYA',
            'TEKHNICHESKOE_OBSLUZHIVANIE',
            'PRIVYAZKA_OPTSIY',
            'VID_OPTSII',
        ];
    }
    public static function getAllElementProps() {
        $props = [];
        $propsObj = \Bitrix\Iblock\PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => IBLOCK_CATALOG,
                'ACTIVE' => 'Y',
            ],
            'select' => ['ID', 'NAME', 'CODE']
        ]);

        while ($row = $propsObj->Fetch()) {
            $props[] = $row;
        }

        return $props;
    }

    /**
     * МЕтод для получения характеристик и свойств товара для детальной страницы
     *
     * @return string[]
     */
    public static function getDefaultProductProps() {
        return [
        ];
    }

    /**
     * Метод для получения свойств типа файл
     *
     * @param $relatedCatalogEntity
     * @param $propertyCode
     * @return array
     */
    public function getPropertyFiles($relatedCatalogEntity, $propertyCode) {
        $filesObj = \CIBlockElement::GetProperty(
            IBLOCK_CATALOG,
            $relatedCatalogEntity,
            ['ACTIVE' => 'Y'],
            ['CODE' => $propertyCode]
        );

        $files = [];
        while ($row = $filesObj->Fetch()) {
            if ((int) $row['VALUE'] !== 0) {
                $files[] = [
                    'id' => (int) $row['VALUE'],
                    'url' => \CFile::GetPath($row['VALUE'])
                ];
            }
        }

        return $files;
    }

    /**
     * метод для получения товара
     *
     * @param false $select
     * @param false $filter
     * @return array|void
     */
    public static function getElement(
        $select = false,
        $filter = false,
        $priceType = false
    ) {
//        $product = \CIBlockElement::GetList(
//            false,
//            array_merge(self::getDefaultFilter(), $filter),
//            false,
//            false,
//            $select ? array_merge(['*'], $select) : ['*']
//        )->Fetch();
//
//        if (!$product['ID']) {
//            return;
//        }

        $res = \CIBlockElement::GetList(Array(), array_merge(self::getDefaultFilter(), $filter), false, Array(), Array('*'));
        while($ob = $res->GetNextElement()){
            $product = $ob->GetFields();
            $arProps = $ob->GetProperties();
        }
        $store_mas = [];
        $rsStoreProduct = \Bitrix\Catalog\StoreProductTable::getList(array(
            'filter' => array('=PRODUCT_ID'=>$product['ID'], '!STORE.ADDRESS'=>false, 'ACTIVE'>='Y'),
            'select' => array('AMOUNT','STORE_ADDRESS'=>'STORE.ADDRESS', 'STORE_TITLE' => 'STORE.TITLE', 'PRODUCT_NAME' => 'PRODUCT.IBLOCK_ELEMENT.NAME'),
        ));
        while($arStoreProduct=$rsStoreProduct->fetch())
        {
            if($store_mas[$arStoreProduct['STORE_ADDRESS']]==NULL)$store_mas[$arStoreProduct['STORE_ADDRESS']]=0;
            $store_mas[$arStoreProduct['STORE_ADDRESS']] += $arStoreProduct['AMOUNT'];
        }
        // множественное свойство "Картинки галереи"
        $pictures = self::getPropertyFiles($product['ID'], 'MORE_PHOTO');

        $priceTypeId = self::getPriceTypeId($priceType);

        $measureCount = \Bitrix\Catalog\ProductTable::getCurrentRatioWithMeasure($product['ID'])[$product['ID']]['MEASURE']['SYMBOL_RUS'];

        // табы
        $tabs = [];
        // таб - описание
        $tabs['description'] = !empty($product['PREVIEW_TEXT']) ? $product['PREVIEW_TEXT'] : '';
        // таб - характеристики
//        $allProps = self::getAllElementProps();
//        $props = self::getDefaultProductProps();
//        return '<pre>'.Print_r($arProps).'</pre>';
        $ignore_prop = self::getIgnoreElementProps();
        $product['PROPERTY_CML2_ARTICLE_VALUE'] = $arProps['CML2_ARTICLE']['VALUE'];
        foreach ($arProps as $k => $prop) {
            if ($prop['CODE'] !== 'CML2_ARTICLE'
                && $prop['VALUE'] !== null
                && $prop['VALUE'] !== 'null'
                && !in_array($prop['CODE'], $ignore_prop)
                && $prop['VALUE'] !== '') {
                 $tabs['props'][] = [
                            'name' => $prop['NAME'],
                            'value' => $prop['VALUE']
                 ];
            }
        }
        // таб - доставка
        $tabs['delivery'] = 'Доставка осуществляется курьером или возможен самовывоз';

        // таб - акции
        $tabs['stocks'] = self::getProductStocks($product['ID']);

        // множественное свойство - похожие товары
        //$similarProducts = self::getSimilarProducts($product['ID']);

        // множественное свойство - сертификаты
        $certificates = self::getPropertyFiles($product['ID'], 'CERTIFICATES');

        // история просмотра (пустая, если пользователь не авторизован)
        $viewedProducts = self::getViewed() ?? [];

        // разделы
        $rsSection = \Bitrix\Iblock\SectionTable::getList([
            'filter' => [
                'IBLOCK_ID' => IBLOCK_CATALOG,
                'DEPTH_LEVEL' => 1
            ],
            'select' =>  ['ID','CODE','NAME'],
        ]);

        $rsSections = [];
        while ($arSection=$rsSection->fetch()) {
            $rsSections[] = $arSection;
        }

        // получение корневого раздела
        $scRes = \CIBlockSection::GetNavChain(
            IBLOCK_CATALOG,
            $product['IBLOCK_SECTION_ID'],
            ['ID', 'DEPTH_LEVEL']
        );

        $ROOT_SECTION_ID = 0;
        while($arGrp = $scRes->Fetch()){
            // определяем корневой раздел
            if ($arGrp['DEPTH_LEVEL'] == 1){
                $ROOT_SECTION_ID = $arGrp['ID'];
            }
        }

        $rsSection = \Bitrix\Iblock\SectionTable::getList(array(
            'filter' => array(
                'IBLOCK_ID' => IBLOCK_CATALOG,
                'ID' => $ROOT_SECTION_ID,
                'DEPTH_LEVEL' => 1,
            ),
            'select' =>  array('ID','CODE','NAME'),
        ))->Fetch();

        $price = [];
        $db_res = \CPrice::GetList(
            array(),
            array(
                "PRODUCT_ID" => (int) $product['ID'],
                "CATALOG_GROUP_ID" => array(496)
            )
        );
        while ($ar_res = $db_res->Fetch())
        {
            $price[]=$ar_res["PRICE"];
        }
        global $USER;
        $quantity = 1;
        $renewal = 'N';
        $arPrice = \CCatalogProduct::GetOptimalPrice(
            $product['ID'],
            $quantity,
            $USER->GetUserGroupArray(),
            $renewal
        );
        $price[]=$arPrice['PRICE']['PRICE'];

        Loader::includeModule("sale");
        $cntBasketItems = \CSaleBasket::GetList(
            array(),
            array(
                "FUSER_ID" => \CSaleBasket::GetBasketUserID(),
                "PRODUCT_ID"=>(int) $product['ID'],
                "ORDER_ID" => "NULL"
            ),
            false,
            false,
            array()
        );
        $inBasket=0;
        $qa=0;
        if ($arItems = $cntBasketItems->Fetch())
        {
            $inBasket=1;
            $qa=$arItems['QUANTITY'];
        }


            return [
                'id' => (int)  $product['ID'],
                'article'=>$product['PROPERTY_CML2_ARTICLE_VALUE'],
                'in_basket'=>$inBasket,
                'qa'=>$qa,
                'store'=>$store_mas,
                'code' => $product['CODE'],
                'name' => $product['NAME'],
                'artnumber' => $product['PROPERTY_CML2_ARTICLE_VALUE'] ?? '',
                'description' => $arProps['OPISANIE_TOVARA']['VALUE'],
                'pictures' => $pictures ?? [],
                // для авторизованных пользователей
                // цены
                'price' => $price ?? [],
                // единица измерения товара
                //'measure_count' => $measureCount ?? '',
                // доступное количество
                'available_count' => self::getAvailableCount($product['ID']) ?? '',
                'tabs' => $tabs ?? [],
                'similar_products' => $similarProducts ?? [],
                'certificates' => $certificates ?? [],
                'section' => [
                    'code' => $rsSection['CODE'],
                    'name' => $rsSection['NAME']
                ],
                'viewed_products' => $viewedProducts ?? []
            ];

    }

    /**
     * Метод для получения Похожих товаров
     *
     * @param $productId
     * @return array
     */
    public static function getSimilarProducts($productId) {
        $similarProductsIds = [];

        $similarProductsObj = \CIBlockElement::GetProperty(
            IBLOCK_CATALOG,
            $productId,
            ['ACTIVE' => 'Y'],
            ['CODE' => 'SIMILAR_PRODUCTS']
        );

        while ($row = $similarProductsObj->Fetch()) {
            $similarProductsIds[] = (int) $row['VALUE'];
        }

        return self::getElements(
            self::getSelectFields(),
            array_merge(self::getDefaultFilter(), ['ID' => $similarProductsIds])
        );
    }

    public static function getViewed()
    {
        $result = [];
        $products_array = self::getViewedProducts();

        foreach ($products_array as $value)
        {
            $result = array_merge($result, self::get($value));
        }

        return $result;
    }

    /**
     * Получить количество элементов по фильтру, работает как self::getList
     * @return int
     */
    public static function getCount()
    {
        return self::countElementsBySectionCode();
    }

    # дополнительные методы
    /**
     * Метод для получения свойств товаров каталога
     *
     * @return array
     */
    public static function getPropertiesFields() {
        $catalogPropsArr = [];

        $props = \Bitrix\Iblock\PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => IBLOCK_CATALOG,
                'ACTIVE' => 'Y'
            ],
            'select' => ['*']
        ]);

        while ($row = $props->Fetch()) {
            $catalogPropsArr[] = [
                'name' => $row['NAME'],
                'code' => $row['CODE']
            ];
        }

        return $catalogPropsArr;
    }

    /**
     * Метод для подготовки массива товара(-ов)
     *
     * @param $products
     * @return mixed
     */
    public static function prepareProducts($products) {
        $catalogPropertiesFields = self::getPropertiesFields();

        foreach ($products as $kP => $product) {
            // удаляем "количество просмотров"
            unset($products[$kP]['show_counter']);

            $products[$kP]['delivery'] = 'Доставка осуществляется курьером или возможен самовывоз';

            $newPropsArr = [];

            // перебираем свойства
            foreach ($catalogPropertiesFields as $kF => $field) {
                foreach ($product['props'] as $kProp => $prop) {
                    if ($prop !== '' && $kProp == $field['name']) {
                        $newPropsArr[strtolower($field['code'])] = [
                            'propertyName' => $field['name'],
                            'value' => $prop
                        ];
                    }
                }
            }

            // удаление свойств в старом формате
            unset($products[$kP]['props']);

            $products['props'] = $newPropsArr;

            $products[$kP]['stocks'] = 'Здесь будет информация об акциях';
        }

        return $products;
    }

    // метод для получения разделов товаров из ассортимента
    public function getAvailableProductsSections($availableProductsXmlId) {
        $availableProductsSections = [];

        $sectionsObj = \Bitrix\Iblock\ElementTable::getList([
            'filter' => [
                '=XML_ID' => $availableProductsXmlId,
                'ACTIVE' => 'Y'
            ],
            'select' => ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID']
        ]);

        while ($row = $sectionsObj->Fetch()) {
            $scRes = \CIBlockSection::GetNavChain(
                IBLOCK_CATALOG,
                $row['IBLOCK_SECTION_ID'],
                ['ID', 'DEPTH_LEVEL', 'NAME']
            );

            while($arGrp = $scRes->Fetch()){
                //$availableProductsSections[] = $arGrp;

                // определяем корневой раздел
                if ($arGrp['DEPTH_LEVEL'] == 1){
                    $availableProductsSections[] = (int) $arGrp['ID'];
                }
            }

            //$availableProductsSections[] = $row;
        }

        return array_values(array_unique($availableProductsSections));
    }

    /**
     * Метод для получения информации о разделах первого уровня каталога
     *
     * @return array
     */
    public static function getFirstLevelSections() {

        $headers = apache_request_headers();
        $availableProductsXmlId = self::getAvailableProductsId($headers);
        $availableProductsSections = self::getAvailableProductsSections($availableProductsXmlId);

        $arFilter = $availableProductsSections ?
            [
                'LOGIC' => 'AND',
                [
                    // добавление дополнительного фильтра по ассортименту
                    'ID' => $availableProductsSections
                ],
                [
                    'IBLOCK_ID' => IBLOCK_CATALOG,
                    'DEPTH_LEVEL' => 1,
                    'ACTIVE' => 'Y'
                ]
            ] :
            [
                'IBLOCK_ID' => IBLOCK_CATALOG,
                'DEPTH_LEVEL' => 1,
                'ACTIVE' => 'Y'
            ];

        // пока для неавторизованного пользователя
        $firstLevelSections = [];

        try {
            $sections = \Bitrix\Iblock\SectionTable::getList([
                'select' => ['*'],
                'filter' => $arFilter,
                // 'order' => []
            ]);
        } catch (\Bitrix\Main\SystemException $e) {
            return ['error' => $e->getMessage()];
        }

        while ($row = $sections->Fetch()) {
            $firstLevelSections[] = [
                // id
                'id' => (int) $row['ID'],
                // code
                'code' => $row['CODE'],
                // url - ссылка на раздел
                'url' => '/catalog/' . $row['CODE'],
                // name - название раздела
                'name' => $row['NAME'],
                // picture - картинка раздела
                'picture' => \CFile::GetPath($row['PICTURE'])
            ];
        }

        return $firstLevelSections;
    }

    /**
     * Метод для получения разделов каталога 1 уровня - "Популярные предложения"
     * Главная страница - блок "Популярные предложения"
     *
     * @param false $sectionsCount
     * @return array
     */
    public static function getPopularSections($sectionsCount = false) {

        $headers = apache_request_headers();
        $availableProductsXmlId = self::getAvailableProductsId($headers);

        $popularSections = [];

        $filter =  $availableProductsXmlId ?
            [
                'LOGIC' => 'AND',
                [
                    'LOGIC' => 'OR',
                    ['!PROPERTY_POPULAR_OFFER' => false],
                    ['!PROPERTY_POPULAR_OFFER_1C' => false]
                ],
                [
                    '=XML_ID' => $availableProductsXmlId,
                    'INCLUDE_SUBSECTIONS' => 'Y'
                ]
            ] :
            [
                'LOGIC' => 'OR',
                ['!PROPERTY_POPULAR_OFFER' => false],
                ['!PROPERTY_POPULAR_OFFER_1C' => false]
            ];

        try {
            // получение элементов с непустым PROPERTY_POPULAR_OFFER, PROPERTY_POPULAR_OFFER_1C
            $popularElementsObj = \CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => IBLOCK_CATALOG,
                    'ACTIVE' => 'Y',
                    $filter
                ],
                false,
                false,
                [
                    '*',
                    'PROPERTY_POPULAR_OFFER',
                    'PROPERTY_POPULAR_OFFER_1C'
                ]
            );

        } catch (\Bitrix\Main\SystemException $e) {
            return ['error' => $e->getMessage()];
        }

        while ($row = $popularElementsObj->Fetch()) {
            $scRes = \CIBlockSection::GetNavChain(
                IBLOCK_CATALOG,
                $row['IBLOCK_SECTION_ID'],
                ['ID', 'DEPTH_LEVEL']
            );

            $ROOT_SECTION_ID = 0;
            while($arGrp = $scRes->Fetch()){
                // определяем корневой раздел
                if ($arGrp['DEPTH_LEVEL'] == 1){
                    $ROOT_SECTION_ID = $arGrp['ID'];
                }
            }

            if (!in_array($ROOT_SECTION_ID, $popularSections)) {
                // получить информацию по разделу
                $section = \Bitrix\Iblock\SectionTable::getList([
                    'select' => ['*'],
                    'filter' => [
                        'IBLOCK_ID' => IBLOCK_CATALOG,
                        'ACTIVE' => 'Y',
                        'ID' => $ROOT_SECTION_ID
                    ]
                ])->Fetch();

                if ($section) {
                    $popularSections[$ROOT_SECTION_ID] = [
                        'id' => (int) $ROOT_SECTION_ID,
                        'name' => $section['NAME'],
                        'code' => $section['CODE'],
                        'picture' => \CFile::GetPath($section['PICTURE'])
                    ];
                }
            }
        }

        return array_values($popularSections);
    }

    /**
     * Метод для получения типа цена
     *
     * @param $tokenUserId
     * @return false
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getPriceType($userXmlId) {
        Loader::includeModule('highloadblock');

        $hlblock = HL\HighloadBlockTable::getById(self::$dogovoraHlId)->fetch();
        $entity = HL\HighloadBlockTable::compileEntity($hlblock);

        $entityDataClass = $entity->getDataClass();

        return $entityDataClass::getList([
            'select' => ['*'],
            'filter' => [
                'UF_IDKONTRAGENTA' => $userXmlId
            ]
        ])->Fetch()['UF_IDTIPACEN'];
    }

    /**
     * метод для получения количества элементов в разделе
     *
     * @param $sectionId
     */
    public static function getSectionElementsCount($sectionId, $filter = false) {
        return \CIBlockSection::GetSectionElementsCount($sectionId, $filter);
    }

    /**
     * метод для получения договора по XML_ID
     *
     * @param $xmlId
     * @return mixed
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\SystemException
     */
    public function getDeal($xmlId) {
        Loader::includeModule('highloadblock');

        $hlblock = HL\HighloadBlockTable::getById(self::$dogovoraHlId)->fetch();
        $entity = HL\HighloadBlockTable::compileEntity($hlblock);

        $entityDataClass = $entity->getDataClass();

        return $entityDataClass::getList([
            'select' => ['*'],
            'filter' => [
                '%UF_XML_ID' => trim($xmlId)
            ]
        ])->Fetch();
    }

    /**
     * метод для получения внешних идентификаторов доступных товаров по договору
     *
     * @param $assortimentId
     * @return array
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\SystemException
     */
    public function getAvailableDealProductsXmlId($assortimentId) {
        $productsXmlId = [];

        Loader::includeModule('highloadblock');

        $hlblock = HL\HighloadBlockTable::getById(HIGHLOAD_ASSORTIMENT_CENTER_ID)->fetch();
        $entity = HL\HighloadBlockTable::compileEntity($hlblock);

        $entityDataClass = $entity->getDataClass();

        $objs = $entityDataClass::getList([
            'select' => ['*'],
            'filter' => [
                'UF_IDASSORTIMENTA' => trim($assortimentId)
            ]
        ]);

        while ($row = $objs->Fetch()) {
            $productsXmlId[] = $row['UF_IDNOMENKLATURY'];
        }

        return $productsXmlId;
    }

    /**
     * Метод для получения идентификаторов доступных товаров
     *
     * @param $headers
     * @return array
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\SystemException
     */
    public function getAvailableProductsId($headers) 
	{
        return [];
		//return (new \Godra\Api\Helpers\Nomenclature)->getByUserId(\Bitrix\Main\Engine\CurrentUser::get()->getId());

		/*
		// определить id пользователя по токену
        $decoded = Authorisation::getUserId($headers);
        if (!isset($decoded['error'])) {
            $tokenUserId = $decoded;
        }

        // является ли суперпользователем
        if (Authorisation::isSuperUser($headers)) {
            $superUserId = $tokenUserId;
        } else {
            // искать суперпользователя для текущего пользователя
            $superUserXmlId = Get::getParentUserXmlId($tokenUserId);
            $superUserId = Get::getUserIdByXmlId($superUserXmlId);
        }
		
        // Идентификатор текущего договора
        $dealId = UserTable::getList([
            'filter' => [ 'ID' => $superUserId, 'ACTIVE' => 'Y' ],
            'select' => [ 'ID', 'UF_ID_DOGOVOR' ]
        ])->Fetch()['UF_ID_DOGOVOR'];

        $deal = self::getDeal($dealId);
        $priceTypeXmlId = $deal['UF_IDTIPACEN'];

        // получить внешние идентификаторы доступных товаров по договору
       return self::getAvailableDealProductsXmlId($deal['UF_IDASSORTIMENTA']);
	   */
    }

    // метод для получения элентов разделов 1-2 уровня
    public static function getSectionElements() {

		$priceType = 496; // базовый тип цен

        $params = Misc::getPostDataFromJson();
//        $params['section_code'] = 'dizelnye_elektrostantsii';
//        $params['sort_by']['code'] = 'price';
//        $params['sort_by']['direction'] = 'asc';

        if (!$params['section_code']) {
            return ['error' => 'Не указан section_code раздела'];
        }

        if ($params['use_pagination'] == 'N') {

        } else {
            // страница раздела, по умолчанию первая страница
            if (!$params['page']) {
                $params['page'] = 1;
            }
        }

        // результат
        $result = [];

        // по умолчанию количество товаров для показа
        if (empty($params['show_count']) || !isset($params['show_count'])) {
            $params['show_count'] = 30;
        }

        $sectionData = self::getSection(['CODE' => $params['section_code']]);

        if (!$sectionData) {
            http_response_code(404); exit;
//            return ['error' => 'Нет раздела с таким section_code'];
        }

        // проверка раздела - является ли разделом первого или второго уровня
        if (!self::checkSection($sectionData['ID'])) {
            return ['error' => 'Раздел не соответствует 1 и 2 уровню вложенности'];
        }

        Loader::includeModule('iblock');

        // id раздела
        $result['section_id'] = (int) $sectionData['ID'];
        // код раздела
        $result['section_code'] = $sectionData['CODE'];
        // название раздела
        $result['section_name'] = $sectionData['NAME'];

        switch ($params['sort']['code']) {
            case 'price':
               switch ($params['sort']['direction']) {
                   case 'asc':
                       $arOrder = [
                           'CATALOG_PRICE_' . $priceType => 'ASC'
                       ];
                   break;
                   case 'desc':
                       $arOrder = [
                           'CATALOG_PRICE_' . $priceType => 'DESC'
                       ];
                   break;
               }
            break;
            case 'name':
                switch ($params['sort']['direction']) {
                    case 'asc':
                        $arOrder = [
                            'NAME' => 'ASC'
                        ];
                        break;
                    case 'desc':
                        $arOrder = [
                            'NAME' => 'DESC'
                        ];
                        break;
                }
                break;
        }

        $arFilter = [];
		
		// фильтр по цене // Dmitry
		if ((float)$params['price']['min'])
		{
			$arFilter['>=CATALOG_PRICE_'.$priceType] = (float)$params['price']['min'];
		}
		
		if ((float)$params['price']['max'])
		{
			$arFilter['<=CATALOG_PRICE_'.$priceType] = (float)$params['price']['max'];
		}
		
		//print_r($arFilter);exit;

        // если не нужно фильтровать по разделу
        $arFilter['SECTION_CODE'] = $result['section_code'];

		if ($arFilter)
		{
			$arFilter = array_merge($arFilter, ['INCLUDE_SUBSECTIONS' => 'Y']);
		}
		else
		{
			$arFilter = ['INCLUDE_SUBSECTIONS' => 'Y'];
		}

        foreach ($params['filter'] as $key => $value){
            if($key=='PRICE'){
                $arFilter['>=CATALOG_PRICE_'.$priceType] = $value['VALUE_MIN'];
                $arFilter['<=CATALOG_PRICE_'.$priceType] = $value['VALUE_MAX'];
            }else{
                if(array_key_exists('VALUE_MIN', $value)){
                    $arFilter['>=PROPERTY_'.$key] = $value['VALUE_MIN'];
                    $arFilter['<=PROPERTY_'.$key] = $value['VALUE_MAX'];
                }else{
                    $arFilter['>=PROPERTY_'.$key] = $value;
                }
            }
        }
//        return $arFilter;
        // получение всех элементов раздела
        $sectionElements = self::getElements(
            self::getSelectFields(),
            $arFilter,
            isset($arOrder) ? $arOrder : false,
            $params['page'] ? [
                'nPageSize' => $params['show_count'],
                'iNumPage' => $params['page']
            ] : false,
            $priceTypeXmlId ?? false,
			'catalog'
        );

        // всего товаров #
        $result['all_elements_count'] = self::getAllSectionElementsCount($arFilter);

        // номер страницы
        if ($params['page']) {
            $result['page'] = $params['page'];
        }

        // количество элементов на данной странице
        $result['show_count'] = $params['show_count'];

        // товары
        $result['elements'] = $sectionElements ?? '';


        // есть ли следующая страница
        if ($params['page']) {
            if ($result['all_elements_count'] < $result['show_count'] * $result['page']) {
                $result['next_page'] = false;
            } else {
                $result['next_page'] = true;
            }
        }

        // для отладки
//        return [
//            'order' => $arOrder,
//            'filter' => $arFilter,
//            'stocks' => self::getStockProducts(),
//            'result' => $result,
//            'brands' => self::getAllSectionBrands($arFilter['SECTION_CODE'])
//        ];

        return $result;
    }

    /**
     * Метод для получения значения id свойства
     *
     * @param $type
     * @param $value
     * @return mixed
     */
    public static function getEnumPropertyId($type, $value) {
         return \CIBlockPropertyEnum::GetList(false, [
             'VALUE' => $value,
             'CODE' => strtoupper($type)
         ])->Fetch()['ID'];
    }

    /**
     * Метод для получения всех акционных товаров или разделов
     *
     * @return array
     */
    public function getStockProducts() {
        $allStocksProds = [];
        $allStocksSects = [];

        $allStocksObj = \CIBlockElement::GetList(
            false,
            [
                'IBLOCK_ID' => 3,
                'ACTIVE' => 'Y',
            ],
            false,
            false,
            [
                'ID',
                'PROPERTY_PRODUCTS',
                'PROPERTY_SECTIONS'
            ]
        );

        while ($row = $allStocksObj->Fetch()) {
            if ($row['PROPERTY_PRODUCTS_VALUE'] !== null) {
                $allStocksProds[] = (int) $row['PROPERTY_PRODUCTS_VALUE'];
            }

            if ($row['PROPERTY_SECTIONS_VALUE'] !== null) {
                $allStocksSects[] = (int) $row['PROPERTY_SECTIONS_VALUE'];
            }
        }

        return [
            'stockProds' => array_values(array_unique($allStocksProds)),
            'stockSects' => array_values(array_unique($allStocksSects)),
        ];
    }

    /**
     * Метод для получения акций, в которых участвует товар
     *
     * @param $productId
     * @return array|int
     */
    public static function getProductStocks($productId) {
        $productStocks = [];

        // метод для получения товаров акции
        $stocksProds = \Godra\Api\Iblock\Stock::getDetailStocksData();

        foreach ($stocksProds as $stock) {
            if (in_array($productId, $stock['products_ids'])) {
                $productStocks[] = (int) $stock['id'];
            }
        }

        return \Godra\Api\Iblock\Stock::getStocksData(
            false,
            ['ID' => $productStocks],
            false,
            false
        );
    }

    /**
     * метод для получения всех id брендов по названию
     *
     * @param $brandNames
     * @return array
     */
    public static function getBrandIdsByBrandName($brandNames) {
        $brandIds = [];

        foreach ($brandNames as $name) {
            $brandIds[] = (int) \CIBlockPropertyEnum::GetList(false, [
                'VALUE' => $name,
                'CODE' => 'BREND'
            ])->Fetch()['ID'];
        }

        return $brandIds;
    }

    // метод для получения

    /**
     * Метод для получения всех брендов товаров раздела
     *
     * @param $sectionCode
     * @return array
     */
    public function getAllSectionBrands($sectionCode) {
        $brandsRaw = [];

        $allSectionElements = self::getElements(
            self::getSelectFields(),
            [
                'SECTION_CODE' => $sectionCode,
                'INCLUDE_SUBSECTIONS' => 'Y'
            ],
            false,
            false,
            false
        );

        foreach ($allSectionElements as $element) {
            if (!in_array($element['brand'], $brandsRaw) && $element['brand'] !== '') {
                $brandsRaw[] = $element['brand'];
            }
        }

        $brands = [];
        $i = 0;
        foreach ($brandsRaw as $brand) {
            $brands[] = [
                'id' => $i,
                'name' => $brand
            ];
            $i++;
        }

        return $brands;
    }

    /**
     * Метод для получения количества элементов раздела
     *
     * @param $sectionCode
     * @return int
     */
    public function getAllSectionElementsCount($filter = false) {
        return count(self::getElements(
            ['ID'],
            $filter ? array_merge($filter, [
                'INCLUDE_SUBSECTIONS' => 'Y'
            ]) : [
                'INCLUDE_SUBSECTIONS' => 'Y'
            ],
            false,
            false,
            false
        ));
    }


    /**
     * Метод для получения выбираемых полей и свойств
     *
     * @return string[]
     */
    public static function getSelectFields() {
        return [
            // Артикул
            '*',
            'PROPERTY_CML2_ARTICLE',
            'PROPERTY_DOP_KOMPLEKTATSIYA',
        ];
    }

    /**
     * Метод для получения элементов
     *
     * @param false $select
     * @param false $filter
     * @param false $order
     * @param false $nav
     * @return array
     */
    public static function getElements(
        $select = false,
        $filter = false,
        $order = false,
        $nav = false,
        $priceTypeXmlId = false,
		$requestType = false
    ) 
	{
        $elements = [];

        $defaultFilter = self::getDefaultFilter();
		
		$mainFilter = $filter ? array_merge($defaultFilter, $filter) : $defaultFilter;

        global $USER;
        $rsUser = \CUser::GetByID($USER->GetID());
        $arUser = $rsUser->Fetch();
        Loader::includeModule("highloadblock");
        $hlbl = 60; // Указываем ID нашего highloadblock блока к которому будет делать запросы.
        $hlblock = HL\HighloadBlockTable::getById($hlbl)->fetch();

        $entity = HL\HighloadBlockTable::compileEntity($hlblock);
        $entity_data_class = $entity->getDataClass();

        $rsData = $entity_data_class::getList(array(
            "select" => array("*"),
            "order" => array("ID" => "ASC"),
            "filter" => array("UF_USER"=>$arUser['UF_CONTRAGENT_ID']),  // Задаем параметры фильтра выборки
        ));
        while($arData = $rsData->Fetch()){
            $cont_discount =  $arData["UF_DISCOUNT"];
        }

        $elementsRaw = \CIBlockElement::GetList(
            $order ? $order : [],
            $mainFilter,
            false,
            $nav ? $nav : [],
            $select ? $select : ['*']
        );

        //while ($row = $elementsRaw->Fetch())
		while ($row = $elementsRaw->GetNext())
		{

            // множественное свойство "Картинки галереи"
            $pictures = self::getPropertyFiles($row['ID'], 'MORE_PHOTO');
			//
			$section_code = '';

			if ($row['DETAIL_PAGE_URL'])
			{
				// костыль для сайта, там очень странные самодельные url товаров
				$urlArr = explode('/', $row['DETAIL_PAGE_URL']);

				if ($urlArr[2])
				{
					$section_code = $urlArr[2];
				}
			}
			//

            // для авторизованного
            $price = [];
            $price[]=$row["CATALOG_PRICE_496"];

//            global $USER;
//            $quantity = 1;
//            $renewal = 'N';
//            $arPrice = \CCatalogProduct::GetOptimalPrice(
//                $row['ID'],
//                $quantity,
//                $USER->GetUserGroupArray(),
//                $renewal
//            );

            if($cont_discount) {
                $price[] = $price[0] - ($price[0] * $cont_discount / 100);
            }else{
                $price[] = $price[0];
            }
//            $price[]=$arPrice['PRICE']['PRICE'];
            Loader::includeModule("sale");
            $cntBasketItems = \CSaleBasket::GetList(
                array(
                    "NAME" => "ASC",
                    "ID" => "ASC"
                ),
                array(
                    "FUSER_ID" => \CSaleBasket::GetBasketUserID(),
                    "PRODUCT_ID"=>$row['ID'],
                    "ORDER_ID" => "NULL"
                ),
                false,
                false,
                array()
            );
            $inBasket=0;
            $qa=0;
            while ($arItems = $cntBasketItems->Fetch())
            {
                $inBasket=1;
                $qa=$arItems['QUANTITY'];
            }

            if($row['PROPERTY_DOP_KOMPLEKTATSIYA_VALUE']==NULL){
                $option_flag  = false;
            }else{
                $option_flag = true;
            }
                // карточка товара
                $elements[] = [
                    // id
                    'id' => (int) $row['ID'],
                    //артикул
                    'article'=>$row['PROPERTY_CML2_ARTICLE_VALUE'],
                    'compl'=>$row['PROPERTY_DOP_KOMPLEKTATSIYA_VALUE'],
                    // название
                    'option_flag'=>$option_flag,
                    'name' => $row['NAME'],
                    // код
                    'code' => $row['CODE'],
                    // картинке на анонсе
                    'pictures' => $pictures ?? '',
                    // цена
                    'price' => $price ?? [],
                    // единица измерения товара
                    //'measure_count' => $measureCount ?? '',
					'section_code' => $section_code,
                    'in_basket'=>$inBasket,
                    'qa'=>$qa,
                ];
		}
    return $elements;
    }

    /**
     * Метод для получения доступного количества товара
     *
     * @param $productId
     * @return mixed
     * @throws \Bitrix\Main\LoaderException
     */
    public static function getAvailableCount($productId) {
        \Bitrix\Main\Loader::IncludeModule('catalog');

        return (int) \Bitrix\Catalog\ProductTable::getList([
            'filter' => ['ID' => $productId]
        ])->fetch()['QUANTITY'];
    }

    /**
     * Метод для получения цены
     *
     * @param $productId
     * @param $priceTypeId
     * @return mixed
     */
    public static function getPriceValue($productId, $priceTypeId) {
        return \Bitrix\Catalog\PriceTable::getList([
            'filter' => [
                'PRODUCT_ID' => (int) $productId,
                'CATALOG_GROUP_ID' => (int) $priceTypeId
            ]
        ])->Fetch()['PRICE'];
    }

    /**
     * Метод для получения идентификатора типа цен
     *
     * @param $priceType
     * @return mixed
     */
    public static function getPriceTypeId($priceType) {
        return \Bitrix\Catalog\GroupTable::getList([
            'select' => ['*'],
            'filter' => [
                '=XML_ID' => trim($priceType)
            ]
        ])->Fetch()['ID'];
    }

    /**
     * Мeтод для получения подготовленного массива данных дочерних разделов
     *
     * @param false $filter
     * @return array
     */
    public static function getSubsections($filter = false) {
        $subSections = [];

        $subSectionsRaw = self::getSections($filter);

        foreach ($subSectionsRaw as $k => $subSection) {
            $subSections[] = [
                'id' =>  (int) $subSection['ID'],
                'code' =>  $subSection['CODE'],
                'name' =>  $subSection['NAME'],
            ];
        }

        return $subSections;
    }

    /**
     * Метод для получения фиьлтра по умолчанию
     *
     * @return array
     */
    public static function getDefaultFilter() {
        return [
            'IBLOCK_ID' => IBLOCK_CATALOG,
            'ACTIVE' => 'Y'
        ];
    }

    /**
     * Метод для получения данных о разделе каталога
     *
     * @param false $filter
     * @return mixed
     */
    public static function getSection($filter = false) {
        $defaultFilter = self::getDefaultFilter();

        return \Bitrix\Iblock\SectionTable::getList([
            'select' => ['*'],
            'filter' => $filter ? array_merge($defaultFilter, $filter) : $defaultFilter,
        ])->Fetch();
    }

    /**
     * Метод для получения данных о разделах каталога
     *
     * @param false $filter
     * @return array
     */
    public static function getSections($filter = false) {
        $defaultFilter = self::getDefaultFilter();

        $sections = [];

        $sectionsObjs = \Bitrix\Iblock\SectionTable::getList([
            'select' => ['*'],
            'filter' => $filter ? array_merge($defaultFilter, $filter) : $defaultFilter,
        ]);

        while ($row = $sectionsObjs->Fetch()) {
            $sections[] = $row;
        }

        return $sections;
    }

    /**
     * Метод для проверки - относится ли раздел к 1 или 2 уровню вложенности
     *
     * @param $sectionId
     * @return bool
     */
    public function checkSection($sectionId) {
        $firstAndSecondLevelSectionsIds = [];

        $firstAndSecondLevelSectionsObjs = self::getSections([
            [
                'LOGIC' => 'OR',
                ['DEPTH_LEVEL' => 1],
                ['DEPTH_LEVEL' => 2]
            ]
        ]);

       foreach ($firstAndSecondLevelSectionsObjs as $k => $section) {
           $firstAndSecondLevelSectionsIds[] = (int) $section['ID'];
       }

       if (in_array($sectionId, $firstAndSecondLevelSectionsIds)) {
            return true;
       }

        return false;
    }
}
?>