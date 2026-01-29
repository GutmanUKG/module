# Инструкция по внедрению: Расчёт сроков доставки

## Обзор доработки

Модуль `imarket.catalog_app` дополнен функционалом автоматического расчёта и отображения сроков доставки в корзине при оформлении заказа. Данные о сроках берутся из Catalog.app (поле `DELIVERY_TIME` в таблице `catalog_app_data`) и модифицируются в зависимости от города и типа доставки.

### Правила расчёта

| Город   | Тип доставки | Формула                            | Пример (3 дня из catalog.app)  |
|---------|--------------|------------------------------------|-------------------------------|
| Алматы  | Курьер       | catalog.app + 1 день, диапазон     | «4-5 дней»                    |
| Алматы  | Самовывоз    | catalog.app без изменений          | «3 дня»                       |
| Астана  | Курьер       | catalog.app + 1 день, диапазон     | «4-5 дней»                    |
| Астана  | Самовывоз    | catalog.app + 1-2 дня, диапазон    | «4-5 дней»                    |

Если срок = 0 или данные отсутствуют — выводится «Срок доставки уточняется».

---

## Предварительные требования

1. Модуль `imarket.catalog_app` установлен и настроен
2. Таблица `catalog_app_data` заполнена (воркеры синхронизации работают)
3. Поле `DELIVERY_TIME` содержит данные из Catalog.app (количество дней)

---

## Шаг 1. Файлы модуля

Убедитесь, что в модуле `imarket.catalog_app` присутствуют следующие файлы:

### `services/DeliveryTimeService.php`
Сервис расчёта и форматирования сроков доставки. Содержит:
- `formatDeliveryTime(int $days, string $city, string $type): string` — основной метод
- `getDeliveryTime(int $productId, string $city, string $type): string` — получение и форматирование по ID товара
- Автоматическое склонение слова «день/дня/дней»

### `classes/DeliveryEventHandler.php`
Обработчик события `onSaleDeliveryServiceCalculate`. Содержит:
- Получение `CalculationResult` из параметров события и его прямая модификация
- Определение города из местоположения заказа (Bitrix Sale Location)
- Определение типа доставки (курьер/самовывоз) по ID службы доставки
- Получение максимального срока доставки среди товаров отгрузки (shipment)

### `include.php`
Автозагрузка классов и регистрация обработчика события.

---

## Шаг 2. Регистрация обработчика события

Обработчик можно зарегистрировать **двумя способами** (выберите один).

### Способ А. Через `init.php` сайта (рекомендуемый)

Этот способ позволяет управлять событиями на уровне сайта без изменения модуля.

Добавьте в файл `/local/php_interface/init.php`:

```php
// Подключение модуля imarket.catalog_app для расчёта сроков доставки
\Bitrix\Main\Loader::includeModule('imarket.catalog_app');

$eventManager = \Bitrix\Main\EventManager::getInstance();
$eventManager->addEventHandler(
    'sale',
    'onSaleDeliveryServiceCalculate',
    function (\Bitrix\Main\Event $event) {
        return \DeliveryEventHandler::onDeliveryCalculate($event);
    }
);
```

При этом способе **уберите** строку `DeliveryEventHandler::register();` из `include.php` модуля, чтобы не было двойной регистрации:

```php
// include.php модуля — убрать или закомментировать:
// DeliveryEventHandler::register();
```

### Способ Б. Через `include.php` модуля

Раскомментируйте строку в `include.php` модуля:

```php
// Было (закомментировано):
// DeliveryEventHandler::register();

// Стало:
DeliveryEventHandler::register();
```

Обработчик будет работать автоматически при подключении модуля. Модуль должен быть установлен и включён в Битрикс.

---

## Шаг 3. Настройка маппинга городов

В файле `classes/DeliveryEventHandler.php`, метод `getCityFromLocation()` (строка ~113), находится маппинг кодов местоположений Битрикс на города.

**Что нужно сделать:**

1. Откройте админку Битрикс: **Магазин → Местоположения**
2. Найдите местоположения для Алматы и Астаны
3. Скопируйте их коды (`CODE` или `XML_ID`)
4. Обновите маппинг в методе `getCityFromLocation()`:

```php
private static function getCityFromLocation(string $locationCode): string
{
    $locationCode = strtoupper($locationCode);

    // Замените на реальные коды из вашей установки Битрикс
    $cityMap = [
        // Примеры кодов — замените на ваши:
        '0000073738' => 'almaty',   // код Алматы в вашей БД
        '0000611011' => 'astana',   // код Астаны в вашей БД
        'ALMATY'     => 'almaty',
        'АСТАНА'     => 'astana',
        'NUR-SULTAN' => 'astana',
    ];

    // Точное совпадение
    if (isset($cityMap[$locationCode])) {
        return $cityMap[$locationCode];
    }

    // Поиск по вхождению (для названий)
    foreach ($cityMap as $key => $city) {
        if (strpos($locationCode, $key) !== false) {
            return $city;
        }
    }

    return 'almaty'; // по умолчанию
}
```

**Как узнать код местоположения:**

```sql
SELECT CODE, CITY_ID, TYPE_ID
FROM b_sale_location
WHERE NAME_RU LIKE '%Алматы%' OR NAME_RU LIKE '%Астана%';
```

Или через PHP:

```php
$res = \Bitrix\Sale\Location\LocationTable::getList([
    'filter' => ['=NAME.NAME' => 'Алматы', '=NAME.LANGUAGE_ID' => 'ru'],
    'select' => ['CODE', 'ID']
]);
while ($loc = $res->fetch()) {
    var_dump($loc); // покажет CODE
}
```

