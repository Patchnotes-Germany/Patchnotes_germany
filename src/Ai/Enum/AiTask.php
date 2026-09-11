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
}
