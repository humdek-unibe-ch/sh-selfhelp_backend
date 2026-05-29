<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */

namespace App\Service\Action;

use App\Repository\UserRepository;

/**
 * Resolves the recipient user ids for an action execution.
 *
 * Recipients may come from the triggering user, selected target groups, or an
 * overwrite-variable impersonation code when that feature is explicitly enabled.
 */
class ActionRecipientResolverService
{
    public function __construct(
        private readonly UserRepository $userRepository
    ) {
    }

    /**
     * Resolve the final list of recipient user ids for the current action run.
     *
     * @param array<string, mixed> $runtimeConfig
     *   The fully interpolated runtime action config.
     * @param array<string, mixed> $submittedValues
     *   Submitted values that may contain overwrite-variable inputs.
     * @param int|null $sourceUserId
     *   The triggering user id, when the action originated from a user save event.
     *
     * @return int[]
     *   A normalized list of positive recipient user ids.
     */
    public function resolve(array $runtimeConfig, array $submittedValues, ?int $sourceUserId): array
    {
        $users = [];

        if (($runtimeConfig[ActionConfig::TARGET_GROUPS] ?? false) === true) {
            $groupNames = [];
            $selectedGroups = $runtimeConfig[ActionConfig::SELECTED_TARGET_GROUPS] ?? [];
            if (is_array($selectedGroups)) {
                foreach ($selectedGroups as $value) {
                    if (is_string($value) && $value !== '') {
                        $groupNames[] = $value;
                    }
                }
            }
            $users = $this->userRepository->findIdsByGroupNames($groupNames);
        } elseif ($sourceUserId !== null) {
            $users = [$sourceUserId];
        }

        $overwriteVariablesEnabled = ($runtimeConfig[ActionConfig::OVERWRITE_VARIABLES] ?? false) === true;
        $overwriteVariables = $runtimeConfig[ActionConfig::SELECTED_OVERWRITE_VARIABLES] ?? [];
        if (
            $overwriteVariablesEnabled &&
            is_array($overwriteVariables) &&
            in_array(ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE, $overwriteVariables, true) &&
            isset($submittedValues[ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE]) &&
            is_string($submittedValues[ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE])
        ) {
            $impersonatedUserId = $this->userRepository->findIdByValidationCode($submittedValues[ActionConfig::OVERWRITE_IMPERSONATE_USER_CODE]);
            if ($impersonatedUserId !== null) {
                return [$impersonatedUserId];
            }

            return [];
        }

        $users = array_values(array_unique(array_map('intval', $users)));
        return array_values(array_filter($users, static fn(int $userId): bool => $userId > 0));
    }
}
