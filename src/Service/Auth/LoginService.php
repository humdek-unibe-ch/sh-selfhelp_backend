<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LoginService
{
    private UserRepository $userRepository;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher
    ) {
        $this->userRepository = $userRepository;
        $this->passwordHasher = $passwordHasher;
    }

    /**
     * Validate user credentials (username/email and password).
     * Returns the matching User entity, or false if the credentials are invalid.
     *
     * @param string $user
     * @param string $password
     * @return User|false
     */
    public function validateUser(string $user, string $password): User|false
    {
        // Find the user by email using UserRepository
        $userEntity = $this->userRepository->findOneBy(['email' => $user]);
        if (!$userEntity || !$this->passwordHasher->isPasswordValid($userEntity, $password)) {
            return false;
        }        
        return $userEntity;
    }

}
