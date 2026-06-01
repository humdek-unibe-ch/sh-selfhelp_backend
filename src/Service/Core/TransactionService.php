<?php

/*
 * SPDX-FileCopyrightText: 2026 Humdek, University of Bern
 * SPDX-License-Identifier: MPL-2.0
 */


namespace App\Service\Core;

use App\Entity\Transaction;
use App\Entity\User;
use App\Service\Auth\UserContextService;
use App\Service\Core\LookupService;
use App\Util\EntityUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for logging transactions in the system
 */
class TransactionService
{
    /**
     * Constructor
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserContextService $userContextService,
        private readonly RequestStack $requestStack,
        private readonly LookupService $lookupService
    ) {}

    /**
     * Log a transaction in the system
     *
     * @param string $tranType The transaction type code (e.g., 'create', 'update', 'delete')
     * @param string $tranBy The transaction by code (e.g., 'user', 'system')
     * @param string|null $tableName The table name affected by the transaction
     * @param int|null $entryId The ID of the affected record
     * @param bool|object $logRow Whether to log the entire row data or the actual row object to log
     * @param string|null $verbalLog Custom verbal log message
     * @return Transaction The created transaction entity
     */
    public function logTransaction(
        string $tranType = LookupService::TRANSACTION_TYPES_INSERT,
        string $tranBy = LookupService::TRANSACTION_BY_BY_USER,
        ?string $tableName = null,
        ?int $entryId = null,
        $logRow = false,
        ?string $verbalLog = null
    ): Transaction {
        // Get current user ID
        $userId = $this->userContextService->getCurrentUser()?->getId() ?? null;
        
        // Create log data
        $log = [
            'verbal_log' => $verbalLog ?: ('Transaction type: `' . $tranType . '` from table: `' . $tableName . '` triggered ' . $tranBy),
            'url' => $userId > 0 ? ($this->requestStack->getCurrentRequest()?->getRequestUri() ?? '') : '',
            'session' => $userId > 0 ? session_id() : ''
        ];
        
        // Handle row data logging
        if ($tableName && $entryId) {
            // If logRow is an object, use it directly as the row data
            if (is_object($logRow)) {
                // Handle Doctrine entities and other objects
                $entityData = EntityUtil::convertEntityToArray($logRow);
                $log['table_row_entry'] = $entityData;
            } 
            // If logRow is true, fetch the row data from the database
            elseif ($logRow === true) {
                $conn = $this->entityManager->getConnection();
                $stmt = $conn->prepare('SELECT * FROM ' . $tableName . ' WHERE id = :id');
                $stmt->bindValue('id', $entryId, \Doctrine\DBAL\ParameterType::INTEGER);
                $result = $stmt->executeQuery();
                $entry = $result->fetchAssociative();
                
                if ($entry) {
                    $log['table_row_entry'] = $entry;
                }
            }
        }
        
        // Create transaction entity
        $transaction = new Transaction();
        
        // Check if we're in an active transaction to avoid EntityManager conflicts
        
            // Safe to do lookup queries when not in transaction
            $transactionType = $this->lookupService->findByTypeAndCode(
                LookupService::TRANSACTION_TYPES,
                $tranType
            );
            
            $transactionBy = $this->lookupService->findByTypeAndCode(
                LookupService::TRANSACTION_BY,
                $tranBy
            );
            
            // Set the entity relationships directly
            if ($transactionType) {
                $transaction->setTransactionType($transactionType);
            }
            
            if ($transactionBy) {
                $transaction->setTransactionBy($transactionBy);
            }
       
        
        // Set user if available
        if ($userId) {
            $user = $this->entityManager->getReference(User::class, $userId);
            $transaction->setUser($user);
        }

        $transaction->setTableName($tableName);
        $transaction->setIdTableName($entryId);
        $transaction->setTransactionLog(json_encode($log) ?: null);
        // Transaction time is now set automatically in the entity constructor
        
        // Persist the transaction
        $this->entityManager->persist($transaction);
        
        $this->entityManager->flush();
        
        return $transaction;
    }
}
