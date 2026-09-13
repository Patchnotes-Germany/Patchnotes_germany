<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Value\LlmResponse;
use App\Ai\Value\LlmUsage;
use App\Ai\Value\ModelReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Model references and the extraction of JSON from an answer (SPEC.md § 8.1, § 8.2).
 */
#[CoversClass(ModelReference::class)]
#[CoversClass(LlmResponse::class)]
final class ModelReferenceTest extends TestCase
{
    public function testItSplitsProviderAndModel(): void
    {
        $model = ModelReference::parse('openai:gpt-5');

        self::assertSame('openai', $model->provider);
        self::assertSame('gpt-5', $model->model);
        self::assertSame('openai:gpt-5', (string) $model);
    }

    /**
     * Local model ids contain slashes and further colons; only the first colon separates.
     */
    public function testAModelIdMayContainColonsAndSlashes(): void
    {
        $model = ModelReference::parse('local:qwen/qwen3.8-27b:q4');

        self::assertSame('local', $model->provider);
        self::assertSame('qwen/qwen3.8-27b:q4', $model->model);
    }

    public function testItRemembersTheAliasItCameFrom(): void
    {
        self::assertSame('large', ModelReference::parse('openai:gpt-5', 'large')->alias);
    }

    #[DataProvider('malformedReferences')]
    public function testAMalformedReferenceIsRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ModelReference::parse($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedReferences(): iterable
    {
        yield 'no provider' => ['gpt-5'];
        yield 'empty provider' => [':gpt-5'];
        yield 'empty model' => ['openai:'];
        yield 'empty' => [''];
    }

    public function testJsonIsReadFromAPlainAnswer(): void
    {
        self::assertSame(['topics' => ['migration']], $this->response('{"topics":["migration"]}')->json());
    }

    public function testJsonIsReadFromACodeFence(): void
    {
        $content = "Here you are:\n```json\n{\"topics\": [\"labor\"]}\n```\nHope that helps!";

        self::assertSame(['topics' => ['labor']], $this->response($content)->json());
    }

    public function testJsonIsReadFromAnswerWithSurroundingProse(): void
    {
        self::assertSame(['impact' => 2], $this->response('Sure. {"impact": 2} — that is my answer.')->json());
    }

    public function testAnAnswerWithoutJsonYieldsNull(): void
    {
        self::assertNull($this->response('I cannot help with that.')->json());
    }

    public function testABareListIsNotAcceptedAsTheAnswerObject(): void
    {
        self::assertNull($this->response('["migration","labor"]')->json());
    }

    public function testBrokenJsonYieldsNullRatherThanThrowing(): void
    {
        self::assertNull($this->response('{"topics": ["migration",}')->json());
    }

    private function response(string $content): LlmResponse
    {
        return new LlmResponse($content, 'fake:model', new LlmUsage());
    }
}
