<?
use ImarketHeplers\ImarketLogger,
    Bitrix\Main\Config\Option;
if (!class_exists("ImarketHeplers\ImarketLogger")) {
    require($_SERVER['DOCUMENT_ROOT'] . '/local/classes/ImarketLogger.php');
}

/**
 * Class catalogAppAPI
 */
class catalogAppAPI {
    private $authLogin = '';
    private $authPass = '';
    private $authToken = '';
    private $catalogID = 0;
    private $loggerLogPath = '/upload/log/catalogAppApi/';
    private $lastError = '';
    private $Logger;

    /**
     * catalogAppAPI constructor.
     * @param array $options
     */
    public function __construct ($options = []) {
        global $DB;

        if (empty($options)) {
            $options['authLogin'] = '';
            $options['authPass'] = '';
            $options['authUrl'] = '';
        }

        $arData["CATALOG_APP_USER"] = Option::get("imarket.catalog_app", "CATALOG_APP_USER");
        $arData["CATALOG_APP_PASSWORD"] = Option::get("imarket.catalog_app", "CATALOG_APP_PASSWORD");
        $arData["CATALOG_APP_CATALOG_ID"] = Option::get("imarket.catalog_app", "CATALOG_APP_CATALOG_ID");

        if (!empty($arData)) {
            $this->authLogin = $arData["CATALOG_APP_USER"];
            $this->authPass = $arData["CATALOG_APP_PASSWORD"];
            $this->catalogID = $arData["CATALOG_APP_CATALOG_ID"];
        }

        $this->Logger = new ImarketLogger($this->loggerLogPath);
        $this->authorize($options['authLogin'], $options['authPass'], $options['authUrl']);
    }

    #Authorization#
    /**
     * @param string $login
     * @param string $pass
     * @param string $authUrl
     * @return string
     */
    private function authorize(string $login = '', string $pass = '', string $authUrl = '') {
        $this->Logger->log("LOG", 'Try authorize');
        if (!empty($login)) {
            $this->authLogin = $login;
        }

        if (!empty($pass)) {
            $this->authPass = $pass;
        }

        $url = empty($authUrl) ? 'https://catalog.app/api/authorization' : $authUrl ;
        $data = [
            'login' => $this->authLogin,
            'password' => $this->authPass
        ];

        $result = $this->request($url, $data, 'post');
        $pr = $result;

        if (!empty($result["ERROR"])) {
            $this->Logger->log("ERROR", "Error: \r\n".print_r($result["ERROR"], true));
            return $this->Logger->lastError;
        }

        $result = json_decode($result, true);

        if (empty($result['token'])) {
            $this->Logger->log("ERROR", 'Authorization fault');
            return $this->Logger->lastError;
        } else {
            $this->authToken = $result['token'];
        }

        $this->Logger->log("LOG", 'Authorization success '.print_r($pr, true));
        return $this->authToken;
    }

