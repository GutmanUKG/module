<? use Bitrix\Main\Application;

class ImarketSectionsConnections {
    protected $table = 'catalog_app_section_connections';

    /**
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * все сопоставления из таблицы
     * вернет связку id категории catalogApp c категорией Imarket.by - если таковая есть
     */
    public function getAll () {
        $sql = 'SELECT * FROM ' . $this->table." ORDER BY CATALOG_APP_SECTION_ID DESC";
        $dbl = Application::getInstance()->getConnection()->query($sql);
        while ($res = $dbl->fetch()) {
            $siteSectionId = $res["SITE_SECTION_ID"];
            $catalogAppSectionId = $res["CATALOG_APP_SECTION_ID"];

            if ($siteSectionId > 0 && $catalogAppSectionId > 0) {
                $out[$catalogAppSectionId] = $siteSectionId;
            }
        }

        return isset($out) && count($out) > 0 ? $out : false;
    }

    /**
     * @return array|null
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * получить данные о связях, ключ - id каталог апп
     */
    public function getCatalogAppId () {
        $sql = 'SELECT * FROM ' . $this->table;
        $dbl = Application::getInstance()->getConnection()->query($sql);
        $out = [];
        while ($res = $dbl->fetch()) {
            $catalogAppSectionId = $res['CATALOG_APP_SECTION_ID'];
            if (!empty($catalogAppSectionId)) {
                $out[$catalogAppSectionId] = $res;
            }
        }

        return $out;
    }

    /**
     * @param array $data
     * @return null
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * обновление связки
     */
    public function updateConnections ($data = []) {
        if (!is_array($data) || count($data) == 0) {
            return null;
        }

        $delete = [];
        foreach ($data as $id => $values) {
            if (empty($values['catalogAppSectionId'])) {
                continue;
            }

            $delete[$values['catalogAppSectionId']] = $values['catalogAppSectionId'];
        }

        if (!is_array($delete) || count($delete) == 0) {
            return null;
        }

        $sql = 'DELETE FROM ' . $this->table . ' WHERE CATALOG_APP_SECTION_ID IN (' . implode(',', $delete) . ')';
        Application::getInstance()->getConnection()->query($sql);

        $insert = 'INSERT INTO ' . $this->table . ' (`CATALOG_APP_SECTION_ID`, `SITE_SECTION_ID`, `SITE_SECTION_NAME`, `CATALOG_APP_SECTION_ID`) VALUES ';
        $fields = [];
        foreach ($data as $id => $item) {
            $fields[] = '(' . ImarketEscape($item['name']) . ','
                . intVal($item['imarketSectionId']) . ','
                . ImarketEscape($item['imarketSectionName']) . ','
                . intVal($item['catalogAppSectionId']) . ')';
        }

        if (!is_array($fields) || count($fields) == 0) {
            return null;
        }

        $insert .= implode(',', $fields);
        Application::getInstance()->getConnection()->query($insert);
    }

    /**
     * @param int $categoryId
     * @param int $imarketCategoryId
     * @return null
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * обновить аустые связи
     */
    public function updateEmptyConnection ($categoryId = 0, $imarketCategoryId = 0) {
        $categoryId = intVal($categoryId);
        $imarketCategoryId = intVal($imarketCategoryId);

        if ($categoryId == 0 || $imarketCategoryId == 0) {
            return null;
        }

        $sql = 'SELECT 1 FROM ' . $this->table . ' WHERE CATALOG_APP_SECTION_ID = ' . $categoryId;
        $dbl = Application::getInstance()->getConnection()->query($sql);

        if ($dbl->getSelectedRowsCount() == 0) {
            return null;
        }

        $sql = 'UPDATE ' . $this->table . ' SET SITE_SECTION_ID = ' . $imarketCategoryId . ' 
                    WHERE CATALOG_APP_SECTION_ID = ' . $categoryId;
        Application::getInstance()->getConnection()->query($sql);
    }

    /**
     * @param array $data
     * @return null
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * добавить связку
     */
    public function create ($data = []) {
        if (!is_array($data) || count($data) == 0) {
            return null;
        }

        $insert = 'INSERT INTO ' . $this->table . ' (`CATALOG_APP_SECTION_NAME`, `CATALOG_APP_SECTION_ID`) VALUES ';
        $fields = [];
        foreach ($data as $item) {
            $fields[] = '(' . ImarketEscape($item['name']) . ',' . intVal($item['sectionId']) . ')';
        }

        if (!is_array($fields) || count($fields) == 0) {
            return null;
        }

        $insert .= implode(',', $fields);
        Application::getInstance()->getConnection()->query($insert);
    }

    /**
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * обновить таблицу
     */
    public function refreshTable () {
        $sql = 'ALTER TABLE ' . $this->table . ' DROP ID';
        Application::getInstance()->getConnection()->query($sql);

        $sql = 'ALTER TABLE ' . $this->table . ' ADD ID BIGINT( 200 ) NOT NULL AUTO_INCREMENT FIRST, ADD PRIMARY KEY (ID)';
        Application::getInstance()->getConnection()->query($sql);
    }

    /**
     * @param array $newSections
     * @return bool
     * @throws \Bitrix\Main\Db\SqlQueryException
     * @throws \Bitrix\Main\SystemException
     *
     * newSections - массив, в виде Имя 1С категории - id категории сайта ['Компьютеры' => 1333]
     */
    public function insertConnection ($newSections = []) {
        if (count($newSections) == 0) {
            return false;
        }

        $isset = $this->getAll();

        foreach ($newSections as $name => $id) {
            $insName = addslashes(trim($name));
            $insId = intVal($id);

            if (!array_key_exists($insName, $isset) && !in_array($insId, $isset)) {
                if (strlen($insName) > 1 && $insId > 0) {
                    $insert[] = '("' . $insName . '",' . $insId . ')';
                }
            }
        }

        if (isset($insert) && is_array($insert) && count($insert) > 0) {
            $sqlStr = 'INSERT INTO ' . $this->table . ' (`CATALOG_APP_SECTION_NAME`, `SITE_SECTION_ID`, `CATALOG_APP_SECTION_ID`) VALUES ';
            $sqlStr .= implode(',', $insert);
            Application::getInstance()->getConnection()->query($sqlStr);
        }

        return true;
    }

    public function deleteRows($arIds = []) {
        if (empty($arIds)) {
            return false;
        }

        $chunks = array_chunk($arIds, 5000);
        foreach ($chunks as $chunk) {
            $sql = "DELETE FROM catalog_app_section_connections WHERE SITE_SECTION_ID IN (".implode(",", $chunk).")";
            Application::getInstance()->getConnection()->query($sql);
        }

        return true;
    }
}