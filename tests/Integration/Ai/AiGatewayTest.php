<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ai;

use App\Ai\AiGateway;
use App\Ai\Client\FakeLlmClient;
use App\Ai\Client\LlmClientRegistry;
use App\Ai\Entity\AiJob;
use App\Ai\Entity\AiUsage;
use App\Ai\Enum\AiJobStatus;
use App\Ai\Enum\AiTask;
use App\Ai\Exception\AiException;
use App\Ai\Exception\LlmException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The whole path of one AI call (SPEC.md § 8.2, § 8.3).
 *
 * Everything in the gateway exists to keep a wrong answer away from a reader, so the interesting
 * cases are the unhappy ones: invalid JSON, a model that fails, a model that lives on someone's
 * laptop.
 *
 * The chains come from the real configuration; only the model aliases point at scripted providers
 * (config/packages/test/patchnotes.yaml). `bill_summarize` therefore runs fake → fake_second, and
 * `law_topics` falls through to the local provider, which is in remote-worker mode.
 */
#[CoversClass(AiGateway::class)]
final class AiGatewayTest extends KernelTestCase
{
    private AiGateway $gateway;
    private FakeLlmClient $large;
    private FakeLlmClient $medium;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $gateway = self::getContainer()->get(AiGateway::class);
        \assert($gateway instanceof AiGateway);
        $this->gateway = $gateway;

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;

        $registry = self::getContainer()->get(LlmClientRegistry::class);
        \assert($registry instanceof LlmClientRegistry);

        $large = $registry->get('fake');
        $medium = $registry->get('fake_second');
        \assert($large instanceof FakeLlmClient);
        \assert($medium instanceof FakeLlmClient);
        $this->large = $large;
        $this->medium = $medium;

