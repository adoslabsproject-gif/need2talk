<?php

namespace Need2Talk\Models;

use Need2Talk\Core\BaseModel;

/**
 * UserProfile Model - User profile settings
 *
 * Handles user profile and privacy settings
 */
class UserProfile extends BaseModel
{
    protected string $table = 'user_profiles';

    protected string $primaryKey = 'user_id';

    public function findByUserId(int $userId): ?array
    {
        return $this->findBy(['user_id' => $userId], 1)[0] ?? null;
    }

    public function updateSettings(int $userId, array $settings): bool
    {
        $existingProfile = $this->findByUserId($userId);

        if ($existingProfile) {
            return $this->update($userId, $settings);
        }
        $this->create(array_merge(['user_id' => $userId], $settings));

        return true;

    }
}
