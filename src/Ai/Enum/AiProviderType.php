<?php

declare(strict_types=1);

namespace App\Ai\Enum;

/**
 * Provider families behind LlmClientInterface (SPEC.md § 8.1).
 */
enum AiProviderType: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    /** Ollama, LM Studio, vLLM, llama.cpp server, LocalAI — anything speaking the OpenAI API. */
    case OpenAiCompatible = 'openai_compatible';
    /** Scripted answers for the test suite and `make demo`: no network, no key, no GPU. */
    case Fake = 'fake';
}
