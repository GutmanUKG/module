<?php

use Bitrix\Main\EventManager;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Sale\Delivery\Services\Manager as DeliveryManager;

/**
 * Обработчик событий для модификации сроков доставки
 *
 * Перехватывает события расчёта доставки и модифицирует
 * название/описание службы, добавляя рассчитанный срок.
 */
class DeliveryEventHandler
{
    /**
     * Регистрация обработчиков событий
     */
    public static function register(): void
    {
        $eventManager = EventManager::getInstance();

        // Событие после расчёта доставки
        $eventManager->addEventHandler(
            'sale',
            'onSaleDeliveryServiceCalculate',
            [self::class, 'onDeliveryCalculate']
        );
    }

    /**
     * Обработчик события расчёта доставки
     *
     * @param Event $event
     * @return EventResult|null
     */
    public static function onDeliveryCalculate(Event $event): ?EventResult
    {
        $parameters = $event->getParameters();

        if (empty($parameters)) {
            return null;
        }

        // Получаем данные расчёта
        $shipment = $parameters['SHIPMENT'] ?? null;
        $deliveryId = $parameters['DELIVERY_ID'] ?? 0;

        if (!$shipment || !$deliveryId) {
            return null;
        }

        try {
            // Получаем товары из shipment
            $basketItems = [];
            $basket = $shipment->getCollection()->getOrder()->getBasket();

            foreach ($basket as $basketItem) {
                $basketItems[] = $basketItem->getProductId();
            }

            if (empty($basketItems)) {
                return null;
            }

            // Определяем город из местоположения заказа
            $order = $shipment->getCollection()->getOrder();
            $propertyCollection = $order->getPropertyCollection();
            $locationProperty = $propertyCollection->getDeliveryLocation();
            $locationCode = $locationProperty ? $locationProperty->getValue() : '';

            $city = self::getCityFromLocation($locationCode);

            // Определяем тип доставки (курьер/самовывоз)
            $deliveryType = self::getDeliveryType($deliveryId);

            // Получаем срок доставки
            require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/imarket.catalog_app/services/DeliveryTimeService.php');
            $deliveryService = new \DeliveryTimeService();

            // Берём максимальный срок из всех товаров корзины
            $maxDays = 0;
            foreach ($basketItems as $productId) {
                $days = self::getProductDeliveryDays($productId);
                if ($days > $maxDays) {
                    $maxDays = $days;
                }
            }

            if ($maxDays > 0) {
                $formattedTime = $deliveryService->formatDeliveryTime($maxDays, $city, $deliveryType);

                // Возвращаем модифицированный результат с информацией о сроке
                return new EventResult(
                    EventResult::SUCCESS,
                    ['DELIVERY_TIME_TEXT' => $formattedTime]
                );
            }

        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем работу
        }

        return null;
    }

    /**
     * Определить город по коду местоположения
     *
     * @param string $locationCode
     * @return string
     */
    private static function getCityFromLocation(string $locationCode): string
    {
        $locationCode = strtoupper($locationCode);

        // Маппинг кодов местоположений (нужно адаптировать под реальные коды сайта)
        $cityMap = [
            'ALMATY'     => 'almaty',
            'АЛМАТЫ'     => 'almaty',
            'ASTANA'     => 'astana',
            'АСТАНА'     => 'astana',
            'NUR-SULTAN' => 'astana',
        ];

        // Поиск по вхождению
        foreach ($cityMap as $key => $city) {
            if (strpos($locationCode, $key) !== false) {
                return $city;
            }
        }

        // По умолчанию - Алматы
        return 'almaty';
    }

    /**
     * Определить тип доставки по ID службы
     *
     * @param int $deliveryId
     * @return string
     */
    private static function getDeliveryType(int $deliveryId): string
    {
        // Получаем информацию о службе доставки
        $delivery = DeliveryManager::getById($deliveryId);

        if ($delivery) {
            $name = strtolower($delivery['NAME'] ?? '');
            $code = strtolower($delivery['CODE'] ?? '');

            // Определяем по названию/коду
            $pickupKeywords = ['самовывоз', 'pickup', 'пункт выдачи', 'офис'];

            foreach ($pickupKeywords as $keyword) {
                if (strpos($name, $keyword) !== false || strpos($code, $keyword) !== false) {
                    return 'pickup';
                }
            }
        }

        // По умолчанию - курьер
        return 'courier';
    }

    /**
     * Получить срок доставки товара из БД
     *
     * @param int $productId
     * @return int
     */
    private static function getProductDeliveryDays(int $productId): int
    {
        $connection = \Bitrix\Main\Application::getConnection();

        $sql = "SELECT DELIVERY_TIME FROM catalog_app_data
                WHERE GOODS_SITE_ID = " . (int)$productId . " LIMIT 1";
        $result = $connection->query($sql);

        if ($row = $result->fetch()) {
            return (int)$row['DELIVERY_TIME'];
        }

        return 0;
    }
}