---

## Шаг 4. Настройка определения типа доставки

В файле `classes/DeliveryEventHandler.php`, метод `getDeliveryType()` (строка ~152), тип доставки определяется по **ID службы доставки**.

**Что нужно сделать:**

1. Откройте админку Битрикс: **Магазин → Службы доставки**
2. Найдите ID служб самовывоза
3. Обновите массив `$pickupServiceIds` в методе `getDeliveryType()`:

```php
private static function getDeliveryType(int $deliveryId): string
{
    // Укажите реальные ID служб самовывоза из Битрикс
    $pickupServiceIds = [
        2,  // замените на ID вашей службы "Самовывоз"
    ];

    if (in_array($deliveryId, $pickupServiceIds, true)) {
        return 'pickup';
    }

    return 'courier';
}
```

Все остальные службы считаются курьерскими по умолчанию.

---

## Шаг 5. Как работает установка срока доставки

Обработчик получает объект `\Bitrix\Sale\Delivery\CalculationResult` из параметров события и **модифицирует его напрямую** — устанавливает поля «Срок доставки (дней)»:

```php
// Получаем CalculationResult из параметров события
$result = $parameters['RESULT'];

// Устанавливаем срок доставки напрямую
$result->setPeriodFrom($periodData['from']);   // Минимум дней
$result->setPeriodTo($periodData['to']);       // Максимум дней
$result->setPeriodType('D');                   // D = дни
$result->setPeriodDescription($formattedTime); // Текст: "4-5 дней"
```

Это стандартный механизм Битрикс — методы `setPeriodFrom()` / `setPeriodTo()` устанавливают значения в поле **«Срок доставки (дней)»** обработчика доставки. Никакой дополнительной настройки не требуется — значения подставляются автоматически.

---

## Шаг 6. Проверка работоспособности

### 6.1. Проверка данных в БД

Убедитесь, что `DELIVERY_TIME` заполнен:

```sql
SELECT GOODS_SITE_ID, DELIVERY_TIME, DELIVERY_COUNTRY_TIME
FROM catalog_app_data
WHERE DELIVERY_TIME > 0
LIMIT 10;
```

### 6.2. Проверка через PHP

```php
// Тест в консоли или в отдельном файле
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('imarket.catalog_app');

$service = new DeliveryTimeService();

// Алматы, курьер, 3 дня из catalog.app → "4-5 дней"
echo $service->formatDeliveryTime(3, 'almaty', 'courier') . "\n";

// Алматы, самовывоз, 3 дня → "3 дня"
echo $service->formatDeliveryTime(3, 'almaty', 'pickup') . "\n";

// Астана, самовывоз, 3 дня → "4-5 дней"
echo $service->formatDeliveryTime(3, 'astana', 'pickup') . "\n";

// Астана, курьер, 1 день → "2-3 дня"
echo $service->formatDeliveryTime(1, 'astana', 'courier') . "\n";
```

### 6.3. Проверка в корзине

1. Добавьте товар в корзину
2. Перейдите к оформлению заказа
3. Выберите город (Алматы или Астана)
4. Выберите способ доставки (курьер или самовывоз)
5. Убедитесь, что отображается рассчитанный срок

---

## Шаг 7. Источник данных

Данные `DELIVERY_TIME` синхронизируются из Catalog.app автоматически воркерами модуля:

- **CreateWorker** — при создании товаров записывает `DELIVERY_TIME`
- **UpdatePriceWorker** — при обновлении цен обновляет `DELIVERY_TIME`

Поле в API Catalog.app: `deliveryTime` (количество дней).

Таблица: `catalog_app_data`, колонка `DELIVERY_TIME` (INT, дни).

---

## Краткая сводка

| Что                    | Где                                                  |
|------------------------|------------------------------------------------------|
| Сервис расчёта сроков  | `services/DeliveryTimeService.php`                   |
| Обработчик события     | `classes/DeliveryEventHandler.php`                   |
| Автозагрузка классов   | `include.php`                                        |
| Регистрация события    | `init.php` (рекомендуется) или `include.php` модуля  |
| Маппинг городов        | `DeliveryEventHandler::getCityFromLocation()`        |
| Маппинг типов доставки | `DeliveryEventHandler::getDeliveryType()`            |
| Данные о сроках        | Таблица `catalog_app_data`, поле `DELIVERY_TIME`     |
| Событие Битрикс        | `onSaleDeliveryServiceCalculate` (модуль `sale`)     |

---

## Возможные проблемы

| Проблема                                      | Решение                                                              |
|-----------------------------------------------|----------------------------------------------------------------------|
| Срок не отображается                          | Проверьте, что `DELIVERY_TIME > 0` в `catalog_app_data`             |
| Город определяется неверно                    | Обновите маппинг в `getCityFromLocation()` реальными кодами из БД   |
| Тип доставки определяется неверно             | Укажите ID служб в `getDeliveryType()` вместо поиска по ключевым словам |
| Обработчик не срабатывает                     | Проверьте, что модуль `imarket.catalog_app` подключён и `sale` установлен |
| Двойная регистрация обработчика               | Используйте только один способ: `init.php` ИЛИ `include.php`       |
| «Срок доставки уточняется» для всех товаров   | Проверьте SQL-запрос — возможно `GOODS_SITE_ID` не совпадает с ID товаров в корзине |
