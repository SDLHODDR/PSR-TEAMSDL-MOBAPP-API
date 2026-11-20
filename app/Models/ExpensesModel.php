<?php

declare(strict_types=1);

namespace App\Models;

use SDL\Framework\Database;
use SDL\Framework\Model;

final class ExpensesModel extends Model
{
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function currentAndLastThreeMonths(int $employeeId): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            WITH periods AS (
                SELECT ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1 - LEVEL) AS MONTH_START
                FROM DUAL
                CONNECT BY LEVEL <= 4
            ),
            expense_headers AS (
                SELECT h.ID AS EXPENSE_ID,
                       h.STATUS,
                       TRUNC(h.ASON_DATE, 'MM') AS MONTH_START,
                       ROW_NUMBER() OVER (
                           PARTITION BY TRUNC(h.ASON_DATE, 'MM')
                           ORDER BY h.ASON_DATE DESC, h.ID DESC
                       ) AS PERIOD_RANK
                FROM SFM_MONTHLY_EXPENSE h
                WHERE h.EMP_ID = :employee_id
                  AND h.ASON_DATE >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -3)
                  AND h.ASON_DATE < ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)
            ),
            monthly_items AS (
                SELECT h.MONTH_START,
                       TRIM(d.ALLOW_NAME) AS ITEM_TYPE,
                       SUM(NVL(d.EXP_AMT, 0)) AS ITEM_AMOUNT,
                       CAST(NULL AS VARCHAR2(4000)) AS ITEM_REMARK,
                       1 AS ITEM_SOURCE,
                       MIN(d.ID) AS ITEM_ID
                FROM expense_headers h
                INNER JOIN SFM_MONTHLY_EXPENSE_DET d
                    ON d.EXPENSE_ID = h.EXPENSE_ID
                WHERE h.PERIOD_RANK = 1
                  AND d.ALLOW_NAME IS NOT NULL
                GROUP BY h.MONTH_START, TRIM(d.ALLOW_NAME)
            ),
            daily_items AS (
                SELECT TRUNC(a.ASON_DATE, 'MM') AS MONTH_START,
                       TRIM(e.EXP_DESC) AS ITEM_TYPE,
                       NVL(a.EXP_AMT, 0) AS ITEM_AMOUNT,
                       a.REMARK AS ITEM_REMARK,
                       2 AS ITEM_SOURCE,
                       a.ID AS ITEM_ID
                FROM SFM_DAILY_EXPENSE a
                INNER JOIN SFM_EXPENSE e ON e.EXP_ID = a.EXP_ID
                WHERE a.EMP_ID = :daily_employee_id
                  AND a.ASON_DATE >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -3)
                  AND a.ASON_DATE < ADD_MONTHS(TRUNC(SYSDATE, 'MM'), 1)
            ),
            expense_items AS (
                SELECT MONTH_START, ITEM_TYPE, ITEM_AMOUNT, ITEM_REMARK,
                       ITEM_SOURCE, ITEM_ID
                FROM monthly_items
                UNION ALL
                SELECT MONTH_START, ITEM_TYPE, ITEM_AMOUNT, ITEM_REMARK,
                       ITEM_SOURCE, ITEM_ID
                FROM daily_items
            )
            SELECT TO_CHAR(p.MONTH_START, 'YYYY-MM') AS MONTH,
                   TO_CHAR(
                       p.MONTH_START,
                       'Mon-YYYY',
                       'NLS_DATE_LANGUAGE=English'
                   ) AS MONTH_LABEL,
                   CASE
                       WHEN p.MONTH_START = TRUNC(SYSDATE, 'MM') THEN 1
                       ELSE 0
                   END AS IS_CURRENT,
                   h.STATUS AS STATUS_CODE,
                   i.ITEM_TYPE,
                   i.ITEM_AMOUNT,
                   i.ITEM_REMARK
            FROM periods p
            LEFT JOIN expense_headers h
                ON h.MONTH_START = p.MONTH_START
               AND h.PERIOD_RANK = 1
            LEFT JOIN expense_items i ON i.MONTH_START = p.MONTH_START
            ORDER BY p.MONTH_START DESC, i.ITEM_SOURCE, i.ITEM_ID
            SQL, [
                'employee_id' => $employeeId,
                'daily_employee_id' => $employeeId,
            ]);

        $months = [];
        foreach ($rows as $row) {
            $month = (string) $row['MONTH'];
            if (!isset($months[$month])) {
                $statusCode = $row['STATUS_CODE'] === null
                    ? null
                    : strtoupper(trim((string) $row['STATUS_CODE']));
                $months[$month] = [
                    'month' => $month,
                    'label' => (string) $row['MONTH_LABEL'],
                    'is_current' => (int) $row['IS_CURRENT'] === 1,
                    'status' => $this->status($statusCode),
                    'status_code' => $statusCode,
                    'total' => 0.0,
                    'items' => [],
                ];
            }

            $type = trim((string) ($row['ITEM_TYPE'] ?? ''));
            if ($type === '') {
                continue;
            }

            $amount = (float) ($row['ITEM_AMOUNT'] ?? 0);
            $months[$month]['items'][] = [
                'type' => $type,
                'amount' => $amount,
                'remark' => (string) ($row['ITEM_REMARK'] ?? ''),
            ];
            $months[$month]['total'] += $amount;
        }

        return ['months' => array_values($months)];
    }

    private function status(?string $statusCode): string
    {
        return match ($statusCode) {
            null, '' => 'notSubmitted',
            'A', 'N' => 'submitted',
            'X' => 'draft',
            default => 'draft',
        };
    }
}
