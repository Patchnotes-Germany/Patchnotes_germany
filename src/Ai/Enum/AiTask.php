<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * Catalogue of AI tasks (SPEC.md § 8.4). Each task has a versioned prompt template
 * (templates/ai/<task>/v<N>.{system,user}.twig) and a JSON schema (config/ai/schemas/<task>.json).
 */
enum AiTask: string
{
    case PromulgationExtract = 'promulgation_extract';
    case AmendmentApply = 'amendment_apply';
    case ChangeAnalyze = 'change_analyze';
    case CardWrite = 'card_write';
    case CardVerify = 'card_verify';
    case CardTranslate = 'card_translate';
    case NormTranslate = 'norm_translate';
    case BillSummarize = 'bill_summarize';
    case DigestWrite = 'digest_write';
    case PlenarySummarize = 'plenary_summarize';
    case LawTopics = 'law_topics';
    case GlossarySuggest = 'glossary_suggest';

    /**
     * Tasks without which nothing can be published at all. When the monthly budget is exhausted and
     * the policy is "pause", everything else stops and these keep running (SPEC.md § 8.2) — a
     * missed translation is an inconvenience, a missed change is the whole point of the project.
     */
    public function isCritical(): bool
    {
        return match ($this) {
            self::PromulgationExtract, self::AmendmentApply, self::ChangeAnalyze,
            self::CardWrite, self::CardVerify => true,
            default => false,
        };
    }
}