    #Catalog#
    /**
     * @param int $catalogId
     * @return mixed|string
     */
    public function getCatalogs($catalogId = 0) {
        $this->Logger->log("LOG", 'Try get catalogs');

        if (!empty($catalogId)) {
            $url = 'https://catalog.app/api/catalogs/'.$catalogId;
        } else {
            $url = 'https://catalog.app/api/catalogs/';
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrCatalogs = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrCatalogs));
        return $arrCatalogs;
    }

    public function getLastTimeOnMarketProducts($offset = 0, $limit = 1000)
    {
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models/last-time-on-market';
        $urlSymble = '?';

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }

    #Categories#
    /**
     * @param int $catalogId
     * @param int $externalId
     * @param int $offset
     * @param int $limit
     * @return mixed|string
     */
    public function getCategories($catalogId = 0, $externalId = 0, $offset = 0, $limit = 100, $id = 0) {
        $this->Logger->log("LOG", 'Try get categories');

        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/categories';

        if (!empty($id)) {
            $url .= '/'.$id;
        } else {
            $urlSymble = '?';

            if (!empty($externalId) && empty($id)) {
                $url .= $urlSymble.'externalId='.$externalId;
                $urlSymble = '&';
            }

            if (!empty($offset) && empty($id)) {
                $url .= $urlSymble.'offset='.$offset;
                $urlSymble = '&';
            }

            if (!empty($limit) && empty($id)) {
                $url .= $urlSymble.'limit='.$limit;
            }
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrCategories = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrCategories;
    }

    /**
     * @param int $catalogId
     * @param int $categoryId
     * @return bool|mixed|string
     */
    public function getCategoryById($catalogId = 0, $categoryId = 0, $includeParentProperties = true) {
        $this->Logger->log("LOG", 'Try get category by id');
        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        if (empty($categoryId)) {
            $this->Logger->log("ERROR", 'Empty category id');
            return false;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/categories/'.$categoryId;

        if ($includeParentProperties) {
            $url .= '?includeParentProperties=true';
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrCategory = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrCategory));
        return $arrCategory;
    }

    #ExportAttributes#
    public function GetExportAttributes($catalogId = 0, $profileId = 0, $offset = 0, $limit = 100) {
        $this->Logger->log("LOG", 'Try get attributes');
        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        if (empty($profileId)) {
            $this->Logger->log("ERROR", 'Empty profile Id');
            return false;
        }

        $arrAttributes = [];
        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/pricing-profiles/'.$profileId.'/export-attributes';
        $urlSymble = '?';

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrAttributes = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrAttributes));
        return $arrAttributes;
    }

    #ImportTasks#
    public function getImportTaskInfo($id = 0) {
        $this->Logger->log("LOG", "Get task info");

        if (empty($id)) {
            $this->Logger->log("ERROR", "No task id");
        }

        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/tasks/import/'.$id;
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrTaskInfo = json_decode($result, true);

        $this->Logger->log("LOG", 'Info successfully getted');
        return $arrTaskInfo;
    }

    public function getPricingTaskInfo($id = 0) {
        $this->Logger->log("LOG", "Get task info");

        if (empty($id)) {
            $this->Logger->log("ERROR", "No task id");
        }

        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/tasks/pricing/'.$id;
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrTaskInfo = json_decode($result, true);

        $this->Logger->log("LOG", 'Info successfully getted');
        return $arrTaskInfo;
    }

    #Installments#
    public function getInstallments($catalogId = 0, $profileId = 0) {
        $this->Logger->log("LOG", "Try get installments");

        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        if (empty($profileId)) {
            $this->Logger->log("ERROR", "Not set profile id");
            return false;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/pricing-profiles/'.$profileId.'/installments';
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrData = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrData));
        return $arrData;
    }

    #Pricing profiles#
    public function getPricingProfiles ($catalogId = 0) {
        $this->Logger->log("LOG", "Try get pricing profiles");

        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/pricing-profiles';
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrPricingProfiles = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrPricingProfiles));
        return $arrPricingProfiles;
    }

    #Profile profiles#
    public function getProfilePrices ($catalogId = 0, $profileId = 0, $offset = 0, $limit = 100, $lastCatalogAppId = 0) {
        $this->Logger->log("LOG", "Try get pricing profiles prices");

        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        if (empty($profileId)) {
            $this->Logger->log("ERROR", "Variable 'profileId' is empty");
            return false;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/pricing-profiles/'.$profileId.'/prices';
        $urlSymble = '?';

        if (!empty($offset) && empty($lastCatalogAppId)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
            $urlSymble = '&';
        }

        if (!empty($lastCatalogAppId)) {
            $url .= $urlSymble.'startId='.$lastCatalogAppId;
        }

        $this->Logger->log("LOG", 'Requested URL '.$url);

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrProfilePrices = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrProfilePrices));
        return $arrProfilePrices;
    }

    #Property Units#
    public function getPropertyUnits() {
        $url = 'https://catalog.app/api/catalogs/units?responseCulture=Russian';
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrData = json_decode($result, true);
        return $arrData;
    }

    #Supplier prices#
    /**
     * @param int $catalogID
     * @return mixed|string
     */
    public function getSuppliers ($catalogID = 0, $offset = 0, $limit = 1000, $id = 0) {
        $this->Logger->log("LOG", 'Try get suppliers list');
        if (!empty($catalogID)) {
            $this->catalogID = $catalogID;
        }

        if (!empty($id)) {
            $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/'.$id;
        } else {
            $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/suppliers?offset='.$offset.'&limit='.$limit;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrSuppliers = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrSuppliers));
        return $arrSuppliers;
    }

    /**
     * @param $supplierId
     * @return mixed|string
     */
    public function getSuppliersGoods($catalogID = 0, $offset = 0, $limit = 1000, $id = 0) {
        $this->Logger->log("LOG", 'Try get suppliers goods list');

        $url = 'https://catalog.app/api/catalogs/' . $this->catalogID . '/suppliers/' . $id . '/prices?offset='.$offset.'&limit='.$limit;
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            $this->Logger->log("ERROR", 'Error gettin suppliers goods '.print_r($result["ERROR"], true));
            return $this->Logger->lastError;
        }

        $arrSuppliersPrices = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted, total count '.count($arrSuppliersPrices));
        return $arrSuppliersPrices;
    }

    #Models#
    /**
     * @param int $id
     * @param string $query
     * @param int $offset
     * @param int $limit
     * @return mixed|string
     */
    public function getModels($id = 0, $query = '', $offset = 0, $limit = 360, $lastCatalogAppId = 0) {
        $this->Logger->log("LOG", 'Try get models');
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models';
        $urlSymble = '?';

        if (!empty($id)) {
            $url .= '/'.$id;
        }

        if (!empty($query) && empty($id)) {
            $url .= $urlSymble.'query='.$query;
            $urlSymble = '&';
        }

        if (!empty($offset) && empty($id) && empty($lastCatalogAppId)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit) && (empty($id))) {
            $url .= $urlSymble.'limit='.$limit;
            $urlSymble = '&';
        }

        if (!empty($lastCatalogAppId)) {
            $url .= $urlSymble.'startId='.$lastCatalogAppId;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }


    public function getModified ($time = 0, $offset = 0, $limit = 100) {
        $this->Logger->log("LOG", 'Try get modified models');
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models/modified';
        $urlSymble = '?';

        if (!empty($time)) {
            $url .= $urlSymble."fromUtc=".$time;
            $urlSymble = '&';
        }

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $this->Logger->log("LOG", 'Requested URL '.$url);

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }

    public function getCartModified ($time = 0, $offset = 0, $limit = 100) {
        $this->Logger->log("LOG", 'Try get modified models');
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models/card-modified';
        $urlSymble = '?';

        if (!empty($time)) {
            $url .= $urlSymble."fromUtc=".$time;
            $urlSymble = '&';
        }

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $this->Logger->log("LOG", 'Requested URL '.$url);

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }

    /**
     * @param int $categotyId
     * @param int $offset
     * @param int $limit
     * @return mixed|string
     */
    public function getModelsByCategory($categotyId = 0, $offset = 0, $limit = 100) {
        $this->Logger->log("LOG", 'Try get models');
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/categories/'.$categotyId.'/models';
        $urlSymble = '?';

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }

    /**
     * @param int $time
     * @param int $offset
     * @param int $limit
     * @return mixed|string
     */
    public function getDeletedModels ($time = 0, $offset = 0, $limit = 100) {
        $this->Logger->log("LOG", 'Try get deleted models');
        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models/deleted';
        $urlSymble = '?';

        if (!empty($time)) {
            $url .= $urlSymble."fromUtc=".$time;
            $urlSymble = '&';
        }

        if (!empty($offset)) {
            $url .= $urlSymble.'offset='.$offset;
            $urlSymble = '&';
        }

        if (!empty($limit)) {
            $url .= $urlSymble.'limit='.$limit;
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->lastError;
        }

        $arrModels = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrModels;
    }


    #Vendors#
    /**
     * @param int $catalogId
     * @param int $externalId
     * @param int $offset
     * @param int $limit
     * @return mixed|string
     */
    public function getVendors($catalogId = 0, $externalId = 0, $offset = 0, $limit = 100, $id = 0) {
        $this->Logger->log("LOG", 'Try get vendors');

        if (empty($catalogId)) {
            $catalogId = $this->catalogID;
        }

        $url = 'https://catalog.app/api/catalogs/'.$catalogId.'/vendors';

        if (!empty($id)) {
            $url .= '/'.$id;
        } else {
            $urlSymble = '?';

            if (!empty($externalId) && empty($id)) {
                $url .= $urlSymble.'externalId='.$externalId;
                $urlSymble = '&';
            }

            if (!empty($offset) && empty($id)) {
                $url .= $urlSymble.'offset='.$offset;
                $urlSymble = '&';
            }

            if (!empty($limit) && empty($id)) {
                $url .= $urlSymble.'limit='.$limit;
            }
        }

        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $arrCategories = json_decode($result, true);

        $this->Logger->log("LOG", 'List successfully getted');
        return $arrCategories;
    }

    /**
     *
     * @param string $id
     * @return mixed
     */
    public function getProductProps($id = 0) {
        $this->Logger->log("LOG", 'Try get product properties');

        if (empty($id)) {
            $this->Logger->log("ERROR", 'Empty product ID');
            return $this->Logger->lastError;
        }

        $url = 'https://catalog.app/api/catalogs/'.$this->catalogID.'/models/' . $id . '/details';
        $result = $this->request($url);

        if (!empty($result["ERROR"])) {
            return $this->Logger->lastError;
        }

        $this->Logger->log("LOG", 'List successfully getted');
        return json_decode($result, true);
    }

    #Main request#
    /**
     * @param string $url
     * @param array $req
     * @return bool|string
     */
    private function request ($url = '', $req = [], $method = 'get') {
        if (empty($url)) {
            $this->Logger->log("ERROR", "Empty request url");
            return $this->Logger->lastError;
        }

        $headers = ['accept: text/plain'];

        if ($method == 'post') {
            $headers[] = 'Content-Type: application/json';
        }

        if (!empty($this->authToken)) {
            $headers[] = 'Authorization: Bearer '.$this->authToken;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);

        if ($method == 'post') {
            curl_setopt($ch, CURLOPT_POST, 1);
        }

        if (!empty($req)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        $return = curl_exec($ch);
        $info = curl_getinfo($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $header = substr($return, 0, $header_size);
        $body = substr($return, $header_size);

        if ($info['http_code'] != 200) {
            $this->Logger->log("ERROR", 'CURL request error, status '.$info['http_code']." ".$body."\r\n".curl_error($ch));
            return $this->Logger->lastError;
        }

        return $body;
    }
}