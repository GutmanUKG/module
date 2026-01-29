<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Обработчик событий для модификации сроков доставки
 *
 * Перехватывает событие расчёта доставки и устанавливает
 * срок доставки (PERIOD_FROM / PERIOD_TO) в CalculationResult.
 */
class DeliveryEventHandler
{
    /**
     * Регистрация обработчиков событий
     */
    public static function register(): void
    {
        $eventManager = EventManager::getInstance();

        $eventManager->addEventHandler(
            'sale',
            'onSaleDeliveryServiceCalculate',
            [self::class, 'onDeliveryCalculate']
        );
    }

    /**
     * Обработчик события расчёта доставки
     *
     * Модифицирует CalculationResult, устанавливая срок доставки
     * на основе данных из catalog_app_data.
     */
    public static function onDeliveryCalculate(Event $event): ?EventResult
    {
        $parameters = $event->getParameters();

        /** @var \Bitrix\Sale\Delivery\CalculationResult|null $result */
        $result = $parameters['RESULT'] ?? null;
        /** @var \Bitrix\Sale\Shipment|null $shipment */
        $shipment = $parameters['SHIPMENT'] ?? null;
        $deliveryId = $parameters['DELIVERY_ID'] ?? 0;

        if (!$result || !$shipment || !$deliveryId) {
            return null;
        }

        try {
            // Получаем ID товаров из отгрузки (не из всей корзины)
            $productIds = [];
            $shipmentItemCollection = $shipment->getShipmentItemCollection();

            foreach ($shipmentItemCollection as $shipmentItem) {
                $basketItem = $shipmentItem->getBasketItem();
                if ($basketItem) {
                    $productIds[] = $basketItem->getProductId();
                }
            }

            if (empty($productIds)) {
                return null;
            }

            // Определяем город из местоположения заказа
            $order = $shipment->getCollection()->getOrder();
            $propertyCollection = $order->getPropertyCollection();
            $locationProperty = $propertyCollection->getDeliveryLocation();
            $locationCode = $locationProperty ? $locationProperty->getValue() : '';

            if (empty($locationCode)) {
                return null;
            }

            $city = self::getCityFromLocation($locationCode);
            $deliveryType = self::getDeliveryType($deliveryId);

            // Берём максимальный срок из всех товаров отгрузки
            $maxDays = 0;
            foreach ($productIds as $productId) {
                $days = self::getProductDeliveryDays($productId);
                if ($days > $maxDays) {
                    $maxDays = $days;
                }
            }

            if ($maxDays <= 0) {
                $result->setPeriodDescription('???? ???????? ??????????');
                return null;
            }

            // Рассчитываем период и форматированный текст
            $periodData = self::calculatePeriod($maxDays, $city, $deliveryType);

            $service = new \DeliveryTimeService();
            $formattedTime = $service->formatDeliveryTime($maxDays, $city, $deliveryType);

            // Устанавливаем срок доставки напрямую в CalculationResult
            $result->setPeriodFrom($periodData['from']);
            $result->setPeriodTo($periodData['to']);
            $result->setPeriodType('D');
            $result->setPeriodDescription($formattedTime);

        } catch (\Exception $e) {

            // Не прерываем расчёт доставки при ошибке
        }

        return null;
    }

    /**
     * Определить город по коду местоположения Битрикс
     *
     * @param string $locationCode — код из b_sale_location.CODE
     * @return string — almaty|astana
     */
    private static function getCityFromLocation(string $locationCode): string
    {
        $locationCode = strtoupper($locationCode);

        // Маппинг кодов местоположений (адаптировать под реальные коды сайта)
        // Узнать коды: SELECT CODE FROM b_sale_location WHERE NAME_RU LIKE '%Алматы%'
        $cityMap = [
            '0000000278' => 'almaty',
            '0000000363' => 'astana',
            'ALMATY'     => 'almaty',
            'ASTANA'     => 'astana',
            'NUR-SULTAN' => 'astana',
        ];

        // Точное совпадение
        if (isset($cityMap[$locationCode])) {
            return $cityMap[$locationCode];
        }

        // Поиск по вхождению
        foreach ($cityMap as $key => $city) {
            if (strpos($locationCode, $key) !== false) {
                return $city;
            }
        }

        // По умолчанию — Алматы
        return 'almaty';
    }

    /**
     * Определить тип доставки по ID службы
     *
     * @param int $deliveryId — ID службы доставки из Битрикс
     * @return string — courier|pickup
     */
    private static function getDeliveryType(int $deliveryId): string
    {
        // ID служб самовывоза (заполнить реальными ID из Битрикс)
        // Узнать: Магазин → Службы доставки → ID нужной службы
        $pickupServiceIds = [
            2,
        ];

        if (in_array($deliveryId, $pickupServiceIds, true)) {
            return 'pickup';
        }

        return 'courier';
    }

    /**
     * Рассчитать диапазон периода доставки
     *
     * @param int $days — дни из catalog.app
     * @param string $city
     * @param string $type
     * @return array{from: int, to: int}
     */
    private static function calculatePeriod(int $days, string $city, string $type): array
    {
        $city = strtolower($city);
        $type = strtolower($type);


        // Курьер: +1 день, диапазон
        if ($type === 'courier') {
            return ['from' => $days, 'to' => $days + 1];
        }

        // Самовывоз
        if ($type === 'pickup') {
            // Алматы: без изменений
            if ($city === 'almaty') {
                return ['from' => $days, 'to' => $days];
            }
            // Астана: +1-2 дня
            if ($city === 'astana') {
                return ['from' => $days + 1, 'to' => $days + 2];
            }
        }
        return ['from' => $days, 'to' => $days];
    }

    /**
     * Получить срок доставки товара из catalog_app_data
     *
     * @param int $productId — GOODS_SITE_ID
     * @return int — количество дней (0 если не найдено)
     */
    private static function getProductDeliveryDays(int $productId): int
    {
        $connection = \Bitrix\Main\Application::getConnection();

        $sql = "SELECT DELIVERY_TIME FROM catalog_app_data
                WHERE GOODS_SITE_ID = " . (int)$productId . " LIMIT 1";
        $result = $connection->query($sql);

        if ($row = $result->fetch()) {
            $value = (int)$row['DELIVERY_TIME'];

            return $value;
        }

        return 0;
    }
}
