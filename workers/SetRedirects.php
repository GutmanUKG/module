<?
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(dirname(__FILE__) . '/../../../..');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Application,
    Bitrix\Main\Config\Option;

if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/ImarketLogger.php');
}

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/classes/RestAPI.php');
require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/services/RedirectService.php');
ini_set('memory_limit', '25600M'); // много кушает оперативки!!!!
set_time_limit(0);


class SetRedirects {
    private ImarketLogger $Logger;
    private \Bitrix\Main\DB\Connection|\Bitrix\Main\Data\Connection $connection;
    private RestAPI $restAPI;
    private RedirectService $redirectService;
    private ?int $catalogIblockId;

    public function __construct () {
        $this->Logger = new ImarketLogger("/upload/log/SetRedirects/");
        $this->connection = Application::getConnection();
        $this->restAPI = new RestAPI();
        $this->redirectService = new RedirectService();

        CModule::IncludeModule("iblock");
        $this->catalogIblockId = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");
    }

    public function StartWorker() {
        $this->Logger->log("LOG", "Начало обработки");

        $this->Logger->log("LOG", "Получение моделей из каталог апп");
        $catalogAppModels = $this->restAPI->GetModels();
        $this->Logger->log("LOG", "Получение моделей на маркете из каталог апп");
        $lastAtMarketProducts = $this->redirectService->getLastMarketProductFromCatalogApp();
        $this->Logger->log("LOG", "Получение товаров сайта");
        $siteProducts = $this->getSiteProducts();

        $catalogAppModelsById = [];
        foreach ($catalogAppModels as $catalogAppModel) {
            $catalogAppModelsById[$catalogAppModel['id']] = $catalogAppModel;
        }

        foreach ($lastAtMarketProducts as $lastAtMarketProduct) {
            $redirectUrl = '/';
            $model = $catalogAppModelsById[$lastAtMarketProduct['modelId']];
            $modelXmlId = $model['externalId'] ?? $model['id'];
            $siteProduct = $siteProducts[$modelXmlId];

            if (empty($lastAtMarketProduct['lastTimeOnMarket'])) {
                if (!empty($siteProduct['IBLOCK_SECTION_ID'])) {
                    if ($this->isProductSectionActive($siteProduct['IBLOCK_SECTION_ID'])) {
                        $redirectUrl = $this->getSectionUrl($siteProduct['IBLOCK_SECTION_ID']);
                    }
                }
            } else {
                $redirectUrl = '';
            }

            if (!empty($siteProduct['ID'])) {
                $this->Logger->log("LOG", "Установка редиректа '{$redirectUrl}' товару [{$siteProduct['ID']}] {$siteProduct['NAME']}");

                if (!$result = $this->redirectService->setProductRedirect($siteProduct['ID'], $redirectUrl)) {
                    $this->Logger->log('ERROR', print_r($result->LAST_ERROR, true));
                }
            }
        }

        $this->Logger->log("LOG", "Обработка закончена");
    }

    private function getSiteProducts(): array
    {
        $products = [];
        $filter = ['IBLOCK_ID' => $this->catalogIblockId];
        $select = ['ID', 'XML_ID', 'NAME', 'IBLOCK_SECTION_ID'];
        $dbl = CIBlockElement::GetList(["ID" => "ASC"], $filter, false, ["nPageSize" => 100000], $select);
        while ($arItem = $dbl->Fetch()) {
            $products[$arItem['XML_ID']] = $arItem;
        }

        return $products;
    }

    private function isProductSectionActive(int $sectionId = 0): bool
    {
        $sql = 'SELECT ID, ACTIVE FROM b_iblock_section WHERE ID = '.$sectionId;
        $res = $this->connection->query($sql);
        if ($section = $res->fetch()) {
            if ($section['ACTIVE'] == 'Y') {
                return true;
            }
        }

        return false;
    }

    private function getSectionUrl(int $sectionId = 0): string
    {
        $sectionUrl = '';
        $filter = ['IBLOCK_ID' => $this->catalogIblockId, "ID" => $sectionId];
        $sectionRes = CIBlockSection::GetList(['left_margin' => 'asc'], $filter, false, ['ID', 'SECTION_PAGE_URL']);
        while ($section = $sectionRes->GetNext()) {
            $sectionUrl = $section['SECTION_PAGE_URL'];
        }

        return $sectionUrl;
    }
}

(new SetRedirects())->StartWorker();