<?php

use Bitrix\Main\Application;

/**
 * Сервис расчёта и форматирования сроков доставки
 *
 * Использует данные из catalog.app (таблица catalog_app_data)
 * и форматирует их согласно правилам для разных городов и типов доставки.
 */
class DeliveryTimeService
{
    // Константы городов
    public const CITY_ALMATY = 'almaty';
    public const CITY_ASTANA = 'astana';

    // Типы доставки
    public const TYPE_COURIER = 'courier';
    public const TYPE_PICKUP = 'pickup';

    private $connection;

    public function __construct()
    {
        $this->connection = Application::getConnection();
    }

    /**
     * Получить отформатированный срок доставки для товара
     *
     * @param int $productId - ID товара на сайте (GOODS_SITE_ID)
     * @param string $city - Город (almaty|astana)
     * @param string $type - Тип доставки (courier|pickup)
     * @return string - Отформатированная строка срока
     */
    public function getDeliveryTime(int $productId, string $city, string $type): string
    {
        $deliveryDays = $this->getDeliveryDaysFromDb($productId);

        if ($deliveryDays === null) {
            return 'Срок доставки уточняется';
        }

        return $this->formatDeliveryTime($deliveryDays, $city, $type);
    }

    /**
     * Получить сроки для нескольких товаров (batch)
     *
     * @param array $productIds - Массив ID товаров
     * @param string $city - Город
     * @param string $type - Тип доставки
     * @return array - [productId => 'форматированный срок']
     */
    public function getDeliveryTimesBatch(array $productIds, string $city, string $type): array
    {
        if (empty($productIds)) {
            return [];
        }

        $ids = array_map('intval', $productIds);
        $sql = "SELECT GOODS_SITE_ID, DELIVERY_TIME FROM catalog_app_data
                WHERE GOODS_SITE_ID IN (" . implode(',', $ids) . ")";

        $result = $this->connection->query($sql);
        $deliveryData = [];

        while ($row = $result->fetch()) {
            $deliveryData[$row['GOODS_SITE_ID']] = (int)$row['DELIVERY_TIME'];
        }

        $formatted = [];
        foreach ($productIds as $productId) {
            $days = $deliveryData[$productId] ?? null;
            $formatted[$productId] = $days !== null
                ? $this->formatDeliveryTime($days, $city, $type)
                : 'Срок доставки уточняется';
        }

        return $formatted;
    }

    /**
     * Получить срок доставки из БД
     *
     * @param int $productId
     * @return int|null
     */
    private function getDeliveryDaysFromDb(int $productId): ?int
    {
        $sql = "SELECT DELIVERY_TIME FROM catalog_app_data
                WHERE GOODS_SITE_ID = " . (int)$productId . " LIMIT 1";
        $result = $this->connection->query($sql);

        if ($row = $result->fetch()) {
            return (int)$row['DELIVERY_TIME'];
        }

        return null;
    }

    /**
     * Форматировать срок доставки согласно ТЗ
     *
     * Правила:
     * - Курьер (любой город): +1 день, диапазон (N → N+1 - N+2 дня)
     * - Самовывоз Алматы: без изменений (N дней)
     * - Самовывоз Астана: +1-2 дня, диапазон (N → N+1 - N+2 дня)
     *
     * @param int $days - Исходный срок из catalog.app
     * @param string $city - Город
     * @param string $type - Тип доставки
     * @return string
     */
    public function formatDeliveryTime(int $days, string $city, string $type): string
    {
        if ($days <= 0) {
            return 'Срок доставки уточняется';
        }

        $city = strtolower($city);
        $type = strtolower($type);

        // Курьерская доставка (для всех городов: +1 день, диапазон)
        if ($type === self::TYPE_COURIER) {
            $minDays = $days + 1;
            $maxDays = $minDays + 1;
            return $this->formatRange($minDays, $maxDays);
        }

        // Самовывоз
        if ($type === self::TYPE_PICKUP) {
            // Алматы: без изменений
            if ($city === self::CITY_ALMATY) {
                return $this->formatDays($days);
            }

            // Астана: +1-2 дня запаса, диапазон
            if ($city === self::CITY_ASTANA) {
                $minDays = $days + 1;
                $maxDays = $days + 2;
                return $this->formatRange($minDays, $maxDays);
            }
        }

        return 'Срок доставки уточняется';
    }

    /**
     * Форматировать диапазон дней
     *
     * @param int $min
     * @param int $max
     * @return string - "1-2 дня"
     */
    private function formatRange(int $min, int $max): string
    {
        return $min . '-' . $max . ' ' . $this->getDayWord($max);
    }

    /**
     * Форматировать одно значение дней
     *
     * @param int $days
     * @return string - "2 дня"
     */
    private function formatDays(int $days): string
    {
        return $days . ' ' . $this->getDayWord($days);
    }

    /**
     * Склонение слова "день"
     *
     * @param int $n
     * @return string - "день" | "дня" | "дней"
     */
    private function getDayWord(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        if ($n > 10 && $n < 20) {
            return 'дней';
        }

        if ($n1 > 1 && $n1 < 5) {
            return 'дня';
        }

        if ($n1 == 1) {
            return 'день';
        }

        return 'дней';
    }
}
