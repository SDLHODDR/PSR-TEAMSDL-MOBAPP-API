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

    public function lastThreeCompletedMonths(int $employeeId): array
    {
        $rows = $this->fetchAll(<<<'SQL'
            WITH periods AS (
                SELECT ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -LEVEL) AS MONTH_START
                FROM DUAL
                CONNECT BY LEVEL <= 3
            ),
            expense_headers AS (
                SELECT h.ID AS EXPENSE_ID,
                       h.ASON_DATE,
                       h.TOTAL_AMOUNT,
                       h.STATUS,
                       TRUNC(h.ASON_DATE, 'MM') AS MONTH_START,
                       ROW_NUMBER() OVER (
                           PARTITION BY TRUNC(h.ASON_DATE, 'MM')
                           ORDER BY h.ASON_DATE DESC, h.ID DESC
                       ) AS PERIOD_RANK
                FROM SFM_MONTHLY_EXPENSE h
                WHERE h.EMP_ID = :employee_id
                  AND h.ASON_DATE >= ADD_MONTHS(TRUNC(SYSDATE, 'MM'), -3)
                  AND h.ASON_DATE < TRUNC(SYSDATE, 'MM')
            )
            SELECT TO_CHAR(p.MONTH_START, 'YYYY-MM') AS MONTH,
                   TO_CHAR(
                       p.MONTH_START,
                       'Mon-YYYY',
                       'NLS_DATE_LANGUAGE=English'
                   ) AS MONTH_LABEL,
                   h.EXPENSE_ID,
                   h.TOTAL_AMOUNT,
                   h.STATUS AS STATUS_CODE,
                   d.ID AS DETAIL_ID,
                   d.ALLOW_NAME,
                   d.EXP_AMT,
                   e.EXP_DESC AS EXPENSE_NAME
            FROM periods p
            LEFT JOIN expense_headers h
                ON h.MONTH_START = p.MONTH_START
               AND h.PERIOD_RANK = 1
            LEFT JOIN SFM_MONTHLY_EXPENSE_DET d
                ON d.EXPENSE_ID = h.EXPENSE_ID
            LEFT JOIN SFM_EXPENSE e
                ON e.EXP_ID = d.EXP_ID
            ORDER BY p.MONTH_START DESC, d.ID
            SQL, ['employee_id' => $employeeId]);

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
                    'status' => $this->status($statusCode),
                    'status_code' => $statusCode,
                    'total' => (float) ($row['TOTAL_AMOUNT'] ?? 0),
                    'items' => [],
                ];
            }

            if ($row['DETAIL_ID'] === null) {
                continue;
            }

            $type = trim((string) ($row['ALLOW_NAME'] ?? ''));
            if ($type === '') {
                $type = trim((string) ($row['EXPENSE_NAME'] ?? ''));
            }

            $months[$month]['items'][] = [
                'type' => $type,
                'amount' => (float) ($row['EXP_AMT'] ?? 0),
                'remark' => '',
            ];
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
