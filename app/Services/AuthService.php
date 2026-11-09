<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmployeeAuthModel;
use InvalidArgumentException;
use SDL\Framework\Helpers;

final class AuthService
{
    public function __construct(
        private readonly EmployeeAuthModel $employeeAuthModel,
        private readonly LegacyPasswordEncoder $passwordEncoder,
        private readonly TokenService $tokenService
    ) {
    }

    public function verifyEmployeeCredentials(string $username, string $password): ?array
    {
        //Helpers::printArr('----Print----',[$username, $this->passwordEncoder->encode($password)]);
        $employee = $this->employeeAuthModel->findActiveEmployeeByCredentials(
            $username,
            $this->passwordEncoder->encode($password)
        );

        //Helpers::printArr('----Auth Employee ----', $employee);
        return $employee === null ? null : $this->normalizeEmployee($employee);
    }

    /** Authenticate against Oracle, then issue a stateless JWT without token-table access. */
    public function loginAndIssueTokens(string $username, string $password, string $clientIp = ''): ?array
    {
        $employee = $this->verifyEmployeeCredentials($username, $password);
        
        //Helpers::printArr('============Emplyeee Details-=========', $employee);

        if ($employee === null) return null;
        if (!is_numeric($employee['emp_id']) || (int) $employee['emp_id'] <= 0) {
            throw new InvalidArgumentException('The employee record has an invalid identifier.');
        }
        return $this->tokenService->issueAccessToken((int) $employee['emp_id'], (string) $employee['login_id']);
    }

    private function normalizeEmployee(array $employee): array
    {
        return [
            'login_id' => $employee['LOGIN_ID'] ?? $employee['login_id'] ?? null,
            'emp_id' => $employee['EMP_ID'] ?? $employee['emp_id'] ?? null,
            'geo_id' => $employee['GEO_ID'] ?? $employee['geo_id'] ?? null,
            'geo_level' => $employee['GEO_LEVEL'] ?? $employee['geo_level'] ?? null,
        ];
    }
}
