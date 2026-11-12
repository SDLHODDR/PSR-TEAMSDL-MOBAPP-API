<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;

final class PriceListModel extends Model
{
    private EmployeeAuthModel $employeeAuthModel;

    public function __construct(Database $db)
    {
        parent::__construct($db);
        $this->employeeAuthModel = new EmployeeAuthModel($db);
    }

    public function catalogueForEmployee(int $employeeId): array
    {
        $divisionIds = $this->employeeAuthModel->getEmpDivisions($employeeId);
        $effectiveDate = $this->fetchOne(
            "SELECT TO_CHAR(TRUNC(SYSDATE), 'YYYY-MM-DD') AS EFFECTIVE_DATE FROM DUAL"
        );
        $date = (string) ($effectiveDate['EFFECTIVE_DATE'] ?? '');
        if ($divisionIds === []) {
            return [
                'effective_date' => $date,
                'categories' => [],
            ];
        }

        $divisionPlaceholders = [];
        $params = [];
        foreach (array_values(array_unique($divisionIds)) as $index => $divisionId) {
            $name = 'division_' . $index;
            $divisionPlaceholders[] = ':' . $name;
            $params[$name] = $divisionId;
        }
        $divisions = implode(', ', $divisionPlaceholders);

        $rows = $this->fetchAll(<<<SQL
            SELECT DISTINCT
                   i.ID AS PRODUCT_ID,
                   i.DESCR AS PRODUCT_NAME,
                   g.GRP_ID AS CATEGORY_ID,
                   g.GRP_DESC AS CATEGORY_NAME,
                   g.SEQ AS CATEGORY_SEQUENCE,
                   r.RATE
            FROM SFM_ITEM_MASTER i
            INNER JOIN SFM_ITEM_DIVISIONS d
                ON d.ITEM_ID = i.ID
               AND d.DIVSN_ID IN ($divisions)
               AND TRUNC(SYSDATE) BETWEEN TRUNC(d.EFFEC_FROM)
                   AND NVL(TRUNC(d.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_RATES r
                ON r.ITEM_ID = i.ID
               AND r.DIVSN_ID = d.DIVSN_ID
               AND TRUNC(SYSDATE) BETWEEN TRUNC(r.EFFEC_FROM)
                   AND NVL(TRUNC(r.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_GROUP_MAP m
                ON m.ITEM_ID = i.ID
               AND TRUNC(SYSDATE) BETWEEN TRUNC(m.EFFEC_FROM)
                   AND NVL(TRUNC(m.EFFEC_TO), DATE '3000-01-01')
            INNER JOIN SFM_ITEM_GROUPS g ON g.GRP_ID = m.GRP_ID
            WHERE i.ITEM_TYPE = 'Sellable Product'
              AND i.STATUS = 'A'
            ORDER BY g.SEQ, g.GRP_DESC, i.DESCR, r.RATE
            SQL, $params);

        $categories = [];
        foreach ($rows as $row) {
            $categoryId = (int) $row['CATEGORY_ID'];
            $categoryKey = (string) $categoryId;
            if (!isset($categories[$categoryKey])) {
                $categories[$categoryKey] = [
                    'category_id' => $categoryId,
                    'name' => (string) $row['CATEGORY_NAME'],
                    'sequence' => (int) $row['CATEGORY_SEQUENCE'],
                    'products' => [],
                ];
            }

            $categories[$categoryKey]['products'][] = [
                'product_id' => (int) $row['PRODUCT_ID'],
                'name' => (string) $row['PRODUCT_NAME'],
                'rate' => (float) $row['RATE'],
            ];
        }

        return [
            'effective_date' => $date,
            'categories' => array_values($categories),
        ];
    }
}
