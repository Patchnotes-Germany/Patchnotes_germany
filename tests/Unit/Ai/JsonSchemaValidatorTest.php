<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Ai\Schema\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The guard between a model and the database (SPEC.md § 8.4).
 *
 * Its messages are fed back to the model in the repair attempt, so they are asserted as well: a
 * vague complaint produces another invalid answer.
 */
#[CoversClass(JsonSchemaValidator::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    private JsonSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new JsonSchemaValidator();
    }

    public function testAValidObjectPasses(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Higher salary threshold',
            'impact' => 2,
            'topics' => ['migration', 'labor'],
            'audience' => ['general' => false],
        ]);

        self::assertSame([], $errors);
    }

    public function testAMissingRequiredPropertyIsReported(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'impact' => 2,
            'topics' => ['migration'],
            'audience' => ['general' => true],
        ]);

        self::assertSame(['$ is missing the required property "title".'], $errors);
    }

    public function testAWrongTypeIsReportedWithBothTypes(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Something',
            'impact' => 'two',
            'topics' => ['migration'],
            'audience' => ['general' => false],
        ]);

        self::assertSame(['$.impact must be of type integer, string given.'], $errors);
    }

    public function testNumericBoundsAreChecked(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Something',
            'impact' => 7,
            'topics' => ['migration'],
            'audience' => ['general' => false],
        ]);

        self::assertSame(['$.impact must be at most 3.'], $errors);
    }

    public function testItemsOfAnArrayAreValidated(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Something',
            'impact' => 1,
            'topics' => ['migration', 42],
            'audience' => ['general' => false],
        ]);

        self::assertSame(['$.topics[1] must be of type string, integer given.'], $errors);
    }

    public function testAnEmptyArrayIsRejectedWhenItemsAreRequired(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Something',
            'impact' => 1,
            'topics' => [],
            'audience' => ['general' => false],
        ]);

        self::assertSame(['$.topics must have at least 1 items.'], $errors);
    }

    public function testUnexpectedPropertiesAreReportedWhenAdditionalPropertiesAreForbidden(): void
    {
        $errors = $this->validator->validate($this->cardSchema(), [
            'title' => 'Something',
            'impact' => 1,
            'topics' => ['migration'],
            'audience' => ['general' => false],
            'invented' => 'field',
        ]);

        self::assertSame(['$ has the unexpected property "invented".'], $errors);
    }

    public function testEnumValuesAreChecked(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'error']]],
        ];

        $errors = $this->validator->validate($schema, ['severity' => 'critical']);

        self::assertSame(['$.severity must be one of "info", "warning", "error".'], $errors);
    }

    public function testANullableTypeAcceptsBothForms(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['date' => ['type' => ['string', 'null'], 'format' => 'date']],
        ];

        self::assertSame([], $this->validator->validate($schema, ['date' => null]));
        self::assertSame([], $this->validator->validate($schema, ['date' => '2026-09-01']));
        self::assertSame(['$.date must be a date in YYYY-MM-DD form.'], $this->validator->validate($schema, ['date' => '1.9.2026']));
    }

    public function testAPatternIsChecked(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['key' => ['type' => 'string', 'pattern' => '^[a-z0-9_]+$']],
        ];

        self::assertSame([], $this->validator->validate($schema, ['key' => 'blue_card_salary']));
        self::assertCount(1, $this->validator->validate($schema, ['key' => 'Blue Card']));
    }

    public function testNestedObjectsReportTheirPath(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'facts' => [
                    'type' => 'object',
                    'properties' => [
                        'amounts' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['source_quote'],
                                'properties' => ['source_quote' => ['type' => 'string']],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validate($schema, ['facts' => ['amounts' => [['value' => 1]]]]);

        self::assertSame(['$.facts.amounts[0] is missing the required property "source_quote".'], $errors);
    }

    public function testAnyOfAcceptsOneMatchingShape(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'value' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
        ];

        self::assertSame([], $this->validator->validate($schema, ['value' => 'text']));
        self::assertSame([], $this->validator->validate($schema, ['value' => 5]));
        self::assertCount(1, $this->validator->validate($schema, ['value' => 1.5]));
    }

    /**
     * A model that answers with a list where an object is expected is a common failure; the message
     * has to name the type it actually produced.
     */
    public function testAListIsNotAnObject(): void
    {
        $errors = $this->validator->validate(['type' => 'object'], [['topics' => []]]);

        self::assertSame(['$ must be of type object, array given.'], $errors);
    }

    /**
     * @return array<string, mixed>
     */
    private function cardSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'impact', 'topics', 'audience'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 3],
                'impact' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3],
                'topics' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                'audience' => [
                    'type' => 'object',
                    'properties' => ['general' => ['type' => 'boolean']],
                ],
            ],
        ];
    }
}
