<?php

declare(strict_types=1);

namespace App\Support\Crm;

/**
 * Canonical catalog of accepted identity-document types.
 *
 * This is the single source of truth shared by the reference catalog endpoint
 * and request validation, so the frontend select and the backend enum cannot
 * drift apart (FBI-003).
 */
final class IdentityDocumentTypeCatalog
{
    /**
     * @var array<int, array{key: string, label: string, required_faces: int, requires_expiry: bool}>
     */
    private const TYPES = [
        ['key' => 'national_id', 'label' => 'National ID Card', 'required_faces' => 2, 'requires_expiry' => true],
        ['key' => 'passport', 'label' => 'Passport', 'required_faces' => 1, 'requires_expiry' => true],
        ['key' => 'drivers_license', 'label' => "Driver's License", 'required_faces' => 2, 'requires_expiry' => true],
        ['key' => 'residence_permit', 'label' => 'Residence Permit', 'required_faces' => 2, 'requires_expiry' => true],
        ['key' => 'voter_card', 'label' => 'Voter Card', 'required_faces' => 1, 'requires_expiry' => false],
        ['key' => 'birth_certificate', 'label' => 'Birth Certificate', 'required_faces' => 1, 'requires_expiry' => false],
    ];

    /**
     * @return array<int, array{key: string, label: string, required_faces: int, requires_expiry: bool}>
     */
    public static function all(): array
    {
        return self::TYPES;
    }

    /**
     * Stable machine keys, suitable for an `in:` validation rule.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $type): string => $type['key'], self::TYPES);
    }

    public static function requiredFaces(string $key): ?int
    {
        foreach (self::TYPES as $type) {
            if ($type['key'] === $key) {
                return $type['required_faces'];
            }
        }

        return null;
    }

    public static function requiresExpiry(string $key): ?bool
    {
        foreach (self::TYPES as $type) {
            if ($type['key'] === $key) {
                return $type['requires_expiry'];
            }
        }

        return null;
    }
}
