<?php

namespace App\Repository;

use App\Entity\SectionsFieldsTranslation;
use App\Util\TranslationContentHelper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SectionsFieldsTranslation>
 */
class SectionsFieldsTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SectionsFieldsTranslation::class);
    }

    /**
     * Fetch all section field translations for a list of section IDs and specific language
     *
     * @param array $sectionIds Array of section IDs
     * @param int $languageId Language ID
     * @return array Associative array with section_id as key and translations as values
     */
    public function fetchTranslationsForSections(array $sectionIds, int $languageId): array
    {
        if (empty($sectionIds)) {
            return [];
        }

        $qb = $this->createQueryBuilder('sft')
            ->select('s.id AS section_id, f.id AS field_id, f.name AS field_name, l.locale AS locale, sft.content, sft.meta')
            ->leftJoin('sft.section', 's')
            ->leftJoin('sft.field', 'f')
            ->leftJoin('sft.language', 'l')
            ->where('s.id IN (:sectionIds)')
            ->andWhere('l.id = :languageId')
            ->setParameter('sectionIds', $sectionIds)
            ->setParameter('languageId', $languageId);

        $results = $qb->getQuery()->getResult();
        
        // Organize results by section_id
        $translations = [];
        foreach ($results as $result) {
            $sectionId = $result['section_id'];
            if (!isset($translations[$sectionId])) {
                $translations[$sectionId] = [];
            }
            
            $translations[$sectionId][$result['field_name']] = [
                'content' => $result['content'],
                'meta' => $result['meta']
            ];
        }
        
        return $translations;
    }

    /**
     * Fetch all section field translations for a list of section IDs with fallback to default language
     *
     * This method fetches translations for the requested language and automatically falls back
     * to the default language at the field level. Only fields with user-visibly non-empty
     * content in the primary language will override the default language translations.
     *
     * "User-visibly non-empty" goes beyond a plain trim() check: rich-text fields
     * persisted by the CMS editor often look like `<p class="single-line-paragraph"></p>`
     * or `<p><br></p>` when the user clears them. Those wrappers must trigger the
     * fallback just like literal empty strings do. See
     * {@see TranslationContentHelper::isEffectivelyEmpty()} for the exact rules.
     *
     * @param array $sectionIds Array of section IDs
     * @param int $languageId Primary language ID
     * @param int|null $defaultLanguageId Default language ID for fallback
     * @return array Associative array with section_id as key and translations as values
     */
    public function fetchTranslationsForSectionsWithFallback(array $sectionIds, int $languageId, ?int $defaultLanguageId = null): array
    {
        if (empty($sectionIds)) {
            return [];
        }

        // Get primary language translations
        $primaryTranslations = $this->fetchTranslationsForSections($sectionIds, $languageId);

        // If no default language or it's the same as primary, return primary translations
        if ($defaultLanguageId === null || $defaultLanguageId === $languageId) {
            return $primaryTranslations;
        }

        // Get default language translations for fallback
        $defaultTranslations = $this->fetchTranslationsForSections($sectionIds, $defaultLanguageId);

        // Merge translations with primary taking precedence at field level
        $mergedTranslations = [];
        foreach ($sectionIds as $sectionId) {
            $mergedTranslations[$sectionId] = [];

            // Start with default translations
            if (isset($defaultTranslations[$sectionId])) {
                $mergedTranslations[$sectionId] = $defaultTranslations[$sectionId];
            }

            // Override with primary translations at field level
            if (isset($primaryTranslations[$sectionId])) {
                foreach ($primaryTranslations[$sectionId] as $fieldName => $primaryField) {
                    // Only override if the primary language has user-visibly non-empty content.
                    // Empty rich-text wrappers (`<p></p>`, `<p><br></p>`, etc.) keep the default.
                    $primaryContent = $primaryField['content'] ?? null;
                    if (!TranslationContentHelper::isEffectivelyEmpty($primaryContent)) {
                        $mergedTranslations[$sectionId][$fieldName] = $primaryField;
                    }
                    // If primary has empty content or doesn't exist, keep default (already set above)
                }
            }
        }

        return $mergedTranslations;
    }
}