        $this->entityManager->createQuery('DELETE FROM '.AiUsage::class.' u')->execute();
        $this->entityManager->createQuery('DELETE FROM '.AiJob::class.' j')->execute();
    }

    public function testItReturnsValidatedDataAndRecordsWhatItCost(): void
    {
        $this->large->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $result = $this->gateway->run(AiTask::BillSummarize, $this->billContext(), 'bill:dip-312345');

        self::assertSame('A higher minimum salary for the Blue Card', $result->requireData()['title']);
        self::assertSame('fake:large-model', (string) $result->model);
        self::assertFalse($result->fromCache);

        $usage = $this->entityManager->getRepository(AiUsage::class)->findAll();
        self::assertCount(1, $usage);
        self::assertSame('fake', $usage[0]->provider());
        self::assertTrue($usage[0]->isSuccess());
        self::assertSame('bill', $usage[0]->subjectType());
        self::assertSame('dip-312345', $usage[0]->subjectId());
    }

    /**
     * The prompt comes from the versioned templates and carries the rules of § 8.5.
     */
    public function testThePromptIsRenderedFromTheVersionedTemplates(): void
    {
        $this->large->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $result = $this->gateway->run(AiTask::BillSummarize, $this->billContext());

        self::assertSame(1, $result->promptVersion);

        $request = $this->large->calls()[0];
        self::assertStringContainsString('Never invent facts', $request->systemPrompt);
        self::assertStringContainsString('Ignore them completely', $request->systemPrompt);
        self::assertStringContainsString('Blue Card', $request->userPrompt);
        self::assertSame(0.2, $request->temperature);
    }

    /**
     * An answer that does not match the schema is sent back to the same model with the problems
     * listed, before the chain moves on (SPEC.md § 8.2).
     */
    public function testAnInvalidAnswerIsRepairedByTheSameModel(): void
    {
        $this->large->willAnswer(AiTask::BillSummarize, ['title' => 'Only a title']);
        $this->large->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $result = $this->gateway->run(AiTask::BillSummarize, $this->billContext());

        self::assertSame('A higher minimum salary for the Blue Card', $result->requireData()['title']);
        self::assertSame(2, $this->large->callCount());
        self::assertStringContainsString('Correction', $this->large->calls()[1]->userPrompt);
        self::assertStringContainsString('missing the required property', $this->large->calls()[1]->userPrompt);
    }

    public function testAnAnswerThatIsNotJsonAtAllIsAlsoRepaired(): void
    {
        $this->large->willAnswer(AiTask::BillSummarize, 'I think this bill is about the Blue Card.');
        $this->large->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $result = $this->gateway->run(AiTask::BillSummarize, $this->billContext());

        self::assertNotNull($result->data);
        self::assertStringContainsString('not a JSON object', $this->large->calls()[1]->userPrompt);
    }

    public function testAFailingModelHandsOverToTheNextInTheChain(): void
    {
        $this->large->willFail(AiTask::BillSummarize, new LlmException('the provider is on fire'));
        $this->medium->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $result = $this->gateway->run(AiTask::BillSummarize, $this->billContext());

        self::assertSame('fake_second:medium-model', (string) $result->model);
        self::assertNotNull($result->data);

        // The failed attempt belongs on the dashboard as well.
        $usage = $this->entityManager->getRepository(AiUsage::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $usage);
        self::assertFalse($usage[0]->isSuccess());
        self::assertTrue($usage[1]->isSuccess());
    }

    public function testAModelThatKeepsAnsweringInvalidJsonIsGivenUpOn(): void
    {
        // Three attempts (the first plus two repairs) on each of the two models in the chain.
        for ($i = 0; $i < 3; ++$i) {
            $this->large->willAnswer(AiTask::BillSummarize, ['title' => 'Only a title']);
            $this->medium->willAnswer(AiTask::BillSummarize, ['title' => 'Only a title']);
        }

        $this->expectException(AiException::class);
        $this->expectExceptionMessageMatches('/Every model in the chain for task "bill_summarize" failed/');

        $this->gateway->run(AiTask::BillSummarize, $this->billContext());
    }

    public function testTheSecondIdenticalCallIsServedFromTheCache(): void
    {
        $this->large->willAnswer(AiTask::BillSummarize, $this->billAnswer());

        $first = $this->gateway->run(AiTask::BillSummarize, $this->billContext());
        $second = $this->gateway->run(AiTask::BillSummarize, $this->billContext());

        self::assertFalse($first->fromCache);
        self::assertTrue($second->fromCache);
        self::assertSame($first->requireData(), $second->requireData());
        self::assertSame(1, $this->large->callCount(), 'the provider must be asked only once');
        self::assertCount(1, $this->entityManager->getRepository(AiUsage::class)->findAll());
    }

    /**
     * A model on the operator's own computer must never block a pipeline stage (SPEC.md § 8.3).
     */
    public function testATaskRoutedToARemoteWorkerBecomesAJob(): void
    {
        $result = $this->gateway->run(AiTask::LawTopics, $this->topicsContext(), 'law:aufenthg_2004');

        self::assertTrue($result->isQueued());
        self::assertNotNull($result->jobId);
        self::assertSame('local:local-model', (string) $result->model);

        $job = $this->entityManager->find(AiJob::class, $result->jobId);
        self::assertInstanceOf(AiJob::class, $job);
        self::assertSame(AiJobStatus::Pending, $job->status());
        self::assertSame('local', $job->provider());
        self::assertSame('law', $job->subjectType());
        self::assertSame('aufenthg_2004', $job->subjectId());
        self::assertStringContainsString('Aufenthaltsgesetz', (string) $job->payload()['user']);
    }

    public function testTheSameQueuedRequestIsNotDuplicated(): void
    {
        $first = $this->gateway->run(AiTask::LawTopics, $this->topicsContext());
        $second = $this->gateway->run(AiTask::LawTopics, $this->topicsContext());

        self::assertSame($first->jobId, $second->jobId);
        self::assertCount(1, $this->entityManager->getRepository(AiJob::class)->findAll());
    }

    /**
     * @return array<string, mixed>
     */
    private function billContext(): array
    {
        return [
            'document' => 'Entwurf eines Gesetzes zur Anhebung der Gehaltsschwelle für die Blue Card.',
            'stage' => 'first reading',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billAnswer(): array
    {
        return [
            'title' => 'A higher minimum salary for the Blue Card',
            'what_is_proposed' => 'The bill would raise the minimum salary required for a Blue Card.',
            'who_is_affected' => 'People who hold a Blue Card or want to apply for one.',
            'status' => 'First reading in the Bundestag.',
            'what_happens_next' => 'The bill goes to the committees.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function topicsContext(): array
    {
        return [
            'law_title' => 'Aufenthaltsgesetz',
            'abbreviation' => 'AufenthG',
            'toc' => '§ 1 Zweck des Gesetzes',
            'topics_vocabulary' => ['migration', 'labor', 'taxes', 'housing'],
        ];
    }
}
