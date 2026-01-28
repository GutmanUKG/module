<?php

use Bitrix\Main\Config\Option;
use Shuchkin\SimpleXLSX;

class RedirectService
{
    private const PRODUCT_FILE_PATH = '/upload/all_catalog_app_products.xlsx';
    private array $redirectProperty = [
        "NAME" => "Ссылка редиректа",
        "ACTIVE" => "Y",
        "SORT" => "1000",
        "CODE" => "REDIRECT_LINK",
        "PROPERTY_TYPE" => "S",
        "IBLOCK_ID" => 0
    ];
    private catalogAppAPI $catalogAppAPI;

    public function __construct () {
        $this->redirectProperty['IBLOCK_ID'] = Option::get("imarket.catalog_app", "CATALOG_IBLOCK_ID");
        $this->catalogAppAPI = new catalogAppAPI();
    }

    public function getLastMarketProductFromCatalogApp()
    {
        $offset = 0;
        $iteration = 0;
        $limit = 10000;
        $catalogAppLastMarketModel = [];

        do {
            $profileRequestResult = $this->catalogAppAPI->getLastTimeOnMarketProducts($offset, $limit);

            if (!empty($profileRequestResult)) {
                if (!empty($catalogAppLastMarketModel)) {
                    $catalogAppLastMarketModel = array_merge($catalogAppLastMarketModel, $profileRequestResult);
                } else {
                    $catalogAppLastMarketModel = $profileRequestResult;
                }
            }

            $iteration++;
            if ($offset == 0) {
                $offset = $limit;
            } else {
                $offset = $limit * $iteration;
            }
        } while (!empty($profileRequestResult) && is_array($profileRequestResult));

        return $catalogAppLastMarketModel;
    }

    public function getProductsFromFile(): array
    {
        $products = [];
        if ($xlsx = SimpleXLSX::parse($_SERVER['DOCUMENT_ROOT'].self::PRODUCT_FILE_PATH)) {
            foreach ($xlsx->rows() as $row) {
                $xmlId = empty($row[1]) ? $row[0] : $row[1];
                $products[$xmlId] = [
                    'id' => $row[0],
                    'xmlId' => $row[1],
                    'lastView' => $row[21],
                ];
            }
        } else {
            echo SimpleXLSX::parseError();
        }

        return $products;
    }

    public function setProductRedirect(int $productId, string $redirectString = ''): mixed
    {
        if (!$this->isPropertyExists()) {
            $this->CreateProperty($this->redirectProperty);
        }

        $arLoadProductArray["PROPERTY_VALUES"] = [$this->redirectProperty['CODE'] => $redirectString];

        $el = new CIBlockElement;
        return $el->Update($productId, $arLoadProductArray);
    }

    private function isPropertyExists(): bool
    {
        $properties = CIBlockProperty::GetList(["name" => "asc"], ["IBLOCK_ID" => $this->redirectProperty['IBLOCK_ID'], "CODE" => $this->redirectProperty['CODE']]);
        if(!$arProp = $properties->GetNext()) {
            return false;
        } else {
            return true;
        }
    }

    private function createProperty(array $property): void
    {
        $iblockProperty = new CIBlockProperty;

        if ($propertyID = $iblockProperty->Add($property)) {
            \Bitrix\Iblock\Model\PropertyFeature::setFeatures($propertyID, [
                ["FEATURE_ID"=>"DETAIL_PAGE_SHOW", "IS_ENABLED" => "N", "MODULE_ID" => "iblock"],
                ["FEATURE_ID"=>"LIST_PAGE_SHOW", "IS_ENABLED" => "N", "MODULE_ID" => "iblock"]
            ]);
        }
    }
}